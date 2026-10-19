<?php
/**
 * Database storage for chunks in {prefix}sitesage_chunks.
 *
 * @package SiteSage
 */

namespace SiteSage;

defined( 'ABSPATH' ) || exit;

/**
 * All queries use $wpdb->prepare or $wpdb->insert / $wpdb->delete.
 */
class Chunk_Store implements Chunk_Repository {

	/**
	 * {@inheritDoc}
	 *
	 * @param array[] $rows Rows.
	 * @return int
	 */
	public function insert_many( array $rows ) {
		global $wpdb;
		$inserted = 0;
		$now      = current_time( 'mysql', true );

		foreach ( $rows as $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
			$ok = $wpdb->insert(
				Installer::chunks_table(),
				array(
					'source_type'     => (string) $row['source_type'],
					'source_id'       => (int) $row['source_id'],
					'source_url'      => (string) $row['source_url'],
					'title'           => (string) $row['title'],
					'chunk_index'     => (int) $row['chunk_index'],
					'content'         => (string) $row['content'],
					'token_count'     => (int) $row['token_count'],
					'embedding'       => Vector_Math::pack( $row['embedding'] ),
					'embedding_model' => (string) $row['embedding_model'],
					'content_hash'    => (string) $row['content_hash'],
					'updated_at'      => $now,
				),
				array( '%s', '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
			);
			if ( $ok ) {
				++$inserted;
			}
		}

		$this->forget();
		return $inserted;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $source_type Source type.
	 * @param int    $source_id   Source ID.
	 * @return void
	 */
	public function delete_source( $source_type, $source_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; the cache is invalidated below.
		$wpdb->delete(
			Installer::chunks_table(),
			array(
				'source_type' => (string) $source_type,
				'source_id'   => (int) $source_id,
			),
			array( '%s', '%d' )
		);
		$this->forget();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function delete_all() {
		global $wpdb;
		$table = Installer::chunks_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table name, no user input; the cache is invalidated below.
		$wpdb->query( "DELETE FROM {$table}" );
		$this->forget();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $model_id Embedding model ID.
	 * @return array[]
	 */
	public function all_for_model( $model_id ) {
		global $wpdb;

		$cache_key = 'chunks_' . md5( $model_id ) . '_' . $this->last_changed();
		$cached    = wp_cache_get( $cache_key, 'sitesage' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$table = Installer::chunks_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached with wp_cache below.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from $wpdb->prefix.
				"SELECT id, source_type, source_id, source_url, title, chunk_index, content, embedding FROM {$table} WHERE embedding_model = %s ORDER BY id ASC",
				$model_id
			),
			ARRAY_A
		);

		$chunks = array();
		foreach ( (array) $rows as $row ) {
			$row['embedding'] = Vector_Math::unpack( $row['embedding'] );
			$row['source_id'] = (int) $row['source_id'];
			$chunks[]         = $row;
		}

		wp_cache_set( $cache_key, $chunks, 'sitesage', HOUR_IN_SECONDS );
		return $chunks;
	}

	/**
	 * Summary for the admin screen: chunk count, source count, models, last update.
	 *
	 * @return array
	 */
	public function stats() {
		global $wpdb;
		$table = Installer::chunks_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table, admin-only summary.
		$chunks  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$sources = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT DISTINCT source_type, source_id FROM {$table}) AS s" );
		$models  = $wpdb->get_col( "SELECT DISTINCT embedding_model FROM {$table}" );
		$updated = $wpdb->get_var( "SELECT MAX(updated_at) FROM {$table}" );
		// phpcs:enable

		return array(
			'chunks'  => $chunks,
			'sources' => $sources,
			'models'  => array_map( 'strval', (array) $models ),
			'updated' => $updated ? (string) $updated : '',
		);
	}

	/**
	 * Indexed sources with chunk counts, for the admin table.
	 *
	 * @return array[]
	 */
	public function sources() {
		global $wpdb;
		$table = Installer::chunks_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table, admin-only listing.
		$rows = $wpdb->get_results( "SELECT source_type, source_id, MAX(title) AS title, MAX(source_url) AS source_url, COUNT(*) AS chunks, SUM(token_count) AS tokens FROM {$table} GROUP BY source_type, source_id ORDER BY CASE source_type WHEN 'page' THEN 1 WHEN 'post' THEN 2 WHEN 'pdf' THEN 3 ELSE 4 END, MIN(id)", ARRAY_A );
		return (array) $rows;
	}

	/**
	 * Cache version that changes after every write.
	 *
	 * @return string
	 */
	private function last_changed() {
		$value = wp_cache_get( 'last_changed', 'sitesage' );
		if ( ! $value ) {
			$value = microtime();
			wp_cache_set( 'last_changed', $value, 'sitesage' );
		}
		return (string) $value;
	}

	/**
	 * Drop the cached chunk list after a write.
	 *
	 * @return void
	 */
	private function forget() {
		wp_cache_set( 'last_changed', microtime(), 'sitesage' );
	}
}
