<?php
/**
 * Question in, grounded answer out.
 *
 * @package SiteSage
 */

namespace SiteSage;

use SiteSage\Providers\Chat_Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Retrieves the top 4 chunks, skips the AI call when nothing is relevant,
 * and asks the provider to answer from the retrieved context only.
 */
class Answer_Service {

	const TOP_K = 4;

	/**
	 * Retriever.
	 *
	 * @var Retriever
	 */
	private $retriever;

	/**
	 * Chat provider.
	 *
	 * @var Chat_Provider
	 */
	private $provider;

	/**
	 * Prompt builder.
	 *
	 * @var Prompt_Builder
	 */
	private $prompts;

	/**
	 * Minimum similarity for a chunk to count as relevant.
	 *
	 * @var float
	 */
	private $min_score;

	/**
	 * Constructor.
	 *
	 * @param Retriever      $retriever Retriever.
	 * @param Chat_Provider  $provider  Chat provider.
	 * @param Prompt_Builder $prompts   Prompt builder.
	 * @param float          $min_score Minimum similarity.
	 */
	public function __construct( Retriever $retriever, Chat_Provider $provider, Prompt_Builder $prompts, $min_score ) {
		$this->retriever = $retriever;
		$this->provider  = $provider;
		$this->prompts   = $prompts;
		$this->min_score = (float) $min_score;
	}

	/**
	 * Answer a question.
	 *
	 * @param string $question Visitor question (already sanitized and length-checked).
	 * @return array|\WP_Error Keys: answer, sources, fallback, top_score, retrieved, provider.
	 */
	public function ask( $question ) {
		$question = trim( (string) $question );
		$chunks   = $this->retriever->search( $question, self::TOP_K );
		if ( is_wp_error( $chunks ) ) {
			return $chunks;
		}

		$top_score = empty( $chunks ) ? 0.0 : (float) $chunks[0]['score'];
		$retrieved = self::summarize_chunks( $chunks );

		if ( empty( $chunks ) || $top_score < $this->min_score ) {
			return $this->result( Prompt_Builder::fallback_message(), array(), true, $top_score, $retrieved );
		}

		$prompt = $this->prompts->build( $question, $chunks );
		$answer = $this->provider->generate( $prompt['system'], $prompt['user'] );

		if ( is_wp_error( $answer ) ) {
			if ( 'sitesage_refusal' === $answer->get_error_code() ) {
				return $this->result( Prompt_Builder::fallback_message(), array(), true, $top_score, $retrieved );
			}
			return $answer;
		}

		if ( Prompt_Builder::is_fallback( $answer ) ) {
			return $this->result( Prompt_Builder::fallback_message(), array(), true, $top_score, $retrieved );
		}

		$answer = Prompt_Builder::limit_words( $answer );
		return $this->result( $answer, $this->sources( $chunks, $answer ), false, $top_score, $retrieved );
	}

	/**
	 * Source links for the answer: pages the answer links to come first,
	 * then other strong matches. Sources scoring well below the best one are
	 * left out, and only retrieved pages can appear.
	 *
	 * @param array  $chunks Retrieved chunks.
	 * @param string $answer Answer text, used to find the pages it cites.
	 * @return array[] Each with title and url.
	 */
	public function sources( array $chunks, $answer = '' ) {
		if ( empty( $chunks ) ) {
			return array();
		}
		$cutoff  = max( $this->min_score, (float) $chunks[0]['score'] * 0.8 );
		$sources = array();

		preg_match_all( '/\]\(([^)\s]+)\)/', (string) $answer, $links );
		foreach ( $links[1] as $url ) {
			foreach ( $chunks as $chunk ) {
				if ( untrailingslashit( $chunk['source_url'] ) === untrailingslashit( $url ) && ! isset( $sources[ $chunk['source_url'] ] ) ) {
					$sources[ $chunk['source_url'] ] = array(
						'title' => (string) $chunk['title'],
						'url'   => (string) $chunk['source_url'],
					);
				}
			}
		}

		foreach ( $chunks as $chunk ) {
			if ( count( $sources ) >= 3 ) {
				break;
			}
			if ( (float) $chunk['score'] < $cutoff || isset( $sources[ $chunk['source_url'] ] ) ) {
				continue;
			}
			$sources[ $chunk['source_url'] ] = array(
				'title' => (string) $chunk['title'],
				'url'   => (string) $chunk['source_url'],
			);
		}
		return array_slice( array_values( $sources ), 0, 3 );
	}

	/**
	 * Provider label, for reports.
	 *
	 * @return string
	 */
	public function provider_label() {
		return $this->provider->label();
	}

	/**
	 * Minimum similarity in use.
	 *
	 * @return float
	 */
	public function min_score() {
		return $this->min_score;
	}

	/**
	 * Compact view of retrieved chunks for the evaluation report.
	 *
	 * @param array $chunks Chunks.
	 * @return array[]
	 */
	private static function summarize_chunks( array $chunks ) {
		$out = array();
		foreach ( $chunks as $chunk ) {
			$out[] = array(
				'title' => (string) $chunk['title'],
				'url'   => (string) $chunk['source_url'],
				'score' => round( (float) $chunk['score'], 4 ),
			);
		}
		return $out;
	}

	/**
	 * Result array.
	 *
	 * @param string $answer    Answer.
	 * @param array  $sources   Sources.
	 * @param bool   $fallback  Whether this is the fallback reply.
	 * @param float  $top_score Best similarity.
	 * @param array  $retrieved Retrieved chunk summary.
	 * @return array
	 */
	private function result( $answer, array $sources, $fallback, $top_score, array $retrieved ) {
		return array(
			'answer'    => $answer,
			'sources'   => $sources,
			'fallback'  => (bool) $fallback,
			'top_score' => round( (float) $top_score, 4 ),
			'retrieved' => $retrieved,
			'provider'  => $this->provider->label(),
		);
	}
}
