<?php
/**
 * @package Nera\AllInOne
 */

namespace Nera\AllInOne\Contracts;

use Nera\AllInOne\Entitlements\Entitlements;

defined( 'ABSPATH' ) || exit;

/**
 * FROZEN CONTRACT. Every feature module (wrapped legacy plugin or first-party)
 * implements this. The registry only ever calls these methods.
 */
interface Feature_Module {

	/** Stable id, e.g. 'age-gate'. Used for flags + override map keys. */
	public function id();

	/** Human label for the dashboard. */
	public function label();

	/** Minimum tier required: Tier::FREE | Tier::PRO | Tier::AGENCY. */
	public function min_tier();

	/**
	 * Runtime dependencies as plugin/feature slugs, e.g. ['woocommerce'].
	 *
	 * @return string[]
	 */
	public function deps();

	/**
	 * Whether the module may run right now: entitled (tier) AND deps active
	 * (AND, for dev modules, non-production). The registry also enforces tier;
	 * this method covers deps/env.
	 *
	 * @param Entitlements $entitlements
	 * @return bool
	 */
	public function is_available( Entitlements $entitlements );

	/**
	 * Whether the legacy standalone plugin is still active (so this wrapped
	 * module must stand down to avoid double-loading). First-party modules
	 * return false.
	 *
	 * @return bool
	 */
	public function standalone_conflict();

	/** Register hooks. Called ONLY when the module is available + entitled. */
	public function boot();

	/** Idempotent setup (tables/caps/rewrites). No-op if already done. */
	public function activate();

	/** Stop behaviour (unschedule cron, flush rewrites). KEEPS data. */
	public function deactivate();

	/** Destructive teardown. Opt-in ONLY. */
	public function uninstall();
}
