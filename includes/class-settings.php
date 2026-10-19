<?php
/**
 * Plugin settings: defaults, reading, sanitizing and secret masking.
 *
 * @package SiteSage
 */

namespace SiteSage;

defined( 'ABSPATH' ) || exit;

/**
 * Stores every SiteSage option in one array option.
 */
class Settings {

	const OPTION = 'sitesage_settings';

	const DEFAULT_OPENAI_MODEL    = 'gpt-4.1-mini';
	const DEFAULT_ANTHROPIC_MODEL = 'claude-opus-5-5';
	const DEFAULT_EMBEDDING_MODEL = 'text-embedding-3-small';

	/**
	 * Cached settings for the current request.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default values for every setting.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'demo_mode'             => true,
			'provider'              => 'openai',
			'model'                 => '',
			'openai_api_key'        => '',
			'anthropic_api_key'     => '',
			'embedding_model'       => self::DEFAULT_EMBEDDING_MODEL,
			'show_widget'           => true,
			'bot_name'              => __( 'Site Assistant', 'sitesage-chat' ),
			'greeting'              => __( 'Hi! Ask me anything about this website.', 'sitesage-chat' ),
			'suggestions'           => '',
			'brand_color'           => '#0e7c86',
			'handoff_type'          => 'email',
			'handoff_whatsapp'      => '',
			'handoff_email'         => '',
			'handoff_label'         => __( 'Talk to a person', 'sitesage-chat' ),
			'lead_after_questions'  => 2,
			'lead_intent_words'     => 'book, appointment, price, pricing',
			'sheets_webhook_url'    => '',
			'sheets_webhook_secret' => '',
			'rate_limit_requests'   => 15,
			'rate_limit_minutes'    => 10,
			'max_message_length'    => 500,
			'index_pages'           => true,
			'index_posts'           => false,
			'index_faqs'            => true,
			'index_pdfs'            => true,
			'min_score'             => '',
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $fallback Value when the key is unknown.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Save a partial settings array (merged into what is stored).
	 *
	 * @param array $values Values to store. They must already be sanitized.
	 * @return void
	 */
	public static function update( array $values ) {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		update_option( self::OPTION, array_merge( $saved, $values ) );
		self::flush();
	}

	/**
	 * Clear the request cache (after the option changes).
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Whether demo mode is on.
	 *
	 * The SITESAGE_DEMO_MODE constant wins, then the DEMO_MODE environment
	 * variable, then the checkbox on the settings screen.
	 *
	 * @return bool
	 */
	public static function is_demo_mode() {
		$forced = self::forced_demo_mode();
		if ( null !== $forced ) {
			return $forced;
		}
		return (bool) self::get( 'demo_mode' );
	}

	/**
	 * Demo mode value forced by a constant or environment variable, if any.
	 *
	 * @return bool|null Null when nothing forces it.
	 */
	public static function forced_demo_mode() {
		if ( defined( 'SITESAGE_DEMO_MODE' ) ) {
			return (bool) constant( 'SITESAGE_DEMO_MODE' );
		}
		$env = getenv( 'DEMO_MODE' );
		if ( false !== $env && '' !== $env ) {
			return in_array( strtolower( (string) $env ), array( '1', 'true', 'yes', 'on' ), true );
		}
		return null;
	}

	/**
	 * Chat provider slug: openai or anthropic.
	 *
	 * @return string
	 */
	public static function provider() {
		$provider = (string) self::get( 'provider' );
		if ( '' === $provider ) {
			$provider = (string) getenv( 'SITESAGE_PROVIDER' );
		}
		return 'anthropic' === $provider ? 'anthropic' : 'openai';
	}

	/**
	 * Chat model name, falling back to the provider default.
	 *
	 * @return string
	 */
	public static function model() {
		$model = trim( (string) self::get( 'model' ) );
		if ( '' === $model ) {
			$model = trim( (string) getenv( 'SITESAGE_MODEL' ) );
		}
		if ( '' === $model ) {
			$model = 'anthropic' === self::provider() ? self::DEFAULT_ANTHROPIC_MODEL : self::DEFAULT_OPENAI_MODEL;
		}
		return $model;
	}

	/**
	 * API key for a provider. Saved option first, then constant, then environment.
	 *
	 * @param string $provider openai or anthropic.
	 * @return string
	 */
	public static function api_key( $provider ) {
		$provider = 'anthropic' === $provider ? 'anthropic' : 'openai';
		$key      = trim( (string) self::get( $provider . '_api_key' ) );
		$name     = strtoupper( $provider ) . '_API_KEY';

		if ( '' === $key && defined( 'SITESAGE_' . $name ) ) {
			$key = trim( (string) constant( 'SITESAGE_' . $name ) );
		}
		if ( '' === $key ) {
			$key = trim( (string) getenv( $name ) );
		}
		return $key;
	}

	/**
	 * Mask a secret so only the last four characters show.
	 *
	 * @param string $secret Secret value.
	 * @return string Masked value, or an empty string when there is no secret.
	 */
	public static function mask( $secret ) {
		$secret = (string) $secret;
		if ( '' === $secret ) {
			return '';
		}
		$visible = strlen( $secret ) > 8 ? substr( $secret, -4 ) : '';
		return str_repeat( '•', 8 ) . $visible;
	}

	/**
	 * Suggested questions, one per line, at most four.
	 *
	 * @return string[]
	 */
	public static function suggestions() {
		$lines = preg_split( '/\r\n|\r|\n/', (string) self::get( 'suggestions' ) );
		$lines = array_filter( array_map( 'trim', $lines ) );
		return array_slice( array_values( $lines ), 0, 4 );
	}

