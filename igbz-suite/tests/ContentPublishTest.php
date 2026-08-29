<?php
/**
 * Phase 53 — the publishing engine (ADR-0004 §8/§13).
 *
 * The real service, the real Zernio client, the real webhook HMAC — only the
 * database and the network are in-memory doubles. Every assertion here is about
 * the decision path: duplicate prevention by the stable per-row idempotency
 * key, no-blind-retry reconciliation, real provider outcomes (webhook and
 * polling), the failure state, the gated catalog voice, and tenant isolation.
 */

declare( strict_types=1 );

use IGBZ\Suite\Modules\Instagram\Services\ContentPublishService;
use IGBZ\Suite\Modules\Instagram\Services\ZernioConnectionService;
use IGBZ\Suite\Modules\Instagram\Services\ZernioSocialService;
use IGBZ\Suite\Modules\Instagram\Gateways\ZernioClient;
use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Http;
use IGBZ\Suite\Support\Logger;

final class PublishDb extends wpdb {

	/** @var array<string,array<string,mixed>> tenant_id => profile row */
	public array $profiles = [];

	/** @var array<int,array<string,mixed>> id => row */
	public array $content = [];

	/** @var array<int,array<string,mixed>> id => row */
	public array $events = [];

	/** @var array<int,array<string,mixed>> id => row */
	public array $registrations = [];

	private int $next_id = 1;

	// ------------------------------------------------------------- reads

	public function get_row( string $sql, $output = null ) {
		$rows = $this->select_rows( $sql );

		return $rows ? $rows[0] : null;
	}

	/** @return array<int,array<string,mixed>> */
	public function get_results( string $sql, $output = null ) {
		return $this->select_rows( $sql );
	}

	/**
	 * A mini WHERE evaluator covering exactly the operators the engine uses —
	 * `col = 'v'` (including empty), `col <= 'v'`, `col <> 'v'`,
	 * `col IN ('a','b')`, `col IS NOT NULL` and LIMIT. Real SQL never executes.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function select_rows( string $sql ): array {
		$this->queries[] = $sql;

		$store = $this->store( $sql );
		if ( [] === $store ) {
			return [];
		}

		$eq      = [];
		$match   = [];
		preg_match_all( "/(\w+) = '([^']*)'/", $sql, $match, PREG_SET_ORDER );
		foreach ( $match as $c ) {
			$eq[ $c[1] ] = $c[2];
		}

		$le      = [];
		$match   = [];
		preg_match_all( "/(\w+) <= '([^']*)'/", $sql, $match, PREG_SET_ORDER );
		foreach ( $match as $c ) {
			$le[ $c[1] ] = $c[2];
		}

		$ne      = [];
		$match   = [];
		preg_match_all( "/(\w+) <> '([^']*)'/", $sql, $match, PREG_SET_ORDER );
		foreach ( $match as $c ) {
			$ne[ $c[1] ] = $c[2];
		}

		$in      = [];
		$match   = [];
		preg_match_all( "/(\w+) IN \(\s*'([^']*)'\s*,\s*'([^']*)'\s*\)/", $sql, $match, PREG_SET_ORDER );
		foreach ( $match as $c ) {
			$in[ $c[1] ] = [ $c[2], $c[3] ];
		}

		$notnull = [];
		$match   = [];
		preg_match_all( "/(\w+) IS NOT NULL/", $sql, $match, PREG_SET_ORDER );
		foreach ( $match as $c ) {
			$notnull[ $c[1] ] = true;
		}

		$out = [];
		foreach ( $store as $row ) {
			$ok = true;
			foreach ( $eq as $col => $val ) {
				if ( ! array_key_exists( $col, $row ) || (string) $row[ $col ] !== (string) $val ) {
					$ok = false;
					break;
				}
			}
			if ( $ok ) {
				foreach ( $le as $col => $val ) {
					// Empty (NULL) never satisfies an ordering condition.
					if ( ! array_key_exists( $col, $row ) || '' === (string) $row[ $col ] || null === $row[ $col ] || (string) $row[ $col ] > (string) $val ) {
						$ok = false;
						break;
					}
				}
			}
			if ( $ok ) {
				foreach ( $ne as $col => $val ) {
					if ( ! array_key_exists( $col, $row ) || (string) $row[ $col ] === (string) $val ) {
						$ok = false;
						break;
					}
				}
			}
			if ( $ok ) {
				foreach ( $in as $col => $vals ) {
					if ( ! in_array( (string) ( $row[ $col ] ?? '' ), $vals, true ) ) {
						$ok = false;
						break;
					}
				}
			}
			if ( $ok ) {
				foreach ( $notnull as $col => $_flag ) {
					if ( ! array_key_exists( $col, $row ) || null === $row[ $col ] || '' === (string) $row[ $col ] ) {
						$ok = false;
						break;
					}
				}
			}
			if ( $ok ) {
				$out[] = $row;
			}
		}

		if ( preg_match( '/LIMIT (\d+)/', $sql, $m ) ) {
			$out = array_slice( $out, 0, (int) $m[1] );
		}

		return $out;
	}

	/** @return array<int,array<string,mixed>> */
	private function store( string $sql ): array {
		if ( str_contains( $sql, 'ig_zernio_profiles' ) ) {
			return array_values( $this->profiles );
		}
		if ( str_contains( $sql, 'ig_publish_events' ) ) {
			return array_values( $this->events );
		}
		if ( str_contains( $sql, 'ig_product_registrations' ) ) {
			return array_values( $this->registrations );
		}
		if ( str_contains( $sql, 'ig_content' ) ) {
			return array_values( $this->content );
		}

		return [];
	}

