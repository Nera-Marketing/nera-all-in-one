<?php
/**
 * Add-plugin-by-GitHub-URL AJAX controller.
 *
 * Backs the Manage page's "Add & Install" input: validates a pasted GitHub
 * repo URL against the owner allowlist, checks it publishes release zips,
 * stores it as a custom catalog entry (Custom_Catalog) and installs it in the
 * same request. Also handles delisting a custom entry (catalog only — never
 * touches files).
 *
 * @package Nera\AllInOne\Admin
 */

namespace Nera\AllInOne\Admin;

use Nera\AllInOne\Catalog\Custom_Catalog;
use Nera\AllInOne\Catalog\Hidden_Plugins;
use Nera\AllInOne\Catalog\Manifest;
use Nera\AllInOne\Entitlements\Entitlements;
use Nera\AllInOne\Install\Installer;
use Nera\AllInOne\License\License_Client;
use Nera\AllInOne\Modules\Module_Registry;
use Nera\AllInOne\Support\Managers;

defined( 'ABSPATH' ) || exit;

/**
 * Repo_Add_Controller — wp_ajax_nera_aio_repo_add / _repo_delist.
 */
final class Repo_Add_Controller {

	/**
	 * Default allowed repo owner (GitHub org).
	 */
	const DEFAULT_OWNER = 'Nera-Marketing';

	/**
	 * @var License_Client
	 */
	private $license;

	/**
	 * @var Entitlements
	 */
	private $entitlements;

	/**
	 * @var Module_Registry
	 */
	private $registry;

	/**
	 * @param License_Client  $license
	 * @param Entitlements    $entitlements
	 * @param Module_Registry $registry
	 */
	public function __construct( License_Client $license, Entitlements $entitlements, Module_Registry $registry ) {
		$this->license      = $license;
		$this->entitlements = $entitlements;
		$this->registry     = $registry;
	}

	/**
	 * Register WP hooks. Called by Plugin::boot_admin().
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_ajax_nera_aio_repo_add', array( $this, 'handle_add' ) );
		add_action( 'wp_ajax_nera_aio_repo_delist', array( $this, 'handle_delist' ) );
	}

	/**
	 * Handle wp_ajax_nera_aio_repo_add.
	 *
	 * @return void
	 */
	public function handle_add() {
		check_ajax_referer( 'nera_aio_repo_add' );
		$this->assert_manager( 'install_plugins' );

		$url  = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';
		$repo = self::parse_repo( $url );
		if ( null === $repo ) {
			wp_send_json_error( array( 'message' => __( 'Enter a GitHub repository URL like https://github.com/Nera-Marketing/plugin-name.', 'nera-all-in-one' ) ), 400 );
		}

		list( $owner, $name ) = $repo;

		if ( ! in_array( strtolower( $owner ), self::allowed_owners(), true ) ) {
			wp_send_json_error(
				array(
					/* translators: %s: GitHub org/user name */
					'message' => sprintf( __( 'Repos from "%s" are not allowed on this site.', 'nera-all-in-one' ), $owner ),
				),
				403
			);
		}

		$gh_repo = $owner . '/' . $name;
		$id      = sanitize_key( $name );

		// Duplicate guard — scans the UNFILTERED manifest (Manifest::all()
		// includes hidden built-ins and custom entries) so a hidden entry can
		// be restored instead of duplicated.
		foreach ( Manifest::all() as $entry ) {
			if ( $entry['id'] !== $id && $entry['slug'] !== $name && $entry['gh_repo'] !== $gh_repo ) {
				continue;
			}
			if ( ! Hidden_Plugins::is_hidden( $entry['id'] ) ) {
				wp_send_json_error( array( 'message' => __( 'This plugin is already in the catalog.', 'nera-all-in-one' ) ), 400 );
			}

			// Previously removed from the list — restore it.
			Hidden_Plugins::unhide( $entry['id'] );
			$restored = Plugin_Catalog::find( $entry['id'] );
			if ( $restored && ! Plugin_Catalog::installed( $restored ) && ! empty( $restored['gh_repo'] ) ) {
				$result = Installer::install( $restored );
				if ( is_wp_error( $result ) ) {
					wp_send_json_error(
						array(
							/* translators: %s: installer error message */
							'message' => sprintf( __( 'Restored to the catalog, but the install failed: %s', 'nera-all-in-one' ), $result->get_error_message() ),
						),
						500
					);
				}
			}
			wp_send_json_success( array( 'id' => $entry['id'], 'restored' => true ) );
		}

		// Must publish an installable release.
		if ( ! Installer::releases( $gh_repo ) ) {
			wp_send_json_error( array( 'message' => __( 'No published release with a zip asset found for this repo (private repos are not supported).', 'nera-all-in-one' ) ), 400 );
		}

		$entry = array(
			'id'        => $id,
			'name'      => self::humanize( $name ),
			'tagline'   => self::repo_description( $gh_repo ),
			'icon'      => '🔌',
			'accent'    => '#64748b',
			'min_tier'  => 'free',
			'slug'      => $name,
			'main_file' => '',
			'gh_repo'   => $gh_repo,
			'deps'      => array(),
			'custom'    => true,
		);

		Custom_Catalog::add( $entry );

		$result = Installer::install( $entry );
		if ( is_wp_error( $result ) ) {
			// Keep the catalog entry — the row renders an Install button to retry.
			wp_send_json_error(
				array(
					/* translators: %s: installer error message */
					'message' => sprintf( __( 'Added to the catalog, but the install failed: %s', 'nera-all-in-one' ), $result->get_error_message() ),
				),
				500
			);
		}

		// Persist the real main file so toggles/activation work reliably.
		$plugin_file = Plugin_Catalog::legacy_plugin_file( $name );
		if ( '' !== $plugin_file ) {
			Custom_Catalog::update( $id, array( 'main_file' => basename( $plugin_file ) ) );
		}

		wp_send_json_success( array( 'id' => $id ) );
	}

