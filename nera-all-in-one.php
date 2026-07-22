<?php
/**
 * Plugin Name:       Nera All In One
 * Plugin URI:        https://github.com/Nera-Marketing/nera-all-in-one
 * Description:        Unified manager for Nera internal plugins + licensing. Loads feature modules gated by license tier.
 * Version: 1.0.1
 * Author:            Nera
 * Text Domain:       nera-all-in-one
 * Requires at least: 6.0
 * Requires PHP:      7.4
 *
 * @package Nera\AllInOne
 */

defined( 'ABSPATH' ) || exit;

use YahnisElsts\PluginUpdateChecker\v5p5\Vcs\GitHubApi;

// --- Constants ---------------------------------------------------------------
define( 'NERA_AIO_VERSION', '1.0.1' );
define( 'NERA_AIO_FILE', __FILE__ );
define( 'NERA_AIO_DIR', plugin_dir_path( __FILE__ ) );
define( 'NERA_AIO_URL', plugin_dir_url( __FILE__ ) );

// Default tier when no license / no last-known-good is available. Defaults to
// the full tier: all sites are Nera-built, and mutating anything in AIO is
// gated by manager identity (Support\Managers / Support\Guard), not by tier.
// The signed-key system stays intact (dormant) for future external licensing.
if ( ! defined( 'NERA_AIO_DEFAULT_TIER' ) ) {
	define( 'NERA_AIO_DEFAULT_TIER', 'agency' );
}

// MVP demo key. Entering this key unlocks PRO — but ONLY in demo mode.
if ( ! defined( 'NERA_AIO_DEMO_KEY' ) ) {
	define( 'NERA_AIO_DEMO_KEY', 'NERA-DEMO-PRO-2026' );
}

// Demo mode is a hard safety rail: the demo key is rejected on production.
if ( ! defined( 'NERA_AIO_DEMO_MODE' ) ) {
	define(
		'NERA_AIO_DEMO_MODE',
		function_exists( 'wp_get_environment_type' ) ? ( 'production' !== wp_get_environment_type() ) : true
	);
}

// Central plugin manifest (catalog source of truth). Empty = use the bundled
// data/plugins.json fallback. Override in wp-config.php once hosted, e.g. a raw
// GitHub URL to nera-plugins.json.
if ( ! defined( 'NERA_AIO_MANIFEST_URL' ) ) {
	define( 'NERA_AIO_MANIFEST_URL', '' );
}

// Ed25519 public key (base64) used to verify signed license keys offline. Nera
// signs keys with the matching private key kept off-site. Empty = signed-key
// verification disabled (only the demo key grants a paid tier).
if ( ! defined( 'NERA_AIO_LICENSE_PUBKEY' ) ) {
	define( 'NERA_AIO_LICENSE_PUBKEY', '2wskav2ZBvMZk4bm7JF7dnHSBO9GuwXfms3OF6idmxY=' );
}

require_once NERA_AIO_DIR . 'inc/upgrade-temp-backup-helper.php';

/**
 * GitHub updates (Plugin Update Checker v5.5). On by default when `lib/plugin-update-checker/load-v5p5.php` exists.
 *
 * Disable only if the repo is missing or you are developing without GitHub:
 *   define( 'NERA_AIO_DISABLE_GITHUB_UPDATES', true );
 *
 * Private repo: `define( 'NERA_AIO_GITHUB_TOKEN', 'ghp_...' );`
 * Custom URL: `define( 'NERA_AIO_GITHUB_REPO_URL', 'https://github.com/Owner/repo/' );` or filter `nera_aio_github_repo_url`.
 *
 * PUC reads the `Version` header in this file from the GitHub ref it selects (not the tag name alone). If the
 * remote header is older than the release, WordPress can show "up to date". Bump `Version` and `NERA_AIO_VERSION`
 * for every release, then tag/push to match.
 *
 * GitHub `GET /repos/.../releases/latest` returns 404 when there is no GitHub "latest" stable release. A custom
 * `setReleaseFilter` callback (always true) plus `maxReleases` > 1 makes `GitHubApi` use `GET .../releases?per_page=...`
 * instead of `/latest`. Releases must not be drafts; GitHub "pre-release" flags are skipped via `RELEASE_FILTER_SKIP_PRERELEASE`.
 *
 * The 4th argument to `PucFactory::buildUpdateChecker` is the check interval in hours (here: 6), not a release count.
 *
 * PUC loads the remote `readme.txt` only when a `readme.txt` is already present in the installed plugin (see
 * `readmeTxtExistsLocally()` in the library). Keep `readme.txt` in the package and bump `Stable tag` on each release
 * (`release.sh` syncs it with the release version). `plugin.json` is optional metadata for a *self-hosted* JSON
 * update URL; it is not used when the metadata URL is the GitHub repository (default).
 *
 * @link https://github.com/YahnisElsts/plugin-update-checker
 * @link https://github.com/Nera-Marketing/nera-all-in-one/
 */
