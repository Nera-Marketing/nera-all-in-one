<?php
/**
 * Module/plugin toggle AJAX controller.
 *
 * Backs the on/off switches rendered on the Overview and Modules card grids.
 * One AJAX action (nera_aio_toggle) dispatches per card "backing":
 *   - plugin: activate/deactivate the real standalone WP plugin.
 *   - module: write a site-level on/off override (Module_Overrides).
 *
 * @package Nera\AllInOne\Admin
 */

namespace Nera\AllInOne\Admin;

use Nera\AllInOne\Entitlements\Entitlements;
use Nera\AllInOne\License\License_Client;
use Nera\AllInOne\Modules\Module_Overrides;
use Nera\AllInOne\Modules\Module_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Module_Toggle — handles the per-card enable/disable AJAX request.
 *
 * Constructor signature matches the other admin screens so Plugin::boot_admin()
 * can instantiate it from the same loop.
 */
final class Module_Toggle {

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
		add_action( 'wp_ajax_nera_aio_toggle', array( $this, 'handle_toggle' ) );
	}

	/**
	 * Handle wp_ajax_nera_aio_toggle.
	 *
	 * @return void
	 */
	public function handle_toggle() {
		check_ajax_referer( 'nera_aio_toggle' );

		$id      = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$enabled = ! empty( $_POST['enabled'] );

		$entry = Plugin_Catalog::find( $id );
		if ( null === $entry ) {
			wp_send_json_error( array( 'message' => __( 'Unknown module.', 'nera-all-in-one' ) ), 404 );
		}

		// Enabling requires entitlement; disabling is always permitted.
		if ( $enabled && ! $this->entitlements->is_entitled( $entry['min_tier'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Upgrade required to enable this module.', 'nera-all-in-one' ) ), 403 );
		}

		switch ( Plugin_Catalog::backing( $entry ) ) {
			case 'plugin':
				$this->toggle_plugin( $entry, $enabled );
				break;

			case 'module':
				$this->toggle_module( $entry, $enabled );
				break;

			default:
				wp_send_json_error( array( 'message' => __( 'Nothing to toggle — not installed.', 'nera-all-in-one' ) ), 400 );
		}

		wp_send_json_success(
			array(
				'id'      => $id,
				'enabled' => $enabled,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Backing handlers
	// -------------------------------------------------------------------------

	/**
	 * Activate or deactivate the real standalone plugin behind a catalog entry.
	 *
	 * @param array $entry
	 * @param bool  $enabled
	 * @return void Sends a JSON error response and exits on failure.
	 */
	private function toggle_plugin( array $entry, $enabled ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions to manage plugins.', 'nera-all-in-one' ) ), 403 );
		}

		$file = Plugin_Catalog::legacy_plugin_file( $entry['legacy_folder'] );
		if ( '' === $file ) {
			wp_send_json_error( array( 'message' => __( 'Plugin not installed.', 'nera-all-in-one' ) ), 404 );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		if ( $enabled ) {
			$result = activate_plugin( $file );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
			}
		} else {
			deactivate_plugins( $file );
		}
	}

	/**
	 * Set the site-level on/off override for an in-process wrapped module.
	 *
	 * On disable we also run the module's deactivate() and clear its first-boot
	 * flag so a later re-enable re-runs activate() cleanly. Hooks already
	 * registered earlier this request can't be unbound live; the override takes
	 * effect on the next page load (the admin JS reloads after a successful
	 * toggle to make this seamless).
	 *
	 * @param array $entry
	 * @param bool  $enabled
	 * @return void
	 */
	private function toggle_module( array $entry, $enabled ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'nera-all-in-one' ) ), 403 );
		}

		$module_id = $entry['module_id'];
		Module_Overrides::set( $module_id, $enabled ? 'on' : 'off' );

		if ( ! $enabled ) {
			$module = $this->registry->get( $module_id );
			if ( $module ) {
				$module->deactivate();
			}
			delete_option( 'nera_aio_module_activated_' . $module_id );
		}
	}
}
