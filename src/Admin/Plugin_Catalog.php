<?php
/**
 * Plugin catalog — the manifest of all Nera plugins shown on the dashboard, plus
 * the shared card-grid and details-modal renderers reused by the Overview and
 * Manage screens.
 *
 * @package Nera\AllInOne\Admin
 */

namespace Nera\AllInOne\Admin;

use Nera\AllInOne\Catalog\Hidden_Plugins;
use Nera\AllInOne\Catalog\Manifest;
use Nera\AllInOne\Entitlements\Entitlements;
use Nera\AllInOne\Install\Installer;
use Nera\AllInOne\Modules\Module_Registry;
use Nera\AllInOne\Support\Managers;

defined( 'ABSPATH' ) || exit;

/**
 * Static catalog + presentation helpers. Stateless; screens pass in the services.
 */
final class Plugin_Catalog {

	/**
	 * Catalog ids that ship as in-process wrapped modules inside AIO (usable
	 * without installing the standalone plugin). These get a module-backed
	 * toggle instead of an Install button. Empty — every catalog plugin now
	 * installs from its GitHub release.
	 *
	 * @var string[]
	 */
	const WRAPPED_MODULES = array();

	/**
	 * The Nera plugin catalog, sourced from the manifest (remote-with-fallback)
	 * and mapped to the keys the renderer/toggle layer expects.
	 *
	 * Each entry: id, name, tagline, icon, accent, min_tier,
	 *   legacy_folder (== install slug, kept for compat), slug, main_file,
	 *   gh_repo|null, deps[], module_id|null
	 *
	 * @return array[]
	 */
	public static function items() {
		$hidden = Hidden_Plugins::ids();
		$out    = array();
		foreach ( Manifest::all() as $m ) {
			if ( in_array( $m['id'], $hidden, true ) ) {
				continue;
			}
			$out[] = array(
				'id'            => $m['id'],
				'name'          => $m['name'],
				'tagline'       => $m['tagline'],
				'icon'          => $m['icon'],
				'accent'        => $m['accent'],
				'min_tier'      => $m['min_tier'],
				'legacy_folder' => $m['slug'],
				'slug'          => $m['slug'],
				'main_file'     => $m['main_file'],
				'gh_repo'       => $m['gh_repo'],
				'deps'          => $m['deps'],
				'custom'        => ! empty( $m['custom'] ),
				'module_id'     => in_array( $m['id'], self::WRAPPED_MODULES, true ) ? $m['id'] : null,
			);
		}
		return $out;
	}

	/**
	 * Resolve a catalog entry's effective status.
	 *
	 * @param array           $entry
	 * @param Entitlements    $e
	 * @param Module_Registry $r
	 * @return array { key: string, label: string }
	 */
	public static function status( array $entry, Entitlements $e, Module_Registry $r ) {
		if ( self::legacy_active( $entry['legacy_folder'] ) ) {
			return array(
				'key'   => 'standalone',
				'label' => __( 'Standalone', 'nera-all-in-one' ),
			);
		}

		if ( ! empty( $entry['module_id'] ) ) {
			$module = $r->get( $entry['module_id'] );
			if ( $module && 'active' === $r->status( $module ) ) {
				return array(
					'key'   => 'active',
					'label' => __( 'Active', 'nera-all-in-one' ),
				);
			}
		}

		// Installed but not active (real plugin present, deactivated).
		if ( self::installed( $entry ) ) {
			return array(
				'key'   => 'installed',
				'label' => __( 'Installed', 'nera-all-in-one' ),
			);
		}

		// Not installed and no source to install from yet.
		if ( 'none' === self::backing( $entry ) && empty( $entry['gh_repo'] ) ) {
			return array(
				'key'   => 'coming-soon',
				'label' => __( 'Coming soon', 'nera-all-in-one' ),
			);
		}

		if ( ! $e->is_entitled( $entry['min_tier'] ) ) {
			return array(
				'key'   => 'locked',
				'label' => __( 'Locked', 'nera-all-in-one' ),
			);
		}

		return array(
			'key'   => 'available',
			'label' => __( 'Available', 'nera-all-in-one' ),
		);
	}

