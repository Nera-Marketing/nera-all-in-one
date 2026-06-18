<?php
/**
 * Ed25519 signed license key verification (offline, no server).
 *
 * A key is "<base64url(payloadJSON)>.<base64url(signature)>". The payload is a
 * JSON object: { "tier": "pro", "exp": 1750000000, "iss": "nera" }. Nera signs
 * the payload bytes with a private key kept off-site; this class verifies the
 * signature against the embedded public key (NERA_AIO_LICENSE_PUBKEY), so the
 * tier cannot be forged even if this source is public.
 *
 * @package Nera\AllInOne\License
 */

namespace Nera\AllInOne\License;

defined( 'ABSPATH' ) || exit;

/**
 * Signed_Key — stateless verifier for offline license keys.
 */
final class Signed_Key {

	/**
	 * Inspect a license key string.
	 *
	 * @param string $key Raw key as entered by the user.
	 * @return array {
	 *     @type bool        $ok      Whether the signature verified against the embedded key.
	 *     @type string|null $tier    Tier claimed by the payload (only when $ok).
	 *     @type bool        $expired Whether a present 'exp' has passed (only when $ok).
	 * }
	 */
	public static function inspect( $key ) {
		$fail = array(
			'ok'      => false,
			'tier'    => null,
			'expired' => false,
		);

		$key = is_string( $key ) ? trim( $key ) : '';
		if ( '' === $key || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return $fail;
		}

		$pub = self::public_key();
		if ( '' === $pub ) {
			return $fail;
		}

		$parts = explode( '.', $key );
		if ( 2 !== count( $parts ) ) {
			return $fail;
		}

		$payload_json = self::b64url_decode( $parts[0] );
		$signature    = self::b64url_decode( $parts[1] );
		if ( false === $payload_json || false === $signature || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $signature ) ) {
			return $fail;
		}

		$verified = false;
		try {
			$verified = sodium_crypto_sign_verify_detached( $signature, $payload_json, $pub );
		} catch ( \Exception $e ) {
			return $fail;
		}

		if ( ! $verified ) {
			return $fail;
		}

		$payload = json_decode( $payload_json, true );
		if ( ! is_array( $payload ) ) {
			return $fail;
		}

		$tier    = isset( $payload['tier'] ) && is_string( $payload['tier'] ) ? $payload['tier'] : null;
		$expired = isset( $payload['exp'] ) && is_numeric( $payload['exp'] ) && (int) $payload['exp'] < time();

		return array(
			'ok'      => true,
			'tier'    => $tier,
			'expired' => $expired,
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Decode the embedded base64 public key to raw 32 bytes, or '' if invalid.
	 *
	 * @return string
	 */
	private static function public_key() {
		$b64 = defined( 'NERA_AIO_LICENSE_PUBKEY' ) ? (string) NERA_AIO_LICENSE_PUBKEY : '';
		if ( '' === $b64 ) {
			return '';
		}
		$raw = base64_decode( $b64, true );
		if ( false === $raw || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $raw ) ) {
			return '';
		}
		return $raw;
	}

	/**
	 * URL-safe base64 decode (no padding).
	 *
	 * @param string $data
	 * @return string|false
	 */
	private static function b64url_decode( $data ) {
		return base64_decode( strtr( $data, '-_', '+/' ), true );
	}
}
