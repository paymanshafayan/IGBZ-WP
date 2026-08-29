<?php
namespace IGBZ\Suite\Modules\Pado\Services;

use IGBZ\Suite\Support\Http;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Small, provider-neutral client for the externally hosted Pado service.
 * Pado remains outside WordPress; this client only sends a validated job envelope and
 * never executes returned code or treats a remote response as an approval.
 */
final class PadoGateway {

	public function __construct( private Http $http, private Logger $logger ) {}

	public function configured(): bool {
		return '' !== igbz()->settings()->string( 'pado.api_key', '' )
			&& '' !== igbz()->settings()->string( 'pado.endpoint', '' );
	}

	/** @param array<string,mixed> $payload @return array{ok:bool,job_id:string,data:array<string,mixed>,error:string} */
	public function submit( string $action, array $payload ): array {
		if ( ! $this->configured() ) {
			return [ 'ok' => false, 'job_id' => '', 'data' => [], 'error' => __( 'Pado service is not configured.', 'igbz-suite' ) ];
		}
		$endpoint = esc_url_raw( igbz()->settings()->string( 'pado.endpoint', '' ) );
		if ( 'https' !== strtolower( (string) wp_parse_url( $endpoint, PHP_URL_SCHEME ) ) ) {
			return [ 'ok' => false, 'job_id' => '', 'data' => [], 'error' => __( 'Pado endpoint must use HTTPS.', 'igbz-suite' ) ];
		}
		$response = $this->http->post(
			$endpoint,
			[
				'json'    => [ 'action' => sanitize_key( $action ), 'payload' => $payload ],
				'headers' => [
					'Authorization' => 'Bearer ' . igbz()->settings()->string( 'pado.api_key', '' ),
					'Accept'        => 'application/json',
				],
				'channel' => 'pado',
				'timeout' => 120,
			]
		);
		$data = $response->json();
		if ( ! $response->ok() || ! is_array( $data ) ) {
			$error = $response->error_message() ?: __( 'Pado service returned an invalid response.', 'igbz-suite' );
			$this->logger->error( 'pado', 'Pado request failed', [ 'action' => $action, 'error' => $error ] );
			return [ 'ok' => false, 'job_id' => '', 'data' => [], 'error' => $error ];
		}
		return [
			'ok'     => (bool) ( $data['ok'] ?? true ),
			'job_id' => sanitize_text_field( (string) ( $data['job_id'] ?? $data['id'] ?? '' ) ),
			'data'   => $data,
			'error'  => sanitize_text_field( (string) ( $data['error'] ?? '' ) ),
		];
	}

	/** @return array{ok:bool,job_id:string,data:array<string,mixed>,error:string} */
	public function status( string $job_id ): array {
		return $this->submit( 'theme_design_status', [ 'job_id' => sanitize_text_field( $job_id ) ] );
	}

	/** @return array{ok:bool,body:string,error:string} */
	public function download( string $url ): array {
		if ( ! $this->configured() || ! wp_http_validate_url( $url ) || 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			return [ 'ok' => false, 'body' => '', 'error' => 'نشانی دریافت قالب معتبر نیست.' ];
		}

		// Credential policy: the bearer only ever travels to the configured Pado host. A zip
		// URL anywhere else (or a redirected attacker host) gets the artifact, never the key.
		$headers       = [];
		$endpoint_host = strtolower( (string) wp_parse_url( igbz()->settings()->string( 'pado.endpoint', '' ), PHP_URL_HOST ) );
		if ( '' !== $endpoint_host && strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === $endpoint_host ) {
			$headers['Authorization'] = 'Bearer ' . igbz()->settings()->string( 'pado.api_key', '' );
		}

		$response = $this->http->get( esc_url_raw( $url ), [
			'headers' => $headers,
			'channel' => 'pado', 'timeout' => 120, 'retries' => 1,
			'max_bytes' => 64 * 1024 * 1024,
		] );
		return $response->ok() ? [ 'ok' => true, 'body' => $response->body, 'error' => '' ] : [ 'ok' => false, 'body' => '', 'error' => $response->error_message() ];
	}
}
