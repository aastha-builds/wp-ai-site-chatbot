<?php
/**
 * Tests for settings, the widget handoff link, PDF extraction and block markup.
 *
 * @package SiteSage
 */

use PHPUnit\Framework\TestCase;
use SiteSage\Demo_Importer;
use SiteSage\PDF_Text_Extractor;
use SiteSage\Settings;
use SiteSage\Widget;

class MiscTest extends TestCase {

	protected function setUp(): void {
		SiteSage_Test_State::reset();
		putenv( 'DEMO_MODE' );
	}

	public function test_mask_shows_only_last_four() {
		$this->assertSame( '••••••••wxyz', Settings::mask( 'sk-abcdefghijklmnopqrstuvwxyz' ) );
		$this->assertSame( '••••••••', Settings::mask( 'short' ) );
		$this->assertSame( '', Settings::mask( '' ) );
	}

	public function test_api_keys_are_write_only() {
		update_option( Settings::OPTION, array( 'openai_api_key' => 'sk-saved-1234' ) );
		Settings::flush();

		$kept = Settings::sanitize( array( 'openai_api_key' => '' ) );
		$this->assertSame( 'sk-saved-1234', $kept['openai_api_key'], 'An empty field keeps the saved key.' );

		$new = Settings::sanitize( array( 'openai_api_key' => ' sk-new-5678 ' ) );
		$this->assertSame( 'sk-new-5678', $new['openai_api_key'] );

		$cleared = Settings::sanitize( array( 'openai_api_key_clear' => '1' ) );
		$this->assertSame( '', $cleared['openai_api_key'] );
	}

	public function test_sanitize_clamps_and_defaults() {
		$clean = Settings::sanitize(
			array(
				'provider'             => 'something-else',
				'brand_color'          => 'red',
				'rate_limit_requests'  => '99999',
				'max_message_length'   => '5',
				'handoff_whatsapp'     => '+1 (415) 555-0142',
				'handoff_type'         => 'whatsapp',
				'sheets_webhook_url'   => 'http://not-https.test',
				'min_score'            => '7',
				'lead_after_questions' => 'abc',
			)
		);
		$this->assertSame( 'openai', $clean['provider'] );
		$this->assertSame( '#0e7c86', $clean['brand_color'] );
		$this->assertSame( 1000, $clean['rate_limit_requests'] );
		$this->assertSame( 50, $clean['max_message_length'] );
		$this->assertSame( '14155550142', $clean['handoff_whatsapp'] );
		$this->assertSame( '', $clean['sheets_webhook_url'] );
		$this->assertSame( '1', $clean['min_score'] );
		$this->assertSame( 2, $clean['lead_after_questions'] );
		$this->assertFalse( $clean['demo_mode'] );
	}

	public function test_demo_mode_env_override_and_model_defaults() {
		update_option( Settings::OPTION, array( 'demo_mode' => false, 'provider' => 'anthropic' ) );
		Settings::flush();
		$this->assertFalse( Settings::is_demo_mode() );
		putenv( 'DEMO_MODE=true' );
		$this->assertTrue( Settings::is_demo_mode() );
		putenv( 'DEMO_MODE' );
		$this->assertSame( 'claude-opus-5-5', Settings::model() );
	}

	public function test_suggestions_are_limited_to_four_lines() {
		update_option( Settings::OPTION, array( 'suggestions' => "One\n\nTwo\nThree\nFour\nFive" ) );
		Settings::flush();
		$this->assertSame( array( 'One', 'Two', 'Three', 'Four' ), Settings::suggestions() );
	}

	public function test_handoff_links() {
		$whatsapp = Widget::handoff_url( 'whatsapp', '+1 415 555 0142', '', 'BrightSmile Dental' );
		$this->assertStringStartsWith( 'https://wa.me/14155550142?text=Hi%20BrightSmile%20Dental', $whatsapp );

		$email = Widget::handoff_url( 'email', '', 'hello@brightsmile.example', 'BrightSmile Dental' );
		$this->assertStringStartsWith( 'mailto:hello@brightsmile.example?subject=', $email );

		$this->assertSame( '', Widget::handoff_url( 'email', '', 'not-an-email', 'X' ) );
		$this->assertSame( '', Widget::handoff_url( 'whatsapp', '', '', 'X' ) );
	}

	public function test_pdf_text_extraction_from_demo_guide() {
		$text = PDF_Text_Extractor::from_file( SITESAGE_PLUGIN_DIR . 'demo/new-patient-guide.pdf' );
		$this->assertStringContainsString( 'What to bring to your first appointment', $text );
		$this->assertStringContainsString( 'A photo ID', $text );
		$this->assertStringContainsString( "driver's license", $text );
		$this->assertSame( '', PDF_Text_Extractor::from_string( 'not a pdf' ) );
	}

	public function test_ascii85_and_content_stream_parsing() {
		$this->assertSame( 'Man ', PDF_Text_Extractor::ascii85_decode( '9jqo^~>' ) );
		$this->assertSame( 'hello', PDF_Text_Extractor::ascii85_decode( '<~BOu!rDZ~>' ) );
		$stream = 'BT /F1 12 Tf 72 700 Td (Hello \\(world\\)) Tj ET BT [(Second) -250 ( line)] TJ ET';
		$this->assertSame( "Hello (world)\nSecond line", PDF_Text_Extractor::text_from_content_stream( $stream ) );
	}

	public function test_blocks_to_markup() {
		$markup = Demo_Importer::blocks_to_markup(
			array(
				array( 'heading' => 'Prices' ),
				array( 'paragraph' => 'Clear prices.' ),
				array( 'list' => array( 'A', 'B' ) ),
			)
		);
		$this->assertStringContainsString( '<!-- wp:heading -->', $markup );
		$this->assertStringContainsString( '<p>Clear prices.</p>', $markup );
		$this->assertSame( 2, substr_count( $markup, '<!-- wp:list-item -->' ) );
	}
}
