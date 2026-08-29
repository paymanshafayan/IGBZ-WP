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
	public function submit( array $data ): int {
		$now    = current_time( 'mysql', true );
		$insert = [
			'tenant_id'     => (int) ( $data['tenant_id'] ?? 0 ),
			'kind'          => sanitize_key( (string) ( $data['kind'] ?? 'generic' ) ),
			'title'         => substr( (string) ( $data['title'] ?? '' ), 0, 255 ),
			'reason'        => (string) ( $data['reason'] ?? '' ),
			'payload'       => is_array( $data['payload'] ?? null ) ? wp_json_encode( $data['payload'], JSON_UNESCAPED_UNICODE ) : null,
			'impact'        => $this->sanitize_impact( (string) ( $data['impact'] ?? self::IMPACT_LOW ) ),
			'requested_by'  => (int) ( $data['requested_by'] ?? get_current_user_id() ),
			'status'        => self::STATUS_PENDING,
			'decision_note' => null,
			'metadata'      => is_array( $data['metadata'] ?? null ) ? wp_json_encode( $data['metadata'], JSON_UNESCAPED_UNICODE ) : null,
			'created_at'    => $now,
			'decided_at'    => null,
			'executed_at'   => null,
		];
		$this->db->insert( 'approval_requests', $insert );
		return (int) $this->db->insert_id;
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
	 * Approve or reject a pending request. The actual execution (firing the action)
	 * is left to a caller-provided callback so this service does not know about
	 * instagram/theme/refund internals. Returns boolean success.
	 *
	 * @param callable(array):bool|null $executor If non-null and $status==approved, run after updating the row.
	 */
	public function decide( int $id, string $status, int $decided_by, string $note = '', ?callable $executor = null, ?int $tenant_id = null ): bool {
		$row = $this->get( $id, $tenant_id );
		if ( ! $row || self::STATUS_PENDING !== $row['status'] ) {
			return false;
		}
		if ( ! in_array( $status, [ self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED ], true ) ) {
			return false;
		}
		$now   = current_time( 'mysql', true );
		$update = [
			'status'        => $status,
			'decided_by'    => $decided_by,
			'decision_note' => $note,
			'decided_at'    => $now,
		];
		$ok = (bool) $this->db->update( 'approval_requests', $update, [ 'id' => $id ] );
		if ( ! $ok ) {
			return false;
		}
		if ( self::STATUS_APPROVED === $status && is_callable( $executor ) ) {
			$refreshed = $this->get( $id );
			$executed  = (bool) call_user_func( $executor, $refreshed );
			$this->db->update(
				'approval_requests',
				[
					'status'      => $executed ? self::STATUS_EXECUTED : self::STATUS_FAILED,
					'executed_at' => $executed ? $now : null,
				],
				[ 'id' => $id ]
			);
			return $executed;
		}
		return true;
	}

	private function sanitize_impact( string $impact ): string {
		return in_array( $impact, [ self::IMPACT_LOW, self::IMPACT_MEDIUM, self::IMPACT_HIGH, self::IMPACT_CRITICAL ], true )
			? $impact
			: self::IMPACT_LOW;
	}
}
