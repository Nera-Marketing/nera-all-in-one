<?php
/**
 * Custom catalog — site-local plugin entries added by a manager via the
 * Manage page's "Add & Install" GitHub URL input.
 *
 * Entries are stored fully normalised (same shape as Manifest entries, plus
 * 'custom' => true) in a non-autoloaded option and merged into Manifest::all()
 * at read time. They are never written into the bundled or cached manifests.
 *
 * @package Nera\AllInOne\Catalog
 */

namespace Nera\AllInOne\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Custom_Catalog — option-backed custom plugin entries.
 */
final class Custom_Catalog {

	/**
	 * Option name holding the entry list.
	 */
	const OPTION = 'nera_aio_custom_plugins';

	/**
	 * Return all custom entries (entries missing an id are dropped).
	 *
	 * @return array[]
	 */
	public static function all() {
		$entries = get_option( self::OPTION, array() );
		if ( ! is_array( $entries ) ) {
			return array();
		}
		return array_values(
			array_filter(
				$entries,
				static function ( $e ) {
					return is_array( $e ) && ! empty( $e['id'] );
				}
			)
		);
	}

	/**
	 * Append an entry and persist.
	 *
	 * @param array $entry Fully-shaped catalog entry.
	 * @return void
	 */
	public static function add( array $entry ) {
		$entries   = self::all();
		$entries[] = $entry;
		update_option( self::OPTION, $entries, false );
	}

	/**
	 * Remove the entry with the given id.
	 *
	 * @param string $id
	 * @return bool Whether an entry was removed.
	 */
	public static function remove( $id ) {
		$entries = self::all();
		$kept    = array_values(
			array_filter(
				$entries,
				static function ( $e ) use ( $id ) {
					return $e['id'] !== $id;
				}
			)
		);
		if ( count( $kept ) === count( $entries ) ) {
			return false;
		}
		update_option( self::OPTION, $kept, false );
		return true;
	}

	/**
	 * Merge a patch into the entry with the given id.
	 *
	 * @param string $id
	 * @param array  $patch
	 * @return void
	 */
	public static function update( $id, array $patch ) {
		$entries = self::all();
		foreach ( $entries as $i => $e ) {
			if ( $e['id'] === $id ) {
				$entries[ $i ] = array_merge( $e, $patch );
				break;
			}
		}
		update_option( self::OPTION, $entries, false );
	}
}
