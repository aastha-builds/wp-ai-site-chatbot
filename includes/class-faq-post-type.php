<?php
/**
 * FAQ custom post type. Each FAQ is one question (title) and answer (content).
 *
 * @package SiteSage
 */

namespace SiteSage;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the sitesage_faq post type.
 */
class FAQ_Post_Type {

	const POST_TYPE = 'sitesage_faq';

	/**
	 * Register the post type.
	 *
	 * @return void
	 */
	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'FAQs', 'sitesage-chat' ),
					'singular_name' => __( 'FAQ', 'sitesage-chat' ),
					'add_new_item'  => __( 'Add New FAQ', 'sitesage-chat' ),
					'edit_item'     => __( 'Edit FAQ', 'sitesage-chat' ),
					'new_item'      => __( 'New FAQ', 'sitesage-chat' ),
					'view_item'     => __( 'View FAQ', 'sitesage-chat' ),
					'search_items'  => __( 'Search FAQs', 'sitesage-chat' ),
					'not_found'     => __( 'No FAQs found.', 'sitesage-chat' ),
					'all_items'     => __( 'FAQs', 'sitesage-chat' ),
					'menu_name'     => __( 'FAQs', 'sitesage-chat' ),
				),
				'public'       => true,
				'has_archive'  => 'faqs',
				'rewrite'      => array( 'slug' => 'faq' ),
				'show_in_menu' => 'sitesage-chat',
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'page-attributes' ),
			)
		);
	}
}
