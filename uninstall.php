<?php
/**
 * Uninstall: removes SiteSage options, transients and custom tables.
 * Pages, FAQs and media you created stay in place.
 *
 * @package SiteSage
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'sitesage_settings' );
delete_option( 'sitesage_db_version' );
delete_option( 'sitesage_index_meta' );
delete_option( 'sitesage_last_eval' );

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing the plugin's own tables and transients.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sitesage_chunks" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sitesage_leads" );
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_sitesage_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_sitesage_' ) . '%'
	)
);
// phpcs:enable
