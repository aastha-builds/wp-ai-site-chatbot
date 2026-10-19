<?php
/**
 * Tests for the evaluation runner and report.
 *
 * @package SiteSage
 */

use PHPUnit\Framework\TestCase;
use SiteSage\Answer_Service;
use SiteSage\Evaluator;
use SiteSage\Prompt_Builder;
use SiteSage\Providers\Demo_Embedding_Provider;
use SiteSage\Retriever;

class EvaluatorTest extends TestCase {

	protected function setUp(): void {
		SiteSage_Test_State::reset();
	}

	public function test_loads_the_thirty_test_questions() {
		$questions = Evaluator::load_questions( SITESAGE_PLUGIN_DIR . 'docs/test-questions.csv' );
		$this->assertCount( 30, $questions );
		foreach ( $questions as $q ) {
			$this->assertNotSame( '', $q['question'] );
			$this->assertNotSame( '', $q['expected'] );
		}
	}

	public function test_missing_file_or_columns_are_errors() {
		$this->assertInstanceOf( WP_Error::class, Evaluator::load_questions( '/nope.csv' ) );
		$file = tempnam( sys_get_temp_dir(), 'ssc' );
		file_put_contents( $file, "q,a\nx,y\n" );
		$this->assertSame( 'sitesage_eval_columns', Evaluator::load_questions( $file )->get_error_code() );
		unlink( $file );
	}

	public function test_grading_rules() {
		$hit = array(
			'fallback' => false,
			'sources'  => array( array( 'title' => 'Teeth Whitening' ), array( 'title' => 'Pricing' ) ),
		);
		$this->assertTrue( Evaluator::grade( 'Pricing', $hit ) );
		$this->assertTrue( Evaluator::grade( 'pricing', $hit ) );
		$this->assertFalse( Evaluator::grade( 'Contact', $hit ) );
		$this->assertTrue( Evaluator::grade( 'none', array( 'fallback' => true, 'sources' => array() ) ) );
		$this->assertFalse( Evaluator::grade( 'none', $hit ) );
		$this->assertFalse( Evaluator::grade( 'Pricing', array( 'fallback' => true, 'sources' => array() ) ) );
		$this->assertTrue( Evaluator::same_title( 'What if I don’t have dental insurance?', "What if I don't have dental insurance?" ) );
	}

	public function test_run_and_markdown_report() {
		$store = new SiteSage_Memory_Store();
		$store->insert_many(
			array(
				sitesage_test_row( 'Pricing', 'Teeth whitening price $399.', 'https://brightsmile.test/pricing/', 1 ),
				sitesage_test_row( 'Contact', 'Free parking behind the building.', 'https://brightsmile.test/contact/', 2 ),
			)
		);
		$provider = new SiteSage_Fake_Provider( 'Whitening is $399. [Pricing](https://brightsmile.test/pricing/)' );
		$service  = new Answer_Service( new Retriever( new Demo_Embedding_Provider(), $store ), $provider, new Prompt_Builder(), 0.05 );
		$results  = ( new Evaluator( $service ) )->run(
			array(
				array( 'question' => 'How much is whitening?', 'expected' => 'Pricing' ),
				array( 'question' => 'Is there parking?', 'expected' => 'Insurance' ),
			)
		);

		$this->assertSame( 1, $results['passed'] );
		$this->assertSame( 2, $results['total'] );

		$markdown = Evaluator::to_markdown(
			$results,
			array(
				'date'      => '2026-10-19',
				'mode'      => 'Demo',
				'provider'  => 'fake:test',
				'embedder'  => 'demo:hash-1024',
				'chunks'    => 2,
				'min_score' => 0.05,
			)
		);
		$this->assertStringContainsString( '**Result: 1 of 2 passed (50%)**', $markdown );
		$this->assertStringContainsString( '| 1 | How much is whitening? | Pricing |', $markdown );
		$this->assertStringContainsString( '✅ Pass', $markdown );
		$this->assertStringContainsString( '❌ Fail', $markdown );
		$this->assertStringContainsString( '## Failed questions', $markdown );
	}
}
