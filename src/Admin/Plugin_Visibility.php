<?php
/**
 * Hides Nera plugins from the wp-admin plugin surfaces.
 *
 * Catalog Nera plugins are managed exclusively through the Nera All In One
 * dashboard, so their rows are removed from the Plugins list table and their
 * update notices are stripped from the update transient (Dashboard → Updates,
 * menu count badges). Nera All In One itself always stays visible (matched by
 * plugin_basename( NERA_AIO_FILE ), so renamed install folders still show) so
 * it can be deactivated/deleted through the normal UI.
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
	 * Whether a plugin file ("folder/main.php") should be hidden: only plugins
	 * this instance actually manages — the catalog defaults (data/plugins.json)
	 * plus any added via the "Add & Install" repo link, minus any delisted from
	 * the dashboard. Other plugins (including nera-* plugins not in the catalog)
	 * stay visible. The running Nera All In One instance is never hidden —
	 * identified by plugin_basename( NERA_AIO_FILE ) so GitHub-zip / renamed
	 * folders still appear on plugins.php.
	 *
	 * @param string $plugin_file Plugin basename relative to the plugins dir.
	 * @return bool
	 */
	private static function is_hidden( $plugin_file ) {
		if ( ! is_string( $plugin_file ) || '' === $plugin_file ) {
			return false;
		}
		if ( defined( 'NERA_AIO_FILE' ) && $plugin_file === plugin_basename( NERA_AIO_FILE ) ) {
			return false;
		}
		return in_array( $plugin_file, self::managed_plugin_files(), true );
	}

	/**
	 * The set of installed plugin files ("folder/main.php") that this instance
	 * manages, resolved from the catalog to their real install folders so that
	 * GitHub-zip / renamed folders (e.g. "-main" suffixes) still match. Cached
	 * for the duration of the request.
	 *
	 * @return string[]
	 */
	private static function managed_plugin_files() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}

		$files = array();
		if ( class_exists( __NAMESPACE__ . '\\Plugin_Catalog' ) ) {
			foreach ( Plugin_Catalog::items() as $entry ) {
				$file = Plugin_Catalog::resolve_plugin_file( $entry );
				if ( is_string( $file ) && '' !== $file ) {
					$files[] = $file;
				}
			}
		}

		$cache = array_values( array_unique( $files ) );
		return $cache;
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
