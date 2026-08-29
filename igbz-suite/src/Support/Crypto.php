<?php
namespace IGBZ\Suite\Support;

defined( 'ABSPATH' ) || exit;

/**
 * AES-256-GCM helpers keyed off the WordPress salts, plus constant-time signature helpers
 * used by the social provider webhook.
 */
final class Crypto {

	public const MASK   = '••••••••••••';
	private const CIPHER = 'aes-256-gcm';

	private static function key(): string {
		$material = ( defined( 'IGBZ_ENCRYPTION_KEY' ) ? IGBZ_ENCRYPTION_KEY : '' )
			. ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' )
			. ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' );
		if ( '' === trim( $material ) ) {
			throw new \RuntimeException( 'IGBZ Suite: no key material available for encryption.' );
		}
		return hash( 'sha256', 'igbz|' . $material, true );
	}

	public static function encrypt( string $plain ): string {
		$iv  = random_bytes( 12 );
		$tag = '';
		$ct  = openssl_encrypt( $plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, 'igbz', 16 );
		if ( false === $ct ) {
			throw new \RuntimeException( 'IGBZ Suite: encryption failed.' );
		}
		return 'igbz1:' . base64_encode( $iv . $tag . $ct );
	}

	public static function decrypt( string $payload ): ?string {
		if ( ! str_starts_with( $payload, 'igbz1:' ) ) {
			return $payload; // value stored before encryption was enabled.
		}
		$raw = base64_decode( substr( $payload, 6 ), true );
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return null;
		}
		$iv    = substr( $raw, 0, 12 );
		$tag   = substr( $raw, 12, 16 );
		$ct    = substr( $raw, 28 );
		$plain = openssl_decrypt( $ct, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, 'igbz' );
		return false === $plain ? null : $plain;
	}

	public static function hmac( string $data, string $secret ): string {
		return hash_hmac( 'sha256', $data, $secret );
	}

	public static function hmac_equals( string $expected, string $given ): bool {
		return hash_equals( $expected, $given );
	}

	/** Cryptographically secure numeric code (OTP / PIN). Never uses mt_rand. */
	public static function numeric_code( int $digits = 6 ): string {
		$max  = ( 10 ** $digits ) - 1;
		$code = random_int( 0, $max );
		return str_pad( (string) $code, $digits, '0', STR_PAD_LEFT );
	}

	public static function token( int $bytes = 32 ): string {
		return bin2hex( random_bytes( $bytes ) );
	}
}
