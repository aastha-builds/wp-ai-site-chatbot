<?php
/**
 * Vector helpers for retrieval: cosine similarity and top-k ranking.
 *
 * @package SiteSage
 */

namespace SiteSage;

defined( 'ABSPATH' ) || exit;

/**
 * Plain PHP vector math. No extensions needed.
 */
class Vector_Math {

	/**
	 * Dot product of two equal-length vectors.
	 *
	 * @param float[] $a First vector.
	 * @param float[] $b Second vector.
	 * @return float
	 */
	public static function dot( array $a, array $b ) {
		$sum   = 0.0;
		$count = min( count( $a ), count( $b ) );
		for ( $i = 0; $i < $count; $i++ ) {
			$sum += $a[ $i ] * $b[ $i ];
		}
		return $sum;
	}

	/**
	 * Euclidean length of a vector.
	 *
	 * @param float[] $v Vector.
	 * @return float
	 */
	public static function norm( array $v ) {
		return sqrt( self::dot( $v, $v ) );
	}

	/**
	 * Cosine similarity between two vectors.
	 *
	 * Returns 0 for empty, zero-length or mismatched vectors so a bad row
	 * can never rank first.
	 *
	 * @param float[] $a First vector.
	 * @param float[] $b Second vector.
	 * @return float Between -1 and 1.
	 */
	public static function cosine( array $a, array $b ) {
		if ( empty( $a ) || count( $a ) !== count( $b ) ) {
			return 0.0;
		}
		$norm = self::norm( $a ) * self::norm( $b );
		if ( $norm <= 0.0 ) {
			return 0.0;
		}
		return self::dot( $a, $b ) / $norm;
	}

	/**
	 * Scale a vector to unit length.
	 *
	 * @param float[] $v Vector.
	 * @return float[]
	 */
	public static function normalize( array $v ) {
		$norm = self::norm( $v );
		if ( $norm <= 0.0 ) {
			return $v;
		}
		foreach ( $v as $i => $value ) {
			$v[ $i ] = $value / $norm;
		}
		return $v;
	}

	/**
	 * Pack a vector as base64 float32 for compact storage.
	 *
	 * @param float[] $v Vector.
	 * @return string
	 */
	public static function pack( array $v ) {
		if ( empty( $v ) ) {
			return '';
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary vector storage, not obfuscation.
		return base64_encode( pack( 'g*', ...array_map( 'floatval', array_values( $v ) ) ) );
	}

	/**
	 * Unpack a vector stored with pack().
	 *
	 * @param string $packed Base64 float32 data.
	 * @return float[]
	 */
	public static function unpack( $packed ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary vector storage, not obfuscation.
		$binary = base64_decode( (string) $packed, true );
		if ( false === $binary || '' === $binary || 0 !== strlen( $binary ) % 4 ) {
			return array();
		}
		return array_values( unpack( 'g*', $binary ) );
	}

	/**
	 * Rank items by cosine similarity to a query vector.
	 *
	 * @param float[] $query Query vector.
	 * @param array   $items Items, each with an 'embedding' key holding a vector.
	 * @param int     $k     How many to return.
	 * @return array Top items, best first, each with a 'score' key added.
	 */
	public static function top_k( array $query, array $items, $k ) {
		$scored = array();
		foreach ( $items as $item ) {
			if ( empty( $item['embedding'] ) || ! is_array( $item['embedding'] ) ) {
				continue;
			}
			$item['score'] = self::cosine( $query, $item['embedding'] );
			$scored[]      = $item;
		}

		usort(
			$scored,
			static function ( $x, $y ) {
				if ( $x['score'] === $y['score'] ) {
					return 0;
				}
				return ( $x['score'] < $y['score'] ) ? 1 : -1;
			}
		);

		return array_slice( $scored, 0, max( 0, (int) $k ) );
	}
}
