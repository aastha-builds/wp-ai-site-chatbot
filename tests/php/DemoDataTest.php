<?php
/**
 * Consistency checks for the fictional demo data and the GitHub Pages copy.
 *
 * @package SiteSage
 */

use PHPUnit\Framework\TestCase;

class DemoDataTest extends TestCase {

	private static function json( $path ) {
		return json_decode( file_get_contents( SITESAGE_PLUGIN_DIR . $path ), true );
	}

	public function test_demo_content_has_the_required_pages_and_faqs() {
		$content = self::json( 'demo/content.json' );
		$this->assertSame(
			array( 'Home', 'About', 'Services', 'Teeth Whitening', 'Implants', 'Pricing', 'Insurance', 'Contact' ),
			array_column( $content['pages'], 'title' )
		);
		$this->assertCount( 15, $content['faqs'] );
		$this->assertFileExists( SITESAGE_PLUGIN_DIR . 'demo/' . $content['pdf']['file'] );
	}

	public function test_every_expected_source_exists() {
		$content = self::json( 'demo/content.json' );
		$titles  = array_merge( array_column( $content['pages'], 'title' ), array_column( $content['faqs'], 'question' ), array( $content['pdf']['title'], 'none' ) );

		$handle = fopen( SITESAGE_PLUGIN_DIR . 'docs/test-questions.csv', 'r' );
		fgetcsv( $handle, 0, ',', '"', '\\' );
		$count = 0;
		while ( false !== ( $row = fgetcsv( $handle, 0, ',', '"', '\\' ) ) ) {
			$this->assertContains( $row[1], $titles, 'Unknown expected source for: ' . $row[0] );
			++$count;
		}
		fclose( $handle );
		$this->assertSame( 30, $count );
	}

	public function test_saved_answers_match_the_test_questions_and_stay_short() {
		$answers = self::json( 'demo/sample-answers.json' )['answers'];
		$this->assertCount( 30, $answers );
		foreach ( $answers as $answer ) {
			$this->assertLessThan( 120, str_word_count( $answer['answer'] ), $answer['question'] );
		}
	}

	public function test_pages_copy_matches_the_saved_answers() {
		$saved = self::json( 'demo/sample-answers.json' )['answers'];
		$pages = self::json( 'docs/data/answers.json' );
		$this->assertSame( array_column( $saved, 'question' ), array_column( $pages['answers'], 'question' ) );
		$this->assertSame( array_column( $saved, 'answer' ), array_column( $pages['answers'], 'answer' ) );
		foreach ( $pages['answers'] as $entry ) {
			if ( 'none' !== $entry['source'] ) {
				$this->assertNotEmpty( $entry['source_url'], $entry['question'] );
			}
		}
	}

	public function test_no_real_secrets_in_the_repo_examples() {
		$env = file_get_contents( SITESAGE_PLUGIN_DIR . '.env.example' );
		$this->assertDoesNotMatchRegularExpression( '/sk-[A-Za-z0-9]{20,}/', $env );
		$this->assertStringContainsString( 'DEMO_MODE=true', $env );
	}
}
