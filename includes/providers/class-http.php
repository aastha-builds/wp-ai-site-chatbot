<?php
/**
 * JSON-over-HTTP helper shared by the live providers.
 *
 * @package SiteSage
 */

namespace SiteSage\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps wp_remote_post with JSON encoding and readable errors.
 */
class Http {

	/**
	 * POST a JSON body and decode the JSON response.
	 *
	 * @param string $url     Endpoint.
	 * @param array  $headers Extra headers.
	 * @param array  $body    Body to JSON-encode.
	 * @param int    $timeout Seconds.
	 * @return array|\WP_Error Decoded response or an error.
	 */
	public static function post_json( $url, array $headers, array $body, $timeout = 30 ) {
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => $timeout,
				'headers' => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $decoded ) && isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : '';
			return new \WP_Error(
				'sitesage_http_' . $code,
				/* translators: 1: HTTP status code, 2: error message from the API. */
				sprintf( __( 'The AI service returned HTTP %1$d. %2$s', 'sitesage-chat' ), $code, $message )
			);
		}

		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'sitesage_bad_json', __( 'The AI service returned an unreadable response.', 'sitesage-chat' ) );
		}

		return $decoded;
	}
}
