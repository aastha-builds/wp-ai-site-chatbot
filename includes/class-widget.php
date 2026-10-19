<?php
/**
 * Front-end chat widget: assets and configuration.
 *
 * @package SiteSage
 */

namespace SiteSage;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the vanilla JS and CSS bubble and passes its settings.
 */
class Widget {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue assets on the front end.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( ! Settings::get( 'show_widget' ) || is_admin() ) {
			return;
		}

		wp_enqueue_style( 'sitesage-widget', SITESAGE_PLUGIN_URL . 'assets/css/widget.css', array(), SITESAGE_VERSION );
		wp_enqueue_script( 'sitesage-widget', SITESAGE_PLUGIN_URL . 'assets/js/widget.js', array(), SITESAGE_VERSION, true );
		/**
		 * Filters the configuration passed to the chat widget script.
		 *
		 * @param array $config Widget configuration.
		 */
		$config = apply_filters( 'sitesage_widget_config', self::config() );
		wp_add_inline_script( 'sitesage-widget', 'window.SiteSageConfig = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Handoff link from the settings, or an empty string when none is set.
	 *
	 * @param string $type     whatsapp or email.
	 * @param string $whatsapp WhatsApp number (digits).
	 * @param string $email    Email address.
	 * @param string $site     Site name for the prefilled message.
	 * @return string
	 */
	public static function handoff_url( $type, $whatsapp, $email, $site ) {
		/* translators: %s: site name. */
		$message = sprintf( __( 'Hi %s, I have a question from your website chat.', 'sitesage-chat' ), $site );

		if ( 'whatsapp' === $type ) {
			$digits = preg_replace( '/\D+/', '', (string) $whatsapp );
			return '' === $digits ? '' : 'https://wa.me/' . $digits . '?text=' . rawurlencode( $message );
		}

		$email = sanitize_email( (string) $email );
		if ( '' === $email ) {
			return '';
		}
		/* translators: %s: site name. */
		$subject = sprintf( __( 'Question from the %s website', 'sitesage-chat' ), $site );
		return 'mailto:' . $email . '?subject=' . rawurlencode( $subject ) . '&body=' . rawurlencode( $message );
	}

	/**
	 * Configuration passed to widget.js.
	 *
	 * @return array
	 */
	public static function config() {
		$site = get_bloginfo( 'name' );

		return array(
			'transport'          => 'rest',
			'restUrl'            => esc_url_raw( rest_url( REST_Controller::NAMESPACE_V1 . '/' ) ),
			'nonce'              => wp_create_nonce( REST_Controller::NONCE_ACTION ),
			'wpNonce'            => wp_create_nonce( 'wp_rest' ),
			'botName'            => (string) Settings::get( 'bot_name' ),
			'greeting'           => (string) Settings::get( 'greeting' ),
			'suggestions'        => Settings::suggestions(),
			'brandColor'         => Settings::sanitize_color( Settings::get( 'brand_color' ) ),
			'maxLength'          => (int) Settings::get( 'max_message_length' ),
			'leadAfterQuestions' => (int) Settings::get( 'lead_after_questions' ),
			'intentWords'        => Lead_Intent::parse_words( Settings::get( 'lead_intent_words' ) ),
			'handoff'            => array(
				'type'  => (string) Settings::get( 'handoff_type' ),
				'url'   => self::handoff_url( Settings::get( 'handoff_type' ), Settings::get( 'handoff_whatsapp' ), Settings::get( 'handoff_email' ), $site ),
				'label' => (string) Settings::get( 'handoff_label' ),
			),
			'demoMode'           => Settings::is_demo_mode(),
			'i18n'               => array(
				'open'          => __( 'Open chat', 'sitesage-chat' ),
				'close'         => __( 'Close chat', 'sitesage-chat' ),
				'placeholder'   => __( 'Type your question…', 'sitesage-chat' ),
				'send'          => __( 'Send', 'sitesage-chat' ),
				'typing'        => __( 'Assistant is typing', 'sitesage-chat' ),
				'sources'       => __( 'Sources', 'sitesage-chat' ),
				'error'         => __( 'Sorry, something went wrong. Please try again.', 'sitesage-chat' ),
				'tooLong'       => __( 'That message is too long.', 'sitesage-chat' ),
				'leadTitle'     => __( 'Want us to follow up?', 'sitesage-chat' ),
				'leadIntro'     => __( 'Leave your details and our team will contact you.', 'sitesage-chat' ),
				'name'          => __( 'Name', 'sitesage-chat' ),
				'phone'         => __( 'Phone', 'sitesage-chat' ),
				'email'         => __( 'Email', 'sitesage-chat' ),
				'submit'        => __( 'Send details', 'sitesage-chat' ),
				'noThanks'      => __( 'No thanks', 'sitesage-chat' ),
				'leadThanks'    => __( 'Thanks! Our team will get back to you soon.', 'sitesage-chat' ),
				'leadPrivacy'   => __( 'Your details go only to our team, never to the AI.', 'sitesage-chat' ),
				'demoBadge'     => __( 'Demo mode: sample answers', 'sitesage-chat' ),
				'poweredBy'     => __( 'Answers come from this website’s content.', 'sitesage-chat' ),
				'requiredField' => __( 'Please fill in all fields.', 'sitesage-chat' ),
			),
		);
	}
}
