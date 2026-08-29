<?php
/**
 * Phase 51 — the Zernio inbox and the comment-to-DM pipeline (ADR-0004 §6).
 *
 * The full decision path runs here: signed webhook capture, server-side
 * ownership mapping, dedupe, opt-out, backend rules, rate limits, human
 * approval, idempotent delivery and the failure ledger. The provider itself
 * is the real ZernioClient against the HTTP double, so request shapes (URL,
 * Bearer key, Idempotency-Key) are asserted as they go out the door.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\Instagram\Gateways\ZernioClient;
use IGBZ\Suite\Modules\Instagram\Services\InboxService;
use IGBZ\Suite\Modules\Instagram\Services\ZernioConnectionService;
use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Http;
use IGBZ\Suite\Support\Logger;

/**
 * In-memory engine for the inbox: profiles, captured events, the delivery
 * ledger, rules and opt-outs are real rows, so the service's SELECT/UPDATE
 * statements run against state.
 */
final class ZernioInboxDb extends wpdb {

	/** @var array<string,array<string,mixed>> tenant_id => profile row */
	public array $profiles = [];

	/** @var array<int,array<string,mixed>> id => row */
	public array $inbox   = [];

	/** @var array<int,array<string,mixed>> id => row */
	public array $actions = [];

	/** @var array<string,array<int,array<string,mixed>>> tenant_id => rows */
	public array $rules   = [];

	/** @var array<string,array<string,array<string,mixed>>> tenant_id => sender_id => row */
	public array $optouts = [];

	private int $next_id = 1;

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;

		// Delivery/approval profile lookup: inbox row -> profile, tenant-checked.
		if ( str_contains( $sql, 'ig_zernio_inbox i' ) && str_contains( $sql, 'ig_zernio_profiles p' ) ) {
			if ( ! preg_match( "/i\.id = '(\d+)' AND p\.tenant_id = '(\d+)'/", $sql, $m ) ) {
				return null;
			}
			$row = $this->inbox[ (int) $m[1] ] ?? null;
			if ( null === $row || (string) $row['tenant_id'] !== $m[2] ) {
				return null;
			}
			return $this->profiles[ $m[2] ] ?? null;
		}

		// The webhook ownership map: account id -> profile row.
		if ( str_contains( $sql, 'ig_zernio_profiles' ) && preg_match( "/p\.account_id = '([^']*)'/", $sql, $m ) ) {
			foreach ( $this->profiles as $row ) {
				if ( (string) $row['account_id'] === $m[1] ) {
					return $row;
				}
			}
			return null;
		}

		// The connection service's per-tenant profile read.
		if ( str_contains( $sql, 'ig_zernio_profiles' ) && preg_match( "/tenant_id = '(\d+)'/", $sql, $m ) ) {
			return $this->profiles[ $m[1] ] ?? null;
		}

		// Dedupe probe: (profile, event) pair.
		if ( str_contains( $sql, 'ig_zernio_inbox' ) && preg_match( "/profile_id = '(\d+)' AND event_id = '([^']*)'/", $sql, $m ) ) {
			foreach ( $this->inbox as $row ) {
				if ( (string) $row['profile_id'] === $m[1] && (string) $row['event_id'] === $m[2] ) {
					return $row;
				}
			}
			return null;
		}

		// The captured event itself.
		if ( str_contains( $sql, 'ig_zernio_inbox' ) && preg_match( "/WHERE id = '(\d+)' LIMIT 1/", $sql, $m ) ) {
			return $this->inbox[ (int) $m[1] ] ?? null;
		}

		// The delivery ledger, tenant-scoped or not.
		if ( str_contains( $sql, 'ig_inbox_actions' ) && preg_match( "/id = '(\d+)' AND tenant_id = '(\d+)'/", $sql, $m ) ) {
			$row = $this->actions[ (int) $m[1] ] ?? null;
			return ( null !== $row && (string) $row['tenant_id'] === $m[2] ) ? $row : null;
		}
		if ( str_contains( $sql, 'ig_inbox_actions' ) && preg_match( "/WHERE id = '(\d+)' LIMIT 1/", $sql, $m ) ) {
			return $this->actions[ (int) $m[1] ] ?? null;
		}

