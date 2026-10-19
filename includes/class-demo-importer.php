<?php
/**
 * Imports the fictional BrightSmile Dental demo content.
 *
 * @package SiteSage
 */

namespace SiteSage;

defined( 'ABSPATH' ) || exit;

/**
 * Creates 8 pages, 15 FAQs and the New Patient Guide PDF, sets the demo
 * chat settings and builds the index. Running it twice updates the demo
 * items instead of duplicating them. Content that was not created by the
 * importer is never changed.
 */
class Demo_Importer {

	const META_KEY = '_sitesage_demo';

	/**
	 * Path to the demo folder.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Constructor.
	 *
	 * @param string $dir Demo folder with content.json, the PDF and sample-leads.json.
	 */
	public function __construct( $dir = '' ) {
		$this->dir = trailingslashit( '' === $dir ? SITESAGE_PLUGIN_DIR . 'demo' : $dir );
	}

	/**
	 * Read content.json.
	 *
	 * @return array|\WP_Error
	 */
	public function content() {
		$file = $this->dir . 'content.json';
		$json = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read.
		if ( ! is_array( $json ) || empty( $json['pages'] ) || empty( $json['faqs'] ) ) {
			return new \WP_Error( 'sitesage_demo_content', __( 'The demo content file is missing or invalid.', 'sitesage-chat' ) );
		}
		return $json;
	}

	/**
	 * Run the import.
	 *
	 * @param array $args Options: with_leads (bool), remove_sample_content (bool), index (bool).
	 * @return array|\WP_Error Stats.
	 */
	public function import( array $args = array() ) {
		$args    = wp_parse_args(
			$args,
			array(
				'with_leads'            => false,
				'remove_sample_content' => false,
				'index'                 => true,
			)
		);
		$content = $this->content();
		if ( is_wp_error( $content ) ) {
			return $content;
		}

		$plugin = Plugin::instance();
		$plugin->pause_auto_index( true );

		if ( ! empty( $content['site']['name'] ) ) {
			update_option( 'blogname', sanitize_text_field( $content['site']['name'] ) );
		}
		if ( ! empty( $content['site']['tagline'] ) ) {
			update_option( 'blogdescription', sanitize_text_field( $content['site']['tagline'] ) );
		}

		if ( $args['remove_sample_content'] ) {
			$this->remove_sample_content();
		}

		$page_ids = array();
		foreach ( $content['pages'] as $order => $page ) {
			$parent = ( ! empty( $page['parent'] ) && isset( $page_ids[ $page['parent'] ] ) ) ? $page_ids[ $page['parent'] ] : 0;
			$id     = $this->upsert_post( 'page', $page['title'], self::blocks_to_markup( $page['blocks'] ), $page['slug'], $order, $parent );
			if ( $id ) {
				$page_ids[ $page['slug'] ] = $id;
			}
		}

		if ( isset( $page_ids['home'] ) ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $page_ids['home'] );
		}

		$faq_count = 0;
		foreach ( $content['faqs'] as $order => $faq ) {
			$markup = self::blocks_to_markup( array( array( 'paragraph' => $faq['answer'] ) ) );
			if ( $this->upsert_post( FAQ_Post_Type::POST_TYPE, $faq['question'], $markup, sanitize_title( $faq['question'] ), $order, 0 ) ) {
				++$faq_count;
			}
		}

		$pdf_id = $this->import_pdf( isset( $content['pdf'] ) ? $content['pdf'] : array() );

