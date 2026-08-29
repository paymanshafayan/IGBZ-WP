<?php
namespace IGBZ\Suite\Modules\Instagram\Gateways;

use IGBZ\Suite\Support\Http;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Config-driven Zernio client (phase 49, ADR-0004 §5).
 *
 * Mirrors the shipping/marketplace adapter discipline: base URL, paths and
 * auth scheme come from settings; nothing here invents endpoint semantics.
 * Every profile mutation is authenticated with the profile-scoped key once
 * one exists, never with the central key.
 */
final class ZernioClient implements ZernioAdapterInterface {

	public function __construct(
		private Http $http,
		private Logger $logger
	) {}

	public function is_configured(): bool {
		return '' !== igbz()->settings()->string( 'zernio.base_url' )
			&& '' !== igbz()->settings()->string( 'zernio.central_api_key' );
	}

	public function create_profile( string $store_slug ): array {
		if ( ! $this->is_configured() ) {
			return [ 'ok' => false, 'profile_id' => '', 'error' => 'zernio_not_configured' ];
		}

		$response = $this->http->post(
			$this->base() . igbz()->settings()->string( 'zernio.profiles_path', '/v1/profiles' ),
			[
				'json'    => [ 'store_slug' => $store_slug ],
				'headers' => $this->headers( igbz()->settings()->string( 'zernio.central_api_key' ) ),
				'channel' => 'zernio',
				'timeout' => 30,
			]
		);
		$body = $response->json();

		if ( ! $response->ok() ) {
			$this->logger->warning( 'zernio', 'Profile creation failed', [ 'status' => $response->status ] );
			return [ 'ok' => false, 'profile_id' => '', 'error' => (string) ( $body['message'] ?? $body['error'] ?? 'zernio_request_failed' ) ];
		}

		$profile_id = (string) ( $body['id'] ?? $body['profile_id'] ?? $body['data']['id'] ?? '' );

		return '' !== $profile_id
			? [ 'ok' => true, 'profile_id' => $profile_id, 'error' => '' ]
			: [ 'ok' => false, 'profile_id' => '', 'error' => 'zernio_missing_profile_id' ];
	}

	public function issue_profile_key( string $profile_id ): array {
		if ( ! $this->is_configured() || '' === $profile_id ) {
			return [ 'ok' => false, 'key' => '', 'error' => 'zernio_not_configured' ];
		}

		$response = $this->http->post(
			$this->base() . igbz()->settings()->string( 'zernio.profile_keys_path', '/v1/profiles' ) . '/' . rawurlencode( $profile_id ) . '/keys',
			[
				'json'    => new \stdClass(),
				'headers' => $this->headers( igbz()->settings()->string( 'zernio.central_api_key' ) ),
				'channel' => 'zernio',
				'timeout' => 30,
			]
		);
		$body = $response->json();

		if ( ! $response->ok() ) {
			return [ 'ok' => false, 'key' => '', 'error' => (string) ( $body['message'] ?? $body['error'] ?? 'zernio_request_failed' ) ];
		}

		$key = (string) ( $body['key'] ?? $body['api_key'] ?? $body['data']['key'] ?? '' );

		return '' !== $key
			? [ 'ok' => true, 'key' => $key, 'error' => '' ]
			: [ 'ok' => false, 'key' => '', 'error' => 'zernio_missing_key' ];
	}

	public function revoke_profile_key( string $profile_id ): array {
		if ( ! $this->is_configured() || '' === $profile_id ) {
			return [ 'ok' => false, 'error' => 'zernio_not_configured' ];
		}

		$response = $this->http->post(
			$this->base() . igbz()->settings()->string( 'zernio.profile_keys_path', '/v1/profiles' ) . '/' . rawurlencode( $profile_id ) . '/revoke',
			[
				'json'    => new \stdClass(),
				'headers' => $this->headers( igbz()->settings()->string( 'zernio.central_api_key' ) ),
				'channel' => 'zernio',
				'timeout' => 30,
			]
		);

		return $response->ok()
			? [ 'ok' => true, 'error' => '' ]
			: [ 'ok' => false, 'error' => $response->error_message() ];
	}

	public function connect_account( string $profile_id ): array {
		if ( ! $this->is_configured() || '' === $profile_id ) {
			return [ 'ok' => false, 'account_id' => '', 'instagram_account_id' => '', 'error' => 'zernio_not_configured' ];
		}

		$response = $this->http->post(
			$this->base() . igbz()->settings()->string( 'zernio.profile_keys_path', '/v1/profiles' ) . '/' . rawurlencode( $profile_id ) . '/connect',
			[
				'json'    => new \stdClass(),
				'headers' => $this->headers( igbz()->settings()->string( 'zernio.central_api_key' ) ),
				'channel' => 'zernio',
				'timeout' => 60,
			]
		);
		$body = $response->json();

		if ( ! $response->ok() ) {
			return [ 'ok' => false, 'account_id' => '', 'instagram_account_id' => '', 'error' => (string) ( $body['message'] ?? $body['error'] ?? 'zernio_request_failed' ) ];
		}

		$account_id    = (string) ( $body['account_id'] ?? $body['data']['account_id'] ?? '' );
		$instagram_id  = (string) ( $body['instagram_account_id'] ?? $body['data']['instagram_account_id'] ?? '' );

		return ( '' !== $account_id && '' !== $instagram_id )
			? [ 'ok' => true, 'account_id' => $account_id, 'instagram_account_id' => $instagram_id, 'error' => '' ]
			: [ 'ok' => false, 'account_id' => '', 'instagram_account_id' => '', 'error' => 'zernio_missing_account' ];
	}

	private function base(): string {
		return rtrim( igbz()->settings()->string( 'zernio.base_url' ), '/' );
	}

	/** @return array<string,string> */
	private function headers( string $key ): array {
		$scheme = igbz()->settings()->string( 'zernio.auth_scheme', 'Bearer' );

		return [
			'Authorization' => ( '' === $scheme ? '' : $scheme . ' ' ) . $key,
			'Accept'        => 'application/json',
		];
	}
}
