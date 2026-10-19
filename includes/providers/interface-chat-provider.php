<?php
/**
 * Chat provider contract.
 *
 * @package SiteSage
 */

namespace SiteSage\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a system prompt and one user message into an answer.
 */
interface Chat_Provider {

	/**
	 * Generate an answer.
	 *
	 * @param string $system System prompt.
	 * @param string $user   User message (retrieved context plus the question).
	 * @return string|\WP_Error Answer text or an error.
	 */
	public function generate( $system, $user );

	/**
	 * Short label for logs and the admin screen, for example "openai:gpt-4.1-mini".
	 *
	 * @return string
	 */
	public function label();
}
