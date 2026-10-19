<?php
/**
 * Tests for the demo chat provider.
 *
 * @package SiteSage
 */

use PHPUnit\Framework\TestCase;
use SiteSage\Prompt_Builder;
use SiteSage\Providers\Demo_Chat_Provider;

class DemoChatProviderTest extends TestCase {

	private function provider() {
		return new Demo_Chat_Provider(
			array(
				array(
					'question' => 'How much does teeth whitening cost?',
					'source'   => 'Pricing',
					'answer'   => 'In-office whitening is $399.',
				),
				array(
					'question' => 'What is the weather like today?',
					'source'   => 'none',
					'answer'   => "I don't know that one, let me connect you with our team",
				),
			)
		);
	}

	private function message( $question ) {
		return ( new Prompt_Builder() )->user_message(
			$question,
			array(
				array(
					'title'      => 'Teeth Whitening',
					'source_url' => 'https://brightsmile.test/whitening/',
					'content'    => 'Professional whitening takes one 90-minute visit. Most patients see teeth up to 8 shades whiter.',
				),
				array(
					'title'      => 'Pricing',
					'source_url' => 'https://brightsmile.test/pricing/',
					'content'    => 'In-office teeth whitening: $399.',
				),
			)
		);
	}

	public function setUp(): void {
		SiteSage_Test_State::reset();
	}

	public function test_saved_answer_links_the_matching_context_page() {
		$answer = $this->provider()->generate( 'system', $this->message( 'How much does teeth whitening cost?' ) );
		$this->assertSame( 'In-office whitening is $399. Read more: [Pricing](https://brightsmile.test/pricing/)', $answer );
	}

	public function test_paraphrase_still_matches_saved_answer() {
		$this->assertNotNull( $this->provider()->best_saved_answer( 'how much is teeth whitening cost' ) );
		$this->assertNull( $this->provider()->best_saved_answer( 'Do you have parking?' ) );
	}

	public function test_unmatched_question_gets_an_extract_from_the_best_passage() {
		$answer = $this->provider()->generate( 'system', $this->message( 'How long does professional whitening take?' ) );
		$this->assertStringContainsString( 'one 90-minute visit', $answer );
		$this->assertStringEndsWith( '[Teeth Whitening](https://brightsmile.test/whitening/)', $answer );
	}

	public function test_saved_out_of_scope_answer_and_empty_context_hand_off() {
		$this->assertTrue( Prompt_Builder::is_fallback( $this->provider()->generate( 'system', $this->message( 'What is the weather like today?' ) ) ) );
		$this->assertTrue( Prompt_Builder::is_fallback( $this->provider()->generate( 'system', 'Visitor question: hello' ) ) );
	}

	public function test_shipped_sample_answers_load() {
		$provider = Demo_Chat_Provider::from_file( SITESAGE_PLUGIN_DIR . 'demo/sample-answers.json' );
		$this->assertSame( 'Pricing', $provider->best_saved_answer( 'How much is a dental implant?' )['source'] );
	}
}
