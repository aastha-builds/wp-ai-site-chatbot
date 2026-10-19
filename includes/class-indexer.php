<?php
/**
 * Turns pages, FAQs and PDFs into embedded chunks.
 *
 * @package SiteSage
 */

namespace SiteSage;

use SiteSage\Providers\Embedding_Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Builds documents from WordPress content, chunks them and stores the vectors.
 */
class Indexer {

	/**
	 * Embedding provider.
	 *
	 * @var Embedding_Provider
	 */
	private $embedder;

	/**
	 * Chunk storage.
	 *
	 * @var Chunk_Repository
	 */
	private $store;

	/**
	 * Chunker.
	 *
	 * @var Chunker
	 */
	private $chunker;

	/**
	 * Constructor.
	 *
	 * @param Embedding_Provider $embedder Embedding provider.
	 * @param Chunk_Repository   $store    Chunk storage.
	 * @param Chunker            $chunker  Chunker.
	 */
	public function __construct( Embedding_Provider $embedder, Chunk_Repository $store, Chunker $chunker ) {
		$this->embedder = $embedder;
		$this->store    = $store;
		$this->chunker  = $chunker;
	}

	/**
	 * Rebuild the whole index.
	 *
	 * Rows are built and embedded first; the old index is replaced only when
	 * that worked, so a failed API call never leaves the chatbot empty.
	 *
	 * @return array|\WP_Error Stats: sources, chunks, skipped.
	 */
	public function index_all() {
		$rows    = array();
		$sources = 0;
		$skipped = array();

		foreach ( $this->collect_documents() as $document ) {
			$document_rows = $this->rows_for_document( $document );
			if ( is_wp_error( $document_rows ) ) {
				return $document_rows;
			}
			if ( empty( $document_rows ) ) {
				$skipped[] = $document['title'];
				continue;
			}
			++$sources;
			$rows = array_merge( $rows, $document_rows );
		}

		$this->store->delete_all();
		$inserted = $this->store->insert_many( $rows );

		update_option(
			'sitesage_index_meta',
			array(
				'model'   => $this->embedder->model_id(),
				'sources' => $sources,
				'chunks'  => $inserted,
				'time'    => time(),
			),
			false
		);

		return array(
			'sources' => $sources,
			'chunks'  => $inserted,
			'skipped' => $skipped,
		);
	}

