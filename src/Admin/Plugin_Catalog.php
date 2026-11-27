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
	 *   gh_repo|null, deps[], edit_url, module_id|null
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
				'edit_url'      => isset( $m['edit_url'] ) ? (string) $m['edit_url'] : '',
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
		if ( self::is_entry_active( $entry ) ) {
			return array(
				'key'   => 'standalone',
				'label' => __( 'Active', 'nera-all-in-one' ),
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
				'label' => __( 'Inactive', 'nera-all-in-one' ),
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
		return '' !== self::resolve_plugin_file( $entry );
	}

	/**
	 * The installed version of the entry's plugin, or '' when not installed.
	 *
	 * @param array $entry
	 * @return string
	 */
	public static function installed_version( array $entry ) {
		$file = self::resolve_plugin_file( $entry );
		if ( '' === $file ) {
			return '';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		if ( isset( $all[ $file ]['Version'] ) ) {
			return (string) $all[ $file ]['Version'];
		}
		// Filesystem-resolved plugins may miss a stale get_plugins() cache entry.
		$abs = WP_PLUGIN_DIR . '/' . $file;
		if ( is_readable( $abs ) ) {
			$data = get_plugin_data( $abs, false, false );
			return isset( $data['Version'] ) ? (string) $data['Version'] : '';
		}
		return '';
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
	 * Whether the catalog entry's standalone plugin is currently active.
	 *
	 * Uses the resolved plugin basename (works for renamed install folders),
	 * then falls back to a legacy folder-prefix scan of active_plugins.
	 *
	 * @param array $entry
	 * @return bool
	 */
	public static function is_entry_active( array $entry ) {
		$file = self::resolve_plugin_file( $entry );
		if ( '' !== $file ) {
			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			if ( is_plugin_active( $file ) ) {
				return true;
			}
		}

		$folder = isset( $entry['legacy_folder'] ) ? (string) $entry['legacy_folder'] : '';
		if ( '' === $folder && isset( $entry['slug'] ) ) {
			$folder = (string) $entry['slug'];
		}
		return '' !== $folder && self::legacy_active( $folder );
	}

	/**
	 * Admin URL of the entry's own editor screen, shown only while its plugin is active
	 * (the screen is registered by that plugin).
	 *
	 * The catalog value is a path relative to wp-admin; absolute URLs and any
	 * scheme (https:, javascript:) are refused.
	 *
	 * @param array $entry
	 * @return string Absolute admin URL, or ''.
	 */
	public static function edit_url( array $entry ) {
		$path = isset( $entry['edit_url'] ) ? trim( (string) $entry['edit_url'] ) : '';
		if ( '' === $path || 0 === strpos( $path, '//' ) || null !== wp_parse_url( $path, PHP_URL_SCHEME ) ) {
			return '';
		}
		if ( ! self::is_entry_active( $entry ) ) {
			return '';
		}
		return admin_url( ltrim( $path, '/' ) );
	}

	/**
	 * Resolve the installed plugin file for a catalog entry ("folder/main.php").
	 *
	 * Order: exact slug folder in get_plugins() → slug/main_file on disk → any
	 * get_plugins() row whose basename ends with the catalog main_file → Plugin
	 * Name match (catalog name or "Nera {name}"). Exact slug folder wins when
	 * multiple matches exist.
	 *
	 * @param array $entry Catalog entry with slug and optional main_file/name.
	 * @return string Plugin file relative to the plugins dir, or ''.
	 */
	public static function resolve_plugin_file( array $entry ) {
		$slug = isset( $entry['slug'] ) ? (string) $entry['slug'] : '';
		if ( '' === $slug && isset( $entry['legacy_folder'] ) ) {
			$slug = (string) $entry['legacy_folder'];
		}
		$main = isset( $entry['main_file'] ) ? (string) $entry['main_file'] : '';
		$name = isset( $entry['name'] ) ? (string) $entry['name'] : '';

		$exact = self::legacy_plugin_file( $slug, $main );
		if ( '' !== $exact ) {
			return $exact;
		}

		return self::find_plugin_by_main_or_name( $main, $name, $slug );
	}

	/**
	 * Resolve the installed standalone plugin file ("folder/main.php") for a
	 * legacy folder, by scanning the installed-plugins list for a basename whose
	 * directory matches. Falls back to $main_file (or folder.php) on disk when
	 * get_plugins() misses (stale cache / inactive edge cases).
	 *
	 * @param string $folder    Legacy plugin folder, e.g. 'nera-spin-to-win'.
	 * @param string $main_file Optional main PHP filename inside the folder.
	 * @return string Plugin file relative to the plugins dir, or ''.
	 */
	public static function legacy_plugin_file( $folder, $main_file = '' ) {
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

		$candidates = array();
		if ( '' !== (string) $main_file ) {
			$candidates[] = (string) $main_file;
		}
		$candidates[] = $folder . '.php';

		foreach ( array_unique( $candidates ) as $main ) {
			$rel = $folder . '/' . $main;
			$abs = WP_PLUGIN_DIR . '/' . $rel;
			if ( ! is_readable( $abs ) ) {
				continue;
			}
			$data = get_plugin_data( $abs, false, false );
			if ( ! empty( $data['Name'] ) ) {
				return $rel;
			}
		}

		return '';
	}

	/**
	 * Find a plugin installed under a non-canonical folder by main filename or
	 * Plugin Name. Prefers a basename under $preferred_slug when several match.
	 *
	 * @param string $main_file      e.g. 'nera-competitions-seeder.php'.
	 * @param string $catalog_name   Catalog display name, e.g. 'Competitions Seeder'.
	 * @param string $preferred_slug Canonical install slug (preferred when tied).
	 * @return string Plugin file relative to the plugins dir, or ''.
	 */
	private static function find_plugin_by_main_or_name( $main_file, $catalog_name, $preferred_slug = '' ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all        = get_plugins();
		$main_match = '';
		$name_match = '';
		$main_file  = (string) $main_file;
		$names      = array();
		if ( '' !== (string) $catalog_name ) {
			$names[] = (string) $catalog_name;
			$names[] = 'Nera ' . $catalog_name;
		}

		foreach ( $all as $basename => $data ) {
			if ( ! is_string( $basename ) ) {
				continue;
			}

			$under_slug = '' !== $preferred_slug && 0 === strpos( $basename, $preferred_slug . '/' );

			if ( '' !== $main_file ) {
				$suffix = '/' . $main_file;
				if ( $basename === $main_file || substr( $basename, -strlen( $suffix ) ) === $suffix ) {
					if ( $under_slug ) {
						return $basename;
					}
					if ( '' === $main_match ) {
						$main_match = $basename;
					}
				}
			}

			if ( $names ) {
				$pname = isset( $data['Name'] ) ? (string) $data['Name'] : '';
				if ( in_array( $pname, $names, true ) ) {
					if ( $under_slug ) {
						return $basename;
					}
					if ( '' === $name_match ) {
						$name_match = $basename;
					}
				}
			}
		}

		return '' !== $main_match ? $main_match : $name_match;
	}

	/**
	 * Drop the get_plugins() object-cache so Manage/Overview see folders that
	 * appeared after the last scan (e.g. FTP / WP-CLI installs).
	 *
	 * @return void
	 */
	public static function refresh_plugins_cache() {
		if ( ! function_exists( 'wp_clean_plugins_cache' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		wp_clean_plugins_cache( true );
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
		if ( '' !== self::resolve_plugin_file( $entry ) ) {
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
			return self::is_entry_active( $entry );
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
		self::refresh_plugins_cache();
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
