<?php
/**
 * Admin Manage screen.
 *
 * Lists every catalog plugin as a table row with checkbox selection and bulk
 * actions (activate / deactivate / update / install / uninstall). Bulk actions
 * are executed client-side by admin.js as a sequential loop over the existing
 * single-plugin AJAX endpoints (nera_aio_toggle / nera_aio_install /
 * nera_aio_remove) — there is no separate bulk endpoint.
 *
 * Non-managers get a read-only table: no checkboxes, no bulk bar, no Remove
 * buttons, disabled switches (mirrors the card grid's read-only mode).
 *
 * @package Nera\AllInOne\Admin
 */

namespace Nera\AllInOne\Admin;

use Nera\AllInOne\Entitlements\Entitlements;
use Nera\AllInOne\Install\Installer;
use Nera\AllInOne\License\License_Client;
use Nera\AllInOne\Modules\Module_Registry;
use Nera\AllInOne\Support\Assets;
use Nera\AllInOne\Support\Managers;

defined( 'ABSPATH' ) || exit;

/**
 * Manage_Screen — plugin table + bulk actions.
 */
final class Manage_Screen {

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
	 * Add the "Manage" submenu under the parent Nera All In One menu.
	 *
	 * @return void
	 */
	public function register_submenu() {
		add_submenu_page(
			'nera-all-in-one',
			__( 'Nera All In One — Manage', 'nera-all-in-one' ),
			__( 'Manage', 'nera-all-in-one' ),
			'manage_options',
			'nera-aio-manage',
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

		$is_manager = Managers::is_manager();

		// Mutating nonces are withheld from non-managers (read-only
		// dashboard); admin.js already no-ops on missing keys.
		wp_localize_script(
			'nera-aio-admin',
			'neraAioAdmin',
			array(
				'ajaxurl'         => admin_url( 'admin-ajax.php' ),
				'toggleNonce'     => $is_manager ? wp_create_nonce( 'nera_aio_toggle' ) : '',
				'installNonce'    => $is_manager ? wp_create_nonce( 'nera_aio_install' ) : '',
				'detailsNonce'    => wp_create_nonce( 'nera_aio_details' ),
				'removeNonce'     => $is_manager ? wp_create_nonce( 'nera_aio_remove' ) : '',
				'repoAddNonce'    => $is_manager ? wp_create_nonce( 'nera_aio_repo_add' ) : '',
				'repoDelistNonce' => $is_manager ? wp_create_nonce( 'nera_aio_repo_delist' ) : '',
			)
		);
	}

	/**
	 * Render the Manage page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nera-all-in-one' ) );
		}

		$is_manager = Managers::is_manager();
		?>
		<div class="wrap nera-aio-wrap">
			<div class="nera-aio-app">

				<div class="nera-aio-hero nera-aio-hero--compact">
					<div class="nera-aio-hero__main">
						<h1 class="nera-aio-hero__title"><?php echo esc_html__( 'Manage', 'nera-all-in-one' ); ?></h1>
						<p class="nera-aio-hero__sub"><?php echo esc_html__( 'Install, activate, update and remove Nera plugins.', 'nera-all-in-one' ); ?></p>
					</div>
				</div>

				<?php
				if ( $is_manager ) {
					$this->render_addrepo();
					$this->render_bulkbar();
				}
				$this->render_table( $is_manager );
				?>

			</div>
		</div>
		<?php
		Plugin_Catalog::render_details_modal();
	}

	// -------------------------------------------------------------------------
	// Private renderers
	// -------------------------------------------------------------------------

	/**
	 * Echo the add-plugin-by-GitHub-URL row (managers only). Element IDs
	 * match admin.js.
	 *
	 * @return void
	 */
	private function render_addrepo() {
		?>
		<div class="nera-aio-addrepo">
			<label class="screen-reader-text" for="nera-aio-repo-url"><?php echo esc_html__( 'GitHub repository URL', 'nera-all-in-one' ); ?></label>
			<input type="url" id="nera-aio-repo-url" class="regular-text" placeholder="https://github.com/Nera-Marketing/plugin-name" />
			<button type="button" class="button button-primary" id="nera-aio-repo-add"><?php echo esc_html__( 'Add & Install', 'nera-all-in-one' ); ?></button>
			<span id="nera-aio-repo-status" class="nera-aio-inline-notice" style="display:none;"></span>
		</div>
		<?php
	}

	/**
	 * Echo the bulk-action bar (managers only). Element IDs match admin.js.
	 *
	 * @return void
	 */
	private function render_bulkbar() {
		?>
		<div class="nera-aio-bulkbar">
			<label class="screen-reader-text" for="nera-aio-bulk-action"><?php echo esc_html__( 'Bulk action', 'nera-all-in-one' ); ?></label>
			<select id="nera-aio-bulk-action">
				<option value=""><?php echo esc_html__( 'Bulk actions', 'nera-all-in-one' ); ?></option>
				<option value="activate"><?php echo esc_html__( 'Activate', 'nera-all-in-one' ); ?></option>
				<option value="deactivate"><?php echo esc_html__( 'Deactivate', 'nera-all-in-one' ); ?></option>
				<option value="update"><?php echo esc_html__( 'Update', 'nera-all-in-one' ); ?></option>
				<option value="install"><?php echo esc_html__( 'Install', 'nera-all-in-one' ); ?></option>
				<option value="uninstall"><?php echo esc_html__( 'Uninstall', 'nera-all-in-one' ); ?></option>
				<option value="delist"><?php echo esc_html__( 'Remove from list', 'nera-all-in-one' ); ?></option>
			</select>
			<button type="button" class="button" id="nera-aio-bulk-apply"><?php echo esc_html__( 'Apply', 'nera-all-in-one' ); ?></button>
			<span id="nera-aio-bulk-status" class="nera-aio-inline-notice" style="display:none;"></span>
		</div>
		<?php
	}

	/**
	 * Echo the plugin table.
	 *
	 * @param bool $is_manager Whether the viewer may mutate (checkboxes, bulk).
	 * @return void
	 */
	private function render_table( $is_manager ) {
		$readonly = ! $is_manager;
		?>
		<div class="nera-aio-table-wrap">
			<table id="nera-aio-manage-table" class="nera-aio-table">
				<thead>
					<tr>
						<?php if ( $is_manager ) : ?>
							<th class="nera-aio-table__check">
								<input type="checkbox" id="nera-aio-check-all" aria-label="<?php echo esc_attr__( 'Select all plugins', 'nera-all-in-one' ); ?>" />
							</th>
						<?php endif; ?>
						<th><?php echo esc_html__( 'Plugin', 'nera-all-in-one' ); ?></th>
						<th><?php echo esc_html__( 'Status', 'nera-all-in-one' ); ?></th>
						<th><?php echo esc_html__( 'Installed', 'nera-all-in-one' ); ?></th>
						<th><?php echo esc_html__( 'Latest', 'nera-all-in-one' ); ?></th>
						<th><?php echo esc_html__( 'Actions', 'nera-all-in-one' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( Plugin_Catalog::items() as $entry ) {
						$this->render_row( $entry, $readonly );
					}
					?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Echo one plugin row.
	 *
	 * @param array $entry    Catalog entry.
	 * @param bool  $readonly True for non-manager viewers.
	 * @return void
	 */
	private function render_row( array $entry, $readonly ) {
		$st         = Plugin_Catalog::status( $entry, $this->entitlements, $this->registry );
		$statuskey  = $st['key'];
		$backing    = Plugin_Catalog::backing( $entry );
		$installed  = Plugin_Catalog::installed( $entry );
		$active     = Plugin_Catalog::is_on( $entry, $this->entitlements, $this->registry );
		$version    = $installed ? Plugin_Catalog::installed_version( $entry ) : '';
		$latest     = $this->latest_tag_for( $entry, $readonly );
		$has_update = '' !== $latest && '' !== $version && version_compare( ltrim( $latest, 'v' ), $version, '>' );
		$display    = $this->display_status( $statuskey );
		$accent     = sanitize_hex_color( $entry['accent'] );
		if ( ! $accent ) {
			$accent = '#64748b';
		}

		printf(
			'<tr class="nera-aio-row nera-aio-row--%1$s" style="--nera-accent:%2$s;" data-id="%3$s" data-name="%4$s" data-status="%1$s" data-backing="%5$s" data-installed="%6$s" data-active="%7$s" data-has-repo="%8$s" data-has-update="%9$s" data-custom="%10$s">',
			esc_attr( $statuskey ),
			esc_attr( $accent ),
			esc_attr( $entry['id'] ),
			esc_attr( $entry['name'] ),
			esc_attr( $backing ),
			$installed ? '1' : '0',
			$active ? '1' : '0',
			empty( $entry['gh_repo'] ) ? '0' : '1',
			$has_update ? '1' : '0',
			empty( $entry['custom'] ) ? '0' : '1'
		);

		if ( ! $readonly ) {
			echo '<td class="nera-aio-table__check">';
			printf(
				'<input type="checkbox" class="nera-aio-row-check" aria-label="%s" />',
				/* translators: %s: plugin name */
				esc_attr( sprintf( __( 'Select %s', 'nera-all-in-one' ), $entry['name'] ) )
			);
			echo '</td>';
		}

		echo '<td class="nera-aio-table__plugin">';
		echo '<div class="nera-aio-table__cellflex">';
		echo '<span class="nera-aio-card__icon nera-aio-card__icon--sm" aria-hidden="true">' . esc_html( $entry['icon'] ) . '</span>';
		echo '<span class="nera-aio-table__id">';
		echo '<span class="nera-aio-table__name">' . esc_html( $entry['name'] );
		printf(
			'<button type="button" class="nera-aio-card__info" data-id="%1$s" aria-label="%2$s">%3$s</button>',
			esc_attr( $entry['id'] ),
			/* translators: %s: plugin name */
			esc_attr( sprintf( __( 'View details for %s', 'nera-all-in-one' ), $entry['name'] ) ),
			'&#9432;'
		);
		echo '</span>';
		echo '<span class="nera-aio-table__tagline">' . esc_html( $entry['tagline'] ) . '</span>';
		echo '</span>';
		echo '</div>';
		echo '</td>';

		echo '<td><span class="nera-aio-badge nera-aio-badge--' . esc_attr( $display['class'] ) . '">' . esc_html( $display['label'] ) . '</span></td>';

		echo '<td class="nera-aio-table__ver">' . ( '' !== $version ? esc_html( $version ) : '&mdash;' ) . '</td>';

		echo '<td class="nera-aio-table__ver' . ( $has_update ? ' is-newer' : '' ) . '">' . ( '' !== $latest ? esc_html( $latest ) : '&mdash;' ) . '</td>';

		echo '<td class="nera-aio-table__actions">';
		echo '<div class="nera-aio-table__cellflex">';
		Plugin_Catalog::action_html( $entry, $statuskey, $this->entitlements, $this->registry, $readonly );
		if ( ! $readonly && $installed && 'module' !== $backing ) {
			printf(
				'<button type="button" class="button-link nera-aio-row-remove" data-id="%1$s" data-name="%2$s">%3$s</button>',
				esc_attr( $entry['id'] ),
				esc_attr( $entry['name'] ),
				esc_html__( 'Remove', 'nera-all-in-one' )
			);
		}
		if ( ! $readonly && ! $installed ) {
			printf(
				'<button type="button" class="nera-aio-row-delist" data-id="%1$s" data-name="%2$s" title="%3$s" aria-label="%4$s">&times;</button>',
				esc_attr( $entry['id'] ),
				esc_attr( $entry['name'] ),
				esc_attr__( 'Remove from list', 'nera-all-in-one' ),
				/* translators: %s: plugin name */
				esc_attr( sprintf( __( 'Remove %s from list', 'nera-all-in-one' ), $entry['name'] ) )
			);
		}
		echo '</div>';
		echo '</td>';

		echo '</tr>';
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Map a raw catalog status key to the table's display badge.
	 *
	 * The card grid distinguishes "standalone" (real plugin active) from
	 * "active" (wrapped module); on the Manage table both simply read "Active".
	 *
	 * @param string $statuskey Raw key from Plugin_Catalog::status().
	 * @return array { label: string, class: string }
	 */
	private function display_status( $statuskey ) {
		switch ( $statuskey ) {
			case 'standalone':
			case 'active':
				return array(
					'label' => __( 'Active', 'nera-all-in-one' ),
					'class' => 'active',
				);
			case 'installed':
				return array(
					'label' => __( 'Inactive', 'nera-all-in-one' ),
					'class' => 'installed',
				);
			case 'available':
				return array(
					'label' => __( 'Not installed', 'nera-all-in-one' ),
					'class' => 'available',
				);
			case 'coming-soon':
				return array(
					'label' => __( 'Coming soon', 'nera-all-in-one' ),
					'class' => 'coming-soon',
				);
			default:
				return array(
					'label' => __( 'Locked', 'nera-all-in-one' ),
					'class' => 'locked',
				);
		}
	}

	/**
	 * Latest release tag for an entry, or '' when not applicable.
	 *
	 * Skipped for read-only viewers: they cannot act on the information and
	 * the lookup spends unauthenticated GitHub API budget (60 req/h per IP) —
	 * mirrors the card grid's update_html() behaviour.
	 *
	 * @param array $entry    Catalog entry.
	 * @param bool  $readonly True for non-manager viewers.
	 * @return string
	 */
	private function latest_tag_for( array $entry, $readonly ) {
		if ( $readonly || empty( $entry['gh_repo'] ) || ! Plugin_Catalog::installed( $entry ) ) {
			return '';
		}
		return Installer::latest_tag( $entry['gh_repo'] );
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