		// The opt-out register.
		if ( str_contains( $sql, 'ig_inbox_optouts' ) && preg_match( "/tenant_id = '(\d+)' AND sender_id = '([^']*)'/", $sql, $m ) ) {
			return $this->optouts[ $m[1] ][ $m[2] ] ?? null;
		}

		return parent::get_row( $sql, $output );
	}

	public function get_results( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'ig_inbox_rules' ) && preg_match( "/tenant_id = '(\d+)'/", $sql, $m ) ) {
			$rows = array_values( $this->rules[ $m[1] ] ?? [] );
			usort( $rows, static fn ( $a, $b ) => ( (int) $a['priority'] <=> (int) $b['priority'] ) ?: ( (int) $a['id'] <=> (int) $b['id'] ) );

			return $rows;
		}

		if ( str_contains( $sql, 'ig_zernio_inbox' ) && preg_match( "/tenant_id = '(\d+)'/", $sql, $m ) ) {
			$rows = array_values( array_filter( $this->inbox, static fn ( $r ) => (string) $r['tenant_id'] === $m[1] ) );
			usort( $rows, static fn ( $a, $b ) => (int) $b['id'] <=> (int) $a['id'] );

			return $rows;
		}

		if ( str_contains( $sql, 'ig_inbox_actions' ) && preg_match( "/tenant_id = '(\d+)'/", $sql, $m ) ) {
			$rows = array_values( array_filter( $this->actions, static fn ( $r ) => (string) $r['tenant_id'] === $m[1] ) );
			usort( $rows, static fn ( $a, $b ) => (int) $b['id'] <=> (int) $a['id'] );

			return $rows;
		}

		return parent::get_results( $sql, $output );
	}

	public function get_var( string $sql ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'ig_inbox_optouts' ) && preg_match( "/tenant_id = '(\d+)' AND sender_id = '([^']*)'/", $sql, $m ) ) {
			return $this->optouts[ $m[1] ][ $m[2] ]['id'] ?? 0;
		}

		if ( str_contains( $sql, 'SELECT COUNT(*)' ) && str_contains( $sql, 'ig_inbox_actions' ) ) {
			if ( ! preg_match( "/tenant_id = '(\d+)'/", $sql, $m ) ) {
				return parent::get_var( $sql );
			}
			$tenant = $m[1];

			// Per-sender count rides a join on the captured event.
			if ( str_contains( $sql, 'JOIN' ) && preg_match( "/i\.sender_id = '([^']*)'/", $sql, $s ) ) {
				$count = 0;
				foreach ( $this->actions as $action ) {
					if ( (string) $action['tenant_id'] !== $tenant || ! in_array( (string) $action['state'], [ 'sent', 'queued', 'pending_approval' ], true ) ) {
						continue;
					}
					$event = $this->inbox[ (int) $action['inbox_id'] ] ?? null;
					if ( null !== $event && (string) $event['sender_id'] === $s[1] ) {
						++$count;
					}
				}
				return $count;
			}

			$count = 0;
			foreach ( $this->actions as $action ) {
				if ( (string) $action['tenant_id'] === $tenant && in_array( (string) $action['state'], [ 'sent', 'queued', 'pending_approval' ], true ) ) {
					++$count;
				}
			}
			return $count;
		}

		return parent::get_var( $sql );
	}

	public function insert( string $table, array $data, $format = null ): int|bool {
		$id   = $this->next_id++;
		$data = $data + [ 'id' => $id ];

		if ( str_ends_with( $table, 'ig_zernio_inbox' ) ) {
			$this->inbox[ $id ] = $data;
		} elseif ( str_ends_with( $table, 'ig_inbox_actions' ) ) {
			$this->actions[ $id ] = $data;
		} elseif ( str_ends_with( $table, 'ig_inbox_rules' ) ) {
			$this->rules[ (string) $data['tenant_id'] ][] = $data;
		} elseif ( str_ends_with( $table, 'ig_inbox_optouts' ) ) {
			$this->optouts[ (string) $data['tenant_id'] ][ (string) $data['sender_id'] ] = $data;
		}

		$this->insert_id = $id;

		return $id;
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		$changed = 0;
		$id      = (int) ( $where['id'] ?? 0 );
		$tenant  = isset( $where['tenant_id'] ) ? (string) $where['tenant_id'] : null;

		if ( str_ends_with( $table, 'ig_zernio_inbox' ) && isset( $this->inbox[ $id ] ) ) {
			if ( null === $tenant || (string) $this->inbox[ $id ]['tenant_id'] === $tenant ) {
				$this->inbox[ $id ] = array_merge( $this->inbox[ $id ], $data );
				++$changed;
			}
		} elseif ( str_ends_with( $table, 'ig_inbox_actions' ) && isset( $this->actions[ $id ] ) ) {
			if ( null === $tenant || (string) $this->actions[ $id ]['tenant_id'] === $tenant ) {
				$this->actions[ $id ] = array_merge( $this->actions[ $id ], $data );
				++$changed;
			}
		} elseif ( str_ends_with( $table, 'ig_inbox_rules' ) ) {
			foreach ( $this->rules as $t => $rows ) {
				foreach ( $rows as $i => $row ) {
					if ( (int) $row['id'] === $id && ( null === $tenant || (string) $t === $tenant ) ) {
						$this->rules[ $t ][ $i ] = array_merge( $row, $data );
						++$changed;
					}
				}
			}
		}

		return $changed;
	}
}

