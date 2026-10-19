<?php
/**
 * CSV formatting for the leads export.
 *
 * @package SiteSage
 */

namespace SiteSage;

defined( 'ABSPATH' ) || exit;

/**
 * Builds CSV rows and guards against spreadsheet formula injection.
 */
class Leads_Export {

	/**
	 * CSV header row.
	 *
	 * @return string[]
	 */
	public static function header() {
		return array( 'ID', 'Date (UTC)', 'Name', 'Phone', 'Email', 'Question', 'Page', 'Google Sheets' );
	}

	/**
	 * One CSV row for a lead.
	 *
	 * @param array $lead Lead row from the database.
	 * @return string[]
	 */
	public static function row( array $lead ) {
		return array_map(
			array( __CLASS__, 'cell' ),
			array(
				isset( $lead['id'] ) ? (string) $lead['id'] : '',
				isset( $lead['created_at'] ) ? (string) $lead['created_at'] : '',
				isset( $lead['name'] ) ? (string) $lead['name'] : '',
				isset( $lead['phone'] ) ? (string) $lead['phone'] : '',
				isset( $lead['email'] ) ? (string) $lead['email'] : '',
				isset( $lead['question'] ) ? (string) $lead['question'] : '',
				isset( $lead['page_url'] ) ? (string) $lead['page_url'] : '',
				isset( $lead['webhook_status'] ) ? (string) $lead['webhook_status'] : '',
			)
		);
	}

	/**
	 * Neutralize values a spreadsheet would run as a formula.
	 *
	 * @param string $value Cell value.
	 * @return string
	 */
	public static function cell( $value ) {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			$value = "'" . $value;
		}
		return $value;
	}

	/**
	 * Full CSV document as a string.
	 *
	 * @param array[] $leads Leads.
	 * @return string
	 */
	public static function to_csv( array $leads ) {
		$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory stream.
		fputcsv( $handle, self::header(), ',', '"', '\\' );
		foreach ( $leads as $lead ) {
			fputcsv( $handle, self::row( $lead ), ',', '"', '\\' );
		}
		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- In-memory stream.
		return (string) $csv;
	}
}
