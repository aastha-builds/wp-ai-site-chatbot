<?php
/**
 * Saved sample answers for demo mode.
 *
 * @package SiteSage
 */

namespace SiteSage\Providers;

use SiteSage\Prompt_Builder;
use SiteSage\Text_Tokenizer;

defined( 'ABSPATH' ) || exit;

/**
 * Answers without an API key.
 *
 * It receives exactly what a live model would receive (the system prompt
 * and the user message holding the retrieved context and the question),
 * then returns a saved sample answer when the question matches one, or a
 * short extract from the best context passage. It never sees anything else.
 */
class Demo_Chat_Provider implements Chat_Provider {

	const MATCH_THRESHOLD = 0.6;
	const MAX_WORDS       = 120;

	/**
	 * Saved answers: each has question, answer and source.
	 *
	 * @var array[]
	 */
	private $answers = array();

	/**
	 * Constructor.
	 *
	 * @param array[] $answers Saved answers.
	 */
	public function __construct( array $answers ) {
		foreach ( $answers as $answer ) {
			if ( isset( $answer['question'], $answer['answer'] ) ) {
				$answer['tokens'] = array_unique( Text_Tokenizer::tokens( $answer['question'] ) );
				$this->answers[]  = $answer;
			}
		}
	}

	/**
	 * Load saved answers from a JSON file.
	 *
	 * @param string $file Path.
	 * @return self
	 */
	public static function from_file( $file ) {
		$answers = array();
		if ( is_readable( $file ) ) {
			$json = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read.
			if ( is_array( $json ) && isset( $json['answers'] ) && is_array( $json['answers'] ) ) {
				$answers = $json['answers'];
			}
		}
		return new self( $answers );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $system System prompt (unused offline, kept for parity with live providers).
	 * @param string $user   User message.
	 * @return string
	 */
	public function generate( $system, $user ) {
		$parsed  = Prompt_Builder::parse_user_message( $user );
		$sources = $parsed['sources'];

		if ( empty( $sources ) || '' === $parsed['question'] ) {
			return Prompt_Builder::fallback_message();
		}

		$saved = $this->best_saved_answer( $parsed['question'] );
		if ( null !== $saved ) {
			if ( empty( $saved['source'] ) || 'none' === strtolower( $saved['source'] ) ) {
				return Prompt_Builder::fallback_message();
			}
			foreach ( $sources as $source ) {
				if ( 0 === strcasecmp( $source['title'], $saved['source'] ) ) {
					return self::with_link( $saved['answer'], $source );
				}
			}
		}

		return $this->extract_answer( $parsed['question'], $sources[0] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label() {
		return 'demo:saved-answers';
	}

	/**
	 * Find the saved answer whose question is closest to the visitor's question.
	 *
	 * @param string $question Visitor question.
	 * @return array|null
	 */
	public function best_saved_answer( $question ) {
		$tokens = array_unique( Text_Tokenizer::tokens( $question ) );
		if ( empty( $tokens ) ) {
			return null;
		}

		$best       = null;
		$best_score = 0.0;
		foreach ( $this->answers as $answer ) {
			$shared = count( array_intersect( $tokens, $answer['tokens'] ) );
			$total  = count( $tokens ) + count( $answer['tokens'] );
			$score  = $total > 0 ? ( 2 * $shared ) / $total : 0.0;
			if ( $score > $best_score ) {
				$best_score = $score;
				$best       = $answer;
			}
		}

		return $best_score >= self::MATCH_THRESHOLD ? $best : null;
	}

	/**
	 * Build a short answer from the sentences of a passage that best match the question.
	 *
	 * @param string $question Visitor question.
	 * @param array  $source   Source with title, url and text.
	 * @return string
	 */
	private function extract_answer( $question, array $source ) {
		$query     = array_unique( Text_Tokenizer::tokens( $question ) );
		$text      = preg_replace( '/^\s*-\s*/m', '', $source['text'] );
		$sentences = preg_split( '/(?<=[.!?])\s+|\n+/u', $text );
		$scored    = array();

		foreach ( $sentences as $position => $sentence ) {
			$sentence = trim( $sentence );
			if ( str_word_count( $sentence ) < 4 ) {
				continue;
			}
			$overlap = count( array_intersect( $query, array_unique( Text_Tokenizer::tokens( $sentence ) ) ) );
			if ( $overlap > 0 ) {
				$scored[] = array(
					'position' => $position,
					'overlap'  => $overlap,
					'text'     => $sentence,
				);
			}
		}

		if ( empty( $scored ) ) {
			return Prompt_Builder::fallback_message();
		}

		usort(
			$scored,
			static function ( $a, $b ) {
				if ( $b['overlap'] !== $a['overlap'] ) {
					return $b['overlap'] - $a['overlap'];
				}
				return $a['position'] - $b['position'];
			}
		);

		$picked = array_slice( $scored, 0, 2 );
		usort(
			$picked,
			static function ( $a, $b ) {
				return $a['position'] - $b['position'];
			}
		);

		$answer = implode( ' ', wp_list_pluck( $picked, 'text' ) );
		$words  = preg_split( '/\s+/u', $answer );
		if ( count( $words ) > self::MAX_WORDS - 10 ) {
			$answer = implode( ' ', array_slice( $words, 0, self::MAX_WORDS - 10 ) ) . '…';
		}

		return self::with_link( $answer, $source );
	}

	/**
	 * Append the Markdown source link the system prompt asks for.
	 *
	 * @param string $answer Answer text.
	 * @param array  $source Source with title and url.
	 * @return string
	 */
	private static function with_link( $answer, array $source ) {
		return sprintf(
			/* translators: 1: answer text, 2: source page title, 3: source page URL. */
			__( '%1$s Read more: [%2$s](%3$s)', 'sitesage-chat' ),
			trim( $answer ),
			$source['title'],
			$source['url']
		);
	}
}
