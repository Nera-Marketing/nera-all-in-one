<?php
/**
 * Entitlements — resolves the active tier and per-module override state.
 *
 * @package Nera\AllInOne
 */

namespace Nera\AllInOne\Entitlements;

use Nera\AllInOne\Modules\Module_Overrides;

defined( 'ABSPATH' ) || exit;

/**
 * Determines what the current site is entitled to run based on the license
 * response from License_Client. All persistence is owned by the client;
 * this class is stateless and side-effect-free.
 *
 * FROZEN CONTRACT — method names, parameter types, and return types must not
 * change; the registry and feature modules depend on them.
 */
final class Entitlements {

	/**
	 * @var \Nera\AllInOne\License\License_Client
	 */
	private $license;

	/**
	 * @param \Nera\AllInOne\License\License_Client $license
	 */
	public function __construct( \Nera\AllInOne\License\License_Client $license ) {
		$this->license = $license;
	}

	// -------------------------------------------------------------------------
	// Public — FROZEN CONTRACT
	// -------------------------------------------------------------------------

	/**
	 * Returns the active tier string for this site.
	 *
	 * Reads the license payload; falls back to NERA_AIO_DEFAULT_TIER when the
	 * payload is absent, malformed, or contains an unrecognised tier value.
	 *
	 * @return string One of Tier::FREE | Tier::PRO | Tier::AGENCY (or the
	 *                default tier constant defined at plugin bootstrap).
	 */
	public function current_tier() {
		$payload = $this->safe_payload();
		$tier    = isset( $payload['tier'] ) ? $payload['tier'] : null;

		if ( Tier::is_valid( $tier ) ) {
			return $tier;
		}

		return defined( 'NERA_AIO_DEFAULT_TIER' ) ? NERA_AIO_DEFAULT_TIER : Tier::FREE;
	}

	/**
	 * Whether the site's current tier meets or exceeds the required minimum.
	 *
	 * @param string $min_tier Minimum tier required (Tier::FREE|PRO|AGENCY).
	 * @return bool
	 */
	public function is_entitled( $min_tier ) {
		return Tier::rank( $this->current_tier() ) >= Tier::rank( $min_tier );
	}

	/**
	 * Returns the per-site override state for a module.
	 *
	 * 'on'      — explicitly forced the module on.
	 * 'off'     — explicitly forced the module off.
	 * 'inherit' — no override; the registry falls back to tier entitlement.
	 *
	 * Resolution precedence:
	 *   1. Site admin override (Module_Overrides option, set via the dashboard
	 *      toggle switches) — highest priority.
	 *   2. License payload modules map (reserved for a future central server;
	 *      empty today).
	 *   3. 'inherit'.
	 *
	 * @param string $module_id Stable module id, e.g. 'age-gate'.
	 * @return string 'on' | 'off' | 'inherit'
	 */
	public function module_state( $module_id ) {
		// 1. Site admin override wins over everything.
		$admin = Module_Overrides::get( $module_id );
		if ( in_array( $admin, array( 'on', 'off' ), true ) ) {
			return $admin;
		}

		// 2. License payload modules map (empty in MVP).
		$payload = $this->safe_payload();
		$modules = isset( $payload['modules'] ) && is_array( $payload['modules'] )
			? $payload['modules']
			: array();

		if ( isset( $modules[ $module_id ] ) && in_array( $modules[ $module_id ], array( 'on', 'off' ), true ) ) {
			return $modules[ $module_id ];
		}

		return 'inherit';
	}

	// -------------------------------------------------------------------------
	// Convenience
	// -------------------------------------------------------------------------

	/**
	 * Returns the raw license payload normalised to a consistent shape.
	 *
	 * Guaranteed to always return an array with at minimum:
	 *   [ 'tier' => string, 'modules' => array ]
	 *
	 * @return array
	 */
	public function current_entitlement() {
		$payload = $this->safe_payload();

		return array(
			'tier'    => isset( $payload['tier'] ) && is_string( $payload['tier'] ) ? $payload['tier'] : '',
			'modules' => isset( $payload['modules'] ) && is_array( $payload['modules'] ) ? $payload['modules'] : array(),
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Calls license->current() and returns a safe array regardless of what the
	 * client returns.  Swallows non-array responses without triggering warnings.
	 *
	 * @return array
	 */
	private function safe_payload() {
		$raw = $this->license->current();
		return is_array( $raw ) ? $raw : array();
	}
}
