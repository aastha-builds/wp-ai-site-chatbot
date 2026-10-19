<?php
/**
 * Tests for the OpenAI and Anthropic request and response handling.
 *
 * @package SiteSage
 */

use PHPUnit\Framework\TestCase;
use SiteSage\Providers\Anthropic_Provider;
use SiteSage\Providers\OpenAI_Provider;

class ProvidersTest extends TestCase {

	protected function setUp(): void {
		SiteSage_Test_State::reset();
	}

	private static function response( $code, $body ) {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => json_encode( $body ),
		);
	}

	public function test_anthropic_request_shape() {
		$provider = new Anthropic_Provider( 'sk-ant-test', 'claude-opus-5-5' );
		$body     = $provider->request_body( 'SYSTEM', 'USER' );
		$headers  = $provider->request_headers();

		$this->assertSame( 'claude-opus-5-5', $body['model'] );
		$this->assertSame( 'SYSTEM', $body['system'] );
		$this->assertSame( array( array( 'role' => 'user', 'content' => 'USER' ) ), $body['messages'] );
		$this->assertArrayNotHasKey( 'temperature', $body );
		$this->assertSame( array( 'effort' => 'low' ), $body['output_config'] );
		$this->assertSame( 'default', $body['fallbacks'] );
		$this->assertSame( 'sk-ant-test', $headers['x-api-key'] );
		$this->assertSame( '2023-06-01', $headers['anthropic-version'] );
		$this->assertSame( 'server-side-fallback-2026-07-01', $headers['anthropic-beta'] );
	}

	public function test_anthropic_older_models_get_no_new_parameters() {
		$provider = new Anthropic_Provider( 'key', 'claude-haiku-4-5' );
		$body     = $provider->request_body( 's', 'u' );
		$this->assertArrayNotHasKey( 'output_config', $body );
		$this->assertArrayNotHasKey( 'fallbacks', $body );
		$this->assertArrayNotHasKey( 'anthropic-beta', $provider->request_headers() );
	}

	public function test_anthropic_reads_text_blocks_only() {
		$text = Anthropic_Provider::text_from_response(
			array(
				'stop_reason' => 'end_turn',
				'content'     => array(
					array( 'type' => 'thinking', 'thinking' => '' ),
					array( 'type' => 'text', 'text' => 'Whitening is $399.' ),
				),
			)
		);
		$this->assertSame( 'Whitening is $399.', $text );
	}

	public function test_anthropic_refusal_and_empty_answers_are_errors() {
		$refusal = Anthropic_Provider::text_from_response( array( 'stop_reason' => 'refusal', 'content' => array() ) );
		$this->assertSame( 'sitesage_refusal', $refusal->get_error_code() );
		$empty = Anthropic_Provider::text_from_response( array( 'content' => array() ) );
		$this->assertSame( 'sitesage_empty_answer', $empty->get_error_code() );
	}

	public function test_anthropic_generate_posts_to_messages_api() {
		SiteSage_Test_State::$http[] = self::response( 200, array( 'content' => array( array( 'type' => 'text', 'text' => 'Hi' ) ) ) );
		$this->assertSame( 'Hi', ( new Anthropic_Provider( 'key' ) )->generate( 's', 'u' ) );
		$this->assertSame( 'https://api.anthropic.com/v1/messages', SiteSage_Test_State::$requests[0]['url'] );
	}

	public function test_openai_chat_and_errors() {
		SiteSage_Test_State::$http[] = self::response( 200, array( 'choices' => array( array( 'message' => array( 'content' => ' Answer ' ) ) ) ) );
		SiteSage_Test_State::$http[] = self::response( 401, array( 'error' => array( 'message' => 'Bad key' ) ) );
		$provider = new OpenAI_Provider( 'sk-test', 'gpt-4.1-mini' );

		$this->assertSame( 'Answer', $provider->generate( 'SYSTEM', 'USER' ) );
		$sent = json_decode( SiteSage_Test_State::$requests[0]['args']['body'], true );
		$this->assertSame( 'system', $sent['messages'][0]['role'] );
		$this->assertSame( 'USER', $sent['messages'][1]['content'] );
		$this->assertSame( 'Bearer sk-test', SiteSage_Test_State::$requests[0]['args']['headers']['Authorization'] );

		$error = $provider->generate( 's', 'u' );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertStringContainsString( 'Bad key', $error->get_error_message() );
	}

	public function test_openai_embeddings_keep_input_order() {
		SiteSage_Test_State::$http[] = self::response(
			200,
			array(
				'data' => array(
					array( 'index' => 1, 'embedding' => array( 0, 1 ) ),
					array( 'index' => 0, 'embedding' => array( 1, 0 ) ),
				),
			)
		);
		$vectors = ( new OpenAI_Provider( 'k' ) )->embed( array( 'first', 'second' ) );
		$this->assertSame( array( array( 1.0, 0.0 ), array( 0.0, 1.0 ) ), $vectors );
		$this->assertSame( 'openai:text-embedding-3-small', ( new OpenAI_Provider( 'k' ) )->model_id() );
	}
}
