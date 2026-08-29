<?php
namespace IGBZ\Suite\Modules\Instagram\Services;

use IGBZ\Suite\Modules\Instagram\Gateways\SocialProviders;
use IGBZ\Suite\Modules\Instagram\Gateways\ZernioAdapterInterface;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * The tenant-facing door to the social plane (phase 50, ADR-0004 §5).
 *
 * Every social operation in the module goes through here, and the ordering of
 * the first three steps is the architecture:
 *
 *   1. SocialProviders::assert_allowed() — the guard; only Zernio passes;
 *   2. the tenant↔profile↔account mapping is resolved from the backend
 *      (ZernioConnectionService) — raw client identifiers never decide;
 *   3. the store's own profile-scoped key is decrypted and passed to the
 *      adapter — the central key never leaves the adapter layer.
 *
 * The facade carries no domain logic: publishing rules (phase 53), inbox
 * handling (phase 51) and giveaway/insight flows (phase 55) are built on top
 * of it. Each method degrades to a structured `ok=false` result — callers own
 * retry/dead-letter policy, never the adapter.
 */
final class ZernioSocialService {

	public const PROVIDER = SocialProviders::ZERNIO;

	public function __construct(
		private Db $db,
		private Logger $logger,
		private ZernioConnectionService $zernio,
		private ZernioAdapterInterface $client
	) {}

	// ------------------------------------------------------------- plumbing

	/**
	 * The connected profile of the tenant, or null. A not-connected store has
	 * no social plane at all — every method below returns `bad_state`.
	 *
	 * @return array<string,mixed>|null
	 */
	public function profile( int $tenant_id ): ?array {
		$row = $this->zernio->profile( $tenant_id );
		if ( null === $row || ZernioConnectionService::STATUS_CONNECTED !== (string) $row['status'] ) {
			return null;
		}

		return $row;
	}

	/** The store's profile-scoped key, only while connected. */
	private function key( int $tenant_id ): string {
		return $this->zernio->key_for( $tenant_id );
	}

	/**
	 * The common prologue: guard → mapping → key.
	 *
	 * @return array{profile:array<string,mixed>,key:string}|null
	 */
	private function resolve( int $tenant_id, string $operation ): ?array {
		SocialProviders::assert_allowed( self::PROVIDER );

		$profile = $this->profile( $tenant_id );
		if ( null === $profile ) {
			$this->logger->info( 'zernio', 'Social operation skipped: store not connected', [ 'tenant' => $tenant_id, 'op' => $operation ] );

			return null;
		}

		return [ 'profile' => $profile, 'key' => $this->key( $tenant_id ) ];
	}

	private function unavailable( string $operation, int $tenant_id ): array {
		$this->logger->info( 'zernio', 'Social operation unavailable', [ 'tenant' => $tenant_id, 'op' => $operation ] );

		return [ 'ok' => false, 'error' => 'not_connected' ];
	}

	// ------------------------------------------------------------ publish

	/**
	 * @param array{caption?:string,media?:array<int,string>,publish_now?:bool,scheduled_at?:string,idempotency_key?:string} $content
	 * @return array{ok:bool,post_id:string,error:string}
	 */
	public function publish( int $tenant_id, array $content ): array {
		$resolved = $this->resolve( $tenant_id, 'publish' );
		if ( null === $resolved ) {
			$result = $this->unavailable( 'publish', $tenant_id );

			return [ 'ok' => false, 'post_id' => '', 'error' => (string) $result['error'] ];
		}

		return $this->client->publish_content(
			$resolved['key'],
			(string) $resolved['profile']['account_id'],
			$content
		);
	}

	/** @return array{ok:bool,status:string,permalink:string,media_id:string,error:string} */
	public function get_post( int $tenant_id, string $post_id ): array {
		$resolved = $this->resolve( $tenant_id, 'get_post' );
		if ( null === $resolved ) {
			$result = $this->unavailable( 'get_post', $tenant_id );

			return [ 'ok' => false, 'status' => '', 'permalink' => '', 'media_id' => '', 'error' => (string) $result['error'] ];
		}

		return $this->client->get_post( $resolved['key'], $post_id );
	}