final class InboxTest extends TestCase {

	private ZernioInboxDb $db;

	private InboxService $inbox;

	/** @var int[] */
	private array $http_urls = [];

	public function run(): void {
		$this->fresh();

		$this->unmapped_account_is_refused_without_side_effects();
		$this->bad_signature_is_refused();
		$this->stale_timestamp_is_refused();
		$this->event_is_stored_and_retries_are_deduplicated();
		$this->comment_to_dm_waits_for_approval_by_default();
		$this->reject_is_final();
		$this->no_matching_rule_ignores_the_event();
		$this->opt_out_phrases_stop_the_pipeline();
		$this->sender_rate_limit_stops_the_excess();
		$this->tenant_rate_limit_stops_a_new_sender();
		$this->failed_delivery_stays_retryable_with_the_same_key();
		$this->reply_action_targets_the_comment();
		$this->foreign_tenant_cannot_touch_the_ledger();
	}

	private function fresh(): void {
		$this->db    = new ZernioInboxDb();
		$GLOBALS['wpdb'] = $this->db;
		$this->http_urls = [];

		$settings = igbz_test_reset_settings();

		// A connected store: its profile-scoped key and its own webhook secret.
		$now        = current_time( 'mysql', true );
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
		// A second store with its own account, for the isolation test.
		$this->db->profiles['2'] = $this->db->profiles['1'] + [
			'id'           => 22,
			'tenant_id'    => 2,
			'profile_id'   => 'prof-2',
			'account_id'   => 'acc-2',
		];

		$logger     = new Logger( $settings );
		$client     = new ZernioClient( new Http( $logger ), $logger );
		$connection = new ZernioConnectionService( new Db(), $logger, $client );
		$this->inbox = new InboxService( new Db(), $logger, $connection, $client, $settings );

		$this->db->rules['1'] = [
			[
				'id'         => 501,
				'tenant_id'  => 1,
				'name'       => 'price questions',
				'source'     => 'comment',
				'keyword'    => 'قیمت',
				'action'     => InboxService::ACTION_DM,
				'template'   => 'سلام {username}! قیمت را در صفحهٔ محصول دیدید.',
				'priority'   => 10,
				'active'     => 1,
				'created_at' => $now,
			],
		];
	}

