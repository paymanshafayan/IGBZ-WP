<?php
use IGBZ\Suite\Support\Http;
use IGBZ\Suite\Support\Logger;
use IGBZ\Suite\Support\UrlGuard;

/**
 * Phase 10 (SSRF): the outbound gate. Literal IPs need no resolver, so every case here is
 * deterministic inside the sandbox; name-resolution behaviour is a production path and is
 * documented rather than faked.
 */
final class UrlGuardTest extends TestCase {

	public function run(): void {
		$this->schemes_and_hosts();
		$this->forbidden_ranges();
		$this->http_blocks_before_transport();
	}

	private function schemes_and_hosts(): void {
		$this->assert_false( UrlGuard::is_safe( 'file:///etc/passwd' ), 'file scheme refused' );
		$this->assert_false( UrlGuard::is_safe( 'ftp://example.com/x' ), 'ftp scheme refused' );
		$this->assert_false( UrlGuard::is_safe( 'gopher://example.com/' ), 'gopher scheme refused' );
		$this->assert_false( UrlGuard::is_safe( 'https://' ), 'missing host refused' );
		$this->assert_true( UrlGuard::is_safe( 'https://gateway.test/api' ), 'reserved .test transport passes by name' );
		$this->assert_true( UrlGuard::is_safe( 'https://8.8.8.8/ok' ), 'public literal IP passes' );
	}

	private function forbidden_ranges(): void {
		foreach ( [
			'127.0.0.1'       => 'loopback',
			'10.0.0.5'        => 'RFC-1918 /8',
			'172.16.0.1'      => 'RFC-1918 /12 lower edge',
			'172.31.255.254'  => 'RFC-1918 /12 upper edge',
			'192.168.1.1'     => 'RFC-1918 /16',
			'169.254.169.254' => 'cloud metadata',
			'0.0.0.0'         => 'unspecified',
			'100.64.0.1'      => 'CGNAT',
			'::1'             => 'IPv6 loopback',
			'fd12::1'         => 'IPv6 ULA',
			'fe80::1'         => 'IPv6 link-local',
		] as $ip => $label ) {
			$this->assert_true( UrlGuard::is_forbidden_ip( $ip ), "forbidden: $label ($ip)" );
			$this->assert_false( UrlGuard::is_safe( 'http://' . $ip . '/x' ), "request to $label refused" );
			if ( str_contains( $ip, ':' ) ) {
				$this->assert_false( UrlGuard::is_safe( 'http://[' . $ip . ']/x' ), "bracketed $label refused" );
			}
		}

		foreach ( [ '8.8.8.8', '1.1.1.1', '172.32.0.1', '100.128.0.1', '192.169.0.1' ] as $ip ) {
			$this->assert_false( UrlGuard::is_forbidden_ip( $ip ), "public stays public: $ip" );
		}
	}

	private function http_blocks_before_transport(): void {
		igbz_test_reset_settings();
		$GLOBALS['igbz_test_http_requests'] = [];
		$http = new Http( new Logger( igbz()->settings() ) );

		$response = $http->get( 'http://169.254.169.254/latest/meta-data/' );
		$this->assert_false( $response->ok(), 'metadata fetch refused' );
		$this->assert_same( 0, count( $GLOBALS['igbz_test_http_requests'] ), 'blocked URL never reaches the transport' );
		$this->assert_contains( 'SSRF', $response->error_message(), 'block reason is visible to callers' );
	}
}
