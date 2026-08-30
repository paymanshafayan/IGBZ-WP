<?php
/**
 * Phase 57 — the atomic permission queue: exactly-once submission, one conditional
 * flip per transition, capability proof, expiry, claim/complete ownership, honest
 * cancellation and a tamper-evident audit trail.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\Pado\Services\ApprovalRequestService;
use IGBZ\Suite\Support\Db;

/** In-memory engine for the approval queue table. */
final class ApprovalQueueDb extends wpdb {

	/** @var array<int,array<string,mixed>> */
	public array $rows = [];

	private int $next_id = 1;

	public function insert( string $table, array $data, $format = null ): int|bool {
		$this->queries[] = 'INSERT INTO ' . $table;
		if ( ! str_contains( $table, 'igbz_approval_requests' ) ) {
			return parent::insert( $table, $data, $format );
		}
		// UNIQUE (tenant_id, kind, idempotency_key) with NULL keys never colliding.
		if ( null !== ( $data['idempotency_key'] ?? null ) ) {
			foreach ( $this->rows as $row ) {
				if ( (int) $row['tenant_id'] === (int) $data['tenant_id']
					&& (string) $row['kind'] === (string) $data['kind']
					&& (string) $row['idempotency_key'] === (string) $data['idempotency_key'] ) {
					return false;
				}
			}
		}
		$data['id'] = $this->next_id++;
		$this->rows[ $data['id'] ] = $data;
		$this->insert_id = $data['id'];
		return 1;
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		$this->queries[] = 'UPDATE ' . $table;
		if ( ! str_contains( $table, 'igbz_approval_requests' ) ) {
			return parent::update( $table, $data, $where, $format, $where_format );
		}
		$changed = 0;
		foreach ( $this->rows as $id => $row ) {
			if ( ! $this->matches( $row, $where ) ) {
				continue;
			}
			$this->rows[ $id ] = array_merge( $row, $data );
			++$changed;
		}
		return $changed;
	}

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;

		// The idempotency probe: WHERE tenant_id = .. AND kind = '..' AND idempotency_key = '..'
		if ( str_contains( $sql, 'idempotency_key' ) ) {
			preg_match( "/tenant_id = '?(\d+)'?/", $sql, $t );
			preg_match( "/kind = '([^']+)'/", $sql, $k );
			preg_match( "/idempotency_key = '([^']+)'/", $sql, $key );
			foreach ( $this->rows as $row ) {
				if ( (int) $row['tenant_id'] === (int) ( $t[1] ?? -1 )
					&& (string) $row['kind'] === (string) ( $k[1] ?? '' )
					&& (string) ( $row['idempotency_key'] ?? '' ) === (string) ( $key[1] ?? '' ) ) {
					return $row;
				}
			}
			return null;
		}

		if ( str_contains( $sql, 'igbz_approval_requests' ) && preg_match( '/WHERE id = \'?(\d+)\'?/', $sql, $m ) ) {
			$row = $this->rows[ (int) $m[1] ] ?? null;
			if ( null === $row ) {
				return null;
			}
			if ( preg_match( "/tenant_id = '(\d+)'/", $sql, $t ) && (int) $row['tenant_id'] !== (int) $t[1] ) {
				return null;
			}
			return $row;
		}
		return parent::get_row( $sql, $output );
	}

	public function get_col( string $sql ) {
		$this->queries[] = $sql;
		if ( str_contains( $sql, 'igbz_approval_requests' ) && str_contains( $sql, 'expires_at <= ' ) ) {
			preg_match( "/expires_at <= '([^']+)'/", $sql, $m );
			$cutoff = $m[1] ?? '';
			$out = [];
			foreach ( $this->rows as $row ) {
				if ( 'pending' === (string) $row['status']
					&& null !== ( $row['expires_at'] ?? null )
					&& strcmp( (string) $row['expires_at'], $cutoff ) <= 0 ) {
					$out[] = (string) $row['id'];
				}
			}
			return array_slice( $out, 0, 500 );
		}
		return parent::get_col( $sql );
	}

	private function matches( array $row, array $where ): bool {
		foreach ( $where as $column => $value ) {
			if ( (int) $row[ $column ] !== (int) $value && (string) $row[ $column ] !== (string) $value ) {
				return false;
			}
		}
		return true;
	}
}

