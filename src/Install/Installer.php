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

use Nera\AllInOne\Admin\Plugin_Catalog;
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
	 * When the plugin is already installed and no update is needed, skips the
	 * zip download and only activates (or no-ops if already active).
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

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		// Already on disk and current — activate only (or no-op). Avoids
		// Plugin_Upgrader failures when Overview/Manage Install is clicked
		// against a plugin that was installed outside AIO or on a stale page.
		Plugin_Catalog::refresh_plugins_cache();
		$existing = Plugin_Catalog::resolve_plugin_file( $entry );
		$on_disk  = '' !== $existing || is_dir( WP_PLUGIN_DIR . '/' . $slug );
		if ( $on_disk && ! self::needs_update( $entry, $repo, $ref ) ) {
			$plugin_file = '' !== $existing ? $existing : self::expected_plugin_file( $entry, $slug );
			if ( ! is_readable( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
				return new \WP_Error(
					'nera_aio_missing_main',
					__( 'Plugin folder exists but the main plugin file could not be found.', 'nera-all-in-one' )
				);
			}
			if ( is_plugin_active( $plugin_file ) ) {
				return true;
			}
			return self::try_activate( $plugin_file );
		}

		$zip = self::resolve_zip( $repo, $ref );
		if ( '' === $zip ) {
			if ( $on_disk ) {
				return new \WP_Error(
					'nera_aio_no_release',
					__( 'Plugin is already on disk but no GitHub release was found to update it.', 'nera-all-in-one' )
				);
			}
			return new \WP_Error( 'nera_aio_no_release', __( 'Could not find a release to install.', 'nera-all-in-one' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		// Snapshot prior state: an update must not change the active state,
		// and a fresh install auto-activates (see below).
		$was_installed = $on_disk;
		$was_active    = '' !== $existing && is_plugin_active( $existing );

		// Git-clone installs carry read-only files (git object files are mode
		// 0444) that the upgrader's clear-destination step cannot delete, which
		// aborts the whole update. Make the old folder writable before replacing.
		if ( is_dir( WP_PLUGIN_DIR . '/' . $slug ) ) {
			self::make_writable( WP_PLUGIN_DIR . '/' . $slug );
		}

		// Force the unpacked folder to the canonical slug.
		$rename = self::source_selection_renamer( $slug );
		add_filter( 'upgrader_source_selection', $rename, 10, 4 );

		$skin     = new \Automatic_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $zip, array( 'overwrite_package' => true ) );

		remove_filter( 'upgrader_source_selection', $rename, 10 );
		self::flush( $repo );

		if ( true !== $result ) {
			// Core's Plugin_Upgrader::install() returns null for most failures
			// inside install_package() (its ->result is only set on success);
			// the real WP_Error text only reaches the skin. Surface it.
			$messages = implode( ' — ', array_filter( array_map( 'wp_strip_all_tags', (array) $skin->get_upgrade_messages() ) ) );
			Logger::log( 'install failed for ' . $slug . ': ' . $messages );

			if ( is_wp_error( $result ) && '' !== $result->get_error_message() ) {
				return $result;
			}
			return new \WP_Error(
				'nera_aio_install_failed',
				'' !== $messages ? $messages : __( 'Install failed.', 'nera-all-in-one' )
			);
		}

		wp_clean_plugins_cache( true );
		$plugin_file = Plugin_Catalog::resolve_plugin_file( $entry );
		if ( '' === $plugin_file ) {
			$plugin_file = self::expected_plugin_file( $entry, $slug );
		}

		// Updating an inactive plugin must leave it inactive.
		if ( $was_installed && ! $was_active ) {
			return true;
		}

		// Activate the freshly installed plugin. Activation runs the plugin's
		// main file, which can fatal on a broken/incompatible plugin — catch it
		// so a successful install is never turned into a white screen.
		return self::try_activate( $plugin_file );
	}

	/**
	 * Whether the install request should download a newer (or specific) release.
	 *
	 * @param array       $entry
	 * @param string      $repo
	 * @param string|null $ref
	 * @return bool
	 */
	private static function needs_update( array $entry, $repo, $ref ) {
		if ( null !== $ref && '' !== $ref ) {
			return true;
		}

		$installed = Plugin_Catalog::installed_version( $entry );
		if ( '' === $installed ) {
			return true;
		}

		$latest = self::latest_tag( $repo );
		if ( '' === $latest ) {
			// Can't confirm currency — leave files alone; activate-only path.
			return false;
		}

		return version_compare( ltrim( $latest, 'v' ), ltrim( $installed, 'v' ), '>' );
	}

	/**
	 * Canonical "folder/main.php" from the catalog entry.
	 *
	 * @param array  $entry
	 * @param string $slug
	 * @return string
	 */
	private static function expected_plugin_file( array $entry, $slug ) {
		$main = isset( $entry['main_file'] ) ? (string) $entry['main_file'] : '';
		if ( '' === $main ) {
			$main = $slug . '.php';
		}
		return $slug . '/' . $main;
	}

	/**
	 * Activate a plugin file, surfacing WP_Error / fatals without white-screening.
	 *
	 * @param string $plugin_file Plugin basename relative to plugins dir.
	 * @return true|\WP_Error
	 */
	private static function try_activate( $plugin_file ) {
		try {
			$activate = activate_plugin( $plugin_file );
			if ( is_wp_error( $activate ) ) {
				Logger::log( 'activation failed: ' . $plugin_file . ' — ' . $activate->get_error_message() );
				return $activate;
			}
		} catch ( \Throwable $e ) {
			Logger::log( 'activation fatal: ' . $plugin_file . ' — ' . $e->getMessage() );
			return new \WP_Error(
				'nera_aio_activate_failed',
				/* translators: %s: exception message */
				sprintf( __( 'Plugin installed but could not be activated: %s', 'nera-all-in-one' ), $e->getMessage() )
			);
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
	 * Recursively add owner write permission to every file and directory so a
	 * subsequent delete/overwrite by the upgrader cannot fail on read-only
	 * entries (e.g. .git object files in a development clone).
	 *
	 * @param string $dir Absolute directory path.
	 * @return void
	 */
	private static function make_writable( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $iterator as $item ) {
			if ( ! $item->isWritable() ) {
				@chmod( $item->getPathname(), $item->isDir() ? 0755 : 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
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
