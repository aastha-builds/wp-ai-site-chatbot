<?php
/**
 * Admin screens: overview and indexing, settings, leads and evaluation.
 *
 * @package SiteSage
 */

namespace SiteSage\Admin;

use SiteSage\Evaluator;
use SiteSage\Lead_Store;
use SiteSage\Leads_Export;
use SiteSage\Plugin;
use SiteSage\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Every screen and form handler checks manage_options and a nonce.
 */
class Admin {

	const CAPABILITY = 'manage_options';
	const SLUG       = 'sitesage-chat';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 9 );
		add_action( 'admin_menu', array( $this, 'submenus' ), 11 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'admin_post_sitesage_index', array( $this, 'handle_index' ) );
		add_action( 'admin_post_sitesage_run_eval', array( $this, 'handle_eval' ) );
		add_action( 'admin_post_sitesage_export_leads', array( $this, 'handle_export' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SITESAGE_PLUGIN_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Admin menu.
	 *
	 * @return void
	 */
	public function menu() {
		add_menu_page(
			__( 'SiteSage Chat', 'sitesage-chat' ),
			__( 'SiteSage Chat', 'sitesage-chat' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render_overview' ),
			'dashicons-format-chat',
			58
		);
		add_submenu_page( self::SLUG, __( 'Overview', 'sitesage-chat' ), __( 'Overview', 'sitesage-chat' ), self::CAPABILITY, self::SLUG, array( $this, 'render_overview' ) );
	}

	/**
	 * Submenus added after the FAQ post type menu, so Overview and FAQs come first.
	 *
	 * @return void
	 */
	public function submenus() {
		add_submenu_page( self::SLUG, __( 'Leads', 'sitesage-chat' ), __( 'Leads', 'sitesage-chat' ), self::CAPABILITY, self::SLUG . '-leads', array( $this, 'render_leads' ) );
		add_submenu_page( self::SLUG, __( 'Evaluation', 'sitesage-chat' ), __( 'Evaluation', 'sitesage-chat' ), self::CAPABILITY, self::SLUG . '-eval', array( $this, 'render_eval' ) );
		add_submenu_page( self::SLUG, __( 'Settings', 'sitesage-chat' ), __( 'Settings', 'sitesage-chat' ), self::CAPABILITY, self::SLUG . '-settings', array( $this, 'render_settings' ) );
	}

	/**
	 * Register the settings option with its sanitize callback.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'sitesage_settings_group',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * Load admin CSS on SiteSage screens only.
	 *
	 * @param string $hook Screen hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'sitesage-admin', SITESAGE_PLUGIN_URL . 'assets/css/admin.css', array(), SITESAGE_VERSION );
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_add_inline_script( 'wp-color-picker', 'jQuery(function($){$(".sitesage-color").wpColorPicker();});' );
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-settings' ) ) . '">' . esc_html__( 'Settings', 'sitesage-chat' ) . '</a>' );
		return $links;
	}

	/**
	 * Notices: results of the last action, indexing errors and an outdated index.
	 *
	 * @return void
	 */
	public function notices() {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, self::SLUG ) || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$notice = get_transient( self::notice_key() );
		if ( is_array( $notice ) ) {
			delete_transient( self::notice_key() );
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notice['type'] ), esc_html( $notice['message'] ) );
		}

		$error = get_transient( 'sitesage_index_error' );
		if ( $error ) {
			delete_transient( 'sitesage_index_error' );
			/* translators: %s: error message. */
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( sprintf( __( 'SiteSage Chat could not re-index the item you saved: %s', 'sitesage-chat' ), $error ) ) );
		}

		$embedder = Plugin::instance()->embedder();
		$stats    = Plugin::instance()->chunk_store()->stats();
		if ( ! is_wp_error( $embedder ) && $stats['chunks'] > 0 && ! in_array( $embedder->model_id(), $stats['models'], true ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'The index was built with a different embedding model than the one in use now. Click "Index site content" so the chatbot can find your content.', 'sitesage-chat' )
			);
		}
	}

	/**
	 * Overview screen with the "Index site content" button.
	 *
	 * @return void
	 */
	public function render_overview() {
		self::check_capability();

		$store    = Plugin::instance()->chunk_store();
		$stats    = $store->stats();
		$sources  = $store->sources();
		$embedder = Plugin::instance()->embedder();
		$demo     = Settings::is_demo_mode();
		$labels   = array(
			'page' => __( 'Page', 'sitesage-chat' ),
			'post' => __( 'Post', 'sitesage-chat' ),
			'faq'  => __( 'FAQ', 'sitesage-chat' ),
			'pdf'  => __( 'PDF', 'sitesage-chat' ),
		);
		?>
		<div class="wrap sitesage-admin">
			<h1><?php esc_html_e( 'SiteSage Chat', 'sitesage-chat' ); ?></h1>
			<p class="sitesage-lead"><?php esc_html_e( 'An AI assistant that answers visitor questions from your pages, FAQs and PDFs.', 'sitesage-chat' ); ?></p>

			<div class="sitesage-cards">
				<div class="sitesage-card">
					<span class="sitesage-card__label"><?php esc_html_e( 'Mode', 'sitesage-chat' ); ?></span>
					<span class="sitesage-badge <?php echo $demo ? 'is-demo' : 'is-live'; ?>"><?php echo $demo ? esc_html__( 'Demo mode', 'sitesage-chat' ) : esc_html__( 'Live', 'sitesage-chat' ); ?></span>
					<span class="sitesage-card__meta"><?php echo $demo ? esc_html__( 'Saved sample answers, no API key needed', 'sitesage-chat' ) : esc_html( Settings::provider() . ' · ' . Settings::model() ); ?></span>
				</div>
				<div class="sitesage-card">
					<span class="sitesage-card__label"><?php esc_html_e( 'Indexed sources', 'sitesage-chat' ); ?></span>
					<span class="sitesage-card__value"><?php echo esc_html( number_format_i18n( $stats['sources'] ) ); ?></span>
					<span class="sitesage-card__meta">
						<?php
						/* translators: %s: number of chunks. */
						echo esc_html( sprintf( __( '%s chunks of about 500 tokens', 'sitesage-chat' ), number_format_i18n( $stats['chunks'] ) ) );
						?>
					</span>
				</div>
				<div class="sitesage-card">
					<span class="sitesage-card__label"><?php esc_html_e( 'Embeddings', 'sitesage-chat' ); ?></span>
					<span class="sitesage-card__value sitesage-card__value--small"><?php echo esc_html( is_wp_error( $embedder ) ? __( 'Not configured', 'sitesage-chat' ) : $embedder->model_id() ); ?></span>
					<span class="sitesage-card__meta">
						<?php
						echo $stats['updated']
							/* translators: %s: date and time. */
							? esc_html( sprintf( __( 'Last indexed %s', 'sitesage-chat' ), get_date_from_gmt( $stats['updated'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) )
							: esc_html__( 'Not indexed yet', 'sitesage-chat' );
						?>
					</span>
				</div>
			</div>

			<div class="sitesage-panel">
				<h2><?php esc_html_e( 'Index site content', 'sitesage-chat' ); ?></h2>
				<p><?php esc_html_e( 'Splits your published pages, FAQs and uploaded PDFs into chunks, creates embeddings and stores them for the chatbot. Pages are re-indexed automatically when you save them.', 'sitesage-chat' ); ?></p>
				<?php if ( is_wp_error( $embedder ) ) : ?>
					<p class="sitesage-warning"><?php echo esc_html( $embedder->get_error_message() ); ?></p>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="sitesage_index" />
					<?php wp_nonce_field( 'sitesage_index' ); ?>
					<?php submit_button( __( 'Index site content', 'sitesage-chat' ), 'primary', 'submit', false, is_wp_error( $embedder ) ? array( 'disabled' => 'disabled' ) : array() ); ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'sitesage_open', '1', home_url( '/' ) ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open the chat on your site', 'sitesage-chat' ); ?></a>
				</form>
			</div>

			<h2><?php esc_html_e( 'What the chatbot knows', 'sitesage-chat' ); ?></h2>
			<table class="widefat striped sitesage-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Title', 'sitesage-chat' ); ?></th>
						<th><?php esc_html_e( 'Type', 'sitesage-chat' ); ?></th>
						<th class="num"><?php esc_html_e( 'Chunks', 'sitesage-chat' ); ?></th>
						<th class="num"><?php esc_html_e( 'Tokens (est.)', 'sitesage-chat' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $sources ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'Nothing indexed yet. Click "Index site content".', 'sitesage-chat' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $sources as $source ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( $source['source_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $source['title'] ); ?></a></td>
						<td><?php echo esc_html( isset( $labels[ $source['source_type'] ] ) ? $labels[ $source['source_type'] ] : $source['source_type'] ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $source['chunks'] ) ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $source['tokens'] ) ); ?></td>
					</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Settings screen.
	 *
	 * @return void
	 */
	public function render_settings() {
		self::check_capability();
		$s      = Settings::all();
		$forced = Settings::forced_demo_mode();
		$name   = Settings::OPTION;
		?>
		<div class="wrap sitesage-admin">
			<h1><?php esc_html_e( 'SiteSage Chat Settings', 'sitesage-chat' ); ?></h1>
			<form method="post" action="options.php" class="sitesage-settings">
				<?php settings_fields( 'sitesage_settings_group' ); ?>

				<div class="sitesage-panel">
					<h2><?php esc_html_e( 'AI provider', 'sitesage-chat' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Demo mode', 'sitesage-chat' ); ?></th>
							<td>
								<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[demo_mode]" value="1" <?php checked( Settings::is_demo_mode() ); ?> <?php disabled( null !== $forced ); ?> /> <?php esc_html_e( 'Use saved sample answers and offline embeddings (no API key, no cost)', 'sitesage-chat' ); ?></label>
								<?php if ( null !== $forced ) : ?>
									<input type="hidden" name="<?php echo esc_attr( $name ); ?>[demo_mode]" value="<?php echo $s['demo_mode'] ? '1' : ''; ?>" />
									<p class="description"><?php esc_html_e( 'Set by the SITESAGE_DEMO_MODE constant or the DEMO_MODE environment variable.', 'sitesage-chat' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sitesage-provider"><?php esc_html_e( 'Provider', 'sitesage-chat' ); ?></label></th>
							<td>
								<select id="sitesage-provider" name="<?php echo esc_attr( $name ); ?>[provider]">
									<option value="openai" <?php selected( $s['provider'], 'openai' ); ?>><?php esc_html_e( 'OpenAI', 'sitesage-chat' ); ?></option>
									<option value="anthropic" <?php selected( $s['provider'], 'anthropic' ); ?>><?php esc_html_e( 'Anthropic Claude', 'sitesage-chat' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sitesage-model"><?php esc_html_e( 'Model name', 'sitesage-chat' ); ?></label></th>
							<td>
								<input type="text" class="regular-text" id="sitesage-model" name="<?php echo esc_attr( $name ); ?>[model]" value="<?php echo esc_attr( $s['model'] ); ?>" placeholder="<?php echo esc_attr( 'anthropic' === $s['provider'] ? Settings::DEFAULT_ANTHROPIC_MODEL : Settings::DEFAULT_OPENAI_MODEL ); ?>" />
								<p class="description">
									<?php
									/* translators: 1: default OpenAI model, 2: default Claude model. */
									echo esc_html( sprintf( __( 'Leave empty for the default: %1$s (OpenAI) or %2$s (Claude).', 'sitesage-chat' ), Settings::DEFAULT_OPENAI_MODEL, Settings::DEFAULT_ANTHROPIC_MODEL ) );
									?>
								</p>
							</td>
						</tr>
						<?php
						self::secret_row( 'openai_api_key', __( 'OpenAI API key', 'sitesage-chat' ), __( 'Used for embeddings, and for answers when OpenAI is the provider.', 'sitesage-chat' ), $s, Settings::api_key( 'openai' ) );
						self::secret_row( 'anthropic_api_key', __( 'Anthropic API key', 'sitesage-chat' ), __( 'Used for answers when Anthropic Claude is the provider. Embeddings still use OpenAI.', 'sitesage-chat' ), $s, Settings::api_key( 'anthropic' ) );
						?>
						<tr>
							<th scope="row"><label for="sitesage-embedding-model"><?php esc_html_e( 'Embedding model', 'sitesage-chat' ); ?></label></th>
							<td><input type="text" class="regular-text" id="sitesage-embedding-model" name="<?php echo esc_attr( $name ); ?>[embedding_model]" value="<?php echo esc_attr( $s['embedding_model'] ); ?>" /></td>
						</tr>
					</table>
				</div>

				<div class="sitesage-panel">
					<h2><?php esc_html_e( 'Chat widget', 'sitesage-chat' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Show widget', 'sitesage-chat' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[show_widget]" value="1" <?php checked( $s['show_widget'] ); ?> /> <?php esc_html_e( 'Show the chat bubble on every front-end page', 'sitesage-chat' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><label for="sitesage-bot-name"><?php esc_html_e( 'Assistant name', 'sitesage-chat' ); ?></label></th>
							<td><input type="text" class="regular-text" id="sitesage-bot-name" name="<?php echo esc_attr( $name ); ?>[bot_name]" value="<?php echo esc_attr( $s['bot_name'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="sitesage-greeting"><?php esc_html_e( 'Greeting', 'sitesage-chat' ); ?></label></th>
							<td><textarea class="large-text" rows="2" id="sitesage-greeting" name="<?php echo esc_attr( $name ); ?>[greeting]"><?php echo esc_textarea( $s['greeting'] ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><label for="sitesage-suggestions"><?php esc_html_e( 'Suggested questions', 'sitesage-chat' ); ?></label></th>
							<td>
								<textarea class="large-text" rows="3" id="sitesage-suggestions" name="<?php echo esc_attr( $name ); ?>[suggestions]"><?php echo esc_textarea( $s['suggestions'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Optional quick-reply buttons, one per line (up to four).', 'sitesage-chat' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sitesage-brand-color"><?php esc_html_e( 'Brand color', 'sitesage-chat' ); ?></label></th>
							<td><input type="text" class="sitesage-color" id="sitesage-brand-color" name="<?php echo esc_attr( $name ); ?>[brand_color]" value="<?php echo esc_attr( $s['brand_color'] ); ?>" data-default-color="#0e7c86" /></td>
						</tr>
					</table>
				</div>

				<div class="sitesage-panel">
					<h2><?php esc_html_e( 'Lead capture', 'sitesage-chat' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="sitesage-lead-after"><?php esc_html_e( 'Ask for contact details after', 'sitesage-chat' ); ?></label></th>
							<td><input type="number" min="0" max="20" class="small-text" id="sitesage-lead-after" name="<?php echo esc_attr( $name ); ?>[lead_after_questions]" value="<?php echo esc_attr( $s['lead_after_questions'] ); ?>" /> <?php esc_html_e( 'questions (0 = only on intent words)', 'sitesage-chat' ); ?></td>
						</tr>
						<tr>
							<th scope="row"><label for="sitesage-intent"><?php esc_html_e( 'Intent words', 'sitesage-chat' ); ?></label></th>
							<td>
								<input type="text" class="large-text" id="sitesage-intent" name="<?php echo esc_attr( $name ); ?>[lead_intent_words]" value="<?php echo esc_attr( $s['lead_intent_words'] ); ?>" />
								<p class="description"><?php esc_html_e( 'Comma-separated. The form also appears as soon as a visitor uses one of these words.', 'sitesage-chat' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sitesage-sheets-url"><?php esc_html_e( 'Google Sheets webhook URL', 'sitesage-chat' ); ?></label></th>
							<td>
								<input type="url" class="large-text" id="sitesage-sheets-url" name="<?php echo esc_attr( $name ); ?>[sheets_webhook_url]" value="<?php echo esc_attr( $s['sheets_webhook_url'] ); ?>" placeholder="https://script.google.com/macros/s/…/exec" />
								<p class="description"><?php esc_html_e( 'Optional. Deploy integrations/google-sheets.gs as a web app and paste its URL here.', 'sitesage-chat' ); ?></p>
							</td>
						</tr>
						<?php self::secret_row( 'sheets_webhook_secret', __( 'Webhook secret', 'sitesage-chat' ), __( 'Must match SHARED_SECRET in the Apps Script.', 'sitesage-chat' ), $s, $s['sheets_webhook_secret'] ); ?>
					</table>
				</div>

				<div class="sitesage-panel">
					<h2><?php esc_html_e( 'Talk to a person', 'sitesage-chat' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Handoff channel', 'sitesage-chat' ); ?></th>
							<td>
								<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[handoff_type]" value="whatsapp" <?php checked( $s['handoff_type'], 'whatsapp' ); ?> /> <?php esc_html_e( 'WhatsApp', 'sitesage-chat' ); ?></label>
								&nbsp;&nbsp;
								<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[handoff_type]" value="email" <?php checked( $s['handoff_type'], 'email' ); ?> /> <?php esc_html_e( 'Email', 'sitesage-chat' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sitesage-whatsapp"><?php esc_html_e( 'WhatsApp number', 'sitesage-chat' ); ?></label></th>
							<td><input type="text" class="regular-text" id="sitesage-whatsapp" name="<?php echo esc_attr( $name ); ?>[handoff_whatsapp]" value="<?php echo esc_attr( $s['handoff_whatsapp'] ); ?>" placeholder="14155550142" /> <p class="description"><?php esc_html_e( 'International format, digits only.', 'sitesage-chat' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><label for="sitesage-handoff-email"><?php esc_html_e( 'Email address', 'sitesage-chat' ); ?></label></th>
							<td><input type="email" class="regular-text" id="sitesage-handoff-email" name="<?php echo esc_attr( $name ); ?>[handoff_email]" value="<?php echo esc_attr( $s['handoff_email'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="sitesage-handoff-label"><?php esc_html_e( 'Button label', 'sitesage-chat' ); ?></label></th>
							<td><input type="text" class="regular-text" id="sitesage-handoff-label" name="<?php echo esc_attr( $name ); ?>[handoff_label]" value="<?php echo esc_attr( $s['handoff_label'] ); ?>" /></td>
						</tr>
					</table>
				</div>

				<div class="sitesage-panel">
					<h2><?php esc_html_e( 'Guardrails', 'sitesage-chat' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="sitesage-rate"><?php esc_html_e( 'Rate limit per IP', 'sitesage-chat' ); ?></label></th>
							<td>
								<input type="number" min="1" max="1000" class="small-text" id="sitesage-rate" name="<?php echo esc_attr( $name ); ?>[rate_limit_requests]" value="<?php echo esc_attr( $s['rate_limit_requests'] ); ?>" />
								<?php esc_html_e( 'questions every', 'sitesage-chat' ); ?>
								<input type="number" min="1" max="1440" class="small-text" name="<?php echo esc_attr( $name ); ?>[rate_limit_minutes]" value="<?php echo esc_attr( $s['rate_limit_minutes'] ); ?>" aria-label="<?php esc_attr_e( 'Minutes', 'sitesage-chat' ); ?>" />
								<?php esc_html_e( 'minutes', 'sitesage-chat' ); ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sitesage-maxlen"><?php esc_html_e( 'Max message length', 'sitesage-chat' ); ?></label></th>
							<td><input type="number" min="50" max="4000" class="small-text" id="sitesage-maxlen" name="<?php echo esc_attr( $name ); ?>[max_message_length]" value="<?php echo esc_attr( $s['max_message_length'] ); ?>" /> <?php esc_html_e( 'characters', 'sitesage-chat' ); ?></td>
						</tr>
						<tr>
							<th scope="row"><label for="sitesage-min-score"><?php esc_html_e( 'Minimum relevance', 'sitesage-chat' ); ?></label></th>
							<td>
								<input type="text" class="small-text" id="sitesage-min-score" name="<?php echo esc_attr( $name ); ?>[min_score]" value="<?php echo esc_attr( $s['min_score'] ); ?>" placeholder="auto" />
								<p class="description"><?php esc_html_e( 'Cosine similarity from 0 to 1. Below it the bot hands over to your team without calling the AI. Leave empty for the default.', 'sitesage-chat' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'What is sent to the AI', 'sitesage-chat' ); ?></th>
							<td><p class="description"><?php esc_html_e( 'Only the visitor question and the retrieved passages from your site. No chat history, no contact details.', 'sitesage-chat' ); ?></p></td>
						</tr>
					</table>
				</div>

				<div class="sitesage-panel">
					<h2><?php esc_html_e( 'Content to index', 'sitesage-chat' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Include', 'sitesage-chat' ); ?></th>
							<td>
								<?php
								$types = array(
									'index_pages' => __( 'Pages', 'sitesage-chat' ),
									'index_faqs'  => __( 'FAQs', 'sitesage-chat' ),
									'index_pdfs'  => __( 'PDFs in the media library', 'sitesage-chat' ),
									'index_posts' => __( 'Blog posts', 'sitesage-chat' ),
								);
								foreach ( $types as $key => $label ) :
									?>
									<label class="sitesage-inline"><input type="checkbox" name="<?php echo esc_attr( $name . '[' . $key . ']' ); ?>" value="1" <?php checked( $s[ $key ] ); ?> /> <?php echo esc_html( $label ); ?></label>
								<?php endforeach; ?>
							</td>
						</tr>
					</table>
				</div>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Write-only secret field. The stored value is never printed, only a masked hint.
	 *
	 * @param string $key       Setting key.
	 * @param string $label     Label.
	 * @param string $help      Help text.
	 * @param array  $settings  Settings.
	 * @param string $effective Value in use (may come from a constant or environment variable).
	 * @return void
	 */
	private static function secret_row( $key, $label, $help, array $settings, $effective ) {
		$name   = Settings::OPTION;
		$masked = Settings::mask( $effective );
		?>
		<tr>
			<th scope="row"><label for="sitesage-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="password" class="regular-text" id="sitesage-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $name . '[' . $key . ']' ); ?>" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( '' === $masked ? __( 'Not set', 'sitesage-chat' ) : $masked ); ?>" />
				<?php if ( '' !== $settings[ $key ] ) : ?>
					<label class="sitesage-inline"><input type="checkbox" name="<?php echo esc_attr( $name . '[' . $key . '_clear]' ); ?>" value="1" /> <?php esc_html_e( 'Remove saved value', 'sitesage-chat' ); ?></label>
				<?php elseif ( '' !== $masked ) : ?>
					<span class="description"><?php esc_html_e( '(set in wp-config.php or the environment)', 'sitesage-chat' ); ?></span>
				<?php endif; ?>
				<p class="description"><?php echo esc_html( $help ); ?> <?php esc_html_e( 'Leave empty to keep the current value.', 'sitesage-chat' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Leads screen.
	 *
	 * @return void
	 */
	public function render_leads() {
		self::check_capability();
		$table = new Leads_List_Table( new Lead_Store() );
		$table->process_bulk_action();
		$table->prepare_items();

		$export = wp_nonce_url( admin_url( 'admin-post.php?action=sitesage_export_leads' ), 'sitesage_export_leads' );
		?>
		<div class="wrap sitesage-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Leads', 'sitesage-chat' ); ?></h1>
			<a href="<?php echo esc_url( $export ); ?>" class="page-title-action"><?php esc_html_e( 'Export CSV', 'sitesage-chat' ); ?></a>
			<hr class="wp-header-end" />
			<p><?php esc_html_e( 'Visitors who left their details in the chat. New leads are also posted to your Google Sheet when a webhook is set.', 'sitesage-chat' ); ?></p>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG . '-leads' ); ?>" />
				<?php
				$table->search_box( __( 'Search leads', 'sitesage-chat' ), 'sitesage-leads' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Evaluation screen.
	 *
	 * @return void
	 */
	public function render_eval() {
		self::check_capability();
		$last = get_option( 'sitesage_last_eval' );
		?>
		<div class="wrap sitesage-admin">
			<h1><?php esc_html_e( 'Evaluation', 'sitesage-chat' ); ?></h1>
			<p class="sitesage-lead"><?php esc_html_e( 'Runs the test questions in docs/test-questions.csv through the real answer pipeline and checks that each answer links the expected page.', 'sitesage-chat' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sitesage-inline-form">
				<input type="hidden" name="action" value="sitesage_run_eval" />
				<?php wp_nonce_field( 'sitesage_run_eval' ); ?>
				<?php submit_button( __( 'Run evaluation', 'sitesage-chat' ), 'primary', 'submit', false ); ?>
				<span class="description"><?php esc_html_e( 'Same as running: wp sitesage eval', 'sitesage-chat' ); ?></span>
			</form>

			<?php if ( ! is_array( $last ) || empty( $last['results'] ) ) : ?>
				<p><?php esc_html_e( 'No evaluation has run yet.', 'sitesage-chat' ); ?></p>
			<?php else : ?>
				<?php
				$results = $last['results'];
				$meta    = $last['meta'];
				$rate    = $results['total'] ? round( 100 * $results['passed'] / $results['total'] ) : 0;
				?>
				<div class="sitesage-cards">
					<div class="sitesage-card">
						<span class="sitesage-card__label"><?php esc_html_e( 'Passed', 'sitesage-chat' ); ?></span>
						<span class="sitesage-card__value"><?php echo esc_html( $results['passed'] . ' / ' . $results['total'] ); ?></span>
						<span class="sitesage-meter"><span style="width: <?php echo esc_attr( (string) $rate ); ?>%"></span></span>
					</div>
					<div class="sitesage-card">
						<span class="sitesage-card__label"><?php esc_html_e( 'Pass rate', 'sitesage-chat' ); ?></span>
						<span class="sitesage-card__value"><?php echo esc_html( $rate . '%' ); ?></span>
						<span class="sitesage-card__meta"><?php echo esc_html( $meta['date'] ); ?></span>
					</div>
					<div class="sitesage-card">
						<span class="sitesage-card__label"><?php esc_html_e( 'Pipeline', 'sitesage-chat' ); ?></span>
						<span class="sitesage-card__value sitesage-card__value--small"><?php echo esc_html( $meta['provider'] ); ?></span>
						<span class="sitesage-card__meta"><?php echo esc_html( $meta['embedder'] . ' · ' . $meta['chunks'] . ' chunks' ); ?></span>
					</div>
				</div>

				<table class="widefat striped sitesage-table sitesage-eval">
					<thead>
						<tr>
							<th class="num">#</th>
							<th><?php esc_html_e( 'Question', 'sitesage-chat' ); ?></th>
							<th><?php esc_html_e( 'Expected source', 'sitesage-chat' ); ?></th>
							<th><?php esc_html_e( 'Sources shown', 'sitesage-chat' ); ?></th>
							<th class="num"><?php esc_html_e( 'Rank', 'sitesage-chat' ); ?></th>
							<th><?php esc_html_e( 'Result', 'sitesage-chat' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $results['rows'] as $i => $row ) : ?>
						<tr>
							<td class="num"><?php echo esc_html( (string) ( $i + 1 ) ); ?></td>
							<td><?php echo esc_html( $row['question'] ); ?></td>
							<td><?php echo esc_html( $row['expected'] ); ?></td>
							<td><?php echo esc_html( empty( $row['shown'] ) ? $row['got'] : implode( ', ', $row['shown'] ) ); ?></td>
							<td class="num"><?php echo esc_html( $row['rank'] ? (string) $row['rank'] : '–' ); ?></td>
							<td><span class="sitesage-result <?php echo $row['pass'] ? 'is-pass' : 'is-fail'; ?>"><?php echo $row['pass'] ? esc_html__( 'Pass', 'sitesage-chat' ) : esc_html__( 'Fail', 'sitesage-chat' ); ?></span></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle the "Index site content" button.
	 *
	 * @return void
	 */
	public function handle_index() {
		self::check_capability();
		check_admin_referer( 'sitesage_index' );

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Indexing can take a while with a live API.
		}

		$indexer = Plugin::instance()->indexer();
		$result  = is_wp_error( $indexer ) ? $indexer : $indexer->index_all();

		if ( is_wp_error( $result ) ) {
			self::flash( 'error', $result->get_error_message() );
		} else {
			/* translators: 1: number of sources, 2: number of chunks. */
			self::flash( 'success', sprintf( __( 'Indexed %1$d sources into %2$d chunks.', 'sitesage-chat' ), $result['sources'], $result['chunks'] ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Handle the "Run evaluation" button.
	 *
	 * @return void
	 */
	public function handle_eval() {
		self::check_capability();
		check_admin_referer( 'sitesage_run_eval' );

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- 30 questions with a live API.
		}

		$run = self::run_evaluation( SITESAGE_PLUGIN_DIR . 'docs/test-questions.csv', SITESAGE_PLUGIN_DIR . 'docs/eval-report.md' );
		if ( is_wp_error( $run ) ) {
			self::flash( 'error', $run->get_error_message() );
		} else {
			/* translators: 1: passed questions, 2: total questions. */
			self::flash( 'success', sprintf( __( 'Evaluation finished: %1$d of %2$d passed.', 'sitesage-chat' ), $run['passed'], $run['total'] ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '-eval' ) );
		exit;
	}

	/**
	 * Stream the leads as a CSV download.
	 *
	 * @return void
	 */
	public function handle_export() {
		self::check_capability();
		check_admin_referer( 'sitesage_export_leads' );

		$csv = Leads_Export::to_csv( ( new Lead_Store() )->all() );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=sitesage-leads-' . gmdate( 'Y-m-d' ) . '.csv' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download, cells are neutralized in Leads_Export.
		exit;
	}

	/**
	 * Run the evaluation, save the results for the admin screen and write the report.
	 *
	 * @param string        $csv      Questions CSV.
	 * @param string        $report   Report path.
	 * @param callable|null $progress Progress callback.
	 * @return array|\WP_Error Keys: passed, total, report, written.
	 */
	public static function run_evaluation( $csv, $report, $progress = null ) {
		$service = Plugin::instance()->answer_service();
		if ( is_wp_error( $service ) ) {
			return $service;
		}
		$questions = Evaluator::load_questions( $csv );
		if ( is_wp_error( $questions ) ) {
			return $questions;
		}

		$embedder = Plugin::instance()->embedder();
		$stats    = Plugin::instance()->chunk_store()->stats();
		$results  = ( new Evaluator( $service ) )->run( $questions, $progress );
		$meta     = array(
			'date'      => gmdate( 'Y-m-d H:i' ) . ' UTC',
			'mode'      => Settings::is_demo_mode() ? 'Demo (saved sample answers, offline embeddings)' : 'Live',
			'provider'  => $service->provider_label(),
			'embedder'  => is_wp_error( $embedder ) ? '-' : $embedder->model_id(),
			'chunks'    => $stats['chunks'],
			'min_score' => $service->min_score(),
		);

		update_option(
			'sitesage_last_eval',
			array(
				'results' => $results,
				'meta'    => $meta,
			),
			false
		);

		$written = false;
		if ( wp_is_writable( dirname( $report ) ) ) {
			$written = false !== file_put_contents( $report, Evaluator::to_markdown( $results, $meta ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Report file in the plugin docs folder.
		}

		return array(
			'passed'  => $results['passed'],
			'total'   => $results['total'],
			'report'  => $report,
			'written' => $written,
		);
	}

	/**
	 * Stop unless the user can manage options.
	 *
	 * @return void
	 */
	private static function check_capability() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage SiteSage Chat.', 'sitesage-chat' ), 403 );
		}
	}

	/**
	 * Store a one-time notice for the current user.
	 *
	 * @param string $type    success, error or warning.
	 * @param string $message Message.
	 * @return void
	 */
	private static function flash( $type, $message ) {
		set_transient(
			self::notice_key(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Transient key for the current user's notice.
	 *
	 * @return string
	 */
	private static function notice_key() {
		return 'sitesage_notice_' . get_current_user_id();
	}
}
