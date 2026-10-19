<?php
/**
 * Storage contract for indexed chunks.
 *
 * @package SiteSage
 */

namespace SiteSage;

defined( 'ABSPATH' ) || exit;

/**
 * Lets the indexer and retriever work with the database table or an in-memory store in tests.
 */
interface Chunk_Repository {

	/**
	 * Insert chunk rows.
	 *
	 * @param array[] $rows Rows with source_type, source_id, source_url, title, chunk_index, content, token_count, embedding (float[]), embedding_model, content_hash.
	 * @return int Rows inserted.
	 */
	public function insert_many( array $rows );

	/**
	 * Delete every chunk of one source.
	 *
	 * @param string $source_type Source type.
	 * @param int    $source_id   Source ID.
	 * @return void
	 */
	public function delete_source( $source_type, $source_id );

	/**
	 * Delete everything.
	 *
	 * @return void
	 */
	public function delete_all();

	/**
	 * All chunks stored with one embedding model, embeddings decoded.
	 *
	 * @param string $model_id Embedding model ID.
	 * @return array[]
	 */
	public function all_for_model( $model_id );
}
