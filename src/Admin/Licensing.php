<?php
/**
 * Admin Licensing screen.
 *
 * Renders the License management page, handles the apply-key and clear-key
 * form submissions via admin-post.php, and handles the AJAX "check now" action.
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
 * Licensing — key input, apply/clear forms, AJAX check-now, upgrade modal.
 */
final class Licensing {

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
		add_action( 'admin_menu', array( $this, 'register_submenu' ) );
		add_action( 'admin_post_nera_aio_apply_key', array( $this, 'handle_apply' ) );
		add_action( 'admin_post_nera_aio_clear_key', array( $this, 'handle_clear' ) );
		add_action( 'wp_ajax_nera_aio_check_now', array( $this, 'handle_check_now' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	// -------------------------------------------------------------------------
	// Menu registration
	// -------------------------------------------------------------------------

	/**
	 * Add the "License" submenu under the parent Nera All In One menu.
	 *
	 * @return void
	 */
	public function register_submenu() {
		add_submenu_page(
			'nera-all-in-one',
			__( 'Nera All In One — License', 'nera-all-in-one' ),
			__( 'License', 'nera-all-in-one' ),
			'manage_options',
			'nera-aio-licensing',
			array( $this, 'render_page' )
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

		wp_localize_script(
			'nera-aio-admin',
			'neraAioAdmin',
			array(
				'ajaxurl'    => admin_url( 'admin-ajax.php' ),
				'checkNonce' => wp_create_nonce( 'nera_aio_check_now' ),
				'demoKey'    => $this->license->is_demo_mode() ? $this->license->get_demo_key() : '',
				'demoMode'   => $this->license->is_demo_mode(),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	/**
	 * Render the License management page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nera-all-in-one' ) );
		}

		$ent       = $this->license->current();
		$tier      = $this->entitlements->current_tier();
		$source    = isset( $ent['source'] ) ? $ent['source'] : '';
		$is_demo   = $this->license->is_demo_mode();
		$key       = $this->license->get_key();
		$has_key   = '' !== $key;

		// Success/error notices from redirects.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice_type = isset( $_GET['nera_aio_notice'] ) ? sanitize_key( $_GET['nera_aio_notice'] ) : '';
		?>
		<div class="wrap nera-aio-wrap">
			<div class="nera-aio-app">
			<div class="nera-aio-hero nera-aio-hero--compact">
				<div class="nera-aio-hero__main">
					<h1 class="nera-aio-hero__title"><?php echo esc_html__( 'License', 'nera-all-in-one' ); ?></h1>
					<p class="nera-aio-hero__sub"><?php echo esc_html__( 'Activate a key to unlock PRO and Agency modules.', 'nera-all-in-one' ); ?></p>
				</div>
			</div>

			<?php if ( 'key_applied' === $notice_type ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'License key applied successfully.', 'nera-all-in-one' ); ?></p>
				</div>
			<?php elseif ( 'key_cleared' === $notice_type ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'License key cleared. Reverted to free tier.', 'nera-all-in-one' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="nera-aio-panel">
				<h2><?php echo esc_html__( 'Current Status', 'nera-all-in-one' ); ?></h2>
				<p>
					<?php echo esc_html__( 'Tier:', 'nera-all-in-one' ); ?>
					<span class="nera-aio-badge nera-aio-badge--tier nera-aio-badge--tier-<?php echo esc_attr( $tier ); ?>">
						<?php echo esc_html( strtoupper( $tier ) ); ?>
					</span>
					<?php if ( $source ) : ?>
						<span class="nera-aio-source">
							(<?php echo esc_html( $source ); ?>)
						</span>
					<?php endif; ?>
				</p>

				<?php if ( $is_demo ) : ?>
					<div class="notice notice-warning inline nera-aio-demo-notice">
						<p>
							<strong><?php echo esc_html__( 'Demo mode is active.', 'nera-all-in-one' ); ?></strong>
							<?php echo esc_html__( 'The demo key grants PRO tier on non-production environments only.', 'nera-all-in-one' ); ?>
						</p>
					</div>
				<?php endif; ?>

				<p>
					<button type="button" id="nera-aio-check-now" class="button button-secondary">
						<?php echo esc_html__( 'Check license now', 'nera-all-in-one' ); ?>
					</button>
					<span id="nera-aio-check-result" class="nera-aio-inline-notice" style="display:none;margin-left:8px;"></span>
				</p>
			</div>

			<div class="nera-aio-panel">
				<h2><?php echo esc_html__( 'Apply License Key', 'nera-all-in-one' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="nera_aio_apply_key" />
					<?php wp_nonce_field( 'nera_aio_apply_key', 'nera_aio_apply_key_nonce' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="nera_aio_key"><?php echo esc_html__( 'License Key', 'nera-all-in-one' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="nera_aio_key"
									name="nera_aio_key"
									class="regular-text"
									value="<?php echo esc_attr( $key ); ?>"
									placeholder="<?php echo esc_attr__( 'Enter your license key…', 'nera-all-in-one' ); ?>"
								/>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Apply', 'nera-all-in-one' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<?php if ( $has_key ) : ?>
				<div class="nera-aio-panel">
					<h2><?php echo esc_html__( 'Deactivate License', 'nera-all-in-one' ); ?></h2>
					<p><?php echo esc_html__( 'Clearing the key reverts the site to the free tier.', 'nera-all-in-one' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="nera_aio_clear_key" />
						<?php wp_nonce_field( 'nera_aio_clear_key', 'nera_aio_clear_key_nonce' ); ?>
						<?php submit_button( __( 'Deactivate / Clear key', 'nera-all-in-one' ), 'delete', 'submit', false ); ?>
					</form>
				</div>
			<?php endif; ?>

			<?php if ( $is_demo ) : ?>
				<div class="nera-aio-panel">
					<p>
						<button type="button" id="nera-aio-open-upgrade" class="button button-primary">
							<?php echo esc_html__( 'Get Demo PRO Key', 'nera-all-in-one' ); ?>
						</button>
					</p>
				</div>
			<?php endif; ?>

		</div>
		</div>

		<?php $this->render_upgrade_modal(); ?>
		<?php
	}

	// -------------------------------------------------------------------------
	// Form handlers
	// -------------------------------------------------------------------------

	/**
	 * Handle admin-post.php action: nera_aio_apply_key.
	 *
	 * @return void
	 */
	public function handle_apply() {
		check_admin_referer( 'nera_aio_apply_key', 'nera_aio_apply_key_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'nera-all-in-one' ) );
		}

		$key = isset( $_POST['nera_aio_key'] ) ? wp_unslash( $_POST['nera_aio_key'] ) : '';
		$this->license->apply_key( sanitize_text_field( $key ) );

		wp_safe_redirect(
			add_query_arg(
				'nera_aio_notice',
				'key_applied',
				admin_url( 'admin.php?page=nera-aio-licensing' )
			)
		);
		exit;
	}

	/**
	 * Handle admin-post.php action: nera_aio_clear_key.
	 *
	 * @return void
	 */
	public function handle_clear() {
		check_admin_referer( 'nera_aio_clear_key', 'nera_aio_clear_key_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'nera-all-in-one' ) );
		}

		$this->license->clear_key();

		wp_safe_redirect(
			add_query_arg(
				'nera_aio_notice',
				'key_cleared',
				admin_url( 'admin.php?page=nera-aio-licensing' )
			)
		);
		exit;
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	/**
	 * Handle wp_ajax_nera_aio_check_now.
	 *
	 * @return void
	 */
	public function handle_check_now() {
		check_ajax_referer( 'nera_aio_check_now' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'nera-all-in-one' ) ), 403 );
		}

		$ent = $this->license->check_now();

		wp_send_json_success(
			array(
				'tier'   => isset( $ent['tier'] ) ? $ent['tier'] : '',
				'source' => isset( $ent['source'] ) ? $ent['source'] : '',
			)
		);
	}

	// -------------------------------------------------------------------------
	// Shared modal partial
	// -------------------------------------------------------------------------

	/**
	 * Output the hidden Upgrade modal markup.
	 *
	 * Called from this class and may be called from Modules_Screen.
	 *
	 * @return void
	 */
	public function render_upgrade_modal() {
		$is_demo = $this->license->is_demo_mode();
		$demo_key = $is_demo ? $this->license->get_demo_key() : '';
		?>
		<div id="nera-aio-upgrade-modal" class="nera-aio-modal" style="display:none;" aria-modal="true" role="dialog" aria-label="<?php echo esc_attr__( 'Upgrade to PRO', 'nera-all-in-one' ); ?>">
			<div class="nera-aio-modal__overlay" id="nera-aio-modal-overlay"></div>
			<div class="nera-aio-modal__card">
				<button type="button" class="nera-aio-modal__close" id="nera-aio-modal-close" aria-label="<?php echo esc_attr__( 'Close', 'nera-all-in-one' ); ?>">
					&times;
				</button>

				<h2><?php echo esc_html__( 'Upgrade to PRO', 'nera-all-in-one' ); ?></h2>

				<?php if ( $is_demo && $demo_key ) : ?>
					<p><?php echo esc_html__( 'This is a demo environment. Use the key below to try PRO features:', 'nera-all-in-one' ); ?></p>
					<div class="nera-aio-demo-key-row">
						<code id="nera-aio-demo-key-display" class="nera-aio-demo-key"><?php echo esc_html( $demo_key ); ?></code>
						<button type="button" id="nera-aio-copy-demo-key" class="button button-secondary">
							<?php echo esc_html__( 'Copy', 'nera-all-in-one' ); ?>
						</button>
					</div>
					<p class="description">
						<?php echo esc_html__( 'Paste this key in the License field on the License page and click Apply.', 'nera-all-in-one' ); ?>
					</p>
					<button type="button" id="nera-aio-use-demo-key" class="button button-primary">
						<?php echo esc_html__( 'Use this key', 'nera-all-in-one' ); ?>
					</button>
				<?php else : ?>
					<p><?php echo esc_html__( 'Contact Nera to obtain a PRO or Agency license key, then enter it on the License page.', 'nera-all-in-one' ); ?></p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=nera-aio-licensing' ) ); ?>" class="button button-primary">
						<?php echo esc_html__( 'Go to License page', 'nera-all-in-one' ); ?>
					</a>
				<?php endif; ?>
			</div>
		</div>
		<?php
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
