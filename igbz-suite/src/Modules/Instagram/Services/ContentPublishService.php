<?php
namespace IGBZ\Suite\Modules\Instagram\Services;

use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;
use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 53 — the publishing engine: the job that takes a draft `ig_content` row
 * (the artifact a human approved in phase 52) and actually puts it on Instagram,
 * then learns the real outcome from the provider instead of assuming one.
 *
 * The state lives on the content row itself:
 *
 *   draft ─► scheduled ─► publishing ─► published
 *     │           │            │
 *     └───────────┴──────────► failed ──(retry, capped)──► publishing …
 *
 * Honesty rules (ADR-0004 §8/§13):
 *
 *  1. Duplicate prevention is anchored to the row: the idempotency key sent to the
 *     provider is `content:<content_id>` — stable across crashes and retries, so
 *     one content row can never create two posts, no matter how many times the
 *     beat or the operator fires it.
 *  2. No blind retries. A `failed` row is first reconciled against the provider
 *     (the post may have published after all); only a confirmed failure is
 *     re-driven, and only up to `MAX_RETRIES`.
 *  3. Outcomes are real: the webhook (`/zernio/posts`, self-authenticating like
 *     the inbox) and the polling sweep both funnel through `apply_provider_state`,
 *     which only accepts forward progress and records the provider's own status.
 *  4. Voice is gated: the catalog voice note (the one recorded at product
 *     registration) is attached only when the store opted in (`igbz.publisher_audio`)
 *     AND a real URL exists on the registration. No audio → the post ships with
 *     image/video only. Missing audio access is a production gate, not a fake.
 */
final class ContentPublishService {

	// ig_content.status values.
	public const STATUS_DRAFT      = 'draft';
	public const STATUS_SCHEDULED  = 'scheduled';
	public const STATUS_PUBLISHING = 'publishing';
	public const STATUS_PUBLISHED  = 'published';
	public const STATUS_FAILED     = 'failed';

	/** Provider statuses we know (Zernio post lifecycle). */
	public const PROVIDER_SCHEDULED  = 'scheduled';
	public const PROVIDER_PUBLISHING = 'publishing';
	public const PROVIDER_PUBLISHED  = 'published';
	public const PROVIDER_PARTIAL    = 'partial';
	public const PROVIDER_FAILED     = 'failed';
	public const PROVIDER_CANCELLED  = 'cancelled';

	public const MAX_RETRIES         = 3;
	public const BATCH_LIMIT         = 50;
	/** Polling never touches a row newer than this — the webhook is the fast path. */
	public const POLL_QUIET_SECONDS  = 300;

	public function __construct(
		private Db $db,
		private Logger $logger,
		private ZernioConnectionService $zernio,
		private ZernioSocialService $social,
		private Settings $settings
	) {}

	// ------------------------------------------------------------- publishing

