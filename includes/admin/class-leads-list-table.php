<?php
/**
 * Leads table on the admin screen.
 *
 * @package SiteSage
 */

namespace SiteSage\Admin;

use SiteSage\Lead_Store;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WP_List_Table for {prefix}sitesage_leads.
 */
class Leads_List_Table extends \WP_List_Table {

	/**
	 * Lead storage.
	 *
	 * @var Lead_Store
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param Lead_Store $store Lead storage.
	 */
	public function __construct( Lead_Store $store ) {
		parent::__construct(
			array(
				'singular' => 'lead',
				'plural'   => 'leads',
				'ajax'     => false,
			)
		);
		$this->store = $store;
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'             => '<input type="checkbox" />',
			'name'           => __( 'Name', 'sitesage-chat' ),
			'phone'          => __( 'Phone', 'sitesage-chat' ),
			'email'          => __( 'Email', 'sitesage-chat' ),
			'question'       => __( 'Last question', 'sitesage-chat' ),
			'page_url'       => __( 'Page', 'sitesage-chat' ),
			'webhook_status' => __( 'Google Sheets', 'sitesage-chat' ),
			'created_at'     => __( 'Date', 'sitesage-chat' ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array( 'delete' => __( 'Delete', 'sitesage-chat' ) );
	}

	/**
	 * Delete selected leads (nonce and capability checked).
	 *
	 * @return void
	 */
	public function process_bulk_action() {
		if ( 'delete' !== $this->current_action() ) {
			return;
		}
		check_admin_referer( 'bulk-' . $this->_args['plural'] );
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$ids = isset( $_REQUEST['lead'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['lead'] ) ) : array();
		$this->store->delete( $ids );
	}

	/**
	 * Load the current page of leads.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = 20;
		$search   = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search.
		$total    = $this->store->count( $search );

		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->items           = $this->store->query( $per_page, $this->get_pagenum(), $search );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Lead.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="lead[]" value="%d" />', (int) $item['id'] );
	}

	/**
	 * Default column output.
	 *
	 * @param array  $item        Lead.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'name':
				return '<strong>' . esc_html( $item['name'] ) . '</strong>';
			case 'phone':
				return '<a href="' . esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $item['phone'] ) ) . '">' . esc_html( $item['phone'] ) . '</a>';
			case 'email':
				return '<a href="' . esc_url( 'mailto:' . $item['email'] ) . '">' . esc_html( $item['email'] ) . '</a>';
			case 'question':
				return esc_html( wp_trim_words( $item['question'], 14 ) );
			case 'page_url':
				$path = wp_parse_url( $item['page_url'], PHP_URL_PATH );
				return $item['page_url'] ? '<a href="' . esc_url( $item['page_url'] ) . '" target="_blank" rel="noopener">' . esc_html( $path ? $path : '/' ) . '</a>' : '–';
			case 'webhook_status':
				$labels = array(
					'sent'    => __( 'Sent', 'sitesage-chat' ),
					'failed'  => __( 'Failed', 'sitesage-chat' ),
					'skipped' => __( 'Not set up', 'sitesage-chat' ),
				);
				$status = isset( $labels[ $item['webhook_status'] ] ) ? $item['webhook_status'] : 'skipped';
				return '<span class="sitesage-status is-' . esc_attr( $status ) . '">' . esc_html( $labels[ $status ] ) . '</span>';
			case 'created_at':
				return esc_html( get_date_from_gmt( $item['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) );
		}
		return '';
	}

	/**
	 * Message when there are no leads.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No leads yet. They appear here when visitors leave their details in the chat.', 'sitesage-chat' );
	}
}
