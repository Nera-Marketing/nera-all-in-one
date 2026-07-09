<?php
/**
 * Hidden plugins — per-site list of catalog entry ids removed from the
 * dashboard by a manager ("Remove from list" on the Manage screen).
 *
 * Built-in manifest entries cannot be deleted from their source, so removal
 * is a per-site hide. Pasting the plugin's GitHub URL into "Add & Install"
 * unhides it. Recovery for entries without a repo URL:
 * `wp option delete nera_aio_hidden_plugins`.
 *
 * @package Nera\AllInOne\Catalog
 */

namespace Nera\AllInOne\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Hidden_Plugins — option-backed id list.
 */
final class Hidden_Plugins {

	/**
	 * Option name holding the id list.
	 */
	const OPTION = 'nera_aio_hidden_plugins';

	/**
	 * Return all hidden entry ids.
	 *
	 * @return string[]
	 */
	public static function ids() {
		$ids = get_option( self::OPTION, array() );
		return is_array( $ids ) ? array_values( array_filter( array_map( 'strval', $ids ) ) ) : array();
	}

	/**
	 * Whether the given id is hidden.
	 *
	 * @param string $id
	 * @return bool
	 */
	public static function is_hidden( $id ) {
		return in_array( $id, self::ids(), true );
	}

	/**
	 * Hide an entry id.
	 *
	 * @param string $id
	 * @return void
	 */
	public static function hide( $id ) {
		$ids = self::ids();
		if ( ! in_array( $id, $ids, true ) ) {
			$ids[] = $id;
			update_option( self::OPTION, $ids, false );
		}
	}

	/**
	 * Unhide an entry id.
	 *
	 * @param string $id
	 * @return void
	 */
	public static function unhide( $id ) {
		$ids = self::ids();
		$kept = array_values( array_diff( $ids, array( $id ) ) );
		if ( count( $kept ) !== count( $ids ) ) {
			update_option( self::OPTION, $kept, false );
		}
	}
}