	/** @return array<string,string> */
	private function signed( string $raw, ?int $ts = null, string $secret = 'whsec-test' ): array {
		$ts = $ts ?? time();

		return [
			'X-Zernio-Timestamp' => (string) $ts,
			'X-Zernio-Signature' => Crypto::hmac( $raw . '.' . $ts, $secret ),
		];
	}

	private function comment_payload( string $event_id, string $sender_id, string $username, string $text, string $account = 'acc-1' ): string {
		return wp_json_encode(
			[
				'event'    => 'comment.created',
				'event_id' => $event_id,
				'data'     => [
					'accountId' => $account,
					'postId'    => 'p-100',
					'sender'    => [ 'id' => $sender_id, 'username' => $username ],
					'text'      => $text,
					'timestamp' => gmdate( 'c', time() - 60 ),
				],
			]
		);
	}

	private function last_delivery_requests(): array {
		return array_values(
			array_filter(
				$GLOBALS['igbz_test_http_requests'],
				static fn ( $r ) => str_contains( (string) $r['url'], '/messages' ) || str_contains( (string) $r['url'], '/comments/' )
			)
		);
	}

	private function reset_http(): void {
		$GLOBALS['igbz_test_http'] = [];
		$GLOBALS['igbz_test_http_requests'] = [];
	}

	// ------------------------------------------------------------ the gates

	private function unmapped_account_is_refused_without_side_effects(): void {
		$this->fresh();
		$this->reset_http();
		$raw = $this->comment_payload( 'evt-x', 'u-1', 'user1', 'سلام', 'acc-someone-else' );

		$result = $this->inbox->handle_webhook( $raw, $this->signed( $raw ) );

		$this->assert_same( 'unknown_account', $result['status'], 'an unmapped account is refused' );
		$this->assert_same( 0, count( $this->db->inbox ), 'nothing is stored for a stranger' );
		$this->assert_same( 0, count( $this->last_delivery_requests() ), 'no provider call for a stranger' );
	}

	private function bad_signature_is_refused(): void {
		$this->fresh();
		$this->reset_http();
		$raw = $this->comment_payload( 'evt-x', 'u-1', 'user1', 'سلام' );

		$result = $this->inbox->handle_webhook( $raw, $this->signed( $raw, null, 'wrong-secret' ) );

		$this->assert_same( 'invalid_signature', $result['status'], 'a foreign signature is refused' );
		$this->assert_same( 0, count( $this->db->inbox ), 'nothing is stored on a bad signature' );
	}

	private function stale_timestamp_is_refused(): void {
		$this->fresh();
		$this->reset_http();
		$raw = $this->comment_payload( 'evt-x', 'u-1', 'user1', 'سلام' );

		$result = $this->inbox->handle_webhook( $raw, $this->signed( $raw, time() - 3600 ) );

		$this->assert_same( 'invalid_signature', $result['status'], 'a replayed event (outside the window) is refused' );
		$this->assert_same( 0, count( $this->db->inbox ), 'a stale replay stores nothing' );
	}

	// ------------------------------------------------------------- the flow

