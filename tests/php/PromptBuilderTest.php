<?php
/**
 * Tests for the prompt builder.
 *
 * @package SiteSage
 */

use PHPUnit\Framework\TestCase;
use SiteSage\Prompt_Builder;

class PromptBuilderTest extends TestCase {

	private function chunks() {
		return array(
			array(
				'title'      => 'Pricing',
				'source_url' => 'https://brightsmile.test/pricing/',
				'content'    => "In-office whitening: $399.\nTake-home kit: $199.",
			),
			array(
				'title'      => 'Teeth Whitening',
				'source_url' => 'https://brightsmile.test/services/teeth-whitening/',
				'content'    => 'One 90-minute visit.',
			),
		);
	}

	protected function setUp(): void {
		SiteSage_Test_State::reset();
	}

	public function test_system_prompt_has_the_required_rules() {
		$system = ( new Prompt_Builder( 'BrightSmile Dental' ) )->system_prompt();
		$this->assertStringContainsString( 'BrightSmile Dental', $system );
		$this->assertStringContainsString( 'only the context', $system );
		$this->assertStringContainsString( 'under 120 words', $system );
		$this->assertStringContainsString( 'Markdown link', $system );
		$this->assertStringContainsString( "I don't know that one, let me connect you with our team", $system );
	}

	public function test_user_message_holds_only_context_and_question() {
		$message = ( new Prompt_Builder() )->user_message( "How much\nis whitening?", $this->chunks() );
		$this->assertStringStartsWith( 'Context passages from the website:', $message );
		$this->assertStringContainsString( "[1] Pricing\nURL: https://brightsmile.test/pricing/\nIn-office whitening: $399.", $message );
		$this->assertStringContainsString( '[2] Teeth Whitening', $message );
		$this->assertStringEndsWith( 'Visitor question: How much is whitening?', $message );
	}

	public function test_parse_round_trip() {
		$message = ( new Prompt_Builder() )->user_message( 'How much is whitening?', $this->chunks() );
		$parsed  = Prompt_Builder::parse_user_message( $message );
		$this->assertSame( 'How much is whitening?', $parsed['question'] );
		$this->assertCount( 2, $parsed['sources'] );
		$this->assertSame( 'Pricing', $parsed['sources'][0]['title'] );
		$this->assertSame( 'https://brightsmile.test/services/teeth-whitening/', $parsed['sources'][1]['url'] );
		$this->assertSame( "In-office whitening: $399.\nTake-home kit: $199.", $parsed['sources'][0]['text'] );
	}

	public function test_is_fallback_handles_curly_quotes_and_punctuation() {
		$this->assertTrue( Prompt_Builder::is_fallback( "I don't know that one, let me connect you with our team." ) );
		$this->assertTrue( Prompt_Builder::is_fallback( 'I don’t know that one, let me connect you with our team' ) );
		$this->assertFalse( Prompt_Builder::is_fallback( 'Whitening costs $399.' ) );
	}

	public function test_fallback_message_is_filterable() {
		SiteSage_Test_State::$filters['sitesage_fallback_message'] = static function () {
			return 'Ask our front desk';
		};
		$this->assertSame( 'Ask our front desk', Prompt_Builder::fallback_message() );
		$this->assertTrue( Prompt_Builder::is_fallback( 'Ask our front desk!' ) );
	}

	public function test_limit_words_keeps_the_source_link() {
		$long   = trim( str_repeat( 'word ', 150 ) ) . ' Read more: [Pricing](https://brightsmile.test/pricing/)';
		$result = Prompt_Builder::limit_words( $long, 120 );
		$this->assertStringEndsWith( '… Read more: [Pricing](https://brightsmile.test/pricing/)', $result );
		$this->assertSame( 120, substr_count( $result, 'word' ) );

		$short = 'Short answer. [Pricing](https://brightsmile.test/pricing/)';
		$this->assertSame( $short, Prompt_Builder::limit_words( $short ) );
	}
}
