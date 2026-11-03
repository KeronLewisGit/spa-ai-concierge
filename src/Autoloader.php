<?php
/**
 * Fallback PSR-4 autoloader used when Composer's vendor/ directory is not shipped.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge;

/**
 * Maps KeronLewis\SpaAiConcierge\Foo\Bar to src/Foo/Bar.php.
 */
final class Autoloader {

	private const PREFIX = 'KeronLewis\\SpaAiConcierge\\';

	/**
	 * Base directory with a trailing slash.
	 *
	 * @var string
	 */
	private static string $base_dir = '';

	/**
	 * Register the loader.
	 *
	 * @param string $base_dir Absolute path to src/ with a trailing slash.
	 */
	public static function register( string $base_dir ): void {
		self::$base_dir = rtrim( $base_dir, '/\\' ) . '/';
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Load a class from the plugin namespace.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	public static function load( string $class_name ): void {
		if ( ! str_starts_with( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );
		if ( '' === $relative || str_contains( $relative, '..' ) ) {
			return;
		}

		$file = self::$base_dir . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
