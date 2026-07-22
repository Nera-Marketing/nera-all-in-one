<?php
/**
 * Update-check refresher: manual "Check for updates" + scheduled auto-check.
 *
 * AIO derives "update available" from a direct GitHub /releases query cached for
 * one hour per repo (see Installer). On its own that cache only refreshes lazily
 * (on the next page load after it expires) and after an AIO-driven install, so a
 * newly published release can stay invisible for up to an hour with no way to
 * force a re-check. This adds:
 *   - a manager-only AJAX endpoint (nera_aio_refresh_updates) behind the Manage
 *     page's "Check for updates" button, which flushes the release caches so the
 *     next render re-fetches fresh data;
 *   - a twice-daily cron event that flushes and re-warms every managed repo's
 *     release cache, so new releases are picked up without anyone clicking.
 *
 * Registered unconditionally (not only in admin) so the cron callback exists
 * during frontend wp-cron runs.
 *
 * @package Nera\AllInOne\Admin
 */

namespace Nera\AllInOne\Admin;

use Nera\AllInOne\Install\Installer;
use Nera\AllInOne\Support\Managers;

defined( 'ABSPATH' ) || exit;

/**
 * Update_Refresher — manual + scheduled release-cache refresh.
 */
final class Update_Refresher {

	const CRON_HOOK   = 'nera_aio_refresh_updates_event';
	const AJAX_ACTION = 'nera_aio_refresh_updates';
	const SCHEDULE    = 'twicedaily';

	/**
	 * Register hooks. Safe to call once per request; idempotent scheduling.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_scheduled_refresh' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'handle_manual_refresh' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::CRON_HOOK );
		}

		if ( defined( 'NERA_AIO_FILE' ) ) {
			register_deactivation_hook( NERA_AIO_FILE, array( __CLASS__, 'unschedule' ) );
		}
	}

	/**
	 * The GitHub repos AIO currently manages (catalog defaults + link-installed).
	 *
	 * @return string[]
	 */
	private static function managed_repos() {
		$repos = array();
		if ( ! class_exists( __NAMESPACE__ . '\\Plugin_Catalog' ) ) {
			return $repos;
		}
		foreach ( Plugin_Catalog::items() as $entry ) {
			if ( ! empty( $entry['gh_repo'] ) ) {
				$repos[] = (string) $entry['gh_repo'];
			}
		}
		return array_values( array_unique( $repos ) );
	}

	/**
	 * AJAX: flush the managed repos' release caches on demand (managers only).
	 * The page then reloads and re-fetches fresh release data.
	 *
	 * @return void
	 */
	public static function handle_manual_refresh() {
		check_ajax_referer( self::AJAX_ACTION );

		if ( ! Managers::is_manager() ) {
			wp_send_json_error( array( 'message' => __( 'Only the Nera service account can manage plugins.', 'nera-all-in-one' ) ), 403 );
		}

		Installer::flush_repos( self::managed_repos() );

		wp_send_json_success( array( 'message' => __( 'Update check refreshed.', 'nera-all-in-one' ) ) );
	}

	/**
	 * Cron: flush and re-warm every managed repo's release cache so newly
	 * published releases are detected on schedule.
	 *
	 * @return void
	 */
	public static function run_scheduled_refresh() {
		$repos = self::managed_repos();
		Installer::flush_repos( $repos );
		foreach ( $repos as $repo ) {
			// Re-populate the cache so the next admin view is already fresh.
			Installer::releases( $repo );
		}
	}

	/**
	 * Clear the scheduled event (plugin deactivation).
	 *
	 * @return void
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		while ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}
}
