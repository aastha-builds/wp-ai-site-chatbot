<?php
/**
 * Minimal text extraction for simple, text-based PDF files.
 *
 * @package SiteSage
 */

namespace SiteSage;

defined( 'ABSPATH' ) || exit;

/**
 * Reads text drawn with the Tj, TJ, ' and " operators from (optionally
 * Flate-compressed) content streams. It covers PDFs exported from word
 * processors with standard fonts. Scanned PDFs and CID/Unicode-mapped fonts
 * are not supported; use a full parser for those (see the README).
 */
class PDF_Text_Extractor {

	/**
	 * Extract text from a PDF file on disk.
	 *
	 * @param string $path File path.
	 * @return string Extracted text, or an empty string.
	 */
	public static function from_file( $path ) {
		if ( ! is_readable( $path ) ) {
			return '';
		}
		$data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read.
		return false === $data ? '' : self::from_string( $data );
	}

	/**
	 * Extract text from raw PDF bytes.
	 *
	 * @param string $data PDF bytes.
	 * @return string
	 */
	public static function from_string( $data ) {
		if ( 0 !== strpos( (string) $data, '%PDF' ) ) {
			return '';
		}

		$pages = array();
		if ( ! preg_match_all( '#\bobj((?:(?!endobj).)*?)stream\r?\n(.*?)\r?\n?endstream#s', $data, $matches, PREG_SET_ORDER ) ) {
			return '';
		}

		foreach ( $matches as $match ) {
			$dictionary = $match[1];
			$stream     = self::decode_stream( $dictionary, $match[2] );

			if ( null === $stream || false === strpos( $stream, 'BT' ) ) {
				continue;
			}

			$text = self::text_from_content_stream( $stream );
			if ( '' !== trim( $text ) ) {
				$pages[] = trim( $text );
			}
		}

		return trim( implode( "\n\n", $pages ) );
	}

	/**
	 * Apply the stream filters listed in the object dictionary.
	 *
	 * @param string $dictionary Object dictionary.
	 * @param string $stream     Raw stream bytes.
	 * @return string|null Decoded bytes, or null for images and unsupported filters.
	 */
	private static function decode_stream( $dictionary, $stream ) {
		if ( preg_match( '#/Subtype\s*/Image#', $dictionary ) ) {
			return null;
		}

		$filters = array();
		if ( preg_match( '#/Filter\s*\[([^\]]*)\]#', $dictionary, $list ) ) {
			preg_match_all( '#/(\w+)#', $list[1], $names );
			$filters = $names[1];
		} elseif ( preg_match( '#/Filter\s*/(\w+)#', $dictionary, $single ) ) {
			$filters = array( $single[1] );
		}

		foreach ( $filters as $filter ) {
			if ( 'FlateDecode' === $filter ) {
				$stream = self::inflate( $stream );
			} elseif ( 'ASCII85Decode' === $filter ) {
				$stream = self::ascii85_decode( $stream );
			} else {
				return null;
			}
			if ( null === $stream ) {
				return null;
			}
		}

		return $stream;
	}

	/**
	 * Decode ASCII85 data.
	 *
	 * @param string $data Encoded data.
	 * @return string|null
	 */
	public static function ascii85_decode( $data ) {
		$data = preg_replace( '/\s+/', '', (string) $data );
		if ( 0 === strpos( $data, '<~' ) ) {
			$data = substr( $data, 2 );
		}
		$end = strpos( $data, '~>' );
		if ( false !== $end ) {
			$data = substr( $data, 0, $end );
		}

		$out    = '';
		$group  = array();
		$length = strlen( $data );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $data[ $i ];
			if ( 'z' === $char && empty( $group ) ) {
				$out .= "\0\0\0\0";
				continue;
			}
			$code = ord( $char ) - 33;
			if ( $code < 0 || $code > 84 ) {
				return null;
			}
			$group[] = $code;
			if ( 5 === count( $group ) ) {
				$out  .= self::ascii85_group( $group, 4 );
				$group = array();
			}
		}

		if ( ! empty( $group ) ) {
			$bytes = count( $group ) - 1;
			$group = array_pad( $group, 5, 84 );
			$out  .= self::ascii85_group( $group, $bytes );
		}

