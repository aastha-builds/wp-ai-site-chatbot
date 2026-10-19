<?php
/**
 * Activation, database tables and upgrades.
 *
 * @package SiteSage
 */

namespace SiteSage;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the custom tables.
 */
class Installer {

	/**
	 * Activation hook.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_tables();

		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults() );
		}

		FAQ_Post_Type::register();
		flush_rewrite_rules();
	}

	/**
	 * Deactivation hook. Data stays; uninstall.php removes it.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'sitesage_reindex_post' );
		flush_rewrite_rules();
	}

	/**
	 * Run the table setup again when the schema version changes.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'sitesage_db_version' ) !== SITESAGE_DB_VERSION ) {
			self::create_tables();
		}
	}

	/**
	 * Name of the chunks table.
	 *
	 * @return string
	 */
	public static function chunks_table() {
		global $wpdb;
		return $wpdb->prefix . 'sitesage_chunks';
	}

	/**
	 * Name of the leads table.
	 *
	 * @return string
	 */
	public static function leads_table() {
		global $wpdb;
		return $wpdb->prefix . 'sitesage_leads';
	}

	/**
	 * Create or update both tables with dbDelta.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$chunks  = self::chunks_table();
		$leads   = self::leads_table();

		$sql = "CREATE TABLE {$chunks} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_type varchar(20) NOT NULL,
			source_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source_url text NOT NULL,
			title text NOT NULL,
			chunk_index int(10) unsigned NOT NULL DEFAULT 0,
			content longtext NOT NULL,
			token_count int(10) unsigned NOT NULL DEFAULT 0,
			embedding longtext NOT NULL,
			embedding_model varchar(100) NOT NULL,
			content_hash char(40) NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY source (source_type,source_id)
		) {$charset};
		CREATE TABLE {$leads} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL,
			phone varchar(50) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			question text NOT NULL,
			page_url text NOT NULL,
			webhook_status varchar(20) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) {$charset};";

		dbDelta( $sql );
		update_option( 'sitesage_db_version', SITESAGE_DB_VERSION );
	}
}
