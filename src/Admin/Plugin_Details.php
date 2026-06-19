<?php
/**
 * Plugin details + remove AJAX controller.
 *
 * Backs the per-card details modal:
 *   - nera_aio_details — read-only; returns a card's metadata plus its full
 *     description, rendered from the plugin's GitHub README (HTML, cached).
 *   - nera_aio_remove  — destructive; full WP-style delete of an installed
 *     standalone plugin (deactivate → delete files → run uninstall routine).
 *
 * @package Nera\AllInOne\Admin
 */

namespace Nera\AllInOne\Admin;

use Nera\AllInOne\Entitlements\Entitlements;
use Nera\AllInOne\License\License_Client;
use Nera\AllInOne\Modules\Module_Registry;
use Nera\AllInOne\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin_Details — handles wp_ajax_nera_aio_details and wp_ajax_nera_aio_remove.
 *
 * Constructor signature matches the other admin screens so Plugin::boot_admin()
 * can instantiate it from the same loop.
 */
final class Plugin_Details {

	/**
	 * Cache lifetime for a successfully fetched README.
	 */
	const README_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Short cache for a failed README fetch, so a 404/private repo doesn't get
	 * re-hit on every modal open.
	 */
	const README_FAIL_TTL = 15 * MINUTE_IN_SECONDS;

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
		add_action( 'wp_ajax_nera_aio_details', array( $this, 'handle_details' ) );
		add_action( 'wp_ajax_nera_aio_remove', array( $this, 'handle_remove' ) );
	}

	/**
	 * Handle wp_ajax_nera_aio_details — return a card's full info for the modal.
	 *
	 * @return void
	 */
	public function handle_details() {
		check_ajax_referer( 'nera_aio_details' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'nera-all-in-one' ) ), 403 );
		}

		$id    = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$entry = Plugin_Catalog::find( $id );
		if ( null === $entry ) {
			wp_send_json_error( array( 'message' => __( 'Unknown plugin.', 'nera-all-in-one' ) ), 404 );
		}

		$status     = Plugin_Catalog::status( $entry, $this->entitlements, $this->registry );
		$installed  = Plugin_Catalog::installed( $entry );
		$repo       = isset( $entry['gh_repo'] ) ? (string) $entry['gh_repo'] : '';
		$can_remove = $installed && current_user_can( 'delete_plugins' );

		wp_send_json_success(
			array(
				'id'               => $entry['id'],
				'name'             => $entry['name'],
				'tagline'          => $entry['tagline'],
				'icon'             => $entry['icon'],
				'category'         => $entry['category'],
				'accent'           => $entry['accent'],
				'tier'             => $entry['min_tier'],
				'status'           => $status['label'],
				'status_key'       => $status['key'],
				'version'          => $installed ? Plugin_Catalog::installed_version( $entry ) : '',
				'repo'             => $repo,
				'repo_url'         => '' !== $repo ? 'https://github.com/' . $repo : '',
				'deps'             => array_values( (array) $entry['deps'] ),
				'description_html' => self::readme_html( $repo ),
				'can_remove'       => $can_remove,
			)
		);
	}

	/**
	 * Handle wp_ajax_nera_aio_remove — full WP-style delete of an installed plugin.
	 *
	 * Deactivates (if active), deletes the plugin files from disk, and runs the
	 * plugin's uninstall routine (uninstall.php / uninstall hooks). Irreversible.
	 *
	 * @return void
	 */
	public function handle_remove() {
		check_ajax_referer( 'nera_aio_remove' );

		if ( ! current_user_can( 'delete_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions to delete plugins.', 'nera-all-in-one' ) ), 403 );
		}

		$id    = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$entry = Plugin_Catalog::find( $id );
		if ( null === $entry ) {
			wp_send_json_error( array( 'message' => __( 'Unknown plugin.', 'nera-all-in-one' ) ), 404 );
		}

		// Re-resolve the installed plugin file from the catalog; never trust a
		// client-supplied path.
		$file = Plugin_Catalog::legacy_plugin_file( $entry['legacy_folder'] );
		if ( '' === $file ) {
			wp_send_json_error( array( 'message' => __( 'Plugin is not installed.', 'nera-all-in-one' ) ), 404 );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$creds = request_filesystem_credentials( '', '', false, false, null );
		if ( false === $creds || ! WP_Filesystem( $creds ) ) {
			wp_send_json_error( array( 'message' => __( 'Filesystem is not writable — cannot delete plugin.', 'nera-all-in-one' ) ), 500 );
		}

		// delete_plugins() does not deactivate; deactivate first so an active
		// plugin doesn't leave a dangling active_plugins entry.
		deactivate_plugins( $file );

		$result = delete_plugins( array( $file ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}
		if ( true !== $result ) {
			Logger::log( 'plugin removal returned non-true for ' . $file );
			wp_send_json_error( array( 'message' => __( 'Removal failed.', 'nera-all-in-one' ) ), 500 );
		}

		wp_send_json_success( array( 'id' => $id ) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Fetch a repo's README as sanitized HTML, cached.
	 *
	 * Uses GitHub's HTML media type so the markdown arrives already rendered —
	 * no markdown parser needed. Returns '' when there is no repo or the fetch
	 * fails (e.g. private repo / 404), caching the empty result briefly so the
	 * modal doesn't re-hit the API on every open.
	 *
	 * @param string $repo "owner/name", or '' .
	 * @return string Sanitized HTML, or '' .
	 */
	private static function readme_html( $repo ) {
		$repo = (string) $repo;
		if ( '' === $repo ) {
			return '';
		}

		$cache_key = 'nera_aio_readme_' . md5( $repo );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		$res = wp_remote_get(
			'https://api.github.com/repos/' . $repo . '/readme',
			array(
				'timeout' => 12,
				'headers' => array(
					// Raw source: GitHub's html media type only renders Markdown,
					// but Nera plugins ship a WordPress readme.txt — fetch the
					// source and format it ourselves (see readme_to_html()).
					'Accept'     => 'application/vnd.github.raw',
					'User-Agent' => 'nera-all-in-one',
				),
			)
		);

		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			Logger::log( 'github readme fetch failed for ' . $repo );
			set_transient( $cache_key, '', self::README_FAIL_TTL );
			return '';
		}

		$html = wp_kses_post( self::readme_to_html( wp_remote_retrieve_body( $res ) ) );
		set_transient( $cache_key, $html, self::README_TTL );
		return $html;
	}

	/**
	 * Convert a WordPress-style readme.txt (or basic Markdown) to HTML.
	 *
	 * Self-contained, no dependencies. Handles the syntax Nera plugin readmes
	 * use: === / == / = headings, * and - and N. lists (with space-indented
	 * wrapped continuation), **bold**, *italic*, `inline code`, standalone
	 * backtick code blocks, [text](url) and bare-URL links. The leading
	 * "=== Title ===" line and the Key: Value metadata header are dropped (the
	 * modal head already shows name/version/deps).
	 *
	 * @param string $text Raw readme source.
	 * @return string HTML (caller wraps it in wp_kses_post()).
	 */
	private static function readme_to_html( $text ) {
		$text  = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		$lines = explode( "\n", $text );

		// Drop the leading "=== Title ===" line and the Key: Value header block.
		$start = 0;
		if ( isset( $lines[0] ) && preg_match( '/^===\s*.+\s*===$/', trim( $lines[0] ) ) ) {
			$start = 1;
		}
		for ( $i = $start; $i < count( $lines ); $i++ ) {
			$t = trim( $lines[ $i ] );
			if ( '' === $t ) {
				$start = $i + 1; // Skip the blank line that ends the header too.
				break;
			}
			if ( ! preg_match( '/^[A-Z][A-Za-z0-9 \/]*:\s/', $t ) ) {
				$start = $i; // First non-meta line: content starts here.
				break;
			}
		}
		$lines = array_slice( $lines, $start );

		$html      = '';
		$para      = array();   // Buffered plain-paragraph lines.
		$list_type = '';        // '', 'ul' or 'ol' — current open list.
		$list_buf  = array();   // Buffered <li> inner HTML strings.

		$flush_para = static function () use ( &$para, &$html ) {
			if ( $para ) {
				$html .= '<p>' . self::format_inline( implode( ' ', $para ) ) . '</p>';
				$para  = array();
			}
		};
		$flush_list = static function () use ( &$list_type, &$list_buf, &$html ) {
			if ( $list_type && $list_buf ) {
				$html .= '<' . $list_type . '>';
				foreach ( $list_buf as $li ) {
					$html .= '<li>' . self::format_inline( $li ) . '</li>';
				}
				$html .= '</' . $list_type . '>';
			}
			$list_type = '';
			$list_buf  = array();
		};

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );

			// Blank line: close the current paragraph and list.
			if ( '' === $trimmed ) {
				$flush_para();
				$flush_list();
				continue;
			}

			// Headings: WP readme (=== / == / =) and Markdown (#, ##, ###).
			if ( preg_match( '/^===\s*(.+?)\s*===$/', $trimmed, $m )
				|| preg_match( '/^#\s+(.+)$/', $trimmed, $m ) ) {
				$flush_para();
				$flush_list();
				$html .= '<h2>' . self::format_inline( $m[1] ) . '</h2>';
				continue;
			}
			if ( preg_match( '/^==\s*(.+?)\s*==$/', $trimmed, $m )
				|| preg_match( '/^##\s+(.+)$/', $trimmed, $m ) ) {
				$flush_para();
				$flush_list();
				$html .= '<h2>' . self::format_inline( $m[1] ) . '</h2>';
				continue;
			}
			if ( preg_match( '/^=\s*(.+?)\s*=$/', $trimmed, $m )
				|| preg_match( '/^###\s+(.+)$/', $trimmed, $m ) ) {
				$flush_para();
				$flush_list();
				$html .= '<h3>' . self::format_inline( $m[1] ) . '</h3>';
				continue;
			}

			// Standalone backtick-wrapped line → code block.
			if ( preg_match( '/^`(.+)`$/', $trimmed, $m ) ) {
				$flush_para();
				$flush_list();
				$html .= '<pre><code>' . esc_html( $m[1] ) . '</code></pre>';
				continue;
			}

			// List items: "* " / "- " (unordered) or "N. " (ordered).
			if ( preg_match( '/^\s*[\*\-]\s+(.+)$/', $line, $m ) ) {
				$flush_para();
				if ( 'ul' !== $list_type ) {
					$flush_list();
					$list_type = 'ul';
				}
				$list_buf[] = $m[1];
				continue;
			}
			if ( preg_match( '/^\s*\d+\.\s+(.+)$/', $line, $m ) ) {
				$flush_para();
				if ( 'ol' !== $list_type ) {
					$flush_list();
					$list_type = 'ol';
				}
				$list_buf[] = $m[1];
				continue;
			}

			// Space-indented continuation of the current list item (wrapped line).
			if ( $list_type && $list_buf && preg_match( '/^\s+\S/', $line ) ) {
				$list_buf[ count( $list_buf ) - 1 ] .= ' ' . $trimmed;
				continue;
			}

			// Otherwise: plain paragraph text (reflowed).
			$flush_list();
			$para[] = $trimmed;
		}

		$flush_para();
		$flush_list();

		return $html;
	}

	/**
	 * Apply inline formatting to an escaped segment: code spans, links, bold,
	 * italic. The segment is HTML-escaped first (the README source is
	 * untrusted), then markup is layered on. Code spans are tokenised before
	 * bold/italic so emphasis never forms inside code.
	 *
	 * @param string $s Raw (unescaped) inline text.
	 * @return string HTML.
	 */
	private static function format_inline( $s ) {
		$s = esc_html( (string) $s );

		// Tokenise `code` spans so later passes can't touch their contents.
		$codes = array();
		$s     = preg_replace_callback(
			'/`([^`]+)`/',
			static function ( $m ) use ( &$codes ) {
				$token            = "\0CODE" . count( $codes ) . "\0";
				$codes[ $token ]  = '<code>' . $m[1] . '</code>';
				return $token;
			},
			$s
		);

		// Markdown links [text](url) — url already HTML-escaped, re-clean via esc_url.
		$s = preg_replace_callback(
			'/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',
			static function ( $m ) {
				return '<a href="' . esc_url( html_entity_decode( $m[2] ) ) . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>';
			},
			$s
		);

		// Bare URL autolink (avoid ones already inside an href="...").
		$s = preg_replace_callback(
			'/(^|[\s(])(https?:\/\/[^\s<)]+)/',
			static function ( $m ) {
				return $m[1] . '<a href="' . esc_url( html_entity_decode( $m[2] ) ) . '" target="_blank" rel="noopener noreferrer">' . $m[2] . '</a>';
			},
			$s
		);

		// Emphasis: bold before italic.
		$s = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s );
		$s = preg_replace( '/(?<![\*\w])[\*_]([^\*_]+)[\*_](?![\*\w])/', '<em>$1</em>', $s );

		// Restore code spans.
		if ( $codes ) {
			$s = strtr( $s, $codes );
		}

		return $s;
	}
}
