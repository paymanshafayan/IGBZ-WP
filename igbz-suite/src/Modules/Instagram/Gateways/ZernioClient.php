<?php
namespace IGBZ\Suite\Modules\Instagram\Gateways;

use IGBZ\Suite\Support\Http;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Config-driven Zernio client (phase 50, ADR-0004 §5).
 *
 * The official adapter for the project's only social provider. Base URL, paths
 * and auth scheme come from settings with the documented docs.zernio.com
 * defaults, so nothing here invents endpoint semantics; the live endpoints are
 * verified in the dedicated `PV-ZERNIO-*` phase.
 *
 * Key discipline (ADR-0004 §5): profile-plane calls use the one central key,
 * which never leaves this layer. Social-plane calls receive the store's own
 * profile-scoped key from the caller — this class stores nothing and resolves
 * nothing.
 */
final class ZernioClient implements ZernioAdapterInterface {

	/** Official base (docs.zernio.com quickstart); overridable per install. */
	private const DEFAULT_BASE_URL = 'https://zernio.com/api/v1';

	public function __construct(
		private Http $http,
		private Logger $logger
	) {}

	// ------------------------------------------------------- profile plane

	public function is_configured(): bool {
		return '' !== $this->central_key()
			&& '' !== $this->base();
	}

	/** Runtime-only staging credential; production remains admin-configured. */
	private function central_key(): string {
		$stored = igbz()->settings()->string( 'zernio.central_api_key' );
		if ( '' !== $stored ) {
			return $stored;
		}
		return 'staging' === (string) getenv( 'WP_ENVIRONMENT_TYPE' )
			? trim( (string) getenv( 'ZERNIO_API_KEY' ) )
			: '';
	}

	public function create_profile( string $store_slug ): array {
		if ( ! $this->is_configured() ) {
			return [ 'ok' => false, 'profile_id' => '', 'error' => 'zernio_not_configured' ];
		}

		// Profile names must be unique within the central team (docs: multi-tenant),
		// so the store slug — unique per tenant — is the name.
		$response = $this->post( $this->path( 'profiles_path', '/profiles' ), [ 'name' => $store_slug, 'description' => $store_slug ] );
		$body     = $response->json();

		if ( ! $response->ok() ) {
			$this->logger->warning( 'zernio', 'Profile creation failed', [ 'status' => $response->status ] );
			return [ 'ok' => false, 'profile_id' => '', 'error' => (string) ( $body['message'] ?? $body['error'] ?? 'zernio_request_failed' ) ];
		}

		$profile_id = (string) ( $body['profile']['_id'] ?? $body['id'] ?? $body['profile_id'] ?? $body['data']['id'] ?? '' );

		return '' !== $profile_id
			? [ 'ok' => true, 'profile_id' => $profile_id, 'error' => '' ]
			: [ 'ok' => false, 'profile_id' => '', 'error' => 'zernio_missing_profile_id' ];
	}

	public function issue_profile_key( string $profile_id ): array {
		if ( ! $this->is_configured() || '' === $profile_id ) {
			return [ 'ok' => false, 'key' => '', 'key_id' => '', 'error' => 'zernio_not_configured' ];
		}

		// Scoped key limited to this profile (docs: create-api-key, scope=profiles).
		$response = $this->post( $this->path( 'api_keys_path', '/api-keys' ), [
			'name'       => 'igbz-profile-' . $profile_id,
			'scope'      => 'profiles',
			'profileIds' => [ $profile_id ],
			'permission' => 'readwrite',
		] );
		$body     = $response->json();

		if ( ! $response->ok() ) {
			return [ 'ok' => false, 'key' => '', 'key_id' => '', 'error' => (string) ( $body['message'] ?? $body['error'] ?? 'zernio_request_failed' ) ];
		}

		$key    = (string) ( $body['apiKey'] ?? $body['key'] ?? $body['api_key'] ?? $body['data']['key'] ?? '' );
		$key_id = (string) ( $body['_id'] ?? $body['id'] ?? $body['keyId'] ?? $body['key_id'] ?? $body['data']['id'] ?? '' );

		return '' !== $key
			? [ 'ok' => true, 'key' => $key, 'key_id' => $key_id, 'error' => '' ]
			: [ 'ok' => false, 'key' => '', 'key_id' => '', 'error' => 'zernio_missing_key' ];
	}