	/**
	 * Re-index one post, page, FAQ or PDF attachment.
	 *
	 * @param int $post_id Post ID.
	 * @return int|\WP_Error Chunks stored.
	 */
	public function index_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return 0;
		}

		$document = $this->document_for_post( $post );
		$type     = self::source_type_for( $post );
		if ( null === $document ) {
			if ( null !== $type ) {
				$this->store->delete_source( $type, $post->ID );
			}
			return 0;
		}

		$rows = $this->rows_for_document( $document );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$this->store->delete_source( $document['type'], $document['id'] );
		return $this->store->insert_many( $rows );
	}

	/**
	 * Remove a post from the index.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function remove_post( $post_id ) {
		$post = get_post( $post_id );
		$type = $post ? self::source_type_for( $post ) : null;
		if ( null !== $type ) {
			$this->store->delete_source( $type, (int) $post_id );
		}
	}

	/**
	 * Every document the settings say to index.
	 *
	 * @return array[]
	 */
	public function collect_documents() {
		$types = array();
		if ( Settings::get( 'index_pages' ) ) {
			$types[] = 'page';
		}
		if ( Settings::get( 'index_posts' ) ) {
			$types[] = 'post';
		}
		if ( Settings::get( 'index_faqs' ) ) {
			$types[] = FAQ_Post_Type::POST_TYPE;
		}

		$documents = array();
		if ( ! empty( $types ) ) {
			$posts = get_posts(
				array(
					'post_type'        => $types,
					'post_status'      => 'publish',
					'posts_per_page'   => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Indexing reads every published page, capped at 500.
					'has_password'     => false,
					'orderby'          => 'menu_order title',
					'order'            => 'ASC',
					'suppress_filters' => false,
				)
			);
			foreach ( $posts as $post ) {
				$document = $this->document_for_post( $post );
				if ( null !== $document ) {
					$documents[] = $document;
				}
			}
		}

		if ( Settings::get( 'index_pdfs' ) ) {
			$pdfs = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_mime_type' => 'application/pdf',
					'posts_per_page' => 100,
				)
			);
			foreach ( $pdfs as $pdf ) {
				$document = $this->document_for_post( $pdf );
				if ( null !== $document ) {
					$documents[] = $document;
				}
			}
		}

		/**
		 * Filters the documents that will be indexed.
		 *
		 * @param array[] $documents Each with type, id, url, title, text.
		 */
		return (array) apply_filters( 'sitesage_index_documents', $documents );
	}

	/**
	 * Source type for a post, or null when that kind of content is not indexed.
	 *
	 * @param \WP_Post $post Post.
	 * @return string|null
	 */
	public static function source_type_for( $post ) {
		if ( 'attachment' === $post->post_type ) {
			return 'application/pdf' === $post->post_mime_type ? 'pdf' : null;
		}
		if ( FAQ_Post_Type::POST_TYPE === $post->post_type ) {
			return 'faq';
		}
		if ( in_array( $post->post_type, array( 'page', 'post' ), true ) ) {
			return $post->post_type;
		}
		return null;
	}

	/**
	 * Build a document (type, id, url, title, text) from a post, or null when it should not be indexed.
	 *
	 * @param \WP_Post $post Post.
	 * @return array|null
	 */
	public function document_for_post( $post ) {
		$type = self::source_type_for( $post );
		if ( null === $type ) {
			return null;
		}

		$enabled = array(
			'page' => 'index_pages',
			'post' => 'index_posts',
			'faq'  => 'index_faqs',
			'pdf'  => 'index_pdfs',
		);
		if ( ! Settings::get( $enabled[ $type ] ) ) {
			return null;
		}

		if ( 'pdf' === $type ) {
			$text = PDF_Text_Extractor::from_file( (string) get_attached_file( $post->ID ) );
			$url  = wp_get_attachment_url( $post->ID );
		} else {
			if ( 'publish' !== $post->post_status || post_password_required( $post ) || '' !== $post->post_password ) {
				return null;
			}
			$text = Chunker::html_to_text( strip_shortcodes( do_blocks( $post->post_content ) ) );
			if ( 'faq' === $type ) {
				$text = sprintf( "Question: %s\nAnswer: %s", wp_strip_all_tags( $post->post_title ), $text );
			}
			$url = get_permalink( $post );
		}

		$text = trim( (string) $text );
		if ( '' === $text || ! $url ) {
			return null;
		}

		return array(
			'type'  => $type,
			'id'    => (int) $post->ID,
			'url'   => (string) $url,
			'title' => html_entity_decode( wp_strip_all_tags( $post->post_title ), ENT_QUOTES, 'UTF-8' ),
			'text'  => $text,
		);
	}

	/**
	 * Chunk and embed one document.
	 *
	 * @param array $document Document.
	 * @return array|\WP_Error Rows ready for the store.
	 */
	public function rows_for_document( array $document ) {
		$chunks = $this->chunker->split( $document['text'] );
		if ( empty( $chunks ) ) {
			return array();
		}

		$inputs = array();
		foreach ( $chunks as $chunk ) {
			$inputs[] = self::embedding_input( $document['title'], $chunk );
		}

		$vectors = $this->embedder->embed( $inputs );
		if ( is_wp_error( $vectors ) ) {
			return $vectors;
		}

		$rows = array();
		foreach ( $chunks as $i => $chunk ) {
			$rows[] = array(
				'source_type'     => $document['type'],
				'source_id'       => $document['id'],
				'source_url'      => $document['url'],
				'title'           => $document['title'],
				'chunk_index'     => $i,
				'content'         => $chunk,
				'token_count'     => Chunker::estimate_tokens( $chunk ),
				'embedding'       => $vectors[ $i ],
				'embedding_model' => $this->embedder->model_id(),
				'content_hash'    => sha1( $inputs[ $i ] ),
			);
		}
		return $rows;
	}

	/**
	 * Text that gets embedded for a chunk: the page title plus the chunk.
	 *
	 * @param string $title Source title.
	 * @param string $chunk Chunk text.
	 * @return string
	 */
	public static function embedding_input( $title, $chunk ) {
		return trim( $title ) . "\n\n" . trim( $chunk );
	}
}
