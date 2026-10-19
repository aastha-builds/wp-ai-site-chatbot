<?php
/**
 * OpenAI chat completions and embeddings.
 *
 * @package SiteSage
 */

namespace SiteSage\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to api.openai.com with the site's API key.
 */
class OpenAI_Provider implements Chat_Provider, Embedding_Provider {

	const CHAT_URL       = 'https://api.openai.com/v1/chat/completions';
	const EMBEDDINGS_URL = 'https://api.openai.com/v1/embeddings';

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Chat model.
	 *
	 * @var string
	 */
	private $model;

	/**
	 * Embedding model.
	 *
	 * @var string
	 */
	private $embedding_model;

	/**
	 * Constructor.
	 *
	 * @param string $api_key         API key.
	 * @param string $model           Chat model name.
	 * @param string $embedding_model Embedding model name.
	 */
	public function __construct( $api_key, $model = 'gpt-4.1-mini', $embedding_model = 'text-embedding-3-small' ) {
		$this->api_key         = (string) $api_key;
		$this->model           = (string) $model;
		$this->embedding_model = (string) $embedding_model;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $system System prompt.
	 * @param string $user   User message.
	 * @return string|\WP_Error
	 */
	public function generate( $system, $user ) {
		$body = array(
			'model'                 => $this->model,
			'max_completion_tokens' => 1024,
			'messages'              => array(
				array(
					'role'    => 'system',
					'content' => $system,
				),
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
		);

		$result = Http::post_json( self::CHAT_URL, $this->headers(), apply_filters( 'sitesage_openai_chat_body', $body ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( empty( $result['choices'][0]['message']['content'] ) ) {
			return new \WP_Error( 'sitesage_empty_answer', __( 'The AI service returned an empty answer.', 'sitesage-chat' ) );
		}
		return trim( (string) $result['choices'][0]['message']['content'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string[] $texts Texts.
	 * @return array|\WP_Error
	 */
	public function embed( array $texts ) {
		$vectors = array();
		foreach ( array_chunk( array_values( $texts ), 64 ) as $batch ) {
			$result = Http::post_json(
				self::EMBEDDINGS_URL,
				$this->headers(),
				array(
					'model' => $this->embedding_model,
					'input' => $batch,
				),
				60
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( empty( $result['data'] ) || count( $result['data'] ) !== count( $batch ) ) {
				return new \WP_Error( 'sitesage_bad_embeddings', __( 'The embeddings response did not match the request.', 'sitesage-chat' ) );
			}
			usort(
				$result['data'],
				static function ( $a, $b ) {
					return (int) $a['index'] - (int) $b['index'];
				}
			);
			foreach ( $result['data'] as $row ) {
				$vectors[] = array_map( 'floatval', $row['embedding'] );
			}
		}
		return $vectors;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function model_id() {
		return 'openai:' . $this->embedding_model;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label() {
		return 'openai:' . $this->model;
	}

	/**
	 * Auth header.
	 *
	 * @return array
	 */
	private function headers() {
		return array( 'Authorization' => 'Bearer ' . $this->api_key );
	}
}
