<?php
namespace IGBZ\Suite\Modules\Instagram\Services;

use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Controlled migration to the single social provider (phase 50, ADR-0004 §6).
 *
 * "Controlled" has three concrete meanings here:
 *
 *   - per-tenant and idempotent — a round may run as often as the hourly beat
 *     wants; every step is journaled in ig_social_migration and a finished
 *     step never runs twice;
 *   - no data destruction — the legacy credentials stay encrypted at rest on
 *     ig_accounts; migration stamps them `legacy_deprecated_at` (reversible,
 *     auditable) and their erasure happens only through offboarding, exactly
 *     as before;
 *   - honest about state — a store whose Zernio profile cannot be provisioned
 *     yet (central key not configured, provider down) is journaled `pending`
 *     and retried by the next round, never marked migrated.
 *
 * The two steps per tenant:
 *   1. profile_ensured   — exactly one Zernio profile per store (ADR-0004 §5);
 *   2. legacy_deprecated — the legacy credentials are stamped, so no future
 *                          code path can mistake them for usable keys.
 */
final class SocialMigrationService {

	public const STEP_PROFILE = 'profile_ensured';
	public const STEP_LEGACY  = 'legacy_deprecated';

	public const STATUS_PENDING = 'pending';
	public const STATUS_DONE    = 'done';
	public const STATUS_FAILED  = 'failed';

	/** Per-round tenant budget (continuation contract, phase 25 pattern). */
	public const ROUND_LIMIT = 20;

	public function __construct(
		private Db $db,
		private Logger $logger,
		private ZernioConnectionService $zernio
	) {}

	// ---------------------------------------------------------------- round

	/**
	 * One distributed round over the tenants still needing migration. Returns
	 * how many were processed so the queue's continuation contract can decide
	 * whether a follow-up round is due.
	 */
	public function run_distributed_round( int $limit = self::ROUND_LIMIT ): int {
		$ids = $this->due_tenant_ids( $limit );
		$done = 0;

		foreach ( $ids as $tenant_id ) {
			$this->run_for_tenant( (int) $tenant_id );
			++$done;
		}

		return $done;
	}

	/**
	 * Tenants with unfinished migration work: they still carry an active
	 * legacy account, or their profile row is not yet provisioned while a
	 * legacy account exists, or a journal row is pending/failed.
	 *
	 * @return array<int,int>
	 */
	public function due_tenant_ids( int $limit = self::ROUND_LIMIT ): array {
		// A tenant is due when it still carries an active legacy account or has any
		// journal row, and is not fully migrated. "Fully" means BOTH steps done —
		// the profile step in particular can stay pending for the whole time the
		// central key is missing, and the round must keep picking it up until the
		// provider is reachable.
		$sql = "SELECT t.tenant_id
				FROM (
					SELECT DISTINCT tenant_id FROM " . $this->db->table( 'ig_accounts' ) . " WHERE is_active = 1 AND tenant_id > 0
					UNION
					SELECT tenant_id FROM " . $this->db->table( 'ig_social_migration' ) . "
				) t
				WHERE NOT EXISTS (
					SELECT 1
					FROM " . $this->db->table( 'ig_social_migration' ) . " a
					JOIN " . $this->db->table( 'ig_social_migration' ) . " b ON a.tenant_id = b.tenant_id
					WHERE a.tenant_id = t.tenant_id
						AND a.step = '" . self::STEP_PROFILE . "' AND a.status = '" . self::STATUS_DONE . "'
						AND b.step = '" . self::STEP_LEGACY . "' AND b.status = '" . self::STATUS_DONE . "'
				)
				LIMIT " . (int) $limit;

		$rows = $this->db->results( $sql );

		return array_map( 'intval', array_column( (array) $rows, 'tenant_id' ) );
	}

	// ----------------------------------------------------------------- step

	/**
	 * Migrate one tenant. Idempotent: re-running a finished tenant only
	 * re-reads its journal.
	 *
	 * @return array{ok:bool,profile:string,legacy:string,error:string}
	 */
	public function run_for_tenant( int $tenant_id ): array {
		$profile_status = $this->ensure_profile( $tenant_id );
		$legacy_status  = $this->deprecate_legacy( $tenant_id );

		return [
			'ok'           => self::STATUS_DONE === $profile_status && self::STATUS_DONE === $legacy_status,
			'profile'      => $profile_status,
			'legacy'       => $legacy_status,
			'error'        => '',
		];
	}

