<?php
/**
 * Runs the test questions through the real answer pipeline and grades them.
 *
 * @package SiteSage
 */

namespace SiteSage;

defined( 'ABSPATH' ) || exit;

/**
 * A question passes when the expected page is one of the source links shown
 * under the answer. Questions whose expected source is "none" pass when the
 * bot gives the "I don't know" reply. The report also shows the stricter
 * top-1 retrieval rank.
 */
class Evaluator {

	/**
	 * Answer service.
	 *
	 * @var Answer_Service
	 */
	private $service;

	/**
	 * Constructor.
	 *
	 * @param Answer_Service $service Answer service.
	 */
	public function __construct( Answer_Service $service ) {
		$this->service = $service;
	}

	/**
	 * Read test questions from a CSV with the columns question,expected_source.
	 *
	 * @param string $path CSV path.
	 * @return array[]|\WP_Error Each with question and expected.
	 */
	public static function load_questions( $path ) {
		if ( ! is_readable( $path ) ) {
			/* translators: %s: file path. */
			return new \WP_Error( 'sitesage_eval_csv', sprintf( __( 'Cannot read %s', 'sitesage-chat' ), $path ) );
		}

		$questions = array();
		$handle    = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local CSV read.
		$header    = fgetcsv( $handle, 0, ',', '"', '\\' );
		$columns   = array_flip( array_map( 'trim', (array) $header ) );

		if ( ! isset( $columns['question'], $columns['expected_source'] ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local CSV read.
			return new \WP_Error( 'sitesage_eval_columns', __( 'The CSV needs question and expected_source columns.', 'sitesage-chat' ) );
		}

		while ( false !== ( $row = fgetcsv( $handle, 0, ',', '"', '\\' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Standard CSV loop.
			$question = isset( $row[ $columns['question'] ] ) ? trim( $row[ $columns['question'] ] ) : '';
			if ( '' === $question ) {
				continue;
			}
			$questions[] = array(
				'question' => $question,
				'expected' => isset( $row[ $columns['expected_source'] ] ) ? trim( $row[ $columns['expected_source'] ] ) : '',
			);
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local CSV read.

		return $questions;
	}

	/**
	 * Grade one answer.
	 *
	 * @param string $expected Expected source title, or "none".
	 * @param array  $result   Result from Answer_Service::ask().
	 * @return bool
	 */
	public static function grade( $expected, array $result ) {
		if ( '' === $expected || 'none' === strtolower( $expected ) ) {
			return ! empty( $result['fallback'] );
		}
		if ( ! empty( $result['fallback'] ) || empty( $result['sources'] ) ) {
			return false;
		}
		foreach ( $result['sources'] as $source ) {
			if ( self::same_title( $source['title'], $expected ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Compare titles, ignoring case and curly quotes.
	 *
	 * @param string $a Title.
	 * @param string $b Title.
	 * @return bool
	 */
	public static function same_title( $a, $b ) {
		$normalize = static function ( $title ) {
			return strtolower( trim( str_replace( array( '’', '‘', '“', '”' ), array( "'", "'", '"', '"' ), (string) $title ) ) );
		};
		return $normalize( $a ) === $normalize( $b );
	}

	/**
	 * Rank (1-4) of the expected source among the retrieved chunks, or 0.
	 *
	 * @param string $expected Expected source title.
	 * @param array  $result   Answer result.
	 * @return int
	 */
	public static function retrieval_rank( $expected, array $result ) {
		$rank = 0;
		foreach ( $result['retrieved'] as $chunk ) {
			++$rank;
			if ( self::same_title( $chunk['title'], $expected ) ) {
				return $rank;
			}
		}
		return 0;
	}

	/**
	 * Run every question.
	 *
	 * @param array[]       $questions Questions from load_questions().
	 * @param callable|null $progress  Called after each question with (index, row).
	 * @return array Keys: rows, passed, total.
	 */
	public function run( array $questions, $progress = null ) {
		$rows   = array();
		$passed = 0;
		$top1   = 0;
		$scoped = 0;

		foreach ( $questions as $i => $item ) {
			$result = $this->service->ask( $item['question'] );
			if ( is_wp_error( $result ) ) {
				$row = array(
					'question' => $item['question'],
					'expected' => $item['expected'],
					'got'      => 'ERROR: ' . $result->get_error_message(),
					'shown'    => array(),
					'rank'     => 0,
					'score'    => 0,
					'answer'   => '',
					'pass'     => false,
				);
			} else {
				$row = array(
					'question' => $item['question'],
					'expected' => $item['expected'],
					'got'      => $result['fallback'] ? 'none (fallback)' : ( empty( $result['sources'] ) ? '-' : $result['sources'][0]['title'] ),
					'shown'    => $result['fallback'] ? array() : wp_list_pluck( $result['sources'], 'title' ),
					'rank'     => self::retrieval_rank( $item['expected'], $result ),
					'score'    => $result['top_score'],
					'answer'   => $result['answer'],
					'pass'     => self::grade( $item['expected'], $result ),
				);
			}

			if ( $row['pass'] ) {
				++$passed;
			}
			if ( 'none' !== strtolower( $item['expected'] ) ) {
				++$scoped;
				if ( 1 === $row['rank'] ) {
					++$top1;
				}
			}
			$rows[] = $row;

			if ( is_callable( $progress ) ) {
				call_user_func( $progress, $i, $row );
			}
		}

		return array(
			'rows'     => $rows,
			'passed'   => $passed,
			'total'    => count( $rows ),
			'top1'     => $top1,
			'in_scope' => $scoped,
		);
	}

	/**
	 * Render the results as a Markdown report.
	 *
	 * @param array $results Results from run().
	 * @param array $meta    Mode, provider, embedder, min_score, chunks, date.
	 * @return string
	 */
	public static function to_markdown( array $results, array $meta ) {
		$total = max( 1, (int) $results['total'] );
		$rate  = round( 100 * $results['passed'] / $total );

		$lines   = array();
		$lines[] = '# SiteSage Chat evaluation report';
		$lines[] = '';
		$lines[] = '> Demo project with fictional data (BrightSmile Dental). Generated by `wp sitesage eval`.';
		$lines[] = '';
		$lines[] = sprintf( '**Result: %d of %d passed (%d%%)**', $results['passed'], $results['total'], $rate );
		$lines[] = '';
		if ( ! empty( $results['in_scope'] ) ) {
			$lines[] = sprintf( 'Stricter view: the expected page was the top retrieved chunk for %d of %d in-scope questions.', $results['top1'], $results['in_scope'] );
			$lines[] = '';
		}
		$lines[] = '| Setting | Value |';
		$lines[] = '| --- | --- |';
		$lines[] = '| Date | ' . self::md( $meta['date'] ) . ' |';
		$lines[] = '| Mode | ' . self::md( $meta['mode'] ) . ' |';
		$lines[] = '| Answer provider | `' . self::md( $meta['provider'] ) . '` |';
		$lines[] = '| Embeddings | `' . self::md( $meta['embedder'] ) . '` |';
		$lines[] = '| Indexed chunks | ' . (int) $meta['chunks'] . ' |';
		$lines[] = '| Minimum similarity | ' . self::md( (string) $meta['min_score'] ) . ' |';
		$lines[] = '';
		$lines[] = 'A question passes when the expected page is one of the source links shown under the answer (up to 3: pages the answer cites first, then other close matches). For out-of-scope questions (expected source `none`) it passes when the bot replies "I don\'t know that one, let me connect you with our team". *Rank* is where the expected page appeared among the top 4 retrieved chunks.';
		$lines[] = '';
		$lines[] = '| # | Question | Expected source | Sources shown | Rank | Top score | Result |';
		$lines[] = '| ---: | --- | --- | --- | ---: | ---: | :---: |';

		foreach ( $results['rows'] as $i => $row ) {
			$lines[] = sprintf(
				'| %d | %s | %s | %s | %s | %s | %s |',
				$i + 1,
				self::md( $row['question'] ),
				self::md( $row['expected'] ),
				self::md( empty( $row['shown'] ) ? $row['got'] : implode( '; ', $row['shown'] ) ),
				$row['rank'] ? (string) $row['rank'] : '-',
				number_format( (float) $row['score'], 3 ),
				$row['pass'] ? '✅ Pass' : '❌ Fail'
			);
		}

		$failed = array_filter(
			$results['rows'],
			static function ( $row ) {
				return ! $row['pass'];
			}
		);

		$lines[] = '';
		$lines[] = '## Failed questions';
		$lines[] = '';
		if ( empty( $failed ) ) {
			$lines[] = 'None.';
		} else {
			foreach ( $failed as $row ) {
				$lines[] = '- **' . self::md( $row['question'] ) . '** expected `' . self::md( $row['expected'] ) . '`, first source shown `' . self::md( $row['got'] ) . '`.';
			}
		}
		$lines[] = '';

		return implode( "\n", $lines );
	}

	/**
	 * Escape a value for a Markdown table cell.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function md( $text ) {
		return str_replace( array( '|', "\n", "\r" ), array( '\\|', ' ', ' ' ), (string) $text );
	}
}
