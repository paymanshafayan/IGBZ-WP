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
}
