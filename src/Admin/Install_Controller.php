<?php
/**
 * Plugin install/update AJAX controller.
 *
 * Backs the Install / Update / version-switch actions on the card grid. Pulls
 * the chosen plugin from its GitHub release and installs it, gated by license
 * tier + the install_plugins capability.
 *
 * @package Nera\AllInOne\Admin
 */

namespace Nera\AllInOne\Admin;

use Nera\AllInOne\Entitlements\Entitlements;
use Nera\AllInOne\Install\Installer;
use Nera\AllInOne\License\License_Client;
use Nera\AllInOne\Modules\Module_Registry;
use Nera\AllInOne\Support\Managers;

defined( 'ABSPATH' ) || exit;

/**
 * Install_Controller — handles wp_ajax_nera_aio_install.
 *
 * Constructor signature matches the other admin screens so Plugin::boot_admin()
 * can instantiate it from the same loop.
 */
final class Install_Controller {

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
		add_action( 'wp_ajax_nera_aio_install', array( $this, 'handle_install' ) );
	}

	/**
	 * Handle wp_ajax_nera_aio_install.
	 *
	 * @return void
	 */
	public function handle_install() {
		check_ajax_referer( 'nera_aio_install' );

		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions to install plugins.', 'nera-all-in-one' ) ), 403 );
		}

		if ( ! Managers::is_manager() ) {
			wp_send_json_error( array( 'message' => __( 'Only the Nera service account can manage plugins.', 'nera-all-in-one' ) ), 403 );
		}

		$id  = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$ref = isset( $_POST['ref'] ) ? sanitize_text_field( wp_unslash( $_POST['ref'] ) ) : '';

		$entry = Plugin_Catalog::find( $id );
		if ( null === $entry ) {
			wp_send_json_error( array( 'message' => __( 'Unknown plugin.', 'nera-all-in-one' ) ), 404 );
		}

		if ( empty( $entry['gh_repo'] ) ) {
			wp_send_json_error( array( 'message' => __( 'This plugin is not available yet.', 'nera-all-in-one' ) ), 400 );
		}

		// Wrapped modules ship inside AIO and are managed by their toggle, not
		// installed as a standalone plugin.
		if ( 'module' === Plugin_Catalog::backing( $entry ) ) {
			wp_send_json_error( array( 'message' => __( 'This feature is built into Nera All In One — use its toggle.', 'nera-all-in-one' ) ), 400 );
		}

		// Installing/updating a tier-gated plugin requires entitlement.
		if ( ! $this->entitlements->is_entitled( $entry['min_tier'] ) ) {
			wp_send_json_error( array( 'message' => __( 'A higher license tier is required. Contact Nera for a key.', 'nera-all-in-one' ) ), 403 );
		}

		$result = Installer::install( $entry, '' !== $ref ? $ref : null );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		wp_send_json_success(
			array(
				'id'      => $id,
				'version' => Plugin_Catalog::installed_version( $entry ),
			)
		);
	}
}
