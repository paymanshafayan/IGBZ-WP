<?php
namespace IGBZ\Suite\Support;

use IGBZ\Suite\Modules\RestApi\Push\DeviceRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 12 — the server side of the biometric signature contract (DESIGN-LEGAL-AUTH §7.6).
 *
 * Employer rule: the SERVER verifies the signature, never the app. The app only signs; every
 * claim it could make ("biometric passed") is worthless here until the signature itself
 * verifies against the key the device enrolled. The contract:
 *
 *   canonical = device_id | user_id | nonce | ts | payload_hash
 *   signature = HMAC-SHA256( canonical, device_key )
 *
 * with three independent gates: a freshness window on `ts`, single-use `nonce` (replay), and
 * the constant-time HMAC check. Failing any gate fails the request, and every attempt is
 * written to the security channel.
 */
final class BiometricGate {

	public function __construct(
		private DeviceRepository $devices,
		private Logger $logger
	) {}

	/**
	 * @param array{device_id:string,user_id:int,nonce:string,ts:int,payload_hash?:string,signature:string} $request
	 * @return array{ok:bool,error:string}
	 */
	public function verify( array $request ): array {
		$fail = function ( string $error ) use ( $request ): array {
			$this->logger->log(
				Logger::WARNING,
				'security',
				sprintf( 'Biometric signature refused: %s', $error ),
				[ 'device_id' => substr( (string) ( $request['device_id'] ?? '' ), 0, 32 ), 'user_id' => (int) ( $request['user_id'] ?? 0 ) ]
			);
			return [ 'ok' => false, 'error' => $error ];
		};

		$device_id = (string) ( $request['device_id'] ?? '' );
		$user_id   = (int) ( $request['user_id'] ?? 0 );
		$nonce     = (string) ( $request['nonce'] ?? '' );
		$ts        = (int) ( $request['ts'] ?? 0 );
		$signature = (string) ( $request['signature'] ?? '' );

		if ( '' === $device_id || $user_id <= 0 || '' === $nonce || $ts <= 0 || '' === $signature ) {
			return $fail( 'incomplete' );
		}

		$window = max( 30, igbz()->settings()->int( 'api.biometric_window_seconds', 300 ) );
		if ( abs( time() - $ts ) > $window ) {
			return $fail( 'stale_timestamp' );
		}

		$device = $this->devices->find( $device_id );
		if ( ! $device || (int) $device['user_id'] !== $user_id ) {
			return $fail( 'unknown_device' );
		}
		$key = Crypto::decrypt( (string) ( $device['signing_key'] ?? '' ) );
		if ( null === $key || '' === $key ) {
			return $fail( 'no_signing_key' );
		}

		// Single-use nonce: the second presentation of the same signed request is a replay.
		$nonce_key = 'igbz_biometric_nonce_' . md5( $nonce );
		if ( false !== get_transient( $nonce_key ) ) {
			return $fail( 'nonce_replay' );
		}

		$canonical = implode(
			'|',
			[ $device_id, (string) $user_id, $nonce, (string) $ts, (string) ( $request['payload_hash'] ?? '' ) ]
		);
		if ( ! Crypto::hmac_equals( Crypto::hmac( $canonical, $key ), $signature ) ) {
			return $fail( 'bad_signature' );
		}

		set_transient( $nonce_key, 1, 2 * $window );
		$this->logger->log( Logger::INFO, 'security', 'Biometric signature accepted', [ 'user_id' => $user_id ] );

		return [ 'ok' => true, 'error' => '' ];
	}
}