	/**
	 * Sanitize a hex color, falling back to the default.
	 *
	 * @param string $color Raw color.
	 * @return string
	 */
	public static function sanitize_color( $color ) {
		$color = sanitize_hex_color( (string) $color );
		return $color ? $color : self::defaults()['brand_color'];
	}

	/**
	 * Keep only digits from a phone number (wa.me needs the bare number).
	 *
	 * @param string $number Raw number.
	 * @return string
	 */
	public static function sanitize_phone_digits( $number ) {
		return preg_replace( '/\D+/', '', (string) $number );
	}

	/**
	 * Sanitize the full settings array submitted from the settings screen.
	 *
	 * API key fields are write-only: an empty field keeps the stored key,
	 * and the matching "clear" checkbox removes it.
	 *
	 * @param array $input Raw input.
	 * @return array Sanitized settings.
	 */
	public static function sanitize( $input ) {
		// The Settings API has already unslashed $_POST at this point.
		$input    = is_array( $input ) ? $input : array();
		$current  = self::all();
		$defaults = self::defaults();
		$clean    = array();

		$clean['demo_mode']   = ! empty( $input['demo_mode'] );
		$clean['provider']    = ( isset( $input['provider'] ) && 'anthropic' === $input['provider'] ) ? 'anthropic' : 'openai';
		$clean['model']       = isset( $input['model'] ) ? sanitize_text_field( $input['model'] ) : '';
		$clean['show_widget'] = ! empty( $input['show_widget'] );

		foreach ( array( 'openai_api_key', 'anthropic_api_key', 'sheets_webhook_secret' ) as $secret_key ) {
			$clean[ $secret_key ] = $current[ $secret_key ];
			if ( ! empty( $input[ $secret_key . '_clear' ] ) ) {
				$clean[ $secret_key ] = '';
			} elseif ( isset( $input[ $secret_key ] ) && '' !== trim( $input[ $secret_key ] ) ) {
				$clean[ $secret_key ] = sanitize_text_field( trim( $input[ $secret_key ] ) );
			}
		}

		$embedding_model          = isset( $input['embedding_model'] ) ? sanitize_text_field( $input['embedding_model'] ) : '';
		$clean['embedding_model'] = '' === $embedding_model ? $defaults['embedding_model'] : $embedding_model;

		$clean['bot_name']      = isset( $input['bot_name'] ) && '' !== trim( $input['bot_name'] ) ? sanitize_text_field( $input['bot_name'] ) : $defaults['bot_name'];
		$clean['greeting']      = isset( $input['greeting'] ) ? sanitize_textarea_field( $input['greeting'] ) : $defaults['greeting'];
		$clean['suggestions']   = isset( $input['suggestions'] ) ? sanitize_textarea_field( $input['suggestions'] ) : '';
		$clean['brand_color']   = self::sanitize_color( isset( $input['brand_color'] ) ? $input['brand_color'] : '' );
		$clean['handoff_label'] = isset( $input['handoff_label'] ) && '' !== trim( $input['handoff_label'] ) ? sanitize_text_field( $input['handoff_label'] ) : $defaults['handoff_label'];

		$clean['handoff_type']     = ( isset( $input['handoff_type'] ) && 'whatsapp' === $input['handoff_type'] ) ? 'whatsapp' : 'email';
		$clean['handoff_whatsapp'] = self::sanitize_phone_digits( isset( $input['handoff_whatsapp'] ) ? $input['handoff_whatsapp'] : '' );
		$clean['handoff_email']    = isset( $input['handoff_email'] ) ? sanitize_email( $input['handoff_email'] ) : '';

		$clean['lead_after_questions'] = self::bounded_int( $input, 'lead_after_questions', 0, 20 );
		$clean['lead_intent_words']    = isset( $input['lead_intent_words'] ) ? sanitize_text_field( $input['lead_intent_words'] ) : $defaults['lead_intent_words'];
		$clean['sheets_webhook_url']   = isset( $input['sheets_webhook_url'] ) ? esc_url_raw( trim( $input['sheets_webhook_url'] ), array( 'https' ) ) : '';

		$clean['rate_limit_requests'] = self::bounded_int( $input, 'rate_limit_requests', 1, 1000 );
		$clean['rate_limit_minutes']  = self::bounded_int( $input, 'rate_limit_minutes', 1, 1440 );
		$clean['max_message_length']  = self::bounded_int( $input, 'max_message_length', 50, 4000 );

		foreach ( array( 'index_pages', 'index_posts', 'index_faqs', 'index_pdfs' ) as $flag ) {
			$clean[ $flag ] = ! empty( $input[ $flag ] );
		}

		$min_score          = isset( $input['min_score'] ) ? trim( (string) $input['min_score'] ) : '';
		$clean['min_score'] = ( '' === $min_score || ! is_numeric( $min_score ) ) ? '' : (string) max( 0, min( 1, (float) $min_score ) );

		self::flush();
		return $clean;
	}

	/**
	 * Read an integer field and clamp it to a range.
	 *
	 * @param array  $input Raw input.
	 * @param string $key   Field key.
	 * @param int    $min   Minimum.
	 * @param int    $max   Maximum.
	 * @return int
	 */
	private static function bounded_int( array $input, $key, $min, $max ) {
		$value = isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ? (int) $input[ $key ] : (int) self::defaults()[ $key ];
		return max( $min, min( $max, $value ) );
	}
}
