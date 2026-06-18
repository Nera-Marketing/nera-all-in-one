<?php
/**
 * Site-level per-module on/off overrides.
 *
 * The license payload (License_Client) carries the *entitlement* view of which
 * modules may run. This store carries the *site admin* view: an explicit
 * on/off override the operator sets from the dashboard toggle switches, which
 * takes precedence over tier inheritance.
 *
 * All storage goes through the WP options API — no raw SQL.
 *
 * @package Nera\AllInOne\Modules
 */

namespace Nera\AllInOne\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Option-backed override map: module_id => 'on' | 'off'.
 *
 * A module absent from the map (or set to anything other than 'on'/'off')
 * resolves to 'inherit' — the registry then falls back to tier entitlement.
 * Stored without autoload so it does not bloat every page load's bootstrap.
 */
final class Module_Overrides {

	/**
	 * Option key holding the override map.
	 */
	const OPTION = 'nera_aio_module_overrides';

	/**
	 * Return the full override map, normalised to a string=>string array.
	 *
	 * @return array<string,string>
	 */
	public static function all() {
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Resolve the stored override for a module.
	 *
	 * @param string $module_id Stable module id, e.g. 'age-gate'.
	 * @return string 'on' | 'off' | 'inherit'
	 */
	public static function get( $module_id ) {
		$map = self::all();
		if ( isset( $map[ $module_id ] ) && in_array( $map[ $module_id ], array( 'on', 'off' ), true ) ) {
			return $map[ $module_id ];
		}
		return 'inherit';
	}

	/**
	 * Persist an override for a module.
	 *
	 * 'on' / 'off' are stored; 'inherit' (or any other value) removes the key so
	 * the module reverts to tier-based resolution.
	 *
	 * @param string $module_id Stable module id.
	 * @param string $state     'on' | 'off' | 'inherit'.
	 * @return void
	 */
	public static function set( $module_id, $state ) {
		$map = self::all();

		if ( in_array( $state, array( 'on', 'off' ), true ) ) {
			$map[ $module_id ] = $state;
		} else {
			unset( $map[ $module_id ] );
		}

		update_option( self::OPTION, $map, 'no' );
	}
}
