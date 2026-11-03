<?php
/**
 * Debug logging that stays silent unless WP_DEBUG is on.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Support;

/**
 * Writes to the PHP error log only when WP_DEBUG is true. Never logs API keys.
 */
final class Log {

	/**
	 * Log an error.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Extra data.
	 */
	public static function error( string $message, array $context = array() ): void {
		self::write( 'error', $message, $context );
	}

	/**
	 * Log debug detail.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Extra data.
	 */
	public static function debug( string $message, array $context = array() ): void {
		self::write( 'debug', $message, $context );
	}

	/**
	 * Write a line.
	 *
	 * @param string               $level   Level.
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Extra data.
	 */
	private static function write( string $level, string $message, array $context ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		unset( $context['api_key'], $context['x-api-key'] );
		$line = sprintf( '[spa-ai-concierge] %s: %s', $level, $message );
		if ( array() !== $context ) {
			$line .= ' ' . wp_json_encode( $context );
		}
		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- gated on WP_DEBUG.
	}
}
