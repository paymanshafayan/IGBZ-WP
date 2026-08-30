<?php
namespace IGBZ\Suite\Support\Backup;

use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 72 — the encrypted backup bundle.
 *
 * Format v1 (all inside one AES-256-GCM envelope, Crypto::encrypt):
 *
 *   igbzbk1:<base64 ciphertext>
 *   plaintext = wp_json_encode({
 *     v: 1,
 *     manifest: { created_at, generator, db:{tables,rows,sql_sha256},
 *                 settings_sha256, files:[{path,sha256,bytes}], skipped:[{path,reason}] },
 *     sql:      "-- IGBZ logical dump" INSERT statements for the suite tables,
 *     settings: raw settings JSON (the bundle is encrypted; secrets never ride plaintext),
 *     files:    { relative path => base64 contents } for uploads below the size cap.
 *   })
 *
 * Every member carries a sha256 in the manifest, so restore can prove what it
 * is about to apply instead of trusting the file's good intentions.
 */
final class Bundle {

	public const MAGIC = 'igbzbk1:';
	public const VERSION = 1;

	private function __construct() {}

	/** @param array<string,mixed> $payload */
	public static function encode( array $payload ): string {
		return self::MAGIC . Crypto::encrypt( (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ) );
	}

	/** @return array{v:int,manifest:array<string,mixed>,sql:string,settings:string,files:array<string,string>}|null */
	public static function decode( string $blob ): ?array {
		if ( ! str_starts_with( $blob, self::MAGIC ) ) {
			return null;
		}
		$plain = Crypto::decrypt( substr( $blob, strlen( self::MAGIC ) ) );
		if ( null === $plain ) {
			return null;
		}
		$payload = json_decode( $plain, true );
		if ( ! is_array( $payload ) || (int) ( $payload['v'] ?? 0 ) !== self::VERSION ) {
			return null;
		}
		return $payload;
	}

	/**
	 * Verify every checksum the manifest promises. Returns the list of broken
	 * members (empty = intact).
	 *
	 * @param array{manifest:array<string,mixed>,sql:string,settings:string,files:array<string,string>} $payload
	 * @return array<int,string>
	 */
	public static function integrity_errors( array $payload ): array {
		$manifest = $payload['manifest'];
		$errors   = [];

		$db = $manifest['db'] ?? [];
		if ( isset( $db['sql_sha256'] ) && ! self::matches( (string) $db['sql_sha256'], $payload['sql'] ?? '' ) ) {
			$errors[] = 'sql checksum mismatch';
		}
		if ( isset( $manifest['settings_sha256'] ) && ! self::matches( (string) $manifest['settings_sha256'], $payload['settings'] ?? '' ) ) {
			$errors[] = 'settings checksum mismatch';
		}
		foreach ( ( $manifest['files'] ?? [] ) as $file ) {
			$path = (string) ( $file['path'] ?? '' );
			$raw  = base64_decode( (string) ( $payload['files'][ $path ] ?? '' ), true );
			if ( false === $raw || ! self::matches( (string) ( $file['sha256'] ?? '' ), $raw ) ) {
				$errors[] = 'file checksum mismatch: ' . $path;
			}
		}
		return $errors;
	}

	private static function matches( string $expected, string $actual ): bool {
		return hash_equals( $expected, hash( 'sha256', $actual ) );
	}

	/** Manifest helper: describe one member file. @return array<string,int|string> */
	public static function file_entry( string $path, string $raw ): array {
		return [ 'path' => $path, 'sha256' => hash( 'sha256', $raw ), 'bytes' => strlen( $raw ) ];
	}

	/** Escape one value for the SQL dump (mirrors wpdb-ish quoting, driver-agnostic). */
	public static function sql_value( mixed $value ): string {
		if ( null === $value ) {
			return 'NULL';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		return "'" . str_replace( [ '\\', "'", "\0", "\n", "\r" ], [ '\\\\', "\\'", '\\0', '\\n', '\\r' ], (string) $value ) . "'";
	}
}
