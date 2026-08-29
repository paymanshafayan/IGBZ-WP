<?php
use IGBZ\Suite\Modules\RestApi\Push\DeviceRepository;
use IGBZ\Suite\Support\BiometricGate;
use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

/**
 * Phase 12: the server side of the biometric signature contract. The app never gets a pass —
 * only a signature the server itself recomputes against the enrolled key counts.
 */
final class BiometricGateTest extends TestCase {

	public const DEVICE_KEY = 'device-secret-key';

	private BiometricGate $gate;

	private function boot(): void {
		igbz_test_reset_settings();
		$GLOBALS['igbz_test_transients'] = [];

		$wpdb          = new class() extends wpdb {
			public function get_row( string $sql, $output = null ) {
				$this->queries[] = $sql;
				if ( ! preg_match( "/device_id = '([^']*)'/", $sql, $m ) ) {
					return null;
				}
				if ( 'dev-1' !== $m[1] ) {
					return null;
				}
				return [
					'id'          => 3,
					'device_id'   => 'dev-1',
					'user_id'     => 7,
					'tenant_id'   => 1,
					'signing_key' => Crypto::encrypt( BiometricGateTest::DEVICE_KEY ),
				];
			}
		};
		$GLOBALS['wpdb'] = $wpdb;

		$this->gate = new BiometricGate( new DeviceRepository( new Db() ), new Logger( igbz()->settings() ) );
	}

	private function signed_request( array $overrides = [] ): array {
		$base = [
			'device_id'    => 'dev-1',
			'user_id'      => 7,
			'nonce'        => 'nonce-' . wp_generate_uuid4(),
			'ts'           => time(),
			'payload_hash' => hash( 'sha256', 'users-export' ),
		];
		$req  = array_merge( $base, $overrides );
		if ( ! isset( $overrides['signature'] ) ) {
			$canonical         = implode( '|', [ $req['device_id'], (string) $req['user_id'], $req['nonce'], (string) $req['ts'], $req['payload_hash'] ] );
			$req['signature']  = Crypto::hmac( $canonical, self::DEVICE_KEY );
		}
		return $req;
	}

	public function run(): void {
		$this->valid_signature_passes_once();
		$this->replay_is_refused();
		$this->stale_timestamp_is_refused();
		$this->wrong_signature_is_refused();
		$this->wrong_user_is_refused();
	}

	private function valid_signature_passes_once(): void {
		$this->boot();
		$result = $this->gate->verify( $this->signed_request() );
		$this->assert_true( $result['ok'], 'a signature the server recomputes is accepted' );
	}

	private function replay_is_refused(): void {
		$this->boot();
		$request = $this->signed_request();
		$this->assert_true( $this->gate->verify( $request )['ok'], 'first presentation passes' );
		$replayed = $this->gate->verify( $request );
		$this->assert_false( $replayed['ok'], 'second presentation refused' );
		$this->assert_same( 'nonce_replay', $replayed['error'], 'replay reason recorded' );
	}

	private function stale_timestamp_is_refused(): void {
		$this->boot();
		$result = $this->gate->verify( $this->signed_request( [ 'ts' => time() - 3600 ] ) );
		$this->assert_same( 'stale_timestamp', $result['error'], 'an hour-old signature is outside the window' );
	}

	private function wrong_signature_is_refused(): void {
		$this->boot();
		$request = $this->signed_request();
		$request['signature'] = strrev( (string) $request['signature'] );
		$result               = $this->gate->verify( $request );
		$this->assert_same( 'bad_signature', $result['error'], 'a forged signature never passes' );
	}

	private function wrong_user_is_refused(): void {
		$this->boot();
		$result = $this->gate->verify( $this->signed_request( [ 'user_id' => 99 ] ) );
		$this->assert_same( 'unknown_device', $result['error'], 'another user cannot borrow the device row' );
	}
}
