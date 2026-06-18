<?php
/**
 * Age Gate feature module.
 *
 * Wraps the Nera DCMS Age Gate (nera-age-shield-plugin) as a bundled free
 * module inside Nera All-In-One. The legacy class names (Nera_DCMS_*) and text
 * domain ('nera-dcms-age-gate') are intentionally preserved so that existing
 * translations and any external code referencing those symbols continue to work.
 *
 * User-meta keys (_nera_dob_encrypted, _nera_age_verified, _nera_dob_verified_at)
 * are owned by class-storage.php and are NOT touched here — they survive
 * deactivation of either the legacy standalone plugin or this module.
 *
 * @package Nera\AllInOne\Modules\AgeGate
 */

namespace Nera\AllInOne\Modules\AgeGate;

use Nera\AllInOne\Modules\Abstract_Module;
use Nera\AllInOne\Entitlements\Tier;
use Nera\AllInOne\Support\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * Module — Age Verification (free tier).
 *
 * Boots the DCMS Voluntary Code age-gate when WooCommerce is active and the
 * legacy standalone plugin is not also running.
 */
final class Module extends Abstract_Module {

	// -------------------------------------------------------------------------
	// Identity
	// -------------------------------------------------------------------------

	/**
	 * {@inheritdoc}
	 *
	 * @return string
	 */
	public function id() {
		return 'age-gate';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return string
	 */
	public function label() {
		return 'Age Verification';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return string
	 */
	public function min_tier() {
		return Tier::FREE;
	}

	// -------------------------------------------------------------------------
	// Dependencies & conflict detection
	// -------------------------------------------------------------------------

	/**
	 * {@inheritdoc}
	 *
	 * @return string[]
	 */
	public function deps() {
		return array( 'woocommerce' );
	}

	/**
	 * Returns true when the legacy standalone plugin is still active, preventing
	 * the module from double-loading the same classes.
	 *
	 * {@inheritdoc}
	 *
	 * @return bool
	 */
	public function standalone_conflict() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( 'nera-age-shield-plugin/nera-dcms-age-gate.php' );
	}

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	/**
	 * Replicates the nera_dcms_init() bootstrap from the standalone plugin,
	 * redirecting all path/URL constants to this module's location inside
	 * nera-all-in-one/modules/age-gate/.
	 *
	 * Include order matches the original exactly (minus github-updates.php which
	 * is not bundled):
	 *   1. class-age-validator.php
	 *   2. class-storage.php
	 *   3. class-enforcement.php
	 *   4. class-ajax.php
	 *   5. class-frontend.php
	 *   6. class-registration.php
	 *
	 * Init sequence (mirrors nera_dcms_init()):
	 *   Nera_DCMS_Storage::init()
	 *   Nera_DCMS_Frontend::init()
	 *   Nera_DCMS_Ajax::init()
	 *   — WooCommerce guard —
	 *   Nera_DCMS_Registration::init()
	 *   Nera_DCMS_Enforcement::init()
	 *
	 * {@inheritdoc}
	 *
	 * @return void
	 */
	public function boot() {
		// Resolve module paths via Assets helper so constants point at the
		// bundled copies, not the old standalone-plugin location.
		$dir = Assets::module_dir( 'age-gate' );   // …/modules/age-gate/ (trailing slash)
		$url = Assets::module_url( 'age-gate', '' ); // …/modules/age-gate/

		if ( ! defined( 'NERA_DCMS_VERSION' ) ) {
			define( 'NERA_DCMS_VERSION', '1.1.8' );
		}
		if ( ! defined( 'NERA_DCMS_PLUGIN_DIR' ) ) {
			define( 'NERA_DCMS_PLUGIN_DIR', $dir );
		}
		if ( ! defined( 'NERA_DCMS_PLUGIN_URL' ) ) {
			define( 'NERA_DCMS_PLUGIN_URL', $url );
		}
		if ( ! defined( 'NERA_DCMS_PLUGIN_FILE' ) ) {
			define( 'NERA_DCMS_PLUGIN_FILE', $dir . 'age-gate.php' );
		}
		if ( ! defined( 'NERA_DCMS_MIN_AGE' ) ) {
			define( 'NERA_DCMS_MIN_AGE', 18 );
		}

		// Load legacy classes in the same order as the standalone plugin.
		require_once $dir . 'includes/class-age-validator.php';
		require_once $dir . 'includes/class-storage.php';
		require_once $dir . 'includes/class-enforcement.php';
		require_once $dir . 'includes/class-ajax.php';
		require_once $dir . 'includes/class-frontend.php';
		require_once $dir . 'includes/class-registration.php';

		// Translations — keep the original text domain so .po/.mo files resolve.
		load_plugin_textdomain( 'nera-dcms-age-gate', false, 'nera-all-in-one/modules/age-gate/languages' );

		// Init sequence — mirrors nera_dcms_init() exactly.
		\Nera_DCMS_Storage::init();
		\Nera_DCMS_Frontend::init();
		\Nera_DCMS_Ajax::init();

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		\Nera_DCMS_Registration::init();
		\Nera_DCMS_Enforcement::init();
	}

	// -------------------------------------------------------------------------
	// Lifecycle — no-op (age-gate owns no tables; user meta is preserved)
	// -------------------------------------------------------------------------
	// activate(), deactivate(), uninstall() intentionally inherit Abstract_Module
	// no-op defaults. Do NOT override them.
}