		if ( ! empty( $content['settings'] ) && is_array( $content['settings'] ) ) {
			$settings              = array_intersect_key( $content['settings'], Settings::defaults() );
			$settings['demo_mode'] = true;
			unset( $settings['openai_api_key'], $settings['anthropic_api_key'], $settings['sheets_webhook_secret'] );
			Settings::update( $settings );
		}

		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			update_option( 'permalink_structure', '/%postname%/' );
		}
		FAQ_Post_Type::register();
		flush_rewrite_rules();

		$leads = $args['with_leads'] ? $this->import_sample_leads() : 0;

		$plugin->pause_auto_index( false );

		$stats = array(
			'pages' => count( $page_ids ),
			'faqs'  => $faq_count,
			'pdf'   => $pdf_id ? 1 : 0,
			'leads' => $leads,
		);

		if ( $args['index'] ) {
			$indexer = $plugin->indexer();
			if ( is_wp_error( $indexer ) ) {
				return $indexer;
			}
			$index = $indexer->index_all();
			if ( is_wp_error( $index ) ) {
				return $index;
			}
			$stats['chunks'] = $index['chunks'];
		}

		return $stats;
	}

	/**
	 * Convert the simple block list in content.json to block markup.
	 *
	 * Supported items: {"heading": "..."} (h2), {"subheading": "..."} (h3),
	 * {"paragraph": "..."} and {"list": ["...", "..."]}.
	 *
	 * @param array $blocks Blocks.
	 * @return string
	 */
	public static function blocks_to_markup( array $blocks ) {
		$out = array();
		foreach ( $blocks as $block ) {
			if ( isset( $block['heading'] ) ) {
				$out[] = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . wp_kses_post( $block['heading'] ) . "</h2>\n<!-- /wp:heading -->";
			} elseif ( isset( $block['subheading'] ) ) {
				$out[] = "<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">" . wp_kses_post( $block['subheading'] ) . "</h3>\n<!-- /wp:heading -->";
			} elseif ( isset( $block['paragraph'] ) ) {
				$out[] = "<!-- wp:paragraph -->\n<p>" . wp_kses_post( $block['paragraph'] ) . "</p>\n<!-- /wp:paragraph -->";
			} elseif ( isset( $block['list'] ) && is_array( $block['list'] ) ) {
				$items = '';
				foreach ( $block['list'] as $item ) {
					$items .= "<!-- wp:list-item -->\n<li>" . wp_kses_post( $item ) . "</li>\n<!-- /wp:list-item -->\n";
				}
				$out[] = "<!-- wp:list -->\n<ul class=\"wp-block-list\">" . $items . "</ul>\n<!-- /wp:list -->";
			}
		}
		return implode( "\n\n", $out );
	}

	/**
	 * Create or update a demo post. Posts not created by the importer are left alone.
	 *
	 * @param string $type    Post type.
	 * @param string $title   Title.
	 * @param string $content Content.
	 * @param string $slug    Slug.
	 * @param int    $order   Menu order.
	 * @param int    $parent_id Parent ID.
	 * @return int Post ID, or 0 when skipped.
	 */
	private function upsert_post( $type, $title, $content, $slug, $order, $parent_id ) {
		$existing = get_posts(
			array(
				'post_type'      => $type,
				'name'           => $slug,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'post_parent'    => $parent_id,
			)
		);

		$data = array(
			'post_type'    => $type,
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => $content,
			'post_status'  => 'publish',
			'menu_order'   => (int) $order,
			'post_parent'  => (int) $parent_id,
		);

		if ( ! empty( $existing ) ) {
			if ( ! get_post_meta( $existing[0]->ID, self::META_KEY, true ) ) {
				return 0;
			}
			$data['ID'] = $existing[0]->ID;
			$id         = wp_update_post( wp_slash( $data ), true );
		} else {
			$id = wp_insert_post( wp_slash( $data ), true );
		}

		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}
		update_post_meta( $id, self::META_KEY, 1 );
		return (int) $id;
	}

	/**
	 * Add the New Patient Guide PDF to the media library.
	 *
	 * @param array $pdf PDF details from content.json: file, title.
	 * @return int Attachment ID, or 0.
	 */
	private function import_pdf( array $pdf ) {
		$file  = $this->dir . ( isset( $pdf['file'] ) ? basename( $pdf['file'] ) : 'new-patient-guide.pdf' );
		$title = isset( $pdf['title'] ) ? $pdf['title'] : 'New Patient Guide';

		$existing = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'meta_key'       => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-off demo import, one row.
				'meta_value'     => 'pdf', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One-off demo import, one row.
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		if ( ! empty( $existing ) ) {
			return (int) $existing[0];
		}
		if ( ! is_readable( $file ) ) {
			return 0;
		}

		$upload = wp_upload_bits( basename( $file ), null, (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read.
		if ( ! empty( $upload['error'] ) ) {
			return 0;
		}

		$id = wp_insert_attachment(
			array(
				'post_title'     => $title,
				'post_mime_type' => 'application/pdf',
				'post_status'    => 'inherit',
				'post_content'   => '',
			),
			$upload['file'],
			0,
			true
		);
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		update_post_meta( $id, self::META_KEY, 'pdf' );
		return (int) $id;
	}

	/**
	 * Add fictional sample leads so the leads screen is not empty.
	 *
	 * @return int Leads added.
	 */
	private function import_sample_leads() {
		$store = new Lead_Store();
		if ( $store->count() > 0 ) {
			return 0;
		}
		$file  = $this->dir . 'sample-leads.json';
		$leads = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read.
		$added = 0;
		$home  = home_url( '/' );

		foreach ( (array) $leads as $lead ) {
			$clean = Lead_Store::validate(
				array(
					'name'     => $lead['name'],
					'phone'    => $lead['phone'],
					'email'    => $lead['email'],
					'question' => $lead['question'],
					'page_url' => $home . ltrim( $lead['page'], '/' ),
				)
			);
			if ( is_wp_error( $clean ) ) {
				continue;
			}
			$clean['webhook_status'] = $lead['webhook_status'];
			$clean['created_at']     = gmdate( 'Y-m-d H:i:s', time() - (int) $lead['minutes_ago'] * MINUTE_IN_SECONDS );
			if ( ! is_wp_error( $store->insert( $clean ) ) ) {
				++$added;
			}
		}
		return $added;
	}

	/**
	 * Trash the default "Sample Page" and "Hello world!" post on a fresh site.
	 *
	 * @return void
	 */
	private function remove_sample_content() {
		foreach ( array(
			'page' => 'sample-page',
			'post' => 'hello-world',
		) as $type => $slug ) {
			$found = get_posts(
				array(
					'post_type'      => $type,
					'name'           => $slug,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
				)
			);
			if ( ! empty( $found ) ) {
				wp_trash_post( $found[0]->ID );
			}
		}
	}
}
