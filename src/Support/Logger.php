<?php
/**
 * @package Nera\AllInOne
 */

namespace Nera\AllInOne\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal logger. No-ops unless WP_DEBUG is on, so production stays quiet.
 */
final class Logger {

	/**
	 * @param string $message
	 * @param array  $context
	 */
	public static function log( $message, array $context = array() ) {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return;
		}
		$line = '[nera-aio] ' . ( is_string( $message ) ? $message : wp_json_encode( $message ) );
		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( $context );
		}
		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