	/**
	 * Step 1: the store's Zernio profile exists (one store, one profile).
	 */
	private function ensure_profile( int $tenant_id ): string {
		$existing = $this->journal( $tenant_id, self::STEP_PROFILE );
		if ( null !== $existing && self::STATUS_DONE === (string) $existing['status'] ) {
			return self::STATUS_DONE;
		}

		$profile = $this->zernio->profile( $tenant_id );
		if ( null !== $profile
			&& in_array( (string) $profile['status'], [ ZernioConnectionService::STATUS_PROVISIONED, ZernioConnectionService::STATUS_CONNECTED ], true )
		) {
			$this->journal_upsert( $tenant_id, self::STEP_PROFILE, self::STATUS_DONE, 'profile already exists: ' . (string) $profile['profile_id'], (string) $profile['profile_id'] );

			return self::STATUS_DONE;
		}

		$slug = $this->store_slug( $tenant_id );
		if ( '' === $slug ) {
			// No tenant row (or no slug): nothing to provision against yet.
			$this->journal_upsert( $tenant_id, self::STEP_PROFILE, self::STATUS_PENDING, 'no tenant slug', '' );

			return self::STATUS_PENDING;
		}

		$result = $this->zernio->provision( $tenant_id, $slug );
		if ( $result['ok'] ) {
			$profile = $this->zernio->profile( $tenant_id );
			$this->journal_upsert( $tenant_id, self::STEP_PROFILE, self::STATUS_DONE, 'provisioned', (string) ( $profile['profile_id'] ?? '' ) );
			$this->logger->info( 'social_migration', 'Zernio profile ensured', [ 'tenant' => $tenant_id ] );

			return self::STATUS_DONE;
		}

		// 'not_configured' is the normal pre-PV state: retry on the next round.
		$this->journal_upsert(
			$tenant_id,
			self::STEP_PROFILE,
			self::STATUS_PENDING,
			(string) $result['error'],
			''
		);

		return self::STATUS_PENDING;
	}

	/**
	 * Step 2: stamp the legacy credentials. Encrypted keys are never touched —
	 * the stamp is what makes them unusable-and-audited; erasure belongs to
	 * offboarding.
	 */
	private function deprecate_legacy( int $tenant_id ): string {
		$existing = $this->journal( $tenant_id, self::STEP_LEGACY );
		if ( null !== $existing && self::STATUS_DONE === (string) $existing['status'] ) {
			return self::STATUS_DONE;
		}

		$now = current_time( 'mysql', true );
		$affected = (int) $this->db->query(
			'UPDATE ' . $this->db->table( 'ig_accounts' ) . "
			 SET legacy_deprecated_at = %s
			 WHERE tenant_id = %d AND legacy_deprecated_at IS NULL",
			$now,
			$tenant_id
		);

		$this->journal_upsert( $tenant_id, self::STEP_LEGACY, self::STATUS_DONE, 'accounts stamped: ' . $affected, (string) $tenant_id );
		$this->logger->info( 'social_migration', 'Legacy credentials deprecated', [ 'tenant' => $tenant_id, 'accounts' => $affected ] );

		return self::STATUS_DONE;
	}

	// --------------------------------------------------------------- status

	/**
	 * The tenant's migration state for the UI and the REST endpoint.
	 *
	 * @return array{profile_status:string,journal:array<string,string>,legacy_accounts:int,deprecated_accounts:int}
	 */
	public function status( int $tenant_id ): array {
		$profile = $this->zernio->profile( $tenant_id );

		$journal = [];
		foreach ( [ self::STEP_PROFILE, self::STEP_LEGACY ] as $step ) {
			$row     = $this->journal( $tenant_id, $step );
			$journal[ $step ] = null === $row ? self::STATUS_PENDING : (string) $row['status'];
		}

		return [
			'profile_status'      => null === $profile ? 'none' : (string) $profile['status'],
			'journal'             => $journal,
			'legacy_accounts'     => (int) $this->db->scalar(
				'SELECT COUNT(*) FROM ' . $this->db->table( 'ig_accounts' ) . ' WHERE tenant_id = %d',
				$tenant_id
			),
			'deprecated_accounts' => (int) $this->db->scalar(
				'SELECT COUNT(*) FROM ' . $this->db->table( 'ig_accounts' ) . ' WHERE tenant_id = %d AND legacy_deprecated_at IS NOT NULL',
				$tenant_id
			),
		];
	}

	// -------------------------------------------------------------- journal

	/** @return array<string,mixed>|null */
	private function journal( int $tenant_id, string $step ): ?array {
		$row = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'ig_social_migration' ) . ' WHERE tenant_id = %d AND step = %s',
			$tenant_id,
			$step
		);

		return $row ?: null;
	}

	private function journal_upsert( int $tenant_id, string $step, string $status, string $detail, string $ref ): void {
		$now = current_time( 'mysql', true );
		// payload_hash fingerprints the tenant+step+ref so a re-run is provably
		// the same logical operation (audit trail, not a lock).
		$hash = (string) Crypto::hmac( $tenant_id . ':' . $step . ':' . $ref, (string) $tenant_id );

		$existing = $this->journal( $tenant_id, $step );
		if ( null === $existing ) {
			$this->db->insert(
				'ig_social_migration',
				[
					'tenant_id'    => $tenant_id,
					'step'         => $step,
					'status'       => $status,
					'detail'       => mb_substr( $detail, 0, 255 ),
					'payload_hash' => $hash,
					'attempts'     => 1,
					'created_at'   => $now,
					'updated_at'   => $now,
				]
			);

			return;
		}

		$this->db->update(
			'ig_social_migration',
			[
				'status'       => $status,
				'detail'       => mb_substr( $detail, 0, 255 ),
				'payload_hash' => $hash,
				'attempts'     => (int) $existing['attempts'] + 1,
				'updated_at'   => $now,
			],
			[ 'id' => (int) $existing['id'] ]
		);
	}

	/** @return string */
	private function store_slug( int $tenant_id ): string {
		$row = $this->db->row( 'SELECT slug FROM ' . $this->db->table( 'tenants' ) . ' WHERE id = %d', $tenant_id );

		return (string) ( $row['slug'] ?? '' );
	}
}
