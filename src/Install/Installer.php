<?php
/**
 * GitHub release installer.
 *
 * Reads a plugin's GitHub releases (public, unauthenticated), downloads the
 * release zip asset, and installs it into wp-content/plugins via core's
 * Plugin_Upgrader — normalising the unpacked folder to the manifest slug so the
 * install path is deterministic regardless of the zip's internal folder name.
 *
 * @package Nera\AllInOne\Install
 */

namespace Nera\AllInOne\Install;

use Nera\AllInOne\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Installer — release lookup + download + install + activate.
 */
final class Installer {

	/**
	 * Releases cache TTL.
	 */
	const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Return published releases for a repo, newest first.
	 *
	 * @param string $repo "owner/name".
	 * @return array[] Each: { tag: string, zip: string }. Empty on failure.
	 */
	public static function releases( $repo ) {
		$repo = (string) $repo;
		if ( '' === $repo ) {
			return array();
		}

		$cache_key = 'nera_aio_rel_' . md5( $repo );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$res = wp_remote_get(
			'https://api.github.com/repos/' . $repo . '/releases?per_page=30',
			array(
				'timeout' => 12,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'nera-all-in-one',
				),
			)
		);

		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			Logger::log( 'github releases fetch failed for ' . $repo );
			return array();
		}

		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $json ) ) {
			return array();
		}

		$out = array();
		foreach ( $json as $rel ) {
			if ( ! empty( $rel['draft'] ) || ! empty( $rel['prerelease'] ) ) {
				continue;
			}
			$zip = self::pick_asset( $rel );
			if ( '' === $zip ) {
				continue;
			}
			$out[] = array(
				'tag' => isset( $rel['tag_name'] ) ? (string) $rel['tag_name'] : '',
				'zip' => $zip,
			);
		}

		set_transient( $cache_key, $out, self::CACHE_TTL );
		return $out;
	}

	/**
	 * The latest release for a repo, or null.
	 *
	 * @param string $repo
	 * @return array|null { tag, zip }
	 */
	public static function latest( $repo ) {
		$rels = self::releases( $repo );
		return $rels ? $rels[0] : null;
	}

	/**
	 * The latest tag string for a repo, or '' .
	 *
	 * @param string $repo
	 * @return string
	 */
	public static function latest_tag( $repo ) {
		$latest = self::latest( $repo );
		return $latest ? $latest['tag'] : '';
	}

	/**
	 * Drop cached releases for a repo (after install, so versions refresh).
	 *
	 * @param string $repo
	 * @return void
	 */
	public static function flush( $repo ) {
		delete_transient( 'nera_aio_rel_' . md5( (string) $repo ) );
	}

	/**
	 * Download + install (or update) a catalog entry's plugin from GitHub, then
	 * activate it.
	 *
	 * @param array       $entry Catalog entry (needs gh_repo, slug, main_file).
	 * @param string|null $ref   Specific release tag, or null for latest.
	 * @return true|\WP_Error
	 */
	public static function install( array $entry, $ref = null ) {
		$repo = isset( $entry['gh_repo'] ) ? (string) $entry['gh_repo'] : '';
		$slug = isset( $entry['slug'] ) ? (string) $entry['slug'] : '';
		if ( '' === $repo || '' === $slug ) {
			return new \WP_Error( 'nera_aio_no_source', __( 'This plugin has no GitHub source.', 'nera-all-in-one' ) );
		}

		$zip = self::resolve_zip( $repo, $ref );
		if ( '' === $zip ) {
			return new \WP_Error( 'nera_aio_no_release', __( 'Could not find a release to install.', 'nera-all-in-one' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		// Force the unpacked folder to the canonical slug.
		$rename = self::source_selection_renamer( $slug );
		add_filter( 'upgrader_source_selection', $rename, 10, 4 );

		$upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
		$result   = $upgrader->install( $zip, array( 'overwrite_package' => true ) );

		remove_filter( 'upgrader_source_selection', $rename, 10 );
		self::flush( $repo );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( true !== $result ) {
			return new \WP_Error( 'nera_aio_install_failed', __( 'Install failed.', 'nera-all-in-one' ) );
		}

		// Activate the freshly installed plugin. Activation runs the plugin's
		// main file, which can fatal on a broken/incompatible plugin — catch it
		// so a successful install is never turned into a white screen. The card
		// then shows "Installed" (inactive) and the user can toggle it on.
		$plugin_file = $slug . '/' . ( isset( $entry['main_file'] ) ? $entry['main_file'] : $slug . '.php' );
		try {
			$activate = activate_plugin( $plugin_file );
			if ( is_wp_error( $activate ) ) {
				Logger::log( 'installed but activation failed: ' . $plugin_file . ' — ' . $activate->get_error_message() );
			}
		} catch ( \Throwable $e ) {
			Logger::log( 'installed but activation fatal: ' . $plugin_file . ' — ' . $e->getMessage() );
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Resolve the zip URL for a repo at an optional tag (latest when null).
	 *
	 * @param string      $repo
	 * @param string|null $ref
	 * @return string
	 */
	private static function resolve_zip( $repo, $ref ) {
		$rels = self::releases( $repo );
		if ( ! $rels ) {
			return '';
		}
		if ( null === $ref || '' === $ref ) {
			return $rels[0]['zip'];
		}
		foreach ( $rels as $rel ) {
			if ( $rel['tag'] === $ref ) {
				return $rel['zip'];
			}
		}
		return '';
	}

	/**
	 * Choose the downloadable zip for a release: the first uploaded .zip asset,
	 * else the source zipball.
	 *
	 * @param array $rel Release object from the API.
	 * @return string URL or ''.
	 */
	private static function pick_asset( array $rel ) {
		if ( ! empty( $rel['assets'] ) && is_array( $rel['assets'] ) ) {
			foreach ( $rel['assets'] as $asset ) {
				$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
				$type = isset( $asset['content_type'] ) ? (string) $asset['content_type'] : '';
				if ( 'application/zip' === $type || preg_match( '/\.zip$/i', $name ) ) {
					return isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : '';
				}
			}
		}
		// Fallback: source zipball (folder gets renamed by source_selection).
		return isset( $rel['zipball_url'] ) ? (string) $rel['zipball_url'] : '';
	}

	/**
	 * Build an upgrader_source_selection callback that renames the unpacked
	 * directory to $slug so the plugin always lands in wp-content/plugins/$slug.
	 *
	 * @param string $slug
	 * @return callable
	 */
	private static function source_selection_renamer( $slug ) {
		return static function ( $source, $remote_source, $upgrader, $hook_extra = array() ) use ( $slug ) {
			global $wp_filesystem;
			if ( ! $wp_filesystem ) {
				return $source;
			}
			$desired = trailingslashit( $remote_source ) . $slug;
			if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
				return $source;
			}
			if ( $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $desired ), true ) ) {
				return trailingslashit( $desired );
			}
			return $source;
		};
	}
}