	// ------------------------------------------------------------- writes

	public function insert( string $table, array $data, $format = null ): int|bool {
		$id         = (int) ( $data['id'] ?? 0 );
		$id         = $id > 0 ? $id : ( ++$this->next_id );
		$data['id'] = $id;

		if ( str_ends_with( $table, 'ig_publish_events' ) ) {
			$this->events[ $id ] = $data;
		} elseif ( str_ends_with( $table, 'ig_content' ) ) {
			$this->content[ $id ] = $data;
		} elseif ( str_ends_with( $table, 'ig_product_registrations' ) ) {
			$this->registrations[ $id ] = $data;
		} else {
			return false;
		}

		$this->insert_id = $id;

		return $id;
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		$changed = 0;
		$id      = (int) ( $where['id'] ?? 0 );
		$tenant  = isset( $where['tenant_id'] ) ? (string) $where['tenant_id'] : null;

		if ( str_ends_with( $table, 'ig_content' ) && isset( $this->content[ $id ] ) ) {
			if ( null === $tenant || (string) $this->content[ $id ]['tenant_id'] === $tenant ) {
				$this->content[ $id ] = array_merge( $this->content[ $id ], $data );
				++$changed;
			}
		}

		return $changed;
	}
}

final class ContentPublishTest extends TestCase {

	private PublishDb $db;

	private ContentPublishService $publisher;

	private \IGBZ\Suite\Support\Settings $settings;

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function seed_content( int $id, int $tenant, array $overrides = [] ): array {
		$now = gmdate( 'Y-m-d H:i:s' );
		$row = [
			'id'               => $id,
			'tenant_id'        => $tenant,
			'account_id'       => 'acc-1',
			'kind'             => 'image',
			'title'            => 'product ' . $id,
			'brief'            => '',
			'caption'          => 'caption-' . $id,
			'hashtags'         => '#igbz',
			'media'            => wp_json_encode( [ 'url' => 'https://cdn.test/img-' . $id . '.jpg' ] ),
			'product_id'       => 0,
			'funnel_id'        => 0,
			'provider'         => '',
			'provider_task_id' => '',
			'provider_status'  => '',
			'status'           => ContentPublishService::STATUS_DRAFT,
			'scheduled_for'    => null,
			'published_at'     => null,
			'permalink'        => '',
			'ig_shortcode'     => '',
			'last_error'       => '',
			'retry_count'      => 0,
			'created_at'       => $now,
			'updated_at'       => $now,
		];
		$row = array_merge( $row, $overrides );

		$this->db->content[ $id ] = $row;

		return $row;
	}

	/** Queue a provider answer for a specific URL fragment (most specific first). */
	private function queue_http( array $response ): void {
		igbz_test_queue_http( $response );
	}

