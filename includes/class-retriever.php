<?php
/**
 * Finds the chunks most similar to a question.
 *
 * @package SiteSage
 */

namespace SiteSage;

use SiteSage\Providers\Embedding_Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Embeds the question and ranks stored chunks by cosine similarity in PHP.
 */
class Retriever {

	/**
	 * Embedding provider.
	 *
	 * @var Embedding_Provider
	 */
	private $embedder;

	/**
	 * Chunk storage.
	 *
	 * @var Chunk_Repository
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param Embedding_Provider $embedder Embedding provider.
	 * @param Chunk_Repository   $store    Chunk storage.
	 */
	public function __construct( Embedding_Provider $embedder, Chunk_Repository $store ) {
		$this->embedder = $embedder;
		$this->store    = $store;
	}

	/**
	 * Top chunks for a question.
	 *
	 * @param string $question Question.
	 * @param int    $k        How many chunks.
	 * @return array|\WP_Error Chunks with a 'score' key, best first.
	 */
	public function search( $question, $k = 4 ) {
		$vectors = $this->embedder->embed( array( (string) $question ) );
		if ( is_wp_error( $vectors ) ) {
			return $vectors;
		}
		if ( empty( $vectors[0] ) ) {
			return array();
		}

		$chunks = $this->store->all_for_model( $this->embedder->model_id() );
		$top    = Vector_Math::top_k( $vectors[0], $chunks, $k );

		foreach ( $top as $i => $chunk ) {
			unset( $top[ $i ]['embedding'] );
		}
		return $top;
	}
}
