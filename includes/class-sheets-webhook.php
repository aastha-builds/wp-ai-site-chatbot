<?php
/**
 * Sends new leads to a Google Apps Script web app.
 *
 * @package SiteSage
 */

namespace SiteSage;

defined( 'ABSPATH' ) || exit;

/**
 * Posts JSON to the webhook URL from the settings. See integrations/google-sheets.gs.
 */
class Sheets_Webhook {

	/**
	 * Webhook URL.
	 *
	 * @var string
	 */
	private $url;

	/**
	 * Shared secret checked by the Apps Script.
	 *
	 * @var string
	 */
	private $secret;

	/**
	 * Constructor.
	 *
	 * @param string $url    Webhook URL.
	 * @param string $secret Shared secret.
	 */
	public function __construct( $url, $secret ) {
		$this->url    = (string) $url;
		$this->secret = (string) $secret;
	}

	/**
	 * JSON payload for a lead.
	 *
	 * @param array $lead Lead.
	 * @return array
	 */
	public function payload( array $lead ) {
		return array(
			'secret'     => $this->secret,
			'created_at' => isset( $lead['created_at'] ) ? $lead['created_at'] : gmdate( 'Y-m-d H:i:s' ),
			'name'       => $lead['name'],
			'phone'      => $lead['phone'],
			'email'      => $lead['email'],
			'question'   => $lead['question'],
			'page_url'   => $lead['page_url'],
			'site'       => home_url( '/' ),
		);
	}

	/**
	 * Send a lead.
	 *
	 * @param array $lead Lead.
	 * @return string sent, failed or skipped.
	 */
	public function send( array $lead ) {
		if ( '' === $this->url || 0 !== strpos( $this->url, 'https://' ) ) {
			return 'skipped';
		}

		$response = wp_remote_post(
			$this->url,
			array(
				'timeout'     => 8,
				'redirection' => 5,
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => wp_json_encode( $this->payload( $lead ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return 'failed';
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return ( $code >= 200 && $code < 400 ) ? 'sent' : 'failed';
	}
}