	/**
	 * Whether the entry's standalone plugin is installed on this site.
	 *
	 * @param array $entry
	 * @return bool
	 */
	public static function installed( array $entry ) {
		return '' !== self::legacy_plugin_file( $entry['slug'] );
	}

	/**
	 * The installed version of the entry's plugin, or '' when not installed.
	 *
	 * @param array $entry
	 * @return string
	 */
	public static function installed_version( array $entry ) {
		$file = self::legacy_plugin_file( $entry['slug'] );
		if ( '' === $file ) {
			return '';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		return isset( $all[ $file ]['Version'] ) ? (string) $all[ $file ]['Version'] : '';
	}

	/**
	 * Whether a legacy standalone plugin (by folder) is currently active. Scans
	 * active_plugins for a "<folder>/" prefix so we don't need the exact main file.
	 *
	 * @param string $folder
	 * @return bool
	 */
	public static function legacy_active( $folder ) {
		$active = (array) get_option( 'active_plugins', array() );
		foreach ( $active as $basename ) {
			if ( is_string( $basename ) && 0 === strpos( $basename, $folder . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Find a catalog entry by its id.
	 *
	 * @param string $id Catalog entry id (e.g. 'spin-to-win').
	 * @return array|null The entry, or null when not found.
	 */
	public static function find( $id ) {
		foreach ( self::items() as $entry ) {
			if ( $entry['id'] === $id ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Resolve the installed standalone plugin file ("folder/main.php") for a
	 * legacy folder, by scanning the installed-plugins list for a basename whose
	 * directory matches. Returns '' when the plugin is not installed.
	 *
	 * @param string $folder Legacy plugin folder, e.g. 'nera-spin-to-win'.
	 * @return string Plugin file relative to the plugins dir, or ''.
	 */
	public static function legacy_plugin_file( $folder ) {
		if ( '' === (string) $folder ) {
			return '';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( array_keys( get_plugins() ) as $basename ) {
			if ( is_string( $basename ) && 0 === strpos( $basename, $folder . '/' ) ) {
				return $basename;
			}
		}
		return '';
	}

	/**
	 * What backs a catalog entry's toggle:
	 *   'plugin' — a real standalone plugin is installed (toggle activates it).
	 *   'module' — only an in-process wrapped module exists (toggle overrides it).
	 *   'none'   — neither installed; nothing to toggle.
	 *
	 * Plugin backing takes precedence: when the standalone is installed we manage
	 * the real plugin rather than the wrapper.
	 *
	 * @param array $entry
	 * @return string 'plugin' | 'module' | 'none'
	 */
	public static function backing( array $entry ) {
		if ( '' !== self::legacy_plugin_file( $entry['legacy_folder'] ) ) {
			return 'plugin';
		}
		if ( ! empty( $entry['module_id'] ) ) {
			return 'module';
		}
		return 'none';
	}

	/**
	 * Whether the entry is currently enabled (the toggle's "on" state).
	 *
	 * @param array           $entry
	 * @param Entitlements    $e
	 * @param Module_Registry $r
	 * @return bool
	 */
	public static function is_on( array $entry, Entitlements $e, Module_Registry $r ) {
		if ( 'plugin' === self::backing( $entry ) ) {
			return self::legacy_active( $entry['legacy_folder'] );
		}
		if ( ! empty( $entry['module_id'] ) ) {
			$module = $r->get( $entry['module_id'] );
			return $module && 'active' === $r->status( $module );
		}
		return false;
	}

	/**
	 * Aggregate status counts across the catalog (for the hero summary).
	 *
	 * @param Entitlements    $e
	 * @param Module_Registry $r
	 * @return array
	 */
	public static function counts( Entitlements $e, Module_Registry $r ) {
		$counts = array(
			'active'     => 0,
			'locked'     => 0,
			'available'  => 0,
			'standalone' => 0,
		);
		foreach ( self::items() as $entry ) {
			$key = self::status( $entry, $e, $r );
			$key = $key['key'];
			if ( isset( $counts[ $key ] ) ) {
				$counts[ $key ]++;
			}
		}
		return $counts;
	}

	// -------------------------------------------------------------------------
	// Presentation (shared by Overview + Modules)
	// -------------------------------------------------------------------------

	/**
	 * Echo the responsive plugin card grid.
	 *
	 * @param Entitlements    $e
	 * @param Module_Registry $r
	 * @return void
	 */
	public static function render_grid( Entitlements $e, Module_Registry $r ) {
		$items    = self::items();
		$readonly = ! Managers::is_manager();
		echo '<div class="nera-aio-grid">';
		$i = 0;
		foreach ( $items as $entry ) {
			$st        = self::status( $entry, $e, $r );
			$statuskey = $st['key'];
			$accent    = sanitize_hex_color( $entry['accent'] );
			if ( ! $accent ) {
				$accent = '#64748b';
			}

			printf(
				'<article class="nera-aio-plugin-card nera-aio-plugin-card--clickable nera-aio-plugin-card--%1$s" data-id="%4$s" style="--nera-accent:%2$s;--nera-i:%3$d;">',
				esc_attr( $statuskey ),
				esc_attr( $accent ),
				(int) $i,
				esc_attr( $entry['id'] )
			);

			echo '<div class="nera-aio-card__head">';
			echo '<span class="nera-aio-card__icon" aria-hidden="true">' . esc_html( $entry['icon'] ) . '</span>';
			printf(
				'<button type="button" class="nera-aio-card__info" data-id="%1$s" aria-label="%2$s">%3$s</button>',
				esc_attr( $entry['id'] ),
				/* translators: %s: plugin name */
				esc_attr( sprintf( __( 'View details for %s', 'nera-all-in-one' ), $entry['name'] ) ),
				'&#9432;'
			);
			echo '</div>';

			echo '<h3 class="nera-aio-card__name">' . esc_html( $entry['name'] ) . '</h3>';
			echo '<p class="nera-aio-card__desc">' . esc_html( $entry['tagline'] ) . '</p>';

			echo '<div class="nera-aio-card__badges">';
			echo '<span class="nera-aio-badge nera-aio-badge--' . esc_attr( $statuskey ) . '">' . esc_html( $st['label'] ) . '</span>';
			echo '</div>';

			echo '<div class="nera-aio-card__action">';
			self::action_html( $entry, $statuskey, $e, $r, $readonly );
			echo '</div>';

			echo '</article>';
			$i++;
		}
		echo '</div>';
	}

	/**
	 * Echo the per-entry action control — the store state machine:
	 *
	 *   module-backed (wrapped)      → toggle (no install needed).
	 *   installed plugin             → toggle + Update if newer.
	 *   not installed, no gh_repo    → "Coming soon".
	 *   not installed, entitled      → [Install].
	 *   not installed, not entitled  → "Contact Nera" hint.
	 *
	 * Server mirrors the gate: Install/Update require entitlement
	 * (Install_Controller); disabling via the toggle never does (Module_Toggle).
	 * All mutating controls additionally require a manager user — non-managers
	 * see disabled switches / muted hints (read-only dashboard).
	 *
	 * Public: reused by the Manage screen's table rows.
	 *
	 * @param array           $entry
	 * @param string          $statuskey Status key from self::status().
	 * @param Entitlements    $e
	 * @param Module_Registry $r
	 * @param bool            $readonly  True for non-manager viewers.
	 * @return void
	 */
	public static function action_html( array $entry, $statuskey, Entitlements $e, Module_Registry $r, $readonly = false ) {
		$kind     = self::backing( $entry );
		$entitled = $e->is_entitled( $entry['min_tier'] );

		// Wrapped module (e.g. age-gate) — usable without installing anything.
		if ( 'module' === $kind ) {
			self::switch_html( $entry['id'], $kind, self::is_on( $entry, $e, $r ), $readonly );
			return;
		}

		// Installed standalone plugin — toggle + an Update button when newer.
		// Update lookup is skipped for read-only viewers (it hits the GitHub
		// API and they can't act on it anyway).
		if ( 'plugin' === $kind ) {
			self::switch_html( $entry['id'], $kind, self::is_on( $entry, $e, $r ), $readonly );
			if ( ! $readonly ) {
				self::update_html( $entry );
			}
			return;
		}

		// Not installed, nothing to install from yet.
		if ( empty( $entry['gh_repo'] ) ) {
			echo '<span class="nera-aio-muted">' . esc_html__( 'Coming soon', 'nera-all-in-one' ) . '</span>';
			return;
		}

		// Not installed — read-only viewers get a hint instead of a button.
		if ( $readonly ) {
			echo '<span class="nera-aio-muted">' . esc_html__( 'Managed by Nera', 'nera-all-in-one' ) . '</span>';
			return;
		}

		// Not installed but available from GitHub.
		if ( $entitled ) {
			printf(
				'<button type="button" class="button button-primary nera-aio-install-btn" data-id="%s">%s</button>',
				esc_attr( $entry['id'] ),
				esc_html__( 'Install', 'nera-all-in-one' )
			);
			return;
		}

		// Paid plugin, not entitled — licensing UI is retired, point at Nera.
		echo '<span class="nera-aio-muted">' . esc_html__( 'Contact Nera', 'nera-all-in-one' ) . '</span>';
	}

	/**
	 * Echo an Update button for an installed, GitHub-sourced plugin when the
	 * latest release is newer than the installed version. Always targets the
	 * latest release (no version picker).
	 *
	 * @param array $entry
	 * @return void
	 */
	private static function update_html( array $entry ) {
		if ( empty( $entry['gh_repo'] ) ) {
			return;
		}

		$installed = self::installed_version( $entry );
		if ( '' === $installed ) {
			return;
		}

		$latest = Installer::latest_tag( $entry['gh_repo'] );
		if ( '' === $latest || ! version_compare( ltrim( $latest, 'v' ), $installed, '>' ) ) {
			return;
		}

		echo '<div class="nera-aio-version-row">';
		printf(
			'<button type="button" class="button button-secondary nera-aio-update-btn" data-id="%s">%s</button>',
			esc_attr( $entry['id'] ),
			esc_html( sprintf( /* translators: %s: version tag */ __( 'Update to %s', 'nera-all-in-one' ), $latest ) )
		);
		echo '</div>';
	}

	/**
	 * Echo an accessible on/off toggle switch for a card.
	 *
	 * @param string $id       Catalog entry id (sent back on toggle).
	 * @param string $kind     'plugin' | 'module' (data attribute, informational).
	 * @param bool   $on       Current state.
	 * @param bool   $disabled Render inert (read-only viewers). Disabled inputs
	 *                         never fire 'change', so admin.js needs no change.
	 * @return void
	 */
	private static function switch_html( $id, $kind, $on, $disabled = false ) {
		printf(
			'<label class="nera-aio-switch">'
				. '<input type="checkbox" class="nera-aio-toggle" data-id="%1$s" data-kind="%2$s" %3$s%5$s />'
				. '<span class="nera-aio-switch__track"><span class="nera-aio-switch__thumb"></span></span>'
				. '<span class="nera-aio-switch__label">%4$s</span>'
				. '</label>',
			esc_attr( $id ),
			esc_attr( $kind ),
			checked( $on, true, false ),
			$on ? esc_html__( 'On', 'nera-all-in-one' ) : esc_html__( 'Off', 'nera-all-in-one' ),
			$disabled ? ' disabled' : ''
		);
	}

	/**
	 * Echo the hidden plugin Details modal. An empty shell whose head, body and
	 * actions are populated by assets/admin.js from the nera_aio_details AJAX
	 * response. Element IDs match assets/admin.js exactly.
	 *
	 * @return void
	 */
	public static function render_details_modal() {
		?>
		<div id="nera-aio-details-modal" class="nera-aio-modal nera-aio-details-modal" style="display:none;" aria-modal="true" role="dialog" aria-labelledby="nera-aio-details-title">
			<div class="nera-aio-modal__overlay" id="nera-aio-details-overlay"></div>
			<div class="nera-aio-modal__card nera-aio-details__card">
				<button type="button" class="nera-aio-modal__close" id="nera-aio-details-close" aria-label="<?php echo esc_attr__( 'Close', 'nera-all-in-one' ); ?>">&times;</button>
				<div id="nera-aio-details-head" class="nera-aio-details__head"></div>
				<div id="nera-aio-details-body" class="nera-aio-readme"></div>
				<div id="nera-aio-details-actions" class="nera-aio-details__actions"></div>
			</div>
		</div>
		<?php
	}
}
