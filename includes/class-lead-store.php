<?php
/**
 * Validation and storage for leads in {prefix}sitesage_leads.
 *
 * @package SiteSage
 */

namespace SiteSage;

defined( 'ABSPATH' ) || exit;

/**
 * Leads are only stored locally and, if configured, posted to a Google Sheets webhook.
 * They are never sent to the AI provider.
 */
class Lead_Store {

	/**
	 * Sanitize and validate a lead submitted from the widget.
	 *
	 * @param array $raw Raw fields: name, phone, email, question, page_url.
	 * @return array|\WP_Error Clean lead or an error with a visitor-friendly message.
	 */
	public static function validate( array $raw ) {
		$lead = array(
			'name'     => isset( $raw['name'] ) ? sanitize_text_field( $raw['name'] ) : '',
			'phone'    => isset( $raw['phone'] ) ? sanitize_text_field( $raw['phone'] ) : '',
			'email'    => isset( $raw['email'] ) ? sanitize_email( $raw['email'] ) : '',
			'question' => isset( $raw['question'] ) ? sanitize_textarea_field( $raw['question'] ) : '',
			'page_url' => isset( $raw['page_url'] ) ? esc_url_raw( $raw['page_url'] ) : '',
		);

		$lead['name']     = mb_substr( trim( $lead['name'] ), 0, 190 );
		$lead['phone']    = mb_substr( trim( $lead['phone'] ), 0, 50 );
		$lead['question'] = mb_substr( trim( $lead['question'] ), 0, 1000 );

		if ( mb_strlen( $lead['name'] ) < 2 ) {
			return new \WP_Error( 'sitesage_lead_name', __( 'Please enter your name.', 'sitesage-chat' ) );
		}
		if ( '' === $lead['email'] || ! is_email( $lead['email'] ) ) {
			return new \WP_Error( 'sitesage_lead_email', __( 'Please enter a valid email address.', 'sitesage-chat' ) );
		}
		$digits = preg_replace( '/\D+/', '', $lead['phone'] );
		if ( strlen( $digits ) < 7 || strlen( $digits ) > 15 || ! preg_match( '/^[0-9+().\s-]+$/', $lead['phone'] ) ) {
			return new \WP_Error( 'sitesage_lead_phone', __( 'Please enter a valid phone number.', 'sitesage-chat' ) );
		}

		return $lead;
	}

	/**
	 * Save a validated lead.
	 *
	 * @param array $lead Clean lead from validate().
	 * @return int|\WP_Error Lead ID.
	 */
	public function insert( array $lead ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
		$ok = $wpdb->insert(
			Installer::leads_table(),
			array(
				'name'           => $lead['name'],
				'phone'          => $lead['phone'],
				'email'          => $lead['email'],
				'question'       => $lead['question'],
				'page_url'       => $lead['page_url'],
				'webhook_status' => isset( $lead['webhook_status'] ) ? $lead['webhook_status'] : '',
				'created_at'     => isset( $lead['created_at'] ) ? $lead['created_at'] : current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( ! $ok ) {
			return new \WP_Error( 'sitesage_lead_db', __( 'Could not save your details. Please try again.', 'sitesage-chat' ) );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Record the webhook result for a lead.
	 *
	 * @param int    $id     Lead ID.
	 * @param string $status sent, failed or skipped.
	 * @return void
	 */
	public function set_webhook_status( $id, $status ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->update( Installer::leads_table(), array( 'webhook_status' => sanitize_key( $status ) ), array( 'id' => (int) $id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Page of leads, newest first.
	 *
	 * @param int    $per_page Rows per page.
	 * @param int    $page     Page number, 1-based.
	 * @param string $search   Optional search in name, email or phone.
	 * @return array[]
	 */
	public function query( $per_page = 20, $page = 1, $search = '' ) {
		global $wpdb;
		$table  = Installer::leads_table();
		$offset = max( 0, ( (int) $page - 1 ) * (int) $per_page );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table, prepared values.
		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE name LIKE %s OR email LIKE %s OR phone LIKE %s ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $like, $like, $like, (int) $per_page, $offset ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", (int) $per_page, $offset ), ARRAY_A );
		}
		// phpcs:enable

		return (array) $rows;
	}

	/**
	 * Count leads.
	 *
	 * @param string $search Optional search.
	 * @return int
	 */
	public function count( $search = '' ) {
		global $wpdb;
		$table = Installer::leads_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table, prepared values.
		if ( '' !== $search ) {
			$like  = '%' . $wpdb->esc_like( $search ) . '%';
			$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE name LIKE %s OR email LIKE %s OR phone LIKE %s", $like, $like, $like ) );
		} else {
			$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}
		// phpcs:enable
		return (int) $count;
	}

	/**
	 * Every lead, oldest first, for CSV export.
	 *
	 * @return array[]
	 */
	public function all() {
		global $wpdb;
		$table = Installer::leads_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table, admin export.
		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at ASC, id ASC", ARRAY_A );
	}

	/**
	 * Delete leads by ID.
	 *
	 * @param int[] $ids Lead IDs.
	 * @return int Rows deleted.
	 */
	public function delete( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		$table        = Installer::leads_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom table, placeholders built above.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) );
	}
}
