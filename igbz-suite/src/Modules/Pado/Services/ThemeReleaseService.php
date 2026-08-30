<?php
namespace IGBZ\Suite\Modules\Pado\Services;

use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 61 — the release side of Pado's theme pipeline: a signed artefact, a
 * separate tenant-scoped preview, a structural visual comparison, and a real
 * rollback.
 *
 * Signing closes the time gap between "the gate judged these bytes" and "these
 * bytes go live": the zip is signed with the site's theme key the moment it is
 * stored (HMAC-SHA256 over the file's SHA-256 — Crypto module only, never
 * rand), and both the preview install and the live activation re-verify the
 * signature against the file on disk. A zip edited on disk after ingest — by
 * anything — is refused, whatever a human approved.
 *
 * The comparison is structural on purpose: it fetches the two renders (preview
 * vs live) and diffs their ordered block signatures, so a human sees WHAT
 * changed in the layout, not a pixel hash that breaks on every cache-buster.
 */
class ThemeReleaseService {

	private const KEY_OPTION = 'igbz_theme_signing_key';

	public function __construct( private Db $db, private Logger $logger ) {}

	/** The site's theme signing key, created once with the Crypto module. */
	public function signing_key(): string {
		$key = (string) get_option( self::KEY_OPTION, '' );
		if ( '' === $key || strlen( $key ) < 64 ) {
			$key = Crypto::token( 32 ); // 64 hex chars, cryptographically secure
			update_option( self::KEY_OPTION, $key, false );
		}
		return $key;
	}

	/**
	 * Sign a stored artefact and stamp the theme row's metadata.
	 *
	 * @param array<string,mixed> $row A igbz_themes row with id + zip_path.
	 * @return array{ok:bool,error:string,sha256:string,signature:string}
	 */
	public function sign( array $row ): array {
		$path = (string) ( $row['zip_path'] ?? '' );
		$id   = (int) ( $row['id'] ?? 0 );
		if ( $id <= 0 || '' === $path || ! is_readable( $path ) ) {
			return [ 'ok' => false, 'error' => 'artifact_missing', 'sha256' => '', 'signature' => '' ];
		}

		$sha256    = hash_file( 'sha256', $path );
		$signature = Crypto::hmac( $sha256, $this->signing_key() );

		$meta       = $this->metadata( $id );
		$meta['artifact'] = [
			'sha256'    => $sha256,
			'signature' => $signature,
			'signed_at' => current_time( 'mysql', true ),
		];
		$this->db->update( 'themes', [ 'metadata' => wp_json_encode( $meta, JSON_UNESCAPED_UNICODE ) ], [ 'id' => $id ] );

		$this->logger->info( 'pado', 'Theme artefact signed', [ 'theme' => $id, 'sha256' => $sha256 ] );
		return [ 'ok' => true, 'error' => '', 'sha256' => $sha256, 'signature' => $signature ];
	}

	/**
	 * Re-verify the stored artefact against the file on disk. Called at the
	 * preview install and again at live activation — the two time boundaries
	 * where trust must be re-earned.
	 *
	 * @param array<string,mixed> $row A igbz_themes row.
	 * @return array{ok:bool,error:string}
	 */
	public function verify( array $row ): array {
		$id   = (int) ( $row['id'] ?? 0 );
		$path = (string) ( $row['zip_path'] ?? '' );
		if ( $id <= 0 || '' === $path || ! is_readable( $path ) ) {
			return [ 'ok' => false, 'error' => 'artifact_missing' ];
		}

		$artifact = $this->metadata( $id )['artifact'] ?? [];
		if ( ! is_array( $artifact ) || '' === (string) ( $artifact['signature'] ?? '' ) ) {
			return [ 'ok' => false, 'error' => 'unsigned_artifact' ];
		}

		$sha256 = hash_file( 'sha256', $path );
		if ( ! Crypto::hmac_equals( (string) $artifact['signature'], Crypto::hmac( $sha256, $this->signing_key() ) ) ) {
			$this->logger->error( 'pado', 'Theme artefact failed its signature check', [ 'theme' => $id, 'stored' => (string) ( $artifact['sha256'] ?? '' ), 'actual' => $sha256 ] );
			return [ 'ok' => false, 'error' => 'signature_mismatch' ];
		}

		return [ 'ok' => true, 'error' => '' ];
	}

	/**
	 * Structural comparison of two renders: the ordered block signatures of
	 * each document, what the second adds, what it drops, what they share.
	 *
	 * @return array{ok:bool,a_blocks:string[],b_blocks:string[],added:string[],removed:string[],common:int}
	 */
	public function snapshot_diff( string $url_a, string $url_b ): array {
		$a = $this->block_signature( $this->fetch( $url_a ) );
		$b = $this->block_signature( $this->fetch( $url_b ) );

		return [
			'ok'       => $a !== [] && $b !== [],
			'a_blocks' => array_values( $a ),
			'b_blocks' => array_values( $b ),
			'added'    => array_values( array_diff( $b, $a ) ),
			'removed'  => array_values( array_diff( $a, $b ) ),
			'common'   => count( array_intersect( $a, $b ) ),
		];
	}

	/**
	 * The ordered signature of a rendered page: every block marker plus the
	 * headings — enough to answer "what changed in the layout" without boiling
	 * an ocean. Scripts, styles and the admin bar are stripped first.
	 *
	 * @return array<int,string>
	 */
	public function block_signature( string $html ): array {
		$html = preg_replace( '#<script\b.*?</script>#is', '', $html ) ?? $html;
		$html = preg_replace( '#<style\b.*?</style>#is', '', $html ) ?? $html;
		$html = preg_replace( '#<div id="wpadminbar".*?</div>\s*</div>#is', '', $html ) ?? $html;

		$found = [];
		if ( preg_match_all( '#<!--\s*(wp:[a-z0-9/-]+)#i', $html, $m ) ) {
			foreach ( $m[1] as $marker ) {
				$found[] = 'block:' . strtolower( $marker );
			}
		}
		if ( preg_match_all( '#<h([1-3])[^>]*>(.*?)</h\1>#is', $html, $h ) ) {
			foreach ( $h[2] as $i => $text ) {
				$clean = trim( preg_replace( '#<[^>]+>#', '', $text ) ?? '' );
				if ( '' !== $clean ) {
					$found[] = 'h' . $h[1][ $i ] . ':' . mb_substr( $clean, 0, 60 );
				}
			}
		}
		return array_values( array_unique( $found ) );
	}

	/** @return array<string,mixed> */
	private function metadata( int $theme_id ): array {
		$raw = (string) $this->db->scalar( 'SELECT metadata FROM ' . $this->db->table( 'themes' ) . ' WHERE id = %d', $theme_id );
		$decoded = '' !== $raw ? json_decode( $raw, true ) : null;
		return is_array( $decoded ) ? $decoded : [];
	}

	// ------------------------------------------------- environment seams

	/**
	 * Fetch a rendered page. The network boundary, overridable in tests.
	 *
	 * Phase 75: the URL crosses the SSRF gate like every other outbound request —
	 * a render-compare URL that has drifted to an internal address returns empty
	 * (an empty signature fails the comparison honestly) instead of reaching it.
	 */
	protected function fetch( string $url ): string {
		if ( ! \IGBZ\Suite\Support\UrlGuard::is_safe( $url ) ) {
			$this->logger->warning( 'pado', 'Render-compare URL blocked by the SSRF guard', [ 'host' => (string) wp_parse_url( $url, PHP_URL_HOST ) ] );
			return '';
		}
		$response = wp_remote_get( $url, [ 'timeout' => 30 ] );
		return is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response );
	}
}
