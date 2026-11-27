<?php
/**
 * Plugin manifest loader.
 *
 * Source of truth for the Nera plugin catalog: which plugins exist, their
 * install slug + main file, the GitHub repo to install from, tier and
 * presentation metadata. Fetched from a central JSON (NERA_AIO_MANIFEST_URL),
 * cached, and backed by a bundled fallback so the dashboard always renders.
 *
 * @package Nera\AllInOne\Catalog
 */

namespace Nera\AllInOne\Catalog;

use Nera\AllInOne\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Manifest — remote-with-fallback plugin list.
 */
final class Manifest {

	/**
	 * Transient key for the cached remote manifest.
	 */
	const CACHE_KEY = 'nera_aio_manifest';

	/**
	 * Cache lifetime for a successful remote fetch.
	 */
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Required keys every normalised entry exposes.
	 *
	 * @var array<string,mixed>
	 */
	private static $defaults = array(
		'id'        => '',
		'name'      => '',
		'tagline'   => '',
		'icon'      => '🔌',
		'accent'    => '#64748b',
		'min_tier'  => 'free',
		'slug'      => '',
		'main_file' => '',
		'gh_repo'   => null,
		'deps'      => array(),
		'edit_url'  => '',
	);

	/**
	 * Return the normalised plugin list.
	 *
	 * Order of resolution: live transient → remote fetch → bundled fallback.
	 * Manager-added custom entries (Custom_Catalog) are appended at read time
	 * — never cached — with built-in entries winning any id/slug collision.
	 *
	 * @return array[] List of normalised entries.
	 */
	public static function all() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return self::with_custom( $cached );
		}

		$entries = self::fetch_remote();
		if ( null === $entries ) {
			$entries = self::bundled();
		} else {
			set_transient( self::CACHE_KEY, $entries, self::CACHE_TTL );
		}

		return self::with_custom( $entries );
	}

	/**
	 * Append custom entries to a base list, skipping id/slug collisions.
	 *
	 * @param array[] $base Normalised built-in entries.
	 * @return array[]
	 */
	private static function with_custom( array $base ) {
		$ids   = array_column( $base, 'id' );
		$slugs = array_column( $base, 'slug' );

		foreach ( Custom_Catalog::all() as $entry ) {
			$entry = self::normalise( $entry );
			if ( in_array( $entry['id'], $ids, true ) || in_array( $entry['slug'], $slugs, true ) ) {
				continue;
			}
			$entry['custom'] = true;
			$base[]          = $entry;
		}

		return $base;
	}

	/**
	 * Force a fresh remote fetch on the next all() call.
	 *
	 * @return void
	 */
	public static function flush() {
		delete_transient( self::CACHE_KEY );
	}

	// -------------------------------------------------------------------------
	// Sources
	// -------------------------------------------------------------------------

	/**
	 * Fetch + parse the remote manifest. Returns null on any failure so the
	 * caller can fall back to the bundled copy.
	 *
	 * @return array[]|null
	 */
	private static function fetch_remote() {
		$url = defined( 'NERA_AIO_MANIFEST_URL' ) ? (string) NERA_AIO_MANIFEST_URL : '';
		if ( '' === $url ) {
			return null;
		}

		$res = wp_remote_get(
			$url,
			array(
				'timeout' => 8,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			Logger::log( 'manifest fetch failed; using bundled fallback' );
			return null;
		}

		$parsed = json_decode( wp_remote_retrieve_body( $res ), true );
		$list   = self::extract( $parsed );

		return $list ? self::normalise_all( $list ) : null;
	}

	/**
	 * Read + parse the bundled fallback manifest shipped with the plugin.
	 *
	 * @return array[]
	 */
	private static function bundled() {
		$path = NERA_AIO_DIR . 'data/plugins.json';
		if ( ! is_readable( $path ) ) {
			return array();
		}

		$parsed = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$list   = self::extract( $parsed );

		return $list ? self::normalise_all( $list ) : array();
	}

	// -------------------------------------------------------------------------
	// Normalisation
	// -------------------------------------------------------------------------

	/**
	 * Pull the plugin array out of a decoded manifest (supports either a bare
	 * array or a { "plugins": [...] } envelope).
	 *
	 * @param mixed $parsed
	 * @return array[]|null
	 */
	private static function extract( $parsed ) {
		if ( is_array( $parsed ) && isset( $parsed['plugins'] ) && is_array( $parsed['plugins'] ) ) {
			return $parsed['plugins'];
		}
		if ( is_array( $parsed ) && isset( $parsed[0] ) ) {
			return $parsed;
		}
		return null;
	}

	/**
	 * Normalise every entry to the full key set; drop entries lacking an id.
	 *
	 * @param array[] $list
	 * @return array[]
	 */
	private static function normalise_all( array $list ) {
		$out = array();
		foreach ( $list as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['id'] ) ) {
				continue;
			}
			$out[] = self::normalise( $entry );
		}
		return $out;
	}

	/**
	 * Merge one raw entry over the defaults and coerce types.
	 *
	 * @param array $entry
	 * @return array
	 */
	private static function normalise( array $entry ) {
		$e = array_merge( self::$defaults, $entry );

		$e['deps']    = is_array( $e['deps'] ) ? array_values( array_filter( array_map( 'strval', $e['deps'] ) ) ) : array();
		$e['gh_repo'] = ( is_string( $e['gh_repo'] ) && '' !== $e['gh_repo'] ) ? $e['gh_repo'] : null;
		$e['edit_url'] = is_string( $e['edit_url'] ) ? $e['edit_url'] : '';

		// Default the install folder to the id when a slug is omitted.
		if ( '' === $e['slug'] ) {
			$e['slug'] = sanitize_key( $e['id'] );
		}

		return $e;
	}
}
