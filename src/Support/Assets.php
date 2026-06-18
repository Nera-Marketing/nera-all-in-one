<?php
/**
 * @package Nera\AllInOne
 */

namespace Nera\AllInOne\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves asset URLs relative to the plugin root. Critical for wrapped modules:
 * their files moved under modules/<id>/, so any plugin_dir_url() base inside the
 * original plugin must be rebuilt from here.
 */
final class Assets {

	/**
	 * URL to a file inside a module dir, e.g. module_url('age-gate', 'assets/age-gate.css').
	 *
	 * @param string $module_id
	 * @param string $relative
	 * @return string
	 */
	public static function module_url( $module_id, $relative ) {
		return plugins_url( 'modules/' . $module_id . '/' . ltrim( $relative, '/' ), NERA_AIO_FILE );
	}

	/**
	 * Filesystem path to a module dir (trailing slash).
	 *
	 * @param string $module_id
	 * @return string
	 */
	public static function module_dir( $module_id ) {
		return NERA_AIO_DIR . 'modules/' . $module_id . '/';
	}

	/**
	 * URL to a shared (dashboard) asset, e.g. admin_url('admin.css').
	 *
	 * @param string $relative
	 * @return string
	 */
	public static function admin_url( $relative ) {
		return plugins_url( 'assets/' . ltrim( $relative, '/' ), NERA_AIO_FILE );
	}
}