if ( ! defined( 'NERA_AIO_DISABLE_GITHUB_UPDATES' ) || ! NERA_AIO_DISABLE_GITHUB_UPDATES ) {
	$nera_aio_github_repo_default = 'https://github.com/Nera-Marketing/nera-all-in-one/';
	if ( defined( 'NERA_AIO_GITHUB_REPO_URL' ) && is_string( NERA_AIO_GITHUB_REPO_URL ) && NERA_AIO_GITHUB_REPO_URL !== '' ) {
		$nera_aio_github_repo_default = NERA_AIO_GITHUB_REPO_URL;
	}
	$nera_aio_github_repo = apply_filters( 'nera_aio_github_repo_url', $nera_aio_github_repo_default );

	$nera_aio_puc_loader = NERA_AIO_DIR . 'lib/plugin-update-checker/load-v5p5.php';
	if ( is_readable( $nera_aio_puc_loader ) ) {
		require_once $nera_aio_puc_loader;
		// Fourth argument: check period in hours (PUC default is 12).
		$nera_aio_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			$nera_aio_github_repo,
			__FILE__,
			'nera-all-in-one',
			6
		);
		$nera_aio_update_checker->setBranch( 'main' );

		if ( defined( 'NERA_AIO_GITHUB_TOKEN' ) && is_string( NERA_AIO_GITHUB_TOKEN ) && NERA_AIO_GITHUB_TOKEN !== '' ) {
			$nera_aio_update_checker->setAuthentication( NERA_AIO_GITHUB_TOKEN );
		}

		$nera_aio_puc_vcs = $nera_aio_update_checker->getVcsApi();
		if ( $nera_aio_puc_vcs instanceof GitHubApi ) {
			// Force paginated /releases (see docblock): custom filter + maxReleases > 1.
			$nera_aio_puc_vcs->setReleaseFilter(
				static function ( $version_number, $release_object ) {
					unset( $version_number, $release_object );
					return true;
				},
				\YahnisElsts\PluginUpdateChecker\v5p5\Vcs\Api::RELEASE_FILTER_SKIP_PRERELEASE,
				20
			);
			$nera_aio_puc_vcs->enableReleaseAssets();
		}
	}
}

/**
 * Core-style update notice on admin screens other than Plugins (there, WordPress prints the yellow inline row).
 *
 * @return void
 */
function nera_aio_admin_notice_plugin_update() {
	if ( ! is_admin() || ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	global $pagenow;
	if ( isset( $pagenow ) && 'plugins.php' === $pagenow ) {
		return;
	}

	$file    = plugin_basename( NERA_AIO_FILE );
	$current = get_site_transient( 'update_plugins' );
	if ( ! is_object( $current ) || empty( $current->response[ $file ] ) ) {
		return;
	}

	$response = $current->response[ $file ];
	if ( empty( $response->new_version ) ) {
		return;
	}

	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$plugin_data = get_plugin_data( NERA_AIO_FILE, false, false );
	$plugin_name = wp_strip_all_tags( $plugin_data['Name'] );

	$plugin_slug = isset( $response->slug ) ? $response->slug : '';
	if ( $plugin_slug ) {
		$details_url = self_admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . rawurlencode( $plugin_slug ) . '&section=changelog' );
	} elseif ( ! empty( $response->url ) ) {
		$details_url = $response->url;
	} else {
		$details_url = ! empty( $plugin_data['PluginURI'] ) ? $plugin_data['PluginURI'] : '';
	}

	if ( $details_url ) {
		$details_url = add_query_arg(
			array(
				'TB_iframe' => 'true',
				'width'     => 600,
				'height'    => 800,
			),
			$details_url
		);
	}

	$new_version = $response->new_version;
	$update_url  = wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $file ) ), 'upgrade-plugin_' . $file );

	if ( ! empty( $response->package ) ) {
		$message = sprintf(
			/* translators: 1: Plugin name, 2: details URL, 3: link attributes, 4: new version, 5: update URL, 6: link attributes */
			__( 'There is a new version of %1$s available. <a href="%2$s" %3$s>View version %4$s details</a> or <a href="%5$s" %6$s>update now</a>.', 'nera-all-in-one' ),
			'<strong>' . esc_html( $plugin_name ) . '</strong>',
			esc_url( $details_url ),
			'class="thickbox open-plugin-details-modal"',
			esc_html( $new_version ),
			esc_url( $update_url ),
			'class="update-link"'
		);
	} else {
		$message = sprintf(
			/* translators: 1: Plugin name, 2: details URL, 3: link attributes, 4: new version */
			__( 'There is a new version of %1$s available. <a href="%2$s" %3$s>View version %4$s details</a>. <em>Automatic update is unavailable for this plugin.</em>', 'nera-all-in-one' ),
			'<strong>' . esc_html( $plugin_name ) . '</strong>',
			esc_url( $details_url ),
			'class="thickbox open-plugin-details-modal"',
			esc_html( $new_version )
		);
	}

	wp_admin_notice(
		wp_kses(
			$message,
			array(
				'strong' => array(),
				'em'     => array(),
				'a'      => array(
					'href'  => array(),
					'class' => array(),
				),
			)
		),
		array(
			'type'               => 'warning',
			'additional_classes' => array( 'update-nag', 'inline' ),
			'paragraph_wrap'     => true,
		)
	);
}
add_action( 'admin_notices', 'nera_aio_admin_notice_plugin_update', 12 );

// --- Autoloading -------------------------------------------------------------
// Composer is optional; a lightweight PSR-4 fallback keeps the plugin runnable
// without a build step (locked decision: no build step for MVP).
$nera_aio_composer = NERA_AIO_DIR . 'vendor/autoload.php';
if ( is_readable( $nera_aio_composer ) ) {
	require $nera_aio_composer;
}
unset( $nera_aio_composer );

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'Nera\\AllInOne\\';
		$len    = strlen( $prefix );
		if ( 0 !== strncmp( $class, $prefix, $len ) ) {
			return;
		}
		$relative = substr( $class, $len );
		$path     = NERA_AIO_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

// --- Boot --------------------------------------------------------------------
if ( class_exists( \Nera\AllInOne\Plugin::class ) ) {
	\Nera\AllInOne\Plugin::instance()->boot();
}