	/**
	 * Handle wp_ajax_nera_aio_repo_delist — remove an entry from the catalog.
	 * Custom entries are deleted; built-in entries are hidden per site (paste
	 * the repo URL to restore). Never deletes plugin files; refuses while
	 * installed.
	 *
	 * @return void
	 */
	public function handle_delist() {
		check_ajax_referer( 'nera_aio_repo_delist' );
		$this->assert_manager( 'manage_options' );

		$id    = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$entry = Plugin_Catalog::find( $id );

		if ( null === $entry ) {
			wp_send_json_error( array( 'message' => __( 'Unknown plugin.', 'nera-all-in-one' ) ), 404 );
		}

		if ( Plugin_Catalog::installed( $entry ) ) {
			wp_send_json_error( array( 'message' => __( 'Uninstall the plugin first, then remove it from the catalog.', 'nera-all-in-one' ) ), 400 );
		}

		if ( ! empty( $entry['custom'] ) ) {
			Custom_Catalog::remove( $id );
		} else {
			Hidden_Plugins::hide( $id );
		}
		wp_send_json_success( array( 'id' => $id ) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Bail with a JSON error unless the current user is a manager with $cap.
	 *
	 * @param string $cap Required capability.
	 * @return void
	 */
	private function assert_manager( $cap ) {
		if ( ! current_user_can( $cap ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'nera-all-in-one' ) ), 403 );
		}
		if ( ! Managers::is_manager() ) {
			wp_send_json_error( array( 'message' => __( 'Only the Nera service account can manage plugins.', 'nera-all-in-one' ) ), 403 );
		}
	}

	/**
	 * Parse a GitHub URL (or bare "owner/repo") into [ owner, repo ], or null.
	 *
	 * @param string $url
	 * @return array{0:string,1:string}|null
	 */
	public static function parse_repo( $url ) {
		$url = trim( $url );
		if ( '' === $url ) {
			return null;
		}

		// Full URL → path component; bare owner/repo used as-is.
		if ( false !== strpos( $url, '://' ) || 0 === strpos( $url, 'github.com' ) ) {
			$host = wp_parse_url( false !== strpos( $url, '://' ) ? $url : 'https://' . $url, PHP_URL_HOST );
			if ( 'github.com' !== strtolower( (string) $host ) ) {
				return null;
			}
			$path = (string) wp_parse_url( false !== strpos( $url, '://' ) ? $url : 'https://' . $url, PHP_URL_PATH );
		} else {
			$path = $url;
		}

		$parts = array_values( array_filter( explode( '/', trim( $path, '/' ) ) ) );
		if ( count( $parts ) < 2 ) {
			return null;
		}

		$owner = $parts[0];
		$name  = preg_replace( '/\.git$/', '', $parts[1] );

		if ( ! preg_match( '/^[A-Za-z0-9_.-]+$/', $owner ) || ! preg_match( '/^[A-Za-z0-9_.-]+$/', $name ) ) {
			return null;
		}

		return array( $owner, $name );
	}

	/**
	 * Lower-cased allowed repo owners. NERA_AIO_REPO_OWNERS (comma-separated)
	 * overrides the default org.
	 *
	 * @return string[]
	 */
	private static function allowed_owners() {
		$raw = defined( 'NERA_AIO_REPO_OWNERS' ) ? (string) NERA_AIO_REPO_OWNERS : self::DEFAULT_OWNER;
		return array_values( array_filter( array_map( 'strtolower', array_map( 'trim', explode( ',', $raw ) ) ) ) );
	}

	/**
	 * Display name from a repo slug: "nera-foo-bar-plugin" → "Foo Bar".
	 *
	 * @param string $name Repo name.
	 * @return string
	 */
	public static function humanize( $name ) {
		$label = preg_replace( '/^nera[-_]/i', '', $name );
		$label = preg_replace( '/[-_]plugin$/i', '', $label );
		return ucwords( str_replace( array( '-', '_' ), ' ', $label ) );
	}

	/**
	 * The repo's GitHub description, or '' (failure is non-fatal).
	 *
	 * @param string $repo "owner/name".
	 * @return string
	 */
	private static function repo_description( $repo ) {
		$res = wp_remote_get(
			'https://api.github.com/repos/' . $repo,
			array(
				'timeout' => 8,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'nera-all-in-one',
				),
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return '';
		}
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		return isset( $json['description'] ) && is_string( $json['description'] ) ? sanitize_text_field( $json['description'] ) : '';
	}
}
