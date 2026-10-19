<?php
/**
 * Tests for retrieval and answering.
 *
 * @package SiteSage
 */

use PHPUnit\Framework\TestCase;
use SiteSage\Answer_Service;
use SiteSage\Prompt_Builder;
use SiteSage\Providers\Demo_Embedding_Provider;
use SiteSage\Retriever;

class AnswerServiceTest extends TestCase {

	private $store;

	protected function setUp(): void {
		SiteSage_Test_State::reset();
		$this->store = new SiteSage_Memory_Store();
		$this->store->insert_many(
			array(
				sitesage_test_row( 'Pricing', 'In-office teeth whitening price: $399. Dental implant price: from $3,800. Cleaning price: $120.', 'https://brightsmile.test/pricing/', 1 ),
				sitesage_test_row( 'Contact', 'Opening hours: Monday to Thursday 8 am to 6 pm. Free parking behind the building.', 'https://brightsmile.test/contact/', 2 ),
				sitesage_test_row( 'Insurance', 'We are in-network with most PPO insurance plans. Medicaid is not accepted for adults.', 'https://brightsmile.test/insurance/', 3 ),
				sitesage_test_row( 'Implants', 'A dental implant replaces a missing tooth root. Healing takes 3 to 6 months.', 'https://brightsmile.test/implants/', 4 ),
				sitesage_test_row( 'Kids', 'We see children from age 2.', 'https://brightsmile.test/kids/', 5 ),
			)
		);
	}

	private function service( $provider, $min_score = 0.08 ) {
		return new Answer_Service( new Retriever( new Demo_Embedding_Provider(), $this->store ), $provider, new Prompt_Builder( 'BrightSmile Dental' ), $min_score );
	}

	public function test_retriever_returns_top_four_best_first() {
		$retriever = new Retriever( new Demo_Embedding_Provider(), $this->store );
		$chunks    = $retriever->search( 'Is there parking near the clinic?', 4 );

		$this->assertCount( 4, $chunks );
		$this->assertSame( 'Contact', $chunks[0]['title'] );
		$this->assertArrayNotHasKey( 'embedding', $chunks[0] );
		for ( $i = 1; $i < 4; $i++ ) {
			$this->assertLessThanOrEqual( $chunks[ $i - 1 ]['score'], $chunks[ $i ]['score'] );
		}
	}

	public function test_retriever_ignores_vectors_from_another_model() {
		$this->store->rows[0]['embedding_model'] = 'openai:text-embedding-3-small';
		$chunks = ( new Retriever( new Demo_Embedding_Provider(), $this->store ) )->search( 'whitening price', 4 );
		$this->assertNotContains( 'Pricing', array_column( $chunks, 'title' ) );
	}

	public function test_only_question_and_context_are_sent_to_the_ai() {
		$provider = new SiteSage_Fake_Provider();
		$this->service( $provider )->ask( 'How much is teeth whitening?' );

		$this->assertCount( 1, $provider->calls );
		$parsed = Prompt_Builder::parse_user_message( $provider->calls[0]['user'] );
		$this->assertSame( 'How much is teeth whitening?', $parsed['question'] );
		$this->assertCount( 4, $parsed['sources'], 'Exactly the top 4 chunks are sent.' );
		$this->assertStringNotContainsString( '@', $provider->calls[0]['user'] );
	}

	public function test_low_relevance_skips_the_ai_and_hands_off() {
		$provider = new SiteSage_Fake_Provider();
		$result   = $this->service( $provider, 0.99 )->ask( 'What is the capital of France?' );

		$this->assertTrue( $result['fallback'] );
		$this->assertSame( Prompt_Builder::fallback_message(), $result['answer'] );
		$this->assertSame( array(), $result['sources'] );
		$this->assertCount( 0, $provider->calls );
	}

	public function test_model_fallback_reply_hides_sources() {
		$result = $this->service( new SiteSage_Fake_Provider( "I don't know that one, let me connect you with our team." ) )->ask( 'Do you sell toothbrushes?' );
		$this->assertTrue( $result['fallback'] );
		$this->assertSame( array(), $result['sources'] );
	}

	public function test_cited_page_comes_first_in_sources() {
		$provider = new SiteSage_Fake_Provider( 'Implants start at $3,800. [Pricing](https://brightsmile.test/pricing/)' );
		$result   = $this->service( $provider )->ask( 'How long does a dental implant take to heal?' );

		$this->assertFalse( $result['fallback'] );
		$this->assertSame( 'Pricing', $result['sources'][0]['title'] );
		$this->assertLessThanOrEqual( 3, count( $result['sources'] ) );
		$this->assertCount( count( array_unique( array_column( $result['sources'], 'url' ) ) ), $result['sources'] );
	}

	public function test_links_to_pages_that_were_not_retrieved_are_not_sources() {
		$provider = new SiteSage_Fake_Provider( 'Answer. [Elsewhere](https://evil.test/)' );
		$result   = $this->service( $provider )->ask( 'whitening price' );
		$this->assertNotContains( 'https://evil.test/', array_column( $result['sources'], 'url' ) );
	}

	public function test_refusal_becomes_handoff_and_errors_pass_through() {
		$refusing = new class() extends SiteSage_Fake_Provider {
			public function generate( $system, $user ) {
				return new WP_Error( 'sitesage_refusal', 'declined' );
			}
		};
		$this->assertTrue( $this->service( $refusing )->ask( 'whitening price' )['fallback'] );

		$broken = new class() extends SiteSage_Fake_Provider {
			public function generate( $system, $user ) {
				return new WP_Error( 'sitesage_http_500', 'down' );
			}
		};
		$this->assertInstanceOf( WP_Error::class, $this->service( $broken )->ask( 'whitening price' ) );
	}

	public function test_long_answers_are_trimmed_to_120_words() {
		$provider = new SiteSage_Fake_Provider( trim( str_repeat( 'word ', 200 ) ) . ' [Pricing](https://brightsmile.test/pricing/)' );
		$result   = $this->service( $provider )->ask( 'whitening price' );
		$this->assertSame( 120, substr_count( $result['answer'], 'word' ) );
	}
}
