<?php
/**
 * Splits page text into chunks of about 500 tokens.
 *
 * @package SiteSage
 */

namespace SiteSage;

defined( 'ABSPATH' ) || exit;

/**
 * Paragraph-aware text chunker with a small word overlap between chunks.
 */
class Chunker {

	/**
	 * Target chunk size in estimated tokens.
	 *
	 * @var int
	 */
	private $target;

	/**
	 * Overlap between neighbouring chunks in estimated tokens.
	 *
	 * @var int
	 */
	private $overlap;

	/**
	 * Constructor.
	 *
	 * @param int $target  Target tokens per chunk.
	 * @param int $overlap Overlap tokens between chunks.
	 */
	public function __construct( $target = 500, $overlap = 50 ) {
		$this->target  = max( 20, (int) $target );
		$this->overlap = max( 0, min( (int) $overlap, (int) floor( $this->target / 2 ) ) );
	}

	/**
	 * Estimate tokens the way most English tokenizers behave: about 0.75 words per token.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function estimate_tokens( $text ) {
		$words = self::word_count( $text );
		return (int) ceil( $words * 4 / 3 );
	}

	/**
	 * Count whitespace-separated words.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function word_count( $text ) {
		return (int) preg_match_all( '/\S+/u', (string) $text );
	}

	/**
	 * Turn post HTML into plain text, keeping paragraph breaks.
	 *
	 * @param string $html HTML or block markup.
	 * @return string
	 */
	public static function html_to_text( $html ) {
		$html = (string) $html;
		$html = preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', ' ', $html );
		$html = preg_replace( '#<!--.*?-->#s', '', $html );
		$html = preg_replace( '#<(ul|ol)[^>]*>#i', "\n", $html );
		$html = preg_replace( '#<li[^>]*>#i', '- ', $html );
		$html = preg_replace( '#<br\s*/?>#i', "\n", $html );
		$html = preg_replace( '#</(p|h[1-6]|div|ul|ol|table|tr|blockquote|figure|section|article|pre)>#i', "\n\n", $html );
		$html = preg_replace( '#</(li|td|th|dt|dd)>#i', "\n", $html );

		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( array( "\r\n", "\r", "\xC2\xA0" ), array( "\n", "\n", ' ' ), $text );
		$text = preg_replace( '/[ \t]+/u', ' ', $text );
		$text = preg_replace( '/ *\n */u', "\n", $text );
		$text = preg_replace( "/\n(- ?\n)+/", "\n", $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		$text = preg_replace( "/^- [^\n]*\n\K\n(?=- )/m", '', $text );

		return trim( $text );
	}

	/**
	 * Split text into chunks.
	 *
	 * @param string $text Plain text.
	 * @return string[] Chunks in reading order.
	 */
	public function split( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return array();
		}

		$units  = $this->units( $text );
		$chunks = array();
		$buffer = '';
		$size   = 0;

		foreach ( $units as $unit ) {
			$unit_size = self::estimate_tokens( $unit['text'] );

			if ( '' !== $buffer && $size + $unit_size > $this->target ) {
				$chunks[] = $buffer;
				$carry    = $this->tail_words( $buffer );
				$buffer   = '' === $carry ? '' : $carry;
				$size     = self::estimate_tokens( $buffer );
			}

			if ( '' === $buffer ) {
				$buffer = $unit['text'];
			} else {
				$buffer .= ( $unit['new_paragraph'] ? "\n\n" : ' ' ) . $unit['text'];
			}
			$size += $unit_size;
		}

		if ( '' !== trim( $buffer ) ) {
			$chunks[] = $buffer;
		}

		return array_values(
			array_filter(
				array_map( 'trim', $chunks ),
				static function ( $chunk ) {
					return '' !== $chunk;
				}
			)
		);
	}

	/**
	 * Break text into units no bigger than the target: paragraphs, then sentences, then word runs.
	 *
	 * @param string $text Plain text.
	 * @return array[] Each unit has 'text' and 'new_paragraph'.
	 */
	private function units( $text ) {
		$units      = array();
		$paragraphs = preg_split( "/\n\s*\n/u", $text );

		foreach ( $paragraphs as $paragraph ) {
			$paragraph = trim( $paragraph );
			if ( '' === $paragraph ) {
				continue;
			}

			if ( self::estimate_tokens( $paragraph ) <= $this->target ) {
				$units[] = array(
					'text'          => $paragraph,
					'new_paragraph' => true,
				);
				continue;
			}

			$first     = true;
			$sentences = preg_split( '/(?<=[.!?])\s+/u', $paragraph );
			foreach ( $sentences as $sentence ) {
				foreach ( $this->split_long( $sentence ) as $piece ) {
					$units[] = array(
						'text'          => $piece,
						'new_paragraph' => $first,
					);
					$first   = false;
				}
			}
		}

		return $units;
	}

	/**
	 * Split a sentence that alone is bigger than the target into word runs.
	 *
	 * @param string $sentence Sentence.
	 * @return string[]
	 */
	private function split_long( $sentence ) {
		if ( self::estimate_tokens( $sentence ) <= $this->target ) {
			return array( $sentence );
		}
		$words    = preg_split( '/\s+/u', trim( $sentence ) );
		$per_part = max( 1, (int) floor( $this->target * 3 / 4 ) );
		$parts    = array();
		foreach ( array_chunk( $words, $per_part ) as $run ) {
			$parts[] = implode( ' ', $run );
		}
		return $parts;
	}

	/**
	 * Last words of a chunk, carried into the next chunk as overlap.
	 *
	 * @param string $chunk Finished chunk.
	 * @return string
	 */
	private function tail_words( $chunk ) {
		if ( $this->overlap <= 0 ) {
			return '';
		}
		$words = preg_split( '/\s+/u', trim( $chunk ) );
		$keep  = (int) floor( $this->overlap * 3 / 4 );
		if ( $keep <= 0 || count( $words ) <= $keep ) {
			return '';
		}
		return implode( ' ', array_slice( $words, -$keep ) );
	}
}
