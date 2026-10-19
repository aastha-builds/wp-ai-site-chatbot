<?php
/**
 * WP-CLI commands: wp sitesage index|ask|eval|status|demo.
 *
 * @package SiteSage
 */

namespace SiteSage;

defined( 'ABSPATH' ) || exit;

/**
 * Manage the SiteSage Chat index, ask questions and run the evaluation.
 */
class CLI {

	/**
	 * Index pages, FAQs and PDFs.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sitesage index
	 *
	 * @return void
	 */
	public function index() {
		$indexer = Plugin::instance()->indexer();
		if ( is_wp_error( $indexer ) ) {
			\WP_CLI::error( $indexer->get_error_message() );
		}
		$stats = $indexer->index_all();
		if ( is_wp_error( $stats ) ) {
			\WP_CLI::error( $stats->get_error_message() );
		}
		\WP_CLI::success( sprintf( 'Indexed %d sources into %d chunks.', $stats['sources'], $stats['chunks'] ) );
	}

	/**
	 * Ask the chatbot a question.
	 *
	 * ## OPTIONS
	 *
	 * <question>
	 * : The question.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sitesage ask "Do you take my insurance?"
	 *
	 * @param array $args Positional arguments.
	 * @return void
	 */
	public function ask( $args ) {
		$service = Plugin::instance()->answer_service();
		if ( is_wp_error( $service ) ) {
			\WP_CLI::error( $service->get_error_message() );
		}
		$result = $service->ask( $args[0] );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::line( $result['answer'] );
		\WP_CLI::line( '' );
		foreach ( $result['sources'] as $source ) {
			\WP_CLI::line( '- ' . $source['title'] . ' <' . $source['url'] . '>' );
		}
		\WP_CLI::line( sprintf( '(top score %.3f, %s)', $result['top_score'], $result['provider'] ) );
	}

	/**
	 * Run the test questions and write a pass/fail report.
	 *
	 * ## OPTIONS
	 *
	 * [--csv=<file>]
	 * : CSV with question and expected_source columns. Default: docs/test-questions.csv in the plugin.
	 *
	 * [--report=<file>]
	 * : Where to write the Markdown report. Default: docs/eval-report.md in the plugin.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sitesage eval
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function eval( $args, $assoc_args ) {
		$csv    = isset( $assoc_args['csv'] ) ? $assoc_args['csv'] : SITESAGE_PLUGIN_DIR . 'docs/test-questions.csv';
		$report = isset( $assoc_args['report'] ) ? $assoc_args['report'] : SITESAGE_PLUGIN_DIR . 'docs/eval-report.md';

		$run = Admin\Admin::run_evaluation( $csv, $report, array( __CLASS__, 'progress' ) );
		if ( is_wp_error( $run ) ) {
			\WP_CLI::error( $run->get_error_message() );
		}

		$message = sprintf( '%d of %d passed. Report written to %s', $run['passed'], $run['total'], $run['report'] );
		if ( empty( $run['written'] ) ) {
			\WP_CLI::warning( 'Could not write the report file; results were saved for the admin screen only.' );
		}
		if ( $run['passed'] === $run['total'] ) {
			\WP_CLI::success( $message );
		} else {
			\WP_CLI::warning( $message );
		}
	}

	/**
	 * Print one progress line per question.
	 *
	 * @param int   $index Question index.
	 * @param array $row   Result row.
	 * @return void
	 */
	public static function progress( $index, $row ) {
		\WP_CLI::line( sprintf( '%2d. %s %s -> %s', $index + 1, $row['pass'] ? 'PASS' : 'FAIL', $row['question'], empty( $row['shown'] ) ? $row['got'] : implode( ' | ', $row['shown'] ) ) );
	}

	/**
	 * Show mode, provider and index status.
	 *
	 * @return void
	 */
	public function status() {
		$stats    = Plugin::instance()->chunk_store()->stats();
		$embedder = Plugin::instance()->embedder();
		\WP_CLI::line( 'Mode:       ' . ( Settings::is_demo_mode() ? 'demo' : 'live' ) );
		\WP_CLI::line( 'Provider:   ' . ( Settings::is_demo_mode() ? 'demo:saved-answers' : Settings::provider() . ':' . Settings::model() ) );
		\WP_CLI::line( 'Embeddings: ' . ( is_wp_error( $embedder ) ? $embedder->get_error_message() : $embedder->model_id() ) );
		\WP_CLI::line( sprintf( 'Index:      %d chunks from %d sources (%s)', $stats['chunks'], $stats['sources'], implode( ', ', $stats['models'] ) ) );
	}
}
