<?php
/**
 * Main plugin class: wires hooks and builds services.
 *
 * @package SiteSage
 */

namespace SiteSage;

use SiteSage\Admin\Admin;
use SiteSage\Providers\Anthropic_Provider;
use SiteSage\Providers\Demo_Chat_Provider;
use SiteSage\Providers\Demo_Embedding_Provider;
use SiteSage\Providers\OpenAI_Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton entry point.
 */
class Plugin {

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether save hooks should skip re-indexing (during bulk imports).
	 *
	 * @var bool
	 */
	private $auto_index_paused = false;

	/**
	 * Get the instance, booting it on first call.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	private function boot() {
		Installer::maybe_upgrade();

		add_action( 'init', array( $this, 'init' ) );
		add_action( 'rest_api_init', array( new REST_Controller(), 'register_routes' ) );
		add_action( 'wp_after_insert_post', array( $this, 'on_post_saved' ), 20, 2 );
		add_action( 'add_attachment', array( $this, 'on_attachment_saved' ) );
		add_action( 'edit_attachment', array( $this, 'on_attachment_saved' ) );
		add_action( 'before_delete_post', array( $this, 'on_post_deleted' ) );
		add_action( 'delete_attachment', array( $this, 'on_post_deleted' ) );
		add_action( 'wp_trash_post', array( $this, 'on_post_deleted' ) );

		( new Widget() )->register();

		if ( is_admin() ) {
			( new Admin() )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'sitesage', CLI::class );
			\WP_CLI::add_command( 'sitesage demo', CLI_Demo::class );
		}
	}

	/**
	 * Init hook.
	 *
	 * @return void
	 */
	public function init() {
		load_plugin_textdomain( 'sitesage-chat', false, dirname( plugin_basename( SITESAGE_PLUGIN_FILE ) ) . '/languages' );
		FAQ_Post_Type::register();
	}

	/**
	 * Pause or resume re-indexing on save.
	 *
	 * @param bool $paused Whether to pause.
	 * @return void
	 */
	public function pause_auto_index( $paused ) {
		$this->auto_index_paused = (bool) $paused;
	}

	/**
	 * Re-index a page, post or FAQ after it is saved.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 * @return void
	 */
	public function on_post_saved( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'attachment' === $post->post_type ) {
			return;
		}
		if ( null === Indexer::source_type_for( $post ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		$this->reindex( $post_id );
	}

	/**
	 * Index a PDF when it is uploaded or edited.
	 *
	 * @param int $post_id Attachment ID.
	 * @return void
	 */
	public function on_attachment_saved( $post_id ) {
		if ( 'application/pdf' === get_post_mime_type( $post_id ) ) {
			$this->reindex( $post_id );
		}
	}

	/**
	 * Remove deleted or trashed content from the index.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_post_deleted( $post_id ) {
		$indexer = $this->indexer();
		if ( ! is_wp_error( $indexer ) ) {
			$indexer->remove_post( $post_id );
		} else {
			$post = get_post( $post_id );
			$type = $post ? Indexer::source_type_for( $post ) : null;
			if ( null !== $type ) {
				$this->chunk_store()->delete_source( $type, $post_id );
			}
		}
	}

	/**
	 * Re-index one item and remember any error for the admin notice.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function reindex( $post_id ) {
		if ( $this->auto_index_paused ) {
			return;
		}
		$indexer = $this->indexer();
		$result  = is_wp_error( $indexer ) ? $indexer : $indexer->index_post( $post_id );
		if ( is_wp_error( $result ) ) {
			set_transient( 'sitesage_index_error', $result->get_error_message(), HOUR_IN_SECONDS );
		}
	}

	/**
	 * Chunk storage.
	 *
	 * @return Chunk_Store
	 */
	public function chunk_store() {
		return new Chunk_Store();
	}

	/**
	 * Embedding provider for the current mode.
	 *
	 * @return \SiteSage\Providers\Embedding_Provider|\WP_Error
	 */
	public function embedder() {
		if ( Settings::is_demo_mode() ) {
			return new Demo_Embedding_Provider( SITESAGE_PLUGIN_DIR . 'demo/demo-embeddings.json' );
		}
		$key = Settings::api_key( 'openai' );
		if ( '' === $key ) {
			return new \WP_Error( 'sitesage_no_embedding_key', __( 'Add an OpenAI API key for embeddings, or turn on demo mode.', 'sitesage-chat' ) );
		}
		return new OpenAI_Provider( $key, Settings::model(), (string) Settings::get( 'embedding_model' ) );
	}

	/**
	 * Chat provider for the current mode and settings.
	 *
	 * @return \SiteSage\Providers\Chat_Provider|\WP_Error
	 */
	public function chat_provider() {
		if ( Settings::is_demo_mode() ) {
			return Demo_Chat_Provider::from_file( SITESAGE_PLUGIN_DIR . 'demo/sample-answers.json' );
		}
		$provider = Settings::provider();
		$key      = Settings::api_key( $provider );
		if ( '' === $key ) {
			return new \WP_Error( 'sitesage_no_chat_key', __( 'Add an API key for the selected AI provider, or turn on demo mode.', 'sitesage-chat' ) );
		}
		if ( 'anthropic' === $provider ) {
			return new Anthropic_Provider( $key, Settings::model() );
		}
		return new OpenAI_Provider( $key, Settings::model(), (string) Settings::get( 'embedding_model' ) );
	}

	/**
	 * Indexer.
	 *
	 * @return Indexer|\WP_Error
	 */
	public function indexer() {
		$embedder = $this->embedder();
		if ( is_wp_error( $embedder ) ) {
			return $embedder;
		}
		return new Indexer( $embedder, $this->chunk_store(), new Chunker( 500, 50 ) );
	}

	/**
	 * Answer service.
	 *
	 * @return Answer_Service|\WP_Error
	 */
	public function answer_service() {
		$embedder = $this->embedder();
		if ( is_wp_error( $embedder ) ) {
			return $embedder;
		}
		$provider = $this->chat_provider();
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}
		return new Answer_Service(
			new Retriever( $embedder, $this->chunk_store() ),
			$provider,
			new Prompt_Builder( get_bloginfo( 'name' ) ),
			self::min_score( $embedder->model_id() )
		);
	}

	/**
	 * Minimum similarity: the setting, or a default tuned per embedding model.
	 *
	 * @param string $model_id Embedding model ID.
	 * @return float
	 */
	public static function min_score( $model_id ) {
		$setting = Settings::get( 'min_score' );
		if ( '' !== $setting && null !== $setting ) {
			return (float) $setting;
		}
		$default = Demo_Embedding_Provider::MODEL_ID === $model_id ? 0.08 : 0.2;
		/**
		 * Filters the default minimum similarity.
		 *
		 * @param float  $default  Default.
		 * @param string $model_id Embedding model ID.
		 */
		return (float) apply_filters( 'sitesage_min_score', $default, $model_id );
	}
}
