<?php
/**
 * Class autoloader for the SiteSage namespace.
 *
 * Maps SiteSage\Foo_Bar to includes/class-foo-bar.php and
 * SiteSage\Providers\Foo_Bar to includes/providers/class-foo-bar.php.
 * Interfaces use the interface- prefix.
 *
 * @package SiteSage
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'SiteSage\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$parts    = explode( '\\', $relative );
		$name     = array_pop( $parts );
		$folder   = '';
		foreach ( $parts as $part ) {
			$folder .= strtolower( $part ) . '/';
		}

		$slug = strtolower( str_replace( '_', '-', $name ) );
		$base = __DIR__ . '/' . $folder;

		foreach ( array( 'class-', 'interface-' ) as $type ) {
			$file = $base . $type . $slug . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
				return;
			}
		}
	}
);