	/** @return array<int,array<string,mixed>> */
	private function http_requests(): array {
		return $GLOBALS['igbz_test_http_requests'];
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array{payload:string,signature:string,timestamp:int}
	 */
	private function signed_event( string $secret, array $data ): array {
		$payload   = wp_json_encode( $data );
		$timestamp = time();

		return [
			'payload'   => $payload,
			'signature' => Crypto::hmac( $payload . '.' . $timestamp, $secret ),
			'timestamp' => $timestamp,
		];
	}

	private function headers( string $signature, int $timestamp ): array {
		return [
			'X-Zernio-Signature' => $signature,
			'X-Zernio-Timestamp' => (string) $timestamp,
		];
	}

	public function run(): void {
		$this->fresh();

		$this->publish_now_creates_exactly_one_provider_post_with_the_stable_key();
		$this->failed_publish_reconciles_before_recreating();
		$this->schedule_validates_the_window();
		$this->due_sweep_fires_only_due_rows_without_a_task();
		$this->reconcile_applies_real_state_and_leaves_fresh_rows_alone();
		$this->webhook_published_updates_the_row_and_records_the_ledger();
		$this->webhook_unknown_account_is_refused();
		$this->webhook_bad_or_stale_signature_is_refused();
		$this->late_events_move_forward_only();
		$this->retry_reconciles_uses_the_provider_retry_and_caps();
		$this->retry_without_a_task_republishes_under_the_same_key();
		$this->catalog_voice_is_gated_and_never_faked();
		$this->partial_provider_state_is_a_failure();
		$this->tenant_isolation_holds_on_content_and_events();
		$this->unconnected_store_cannot_publish();
	}

	private function fresh(): void {
		$this->db    = new PublishDb();
		$GLOBALS['wpdb'] = $this->db;

		$this->settings = igbz_test_reset_settings();

		$now = current_time( 'mysql', true );
		$this->db->profiles['1'] = [
			'id'                 => 11,
			'tenant_id'          => 1,
			'profile_id'         => 'prof-1',
			'status'             => ZernioConnectionService::STATUS_CONNECTED,
			'key_enc'            => Crypto::encrypt( 'sk-store' ),
			'key_id'             => 'kid-1',
			'key_version'        => 1,
			'webhook_secret_enc' => Crypto::encrypt( 'whsec-test' ),
			'account_id'         => 'acc-1',
			'instagram_account_id' => 'ig-1',
			'connected_at'       => $now,
			'created_at'         => $now,
			'updated_at'         => $now,
		];
		// Built explicitly (not a union): every field of the second store is its own.
		$this->db->profiles['2'] = [
			'id'                   => 22,
			'tenant_id'            => 2,
			'profile_id'           => 'prof-2',
			'status'               => ZernioConnectionService::STATUS_CONNECTED,
			'key_enc'              => Crypto::encrypt( 'sk-store-2' ),
			'key_id'               => 'kid-2',
			'key_version'          => 1,
			'webhook_secret_enc'   => Crypto::encrypt( 'whsec-test-2' ),
			'account_id'           => 'acc-2',
			'instagram_account_id' => 'ig-2',
			'connected_at'         => $now,
			'created_at'           => $now,
			'updated_at'           => $now,
		];

		$logger     = new Logger( $this->settings );
		$client     = new ZernioClient( new Http( $logger ), $logger );
		$connection = new ZernioConnectionService( new Db(), $logger, $client );
		$social     = new ZernioSocialService( new Db(), $logger, $connection, $client );
		$this->publisher = new ContentPublishService( new Db(), $logger, $connection, $social, $this->settings );
	}

	// ------------------------------------------------------------- scenarios

	private function publish_now_creates_exactly_one_provider_post_with_the_stable_key(): void {
		$this->fresh();
		$this->seed_content( 31, 1 );

		$this->queue_http( [
			'match' => '/posts',
			'status' => 200,
			'body'   => wp_json_encode( [ 'post' => [ '_id' => 'post-77' ] ] ),
		] );

		$result = $this->publisher->publish_now( 1, 31 );
		$this->assert_true( $result['ok'], 'a draft row publishes' );
		$this->assert_same( ContentPublishService::STATUS_PUBLISHING, $result['status'], 'the row enters publishing' );

		$row = $this->db->content[31];
		$this->assert_same( 'post-77', $row['provider_task_id'], 'the provider task is anchored to the row' );
		$this->assert_same( 'zernio', $row['provider'], 'the provider is recorded' );

		$requests = $this->http_requests();
		$this->assert_same( 1, count( $requests ), 'exactly one outbound call' );
		$this->assert_same( 'POST', $requests[0]['method'], 'the post is created' );
		$this->assert_contains( '/posts', $requests[0]['url'], 'it targets the posts endpoint' );
		$this->assert_same( 'content:31', (string) ( $requests[0]['headers']['Idempotency-Key'] ?? '' ), 'the stable per-row idempotency key rides the request' );

		$body = json_decode( (string) $requests[0]['body'], true );
		$this->assert_same( 'caption-31', (string) ( $body['content'] ?? '' ), 'the caption is the content row caption' );
		$this->assert_same( [ 'https://cdn.test/img-31.jpg' ], (array) ( $body['media'] ?? [] ), 'the media is the content row media' );
		$this->assert_same( 'acc-1', (string) ( $body['platforms'][0]['accountId'] ?? '' ), 'the post goes to the store connected account' );
		$this->assert_true( (bool) ( $body['publishNow'] ?? false ), 'a now-publish says publishNow' );

		// The row is in flight: firing it again is refused WITHOUT another provider call.
		$again = $this->publisher->publish_now( 1, 31 );
		$this->assert_false( $again['ok'], 'a publishing row cannot be re-fired' );
		$this->assert_same( 'invalid_state_for_publish', $again['error'], 'the guard says why' );
		$this->assert_same( 1, count( $this->http_requests() ), 'no second provider call' );

		// And the reconciliation funnel would not duplicate either: a second
		// create under the same key is the provider safe-retry, but the engine
		// never issues it — the row stays anchored to post-77.
		$this->assert_same( 'post-77', $this->db->content[31]['provider_task_id'], 'the anchor never changes' );
	}

	private function failed_publish_reconciles_before_recreating(): void {
		$this->fresh();
		// A row whose create failed on the transport but which in fact went out.
		$this->seed_content( 32, 1, [
			'status'           => ContentPublishService::STATUS_FAILED,
			'provider_task_id' => 'post-9',
			'last_error'       => 'timeout',
		] );

		$this->queue_http( [
			'match' => '/posts/post-9',
			'status' => 200,
			'body'   => wp_json_encode( [ 'post' => [ 'status' => 'published', 'permalink' => 'https://instagram.com/p/ok', 'mediaId' => 'ok' ] ] ),
		] );

		$result = $this->publisher->publish_now( 1, 32 );
		$this->assert_true( $result['ok'], 'the operator intent succeeds' );
		$this->assert_same( 'already_published', $result['error'], 'the honest outcome is reported' );

		$row = $this->db->content[32];
		$this->assert_same( ContentPublishService::STATUS_PUBLISHED, $row['status'], 'the row is published, not re-created' );
		$this->assert_same( 'https://instagram.com/p/ok', $row['permalink'], 'the real permalink lands' );
		$this->assert_same( 'ok', $row['ig_shortcode'], 'the shortcode lands' );

		$requests = $this->http_requests();
		$this->assert_same( 1, count( $requests ), 'only the reconciliation read happened' );
		$this->assert_same( 'GET', $requests[0]['method'], 'reconciliation reads, it does not write' );
	}

	private function schedule_validates_the_window(): void {
		$this->fresh();
		$this->seed_content( 33, 1 );

		$past = $this->publisher->schedule( 1, 33, gmdate( 'c', time() - 600 ) );
		$this->assert_false( $past['ok'], 'the past is refused' );
		$this->assert_same( 'schedule_in_past', $past['error'], 'the guard says why' );

		$far = $this->publisher->schedule( 1, 33, gmdate( 'c', time() + 91 * 86400 ) );
		$this->assert_false( $far['ok'], 'beyond the 90-day window is refused' );
		$this->assert_same( 'schedule_too_far', $far['error'], 'the guard says why' );

		$ok = $this->publisher->schedule( 1, 33, gmdate( 'c', time() + 3600 ) );
		$this->assert_true( $ok['ok'], 'a future moment schedules' );
		$this->assert_same( ContentPublishService::STATUS_SCHEDULED, $this->db->content[33]['status'], 'the row is scheduled' );
		$this->assert_same( gmdate( 'Y-m-d H:i:s', time() + 3600 ), $this->db->content[33]['scheduled_for'], 'the chosen moment is stored (UTC)' );
		$this->assert_same( 0, count( $this->http_requests() ), 'scheduling never touches the provider' );
	}

	private function due_sweep_fires_only_due_rows_without_a_task(): void {
		$this->fresh();
		// Due, no task → fires.
		$this->seed_content( 41, 1, [
			'status'        => ContentPublishService::STATUS_SCHEDULED,
			'scheduled_for' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
		] );
		// Due, but already has a provider task → left to reconciliation.
		$this->seed_content( 42, 1, [
			'status'           => ContentPublishService::STATUS_SCHEDULED,
			'scheduled_for'    => gmdate( 'Y-m-d H:i:s', time() - 60 ),
			'provider_task_id' => 'post-existing',
		] );
		// Not due yet → waits.
		$this->seed_content( 43, 1, [
			'status'        => ContentPublishService::STATUS_SCHEDULED,
			'scheduled_for' => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
		] );

		$this->queue_http( [
			'match' => '/posts',
			'status' => 200,
			'body'   => wp_json_encode( [ 'post' => [ '_id' => 'post-due' ] ] ),
		] );

		$counts = $this->publisher->publish_due();
		$this->assert_same( 1, $counts['published'], 'exactly one row fired' );
		$this->assert_same( 0, $counts['failed'], 'no failures' );

		$this->assert_same( ContentPublishService::STATUS_PUBLISHING, $this->db->content[41]['status'], 'the due row fired' );
		$this->assert_same( 'post-due', $this->db->content[41]['provider_task_id'], 'it anchored its provider task' );
		$this->assert_same( ContentPublishService::STATUS_SCHEDULED, $this->db->content[42]['status'], 'the row with a task was not re-fired (no double publish)' );
		$this->assert_same( ContentPublishService::STATUS_SCHEDULED, $this->db->content[43]['status'], 'the not-due row still waits' );
		$this->assert_same( 1, count( $this->http_requests() ), 'one outbound call for one row' );
	}

	private function reconcile_applies_real_state_and_leaves_fresh_rows_alone(): void {
		$this->fresh();
		// Old enough to be polled (quiet window 300s).
		$this->seed_content( 51, 1, [
			'status'           => ContentPublishService::STATUS_PUBLISHING,
			'provider_task_id' => 'post-a',
			'updated_at'       => gmdate( 'Y-m-d H:i:s', time() - 400 ),
		] );
		// Fresh: the webhook is the fast path; polling must not hammer it.
		$this->seed_content( 52, 1, [
			'status'           => ContentPublishService::STATUS_PUBLISHING,
			'provider_task_id' => 'post-b',
		] );

		$this->queue_http( [
			'match' => '/posts/post-a',
			'status' => 200,
			'body'   => wp_json_encode( [ 'post' => [ 'status' => 'published', 'permalink' => 'https://instagram.com/p/a', 'mediaId' => 'a' ] ] ),
		] );

		$counts = $this->publisher->reconcile();
		$this->assert_same( 1, $counts['applied'], 'the quiet row advanced' );

		$this->assert_same( ContentPublishService::STATUS_PUBLISHED, $this->db->content[51]['status'], 'the real outcome applied' );
		$this->assert_same( 'https://instagram.com/p/a', $this->db->content[51]['permalink'], 'the permalink applied' );
		$this->assert_same( ContentPublishService::STATUS_PUBLISHING, $this->db->content[52]['status'], 'the fresh row was left alone' );
		$this->assert_same( 1, count( $this->http_requests() ), 'only the quiet row was polled' );
	}

	private function webhook_published_updates_the_row_and_records_the_ledger(): void {
		$this->fresh();
		$this->seed_content( 61, 1, [
			'status'           => ContentPublishService::STATUS_PUBLISHING,
			'provider_task_id' => 'post-77',
		] );

		$event = $this->signed_event( 'whsec-test', [
			'event'    => 'post.published',
			'event_id' => 'ev-1',
			'data'     => [
				'postId'    => 'post-77',
				'accountId' => 'acc-1',
				'status'    => 'published',
				'permalink' => 'https://instagram.com/p/real',
				'mediaId'   => 'real',
				'timestamp' => gmdate( 'c' ),
			],
		] );

		$result = $this->publisher->handle_post_event( $event['payload'], $this->headers( $event['signature'], $event['timestamp'] ) );
		$this->assert_true( $result['ok'], 'the event is received' );
		$this->assert_same( 'received', $result['status'], 'the status is received' );

		$row = $this->db->content[61];
		$this->assert_same( ContentPublishService::STATUS_PUBLISHED, $row['status'], 'the row is published by the real event' );
		$this->assert_same( 'https://instagram.com/p/real', $row['permalink'], 'the live link is stored' );
		$this->assert_same( 'real', $row['ig_shortcode'], 'the media id is stored' );

		$this->assert_same( 1, count( $this->db->events ), 'one ledger row' );
		$ledger = array_values( $this->db->events )[0];
		$this->assert_same( 1, (int) $ledger['tenant_id'], 'the ledger is tenant-scoped' );
		$this->assert_same( 'post.published', $ledger['event'], 'the event name is recorded' );
		$this->assert_same( 61, (int) $ledger['content_id'], 'the ledger links the content row' );
		$this->assert_same( 'applied', $ledger['outcome'], 'the outcome is applied' );

		// The provider retries its delivery: deduplicated no-op, state untouched.
		$retry = $this->publisher->handle_post_event( $event['payload'], $this->headers( $event['signature'], $event['timestamp'] ) );
		$this->assert_true( $retry['ok'], 'the duplicate is accepted politely' );
		$this->assert_same( 'duplicate', $retry['status'], 'it is a deduplicated no-op' );
		$this->assert_same( 1, count( $this->db->events ), 'still one ledger row' );
	}

	private function webhook_unknown_account_is_refused(): void {
		$this->fresh();
		$event = $this->signed_event( 'whsec-test', [
			'event'    => 'post.published',
			'event_id' => 'ev-404',
			'data'     => [ 'accountId' => 'acc-unknown', 'status' => 'published' ],
		] );

		$result = $this->publisher->handle_post_event( $event['payload'], $this->headers( $event['signature'], $event['timestamp'] ) );
		$this->assert_false( $result['ok'], 'an unmapped account is refused' );
		$this->assert_same( 'unknown_account', $result['status'], 'the status says why' );
		$this->assert_same( 0, count( $this->db->events ), 'nothing is captured' );
	}

	private function webhook_bad_or_stale_signature_is_refused(): void {
		$this->fresh();
		$this->seed_content( 62, 1, [
			'status'           => ContentPublishService::STATUS_PUBLISHING,
			'provider_task_id' => 'post-77',
		] );

		// A foreign signature (signed with the OTHER store's secret).
		$foreign = $this->signed_event( 'whsec-test-2', [
			'event'    => 'post.published',
			'event_id' => 'ev-bad',
			'data'     => [ 'accountId' => 'acc-1', 'status' => 'published' ],
		] );
		$bad = $this->publisher->handle_post_event( $foreign['payload'], $this->headers( $foreign['signature'], $foreign['timestamp'] ) );
		$this->assert_false( $bad['ok'], 'a foreign signature is refused' );
		$this->assert_same( 'invalid_signature', $bad['status'], 'the status says why' );

		// A replayed (stale) timestamp, correctly signed.
		$stale = $this->signed_event( 'whsec-test', [
			'event'    => 'post.published',
			'event_id' => 'ev-stale',
			'data'     => [ 'accountId' => 'acc-1', 'status' => 'published' ],
		] );
		$replay = $this->publisher->handle_post_event( $stale['payload'], $this->headers( $stale['signature'], $stale['timestamp'] - 3600 ) );
		$this->assert_false( $replay['ok'], 'a replayed event is refused' );
		$this->assert_same( 'invalid_signature', $replay['status'], 'the status says why' );

		$this->assert_same( ContentPublishService::STATUS_PUBLISHING, $this->db->content[62]['status'], 'the row never moved' );
		$this->assert_same( 0, count( $this->db->events ), 'nothing was captured' );
	}

	private function late_events_move_forward_only(): void {
		$this->fresh();

		// A published row never reverts on a late failure event.
		$this->seed_content( 71, 1, [
			'status'           => ContentPublishService::STATUS_PUBLISHED,
			'provider_task_id' => 'post-p',
			'published_at'     => current_time( 'mysql', true ),
		] );
		$fail = $this->signed_event( 'whsec-test', [
			'event'    => 'post.failed',
			'event_id' => 'ev-late-fail',
			'data'     => [ 'postId' => 'post-p', 'accountId' => 'acc-1', 'status' => 'failed' ],
		] );
		$r = $this->publisher->handle_post_event( $fail['payload'], $this->headers( $fail['signature'], $fail['timestamp'] ) );
		$this->assert_true( $r['ok'], 'the late event is received' );
		$this->assert_same( ContentPublishService::STATUS_PUBLISHED, $this->db->content[71]['status'], 'a published row never reverts' );

		// A failed row advances on the real published event (the provider's own
		// transient retry succeeded after all — that IS the real outcome).
		$this->seed_content( 72, 1, [
			'status'           => ContentPublishService::STATUS_FAILED,
			'provider_task_id' => 'post-q',
			'last_error'       => 'provider_post_failed',
		] );
		$pub = $this->signed_event( 'whsec-test', [
			'event'    => 'post.published',
			'event_id' => 'ev-late-pub',
			'data'     => [ 'postId' => 'post-q', 'accountId' => 'acc-1', 'status' => 'published', 'permalink' => 'https://instagram.com/p/q' ],
		] );
		$r = $this->publisher->handle_post_event( $pub['payload'], $this->headers( $pub['signature'], $pub['timestamp'] ) );
		$this->assert_true( $r['ok'], 'the published event is received' );
		$this->assert_same( ContentPublishService::STATUS_PUBLISHED, $this->db->content[72]['status'], 'forward progress is accepted' );
	}

	private function retry_reconciles_uses_the_provider_retry_and_caps(): void {
		$this->fresh();
		$this->seed_content( 81, 1, [
			'status'           => ContentPublishService::STATUS_FAILED,
			'provider_task_id' => 'post-9',
			'last_error'       => 'provider_post_failed',
			'retry_count'      => 1,
		] );

		// Most specific match first: the GET must not swallow the retry answer.
		$this->queue_http( [
			'match' => '/posts/post-9/retry',
			'status' => 200,
			'body'   => wp_json_encode( [ 'id' => 'post-9' ] ),
		] );
		$this->queue_http( [
			'match' => '/posts/post-9',
			'status' => 200,
			'body'   => wp_json_encode( [ 'post' => [ 'status' => 'failed' ] ] ),
		] );

		$result = $this->publisher->retry( 1, 81 );
		$this->assert_true( $result['ok'], 'the retry is driven' );
		$this->assert_same( ContentPublishService::STATUS_PUBLISHING, $this->db->content[81]['status'], 'the row is in flight again' );
		$this->assert_same( 2, (int) $this->db->content[81]['retry_count'], 'the attempt is counted' );

		$requests = $this->http_requests();
		$this->assert_same( 2, count( $requests ), 'reconcile read + provider retry, no blind re-create' );
		$this->assert_same( 'GET', $requests[0]['method'], 'reconciliation comes first' );
		$this->assert_same( 'POST', $requests[1]['method'], 'then the provider retry endpoint' );
		$this->assert_contains( '/posts/post-9/retry', $requests[1]['url'], 'the retry targets the task' );

		// At the cap: refused, and nothing leaves the building.
		$this->db->content[81]['status']      = ContentPublishService::STATUS_FAILED;
		$this->db->content[81]['retry_count'] = ContentPublishService::MAX_RETRIES;
		$capped = $this->publisher->retry( 1, 81 );
		$this->assert_false( $capped['ok'], 'the cap holds' );
		$this->assert_same( 'retry_limit_reached', $capped['error'], 'the cap is named' );
		$this->assert_same( 2, count( $this->http_requests() ), 'no call at the cap' );
	}

	private function retry_without_a_task_republishes_under_the_same_key(): void {
		$this->fresh();
		// The create itself failed on the transport: there is no task to retry.
		$this->seed_content( 82, 1, [
			'status'     => ContentPublishService::STATUS_FAILED,
			'last_error' => 'timeout',
		] );

		$this->queue_http( [
			'match' => '/posts',
			'status' => 200,
			'body'   => wp_json_encode( [ 'post' => [ '_id' => 'post-82' ] ] ),
		] );

		$result = $this->publisher->retry( 1, 82 );
		$this->assert_true( $result['ok'], 'a task-less failure can be retried' );
		$this->assert_same( 'post-82', $this->db->content[82]['provider_task_id'], 'the new task anchors' );
		$this->assert_same( 1, (int) $this->db->content[82]['retry_count'], 'the attempt is counted' );

		$request = $this->http_requests()[0];
		$this->assert_same( 'content:82', (string) ( $request['headers']['Idempotency-Key'] ?? '' ), 'the SAME stable key — the provider treats it as the same logical create' );
	}

	private function catalog_voice_is_gated_and_never_faked(): void {
		$this->fresh();
		$this->db->registrations[101] = [
			'id'         => 101,
			'tenant_id'  => 1,
			'product_id' => 7,
			'voice_url'  => 'https://cdn.test/voice-7.mp3',
		];
		$this->db->registrations[102] = [
			'id'         => 102,
			'tenant_id'  => 1,
			'product_id' => 8,
			'voice_url'  => '',
		];

		// Gate OFF (the default): image only, even though a real voice exists.
		$this->seed_content( 91, 1, [ 'product_id' => 7 ] );
		$this->queue_http( [
			'match' => '/posts',
			'status' => 200,
			'body'   => wp_json_encode( [ 'post' => [ '_id' => 'post-91' ] ] ),
		] );
		$this->assert_true( $this->publisher->publish_now( 1, 91 )['ok'], 'publishes with the gate off' );
		$body = json_decode( (string) $this->http_requests()[0]['body'], true );
		$this->assert_same( [ 'https://cdn.test/img-91.jpg' ], (array) ( $body['media'] ?? [] ), 'gate off: no audio, image only' );

		// Gate ON with a real voice: it is attached.
		$this->settings->set( 'igbz.publisher_audio', 1 );
		$this->seed_content( 92, 1, [ 'product_id' => 7 ] );
		$this->queue_http( [
			'match' => '/posts',
			'status' => 200,
			'body'   => wp_json_encode( [ 'post' => [ '_id' => 'post-92' ] ] ),
		] );
		$this->assert_true( $this->publisher->publish_now( 1, 92 )['ok'], 'publishes with the gate on' );
		$body = json_decode( (string) $this->http_requests()[1]['body'], true );
		$this->assert_same( [ 'https://cdn.test/img-92.jpg', 'https://cdn.test/voice-7.mp3' ], (array) ( $body['media'] ?? [] ), 'gate on: the catalog voice is attached' );

		// Gate ON but the registration has no real voice: still no audio. No fake.
		$this->seed_content( 93, 1, [ 'product_id' => 8 ] );
		$this->queue_http( [
			'match' => '/posts',
			'status' => 200,
			'body'   => wp_json_encode( [ 'post' => [ '_id' => 'post-93' ] ] ),
		] );
		$this->assert_true( $this->publisher->publish_now( 1, 93 )['ok'], 'publishes' );
		$body = json_decode( (string) $this->http_requests()[2]['body'], true );
		$this->assert_same( [ 'https://cdn.test/img-93.jpg' ], (array) ( $body['media'] ?? [] ), 'no real voice: nothing is invented' );
	}

	private function partial_provider_state_is_a_failure(): void {
		$this->fresh();
		$this->seed_content( 94, 1, [
			'status'           => ContentPublishService::STATUS_PUBLISHING,
			'provider_task_id' => 'post-partial',
			'updated_at'       => gmdate( 'Y-m-d H:i:s', time() - 400 ),
		] );

		$this->queue_http( [
			'match' => '/posts/post-partial',
			'status' => 200,
			'body'   => wp_json_encode( [ 'post' => [ 'status' => 'partial' ] ] ),
		] );

		$counts = $this->publisher->reconcile();
		$this->assert_same( 1, $counts['failed'], 'a partial is counted as failed' );
		$this->assert_same( ContentPublishService::STATUS_FAILED, $this->db->content[94]['status'], 'the row is failed' );
		$this->assert_same( 'provider_partial_failure', $this->db->content[94]['last_error'], 'the reason is named' );
		$this->assert_same( ContentPublishService::PROVIDER_PARTIAL, $this->db->content[94]['provider_status'], 'the provider word is kept' );
	}

	private function tenant_isolation_holds_on_content_and_events(): void {
		$this->fresh();
		$this->seed_content( 95, 1, [
			'status'           => ContentPublishService::STATUS_PUBLISHING,
			'provider_task_id' => 'post-77',
		] );

		// The other store cannot even see the row.
		$foreign = $this->publisher->publish_now( 2, 95 );
		$this->assert_false( $foreign['ok'], 'cross-tenant access is refused' );
		$this->assert_same( 'not_found', $foreign['error'], 'it does not leak existence' );

		// A webhook for the same provider post under the OTHER account routes to
		// the other tenant and finds no row there — the original row is untouched.
		$event = $this->signed_event( 'whsec-test-2', [
			'event'    => 'post.published',
			'event_id' => 'ev-isolated',
			'data'     => [ 'postId' => 'post-77', 'accountId' => 'acc-2', 'status' => 'published' ],
		] );
		$result = $this->publisher->handle_post_event( $event['payload'], $this->headers( $event['signature'], $event['timestamp'] ) );
		$this->assert_true( $result['ok'], 'the event is received' );
		$this->assert_same( ContentPublishService::STATUS_PUBLISHING, $this->db->content[95]['status'], 'tenant 1 row untouched' );

		$ledger = array_values( $this->db->events );
		$this->assert_same( 1, count( $ledger ), 'one ledger row' );
		$this->assert_same( 2, (int) $ledger[0]['tenant_id'], 'it belongs to tenant 2' );
		$this->assert_same( 0, (int) $ledger[0]['content_id'], 'no content row was linked' );
		$this->assert_same( 'no_content_row', $ledger[0]['outcome'], 'the outcome is honest' );

		// Listing is tenant-scoped too.
		$this->assert_same( 1, count( $this->publisher->list_content( 1 ) ), 'tenant 1 sees its row' );
		$this->assert_same( 0, count( $this->publisher->list_content( 2 ) ), 'tenant 2 sees none of it' );
	}

	private function unconnected_store_cannot_publish(): void {
		$this->fresh();
		$this->seed_content( 96, 3 );

		$result = $this->publisher->publish_now( 3, 96 );
		$this->assert_false( $result['ok'], 'an unconnected store cannot publish' );
		$this->assert_same( 'not_connected', $result['error'], 'the guard says why' );
		$this->assert_same( ContentPublishService::STATUS_DRAFT, $this->db->content[96]['status'], 'the row stays a draft' );
		$this->assert_same( 0, count( $this->http_requests() ), 'nothing left the building' );
	}
}
