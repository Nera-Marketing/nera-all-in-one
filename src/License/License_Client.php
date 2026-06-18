<?php
/**
 * License resolution and entitlement retrieval.
 *
 * Computes entitlements from the stored key, manages the transient cache, and
 * exposes the current entitlement without making a remote call when possible.
 *
 * @package Nera\AllInOne\License
 */

namespace Nera\AllInOne\License;

use Nera\AllInOne\Entitlements\Tier;
use Nera\AllInOne\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves, caches, and serves license entitlements.
 *
 * MVP implementation: no central server. All resolution is local — a demo key
 * grants PRO in demo mode only; every other unknown key falls back to the
 * default (free) tier and is logged for future debugging.
 */
final class License_Client {

	/**
	 * @var License_Store
	 */
	private $store;

	/**
	 * @param License_Store $store
	 */
	public function __construct( License_Store $store ) {
		$this->store = $store;
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Computes a fresh entitlement from the stored key without touching the
	 * cache. Applies the demo-key gate and the env safety rail.
	 *
	 * @return array Entitlement array with keys: tier, modules, source, checked_at.
	 */
	public function resolve() {
		$key = $this->store->get_key();

		if ( '' === $key ) {
			return self::entitlement( NERA_AIO_DEFAULT_TIER, 'no-key' );
		}

		if ( hash_equals( NERA_AIO_DEMO_KEY, $key ) ) {
			if ( NERA_AIO_DEMO_MODE ) {
				return self::entitlement( Tier::PRO, 'demo' );
			}
			Logger::log( 'demo key rejected on production' );
			return self::entitlement( NERA_AIO_DEFAULT_TIER, 'demo-rejected-prod' );
		}

		// Signed key — verified offline against the embedded Ed25519 public key.
		$ins = Signed_Key::inspect( $key );
		if ( $ins['ok'] ) {
			if ( $ins['expired'] ) {
				Logger::log( 'signed license key expired' );
				return self::entitlement( NERA_AIO_DEFAULT_TIER, 'signed-expired' );
			}
			if ( Tier::is_valid( $ins['tier'] ) ) {
				return self::entitlement( $ins['tier'], 'signed' );
			}
			Logger::log( 'signed key has invalid tier' );
			return self::entitlement( NERA_AIO_DEFAULT_TIER, 'signed-bad-tier' );
		}

		// Unrecognised key -> treat as free, log.
		Logger::log( 'unknown / invalid license key' );
		return self::entitlement( NERA_AIO_DEFAULT_TIER, 'unknown-key' );
	}

	/**
	 * Resolves a fresh entitlement, caches it (transient + LKG), and returns it.
	 *
	 * @return array
	 */
	public function check_now() {
		$ent = $this->resolve();
		$this->store->set_entitlement( $ent, 12 * HOUR_IN_SECONDS );
		$this->store->set_lkg( $ent );
		return $ent;
	}

	/**
	 * Returns the best available entitlement without triggering a remote call:
	 *   1. Live transient.
	 *   2. Last-known-good option.
	 *   3. Hard default (free tier).
	 *
	 * Always returns a well-formed array with 'tier' and 'modules' keys.
	 *
	 * @return array
	 */
	public function current() {
		$ent = $this->store->get_entitlement();

		if ( ! is_array( $ent ) ) {
			$ent = $this->store->get_lkg();
		}

		if ( ! is_array( $ent ) ) {
			$ent = self::entitlement( NERA_AIO_DEFAULT_TIER, 'default' );
		}

		// Normalise: ensure both mandatory keys are present regardless of how
		// legacy or partial data ended up in the store.
		if ( ! isset( $ent['tier'] ) || ! Tier::is_valid( $ent['tier'] ) ) {
			$ent['tier'] = NERA_AIO_DEFAULT_TIER;
		}
		if ( ! isset( $ent['modules'] ) || ! is_array( $ent['modules'] ) ) {
			$ent['modules'] = array();
		}

		return $ent;
	}

	/**
	 * Returns the demo key string. Intended for the Upgrade modal to reveal it.
	 * Only meaningful when NERA_AIO_DEMO_MODE is true.
	 *
	 * @return string
	 */
	public function get_demo_key() {
		return NERA_AIO_DEMO_KEY;
	}

	/**
	 * Whether the site is currently running in demo mode.
	 *
	 * @return bool
	 */
	public function is_demo_mode() {
		return (bool) NERA_AIO_DEMO_MODE;
	}

	// -------------------------------------------------------------------------
	// Admin facade — keeps the admin layer decoupled from License_Store.
	// -------------------------------------------------------------------------

	/**
	 * The currently stored license key (for pre-filling the Licensing field).
	 *
	 * @return string
	 */
	public function get_key() {
		return $this->store->get_key();
	}

	/**
	 * Store a new key then immediately re-resolve + cache. Returns the resulting
	 * entitlement so the caller can report the new tier.
	 *
	 * @param string $key
	 * @return array
	 */
	public function apply_key( $key ) {
		$this->store->set_key( $key );
		return $this->check_now();
	}

	/**
	 * Clear the stored key (revert to free) then re-resolve + cache.
	 *
	 * @return array
	 */
	public function clear_key() {
		$this->store->set_key( '' );
		return $this->check_now();
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Builds a normalised entitlement array.
	 *
	 * @param string $tier   One of the Tier constants.
	 * @param string $source Human-readable origin label for debugging.
	 * @return array
	 */
	private static function entitlement( $tier, $source ) {
		return array(
			'tier'       => $tier,
			'modules'    => array(),
			'source'     => $source,
			'checked_at' => time(),
		);
	}
}
