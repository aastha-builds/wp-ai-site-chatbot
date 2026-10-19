<?php
/**
 * Tests for the demo tokenizer.
 *
 * @package SiteSage
 */

use PHPUnit\Framework\TestCase;
use SiteSage\Text_Tokenizer;

class TextTokenizerTest extends TestCase {

	public function test_removes_stopwords_and_numbers() {
		$this->assertSame( array( 'price', 'whitening' ), Text_Tokenizer::tokens( 'What is the price of whitening in 2026?' ) );
	}

	public function test_maps_synonyms_and_stems() {
		$this->assertSame( array( 'price' ), Text_Tokenizer::tokens( 'pricing' ) );
		$this->assertSame( array( 'price' ), Text_Tokenizer::tokens( 'costs' ) );
		$this->assertSame( array( 'child' ), Text_Tokenizer::tokens( 'kids' ) );
		$this->assertSame( array( 'implant' ), Text_Tokenizer::tokens( 'implants' ) );
		$this->assertSame( array( 'xray' ), Text_Tokenizer::tokens( 'X-rays' ) );
		$this->assertSame( array( 'insurance' ), Text_Tokenizer::tokens( 'insured' ) );
		$this->assertSame( Text_Tokenizer::tokens( 'smoke' ), Text_Tokenizer::tokens( 'smoking' ) );
	}

	public function test_canonical_targets_map_to_themselves() {
		$this->assertSame( 'insurance', Text_Tokenizer::canonical( 'insurance' ) );
		$this->assertSame( 'cleaning', Text_Tokenizer::canonical( 'cleaning' ) );
		$this->assertSame( 'cleaning', Text_Tokenizer::canonical( 'cleanings' ) );
	}
}