	public function revoke_profile_key( string $key_id ): array {
		if ( ! $this->is_configured() || '' === $key_id ) {
			return [ 'ok' => false, 'error' => 'zernio_not_configured' ];
		}

		$response = $this->request( 'DELETE', $this->path( 'api_keys_path', '/api-keys' ) . '/' . rawurlencode( $key_id ), [] );

		return $response->ok()
			? [ 'ok' => true, 'error' => '' ]
			: [ 'ok' => false, 'error' => $response->error_message() ];
	}

	public function start_connect( string $profile_id ): array {
		if ( ! $this->is_configured() || '' === $profile_id ) {
			return [ 'ok' => false, 'auth_url' => '', 'error' => 'zernio_not_configured' ];
		}

		$query = http_build_query(
			[
				'profileId'    => $profile_id,
				'redirect_url' => (string) ( igbz()->settings()->string( 'zernio.connect_redirect_url' ) !== ''
					? igbz()->settings()->string( 'zernio.connect_redirect_url' )
					: home_url( '/' ) ),
			]
		);
		$response = $this->get( $this->path( 'connect_path', '/connect/instagram' ) . '?' . $query );
		$body     = $response->json();

		if ( ! $response->ok() ) {
			return [ 'ok' => false, 'auth_url' => '', 'error' => (string) ( $body['message'] ?? $body['error'] ?? 'zernio_request_failed' ) ];
		}

		$auth_url = (string) ( $body['authUrl'] ?? $body['data']['authUrl'] ?? '' );

		return '' !== $auth_url
			? [ 'ok' => true, 'auth_url' => $auth_url, 'error' => '' ]
			: [ 'ok' => false, 'auth_url' => '', 'error' => 'zernio_missing_auth_url' ];
	}

	public function list_accounts( string $profile_id ): array {
		if ( ! $this->is_configured() || '' === $profile_id ) {
			return [ 'ok' => false, 'accounts' => [], 'error' => 'zernio_not_configured' ];
		}

		$response = $this->get( $this->path( 'accounts_path', '/accounts' ) . '?' . http_build_query( [ 'profileId' => $profile_id ] ) );
		$body     = $response->json();

		if ( ! $response->ok() ) {
			return [ 'ok' => false, 'accounts' => [], 'error' => (string) ( $body['message'] ?? $body['error'] ?? 'zernio_request_failed' ) ];
		}

		$accounts = [];
		foreach ( (array) ( $body['accounts'] ?? $body['data']['accounts'] ?? [] ) as $account ) {
			if ( ! is_array( $account ) ) {
				continue;
			}
			$account_id = (string) ( $account['_id'] ?? $account['accountId'] ?? $account['id'] ?? '' );
			if ( '' === $account_id ) {
				continue;
			}
			$accounts[] = [
				'account_id' => $account_id,
				'platform'   => (string) ( $account['platform'] ?? 'instagram' ),
				'username'   => (string) ( $account['username'] ?? $account['displayName'] ?? '' ),
			];
		}

		return [ 'ok' => true, 'accounts' => $accounts, 'error' => '' ];
	}

	public function delete_profile( string $profile_id ): array {
		if ( ! $this->is_configured() || '' === $profile_id ) {
			return [ 'ok' => false, 'error' => 'zernio_not_configured' ];
		}

		$response = $this->request( 'DELETE', $this->path( 'profiles_path', '/profiles' ) . '/' . rawurlencode( $profile_id ), [] );

		return $response->ok()
			? [ 'ok' => true, 'error' => '' ]
			: [ 'ok' => false, 'error' => $response->error_message() ];
	}

	// ------------------------------------------------------- social plane

	public function publish_content( string $key, string $account_id, array $content ): array {
		if ( '' === $key || '' === $account_id ) {
			return [ 'ok' => false, 'post_id' => '', 'error' => 'zernio_not_configured' ];
		}

		$body = [
			'content'   => (string) ( $content['caption'] ?? '' ),
			'platforms' => [ [ 'platform' => 'instagram', 'accountId' => $account_id ] ],
		];

		$media = array_values( array_filter( (array) ( $content['media'] ?? [] ), static fn ( $url ) => '' !== (string) $url ) );
		if ( $media ) {
			$body['media'] = $media;
		}

		$scheduled_at = (string) ( $content['scheduled_at'] ?? '' );
		$body['publishNow'] = '' === $scheduled_at && ( (bool) ( $content['publish_now'] ?? true ) );
		if ( '' !== $scheduled_at ) {
			$body['scheduledAt'] = $scheduled_at;
		}

		$headers = $this->profile_headers( $key );
		$idem    = (string) ( $content['idempotency_key'] ?? '' );
		if ( '' !== $idem ) {
			// Docs: idempotency & safe retries — a retried create must not double-publish.
			$headers['Idempotency-Key'] = $idem;
		}

		$response = $this->request( 'POST', $this->path( 'posts_path', '/posts' ), [ 'json' => $body, 'headers' => $headers, 'channel' => 'zernio', 'timeout' => 60 ] );
		$json     = $response->json();

		if ( ! $response->ok() ) {
			$this->logger->warning( 'zernio', 'Publish failed', [ 'status' => $response->status, 'idempotency' => $idem ] );
			return [ 'ok' => false, 'post_id' => '', 'error' => (string) ( $json['message'] ?? $json['error'] ?? $response->error_message() ) ];
		}

		$post_id = (string) ( $json['post']['_id'] ?? $json['_id'] ?? $json['id'] ?? $json['postId'] ?? $json['data']['id'] ?? '' );

		return '' !== $post_id
			? [ 'ok' => true, 'post_id' => $post_id, 'error' => '' ]
			: [ 'ok' => false, 'post_id' => '', 'error' => 'zernio_missing_post_id' ];
	}