	private function event_is_stored_and_retries_are_deduplicated(): void {
		$this->fresh();
		$this->reset_http();
		$settings = igbz()->settings();
		$settings->set( 'igbz.inbox_auto_approve', 1 );
		igbz_test_queue_http( [ 'body' => wp_json_encode( [ 'messageId' => 'msg-1' ] ) ] );

		$raw    = $this->comment_payload( 'evt-1', 'u-9', 'user9', 'سلام، قیمت چنده؟' );
		$result = $this->inbox->handle_webhook( $raw, $this->signed( $raw ) );

		$this->assert_same( 'received', $result['status'], 'a valid event is received' );
		$this->assert_same( 1, count( $this->db->inbox ), 'the event is captured' );
		$this->assert_same( 1, count( $this->db->actions ), 'a delivery row exists' );

		// The provider saw exactly one DM, on the store's own key, idempotent.
		$sends = $this->last_delivery_requests();
		$this->assert_same( 1, count( $sends ), 'one provider call' );
		$this->assert_true( str_contains( (string) $sends[0]['url'], '/messages' ), 'the DM endpoint' );
		$this->assert_same( 'Bearer sk-store', (string) $sends[0]['headers']['Authorization'], 'the store key, never the central one' );
		$this->assert_same( 'inbox:1', (string) $sends[0]['headers']['Idempotency-Key'], 'the stable idempotency key' );

		// The provider retries the delivery: same event, same signature.
		$retry = $this->inbox->handle_webhook( $raw, $this->signed( $raw ) );
		$this->assert_same( 'duplicate', $retry['status'], 'the retry is a deduplicated no-op' );
		$this->assert_same( 1, count( $this->db->inbox ), 'still one captured event' );
		$this->assert_same( 1, count( $this->last_delivery_requests() ), 'still one provider call' );
	}

	private function comment_to_dm_waits_for_approval_by_default(): void {
		$this->fresh();
		$this->reset_http();
		// Auto-approve stays off (the default): a human approves first.
		$raw    = $this->comment_payload( 'evt-2', 'u-9', 'user9', 'قیمت لطفا' );
		$result = $this->inbox->handle_webhook( $raw, $this->signed( $raw ) );

		$this->assert_same( 'received', $result['status'], 'the event is received' );
		$inbox_row = $this->db->inbox[ (int) $result['id'] ];
		$this->assert_same( InboxService::STATUS_PENDING_APPROVAL, (string) $inbox_row['status'], 'the event waits for approval' );
		$this->assert_same( 1, count( $this->db->actions ), 'the decision row exists' );

		$action_id = (int) array_key_first( $this->db->actions );
		$this->assert_same( InboxService::STATE_PENDING_APPROVAL, (string) $this->db->actions[ $action_id ]['state'], 'the action is pending' );
		$this->assert_same( 0, count( $this->last_delivery_requests() ), 'nothing leaves the building before approval' );

		// The human approves: exactly one provider call, same idempotency key. The key is
		// anchored to the captured event (inbox:<event_id>), so the same event can never
		// be delivered twice no matter how many times the approval path is walked.
		igbz_test_queue_http( [ 'body' => wp_json_encode( [ 'messageId' => 'msg-2' ] ) ] );
		$approval = $this->inbox->approve( 1, $action_id );

		$this->assert_true( $approval['ok'], 'approval succeeds' );
		$sends = $this->last_delivery_requests();
		$this->assert_same( 1, count( $sends ), 'approval triggers exactly one provider call' );
		$this->assert_same( 'inbox:' . (int) $result['id'], (string) $sends[0]['headers']['Idempotency-Key'], 'the idempotency key is anchored to the event, stable across the approval' );
		$this->assert_same( InboxService::STATE_SENT, (string) $this->db->actions[ $action_id ]['state'], 'the action is sent' );
		$this->assert_same( 'msg-2', (string) $this->db->actions[ $action_id ]['provider_ref'], 'the provider ref is recorded' );
		$this->assert_same( InboxService::STATUS_SENT, (string) $this->db->inbox[ (int) $result['id'] ]['status'], 'the event is marked sent' );

		// Approving twice is refused: it is not pending any more.
		$again = $this->inbox->approve( 1, $action_id );
		$this->assert_false( $again['ok'], 'a second approval is refused' );
		$this->assert_same( 1, count( $this->last_delivery_requests() ), 'no double delivery' );
	}

