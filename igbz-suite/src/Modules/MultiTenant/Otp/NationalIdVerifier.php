<?php
namespace IGBZ\Suite\Modules\MultiTenant\Otp;

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Shahkar national-id verification. Only active once the senior admin has
 * stored legal.shahkar_api_key; otherwise the payment-time national-id
 * check stays locked (admins then must accept the legal digital waiver).
 *
 * Phase 34 hardening — the three outcomes are told apart, because conflating them is a legal
 * hazard in both directions:
 *
 *  - `matched`     the registry says the phone and national id belong to one person;
 *  - `mismatch`    the registry answered and said they do not — recorded as evidence;
 *  - `error`       Shahkar itself failed (timeout, bad body, refusal) — the user is NOT marked
 *                  mismatched, the attempt is recorded as `error` and the caller may retry.
 *
 * PII discipline: only the SHA-256 hash of the national id ever touches the database; the phone
 * travels in the request and is never persisted here. The raw national id is never logged.
 */
final class NationalIdVerifier {

	public const RESULT_MATCHED  = 'matched';
	public const RESULT_MISMATCH = 'mismatch';
	public const RESULT_ERROR    = 'error';

	public function __construct(
		private Db $db,
		private Http $http
	) {}

	public function is_available(): bool {
		return '' !== igbz()->settings()->string( 'legal.shahkar_api_key' );
	}

	/**
	 * Iranian national code checksum (ISO 7064 mod 11, the official 10-digit form).
	 */
	public static function valid_national_id( string $national_id ): bool {
		if ( ! preg_match( '/^\d{10}$/', $national_id ) ) {
			return false;
		}
		if ( preg_match( '/^(\d)\1{9}$/', $national_id ) ) {
			return false; // 0000000000, 1111111111, ...
		}
		$sum = 0;
		for ( $i = 0; $i < 9; ++$i ) {
			$sum += (int) $national_id[ $i ] * ( 10 - $i );
		}
		$remainder = $sum % 11;
		$check     = (int) $national_id[9];
		return ( $remainder < 2 && $check === $remainder ) || ( $remainder >= 2 && $check === 11 - $remainder );
	}

	/**
	 * Classify a Shahkar round-trip without touching user state.
	 *
	 * @param array<string,mixed> $body Decoded response body.
	 * @return string one of the RESULT_* constants
	 */
	public static function classify_response( bool $http_ok, string $error_message, array $body ): string {
		if ( ! $http_ok || [] === $body ) {
			return self::RESULT_ERROR;
		}
		$matched = $body['matched'] ?? $body['status'] ?? null;
		if ( true === $matched || 'matched' === $matched || 'true' === $matched || 1 === $matched || '1' === $matched ) {
			return self::RESULT_MATCHED;
		}
		if ( false === $matched || 'mismatch' === $matched || 'false' === $matched || 0 === $matched || '0' === $matched ) {
			return self::RESULT_MISMATCH;
		}
		return self::RESULT_ERROR; // The registry did not give a verdict — never guess.
	}

	/**
	 * @return array{ok:bool,ref:string,error:string,status:string}
	 */
	public function verify( int $user_id, string $phone, string $national_id ): array {
		if ( ! $this->is_available() ) {
			return [ 'ok' => false, 'ref' => '', 'error' => __( 'National-id verification is not activated (no Shahkar key).', 'igbz-suite' ), 'status' => self::RESULT_ERROR ];
		}
		if ( ! self::valid_national_id( $national_id ) ) {
			return [ 'ok' => false, 'ref' => '', 'error' => __( 'The national id format is not valid.', 'igbz-suite' ), 'status' => self::RESULT_ERROR ];
		}
		if ( ! preg_match( '/^09\d{9}$/', $phone ) ) {
			return [ 'ok' => false, 'ref' => '', 'error' => __( 'The mobile number format is not valid.', 'igbz-suite' ), 'status' => self::RESULT_ERROR ];
		}
		if ( $this->attempts_today( $user_id ) >= $this->max_attempts_per_day() ) {
			return [ 'ok' => false, 'ref' => '', 'error' => __( 'Too many verification attempts today. Try again tomorrow.', 'igbz-suite' ), 'status' => self::RESULT_ERROR ];
		}

		$base     = rtrim( igbz()->settings()->string( 'legal.shahkar_base_url' ), '/' );
		$response = $this->http->post(
			$base . '/v1/identity/match',
			[
				'json'    => [ 'phone' => $phone, 'national_id' => $national_id ],
				'headers' => [ 'Authorization' => 'Bearer ' . igbz()->settings()->string( 'legal.shahkar_api_key' ), 'Accept' => 'application/json' ],
				'channel' => 'otp',
				'timeout' => 25,
			]
		);
		$body   = $response->json();
		$status = self::classify_response( $response->ok(), $response->error_message(), $body );

		// Every attempt is recorded — hash only, never the raw id.
		$this->db->insert(
			'ig_nid_verifications',
			[
				'tenant_id'        => (int) igbz()->tenancy()->id(),
				'user_id'          => $user_id,
				'national_id_hash' => hash( 'sha256', $national_id ),
				'status'           => $status,
				'ref'              => (string) ( $body['ref'] ?? '' ),
				'created_at'       => current_time( 'mysql', true ),
			]
		);

		if ( self::RESULT_MATCHED === $status ) {
			return [ 'ok' => true, 'ref' => (string) ( $body['ref'] ?? '' ), 'error' => '', 'status' => $status ];
		}
		if ( self::RESULT_MISMATCH === $status ) {
			return [ 'ok' => false, 'ref' => '', 'error' => __( 'National id and phone do not match.', 'igbz-suite' ), 'status' => $status ];
		}
		return [ 'ok' => false, 'ref' => '', 'error' => __( 'The verification service did not answer; try again later.', 'igbz-suite' ), 'status' => $status ];
	}

	/** Phase 34: how many Shahkar attempts one user gets per day (registry cost + abuse guard). */
	public function max_attempts_per_day(): int {
		return max( 1, (int) igbz()->settings()->get( 'legal.shahkar_max_attempts_per_day', 5 ) );
	}

	private function attempts_today( int $user_id ): int {
		return (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'ig_nid_verifications' ) . ' WHERE user_id = %d AND created_at >= %s',
			$user_id,
			gmdate( 'Y-m-d H:i:s', strtotime( gmdate( 'Y-m-d' ) ) )
		);
	}
}
