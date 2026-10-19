<?php
/**
 * PHPUnit bootstrap: loads the plugin classes with small stand-ins for the
 * WordPress functions they call, so the core logic runs without WordPress
 * or a database. The full plugin is tested inside WordPress by
 * bin/integration-test.sh.
 *
 * @package SiteSage
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'SITESAGE_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );
define( 'SITESAGE_PLUGIN_URL', 'https://example.test/wp-content/plugins/sitesage-chat/' );
define( 'SITESAGE_PLUGIN_FILE', SITESAGE_PLUGIN_DIR . 'sitesage-chat.php' );
define( 'SITESAGE_VERSION', 'test' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

/**
 * In-memory state shared by the stubs. Tests reset it in setUp().
 */
class SiteSage_Test_State {
	public static $options    = array();
	public static $transients = array();
	public static $filters    = array();
	public static $http       = array();
	public static $requests   = array();

	public static function reset() {
		self::$options    = array();
		self::$transients = array();
		self::$filters    = array();
		self::$http       = array();
		self::$requests   = array();
		\SiteSage\Settings::flush();
	}
}

class WP_Error {
	private $code;
	private $message;
	private $data = array();

	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
	public function get_error_data() {
		return $this->data;
	}
	public function add_data( $data ) {
		$this->data = $data;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function __( $text, $domain = 'default' ) {
	return $text;
}
function esc_html__( $text, $domain = 'default' ) {
	return $text;
}
function apply_filters( $hook, $value, ...$args ) {
	if ( isset( SiteSage_Test_State::$filters[ $hook ] ) ) {
		return call_user_func( SiteSage_Test_State::$filters[ $hook ], $value, ...$args );
	}
	return $value;
}
function do_action( $hook, ...$args ) {}
function wp_strip_all_tags( $text, $remove_breaks = false ) {
	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
	$text = strip_tags( $text );
	if ( $remove_breaks ) {
		$text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
	}
	return trim( $text );
}
function sanitize_text_field( $text ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', wp_strip_all_tags( (string) $text ) ) );
}
function sanitize_textarea_field( $text ) {
	return trim( wp_strip_all_tags( (string) $text ) );
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function sanitize_email( $email ) {
	$email = trim( (string) $email );
	return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : '';
}
function is_email( $email ) {
	return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
}
function sanitize_hex_color( $color ) {
	return preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', (string) $color ) ? $color : null;
}
function esc_url_raw( $url, $protocols = null ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return '';
	}
	$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
	$allow  = $protocols ? $protocols : array( 'http', 'https', 'mailto', 'tel' );
	return ( '' === $scheme || in_array( $scheme, $allow, true ) ) ? $url : '';
}
function wp_kses_post( $html ) {
	return (string) $html;
}
function wp_unslash( $value ) {
	return $value;
}
function wp_json_encode( $data, $options = 0 ) {
	return json_encode( $data, $options );
}
function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, (array) $args );
}
function wp_list_pluck( $list, $field ) {
	return array_map(
		static function ( $item ) use ( $field ) {
			return is_array( $item ) ? $item[ $field ] : $item->$field;
		},
		$list
	);
}
function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' );
}
function trailingslashit( $value ) {
	return untrailingslashit( $value ) . '/';
}
function wp_salt( $scheme = 'auth' ) {
	return 'test-salt-' . $scheme;
}
function home_url( $path = '' ) {
	return 'https://brightsmile.test' . $path;
}
function get_option( $name, $default = false ) {
	return array_key_exists( $name, SiteSage_Test_State::$options ) ? SiteSage_Test_State::$options[ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	SiteSage_Test_State::$options[ $name ] = $value;
	return true;
}
function get_transient( $name ) {
	return isset( SiteSage_Test_State::$transients[ $name ] ) ? SiteSage_Test_State::$transients[ $name ] : false;
}
function set_transient( $name, $value, $expiration = 0 ) {
	SiteSage_Test_State::$transients[ $name ] = $value;
	return true;
}
function delete_transient( $name ) {
	unset( SiteSage_Test_State::$transients[ $name ] );
	return true;
}
function wp_remote_post( $url, $args = array() ) {
	SiteSage_Test_State::$requests[] = array(
		'url'  => $url,
		'args' => $args,
	);
	$next = array_shift( SiteSage_Test_State::$http );
	return null === $next ? new WP_Error( 'no_stub', 'No stubbed response' ) : $next;
}
function wp_remote_retrieve_response_code( $response ) {
	return is_array( $response ) ? $response['response']['code'] : 0;
}
function wp_remote_retrieve_body( $response ) {
	return is_array( $response ) ? $response['body'] : '';
}

require SITESAGE_PLUGIN_DIR . 'includes/autoload.php';

/**
 * Chunk store kept in memory, for retriever and answer tests.
 */
class SiteSage_Memory_Store implements \SiteSage\Chunk_Repository {
	public $rows = array();

	public function insert_many( array $rows ) {
		foreach ( $rows as $row ) {
			$this->rows[] = $row;
		}
		return count( $rows );
	}
	public function delete_source( $source_type, $source_id ) {
		$this->rows = array_values(
			array_filter(
				$this->rows,
				static function ( $row ) use ( $source_type, $source_id ) {
					return ! ( $row['source_type'] === $source_type && (int) $row['source_id'] === (int) $source_id );
				}
			)
		);
	}
	public function delete_all() {
		$this->rows = array();
	}
	public function all_for_model( $model_id ) {
		return array_values(
			array_filter(
				$this->rows,
				static function ( $row ) use ( $model_id ) {
					return $row['embedding_model'] === $model_id;
				}
			)
		);
	}
}

/**
 * Chat provider that records what it was sent.
 */
class SiteSage_Fake_Provider implements \SiteSage\Providers\Chat_Provider {
	public $calls = array();
	public $reply;

	public function __construct( $reply = 'An answer. [Pricing](https://brightsmile.test/pricing/)' ) {
		$this->reply = $reply;
	}
	public function generate( $system, $user ) {
		$this->calls[] = array(
			'system' => $system,
			'user'   => $user,
		);
		return $this->reply;
	}
	public function label() {
		return 'fake:test';
	}
}

/**
 * Build a chunk row for the memory store with a demo embedding.
 *
 * @param string $title Title.
 * @param string $text  Text.
 * @param string $url   URL.
 * @param int    $id    Source ID.
 * @return array
 */
function sitesage_test_row( $title, $text, $url, $id ) {
	$embedder = new \SiteSage\Providers\Demo_Embedding_Provider();
	$input    = \SiteSage\Indexer::embedding_input( $title, $text );
	return array(
		'source_type'     => 'page',
		'source_id'       => $id,
		'source_url'      => $url,
		'title'           => $title,
		'chunk_index'     => 0,
		'content'         => $text,
		'token_count'     => \SiteSage\Chunker::estimate_tokens( $text ),
		'embedding'       => $embedder->embed( array( $input ) )[0],
		'embedding_model' => $embedder->model_id(),
		'content_hash'    => sha1( $input ),
	);
}
