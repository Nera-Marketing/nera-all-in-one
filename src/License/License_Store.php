<?php
/**
 * Persistence layer for license key and entitlement data.
 *
 * All storage goes through the WP options / transients API — no raw SQL.
 *
 * @package Nera\AllInOne\License
 */

namespace Nera\AllInOne\License;

defined( 'ABSPATH' ) || exit;

/**
 * Pure persistence: reads and writes the license key, the live entitlement
 * transient, and the last-known-good (LKG) entitlement option.
 *
 * Option / transient keys:
 *   nera_aio_license_key      — WP option, the raw license key string.
 *   nera_aio_entitlement      — transient, cached entitlement array.
 *   nera_aio_entitlement_lkg  — WP option, last-known-good entitlement array.
 */
final class License_Store {

	// -------------------------------------------------------------------------
	// License key
	// -------------------------------------------------------------------------

	/**
	 * Returns the stored license key, or an empty string if none is set.
	 *
	 * @return string
	 */
	public function get_key() {
		$value = get_option( 'nera_aio_license_key', '' );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Persists a sanitized license key.
	 *
	 * @param string $key Raw key as entered by the user.
	 * @return void
	 */
	public function set_key( $key ) {
		update_option( 'nera_aio_license_key', trim( sanitize_text_field( $key ) ) );
	}

	// -------------------------------------------------------------------------
	// Live entitlement transient
	// -------------------------------------------------------------------------

	/**
	 * Returns the cached entitlement array, or null when the transient has
	 * expired or was never set.
	 *
	 * @return array|null
	 */
	public function get_entitlement() {
		$value = get_transient( 'nera_aio_entitlement' );
		if ( ! is_array( $value ) ) {
			return null;
		}
		return $value;
	}

	/**
	 * Caches an entitlement array for the given TTL.
	 *
	 * @param array $ent Entitlement array.
	 * @param int   $ttl Time-to-live in seconds.
	 * @return void
	 */
	public function set_entitlement( array $ent, $ttl ) {
		set_transient( 'nera_aio_entitlement', $ent, (int) $ttl );
	}

	/**
	 * Removes the cached entitlement transient, forcing a fresh resolve on the
	 * next request.
	 *
	 * @return void
	 */
	public function delete_entitlement() {
		delete_transient( 'nera_aio_entitlement' );
	}

	// -------------------------------------------------------------------------
	// Last-known-good entitlement option
	// -------------------------------------------------------------------------

	/**
	 * Returns the last-known-good entitlement array, or null when it has never
	 * been written.
	 *
	 * @return array|null
	 */
	public function get_lkg() {
		$value = get_option( 'nera_aio_entitlement_lkg', null );
		if ( ! is_array( $value ) ) {
			return null;
		}
		return $value;
	}

	/**
	 * Persists the last-known-good entitlement. Stored without autoload so it
	 * does not bloat every page load's option bootstrap.
	 *
	 * @param array $ent Entitlement array.
	 * @return void
	 */
	public function set_lkg( array $ent ) {
		update_option( 'nera_aio_entitlement_lkg', $ent, 'no' );
	}
}
