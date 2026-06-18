<?php
/**
 * Admin Modules screen.
 *
 * Lists all Nera plugins as a card grid (shared with the Overview screen via
 * Plugin_Catalog) with category accents, tier badges, live status, and an
 * Upgrade action on locked cards.
 *
 * @package Nera\AllInOne\Admin
 */

namespace Nera\AllInOne\Admin;

use Nera\AllInOne\Entitlements\Entitlements;
use Nera\AllInOne\License\License_Client;
use Nera\AllInOne\Modules\Module_Registry;
use Nera\AllInOne\Support\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * Modules_Screen — plugin card grid + upgrade modal.
 */
final class Modules_Screen {

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
		add_action( 'admin_menu', array( $this, 'register_submenu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Add the "Modules" submenu under the parent Nera All In One menu.
	 *
	 * @return void
	 */
	public function register_submenu() {
		add_submenu_page(
			'nera-all-in-one',
			__( 'Nera All In One — Modules', 'nera-all-in-one' ),
			__( 'Modules', 'nera-all-in-one' ),
			'manage_options',
			'nera-aio-modules',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin CSS + JS only on our own pages.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue( $hook_suffix ) {
		if ( ! $this->is_our_page( $hook_suffix ) ) {
			return;
		}

		wp_enqueue_style( 'nera-aio-admin', Assets::admin_url( 'admin.css' ), array(), NERA_AIO_VERSION );
		wp_enqueue_script( 'nera-aio-admin', Assets::admin_url( 'admin.js' ), array(), NERA_AIO_VERSION, true );

		wp_localize_script(
			'nera-aio-admin',
			'neraAioAdmin',
			array(
				'ajaxurl'      => admin_url( 'admin-ajax.php' ),
				'checkNonce'   => wp_create_nonce( 'nera_aio_check_now' ),
				'toggleNonce'  => wp_create_nonce( 'nera_aio_toggle' ),
				'installNonce' => wp_create_nonce( 'nera_aio_install' ),
				'demoKey'      => $this->license->is_demo_mode() ? $this->license->get_demo_key() : '',
				'demoMode'     => $this->license->is_demo_mode(),
			)
		);
	}

	/**
	 * Render the Modules page (shared card grid).
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nera-all-in-one' ) );
		}
		?>
		<div class="wrap nera-aio-wrap">
			<div class="nera-aio-app">

				<div class="nera-aio-hero nera-aio-hero--compact">
					<div class="nera-aio-hero__main">
						<h1 class="nera-aio-hero__title"><?php echo esc_html__( 'Modules', 'nera-all-in-one' ); ?></h1>
						<p class="nera-aio-hero__sub"><?php echo esc_html__( 'All Nera plugins and their current status on this site.', 'nera-all-in-one' ); ?></p>
					</div>
				</div>

				<?php Plugin_Catalog::render_grid( $this->entitlements, $this->registry ); ?>

			</div>
		</div>
		<?php
		Plugin_Catalog::render_upgrade_modal( $this->license );
	}

	/**
	 * Whether $hook_suffix belongs to one of our admin pages.
	 *
	 * @param string $hook_suffix
	 * @return bool
	 */
	private function is_our_page( $hook_suffix ) {
		return (
			false !== strpos( $hook_suffix, 'nera-all-in-one' ) ||
			false !== strpos( $hook_suffix, 'nera-aio-' )
		);
	}
}