	public function get_post( string $key, string $post_id ): array {
		if ( '' === $key || '' === $post_id ) {
			return [ 'ok' => false, 'status' => '', 'permalink' => '', 'media_id' => '', 'error' => 'zernio_not_configured' ];
		}

		$response = $this->get( $this->path( 'posts_path', '/posts' ) . '/' . rawurlencode( $post_id ), [ 'headers' => $this->profile_headers( $key ), 'channel' => 'zernio' ] );
		$body     = $response->json();

		if ( ! $response->ok() ) {
			return [ 'ok' => false, 'status' => '', 'permalink' => '', 'media_id' => '', 'error' => (string) ( $body['message'] ?? $body['error'] ?? $response->error_message() ) ];
		}

		$post = (array) ( $body['post'] ?? $body['data'] ?? $body );

		return [
			'ok'        => true,
			'status'    => (string) ( $post['status'] ?? '' ),
			'permalink' => (string) ( $post['permalink'] ?? $post['url'] ?? '' ),
			'media_id'  => (string) ( $post['mediaId'] ?? $post['media_id'] ?? '' ),
			'error'     => '',
		];
	}

	public function retry_post( string $key, string $post_id ): array {
		if ( '' === $key || '' === $post_id ) {
			return [ 'ok' => false, 'error' => 'zernio_not_configured' ];
		}

		$response = $this->post( $this->path( 'posts_path', '/posts' ) . '/' . rawurlencode( $post_id ) . '/retry', [], $this->profile_headers( $key ) );

		return $response->ok()
			? [ 'ok' => true, 'error' => '' ]
			: [ 'ok' => false, 'error' => $response->error_message() ];
	}

	public function send_direct_message( string $key, string $account_id, string $recipient_id, array $message ): array {
		if ( '' === $key || '' === $account_id || '' === $recipient_id ) {
			return [ 'ok' => false, 'message_id' => '', 'error' => 'zernio_not_configured' ];
		}

		$body = [
			'accountId' => $account_id,
			'recipient' => $recipient_id,
			'content'   => (string) ( $message['content'] ?? '' ),
		];
		$media = array_values( array_filter( (array) ( $message['media'] ?? [] ), static fn ( $url ) => '' !== (string) $url ) );
		if ( $media ) {
			$body['media'] = $media;
		}

		$headers = $this->profile_headers( $key );
		$idem    = (string) ( $message['idempotency_key'] ?? '' );
		if ( '' !== $idem ) {
			$headers['Idempotency-Key'] = $idem;
		}

		$response = $this->request( 'POST', $this->path( 'dm_path', '/messages' ), [ 'json' => $body, 'headers' => $headers, 'channel' => 'zernio', 'timeout' => 30 ] );
		$json     = $response->json();

		if ( ! $response->ok() ) {
			return [ 'ok' => false, 'message_id' => '', 'error' => (string) ( $json['message'] ?? $json['error'] ?? $response->error_message() ) ];
		}

		$message_id = (string) ( $json['messageId'] ?? $json['message']['id'] ?? $json['id'] ?? $json['data']['messageId'] ?? '' );

		return '' !== $message_id
			? [ 'ok' => true, 'message_id' => $message_id, 'error' => '' ]
			: [ 'ok' => false, 'message_id' => '', 'error' => 'zernio_missing_message_id' ];
	}

