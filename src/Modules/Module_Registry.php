<?php
/**
 * Module Registry — collects, gates, and boots all feature modules.
 *
 * @package Nera\AllInOne\Modules
 */

namespace Nera\AllInOne\Modules;

use Nera\AllInOne\Contracts\Feature_Module;
use Nera\AllInOne\Entitlements\Entitlements;
use Nera\AllInOne\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Module_Registry
 *
 * Collects Feature_Module instances registered by Plugin::register_modules(),
 * then boots each one during `plugins_loaded` according to the gating rules:
 *   standalone conflict → module_state override → tier entitlement → deps/env.
 *
 * Public surface used by admin screens:
 *   all()                                  — all registered modules
 *   get( string $id )                      — single module by id, or null
 *   is_module_available( Feature_Module )  — would this module boot?
 *   status( Feature_Module )               — 'active'|'locked'|'deps-missing'|'conflict'|'disabled'
 */
final class Module_Registry {

	/**
	 * Ordered list of registered modules.
	 *
	 * @var Feature_Module[]
	 */
	private $modules = array();

	/**
	 * @var Entitlements
	 */
	private $entitlements;

	/**
	 * @param Entitlements $entitlements
	 */
	public function __construct( Entitlements $entitlements ) {
		$this->entitlements = $entitlements;
	}

	// -------------------------------------------------------------------------
	// Registration
	// -------------------------------------------------------------------------

	/**
	 * Append a module to the registry. Registration order is preserved.
	 *
	 * @param Feature_Module $module
	 * @return void
	 */
	public function register( Feature_Module $module ) {
		$this->modules[] = $module;
	}

	// -------------------------------------------------------------------------
	// Accessors (used by admin screens)
	// -------------------------------------------------------------------------

	/**
	 * Return all registered modules in registration order.
	 *
	 * @return Feature_Module[]
	 */
	public function all() {
		return $this->modules;
	}

	/**
	 * Return the module with the given id, or null if not found.
	 *
	 * @param string $id
	 * @return Feature_Module|null
	 */
	public function get( $id ) {
		foreach ( $this->modules as $module ) {
			if ( $module->id() === $id ) {
				return $module;
			}
		}
		return null;
	}

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	/**
	 * Gate and boot every registered module.
	 *
	 * Gating order per module:
	 *   1. standalone conflict   → skip (legacy plugin still active)
	 *   2. module_state === 'off' → skip (admin override)
	 *   3. tier entitlement      → skip if not entitled and no 'on' override
	 *   4. deps / env            → skip if is_available() returns false
	 *   5. first-boot activate   → call activate() exactly once, flag in options
	 *   6. boot()                → register all hooks
	 *
	 * @return void
	 */
	public function boot_all() {
		foreach ( $this->modules as $m ) {

			// 1. Standalone conflict — legacy plugin still active, stand down.
			if ( $m->standalone_conflict() ) {
				Logger::log( 'skip ' . $m->id() . ': standalone active' );
				continue;
			}

			// 2. Per-site override state.
			$state = $this->entitlements->module_state( $m->id() );
			if ( 'off' === $state ) {
				continue;
			}

			// 3. Tier entitlement. An explicit 'on' override bypasses tier check.
			$entitled = ( 'on' === $state ) || $this->entitlements->is_entitled( $m->min_tier() );
			if ( ! $entitled ) {
				continue;
			}

			// 4. Dependencies / environment.
			if ( ! $m->is_available( $this->entitlements ) ) {
				continue;
			}

			// 5. First-boot idempotent activate (stored as a timestamp option).
			$flag = 'nera_aio_module_activated_' . $m->id();
			if ( ! get_option( $flag ) ) {
				$m->activate();
				update_option( $flag, time(), 'no' );
			}

			// 6. Boot — register all hooks.
			$m->boot();
		}
	}

	// -------------------------------------------------------------------------
	// Admin helpers
	// -------------------------------------------------------------------------

	/**
	 * Whether this module would be booted if boot_all() ran right now.
	 *
	 * Mirrors steps 1-5 (conflict → state → entitlement → deps) without calling
	 * activate() or boot(). Safe to call from admin screens.
	 *
	 * @param Feature_Module $m
	 * @return bool
	 */
	public function is_module_available( Feature_Module $m ) {
		if ( $m->standalone_conflict() ) {
			return false;
		}

		$state = $this->entitlements->module_state( $m->id() );
		if ( 'off' === $state ) {
			return false;
		}

		$entitled = ( 'on' === $state ) || $this->entitlements->is_entitled( $m->min_tier() );
		if ( ! $entitled ) {
			return false;
		}

		if ( ! $m->is_available( $this->entitlements ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Return a single string describing the module's effective state.
	 *
	 * Used by the Modules admin screen to render status badges.
	 *
	 *   'active'       — passes all gates; would boot.
	 *   'conflict'     — standalone legacy plugin is still active.
	 *   'disabled'     — admin override is 'off'.
	 *   'locked'       — site is not entitled to the required tier.
	 *   'deps-missing' — entitled but one or more deps/env checks failed.
	 *
	 * @param Feature_Module $m
	 * @return string 'active'|'locked'|'deps-missing'|'conflict'|'disabled'
	 */
	public function status( Feature_Module $m ) {
		// Step 1 — standalone conflict.
		if ( $m->standalone_conflict() ) {
			return 'conflict';
		}

		// Step 2 — explicit admin override off.
		$state = $this->entitlements->module_state( $m->id() );
		if ( 'off' === $state ) {
			return 'disabled';
		}

		// Step 3 — tier entitlement.
		$entitled = ( 'on' === $state ) || $this->entitlements->is_entitled( $m->min_tier() );
		if ( ! $entitled ) {
			return 'locked';
		}

		// Step 4 — deps / environment.
		if ( ! $m->is_available( $this->entitlements ) ) {
			return 'deps-missing';
		}

		return 'active';
	}
}
