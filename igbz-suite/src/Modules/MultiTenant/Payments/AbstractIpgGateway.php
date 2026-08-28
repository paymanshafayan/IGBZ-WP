<?php
namespace IGBZ\Suite\Modules\MultiTenant\Payments;

use IGBZ\Suite\Support\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Base for the direct-bank (IPG) adapters.
 *
 * Each Iranian bank has its own protocol (REST/SOAP + specific encryption),
 * so each adapter implements request/verify specifics and reuses the common
 * HTTP + amount + result plumbing here. Settings are read from a per-bank
 * prefix (payments.<bank>.*).
 */
abstract class AbstractIpgGateway implements GatewayInterface {

	protected function __construct( protected Http $http, protected string $prefix ) {}

	public function is_configured(): bool {
		foreach ( $this->required_settings() as $key ) {
			if ( '' === igbz()->settings()->string( $key ) ) {
				return false;
			}
		}
		return true;
	}

	protected function cfg( string $key ): string {
		return (string) igbz()->settings()->string( $this->prefix . '.' . $key );
	}

	protected function bool_cfg( string $key, bool $default = false ): bool {
		return igbz()->settings()->bool( $this->prefix . '.' . $key, $default );
	}

	/**
	 * POST JSON and return decoded body.
	 *
	 * Returns an **associative** array: `ok`, `body`, `raw`, `error`. Read it by key —
	 * `$r = $this->post_json(...); $ok = $r['ok'];` — never by list destructuring.
	 * `[ $ok, $body ] = $this->post_json(...)` silently yields two nulls (keys 0 and 1 do
	 * not exist), so every response reads as a failure and PHP emits "Undefined array key".
	 * Four gateways shipped with exactly that bug; see AGENT-BRIEF §5.
	 *
	 * Phase 30: the default timeout is the single shared PSP value (PspHttp::timeout()), never a
	 * per-adapter number. And request() calls must never be auto-retried on timeout — see PspHttp.
	 *
	 * @return array{ok:bool,body:array,raw:string,error:string}
	 */
	protected function post_json( string $url, array $payload, int $timeout = 0 ): array {
		$timeout = $timeout > 0 ? $timeout : PspHttp::timeout();
		$response = $this->http->post(
			$url,
			[
				'json'    => $payload,
				'headers' => [ 'Accept' => 'application/json', 'Content-Type' => 'application/json' ],
				'channel' => 'payments',
				'timeout' => $timeout,
			]
		);
		return [ 'ok' => $response->ok(), 'body' => $response->json(), 'raw' => $response->body, 'error' => $response->error_message() ];
	}

	/**
	 * POST raw body (SOAP/XML or form) and return raw response.
	 *
	 * Associative array, same contract as post_json(): read `ok` / `raw` by key, never by
	 * list destructuring.
	 *
	 * @return array{ok:bool,body:array,raw:string,error:string}
	 */
	protected function post_raw( string $url, string $body, string $content_type = 'application/soap+xml; charset=utf-8', int $timeout = 0 ): array {
		$timeout = $timeout > 0 ? $timeout : PspHttp::timeout();
		$response = $this->http->post(
			$url,
			[
				'body'    => $body,
				'headers' => [ 'Content-Type' => $content_type, 'Accept' => 'text/xml, application/json' ],
				'channel' => 'payments',
				'timeout' => $timeout,
			]
		);
		return [ 'ok' => $response->ok(), 'body' => $response->json(), 'raw' => $response->body, 'error' => $response->error_message() ];
	}
}
