<?php
/**
 * Tests for the offline demo embedder.
 *
 * @package SiteSage
 */

use PHPUnit\Framework\TestCase;
use SiteSage\Providers\Demo_Embedding_Provider;
use SiteSage\Vector_Math;

class DemoEmbeddingProviderTest extends TestCase {

	public function test_vectors_are_deterministic_and_unit_length() {
		$a = Demo_Embedding_Provider::compute( 'Teeth whitening prices' );
		$b = Demo_Embedding_Provider::compute( 'Teeth whitening prices' );
		$this->assertSame( $a, $b );
		$this->assertCount( Demo_Embedding_Provider::DIMENSIONS, $a );
		$this->assertEqualsWithDelta( 1.0, Vector_Math::norm( $a ), 1e-3 );
	}

	public function test_related_text_is_closer_than_unrelated_text() {
		$query    = Demo_Embedding_Provider::compute( 'How much does whitening cost?' );
		$related  = Demo_Embedding_Provider::compute( 'In-office teeth whitening price: $399. Take-home whitening kit: $199.' );
		$unrelated = Demo_Embedding_Provider::compute( 'Free parking is available behind the building.' );
		$this->assertGreaterThan( Vector_Math::cosine( $query, $unrelated ), Vector_Math::cosine( $query, $related ) );
	}

	public function test_idf_lowers_the_weight_of_common_terms() {
		$texts = array( 'patient parking lot', 'patient whitening gel', 'patient implant crown' );
		$idf   = Demo_Embedding_Provider::build_idf( $texts );
		$this->assertSame( 3, $idf['__n'] );
		$this->assertLessThan( $idf['parking'], $idf['patient'] );
	}

	public function test_saved_vectors_are_used_when_text_matches() {
		$file = tempnam( sys_get_temp_dir(), 'ssc' );
		$text = "Pricing\n\nClear prices.";
		file_put_contents(
			$file,
			json_encode(
				array(
					'model'   => Demo_Embedding_Provider::MODEL_ID,
					'idf'     => array(),
					'vectors' => array( sha1( $text ) => array( '5' => 1.0 ) ),
				)
			)
		);
		$provider = new Demo_Embedding_Provider( $file );
		$vector   = $provider->embed( array( $text ) )[0];
		unlink( $file );

		$this->assertSame( 1, $provider->saved_count() );
		$this->assertSame( 1.0, $vector[5] );
		$this->assertSame( 1.0, array_sum( $vector ) );
	}

	public function test_sparse_round_trip() {
		$dense = Demo_Embedding_Provider::compute( 'Saturday opening hours' );
		$this->assertSame( $dense, Demo_Embedding_Provider::from_sparse( Demo_Embedding_Provider::to_sparse( $dense ) ) );
	}

	public function test_shipped_demo_embeddings_file_matches_model() {
		$json = json_decode( file_get_contents( SITESAGE_PLUGIN_DIR . 'demo/demo-embeddings.json' ), true );
		$this->assertSame( Demo_Embedding_Provider::MODEL_ID, $json['model'] );
		$this->assertGreaterThanOrEqual( 24, count( $json['vectors'] ) );
		$this->assertArrayHasKey( '__n', $json['idf'] );
	}
}
