<?php
namespace IGBZ\Suite\Modules\Pado\Services;

use IGBZ\Suite\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Service for persisting/retrieving Pado approval requests.
 *
 * One table, one API — every sensitive action (theme apply/rollback, price change,
 * refund, instagram publish of the four content kinds, bulk delete, campaign
 * send, policy change) goes through this service so the "درخواست‌های مجوز" tab in
 * the Pado center has a single source of truth.
 */
final class ApprovalRequestService {

	public const STATUS_PENDING  = 'pending';
	public const STATUS_APPROVED = 'approved';
	public const STATUS_REJECTED = 'rejected';
	public const STATUS_EXECUTED = 'executed';
	public const STATUS_FAILED   = 'failed';
	public const STATUS_CANCELLED= 'cancelled';
	/** Phase 57: a worker atomically owns the execution; expired pending rows die honestly. */
	public const STATUS_CLAIMED  = 'claimed';
	public const STATUS_EXPIRED  = 'expired';

	/** Canonical payload serialisation version — bump when the hashing recipe changes. */
	public const PAYLOAD_VERSION = 1;

	public const IMPACT_LOW      = 'low';
	public const IMPACT_MEDIUM   = 'medium';
	public const IMPACT_HIGH     = 'high';
	public const IMPACT_CRITICAL = 'critical';

	private Db $db;

	public function __construct( Db $db ) {
		$this->db = $db;
	}

