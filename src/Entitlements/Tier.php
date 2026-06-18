<?php
/**
 * @package Nera\AllInOne
 */

namespace Nera\AllInOne\Entitlements;

defined( 'ABSPATH' ) || exit;

/**
 * Tier constants + ranking. FROZEN CONTRACT — do not change values; modules and
 * the central server both key off these strings.
 */
final class Tier {

	const FREE   = 'free';
	const PRO    = 'pro';
	const AGENCY = 'agency';

	/**
	 * Numeric rank for comparisons. free=0, pro=1, agency=2. Unknown → 0.
	 *
	 * @param string $tier
	 * @return int
	 */
	public static function rank( $tier ) {
		switch ( $tier ) {
			case self::AGENCY:
				return 2;
			case self::PRO:
				return 1;
			case self::FREE:
			default:
				return 0;
		}
	}

	/**
	 * @return string[]
	 */
	public static function all() {
		return array( self::FREE, self::PRO, self::AGENCY );
	}

	/**
	 * Whether a string is a recognised tier.
	 *
	 * @param mixed $tier
	 * @return bool
	 */
	public static function is_valid( $tier ) {
		return is_string( $tier ) && in_array( $tier, self::all(), true );
	}
}