	public function send_story_reply( string $key, string $account_id, string $story_id, string $recipient_id, string $text ): array {
		if ( '' === $key || '' === $account_id || '' === $story_id || '' === $recipient_id ) {
			return [ 'ok' => false, 'message_id' => '', 'error' => 'zernio_not_configured' ];
		}

		$response = $this->post(
			$this->path( 'story_reply_path', '/messages/story-reply' ),
			[ 'accountId' => $account_id, 'storyId' => $story_id, 'recipient' => $recipient_id, 'content' => $text ],
			$this->profile_headers( $key )
		);
		$json     = $response->json();

		if ( ! $response->ok() ) {
			return [ 'ok' => false, 'message_id' => '', 'error' => (string) ( $json['message'] ?? $json['error'] ?? $response->error_message() ) ];
		}

		$message_id = (string) ( $json['messageId'] ?? $json['message']['id'] ?? $json['id'] ?? '' );

		return '' !== $message_id
			? [ 'ok' => true, 'message_id' => $message_id, 'error' => '' ]
			: [ 'ok' => false, 'message_id' => '', 'error' => 'zernio_missing_message_id' ];
	}

	/**
	 * Phase 51 — reply to a public comment from the store's own account.
	 *
	 * The path is settings-driven like every other inbox endpoint: the live
	 * semantics belong to PV-ZERNIO-*, not to a guess in code.
	 */
	public function reply_to_comment( string $key, string $account_id, string $comment_id, string $text, string $idempotency_key = '' ): array {
		if ( '' === $key || '' === $account_id || '' === $comment_id ) {
			return [ 'ok' => false, 'comment_id' => '', 'error' => 'zernio_not_configured' ];
		}

		$headers = $this->profile_headers( $key );
		if ( '' !== $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}

		$url      = str_replace( '{commentId}', rawurlencode( $comment_id ), $this->path( 'comment_reply_path', '/comments/{commentId}/reply' ) );
		$response = $this->request(
			'POST',
			$url,
			[ 'json' => [ 'accountId' => $account_id, 'content' => $text ], 'headers' => $headers, 'channel' => 'zernio', 'timeout' => 30 ]
		);
		$json     = $response->json();

		if ( ! $response->ok() ) {
			return [ 'ok' => false, 'comment_id' => '', 'error' => (string) ( $json['message'] ?? $json['error'] ?? $response->error_message() ) ];
		}

		$reply_id = (string) ( $json['commentId'] ?? $json['comment']['id'] ?? $json['id'] ?? '' );

		return '' !== $reply_id
			? [ 'ok' => true, 'comment_id' => $reply_id, 'error' => '' ]
			: [ 'ok' => false, 'comment_id' => '', 'error' => 'zernio_missing_comment_id' ];
	}

	public function get_inbox( string $key, string $kind, string $cursor = '', int $limit = 50 ): array {
		if ( '' === $key ) {
			return [ 'ok' => false, 'items' => [], 'next_cursor' => '', 'error' => 'zernio_not_configured' ];
		}
		// The inbox kinds are a closed set (docs: /inbox/*); anything else is a caller bug.
		if ( ! in_array( $kind, [ 'conversations', 'comments', 'mentions', 'reviews' ], true ) ) {
			return [ 'ok' => false, 'items' => [], 'next_cursor' => '', 'error' => 'unknown_inbox_kind' ];
		}

		$query = http_build_query(
			array_filter(
				[
					'profileId' => '', // filled below only when the profile is known to the caller; the key already scopes
					'cursor'    => $cursor,
					'limit'     => max( 1, min( 100, $limit ) ),
				]
			)
		);
		$url   = $this->path( 'inbox_path', '/inbox' ) . '/' . $kind;
		if ( '' !== $query ) {
			$url .= '?' . $query;
		}

		$response = $this->get( $url, [ 'headers' => $this->profile_headers( $key ), 'channel' => 'zernio' ] );
		$body     = $response->json();

		if ( ! $response->ok() ) {
			return [ 'ok' => false, 'items' => [], 'next_cursor' => '', 'error' => (string) ( $body['message'] ?? $body['error'] ?? $response->error_message() ) ];
		}

		$items = (array) ( $body[$kind] ?? $body['items'] ?? $body['data'][$kind] ?? [] );

		return [
			'ok'          => true,
			'items'       => array_values( array_filter( $items, 'is_array' ) ),
			'next_cursor' => (string) ( $body['nextCursor'] ?? $body['next_cursor'] ?? '' ),
			'error'       => '',
		];
	}

