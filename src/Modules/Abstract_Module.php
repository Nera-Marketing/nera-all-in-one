<?php
/**
 * Abstract base class for all feature modules.
 *
 * Provides concrete defaults for the optional parts of Feature_Module, leaving
 * identity and behaviour abstract for concrete subclasses.
 *
 * @package Nera\AllInOne\Modules
 */

namespace Nera\AllInOne\Modules;

use Nera\AllInOne\Contracts\Feature_Module;
use Nera\AllInOne\Entitlements\Entitlements;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract_Module — concrete defaults + plugin-detection helpers.
 *
 * Subclasses MUST implement: id(), label(), min_tier(), boot().
 * Subclasses MAY override: deps(), standalone_conflict(), activate(),
 * deactivate(), uninstall(), is_available().
 */
abstract class Abstract_Module implements Feature_Module {

	// -------------------------------------------------------------------------
	// Abstract — identity & behaviour (subclass must provide)
	// -------------------------------------------------------------------------

	/**
	 * Stable machine-readable id, e.g. 'age-gate'.
	 * Used as option-flag key and override-map key.
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Human-readable label for the Modules admin screen.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * Minimum tier required to boot this module.
	 * Must be one of Tier::FREE | Tier::PRO | Tier::AGENCY.
	 *
	 * @return string
	 */
	abstract public function min_tier();

	/**
	 * Register all WordPress hooks for this module.
	 * Called only when the module is available and entitled.
	 *
	 * @return void
	 */
	abstract public function boot();

	// -------------------------------------------------------------------------
	// Optional overrides — sensible defaults provided
	// -------------------------------------------------------------------------

	/**
	 * Runtime dependencies as plugin/feature slugs.
	 * Return an empty array when there are no dependencies.
	 *
	 * @return string[]
	 */
	public function deps() {
		return array();
	}

	/**
	 * Whether the legacy standalone version of this feature is still active.
	 * First-party modules always return false.
	 * Wrapped legacy modules override this to prevent double-loading.
	 *
	 * @return bool
	 */
	public function standalone_conflict() {
		return false;
	}

	/**
	 * Idempotent setup (tables, capabilities, rewrite rules, etc.).
	 * Called once on first-boot; subsequent calls are no-ops by convention.
	 *
	 * @return void
	 */
	public function activate() {}

	/**
	 * Stop active behaviour: unschedule cron jobs, flush rewrite rules, etc.
	 * MUST NOT remove data — deactivate is reversible.
	 *
	 * @return void
	 */
	public function deactivate() {}

	/**
	 * Destructive teardown. Remove all data owned by this module.
	 * Only called when the user explicitly requests uninstall.
	 *
	 * @return void
	 */
	public function uninstall() {}

	/**
	 * Whether the module may run right now: all declared deps are active and
	 * any environment constraints are satisfied.
	 *
	 * The registry also checks tier entitlement independently; this method
	 * covers only deps/env so each concern stays separate.
	 *
	 * @param Entitlements $entitlements
	 * @return bool
	 */
	public function is_available( Entitlements $entitlements ) {
		foreach ( $this->deps() as $dep ) {
			if ( ! $this->dep_active( $dep ) ) {
				return false;
			}
		}
		return true;
	}

	// -------------------------------------------------------------------------
	// Protected helpers
	// -------------------------------------------------------------------------

	/**
	 * Check whether a single dependency slug is satisfied.
	 *
	 * Handles first-class slugs ('woocommerce', 'lottery-for-woocommerce') with
	 * cheap symbol checks. Falls back to is_plugin_active() for arbitrary slugs.
	 *
	 * @param string $slug Dependency slug.
	 * @return bool
	 */
	protected function dep_active( $slug ) {
		switch ( $slug ) {
			case 'woocommerce':
				return class_exists( 'WooCommerce' );

			case 'lottery-for-woocommerce':
				return function_exists( 'lty_is_lottery_product' );

			default:
				if ( ! function_exists( 'is_plugin_active' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				return is_plugin_active( $slug );
		}
	}
}