	private function reject_is_final(): void {
		$this->fresh();
		$this->reset_http();
		$raw    = $this->comment_payload( 'evt-3', 'u-3', 'user3', 'قیمت؟' );
		$result = $this->inbox->handle_webhook( $raw, $this->signed( $raw ) );
		$action_id = (int) array_key_first( $this->db->actions );

		$reject = $this->inbox->reject( 1, $action_id );

		$this->assert_true( $reject['ok'], 'rejection succeeds' );
		$this->assert_same( InboxService::STATE_REJECTED, (string) $this->db->actions[ $action_id ]['state'], 'the action is rejected, final' );
		$this->assert_same( 0, count( $this->last_delivery_requests() ), 'a rejected reply is never delivered' );
		$approve_after = $this->inbox->approve( 1, $action_id );
		$this->assert_false( $approve_after['ok'], 'a rejected action cannot be approved later' );
	}

	private function no_matching_rule_ignores_the_event(): void {
		$this->fresh();
		$this->reset_http();
		// The only rule matches the keyword قیمت — a greeting does not.
		$raw    = $this->comment_payload( 'evt-4', 'u-4', 'user4', 'عکس خیلی قشنگه' );
		$result = $this->inbox->handle_webhook( $raw, $this->signed( $raw ) );

		$this->assert_same( 'received', $result['status'], 'the event is still captured' );
		$this->assert_same( InboxService::STATUS_IGNORED, (string) $this->db->inbox[ (int) $result['id'] ]['status'], 'no rule matches: ignored' );
		$this->assert_same( 0, count( $this->db->actions ), 'no decision row for an ignored event' );
		$this->assert_same( 0, count( $this->last_delivery_requests() ), 'nothing is sent' );
	}

	private function opt_out_phrases_stop_the_pipeline(): void {
		$this->fresh();
		$this->reset_http();
		// A user says stop, in a DM.
		$raw = wp_json_encode(
			[
				'event'    => 'message.received',
				'event_id' => 'evt-5',
				'data'     => [
					'accountId' => 'acc-1',
					'sender'    => [ 'id' => 'u-5', 'username' => 'user5' ],
					'text'      => 'نه',
					'timestamp' => gmdate( 'c', time() - 60 ),
				],
			]
		);
		$result = $this->inbox->handle_webhook( $raw, $this->signed( $raw ) );

		$this->assert_same( InboxService::STATUS_SKIPPED_OPTOUT, (string) $this->db->inbox[ (int) $result['id'] ]['status'], 'the stop is registered' );
		$this->assert_true( isset( $this->db->optouts['1']['u-5'] ), 'the opt-out row exists' );

		// Later, the same user comments on a post: still no reply.
		$raw2    = $this->comment_payload( 'evt-6', 'u-5', 'user5', 'قیمت؟' );
		$result2 = $this->inbox->handle_webhook( $raw2, $this->signed( $raw2 ) );
		$this->assert_same( InboxService::STATUS_SKIPPED_OPTOUT, (string) $this->db->inbox[ (int) $result2['id'] ]['status'], 'an opted-out user is never replied to again' );
		$this->assert_same( 0, count( $this->last_delivery_requests() ), 'nothing is sent to an opted-out user' );
	}