	public function get_analytics( string $key, string $account_id, string $period = '30d' ): array {
		if ( '' === $key || '' === $account_id ) {
			return [ 'ok' => false, 'metrics' => [], 'error' => 'zernio_not_configured' ];
		}

		$query    = http_build_query( [ 'accountId' => $account_id, 'period' => $period ] );
		$response = $this->get( $this->path( 'analytics_path', '/analytics' ) . '?' . $query, [ 'headers' => $this->profile_headers( $key ), 'channel' => 'zernio' ] );
		$body     = $response->json();

		if ( ! $response->ok() ) {
			return [ 'ok' => false, 'metrics' => [], 'error' => (string) ( $body['message'] ?? $body['error'] ?? $response->error_message() ) ];
		}

		return [ 'ok' => true, 'metrics' => (array) ( $body['metrics'] ?? $body['data']['metrics'] ?? $body ), 'error' => '' ];
	}

	public function get_trending_audio( string $key, int $limit = 20 ): array {
		if ( '' === $key ) {
			return [ 'ok' => false, 'audios' => [], 'error' => 'zernio_not_configured' ];
		}

		$query    = http_build_query( [ 'limit' => max( 1, min( 50, $limit ) ) ] );
		$response = $this->get( $this->path( 'audio_path', '/audio/trending' ) . '?' . $query, [ 'headers' => $this->profile_headers( $key ), 'channel' => 'zernio' ] );
		$body     = $response->json();

		if ( ! $response->ok() ) {
			return [ 'ok' => false, 'audios' => [], 'error' => (string) ( $body['message'] ?? $body['error'] ?? $response->error_message() ) ];
		}

		return [ 'ok' => true, 'audios' => array_values( array_filter( (array) ( $body['audios'] ?? $body['data']['audios'] ?? [] ), 'is_array' ) ), 'error' => '' ];
	}

	public function account_health( string $key, string $account_id ): array {
		if ( '' === $key || '' === $account_id ) {
			return [ 'ok' => false, 'healthy' => false, 'error' => 'zernio_not_configured' ];
		}

		$response = $this->get( $this->path( 'accounts_path', '/accounts' ) . '/' . rawurlencode( $account_id ) . '/health', [ 'headers' => $this->profile_headers( $key ), 'channel' => 'zernio' ] );
		$body     = $response->json();

		if ( ! $response->ok() ) {
			return [ 'ok' => false, 'healthy' => false, 'error' => (string) ( $body['message'] ?? $body['error'] ?? $response->error_message() ) ];
		}

		$healthy = array_key_exists( 'healthy', $body )
			? (bool) $body['healthy']
			: ( 'active' === (string) ( $body['status'] ?? '' ) );

		return [ 'ok' => true, 'healthy' => $healthy, 'error' => '' ];
	}

	// -------------------------------------------------------------- plumbing

	/** @param array<string,string> $headers */
	private function post( string $url, array $json, array $headers = [] ): \IGBZ\Suite\Support\HttpResponse {
		return $this->request( 'POST', $url, [ 'json' => $json, 'headers' => array_merge( $this->central_headers(), $headers ), 'channel' => 'zernio', 'timeout' => 30 ] );
	}

	private function get( string $url, array $args = [] ): \IGBZ\Suite\Support\HttpResponse {
		return $this->request( 'GET', $url, array_merge( [ 'headers' => $this->central_headers(), 'channel' => 'zernio' ], $args ) );
	}

	private function request( string $method, string $url, array $args = [] ): \IGBZ\Suite\Support\HttpResponse {
		return $this->http->request( $method, $url, $args );
	}

	/** Profile-scoped auth header — the store's own key, never the central one. */
	private function profile_headers( string $key ): array {
		$scheme = igbz()->settings()->string( 'zernio.auth_scheme', 'Bearer' );

		return [
			'Authorization' => ( '' === $scheme ? '' : $scheme . ' ' ) . $key,
			'Accept'        => 'application/json',
		];
	}

	private function central_headers(): array {
		$scheme = igbz()->settings()->string( 'zernio.auth_scheme', 'Bearer' );

		return [
			'Authorization' => ( '' === $scheme ? '' : $scheme . ' ' ) . $this->central_key(),
			'Accept'        => 'application/json',
		];
	}

	private function base(): string {
		$base = igbz()->settings()->string( 'zernio.base_url', self::DEFAULT_BASE_URL );

		return rtrim( $base, '/' );
	}

	/** A config-driven path, always rooted at the base URL. */
	private function path( string $setting_key, string $default ): string {
		$path = igbz()->settings()->string( 'zernio.' . $setting_key, $default );
		if ( '' === $path ) {
			$path = $default;
		}
		if ( '' !== $path && '/' !== $path[0] ) {
			$path = '/' . $path;
		}

		return $this->base() . $path;
	}
}
