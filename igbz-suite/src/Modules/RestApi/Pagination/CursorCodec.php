<?php
namespace IGBZ\Suite\Modules\RestApi\Pagination;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 67 — the opaque cursor.
 *
 * Mobile feeds paginate by cursor, not by page number: a page number shifts under the
 * client's feet the moment anything is inserted or deleted, which duplicates or skips
 * rows on every flaky refresh. A cursor names the last row the client actually saw, so
 * the next page starts exactly there no matter what happened in between.
 *
 * The token is deliberately opaque (base64url of a tiny JSON tuple ending in a unique
 * column): the client never constructs or interprets it, so the sort scheme can change
 * later without breaking anyone. It is not signed — it names a position, grants no
 * rights, and every row it leads to still passes the same tenant/ownership checks as
 * page one. Decoding is strict: anything malformed, foreign, or non-canonical is
 * rejected so a corrupted bookmark fails loudly (400) instead of silently feeding the
 * client the wrong slice.
 */
final class CursorCodec {

	public const KIND_ORDERS   = 'orders';
	public const KIND_PRODUCTS = 'products';
	public const KIND_WALLET   = 'wallet';
	public const KIND_LEDGER   = 'ledger';

	private const VERSION = 1;

	/**
	 * @param array<string,int|string> $position sort tuple values, keyed by short name (e.g. ['t' => ts, 'i' => id]); must end in a unique column.
	 */
	public static function encode( string $kind, array $position ): string {
		$payload = [ 'v' => self::VERSION, 'k' => $kind ];
		foreach ( $position as $key => $value ) {
			$payload['p'][ (string) $key ] = $value;
		}

		$json = (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		$json = str_replace( [ '+', '/', '=' ], [ '-', '_', '' ], base64_encode( $json ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		return $json;
	}

	/**
	 * Strict decode: returns the position tuple, or null when the cursor is not a
	 * canonical, well-formed cursor of the expected kind (the caller answers 400).
	 *
	 * @return array<string,int|string>|null
	 */
	public static function decode( string $cursor, string $kind ): ?array {
		$cursor = trim( $cursor );
		if ( '' === $cursor || strlen( $cursor ) > 512 ) {
			return null;
		}
		if ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $cursor ) ) {
			return null;
		}

		$b64 = str_replace( [ '-', '_' ], [ '+', '/' ], $cursor );
		$pad = strlen( $b64 ) % 4;
		if ( $pad ) {
			$b64 .= str_repeat( '=', 4 - $pad );
		}
		$json = base64_decode( $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $json ) {
			return null;
		}

		$payload = json_decode( $json, true );
		if ( ! is_array( $payload ) ) {
			return null;
		}
		if ( ( $payload['v'] ?? null ) !== self::VERSION || ( $payload['k'] ?? null ) !== $kind ) {
			return null;
		}
		$position = $payload['p'] ?? null;
		if ( ! is_array( $position ) || ! $position ) {
			return null;
		}

		foreach ( $position as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key ) {
				return null;
			}
			if ( ! is_int( $value ) && ! ( is_string( $value ) && $value !== '' ) ) {
				return null;
			}
		}

		// Canonical re-encode: a hand-edited tuple (extra keys, floats, reordering) is a
		// different token and must not silently address a different position.
		if ( self::encode( $kind, $position ) !== $cursor ) {
			return null;
		}

		return $position;
	}
}