	/**
	 * Submit a new approval request. Returns the new row id.
	 *
	 * @param array{kind:string,title:string,reason?:string,payload?:array,mixed} $data
	 */
	/**
	 * Canonical, order-independent payload serialisation: keys sorted recursively, so the
	 * same intent always hashes to the same digest no matter how the caller built the array.
	 *
	 * @param array<string,mixed> $payload
	 */
	public static function canonical_payload( array $payload ): string {
		self::ksort_recursive( $payload );
		return (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
	}

	/** @param array<string,mixed> $payload */
	public static function payload_hash( array $payload ): string {
		return hash( 'sha256', self::PAYLOAD_VERSION . ':' . self::canonical_payload( $payload ) );
	}

	private static function ksort_recursive( array &$value ): void {
		ksort( $value );
		foreach ( $value as &$item ) {
			if ( is_array( $item ) ) {
				self::ksort_recursive( $item );
			}
		}
		unset( $item );
	}

	/**
	 * Phase 57 enqueue: idempotent per (tenant, kind, key), payload-hashed, optionally
	 * expiring, pinned to the capability the decider must prove. A repeated key returns
	 * the original row — exactly-once submission, no duplicates.
	 *
	 * @param array<string,mixed> $data
	 * @return array{ok:bool,id:int,error:string,duplicate:bool}
	 */
	public function enqueue( array $data ): array {
		$now    = current_time( 'mysql', true );
		$tenant = (int) ( $data['tenant_id'] ?? 0 );
		$kind   = sanitize_key( (string) ( $data['kind'] ?? 'generic' ) );
		$key    = substr( sanitize_text_field( (string) ( $data['idempotency_key'] ?? '' ) ), 0, 191 );

		if ( '' !== $key ) {
			$existing = $this->db->row(
				'SELECT id FROM ' . $this->db->table( 'approval_requests' ) . ' WHERE tenant_id = %d AND kind = %s AND idempotency_key = %s',
				$tenant,
				$kind,
				$key
			);
			if ( null !== $existing ) {
				return [ 'ok' => true, 'id' => (int) $existing['id'], 'error' => '', 'duplicate' => true ];
			}
		}

		$payload   = is_array( $data['payload'] ?? null ) ? $data['payload'] : [];
		$expires   = trim( (string) ( $data['expires_at'] ?? '' ) );
		$expires_at = '' !== $expires ? gmdate( 'Y-m-d H:i:s', (int) strtotime( $expires ) ) : null;

		$id = $this->db->insert( 'approval_requests', [
			'tenant_id'       => $tenant,
			'kind'            => $kind,
			'title'           => substr( (string) ( $data['title'] ?? '' ), 0, 255 ),
			'reason'          => (string) ( $data['reason'] ?? '' ),
			'payload'         => self::canonical_payload( $payload ),
			'payload_version' => self::PAYLOAD_VERSION,
			'payload_hash'    => self::payload_hash( $payload ),
			'idempotency_key' => '' !== $key ? $key : null,
			'capability'      => substr( sanitize_key( (string) ( $data['capability'] ?? '' ) ), 0, 64 ),
			'expires_at'      => $expires_at,
			'impact'          => $this->sanitize_impact( (string) ( $data['impact'] ?? self::IMPACT_LOW ) ),
			'requested_by'    => (int) ( $data['requested_by'] ?? get_current_user_id() ),
			'status'          => self::STATUS_PENDING,
			'audit'           => wp_json_encode( [ [ 'event' => 'submitted', 'at' => $now, 'actor' => (int) ( $data['requested_by'] ?? get_current_user_id() ) ] ], JSON_UNESCAPED_UNICODE ),
			'metadata'        => is_array( $data['metadata'] ?? null ) ? wp_json_encode( $data['metadata'], JSON_UNESCAPED_UNICODE ) : null,
			'created_at'      => $now,
		] );

		if ( $id <= 0 ) {
			return [ 'ok' => false, 'id' => 0, 'error' => 'insert_failed', 'duplicate' => false ];
		}

		return [ 'ok' => true, 'id' => $id, 'error' => '', 'duplicate' => false ];
	}

	/** Legacy thin wrapper: enqueue and hand back the row id (0 on refusal). */
	public function submit( array $data ): int {
		return $this->enqueue( $data )['id'];
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get( int $id, ?int $tenant_id = null ): ?array {
		$table = $this->db->table( 'approval_requests' );
		$sql = "SELECT * FROM {$table} WHERE id = %d";
		$args = [ $id ];
		if ( null !== $tenant_id ) { $sql .= ' AND tenant_id = %d'; $args[] = $tenant_id; }
		$row = $this->db->row( $sql, ...$args );
		return $row ?: null;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function list( string $status = '', int $per_page = 25, int $offset = 0, ?int $tenant_id = null ): array {
		$table = $this->db->table( 'approval_requests' );
		$sql   = "SELECT * FROM {$table} WHERE 1=1";
		$args  = [];
		if ( '' !== $status ) { $sql .= ' AND status = %s'; $args[] = $status; }
		if ( null !== $tenant_id ) { $sql .= ' AND tenant_id = %d'; $args[] = $tenant_id; }
		$sql .= ' ORDER BY created_at DESC LIMIT %d OFFSET %d';
		$args[] = max( 1, min( 200, $per_page ) ); $args[] = max( 0, $offset );
		return (array) $this->db->results( $sql, ...$args );
	}

	public function count( string $status = '', ?int $tenant_id = null ): int {
		$table = $this->db->table( 'approval_requests' );
		$sql = "SELECT COUNT(*) FROM {$table} WHERE 1=1"; $args = [];
		if ( '' !== $status ) { $sql .= ' AND status = %s'; $args[] = $status; }
		if ( null !== $tenant_id ) { $sql .= ' AND tenant_id = %d'; $args[] = $tenant_id; }
		return (int) $this->db->scalar( $sql, ...$args );
	}

	/**
	 * Phase 57 decision: ONE conditional UPDATE moves `pending` to a decision — the
	 * race loser's UPDATE matches zero rows and gets false. Refused honestly when the
	 * row needs a capability the caller has not proven, or when the window expired.
	 *
	 * @param callable(array):bool|null $executor Legacy inline path; routed through the
	 *        atomic claim so even this path executes at most once.
	 */
	public function decide( int $id, string $status, int $decided_by, string $note = '', ?callable $executor = null, ?int $tenant_id = null, bool $capability_proven = false ): bool {
		$row = $this->get( $id, $tenant_id );
		if ( ! $row || self::STATUS_PENDING !== (string) $row['status'] ) {
			return false;
		}
		if ( ! in_array( $status, [ self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED ], true ) ) {
			return false;
		}
		if ( '' !== (string) ( $row['capability'] ?? '' ) && self::STATUS_CANCELLED !== $status && ! $capability_proven ) {
			return false; // the queue pinned a capability the caller did not prove
		}
		if ( null !== $row['expires_at'] && (string) $row['expires_at'] <= current_time( 'mysql', true ) ) {
			$this->expire_one( $id );
			return false;
		}

		$now = current_time( 'mysql', true );
		$flipped = $this->db->update(
			'approval_requests',
			[
				'status'        => $status,
				'decided_by'    => $decided_by,
				'decision_note' => $note,
				'decided_at'    => $now,
			],
			[ 'id' => $id, 'tenant_id' => (int) $row['tenant_id'], 'status' => self::STATUS_PENDING ]
		);
		if ( $flipped <= 0 ) {
			return false; // somebody else decided first
		}

		$this->audit( $id, 'decided', $decided_by, [ 'to' => $status ] );

		if ( self::STATUS_APPROVED === $status && is_callable( $executor ) ) {
			// The legacy inline path still goes through the atomic claim.
			if ( ! $this->claim( $id, $decided_by, $tenant_id ) ) {
				return false;
			}
			$refreshed = $this->get( $id );
			$executed  = (bool) call_user_func( $executor, $refreshed ?? [] );
			return $this->complete( $id, $decided_by, $executed, $executed ? '' : 'executor_returned_false' );
		}

		return true;
	}

	/**
	 * Atomic claim: exactly one worker moves `approved` → `claimed`; the loser gets
	 * false and walks away. Attempts are counted so a crashed worker is visible.
	 */
	public function claim( int $id, int $worker_id, ?int $tenant_id = null ): bool {
		if ( $worker_id <= 0 ) {
			return false; // an anonymous worker owns nothing
		}
		$row = $this->get( $id, $tenant_id );
		if ( ! $row || self::STATUS_APPROVED !== (string) $row['status'] ) {
			return false;
		}

		$now = current_time( 'mysql', true );
		$flipped = $this->db->update(
			'approval_requests',
			[
				'status'     => self::STATUS_CLAIMED,
				'claimed_by' => $worker_id,
				'claimed_at' => $now,
				'attempts'   => (int) ( $row['attempts'] ?? 0 ) + 1,
			],
			[ 'id' => $id, 'tenant_id' => (int) $row['tenant_id'], 'status' => self::STATUS_APPROVED ]
		);

		if ( $flipped <= 0 ) {
			return false;
		}

		$this->audit( $id, 'claimed', $worker_id );
		return true;
	}

	/**
	 * Complete a claim: only the claimer may finish it, `claimed` → `executed`/`failed`.
	 *
	 * @return bool false when the caller is not the claimer (or the row is not claimed).
	 */
	public function complete( int $id, int $worker_id, bool $ok, string $error = '' ): bool {
		$row = $this->get( $id );
		if ( ! $row || self::STATUS_CLAIMED !== (string) $row['status'] || (int) $row['claimed_by'] !== $worker_id ) {
			return false;
		}

		$now = current_time( 'mysql', true );
		$flipped = $this->db->update(
			'approval_requests',
			[
				'status'          => $ok ? self::STATUS_EXECUTED : self::STATUS_FAILED,
				'executed_at'     => $ok ? $now : null,
				'execution_error' => $ok ? null : substr( $error, 0, 1000 ),
			],
			[ 'id' => $id, 'status' => self::STATUS_CLAIMED, 'claimed_by' => $worker_id ]
		);

		if ( $flipped <= 0 ) {
			return false;
		}

		$this->audit( $id, $ok ? 'executed' : 'failed', $worker_id, $ok ? [] : [ 'error' => substr( $error, 0, 200 ) ] );
		return true;
	}

	/** Requester-side cancellation while still pending. */
	public function cancel( int $id, int $requested_by, ?int $tenant_id = null ): bool {
		$row = $this->get( $id, $tenant_id );
		if ( ! $row || self::STATUS_PENDING !== (string) $row['status'] ) {
			return false;
		}
		// Only the requester (or the system, 0) cancels; everyone else goes through decide().
		if ( $requested_by > 0 && (int) $row['requested_by'] !== $requested_by ) {
			return false;
		}

		$flipped = $this->db->update(
			'approval_requests',
			[ 'status' => self::STATUS_CANCELLED, 'decided_at' => current_time( 'mysql', true ) ],
			[ 'id' => $id, 'tenant_id' => (int) $row['tenant_id'], 'status' => self::STATUS_PENDING ]
		);
		if ( $flipped <= 0 ) {
			return false;
		}

		$this->audit( $id, 'cancelled', $requested_by );
		return true;
	}

	/** Sweep expired pending rows. Returns how many flipped to `expired`. */
	public function expire_due(): int {
		$now  = current_time( 'mysql', true );
		$rows = $this->db->column(
			'SELECT id FROM ' . $this->db->table( 'approval_requests' ) . ' WHERE status = %s AND expires_at IS NOT NULL AND expires_at <= %s LIMIT 500',
			self::STATUS_PENDING,
			$now
		);

		$expired = 0;
		foreach ( $rows as $id ) {
			if ( $this->expire_one( (int) $id ) ) {
				++$expired;
			}
		}
		return $expired;
	}

	private function expire_one( int $id ): bool {
		$flipped = $this->db->update(
			'approval_requests',
			[ 'status' => self::STATUS_EXPIRED, 'decided_at' => current_time( 'mysql', true ) ],
			[ 'id' => $id, 'status' => self::STATUS_PENDING ]
		);
		if ( $flipped > 0 ) {
			$this->audit( $id, 'expired', 0 );
			return true;
		}
		return false;
	}

	/**
	 * Integrity: recompute the payload digest and compare with the stamped one. A row
	 * whose payload was edited after submission says so loudly.
	 */
	public function verify_payload_integrity( int $id, ?int $tenant_id = null ): bool {
		$row = $this->get( $id, $tenant_id );
		if ( ! $row ) {
			return false;
		}
		$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
		if ( ! is_array( $payload ) ) {
			return '' === (string) ( $row['payload'] ?? '' ) && '' === (string) $row['payload_hash'];
		}
		$expected = self::payload_hash( $payload );
		return hash_equals( (string) $row['payload_hash'], $expected )
			&& (int) $row['payload_version'] === self::PAYLOAD_VERSION;
	}

	/** @return array<int,array<string,mixed>> the parsed audit trail, oldest first */
	public function audit_trail( int $id, ?int $tenant_id = null ): array {
		$row = $this->get( $id, $tenant_id );
		if ( ! $row || '' === (string) ( $row['audit'] ?? '' ) ) {
			return [];
		}
		$events = json_decode( (string) $row['audit'], true );
		return is_array( $events ) ? array_values( array_filter( $events, 'is_array' ) ) : [];
	}

	/** @param array<string,mixed> $context */
	private function audit( int $id, string $event, int $actor, array $context = [] ): void {
		$row = $this->get( $id );
		if ( ! $row ) {
			return;
		}
		$events = json_decode( (string) ( $row['audit'] ?? '' ), true );
		$events = is_array( $events ) ? $events : [];
		$events[] = [ 'event' => $event, 'at' => current_time( 'mysql', true ), 'actor' => $actor ] + $context;

		$this->db->update(
			'approval_requests',
			[ 'audit' => wp_json_encode( $events, JSON_UNESCAPED_UNICODE ) ],
			[ 'id' => $id ]
		);
	}


	private function sanitize_impact( string $impact ): string {
		return in_array( $impact, [ self::IMPACT_LOW, self::IMPACT_MEDIUM, self::IMPACT_HIGH, self::IMPACT_CRITICAL ], true )
			? $impact
			: self::IMPACT_LOW;
	}
}