final class PermissionQueueTest extends TestCase {

	private ApprovalQueueDb $db;
	private ApprovalRequestService $queue;

	public function run(): void {
		$this->payloads_hash_canonically_and_idempotently();
		$this->an_idempotency_key_submits_exactly_once();
		$this->the_decision_is_one_conditional_flip();
		$this->a_pinned_capability_must_be_proven();
		$this->an_expired_window_decides_nothing();
		$this->a_claim_has_exactly_one_owner();
		$this->only_the_claimer_completes();
		$this->the_legacy_executor_path_is_atomic();
		$this->the_requester_can_cancel_while_pending();
		$this->the_audit_trail_is_append_only_and_readable();
		$this->a_tampered_payload_fails_integrity();
		$this->rows_stay_tenant_scoped();
	}

	// -------------------------------------------------------------- payload

	private function payloads_hash_canonically_and_idempotently(): void {
		$this->fresh();
		$a = ApprovalRequestService::payload_hash( [ 'price' => 9, 'product' => [ 'id' => 5, 'name' => 'رژ' ] ] );
		$b = ApprovalRequestService::payload_hash( [ 'product' => [ 'name' => 'رژ', 'id' => 5 ], 'price' => 9 ] );
		$this->assert_same( $a, $b, 'key order never changes the digest' );
		$this->assert_same( 64, strlen( $a ), 'sha256 hex' );

		$made = $this->queue->enqueue( [ 'kind' => 'price_change', 'payload' => [ 'price' => 9 ] ] );
		$this->assert_true( $made['ok'], 'the row lands' , 'the invariant holds' );
		$row = $this->db->rows[ $made['id'] ];
		$this->assert_same( ApprovalRequestService::payload_hash( [ 'price' => 9 ] ), (string) $row['payload_hash'], 'the row carries the canonical digest' );
		$this->assert_same( 1, (int) $row['payload_version'], 'the hashing recipe is versioned' );
	}

	private function an_idempotency_key_submits_exactly_once(): void {
		$this->fresh();
		$first = $this->queue->enqueue( [ 'kind' => 'refund', 'idempotency_key' => 'refund:77', 'payload' => [ 'order' => 77 ] ] );
		$again = $this->queue->enqueue( [ 'kind' => 'refund', 'idempotency_key' => 'refund:77', 'payload' => [ 'order' => 77 ] ] );

		$this->assert_true( $first['ok'], 'the first submission lands' , 'the invariant holds' );
		$this->assert_false( $first['duplicate'], 'the first submission is fresh' , 'the invariant holds' );
		$this->assert_true( $again['ok'], 'the repeat is not an error' , 'the invariant holds' );
		$this->assert_true( $again['duplicate'], 'but it is flagged as a duplicate' , 'the invariant holds' );
		$this->assert_same( $first['id'], $again['id'], 'and points at the original row' );
		$this->assert_same( 1, count( $this->db->rows ), 'exactly one row exists' );

		$other = $this->queue->enqueue( [ 'kind' => 'refund', 'idempotency_key' => 'refund:78', 'payload' => [ 'order' => 78 ] ] );
		$this->assert_false( $other['duplicate'], 'a different key is a different request' , 'the invariant holds' );
	}

	// ------------------------------------------------------------- decision

	private function the_decision_is_one_conditional_flip(): void {
		$this->fresh();
		$id = $this->queue->enqueue( [ 'kind' => 'price_change', 'payload' => [ 'price' => 5 ] ] )['id'];

		$first  = $this->queue->decide( $id, ApprovalRequestService::STATUS_APPROVED, 9, 'ok' );
		$second = $this->queue->decide( $id, ApprovalRequestService::STATUS_REJECTED, 10, 'no' );

		$this->assert_true( $first, 'the first decision wins' , 'the invariant holds' );
		$this->assert_false( $second, 'the racing decision loses' , 'the invariant holds' );
		$row = $this->db->rows[ $id ];
		$this->assert_same( 'approved', (string) $row['status'], 'the winner\'s status stands' );
		$this->assert_same( 9, (int) $row['decided_by'], 'the winner\'s decider stands' );
	}