	private function sender_rate_limit_stops_the_excess(): void {
		$this->fresh();
		$this->reset_http();
		$settings = igbz()->settings();
		$settings->set( 'igbz.inbox_auto_approve', 1 );
		$settings->set( 'igbz.inbox_rate_limit_sender', 2 );

		for ( $i = 0; $i < 2; $i++ ) {
			igbz_test_queue_http( [ 'body' => wp_json_encode( [ 'messageId' => 'msg-s' . $i ] ) ] );
			$this->inbox->handle_webhook( $this->comment_payload( 'evt-s' . $i, 'u-hot', 'hot', 'قیمت' ), $this->signed( $this->comment_payload( 'evt-s' . $i, 'u-hot', 'hot', 'قیمت' ) ) );
		}
		$this->assert_same( 2, count( $this->last_delivery_requests() ), 'the first two replies go out' );

		// Third reply to the same user in the same hour: stored, but not sent.
		$raw    = $this->comment_payload( 'evt-s3', 'u-hot', 'hot', 'قیمت دوباره' );
		$result = $this->inbox->handle_webhook( $raw, $this->signed( $raw ) );
		$this->assert_same( InboxService::STATUS_SKIPPED_RLIMIT, (string) $this->db->inbox[ (int) $result['id'] ]['status'], 'the sender cap stops the third reply' );
		$this->assert_same( 2, count( $this->last_delivery_requests() ), 'no third provider call' );

		// A different user is not affected.
		igbz_test_queue_http( [ 'body' => wp_json_encode( [ 'messageId' => 'msg-s4' ] ) ] );
		$this->inbox->handle_webhook( $this->comment_payload( 'evt-s4', 'u-other', 'other', 'قیمت' ), $this->signed( $this->comment_payload( 'evt-s4', 'u-other', 'other', 'قیمت' ) ) );
		$this->assert_same( 3, count( $this->last_delivery_requests() ), 'another user is unaffected' );
	}

	private function tenant_rate_limit_stops_a_new_sender(): void {
		$this->fresh();
		$this->reset_http();
		$settings = igbz()->settings();
		$settings->set( 'igbz.inbox_auto_approve', 1 );
		$settings->set( 'igbz.inbox_rate_limit_tenant', 1 );
		$settings->set( 'igbz.inbox_rate_limit_sender', 0 );

		igbz_test_queue_http( [ 'body' => wp_json_encode( [ 'messageId' => 'msg-t0' ] ) ] );
		$this->inbox->handle_webhook( $this->comment_payload( 'evt-t0', 'u-a', 'a', 'قیمت' ), $this->signed( $this->comment_payload( 'evt-t0', 'u-a', 'a', 'قیمت' ) ) );
		$this->assert_same( 1, count( $this->last_delivery_requests() ), 'the first reply of the hour goes out' );

		$raw    = $this->comment_payload( 'evt-t1', 'u-b', 'b', 'قیمت' );
		$result = $this->inbox->handle_webhook( $raw, $this->signed( $raw ) );
		$this->assert_same( InboxService::STATUS_SKIPPED_RLIMIT, (string) $this->db->inbox[ (int) $result['id'] ]['status'], 'the store cap stops a new sender too' );
		$this->assert_same( 1, count( $this->last_delivery_requests() ), 'no second provider call' );
	}

	private function failed_delivery_stays_retryable_with_the_same_key(): void {
		$this->fresh();
		$this->reset_http();
		$settings = igbz()->settings();
		$settings->set( 'igbz.inbox_auto_approve', 1 );

		igbz_test_queue_http( [ 'status' => 503, 'body' => wp_json_encode( [ 'message' => 'upstream unavailable' ] ) ] );
		$raw    = $this->comment_payload( 'evt-f', 'u-f', 'userf', 'قیمت' );
		$result = $this->inbox->handle_webhook( $raw, $this->signed( $raw ) );

		$action_id = (int) array_key_first( $this->db->actions );
		$this->assert_same( InboxService::STATE_FAILED, (string) $this->db->actions[ $action_id ]['state'], 'the failure lands in the ledger' );
		$this->assert_same( 'upstream unavailable', (string) $this->db->actions[ $action_id ]['error'], 'the provider error is recorded' );
		$this->assert_same( InboxService::STATUS_FAILED, (string) $this->db->inbox[ (int) $result['id'] ]['status'], 'the event is marked failed' );

		// The operator retries: same idempotency key, so a success can never duplicate.
		$first_key = $this->last_delivery_requests()[0]['headers']['Idempotency-Key'] ?? null;
		igbz_test_queue_http( [ 'body' => wp_json_encode( [ 'messageId' => 'msg-f-retry' ] ) ] );
		$retry = $this->inbox->retry_failed( 1, $action_id );

		$this->assert_true( $retry['ok'], 'the retry is accepted' );
		$sends = $this->last_delivery_requests();
		$this->assert_same( 2, count( $sends ), 'the retry reaches the provider once' );
		$this->assert_same( (string) $first_key, (string) $sends[1]['headers']['Idempotency-Key'], 'the retry reuses the original idempotency key' );
		$this->assert_same( InboxService::STATE_SENT, (string) $this->db->actions[ $action_id ]['state'], 'the action ends sent' );

		// Retrying a sent action is refused.
		$again = $this->inbox->retry_failed( 1, $action_id );
		$this->assert_false( $again['ok'], 'a sent action cannot be retried' );
	}

