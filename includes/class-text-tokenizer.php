<?php
/**
 * Small keyword tokenizer used by the offline demo embedder and demo answers.
 *
 * @package SiteSage
 */

namespace SiteSage;

defined( 'ABSPATH' ) || exit;

/**
 * Lowercases, drops stopwords, applies light stemming and maps common synonyms.
 */
class Text_Tokenizer {

	/**
	 * Common English words that carry no meaning for retrieval, space-separated.
	 *
	 * @var string
	 */
	const STOPWORDS = 'a about after again all also am an and any are as at be because been before being both but by '
		. 'can could did do does doing done each else etc every few for from get got had has have having he '
		. 'her here hi him his how i if in into is it its just let like me might more most my need no not '
		. 'of off on once one only or other our ours out over own please same she should so some such than '
		. 'that the their them then there these they this those through to too under until up us very via '
		. 'want was way we well were what when which while who whom why will with would yes you your yours '
		. 've ll re s t d m dental dentistry clinic practice tell know much many offer really bad good help '
		. 'take see make give keep come go usually often able way thing';

	/**
	 * Synonyms mapped to one canonical token (applied after stemming).
	 *
	 * @var array<string,string>
	 */
	const SYNONYMS = array(
		'cost'        => 'price',
		'fee'         => 'price',
		'charge'      => 'price',
		'pric'        => 'price',
		'expensive'   => 'price',
		'cheap'       => 'price',
		'afford'      => 'price',
		'affordable'  => 'price',
		'kid'         => 'child',
		'children'    => 'child',
		'toddler'     => 'child',
		'son'         => 'child',
		'daughter'    => 'child',
		'pediatric'   => 'child',
		'baby'        => 'child',
		'toothache'   => 'emergency',
		'urgent'      => 'emergency',
		'nervous'     => 'anxious',
		'anxiety'     => 'anxious',
		'scar'        => 'anxious',
		'scared'      => 'anxious',
		'afraid'      => 'anxious',
		'fear'        => 'anxious',
		'phobia'      => 'anxious',
		'weekend'     => 'saturday',
		'hour'        => 'hours',
		'open'        => 'hours',
		'clos'        => 'hours',
		'close'       => 'hours',
		'park'        => 'parking',
		'whiten'      => 'whitening',
		'white'       => 'whitening',
		'whiter'      => 'whitening',
		'bleach'      => 'whitening',
		'stain'       => 'whitening',
		'yellow'      => 'whitening',
		'aligner'     => 'aligners',
		'invisalign'  => 'aligners',
		'brace'       => 'aligners',
		'straighten'  => 'aligners',
		'crooked'     => 'aligners',
		'insur'       => 'insurance',
		'insurer'     => 'insurance',
		'ppo'         => 'insurance',
		'coverage'    => 'insurance',
		'cover'       => 'insurance',
		'book'        => 'appointment',
		'schedul'     => 'appointment',
		'schedule'    => 'appointment',
		'reschedul'   => 'cancel',
		'reschedule'  => 'cancel',
		'pay'         => 'payment',
		'financ'      => 'payment',
		'finance'     => 'payment',
		'installment' => 'payment',
		'clean'       => 'cleaning',
		'hygien'      => 'cleaning',
		'hygienist'   => 'cleaning',
		'checkup'     => 'cleaning',
		'radiograph'  => 'xray',
		'spanish'     => 'language',
		'speak'       => 'language',
		'paperwork'   => 'form',
		'locat'       => 'location',
		'address'     => 'location',
		'direction'   => 'location',
		'where'       => 'location',
		'teeth'       => 'tooth',
		'doctor'      => 'dentist',
		'dr'          => 'dentist',
		'dds'         => 'dentist',
		'dmd'         => 'dentist',
		'dentists'    => 'dentist',
	);

	/**
	 * Tokenize text into normalized keyword tokens.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function tokens( $text ) {
		$text = strtolower( (string) $text );
		$text = preg_replace( '/x-?rays?/', 'xray', $text );
		$text = preg_replace( '/check-?ups?/', 'checkup', $text );
		$text = preg_replace( '/[^a-z0-9]+/', ' ', $text );
		$raw  = preg_split( '/\s+/', trim( $text ) );

		$stopwords = array_flip( explode( ' ', self::STOPWORDS ) );
		$tokens    = array();

		foreach ( $raw as $word ) {
			if ( '' === $word || isset( $stopwords[ $word ] ) || ctype_digit( $word ) ) {
				continue;
			}
			$token = self::canonical( $word );
			if ( '' !== $token && ! isset( $stopwords[ $token ] ) ) {
				$tokens[] = $token;
			}
		}

		return $tokens;
	}

	/**
	 * Map a word to its canonical token.
	 *
	 * @param string $word Lowercase word.
	 * @return string
	 */
	public static function canonical( $word ) {
		static $targets = null;
		if ( null === $targets ) {
			$targets = array_flip( array_values( self::SYNONYMS ) );
		}
		if ( isset( $targets[ $word ] ) ) {
			return $word;
		}
		if ( isset( self::SYNONYMS[ $word ] ) ) {
			return self::SYNONYMS[ $word ];
		}
		$stem = self::stem( $word );
		if ( isset( self::SYNONYMS[ $stem ] ) ) {
			return self::SYNONYMS[ $stem ];
		}
		return $stem;
	}

	/**
	 * Very light English suffix stripping.
	 *
	 * @param string $word Lowercase word.
	 * @return string
	 */
	public static function stem( $word ) {
		$len = strlen( $word );
		if ( $len > 4 && 'ies' === substr( $word, -3 ) ) {
			$word = substr( $word, 0, -3 ) . 'y';
		} elseif ( $len > 3 && 's' === substr( $word, -1 ) && 'ss' !== substr( $word, -2 ) && 'us' !== substr( $word, -2 ) ) {
			$word = substr( $word, 0, -1 );
		}

		$len = strlen( $word );
		if ( $len > 5 && 'ing' === substr( $word, -3 ) ) {
			$word = substr( $word, 0, -3 );
		} elseif ( $len > 4 && 'ed' === substr( $word, -2 ) ) {
			$word = substr( $word, 0, -2 );
		} elseif ( $len > 4 && 'e' === substr( $word, -1 ) ) {
			$word = substr( $word, 0, -1 );
		}

		return $word;
	}
}