	/**
	 * Publish a content row now. Legal from `draft` (the operator's "now"), from
	 * `scheduled` (the due-sweep firing its moment, or the operator overriding
	 * the schedule) and from a reconciled `failed`.
	 *
	 * @return array<string,mixed> {ok, id, status, error}
	 */
	public function publish_now( int $tenant_id, int $content_id ): array {
		$row = $this->content( $tenant_id, $content_id );
		if ( null === $row ) {
			return [ 'ok' => false, 'id' => $content_id, 'status' => '', 'error' => 'not_found' ];
		}

		$status = (string) $row['status'];
		if ( ! in_array( $status, [ self::STATUS_DRAFT, self::STATUS_SCHEDULED, self::STATUS_FAILED ], true ) ) {
			return [ 'ok' => false, 'id' => $content_id, 'status' => $status, 'error' => 'invalid_state_for_publish' ];
		}

		// A failed row that already has a provider task is reconciled FIRST: the
		// post may have gone out after the error. No blind re-creation.
		if ( self::STATUS_FAILED === $status && '' !== (string) $row['provider_task_id'] ) {
			$check = $this->social->get_post( $tenant_id, (string) $row['provider_task_id'] );
			if ( ! empty( $check['ok'] ) ) {
				$this->apply_provider_state( $tenant_id, $row, $check );
				$after = $this->content( $tenant_id, $content_id );
				$status = null !== $after ? (string) $after['status'] : $status;
				if ( self::STATUS_PUBLISHED === $status ) {
					return [ 'ok' => true, 'id' => $content_id, 'status' => $status, 'error' => 'already_published' ];
				}
			}
		}

		if ( null === $this->social->profile( $tenant_id ) ) {
			$this->logger->warning( 'ig.content_publish', 'Publish refused: store not connected', [ 'tenant' => $tenant_id, 'content' => $content_id ] );
			return [ 'ok' => false, 'id' => $content_id, 'status' => $status, 'error' => 'not_connected' ];
		}

		$result = $this->social->publish(
			$tenant_id,
			[
				'caption'         => (string) $row['caption'],
				'media'           => $this->media_urls( $row ),
				'publish_now'     => true,
				'idempotency_key' => self::idempotency_key( (int) $row['id'] ),
			]
		);

		if ( empty( $result['ok'] ) ) {
			$this->logger->error( 'ig.content_publish', 'Publish attempt failed', [ 'tenant' => $tenant_id, 'content' => $content_id, 'error' => (string) $result['error'] ] );
			$this->set( $tenant_id, $content_id, [
				'status'      => self::STATUS_FAILED,
				'last_error'  => substr( (string) $result['error'], 0, 500 ),
				'retry_count' => (int) $row['retry_count'],
			] );
			return [ 'ok' => false, 'id' => $content_id, 'status' => self::STATUS_FAILED, 'error' => (string) $result['error'] ];
		}

		$this->logger->info( 'ig.content_publish', 'Post created on the provider', [ 'tenant' => $tenant_id, 'content' => $content_id, 'post' => (string) $result['post_id'] ] );

		$this->set( $tenant_id, $content_id, [
			'status'           => self::STATUS_PUBLISHING,
			'provider'         => 'zernio',
			'provider_task_id' => substr( (string) $result['post_id'], 0, 191 ),
			'provider_status'  => self::PROVIDER_PUBLISHING,
			'last_error'       => '',
		] );

		return [ 'ok' => true, 'id' => $content_id, 'status' => self::STATUS_PUBLISHING, 'error' => '' ];
	}

	/**
	 * Schedule a content row for a future moment (time selection).
	 *
	 * @return array<string,mixed>
	 */
	public function schedule( int $tenant_id, int $content_id, string $when_iso ): array {
		$row = $this->content( $tenant_id, $content_id );
		if ( null === $row ) {
			return [ 'ok' => false, 'id' => $content_id, 'status' => '', 'error' => 'not_found' ];
		}

		$status = (string) $row['status'];
		if ( ! in_array( $status, [ self::STATUS_DRAFT, self::STATUS_FAILED ], true ) ) {
			return [ 'ok' => false, 'id' => $content_id, 'status' => $status, 'error' => 'invalid_state_for_schedule' ];
		}

		$ts   = strtotime( $when_iso );
		$now  = time();
		if ( false === $ts || $ts < $now + 60 ) {
			return [ 'ok' => false, 'id' => $content_id, 'status' => $status, 'error' => 'schedule_in_past' ];
		}
		if ( $ts > $now + 90 * DAY_IN_SECONDS ) {
			return [ 'ok' => false, 'id' => $content_id, 'status' => $status, 'error' => 'schedule_too_far' ];
		}

		$write = [
			'status'        => self::STATUS_SCHEDULED,
			'scheduled_for' => gmdate( 'Y-m-d H:i:s', $ts ),
			'last_error'    => '',
		];

		// Re-scheduling a row that already created a post cancels nothing here: the
		// provider task stays anchored and the next due-sweep will NOT re-create
		// (publish_due only fires rows without a task id). Honest gap recorded.
		if ( '' !== (string) $row['provider_task_id'] ) {
			$write['provider_status'] = self::PROVIDER_SCHEDULED;
		}

		$this->set( $tenant_id, $content_id, $write );

		return [ 'ok' => true, 'id' => $content_id, 'status' => self::STATUS_SCHEDULED, 'error' => '' ];
	}

