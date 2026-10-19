<?php
/**
 * REST API: /wp-json/sitesage/v1/ask and /wp-json/sitesage/v1/lead.
 *
 * @package SiteSage
 */

namespace SiteSage;

defined( 'ABSPATH' ) || exit;

/**
 * Public chat endpoints, protected by a nonce, a per-IP rate limit and a message length limit.
 */
class REST_Controller {

	const NAMESPACE_V1 = 'sitesage/v1';
	const NONCE_ACTION = 'sitesage_chat';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/ask',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ask' ),
				'permission_callback' => array( $this, 'check_nonce' ),
				'args'                => array(
					'question' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/lead',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'lead' ),
				'permission_callback' => array( $this, 'check_nonce' ),
				'args'                => array(
					'name'     => array(
						'type'     => 'string',
						'required' => true,
					),
					'phone'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'email'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'question' => array( 'type' => 'string' ),
					'page_url' => array( 'type' => 'string' ),
					'website'  => array( 'type' => 'string' ),
				),
			)
		);
	}

	/**
	 * Verify the chat nonce sent in the X-SiteSage-Nonce header.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function check_nonce( $request ) {
		$nonce = (string) $request->get_header( 'x_sitesage_nonce' );
		if ( '' !== $nonce && wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return true;
		}
		return new \WP_Error(
			'sitesage_bad_nonce',
			__( 'Your session expired. Please reload the page and try again.', 'sitesage-chat' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * POST /ask.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function ask( $request ) {
		$question = trim( (string) $request->get_param( 'question' ) );
		$max      = (int) Settings::get( 'max_message_length' );

		if ( '' === $question ) {
			return new \WP_Error( 'sitesage_empty', __( 'Please type a question.', 'sitesage-chat' ), array( 'status' => 400 ) );
		}
		if ( mb_strlen( $question ) > $max ) {
			return new \WP_Error(
				'sitesage_too_long',
				/* translators: %d: maximum number of characters. */
				sprintf( __( 'Please keep your question under %d characters.', 'sitesage-chat' ), $max ),
				array( 'status' => 400 )
			);
		}

		$limited = $this->rate_limit( 'ask', (int) Settings::get( 'rate_limit_requests' ), (int) Settings::get( 'rate_limit_minutes' ) * MINUTE_IN_SECONDS );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$service = Plugin::instance()->answer_service();
		if ( is_wp_error( $service ) ) {
			return $this->service_error( $service );
		}

		$result = $service->ask( $question );
		if ( is_wp_error( $result ) ) {
			return $this->service_error( $result );
		}

		$intent_words = Lead_Intent::parse_words( Settings::get( 'lead_intent_words' ) );

		return rest_ensure_response(
			array(
				'answer'      => $result['answer'],
				'sources'     => $result['sources'],
				'fallback'    => $result['fallback'],
				'lead_intent' => Lead_Intent::detect( $question, $intent_words ),
				'mode'        => Settings::is_demo_mode() ? 'demo' : 'live',
			)
		);
	}

	/**
	 * POST /lead.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function lead( $request ) {
		// Honeypot: real visitors never see or fill this field.
		if ( '' !== trim( (string) $request->get_param( 'website' ) ) ) {
			return rest_ensure_response( array( 'saved' => true ) );
		}

		$limited = $this->rate_limit( 'lead', 5, HOUR_IN_SECONDS );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$lead = Lead_Store::validate(
			array(
				'name'     => $request->get_param( 'name' ),
				'phone'    => $request->get_param( 'phone' ),
				'email'    => $request->get_param( 'email' ),
				'question' => $request->get_param( 'question' ),
				'page_url' => $request->get_param( 'page_url' ),
			)
		);
		if ( is_wp_error( $lead ) ) {
			$lead->add_data( array( 'status' => 400 ) );
			return $lead;
		}

		$store = new Lead_Store();
		$id    = $store->insert( $lead );
		if ( is_wp_error( $id ) ) {
			$id->add_data( array( 'status' => 500 ) );
			return $id;
		}

		$webhook = new Sheets_Webhook( (string) Settings::get( 'sheets_webhook_url' ), (string) Settings::get( 'sheets_webhook_secret' ) );
		$store->set_webhook_status( $id, $webhook->send( $lead ) );

		/**
		 * Fires after a lead is saved.
		 *
		 * @param int   $id   Lead ID.
		 * @param array $lead Lead fields.
		 */
		do_action( 'sitesage_lead_saved', $id, $lead );

		return rest_ensure_response(
			array(
				'saved'   => true,
				'message' => __( 'Thanks! Our team will get back to you soon.', 'sitesage-chat' ),
			)
		);
	}

	/**
	 * Apply a rate limit for the current visitor.
	 *
	 * @param string $bucket Bucket.
	 * @param int    $limit  Requests per window.
	 * @param int    $window Window in seconds.
	 * @return true|\WP_Error
	 */
	private function rate_limit( $bucket, $limit, $window ) {
		$limiter = new Rate_Limiter( $bucket, $limit, $window );
		$ip      = Rate_Limiter::client_ip();
		if ( $limiter->hit( $ip ) ) {
			return true;
		}
		return new \WP_Error(
			'sitesage_rate_limited',
			__( 'You are sending messages too quickly. Please wait a few minutes and try again.', 'sitesage-chat' ),
			array(
				'status'      => 429,
				'retry_after' => $limiter->retry_after( $ip ),
			)
		);
	}

	/**
	 * Hide provider details from visitors; log them for the site owner.
	 *
	 * @param \WP_Error $error Error.
	 * @return \WP_Error
	 */
	private function service_error( \WP_Error $error ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug log only when enabled.
			error_log( 'SiteSage Chat: ' . $error->get_error_code() . ' ' . $error->get_error_message() );
		}
		return new \WP_Error(
			'sitesage_unavailable',
			__( 'The assistant is unavailable right now. Please try again later or talk to our team.', 'sitesage-chat' ),
			array( 'status' => 503 )
		);
	}
}
