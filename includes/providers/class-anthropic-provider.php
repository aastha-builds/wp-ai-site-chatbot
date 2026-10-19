<?php
/**
 * Anthropic Claude Messages API.
 *
 * @package SiteSage
 */

namespace SiteSage\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to api.anthropic.com with the site's API key.
 */
class Anthropic_Provider implements Chat_Provider {

	const URL         = 'https://api.anthropic.com/v1/messages';
	const API_VERSION = '2023-06-01';

	/**
	 * Models that accept the server-side refusal fallback ("fallbacks": "default").
	 *
	 * @var string[]
	 */
	const FALLBACK_MODELS = array( 'claude-opus-5-5', 'claude-opus-5', 'claude-fable-5-1', 'claude-sonnet-5-5' );

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Model name.
	 *
	 * @var string
	 */
	private $model;

	/**
	 * Constructor.
	 *
	 * @param string $api_key API key.
	 * @param string $model   Model name.
	 */
	public function __construct( $api_key, $model = 'claude-opus-5-5' ) {
		$this->api_key = (string) $api_key;
		$this->model   = (string) $model;
	}

	/**
	 * Build the request body. Public so the unit tests can check it.
	 *
	 * @param string $system System prompt.
	 * @param string $user   User message.
	 * @return array
	 */
	public function request_body( $system, $user ) {
		$body = array(
			'model'      => $this->model,
			'max_tokens' => 4096,
			'system'     => $system,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
		);

		// Claude 5 generation models take an effort level; short support answers need little thinking.
		if ( preg_match( '/^claude-(opus|sonnet|haiku|fable)-5/', $this->model ) ) {
			$body['output_config'] = array( 'effort' => 'low' );
		}

		if ( $this->uses_fallbacks() ) {
			$body['fallbacks'] = 'default';
		}

		return apply_filters( 'sitesage_anthropic_body', $body );
	}

	/**
	 * Request headers. Public so the unit tests can check them.
	 *
	 * @return array
	 */
	public function request_headers() {
		$headers = array(
			'x-api-key'         => $this->api_key,
			'anthropic-version' => self::API_VERSION,
		);
		if ( $this->uses_fallbacks() ) {
			$headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
		}
		return $headers;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $system System prompt.
	 * @param string $user   User message.
	 * @return string|\WP_Error
	 */
	public function generate( $system, $user ) {
		$result = Http::post_json( self::URL, $this->request_headers(), $this->request_body( $system, $user ), 60 );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::text_from_response( $result );
	}

	/**
	 * Pull the answer text out of a Messages API response.
	 *
	 * Only "text" blocks are read; thinking blocks are skipped. A refusal
	 * becomes an error so the chat shows the handoff message instead.
	 *
	 * @param array $response Decoded response.
	 * @return string|\WP_Error
	 */
	public static function text_from_response( array $response ) {
		if ( isset( $response['stop_reason'] ) && 'refusal' === $response['stop_reason'] ) {
			return new \WP_Error( 'sitesage_refusal', __( 'The AI service declined to answer.', 'sitesage-chat' ) );
		}

		$text = '';
		if ( ! empty( $response['content'] ) && is_array( $response['content'] ) ) {
			foreach ( $response['content'] as $block ) {
				if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
					$text .= $block['text'];
				}
			}
		}

		$text = trim( $text );
		if ( '' === $text ) {
			return new \WP_Error( 'sitesage_empty_answer', __( 'The AI service returned an empty answer.', 'sitesage-chat' ) );
		}
		return $text;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label() {
		return 'anthropic:' . $this->model;
	}

	/**
	 * Whether to request the server-side refusal fallback for this model.
	 *
	 * @return bool
	 */
	private function uses_fallbacks() {
		return in_array( $this->model, self::FALLBACK_MODELS, true );
	}
}