	/**
	 * The five-minute beat: fire every due scheduled row that has not yet created
	 * a provider task. Rows that already have a task id are left to reconciliation.
	 *
	 * @return array<string,int> counts by outcome
	 */
	public function publish_due(): array {
		$now  = current_time( 'mysql', true );
		$rows = $this->db->results(
			"SELECT id, tenant_id FROM " . $this->db->table( 'ig_content' ) . "
			 WHERE status = %s AND scheduled_for IS NOT NULL AND scheduled_for <= %s AND provider_task_id = ''
			 ORDER BY scheduled_for ASC, id ASC LIMIT " . self::BATCH_LIMIT,
			self::STATUS_SCHEDULED,
			$now
		);

		$counts = [ 'published' => 0, 'failed' => 0 ];
		foreach ( $rows as $row ) {
			$result = $this->publish_now( (int) $row['tenant_id'], (int) $row['id'] );
			if ( ! empty( $result['ok'] ) ) {
				++$counts['published'];
			} else {
				++$counts['failed'];
			}
		}

		if ( $counts['published'] + $counts['failed'] > 0 ) {
			$this->logger->info( 'ig.content_publish', 'Due-sweep finished', $counts );
		}

		return $counts;
	}

	/**
	 * The polling sweep (the webhook is the fast path; this is the safety net for
	 * missed/delayed events — ADR-0004 §13). Only touches rows that already have a
	 * provider task and have been quiet for the quiet window.
	 *
	 * @return array<string,int> counts by outcome
	 */
	public function reconcile(): array {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::POLL_QUIET_SECONDS );
		$rows   = $this->db->results(
			"SELECT * FROM " . $this->db->table( 'ig_content' ) . "
			 WHERE provider_task_id <> '' AND status IN ( %s, %s ) AND updated_at <= %s
			 ORDER BY updated_at ASC, id ASC LIMIT " . self::BATCH_LIMIT,
			self::STATUS_PUBLISHING,
			self::STATUS_SCHEDULED,
			$cutoff
		);

		$counts = [ 'applied' => 0, 'pending' => 0, 'failed' => 0 ];
		foreach ( $rows as $row ) {
			$check = $this->social->get_post( (int) $row['tenant_id'], (string) $row['provider_task_id'] );
			if ( empty( $check['ok'] ) ) {
				// The provider answer itself is uncertain: leave the row untouched.
				++$counts['pending'];
				continue;
			}
			$before = (string) $row['status'];
			$this->apply_provider_state( (int) $row['tenant_id'], $row, $check );
			$after = $this->content( (int) $row['tenant_id'], (int) $row['id'] );
			$after = null !== $after ? (string) $after['status'] : $before;

			if ( self::STATUS_PUBLISHED === $after && self::STATUS_PUBLISHED !== $before ) {
				++$counts['applied'];
			} elseif ( self::STATUS_FAILED === $after && self::STATUS_FAILED !== $before ) {
				++$counts['failed'];
			} else {
				++$counts['pending'];
			}
		}

		if ( $counts['applied'] > 0 ) {
			$this->logger->info( 'ig.content_publish', 'Reconciliation finished', $counts );
		}

