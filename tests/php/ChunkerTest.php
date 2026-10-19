<?php
/**
 * Tests for the chunker.
 *
 * @package SiteSage
 */

use PHPUnit\Framework\TestCase;
use SiteSage\Chunker;

class ChunkerTest extends TestCase {

	public function test_html_to_text_keeps_paragraphs_and_lists() {
		$html = "<!-- wp:paragraph --><p>Hello <strong>world</strong>.</p><!-- /wp:paragraph --><h2>Prices</h2><ul><li>Cleaning: $120</li><li>Whitening &amp; more</li></ul><script>alert(1)</script>";
		$text = Chunker::html_to_text( $html );

		$this->assertStringContainsString( "Hello world.\n\nPrices", $text );
		$this->assertStringContainsString( "- Cleaning: $120\n- Whitening & more", $text );
		$this->assertStringNotContainsString( 'alert', $text );
		$this->assertStringNotContainsString( '<', $text );
	}

	public function test_estimate_tokens_uses_four_thirds_of_words() {
		$this->assertSame( 4, Chunker::estimate_tokens( 'one two three' ) );
		$this->assertSame( 0, Chunker::estimate_tokens( '' ) );
	}

	public function test_short_text_is_one_chunk() {
		$chunks = ( new Chunker( 500, 50 ) )->split( "First paragraph.\n\nSecond paragraph." );
		$this->assertCount( 1, $chunks );
		$this->assertSame( "First paragraph.\n\nSecond paragraph.", $chunks[0] );
	}

	public function test_empty_text_has_no_chunks() {
		$this->assertSame( array(), ( new Chunker() )->split( "  \n " ) );
	}

	public function test_long_text_is_split_near_target_with_overlap() {
		$paragraphs = array();
		for ( $p = 0; $p < 30; $p++ ) {
			$paragraphs[] = trim( str_repeat( "Sentence number $p talks about dental care. ", 8 ) );
		}
		$text   = implode( "\n\n", $paragraphs );
		$chunks = ( new Chunker( 500, 50 ) )->split( $text );

		$this->assertGreaterThan( 2, count( $chunks ) );
		foreach ( $chunks as $chunk ) {
			$this->assertLessThanOrEqual( 560, Chunker::estimate_tokens( $chunk ), 'Chunk is far above the target.' );
		}

		// Chunk 2 starts with the last words of chunk 1 (50 tokens is about 37 words).
		$tail = implode( ' ', array_slice( preg_split( '/\s+/', $chunks[0] ), -37 ) );
		$this->assertStringStartsWith( $tail, $chunks[1] );

		// Nothing is lost: the last paragraph is in the last chunk.
		$this->assertStringContainsString( 'Sentence number 29', end( $chunks ) );
	}

	public function test_giant_sentence_is_split_by_words() {
		$text   = trim( str_repeat( 'word ', 1500 ) );
		$chunks = ( new Chunker( 500, 0 ) )->split( $text );
		$this->assertGreaterThanOrEqual( 3, count( $chunks ) );
		$total = 0;
		foreach ( $chunks as $chunk ) {
			$total += Chunker::word_count( $chunk );
		}
		$this->assertSame( 1500, $total );
	}
}
