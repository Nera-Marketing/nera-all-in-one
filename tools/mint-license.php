<?php
/**
 * Offline license-key tool for Nera All In One.
 *
 * NOT part of the plugin runtime. Run from the CLI by Nera staff only.
 *
 * Usage:
 *   php tools/mint-license.php keygen
 *       Generate a one-time Ed25519 keypair. Prints the PUBLIC key (base64) to
 *       paste into NERA_AIO_LICENSE_PUBKEY, and writes the PRIVATE key to
 *       tools/.license-private.key (gitignored — keep it secret).
 *
 *   php tools/mint-license.php mint --tier=pro [--days=365] [--iss=nera]
 *       Sign and print a license key for the given tier. Reads the private key
 *       from tools/.license-private.key.
 *
 * @package Nera\AllInOne
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "CLI only.\n" );
	exit( 1 );
}
if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
	fwrite( STDERR, "libsodium not available in this PHP build.\n" );
	exit( 1 );
}

$key_path = __DIR__ . '/.license-private.key';
$cmd      = isset( $argv[1] ) ? $argv[1] : '';

/**
 * Parse --key=value flags into an assoc array.
 *
 * @param array $argv
 * @return array
 */
function nera_parse_flags( array $argv ) {
	$out = array();
	foreach ( array_slice( $argv, 2 ) as $a ) {
		if ( preg_match( '/^--([a-z0-9_-]+)=(.*)$/i', $a, $m ) ) {
			$out[ $m[1] ] = $m[2];
		}
	}
	return $out;
}

/**
 * URL-safe base64 without padding.
 *
 * @param string $bin
 * @return string
 */
function nera_b64url( $bin ) {
	return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
}

switch ( $cmd ) {
	case 'keygen':
		$pair = sodium_crypto_sign_keypair();
		$priv = sodium_crypto_sign_secretkey( $pair );
		$pub  = sodium_crypto_sign_publickey( $pair );

		file_put_contents( $key_path, base64_encode( $priv ) );
		chmod( $key_path, 0600 );

		echo "Keypair generated.\n\n";
		echo "PUBLIC KEY (paste into NERA_AIO_LICENSE_PUBKEY):\n";
		echo base64_encode( $pub ) . "\n\n";
		echo "Private key written to: $key_path  (KEEP SECRET — do not commit)\n";
		break;

	case 'mint':
		if ( ! is_readable( $key_path ) ) {
			fwrite( STDERR, "No private key at $key_path — run 'keygen' first.\n" );
			exit( 1 );
		}
		$flags = nera_parse_flags( $argv );
		$tier  = isset( $flags['tier'] ) ? $flags['tier'] : '';
		if ( ! in_array( $tier, array( 'free', 'pro', 'agency' ), true ) ) {
			fwrite( STDERR, "--tier must be free|pro|agency\n" );
			exit( 1 );
		}

		$priv    = base64_decode( trim( file_get_contents( $key_path ) ), true );
		$payload = array(
			'tier' => $tier,
			'iss'  => isset( $flags['iss'] ) ? $flags['iss'] : 'nera',
		);
		if ( isset( $flags['days'] ) && (int) $flags['days'] > 0 ) {
			$payload['exp'] = time() + ( (int) $flags['days'] * DAY_IN_SECONDS_FALLBACK() );
		}

		$json = json_encode( $payload, JSON_UNESCAPED_SLASHES );
		$sig  = sodium_crypto_sign_detached( $json, $priv );
		$key  = nera_b64url( $json ) . '.' . nera_b64url( $sig );

		echo $key . "\n";
		break;

	default:
		fwrite( STDERR, "Usage: php tools/mint-license.php keygen | mint --tier=pro [--days=365] [--iss=nera]\n" );
		exit( 1 );
}

/**
 * DAY_IN_SECONDS without WordPress loaded.
 *
 * @return int
 */
function DAY_IN_SECONDS_FALLBACK() {
	return 86400;
}
