<?php
/**
 * Embedding provider contract.
 *
 * @package SiteSage
 */

namespace SiteSage\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Turns texts into vectors.
 */
interface Embedding_Provider {

	/**
	 * Embed several texts.
	 *
	 * @param string[] $texts Texts to embed.
	 * @return array|\WP_Error List of float vectors in the same order, or an error.
	 */
	public function embed( array $texts );

	/**
	 * Identifier stored next to every vector, so vectors from different models are never compared.
	 *
	 * @return string
	 */
	public function model_id();
}