		return $counts;
	}

	/**
	 * Operator retry of a failed row. Reconciles first (no blind retry), caps at
	 * MAX_RETRIES, and re-drives through the provider's own retry endpoint when a
	 * task exists (its documented semantics), otherwise re-publishes under the same
	 * idempotency key (safe re-creation).
	 *
	 * @return array<string,mixed>
	 */
	public function retry( int $tenant_id, int $content_id ): array {
		$row = $this->content( $tenant_id, $content_id );
		if ( null === $row ) {
			return [ 'ok' => false, 'id' => $content_id, 'status' => '', 'error' => 'not_found' ];
		}
		if ( self::STATUS_FAILED !== (string) $row['status'] ) {
			return [ 'ok' => false, 'id' => $content_id, 'status' => (string) $row['status'], 'error' => 'invalid_state_for_retry' ];
		}
		if ( (int) $row['retry_count'] >= self::MAX_RETRIES ) {
			return [ 'ok' => false, 'id' => $content_id, 'status' => (string) $row['status'], 'error' => 'retry_limit_reached' ];
		}

		// Reconcile first: the provider may already have the final answer.
		$task = (string) $row['provider_task_id'];
		if ( '' !== $task ) {
			$check = $this->social->get_post( $tenant_id, $task );
			if ( ! empty( $check['ok'] ) ) {
				$this->apply_provider_state( $tenant_id, $row, $check );
				$after = $this->content( $tenant_id, $content_id );
				if ( null !== $after && self::STATUS_FAILED !== (string) $after['status'] ) {
					return [ 'ok' => true, 'id' => $content_id, 'status' => (string) $after['status'], 'error' => 'reconciled' ];
				}
			}
		}

		$attempts = (int) $row['retry_count'] + 1;

		if ( '' !== $task ) {
			$provider_retry = $this->social->retry_post( $tenant_id, $task );
			if ( ! empty( $provider_retry['ok'] ) ) {
				$this->set( $tenant_id, $content_id, [
					'status'          => self::STATUS_PUBLISHING,
					'provider_status' => self::PROVIDER_PUBLISHING,
					'last_error'      => '',
					'retry_count'     => $attempts,
				] );
				return [ 'ok' => true, 'id' => $content_id, 'status' => self::STATUS_PUBLISHING, 'error' => '' ];
			}
			// The provider's retry refused: fall through to a safe re-publish under
			// the same idempotency key (it cannot double-post).
			$this->logger->warning( 'ig.content_publish', 'Provider retry refused; re-publishing under the same key', [ 'tenant' => $tenant_id, 'content' => $content_id, 'error' => (string) $provider_retry['error'] ] );
		}

		$result = $this->social->publish(
			$tenant_id,
			[
				'caption'         => (string) $row['caption'],
				'media'           => $this->media_urls( $row ),
				'publish_now'     => true,
				'idempotency_key' => self::idempotency_key( (int) $row['id'] ),
			]
		);

		if ( empty( $result['ok'] ) ) {
			$this->set( $tenant_id, $content_id, [
				'last_error'  => substr( (string) $result['error'], 0, 500 ),
				'retry_count' => $attempts,
			] );
			return [ 'ok' => false, 'id' => $content_id, 'status' => self::STATUS_FAILED, 'error' => (string) $result['error'] ];
		}

		$this->set( $tenant_id, $content_id, [
			'status'           => self::STATUS_PUBLISHING,
			'provider_task_id' => substr( (string) $result['post_id'], 0, 191 ),
			'provider_status'  => self::PROVIDER_PUBLISHING,
			'last_error'       => '',
			'retry_count'      => $attempts,
		] );

		return [ 'ok' => true, 'id' => $content_id, 'status' => self::STATUS_PUBLISHING, 'error' => '' ];
	}

	// ------------------------------------------------------------- outcomes

	/**
	 * The single funnel for real provider outcomes (webhook and polling share it).
	 * Only forward progress is accepted; a stale event never reverts a row.
	 *
	 * @param array<string,mixed> $row     the current content row
	 * @param array<string,mixed> $check   a ZernioSocialService::get_post-shaped result
	 */
	public function apply_provider_state( int $tenant_id, array $row, array $check ): void {
		$provider = strtolower( (string) $check['status'] );
		$id       = (int) $row['id'];

		switch ( $provider ) {
			case self::PROVIDER_PUBLISHED:
				if ( self::STATUS_PUBLISHED === (string) $row['status'] ) {
					return; // terminal and already applied
				}
				$this->set( $tenant_id, $id, [
					'status'        => self::STATUS_PUBLISHED,
					'published_at'  => current_time( 'mysql', true ),
					'permalink'     => substr( (string) $check['permalink'], 0, 255 ),
					'ig_shortcode'  => substr( (string) $check['media_id'], 0, 64 ),
					'provider_status' => $provider,
					'last_error'    => '',
				] );
				$this->logger->info( 'ig.content_publish', 'Content published (real provider outcome)', [ 'tenant' => $tenant_id, 'content' => $id ] );
				return;

			case self::PROVIDER_FAILED:
			case self::PROVIDER_PARTIAL:
			case self::PROVIDER_CANCELLED:
				if ( self::STATUS_PUBLISHED === (string) $row['status'] ) {
					return; // never revert a published row on a late event
				}
				$this->set( $tenant_id, $id, [
					'status'          => self::STATUS_FAILED,
					'provider_status' => $provider,
					'last_error'      => 'partial' === $provider ? 'provider_partial_failure' : ( 'cancelled' === $provider ? 'post_cancelled_by_provider' : 'provider_post_failed' ),
				] );
				$this->logger->warning( 'ig.content_publish', 'Content failed (real provider outcome)', [ 'tenant' => $tenant_id, 'content' => $id, 'state' => $provider ] );
				return;

			case self::PROVIDER_SCHEDULED:
			case self::PROVIDER_PUBLISHING:
			default:
				// Still in flight: record the provider's own word, change nothing else.
				$this->set( $tenant_id, $id, [ 'provider_status' => $provider ] );
		}
	}

	// ------------------------------------------------------------- webhook

	/**
	 * The provider's post lifecycle webhook — self-authenticating, same identity
	 * model as the inbox: ownership via the account→profile→tenant mapping, then
	 * the profile's own HMAC inside the replay window, then dedupe on
	 * (profile, event) in `ig_publish_events`.
	 *
	 * @param array<string,string> $headers X-Zernio-Signature / X-Zernio-Timestamp
	 * @return array<string,mixed> {ok, status, id?, event?}
	 */
	public function handle_post_event( string $raw, array $headers ): array {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return [ 'ok' => false, 'status' => 'bad_payload' ];
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return [ 'ok' => false, 'status' => 'bad_payload' ];
		}

		$event = strtolower( (string) ( $decoded['event'] ?? $decoded['type'] ?? '' ) );
		if ( '' === $event || 0 !== strpos( $event, 'post.' ) ) {
			return [ 'ok' => false, 'status' => 'bad_payload' ];
		}

		// 1) ownership — post events carry accountId on each platform entry.
		$account_id = $this->first_string( $decoded, [
			[ 'data', 'accountId' ],
			[ 'account', 'accountId' ],
			[ 'accountId' ],
			[ 'data', 'platform', 'accountId' ],
			[ 'platform', 'accountId' ],
		] );
		$platforms  = (array) ( $decoded['data']['platforms'] ?? $decoded['platforms'] ?? [] );
		if ( '' === $account_id && is_array( $platforms ) && ! empty( $platforms[0]['accountId'] ) ) {
			$account_id = (string) $platforms[0]['accountId'];
		}
		if ( '' === $account_id ) {
			return [ 'ok' => false, 'status' => 'bad_payload' ];
		}

		$profile = $this->db->row(
			"SELECT p.* FROM " . $this->db->table( 'ig_zernio_profiles' ) . ' p WHERE p.account_id = %s LIMIT 1',
			$account_id
		);
		if ( null === $profile ) {
			$this->logger->warning( 'ig.content_publish', 'Post event for an unmapped account refused', [ 'account' => $account_id ] );
			return [ 'ok' => false, 'status' => 'unknown_account' ];
		}

		$tenant_id = (int) $profile['tenant_id'];

		// 2) identity — the profile's own secret, inside the replay window.
		$signature = trim( (string) ( $headers['X-Zernio-Signature'] ?? $headers['x-zernio-signature'] ?? '' ) );
		$timestamp = (int) ( $headers['X-Zernio-Timestamp'] ?? $headers['x-zernio-timestamp'] ?? 0 );
		if ( ! $this->zernio->verify_webhook( $tenant_id, $raw, $timestamp, $signature ) ) {
			$this->logger->warning( 'ig.content_publish', 'Post event failed signature verification', [ 'tenant' => $tenant_id ] );
			return [ 'ok' => false, 'status' => 'invalid_signature' ];
		}

		// 3) capture + dedupe.
		$event_id = (string) (
			$decoded['event_id'] ?? $decoded['eventId']
			?? $decoded['data']['id'] ?? $decoded['id']
			?? ''
		);
		if ( '' === $event_id ) {
			$event_id = hash( 'sha256', $raw );
		}

		$existing = $this->db->row(
			"SELECT id FROM " . $this->db->table( 'ig_publish_events' ) . " WHERE profile_id = %d AND event_id = %s LIMIT 1",
			(int) $profile['id'],
			$event_id
		);
		if ( null !== $existing ) {
			return [ 'ok' => true, 'status' => 'duplicate', 'id' => (int) $existing['id'] ];
		}

		$post_id       = substr( $this->first_string( $decoded, [ [ 'data', 'postId' ], [ 'postId' ], [ 'data', 'post', 'id' ], [ 'post', 'id' ] ] ), 0, 64 );
		$platform_status = strtolower( $this->first_string( $decoded, [ [ 'data', 'status' ], [ 'status' ], [ 'data', 'platform', 'status' ], [ 'platform', 'status' ] ] ) );

		// 4) apply — resolve the content row by the provider task id, tenant-scoped.
		$content_id = 0;
		$outcome    = 'received';
		$error      = '';

		$content = '' !== $post_id
			? $this->db->row(
				"SELECT * FROM " . $this->db->table( 'ig_content' ) . ' WHERE tenant_id = %d AND provider_task_id = %s LIMIT 1',
				$tenant_id,
				$post_id
			)
			: null;

		if ( null !== $content ) {
			$content_id = (int) $content['id'];

			// Map the event to a get_post-shaped check so ONE funnel applies the state.
			$state_map = [
				'post.published'          => self::PROVIDER_PUBLISHED,
				'post.partial'            => self::PROVIDER_PARTIAL,
				'post.failed'             => self::PROVIDER_FAILED,
				'post.cancelled'          => self::PROVIDER_CANCELLED,
				'post.platform.published' => self::PROVIDER_PUBLISHED,
				'post.platform.failed'    => self::PROVIDER_FAILED,
			];
			$state = $state_map[ $event ] ?? ( '' !== $platform_status ? $platform_status : '' );

			if ( '' === $state ) {
				$outcome = 'recorded'; // e.g. post.scheduled: noted, nothing to apply
			} else {
				$this->apply_provider_state(
					$tenant_id,
					$content,
					[
						'ok'        => true,
						'status'    => $state,
						'permalink' => $this->first_string( $decoded, [ [ 'data', 'permalink' ], [ 'permalink' ], [ 'data', 'platform', 'permalink' ] ] ),
						'media_id'  => $this->first_string( $decoded, [ [ 'data', 'mediaId' ], [ 'mediaId' ], [ 'data', 'platform', 'mediaId' ] ] ),
						'error'     => '',
					]
				);
				$outcome = self::PROVIDER_PUBLISHED === $state ? 'applied' : ( in_array( $state, [ self::PROVIDER_FAILED, self::PROVIDER_PARTIAL, self::PROVIDER_CANCELLED ], true ) ? 'failed' : 'recorded' );
			}
		} else {
			$outcome = 'no_content_row'; // foreign post on our account: captured, nothing to apply
			$error   = 'no_content_row';
		}

		$event_id = substr( $event_id, 0, 64 );
		$event_id = $this->unique_event_id( (int) $profile['id'], $event_id );
		$now      = current_time( 'mysql', true );
		$event_row_id = $this->db->insert(
			'ig_publish_events',
			[
				'tenant_id'       => $tenant_id,
				'profile_id'      => (int) $profile['id'],
				'event_id'        => $event_id,
				'event'           => substr( $event, 0, 48 ),
				'provider_post_id' => $post_id,
				'platform_status' => substr( $platform_status, 0, 32 ),
				'content_id'      => $content_id,
				'outcome'         => $outcome,
				'error'           => substr( $error, 0, 500 ),
				'occurred_at'     => $this->to_mysql_datetime( (string) ( $decoded['data']['timestamp'] ?? $decoded['timestamp'] ?? '' ) ),
				'received_at'     => $now,
			]
		);

		return [ 'ok' => true, 'status' => 'received', 'id' => (int) $event_row_id, 'event' => $event ];
	}

	// ------------------------------------------------------------- audio (gated)

	/**
	 * The catalog voice for a content row: the voice note recorded at product
	 * registration, attached to the post's media only when the store opted in AND
	 * a real URL exists. The production gate: without a real URL there is no
	 * audio — the post ships with image/video only.
	 *
	 * @param array<string,mixed> $row
	 * @return array<int,string> media urls (including the voice, when applicable)
	 */
	private function media_urls( array $row ): array {
		$urls = [];
		$raw  = (string) $row['media'];
		if ( '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				if ( isset( $decoded['url'] ) && is_string( $decoded['url'] ) ) {
					$urls[] = $decoded['url'];
				} else {
					foreach ( $decoded as $value ) {
						if ( is_string( $value ) && '' !== $value ) {
							$urls[] = $value;
						}
					}
				}
			} elseif ( filter_var( trim( $raw ), FILTER_VALIDATE_URL ) ) {
				$urls[] = trim( $raw );
			}
		}

		if ( 1 === (int) $this->settings->int( 'igbz.publisher_audio', 0 ) && (int) $row['product_id'] > 0 ) {
			$candidates = $this->db->results(
				"SELECT voice_url FROM " . $this->db->table( 'ig_product_registrations' ) . ' WHERE tenant_id = %d AND product_id = %d ORDER BY id DESC LIMIT 5',
				(int) $row['tenant_id'],
				(int) $row['product_id']
			);
			$voice = '';
			foreach ( $candidates as $candidate ) {
				if ( '' !== trim( (string) $candidate['voice_url'] ) ) {
					$voice = (string) $candidate['voice_url'];
					break;
				}
			}
			if ( '' !== $voice && ! in_array( $voice, $urls, true ) ) {
				$urls[] = $voice;
			}
		}

		return array_values( array_filter( $urls, static fn ( $u ) => '' !== trim( (string) $u ) ) );
	}

	// ------------------------------------------------------------- reporting

	/**
	 * The store's content queue + publishing report, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function list_content( int $tenant_id, string $status_filter = '', int $limit = 50 ): array {
		$status_filter = trim( $status_filter );
		if ( '' !== $status_filter && in_array( $status_filter, [ self::STATUS_DRAFT, self::STATUS_SCHEDULED, self::STATUS_PUBLISHING, self::STATUS_PUBLISHED, self::STATUS_FAILED ], true ) ) {
			return $this->db->results(
				"SELECT * FROM " . $this->db->table( 'ig_content' ) . " WHERE tenant_id = %d AND status = %s ORDER BY updated_at DESC, id DESC LIMIT " . max( 1, min( 200, $limit ) ),
				$tenant_id,
				$status_filter
			);
		}

		return $this->db->results(
			"SELECT * FROM " . $this->db->table( 'ig_content' ) . ' WHERE tenant_id = %d ORDER BY updated_at DESC, id DESC LIMIT ' . max( 1, min( 200, $limit ) ),
			$tenant_id
		);
	}

	/**
	 * The publishing event ledger (the audit trail behind every real outcome).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function list_events( int $tenant_id, int $limit = 50 ): array {
		return $this->db->results(
			"SELECT * FROM " . $this->db->table( 'ig_publish_events' ) . ' WHERE tenant_id = %d ORDER BY received_at DESC, id DESC LIMIT ' . max( 1, min( 200, $limit ) ),
			$tenant_id
		);
	}

	/** @return array<string,mixed>|null */
	public function get( int $tenant_id, int $content_id ): ?array {
		return $this->content( $tenant_id, $content_id );
	}

	// ------------------------------------------------------------- plumbing

	/** @return array<string,mixed>|null */
	private function content( int $tenant_id, int $content_id ): ?array {
		return $this->db->row(
			"SELECT * FROM " . $this->db->table( 'ig_content' ) . ' WHERE id = %d AND tenant_id = %d LIMIT 1',
			$content_id,
			$tenant_id
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function set( int $tenant_id, int $content_id, array $data ): void {
		$data['updated_at'] = current_time( 'mysql', true );
		$this->db->update( 'ig_content', $data, [ 'id' => $content_id, 'tenant_id' => $tenant_id ] );
	}

	/** The stable per-row idempotency anchor: one content row, one post, ever. */
	private static function idempotency_key( int $content_id ): string {
		return 'content:' . $content_id;
	}

	/** Keep the (profile, event) unique key safe against colliding short ids. */
	private function unique_event_id( int $profile_id, string $event_id ): string {
		for ( $i = 0; $i < 5; $i++ ) {
			$existing = $this->db->row(
				"SELECT id FROM " . $this->db->table( 'ig_publish_events' ) . ' WHERE profile_id = %d AND event_id = %s LIMIT 1',
				$profile_id,
				$event_id
			);
			if ( null === $existing ) {
				return $event_id;
			}
			$event_id = substr( $event_id, 0, 56 ) . '-' . $i;
		}

		return substr( $event_id, 0, 56 ) . '-' . substr( Crypto::token( 4 ), 0, 8 );
	}

	/**
	 * @param array<int,string[]> $paths
	 */
	private function first_string( array $decoded, array $paths ): string {
		foreach ( $paths as $path ) {
			$value = $decoded;
			foreach ( $path as $key ) {
				if ( ! is_array( $value ) || ! isset( $value[ $key ] ) ) {
					$value = null;
					break;
				}
				$value = $value[ $key ];
			}
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}

		return '';
	}

	private function to_mysql_datetime( string $iso ): string {
		$ts = strtotime( $iso );
		if ( false === $ts ) {
			return current_time( 'mysql', true );
		}

		return gmdate( 'Y-m-d H:i:s', $ts );
	}
}
