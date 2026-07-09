<?php
/**
 * Plugin Name:       Nera All In One
 * Plugin URI:        https://github.com/Nera-Marketing/nera-all-in-one
 * Description:        Unified manager for Nera internal plugins + licensing. Loads feature modules gated by license tier.
 * Version:           0.1.0
 * Author:            Nera
 * Text Domain:       nera-all-in-one
 * Requires at least: 6.0
 * Requires PHP:      7.4
 *
 * @package Nera\AllInOne
 */

defined( 'ABSPATH' ) || exit;

// --- Constants ---------------------------------------------------------------
define( 'NERA_AIO_VERSION', '0.1.0' );
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
