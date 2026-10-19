<?php
/**
 * Fixed-window rate limit per visitor IP, stored in transients.
 *
 * @package SiteSage
 */

namespace SiteSage;

defined( 'ABSPATH' ) || exit;

/**
 * The IP is hashed with a site salt before it is used as a key, so raw IPs are never stored.
 */
class Rate_Limiter {

	/**
	 * Bucket name, for example "ask" or "lead".
	 *
	 * @var string
	 */
	private $bucket;

	/**
	 * Requests allowed per window.
	 *
	 * @var int
	 */
	private $limit;

	/**
	 * Window length in seconds.
	 *
	 * @var int
	 */
	private $window;

	/**
	 * Clock, injectable for tests.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param string        $bucket Bucket name.
	 * @param int           $limit  Requests per window.
	 * @param int           $window Window in seconds.
	 * @param callable|null $clock  Returns the current Unix time.
	 */
	public function __construct( $bucket, $limit, $window, $clock = null ) {
		$this->bucket = sanitize_key( $bucket );
		$this->limit  = max( 1, (int) $limit );
		$this->window = max( 1, (int) $window );
		$this->clock  = is_callable( $clock ) ? $clock : 'time';
	}

	/**
	 * Record a request. Returns false when the visitor is over the limit.
	 *
	 * @param string $ip Visitor IP.
	 * @return bool True when allowed.
	 */
	public function hit( $ip ) {
		$key   = $this->key( $ip );
		$now   = (int) call_user_func( $this->clock );
		$state = get_transient( $key );

		if ( ! is_array( $state ) || ! isset( $state['start'], $state['count'] ) || $now - (int) $state['start'] >= $this->window ) {
			$state = array(
				'start' => $now,
				'count' => 0,
			);
		}

		if ( (int) $state['count'] >= $this->limit ) {
			return false;
		}

		++$state['count'];
		set_transient( $key, $state, $this->window );
		return true;
	}

	/**
	 * Seconds until the visitor may try again.
	 *
	 * @param string $ip Visitor IP.
	 * @return int
	 */
	public function retry_after( $ip ) {
		$state = get_transient( $this->key( $ip ) );
		if ( ! is_array( $state ) || ! isset( $state['start'] ) ) {
			return 0;
		}
		$now = (int) call_user_func( $this->clock );
		return max( 0, (int) $state['start'] + $this->window - $now );
	}

	/**
	 * Visitor IP from REMOTE_ADDR.
	 *
	 * Forwarded headers are spoofable, so they are only used when a site
	 * behind a proxy opts in with the sitesage_client_ip filter.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Filters the IP used for rate limiting.
		 *
		 * @param string $ip IP from REMOTE_ADDR.
		 */
		$ip = (string) apply_filters( 'sitesage_client_ip', $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/**
	 * Transient key for an IP.
	 *
	 * @param string $ip Visitor IP.
	 * @return string
	 */
	private function key( $ip ) {
		return 'sitesage_rl_' . $this->bucket . '_' . substr( hash_hmac( 'sha256', (string) $ip, wp_salt( 'nonce' ) ), 0, 24 );
	}
}