	private function reply_action_targets_the_comment(): void {
		$this->fresh();
		$this->reset_http();
		$settings = igbz()->settings();
		$settings->set( 'igbz.inbox_auto_approve', 1 );
		$this->db->rules['1'][] = [
			'id'         => 502,
			'tenant_id'  => 1,
			'name'       => 'public thank-you',
			'source'     => 'comment',
			'keyword'    => 'ممنون',
			'action'     => InboxService::ACTION_REPLY,
			'template'   => 'خواهش می‌کنم {username}!',
			'priority'   => 5,
			'active'     => 1,
			'created_at' => current_time( 'mysql', true ),
		];

		// data.id carries the comment id for reply routing.
		$raw = wp_json_encode(
			[
				'event' => 'comment.created',
				'data'  => [
					'accountId' => 'acc-1',
					'id'        => 'cmt-77',
					'postId'    => 'p-100',
					'sender'    => [ 'id' => 'u-r', 'username' => 'userr' ],
					'text'      => 'ممنون از شما',
					'timestamp' => gmdate( 'c', time() - 60 ),
				],
			]
		);
		igbz_test_queue_http( [ 'body' => wp_json_encode( [ 'commentId' => 'cmt-78' ] ), 'match' => '/comments/cmt-77/reply' ] );
		$result = $this->inbox->handle_webhook( $raw, $this->signed( $raw ) );

		$sends = $this->last_delivery_requests();
		$this->assert_same( 1, count( $sends ), 'one reply call' );
		$this->assert_true( str_contains( (string) $sends[0]['url'], '/comments/cmt-77/reply' ), 'the reply targets the comment' );
		$this->assert_same( InboxService::STATE_SENT, (string) $this->db->actions[ (int) array_key_first( $this->db->actions ) ]['state'], 'the reply is sent' );
		$this->assert_same( InboxService::STATUS_SENT, (string) $this->db->inbox[ (int) $result['id'] ]['status'], 'the event is marked sent' );
	}

	private function foreign_tenant_cannot_touch_the_ledger(): void {
		$this->fresh();
		$this->reset_http();
		$raw    = $this->comment_payload( 'evt-iso', 'u-iso', 'iso', 'قیمت' );
		$result = $this->inbox->handle_webhook( $raw, $this->signed( $raw ) );
		$action_id = (int) array_key_first( $this->db->actions );

		$steal = $this->inbox->approve( 2, $action_id );
		$this->assert_false( $steal['ok'], 'tenant 2 cannot approve an action owned by tenant 1' );

		$steal_reject = $this->inbox->reject( 2, $action_id );
		$this->assert_false( $steal_reject['ok'], 'tenant 2 cannot reject an action owned by tenant 1' );

		$steal_retry = $this->inbox->retry_failed( 2, $action_id );
		$this->assert_false( $steal_retry['ok'], 'tenant 2 cannot retry an action owned by tenant 1' );

		$this->assert_same( 0, count( $this->last_delivery_requests() ), 'no cross-tenant delivery' );
	}
}
