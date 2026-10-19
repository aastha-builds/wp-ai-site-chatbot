<?php
/**
 * Decides when to ask a visitor for contact details.
 *
 * @package SiteSage
 */

namespace SiteSage;

defined( 'ABSPATH' ) || exit;

/**
 * Detects buying-intent words such as "book", "appointment" or "price".
 */
class Lead_Intent {

	/**
	 * Parse a comma-separated word list from the settings.
	 *
	 * @param string $csv Words separated by commas.
	 * @return string[] Lowercase words.
	 */
	public static function parse_words( $csv ) {
		$words = array();
		foreach ( explode( ',', strtolower( (string) $csv ) ) as $word ) {
			$word = trim( preg_replace( '/[^a-z0-9\s\'-]/', '', $word ) );
			if ( '' !== $word ) {
				$words[] = $word;
			}
		}
		return array_values( array_unique( $words ) );
	}

	/**
	 * Whether a message contains an intent word. Matches word starts, so
	 * "book" also matches "booking" and "price" also matches "prices".
	 *
	 * @param string   $message Visitor message.
	 * @param string[] $words   Intent words.
	 * @return bool
	 */
	public static function detect( $message, array $words ) {
		$message = strtolower( (string) $message );
		foreach ( $words as $word ) {
			if ( '' === $word ) {
				continue;
			}
			if ( preg_match( '/\b' . preg_quote( $word, '/' ) . '[a-z]*\b/', $message ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the widget should show the lead form now.
	 *
	 * @param int  $question_count Questions asked so far, including this one.
	 * @param bool $has_intent     Whether this message has an intent word.
	 * @param int  $threshold      Ask after this many questions (0 disables the count rule).
	 * @param bool $already_asked  Whether the form was already shown or completed.
	 * @return bool
	 */
	public static function should_ask( $question_count, $has_intent, $threshold, $already_asked ) {
		if ( $already_asked ) {
			return false;
		}
		if ( $has_intent ) {
			return true;
		}
		return (int) $threshold > 0 && (int) $question_count >= (int) $threshold;
	}
}
