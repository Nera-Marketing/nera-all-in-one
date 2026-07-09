<?php
/**
 * Admin dashboard overview screen.
 *
 * Registers the top-level "Nera All In One" admin menu entry and renders the
 * Overview page: plugin status counts and the shared plugin card grid.
 *
 * @package Nera\AllInOne\Admin
 */

namespace Nera\AllInOne\Admin;

use Nera\AllInOne\Entitlements\Entitlements;
use Nera\AllInOne\License\License_Client;
use Nera\AllInOne\Modules\Module_Registry;
use Nera\AllInOne\Support\Assets;
use Nera\AllInOne\Support\Managers;

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard — top-level menu + overview page.
 */
final class Dashboard {

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

	// -------------------------------------------------------------------------
	// Init
	// -------------------------------------------------------------------------

	/**
	 * Register WP hooks. Called by Plugin::boot_admin().
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	// -------------------------------------------------------------------------
	// Menu registration
	// -------------------------------------------------------------------------

	/**
	 * Register the top-level menu page and the "Overview" first submenu so the
	 * first item reads "Overview" rather than repeating the parent title.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Nera All In One', 'nera-all-in-one' ),
			__( 'Nera All In One', 'nera-all-in-one' ),
			'manage_options',
			'nera-all-in-one',
			array( $this, 'render_overview' ),
			'dashicons-admin-network',
			58
		);

		// Override the auto-generated first submenu label.
		add_submenu_page(
			'nera-all-in-one',
			__( 'Nera All In One — Overview', 'nera-all-in-one' ),
			__( 'Overview', 'nera-all-in-one' ),
			'manage_options',
			'nera-all-in-one',
			array( $this, 'render_overview' )
		);
	}

	// -------------------------------------------------------------------------
	// Asset enqueue
	// -------------------------------------------------------------------------

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

		wp_enqueue_style(
			'nera-aio-admin',
			Assets::admin_url( 'admin.css' ),
			array(),
			NERA_AIO_VERSION
		);

		wp_enqueue_script(
			'nera-aio-admin',
			Assets::admin_url( 'admin.js' ),
			array(),
			NERA_AIO_VERSION,
			true
		);

		$is_manager = Managers::is_manager();

		// Mutating nonces are withheld from non-managers (read-only
		// dashboard); admin.js already no-ops on missing keys.
		wp_localize_script(
			'nera-aio-admin',
			'neraAioAdmin',
			array(
				'ajaxurl'      => admin_url( 'admin-ajax.php' ),
				'toggleNonce'  => $is_manager ? wp_create_nonce( 'nera_aio_toggle' ) : '',
				'installNonce' => $is_manager ? wp_create_nonce( 'nera_aio_install' ) : '',
				'detailsNonce' => wp_create_nonce( 'nera_aio_details' ),
				'removeNonce'  => $is_manager ? wp_create_nonce( 'nera_aio_remove' ) : '',
			)
		);
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	/**
	 * Render the Overview page.
	 *
	 * @return void
	 */
	public function render_overview() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nera-all-in-one' ) );
		}

		$counts = Plugin_Catalog::counts( $this->entitlements, $this->registry );
		$total  = count( Plugin_Catalog::items() );
		?>
		<div class="wrap nera-aio-wrap">
			<div class="nera-aio-app">

				<div class="nera-aio-hero">
					<div class="nera-aio-hero__main">
						<h1 class="nera-aio-hero__title"><?php echo esc_html__( 'Nera All In One', 'nera-all-in-one' ); ?></h1>
						<p class="nera-aio-hero__sub">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: total plugins, 2: active count */
									__( '%1$d plugins · %2$d active', 'nera-all-in-one' ),
									$total,
									$counts['active'] + $counts['standalone']
								)
							);
							?>
						</p>
					</div>
				</div>

				<?php Plugin_Catalog::render_grid( $this->entitlements, $this->registry ); ?>

			</div>
		</div>
		<?php
		Plugin_Catalog::render_details_modal();
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

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
