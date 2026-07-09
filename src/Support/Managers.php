<?php
/**
 * Manager identity resolution.
 *
 * "Managers" are the Nera service accounts — the only users allowed to mutate
 * anything in Nera All In One (toggle, install, remove, license actions).
 * Everyone else gets a read-only dashboard. Identity is matched by user_login
 * or user_email against a fixed allowlist, NOT by role/capability, so client
 * admins (who are full `administrator`s) hold no lever here and role-editor
 * plugins change nothing.
 *
 * Allowlist sources, in order:
 *   1. NERA_AIO_MANAGERS constant (wp-config.php, comma-separated logins or
 *      emails) — authoritative when defined.
 *   2. Hard-coded Nera defaults below.
 *
 * Deliberately NO public filter: any active plugin (client-installable) could
 * hook a filter and inject itself into the allowlist.
 *
 * @package Nera\AllInOne\Support
 */

namespace Nera\AllInOne\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Managers — stateless allowlist matcher.
 */
final class Managers {

	/**
	 * Default manager identifiers (logins and/or emails), used when the
	 * NERA_AIO_MANAGERS constant is not defined.
	 *
	 * @var string[]
	 */
	const DEFAULTS = array(
		'adm1n',
		'websites@neramarketing.co.uk',
	);

	/**
	 * The normalised allowlist of manager identifiers.
	 *
	 * @return string[] Lowercased, trimmed logins/emails.
	 */
	public static function allowlist() {
		if ( defined( 'NERA_AIO_MANAGERS' ) && is_string( NERA_AIO_MANAGERS ) && '' !== trim( NERA_AIO_MANAGERS ) ) {
			$raw = explode( ',', NERA_AIO_MANAGERS );
		} else {
			$raw = self::DEFAULTS;
		}

		$out = array();
		foreach ( $raw as $id ) {
			$id = strtolower( trim( (string) $id ) );
			if ( '' !== $id ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * Whether a user is a manager.
	 *
	 * WP-CLI is always a manager context — CLI access implies server access,
	 * and it must stay usable as the recovery path.
	 *
	 * @param \WP_User|null $user User to check; defaults to the current user.
	 * @return bool
	 */
	public static function is_manager( $user = null ) {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		if ( null === $user ) {
			$user = wp_get_current_user();
		}

		if ( ! ( $user instanceof \WP_User ) || 0 === (int) $user->ID ) {
			return false;
		}

		return self::matches_identity( $user->user_login, $user->user_email );
	}

	/**
	 * Whether a user id belongs to a manager account.
	 *
	 * @param int $user_id
	 * @return bool
	 */
	public static function is_manager_id( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return false;
		}
		return self::matches_identity( $user->user_login, $user->user_email );
	}

	/**
	 * Whether a login or email matches the allowlist (case-insensitive).
	 *
	 * @param string $login
	 * @param string $email
	 * @return bool
	 */
	public static function matches_identity( $login, $email ) {
		$list  = self::allowlist();
		$login = strtolower( trim( (string) $login ) );
		$email = strtolower( trim( (string) $email ) );

		return ( '' !== $login && in_array( $login, $list, true ) )
			|| ( '' !== $email && in_array( $email, $list, true ) );
	}
}
