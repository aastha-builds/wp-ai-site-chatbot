<?php
/**
 * Tests for cosine similarity and ranking.
 *
 * @package SiteSage
 */

use PHPUnit\Framework\TestCase;
use SiteSage\Vector_Math;

class VectorMathTest extends TestCase {

	public function test_cosine_basics() {
		$this->assertEqualsWithDelta( 1.0, Vector_Math::cosine( array( 1, 2, 3 ), array( 2, 4, 6 ) ), 1e-9 );
		$this->assertEqualsWithDelta( 0.0, Vector_Math::cosine( array( 1, 0 ), array( 0, 1 ) ), 1e-9 );
		$this->assertEqualsWithDelta( -1.0, Vector_Math::cosine( array( 1, 1 ), array( -1, -1 ) ), 1e-9 );
	}

	public function test_cosine_is_zero_for_bad_input() {
		$this->assertSame( 0.0, Vector_Math::cosine( array(), array() ) );
		$this->assertSame( 0.0, Vector_Math::cosine( array( 1, 2 ), array( 1, 2, 3 ) ) );
		$this->assertSame( 0.0, Vector_Math::cosine( array( 0, 0 ), array( 1, 1 ) ) );
	}

	public function test_normalize_gives_unit_length() {
		$this->assertEqualsWithDelta( 1.0, Vector_Math::norm( Vector_Math::normalize( array( 3, 4 ) ) ), 1e-9 );
	}

	public function test_top_k_orders_by_score_and_limits() {
		$items = array(
			array( 'id' => 'a', 'embedding' => array( 1, 0 ) ),
			array( 'id' => 'b', 'embedding' => array( 0.7, 0.7 ) ),
			array( 'id' => 'c', 'embedding' => array( 0, 1 ) ),
			array( 'id' => 'bad', 'embedding' => array() ),
		);
		$top = Vector_Math::top_k( array( 1, 0.1 ), $items, 2 );

		$this->assertCount( 2, $top );
		$this->assertSame( array( 'a', 'b' ), array_column( $top, 'id' ) );
		$this->assertGreaterThan( $top[1]['score'], $top[0]['score'] );
	}

	public function test_pack_round_trip() {
		$vector   = array( 0.25, -1.5, 0.0, 3.125 );
		$unpacked = Vector_Math::unpack( Vector_Math::pack( $vector ) );
		$this->assertCount( 4, $unpacked );
		foreach ( $vector as $i => $value ) {
			$this->assertEqualsWithDelta( $value, $unpacked[ $i ], 1e-6 );
		}
		$this->assertSame( array(), Vector_Math::unpack( 'not base64 !!' ) );
		$this->assertSame( '', Vector_Math::pack( array() ) );
	}
}