	private function a_pinned_capability_must_be_proven(): void {
		$this->fresh();
		$id = $this->queue->enqueue( [
			'kind'       => 'theme_apply',
			'capability' => 'igbz_manage_pado',
			'payload'    => [ 'theme_id' => 3 ],
		] )['id'];

		$this->assert_false( $this->queue->decide( $id, ApprovalRequestService::STATUS_APPROVED, 9 ), 'an unproven capability decides nothing' , 'the invariant holds' );
		$this->assert_same( 'pending', (string) $this->db->rows[ $id ]['status'], 'the row is untouched' );

		$this->assert_true( $this->queue->decide( $id, ApprovalRequestService::STATUS_APPROVED, 9, '', null, null, true ), 'the proven capability decides' , 'the invariant holds' );
	}

	private function an_expired_window_decides_nothing(): void {
		$this->fresh();
		$id = $this->queue->enqueue( [
			'kind'       => 'campaign_send',
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
			'payload'    => [ 'camp' => 1 ],
		] )['id'];

		$this->assert_false( $this->queue->decide( $id, ApprovalRequestService::STATUS_APPROVED, 9 ), 'an expired window refuses the decision' , 'the invariant holds' );
		$this->assert_same( 'expired', (string) $this->db->rows[ $id ]['status'], 'and the row dies honestly as expired' );

		// The sweep is idempotent: the second pass finds nothing.
		$this->assert_same( 0, $this->queue->expire_due(), 'nothing left to expire' );

		$live = $this->queue->enqueue( [
			'kind'       => 'campaign_send',
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 30 ),
			'payload'    => [ 'camp' => 2 ],
		] )['id'];
		$this->assert_same( 1, $this->queue->expire_due(), 'the sweep flips the lapsed row' );
		$this->assert_same( 'expired', (string) $this->db->rows[ $live ]['status'] , 'the invariant holds' );
	}

	// ---------------------------------------------------------------- claim

	private function a_claim_has_exactly_one_owner(): void {
		$this->fresh();
		$id = $this->queue->enqueue( [ 'kind' => 'bulk_delete', 'payload' => [ 'ids' => [ 1, 2 ] ] ] )['id'];
		$this->queue->decide( $id, ApprovalRequestService::STATUS_APPROVED, 9 );

		$this->assert_false( $this->queue->claim( $id, 0 ), 'claiming needs a worker' , 'the invariant holds' );
		$this->assert_true( $this->queue->claim( $id, 41 ), 'worker A claims' , 'the invariant holds' );
		$this->assert_false( $this->queue->claim( $id, 42 ), 'worker B loses the race' , 'the invariant holds' );

		$row = $this->db->rows[ $id ];
		$this->assert_same( 'claimed', (string) $row['status'] , 'the invariant holds' );
		$this->assert_same( 41, (int) $row['claimed_by'], 'A owns it' );
		$this->assert_same( 1, (int) $row['attempts'], 'the attempt is counted' );
	}

	private function only_the_claimer_completes(): void {
		$this->fresh();
		$id = $this->queue->enqueue( [ 'kind' => 'price_change', 'payload' => [ 'price' => 1 ] ] )['id'];
		$this->queue->decide( $id, ApprovalRequestService::STATUS_APPROVED, 9 );
		$this->queue->claim( $id, 41 );

		$this->assert_false( $this->queue->complete( $id, 42, true ), 'a stranger cannot complete the claim' , 'the invariant holds' );
		$this->assert_same( 'claimed', (string) $this->db->rows[ $id ]['status'], 'the row is still claimed' );

		$this->assert_true( $this->queue->complete( $id, 41, false, 'gateway timeout' ), 'the owner completes with a failure' , 'the invariant holds' );
		$row = $this->db->rows[ $id ];
		$this->assert_same( 'failed', (string) $row['status'] , 'the invariant holds' );
		$this->assert_same( 'gateway timeout', (string) $row['execution_error'], 'the failure reason is kept' );
		$this->assert_false( isset( $row['executed_at'] ) && null !== $row['executed_at'], 'a failure carries no executed stamp' , 'the invariant holds' );

		$this->assert_false( $this->queue->complete( $id, 41, true ), 'a finished row cannot be completed again' , 'the invariant holds' );
	}

	private function the_legacy_executor_path_is_atomic(): void {
		$this->fresh();
		$id = $this->queue->enqueue( [ 'kind' => 'theme_rollback', 'payload' => [ 'tenant_id' => 1 ] ] )['id'];

		$calls = 0;
		$first = $this->queue->decide( $id, ApprovalRequestService::STATUS_APPROVED, 9, '', static function () use ( &$calls ): bool {
			++$calls;
			return true;
		} );
		$again = $this->queue->decide( $id, ApprovalRequestService::STATUS_APPROVED, 9, '', static function (): bool {
			return true;
		} );

		$this->assert_true( $first, 'the first run executes' , 'the invariant holds' );
		$this->assert_false( $again, 'the second run is refused before any executor' , 'the invariant holds' );
		$this->assert_same( 1, $calls, 'the executor ran exactly once' );
		$this->assert_same( 'executed', (string) $this->db->rows[ $id ]['status'] , 'the invariant holds' );
	}

	// --------------------------------------------------------------- cancel

	private function the_requester_can_cancel_while_pending(): void {
		$this->fresh();
		$id = $this->queue->enqueue( [ 'kind' => 'price_change', 'requested_by' => 7, 'payload' => [ 'price' => 2 ] ] )['id'];

		$this->assert_false( $this->queue->cancel( $id, 8 ), 'a bystander cancels nothing' , 'the invariant holds' );
		$this->assert_true( $this->queue->cancel( $id, 7 ), 'the requester cancels' , 'the invariant holds' );
		$this->assert_false( $this->queue->decide( $id, ApprovalRequestService::STATUS_APPROVED, 9 ), 'a cancelled row can no longer be decided' , 'the invariant holds' );
		$this->assert_false( $this->queue->cancel( $id, 7 ), 'cancelling twice is a no-op' , 'the invariant holds' );
	}

	// ---------------------------------------------------------------- audit

	private function the_audit_trail_is_append_only_and_readable(): void {
		$this->fresh();
		$id = $this->queue->enqueue( [ 'kind' => 'price_change', 'requested_by' => 7, 'payload' => [ 'price' => 3 ] ] )['id'];
		$this->queue->decide( $id, ApprovalRequestService::STATUS_APPROVED, 9 );
		$this->queue->claim( $id, 41 );
		$this->queue->complete( $id, 41, true );

		$events = $this->queue->audit_trail( $id );
		$kinds  = array_map( static fn ( array $e ): string => (string) $e['event'], $events );

		$this->assert_same( [ 'submitted', 'decided', 'claimed', 'executed' ], $kinds, 'the trail is the whole life, in order' );
		$this->assert_same( 7, (int) $events[0]['actor'], 'the submitter is the first actor' );
		$this->assert_same( 'approved', (string) $events[1]['to'], 'the decision records where it went' );
		$this->assert_same( 41, (int) $events[3]['actor'], 'the executor is the last actor' );
	}

	private function a_tampered_payload_fails_integrity(): void {
		$this->fresh();
		$id = $this->queue->enqueue( [ 'kind' => 'refund', 'payload' => [ 'amount' => 100 ] ] )['id'];
		$this->assert_true( $this->queue->verify_payload_integrity( $id ), 'a pristine row verifies' , 'the invariant holds' );

		$this->db->rows[ $id ]['payload'] = wp_json_encode( [ 'amount' => 999999 ] );
		$this->assert_false( $this->queue->verify_payload_integrity( $id ), 'an edited payload no longer matches its stamp' , 'the invariant holds' );
	}

	private function rows_stay_tenant_scoped(): void {
		$this->fresh();
		$id = $this->queue->enqueue( [ 'tenant_id' => 1, 'kind' => 'price_change', 'payload' => [ 'price' => 4 ] ] )['id'];

		$this->assert_same( null, $this->queue->get( $id, 2 ), 'a foreign tenant sees no row' );
		$this->assert_false( $this->queue->decide( $id, ApprovalRequestService::STATUS_APPROVED, 9, '', null, 2 ), 'and cannot decide it' , 'the invariant holds' );
		$this->assert_true( $this->queue->decide( $id, ApprovalRequestService::STATUS_APPROVED, 9, '', null, 1 ), 'the owning tenant can' , 'the invariant holds' );
	}

	// ---------------------------------------------------------------- setup

	private function fresh(): void {
		igbz_test_reset_settings();
		$this->db = new ApprovalQueueDb();
		$GLOBALS['wpdb'] = $this->db;
		$this->queue = new ApprovalRequestService( new Db() );
	}
}