		return $out;
	}

	/**
	 * Turn five base-85 digits into up to four bytes.
	 *
	 * @param int[] $group Five digits.
	 * @param int   $bytes Bytes to keep.
	 * @return string
	 */
	private static function ascii85_group( array $group, $bytes ) {
		$value = 0;
		foreach ( $group as $digit ) {
			$value = $value * 85 + $digit;
		}
		$packed = pack( 'N', $value & 0xFFFFFFFF );
		return substr( $packed, 0, $bytes );
	}

	/**
	 * Decompress a Flate stream.
	 *
	 * @param string $stream Compressed bytes.
	 * @return string|null
	 */
	private static function inflate( $stream ) {
		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- Corrupt streams are skipped, not reported.
		$out = @gzuncompress( $stream );
		if ( false === $out ) {
			$out = @gzinflate( $stream );
		}
		if ( false === $out ) {
			$out = @gzinflate( substr( $stream, 2 ) );
		}
		// phpcs:enable
		return false === $out ? null : $out;
	}

	/**
	 * Walk a content stream and collect text operators in order.
	 *
	 * @param string $stream Decoded content stream.
	 * @return string
	 */
	public static function text_from_content_stream( $stream ) {
		$out    = '';
		$length = strlen( $stream );
		$i      = 0;
		$last   = '';

		while ( $i < $length ) {
			$char = $stream[ $i ];

			if ( '(' === $char ) {
				list( $string, $i ) = self::read_literal( $stream, $i );
				$last              .= $string;
				continue;
			}

			if ( '[' === $char ) {
				++$i;
				continue;
			}

			if ( ctype_alpha( $char ) || "'" === $char || '"' === $char || '*' === $char ) {
				$start = $i;
				while ( $i < $length && ( ctype_alpha( $stream[ $i ] ) || '*' === $stream[ $i ] || "'" === $stream[ $i ] || '"' === $stream[ $i ] ) ) {
					++$i;
				}
				$operator = substr( $stream, $start, $i - $start );

				if ( in_array( $operator, array( 'Tj', 'TJ', "'", '"' ), true ) ) {
					if ( "'" === $operator || '"' === $operator ) {
						$out .= "\n";
					}
					$out .= $last;
				} elseif ( in_array( $operator, array( 'Td', 'TD', 'T*', 'ET' ), true ) && "\n" !== substr( $out, -1 ) ) {
					$out .= "\n";
				}
				$last = '';
				continue;
			}

			++$i;
		}

		$out = preg_replace( "/[ \t]+/", ' ', $out );
		$out = preg_replace( "/ *\n */", "\n", $out );
		$out = preg_replace( "/\n{3,}/", "\n\n", $out );

		return trim( $out );
	}

	/**
	 * Read a literal string starting at an opening parenthesis.
	 *
	 * @param string $stream Content stream.
	 * @param int    $i      Position of the opening parenthesis.
	 * @return array Decoded string and the position after the closing parenthesis.
	 */
	private static function read_literal( $stream, $i ) {
		$length = strlen( $stream );
		$depth  = 1;
		$out    = '';
		++$i;

		$escapes = array(
			'n'  => "\n",
			'r'  => "\r",
			't'  => "\t",
			'b'  => '',
			'f'  => '',
			'('  => '(',
			')'  => ')',
			'\\' => '\\',
		);

		while ( $i < $length && $depth > 0 ) {
			$char = $stream[ $i ];
			if ( '\\' === $char && $i + 1 < $length ) {
				$next = $stream[ $i + 1 ];
				if ( isset( $escapes[ $next ] ) ) {
					$out .= $escapes[ $next ];
					$i   += 2;
					continue;
				}
				if ( ctype_digit( $next ) ) {
					$octal = '';
					$j     = $i + 1;
					$stop  = min( $length, $j + 3 );
					while ( $j < $stop && ctype_digit( $stream[ $j ] ) ) {
						$octal .= $stream[ $j ];
						++$j;
					}
					$out .= self::win_ansi_char( octdec( $octal ) );
					$i    = $j;
					continue;
				}
				$i += 2;
				continue;
			}
			if ( '(' === $char ) {
				++$depth;
			} elseif ( ')' === $char ) {
				--$depth;
				if ( 0 === $depth ) {
					++$i;
					break;
				}
			}
			$out .= ( ord( $char ) > 127 ) ? self::win_ansi_char( ord( $char ) ) : $char;
			++$i;
		}

		return array( $out, $i );
	}

	/**
	 * Convert a WinAnsi byte to UTF-8.
	 *
	 * @param int $code Byte value.
	 * @return string
	 */
	private static function win_ansi_char( $code ) {
		$map = array(
			0x80 => '€',
			0x85 => '…',
			0x91 => '‘',
			0x92 => '’',
			0x93 => '“',
			0x94 => '”',
			0x95 => '•',
			0x96 => '–',
			0x97 => '—',
		);
		if ( isset( $map[ $code ] ) ) {
			return $map[ $code ];
		}
		if ( $code < 128 ) {
			return chr( $code );
		}
		return mb_convert_encoding( chr( $code ), 'UTF-8', 'ISO-8859-1' );
	}
}
