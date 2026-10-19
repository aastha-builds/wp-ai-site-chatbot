<?php
/**
 * Offline embeddings for demo mode.
 *
 * @package SiteSage
 */

namespace SiteSage\Providers;

use SiteSage\Text_Tokenizer;
use SiteSage\Vector_Math;

defined( 'ABSPATH' ) || exit;

/**
 * Hashed TF-IDF vectors (1024 dimensions) that need no API key.
 *
 * Vectors for the demo content are read from the saved demo embeddings file
 * so indexing is instant. Any text not in that file (an edited page, a
 * visitor question) is embedded locally with the same method.
 */
class Demo_Embedding_Provider implements Embedding_Provider {

	const DIMENSIONS = 1024;
	const MODEL_ID   = 'demo:hash-1024';

	/**
	 * Saved vectors keyed by sha1 of the text.
	 *
	 * @var array<string,array>
	 */
	private $saved = array();

	/**
	 * Inverse document frequency per term, from the saved file.
	 *
	 * @var array<string,float>
	 */
	private $idf = array();

	/**
	 * Constructor.
	 *
	 * @param string $saved_file Path to the saved demo embeddings JSON file.
	 */
	public function __construct( $saved_file = '' ) {
		if ( $saved_file && is_readable( $saved_file ) ) {
			$json = json_decode( (string) file_get_contents( $saved_file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read.
			if ( is_array( $json ) && isset( $json['model'], $json['vectors'] ) && self::MODEL_ID === $json['model'] && is_array( $json['vectors'] ) ) {
				$this->saved = $json['vectors'];
				$this->idf   = isset( $json['idf'] ) && is_array( $json['idf'] ) ? $json['idf'] : array();
			}
		}
	}

	/**
	 * Number of saved vectors loaded from the file.
	 *
	 * @return int
	 */
	public function saved_count() {
		return count( $this->saved );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string[] $texts Texts.
	 * @return array
	 */
	public function embed( array $texts ) {
		$vectors = array();
		foreach ( $texts as $text ) {
			$hash = sha1( (string) $text );
			if ( isset( $this->saved[ $hash ] ) ) {
				$vectors[] = self::from_sparse( $this->saved[ $hash ] );
			} else {
				$vectors[] = self::compute( (string) $text, $this->idf );
			}
		}
		return $vectors;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function model_id() {
		return self::MODEL_ID;
	}

	/**
	 * Weighted terms of a text: unigrams plus half-weight bigrams.
	 *
	 * @param string $text Text.
	 * @return array<string,float> Term => raw count.
	 */
	public static function terms( $text ) {
		$tokens = Text_Tokenizer::tokens( $text );
		$terms  = array();
		foreach ( $tokens as $i => $token ) {
			$terms[ $token ] = ( isset( $terms[ $token ] ) ? $terms[ $token ] : 0 ) + 1.0;
			if ( $i > 0 ) {
				$bigram           = $tokens[ $i - 1 ] . '_' . $token;
				$terms[ $bigram ] = ( isset( $terms[ $bigram ] ) ? $terms[ $bigram ] : 0 ) + 1.0;
			}
		}
		return $terms;
	}

	/**
	 * Inverse document frequency for every term in a set of texts.
	 *
	 * @param string[] $texts Texts (one per chunk).
	 * @return array<string,float>
	 */
	public static function build_idf( array $texts ) {
		$df = array();
		foreach ( $texts as $text ) {
			foreach ( array_keys( self::terms( $text ) ) as $term ) {
				$df[ $term ] = ( isset( $df[ $term ] ) ? $df[ $term ] : 0 ) + 1;
			}
		}
		$n   = count( $texts );
		$idf = array( '__n' => $n );
		foreach ( $df as $term => $count ) {
			$idf[ $term ] = round( log( ( $n + 1 ) / ( $count + 1 ) ) + 1, 4 );
		}
		ksort( $idf );
		return $idf;
	}

	/**
	 * Compute a hashed TF-IDF vector.
	 *
	 * @param string $text Text.
	 * @param array  $idf  IDF table from build_idf(); empty means every term weighs 1.
	 * @return float[] Unit-length vector.
	 */
	public static function compute( $text, array $idf = array() ) {
		$vector  = array_fill( 0, self::DIMENSIONS, 0.0 );
		$unknown = isset( $idf['__n'] ) ? log( (float) $idf['__n'] + 1 ) + 1 : 1.0;

		foreach ( self::terms( $text ) as $term => $count ) {
			$weight  = 1 + log( $count );
			$weight *= empty( $idf ) ? 1.0 : ( isset( $idf[ $term ] ) ? (float) $idf[ $term ] : $unknown );
			$weight *= false !== strpos( $term, '_' ) ? 0.5 : 1.0;

			$hash  = (int) sprintf( '%u', crc32( $term ) );
			$index = $hash % self::DIMENSIONS;
			$sign  = ( ( (int) sprintf( '%u', crc32( 'sign:' . $term ) ) ) & 1 ) ? 1.0 : -1.0;

			$vector[ $index ] += $sign * $weight;
		}

		$vector = Vector_Math::normalize( $vector );
		foreach ( $vector as $i => $value ) {
			$vector[ $i ] = round( $value, 5 );
		}
		return $vector;
	}

	/**
	 * Store a vector sparsely: only non-zero dimensions.
	 *
	 * @param float[] $vector Dense vector.
	 * @return array<string,float>
	 */
	public static function to_sparse( array $vector ) {
		$sparse = array();
		foreach ( $vector as $i => $value ) {
			if ( 0.0 !== (float) $value ) {
				$sparse[ (string) $i ] = (float) $value;
			}
		}
		return $sparse;
	}

	/**
	 * Expand a sparse vector.
	 *
	 * @param array $sparse Sparse vector.
	 * @return float[]
	 */
	public static function from_sparse( array $sparse ) {
		$vector = array_fill( 0, self::DIMENSIONS, 0.0 );
		foreach ( $sparse as $i => $value ) {
			$i = (int) $i;
			if ( $i >= 0 && $i < self::DIMENSIONS ) {
				$vector[ $i ] = (float) $value;
			}
		}
		return $vector;
	}
}
