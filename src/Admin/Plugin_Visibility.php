<?php
/**
 * Hides Nera plugins from the wp-admin plugin surfaces.
 *
 * All Nera plugins are managed exclusively through the Nera All In One
 * dashboard, so their rows are removed from the Plugins list table and their
 * update notices are stripped from the update transient (Dashboard → Updates,
 * menu count badges). Nera All In One itself stays visible so it can be
 * deactivated/updated through the normal UI.
 *
 * Cosmetic, not security: the plugins remain active and manageable via WP-CLI.
 * Filters register admin-side only, so wp-cron auto-updates and WP-CLI keep
 * seeing the real update transient. Define NERA_AIO_SHOW_ALL_PLUGINS (truthy)
 * in wp-config.php to reveal everything again for dev/debugging.
 *
 * @package Nera\AllInOne\Admin
 */

namespace Nera\AllInOne\Admin;

use Nera\AllInOne\Entitlements\Entitlements;
use Nera\AllInOne\License\License_Client;
use Nera\AllInOne\Modules\Module_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin_Visibility — filters Nera plugins out of plugins.php and the
 * update-plugins transient.
 *
 * Constructor signature matches the other admin screens so Plugin::boot_admin()
 * can instantiate it from the same loop.
 */
final class Plugin_Visibility {

	/**
	 * @var License_Client
	 */
	private $license;

	/**
	 * @var Entitlements
	 */
	private $entitlements;

	/**
	 * @var Module_Registry
	 */
	private $registry;

	/**
	 * @param License_Client  $license
	 * @param Entitlements    $entitlements
	 * @param Module_Registry $registry
	 */
	public function __construct( License_Client $license, Entitlements $entitlements, Module_Registry $registry ) {
		$this->license      = $license;
		$this->entitlements = $entitlements;
		$this->registry     = $registry;
	}

	/**
	 * Register WP hooks. Called by Plugin::boot_admin().
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'all_plugins', array( $this, 'filter_plugin_list' ) );
		add_filter( 'site_transient_update_plugins', array( $this, 'filter_update_transient' ) );
	}

	// -------------------------------------------------------------------------
	// Filters
	// -------------------------------------------------------------------------

	/**
	 * Remove hidden Nera plugins from the plugins.php list table. The
	 * All/Active/Inactive view counts derive from this filtered list, so they
	 * adjust automatically.
	 *
	 * @param array $plugins Map of plugin_file => plugin data.
	 * @return array
	 */
	public function filter_plugin_list( $plugins ) {
		if ( self::show_all() || ! is_array( $plugins ) ) {
			return $plugins;
		}

		foreach ( array_keys( $plugins ) as $file ) {
			if ( self::is_hidden( $file ) ) {
				unset( $plugins[ $file ] );
			}
		}

		return $plugins;
	}

	/**
	 * Strip hidden Nera plugins' pending updates from the update transient so
	 * they don't surface in Dashboard → Updates or the admin count badges.
	 * Updates for them are offered on the AIO dashboard instead (GitHub-release
	 * driven, independent of this transient).
	 *
	 * @param mixed $value Transient value (object with a 'response' map, or false).
	 * @return mixed
	 */
	public function filter_update_transient( $value ) {
		if ( self::show_all() || ! is_object( $value ) || empty( $value->response ) || ! is_array( $value->response ) ) {
			return $value;
		}

		foreach ( array_keys( $value->response ) as $file ) {
			if ( self::is_hidden( $file ) ) {
				unset( $value->response[ $file ] );
			}
		}

		return $value;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Whether a plugin file ("folder/main.php") should be hidden: any Nera
	 * plugin. Nera All In One's own row stays visible to manager users only
	 * (non-managers must not reach its Deactivate action — Support\Guard also
	 * denies the 'deactivate_plugin' cap as backup).
	 *
	 * @param string $plugin_file Plugin basename relative to the plugins dir.
	 * @return bool
	 */
	private static function is_hidden( $plugin_file ) {
		if ( ! is_string( $plugin_file ) || 0 !== strpos( $plugin_file, 'nera-' ) ) {
			return false;
		}
		if ( 0 === strpos( $plugin_file, 'nera-all-in-one/' ) ) {
			return ! \Nera\AllInOne\Support\Managers::is_manager();
		}
		return true;
	}

	/**
	 * Whether the reveal-everything escape hatch is enabled.
	 *
	 * @return bool
	 */
	private static function show_all() {
		return defined( 'NERA_AIO_SHOW_ALL_PLUGINS' ) && NERA_AIO_SHOW_ALL_PLUGINS;
	}
}