	/** @return array{ok:bool,error:string} */
	public function retry_post( int $tenant_id, string $post_id ): array {
		$resolved = $this->resolve( $tenant_id, 'retry_post' );
		if ( null === $resolved ) {
			return $this->unavailable( 'retry_post', $tenant_id );
		}

		return $this->client->retry_post( $resolved['key'], $post_id );
	}

	// ------------------------------------------------------------- messaging

	/**
	 * @param array{content?:string,media?:array<int,string>,idempotency_key?:string} $message
	 * @return array{ok:bool,message_id:string,error:string}
	 */
	public function send_direct_message( int $tenant_id, string $recipient_id, array $message ): array {
		$resolved = $this->resolve( $tenant_id, 'send_dm' );
		if ( null === $resolved ) {
			$result = $this->unavailable( 'send_dm', $tenant_id );

			return [ 'ok' => false, 'message_id' => '', 'error' => (string) $result['error'] ];
		}

		return $this->client->send_direct_message(
			$resolved['key'],
			(string) $resolved['profile']['account_id'],
			$recipient_id,
			$message
		);
	}

	/** @return array{ok:bool,message_id:string,error:string} */
	public function send_story_reply( int $tenant_id, string $story_id, string $recipient_id, string $text ): array {
		$resolved = $this->resolve( $tenant_id, 'send_story_reply' );
		if ( null === $resolved ) {
			$result = $this->unavailable( 'send_story_reply', $tenant_id );

			return [ 'ok' => false, 'message_id' => '', 'error' => (string) $result['error'] ];
		}

		return $this->client->send_story_reply(
			$resolved['key'],
			(string) $resolved['profile']['account_id'],
			$story_id,
			$recipient_id,
			$text
		);
	}

	// ---------------------------------------------------------------- inbox

	/**
	 * @return array{ok:bool,items:array<int,array<string,mixed>>,next_cursor:string,error:string}
	 */
	public function inbox( int $tenant_id, string $kind, string $cursor = '', int $limit = 50 ): array {
		$resolved = $this->resolve( $tenant_id, 'inbox' );
		if ( null === $resolved ) {
			$result = $this->unavailable( 'inbox', $tenant_id );

			return [ 'ok' => false, 'items' => [], 'next_cursor' => '', 'error' => (string) $result['error'] ];
		}

		return $this->client->get_inbox( $resolved['key'], $kind, $cursor, $limit );
	}

	// ------------------------------------------------------- analytics/audio

	/** @return array{ok:bool,metrics:array<string,mixed>,error:string} */
	public function analytics( int $tenant_id, string $period = '30d' ): array {
		$resolved = $this->resolve( $tenant_id, 'analytics' );
		if ( null === $resolved ) {
			$result = $this->unavailable( 'analytics', $tenant_id );

			return [ 'ok' => false, 'metrics' => [], 'error' => (string) $result['error'] ];
		}

		return $this->client->get_analytics( $resolved['key'], (string) $resolved['profile']['account_id'], $period );
	}

	/** @return array{ok:bool,audios:array<int,array<string,mixed>>,error:string} */
	public function trending_audio( int $tenant_id, int $limit = 20 ): array {
		$resolved = $this->resolve( $tenant_id, 'audio' );
		if ( null === $resolved ) {
			$result = $this->unavailable( 'audio', $tenant_id );

			return [ 'ok' => false, 'audios' => [], 'error' => (string) $result['error'] ];
		}

		return $this->client->get_trending_audio( $resolved['key'], $limit );
	}

	/** @return array{ok:bool,healthy:bool,error:string} */
	public function account_health( int $tenant_id ): array {
		$resolved = $this->resolve( $tenant_id, 'health' );
		if ( null === $resolved ) {
			$result = $this->unavailable( 'health', $tenant_id );

			return [ 'ok' => false, 'healthy' => false, 'error' => (string) $result['error'] ];
		}

		return $this->client->account_health( $resolved['key'], (string) $resolved['profile']['account_id'] );
	}
}
