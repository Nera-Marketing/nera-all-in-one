<?php
/**
 * Hard protection around the manager account and the AIO plugin itself.
 *
 * Two attack surfaces this closes for non-manager users (typically client
 * admins, who are full `administrator`s):
 *
 *   1. Account takeover — editing the manager's password/email/role, deleting
 *      the account, or "identity squatting" (creating/updating another user to
 *      the manager login/email on a site where it is unclaimed; admin-initiated
 *      email changes of other users skip WP's confirmation flow).
 *   2. Disabling the gate — deactivating Nera All In One would un-hide the
 *      Nera plugin rows and give back native activate/deactivate control.
 *
 * Registered unconditionally from Plugin::boot() (not boot_admin) so the
 * checks also cover REST (/wp/v2/users) and XML-RPC. Everything is skipped
 * under WP-CLI, which is the trusted recovery path.
 *
 * @package Nera\AllInOne\Support
 */

namespace Nera\AllInOne\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Guard — capability and user-data filters protecting the manager model.
 */
final class Guard {

	/**
	 * AIO's own plugin basename (protected from deactivation by non-managers).
	 */
	const SELF = 'nera-all-in-one/nera-all-in-one.php';

	/**
	 * Register all guard filters. Called once from Plugin::boot().
	 *
	 * @return void
	 */
	public static function init() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		add_filter( 'map_meta_cap', array( __CLASS__, 'filter_map_meta_cap' ), 10, 4 );
		add_filter( 'wp_pre_insert_user_data', array( __CLASS__, 'filter_user_data' ), 10, 3 );
		add_filter( 'illegal_user_logins', array( __CLASS__, 'filter_illegal_logins' ) );
	}

	/**
	 * Deny protected meta caps to non-managers.
	 *
	 * Returns array('do_not_allow') rather than stripping caps so multisite
	 * super admins are bound too (is_super_admin() bypasses plain cap checks).
	 *
	 * @param string[] $caps    Primitive caps required.
	 * @param string   $cap     Meta cap being mapped.
	 * @param int      $user_id User the check is for.
	 * @param array    $args    Context args (target user id / plugin file).
	 * @return string[]
	 */
	public static function filter_map_meta_cap( $caps, $cap, $user_id, $args ) {
		switch ( $cap ) {
			// Manager account: no edit (covers password/email), delete, role
			// change, or multisite removal by non-managers.
			case 'edit_user':
			case 'delete_user':
			case 'promote_user':
			case 'remove_user':
				$target = isset( $args[0] ) ? (int) $args[0] : 0;
				if ( $target && Managers::is_manager_id( $target ) && ! self::actor_is_manager( $user_id ) ) {
					return array( 'do_not_allow' );
				}
				break;

			// AIO itself: non-managers cannot deactivate it. Deletion is
			// unreachable without deactivation (WP refuses to delete active
			// plugins). 'activate_plugin' is intentionally NOT denied so a
			// client can re-activate AIO if a fatal auto-deactivated it.
			case 'deactivate_plugin':
				if ( isset( $args[0] ) && self::SELF === $args[0] && ! self::actor_is_manager( $user_id ) ) {
					return array( 'do_not_allow' );
				}
				break;
		}

		return $caps;
	}

	/**
	 * Anti-identity-squatting chokepoint.
	 *
	 * wp_pre_insert_user_data fires on both insert and update, across the
	 * admin UI, REST, XML-RPC and importers. If the resulting row would carry
	 * a manager login or email, only a manager (or the already-recognized
	 * manager account itself) may save it. Returning an empty array makes
	 * wp_insert_user() bail with a WP_Error instead of saving.
	 *
	 * @param array          $data   Slashed user row about to be written.
	 * @param bool           $update Whether this is an update.
	 * @param int|null       $user_id Target user id on update, null on insert.
	 * @return array
	 */
	public static function filter_user_data( $data, $update, $user_id ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$login = isset( $data['user_login'] ) ? wp_unslash( $data['user_login'] ) : '';
		$email = isset( $data['user_email'] ) ? wp_unslash( $data['user_email'] ) : '';

		if ( ! Managers::matches_identity( $login, $email ) ) {
			return $data;
		}

		// Updating the existing manager account (e.g. the manager editing
		// their own profile, or cron touching it) is fine.
		if ( $update && $user_id && Managers::is_manager_id( (int) $user_id ) ) {
			return $data;
		}

		if ( Managers::is_manager() ) {
			return $data;
		}

		Logger::log( 'blocked attempt to claim manager identity: ' . $login . ' / ' . $email );
		return array();
	}

	/**
	 * Register manager logins as illegal for the core registration/creation
	 * forms (belt-and-braces alongside filter_user_data()).
	 *
	 * @param string[] $logins
	 * @return string[]
	 */
	public static function filter_illegal_logins( $logins ) {
		if ( Managers::is_manager() ) {
			return $logins;
		}

		foreach ( Managers::allowlist() as $id ) {
			if ( false === strpos( $id, '@' ) ) {
				$logins[] = $id;
			}
		}
		return $logins;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Whether the acting user id (from a cap check) is a manager.
	 *
	 * @param int $user_id
	 * @return bool
	 */
	private static function actor_is_manager( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return false;
		}
		return Managers::is_manager_id( $user_id );
	}
}
