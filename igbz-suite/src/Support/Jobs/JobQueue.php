<?php
/**
 * Phase 23 — the durable job queue core.
 *
 * Design contract (what phases 24-27 build on):
 *  - At-least-once delivery: a job is never deleted on claim; it is leased. If the worker dies
 *    the lease expires and the job returns to the queue. Handlers MUST therefore be idempotent —
 *    running a job twice must leave the same state as running it once.
 *  - Versioned envelope (Envelope) carries job type, payload, tenant, trace id and an
 *    idempotency key that is stable across every retry of the same logical operation.
 *  - Retries use exponential backoff with jitter so a downstream outage cannot create a
 *    thundering herd of simultaneous retries.
 *  - A job that exhausts its attempts is dead-lettered (status `dead`), never silently dropped,
 *    so poison messages can be inspected, replayed or closed deliberately.
 *  - Everything is tenant-scoped: the tenant is stamped on enqueue and can be used for fairness.
 *
 * @package IGBZ\Suite\Support\Jobs
 */

declare( strict_types = 1 );

namespace IGBZ\Suite\Support\Jobs;

use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

final class JobQueue {

	public const STATUS_PENDING   = 'pending';
	public const STATUS_CLAIMED   = 'claimed';
	public const STATUS_DONE      = 'done';
	public const STATUS_DEAD      = 'dead';
	public const STATUS_CANCELLED = 'cancelled';

	public const DEFAULT_MAX_ATTEMPTS = 5;
	public const DEFAULT_LEASE_SECONDS = 300;

	/** Retry backoff: base delay doubled per attempt, capped, plus jitter in [0, base). */
	public const BACKOFF_BASE_SECONDS = 10;
	public const BACKOFF_CAP_SECONDS  = 3600;

	/** @var array<string,callable> job_type => handler(array $payload, EnvelopeContext) */
	private array $handlers = [];

	/**
	 * @param Db     $db     Database access.
	 * @param Logger $logger Structured event log.
	 */
	public function __construct( private Db $db, private Logger $logger ) {}

	/**
	 * Register a handler for a job type. The handler receives the decoded payload plus the
	 * envelope context (tenant, trace id, idempotency key, attempt number) and must be
	 * idempotent: it can run more than once for the same job. Throwing marks the attempt failed.
	 */
	public function register( string $job_type, callable $handler ): void {
		$this->handlers[ $job_type ] = $handler;
	}

	/**
	 * Idempotency key for "once per time window" jobs (phase 24): WP-Cron fires at least once,
	 * so the same beat can arrive twice — the slot key makes the second enqueue a no-op.
	 */
	public static function slot( int $window_seconds = 300 ): string {
		return gmdate( 'Y-m-d H:i:s', intdiv( time(), max( 1, $window_seconds ) ) * max( 1, $window_seconds ) );
	}

	/**
	 * Phase 25 — tenant fan-out: one job per tenant, each in its own fairness group. The slot
	 * key is mixed with the tenant so a duplicate beat is absorbed per tenant, and the group
	 * keeps one loud tenant from starving the others at claim time.
	 *
	 * @param array<int,int>      $tenant_ids
	 * @param array<string,mixed> $options Extra enqueue options (queue, delay, max_attempts...).
	 * @return int Number of jobs now present for this fan-out (fresh or already queued).
	 */
	public function fan_out_tenants( string $job_type, array $tenant_ids, array $options = [] ): int {
		$slot = (string) ( $options['slot'] ?? self::slot() );
		unset( $options['slot'] );

		$count = 0;
		foreach ( array_unique( array_map( 'intval', $tenant_ids ) ) as $tenant_id ) {
			if ( $tenant_id <= 0 ) {
				continue;
			}
			$id = $this->enqueue(
				$job_type,
				(array) ( $options['payload'] ?? [] ),
				array_merge(
					$options,
					[
						'tenant_id'       => $tenant_id,
						'group'           => (string) $tenant_id,
						'idempotency_key' => $slot . ':' . $tenant_id,
					]
				)
			);
			if ( $id > 0 ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Phase 26 — the canonical continuation contract for bounded sweeps. When a batch comes
	 * back full there may be more rows, so round N enqueues round N+1 under a key derived from
	 * the current one (`key:rN`) — stable per chain, so replaying a round can never fork a
	 * duplicate continuation. The round cap bounds the worst case inside one drain.
	 *
	 * @param array<string,mixed> $payload Payload of the job that just ran (carries `round`).
	 */
	public function continue_round( JobContext $ctx, array $payload, string $job_type, int $processed, int $batch, int $max_rounds = 10 ): void {
		$round = (int) ( $payload['round'] ?? 0 );
		if ( $processed < $batch || $round >= $max_rounds ) {
			return;
		}
		$options = [
			'tenant_id'       => $ctx->tenant_id,
			'idempotency_key' => $ctx->idempotency_key . ':r' . ( $round + 1 ),
		];
		if ( '' !== $ctx->group ) {
			$options['group'] = $ctx->group;
		}
		$this->enqueue( $job_type, [ 'round' => $round + 1 ], $options );
	}

	/**
	 * Enqueue a job. Idempotent when an idempotency key is given: re-enqueuing the same key for
	 * the same queue returns the existing job instead of creating a duplicate.
	 *
	 * @param array<string,mixed> $payload
	 * @param array{queue?:string,tenant_id?:int,delay_seconds?:int,max_attempts?:int,trace_id?:string,idempotency_key?:string,group?:string} $options
	 * @return int Job id (0 on failure).
	 */
	public function enqueue( string $job_type, array $payload = [], array $options = [] ): int {
		$queue           = (string) ( $options['queue'] ?? $job_type );
		$idempotency_key = (string) ( $options['idempotency_key'] ?? '' );
		$tenant_id       = (int) ( $options['tenant_id'] ?? igbz()->tenancy()->id() );
		$trace_id        = (string) ( $options['trace_id'] ?? Crypto::token( 16 ) );
		$max_attempts    = max( 1, (int) ( $options['max_attempts'] ?? self::DEFAULT_MAX_ATTEMPTS ) );
		$delay_seconds   = max( 0, (int) ( $options['delay_seconds'] ?? 0 ) );

		if ( '' !== $idempotency_key ) {
			$existing = $this->find_active_by_key( $queue, $idempotency_key );
			if ( $existing ) {
				return (int) $existing['id'];
			}
		}

		$now       = current_time( 'mysql', true );
		$available = $delay_seconds > 0 ? gmdate( 'Y-m-d H:i:s', strtotime( $now . ' UTC' ) + $delay_seconds ) : $now;

		$envelope = Envelope::wrap(
			$job_type,
			$payload,
			$trace_id,
			[
				'queue'           => $queue,
				'group'           => (string) ( $options['group'] ?? '' ),
				'idempotency_key' => $idempotency_key,
			]
		);

		$id = $this->db->insert(
			'jobs',
			[
				'queue'           => $queue,
				'group_key'       => (string) ( $options['group'] ?? '' ),
				'tenant_id'       => $tenant_id,
				'job_type'        => $job_type,
				'status'          => self::STATUS_PENDING,
				'attempts'        => 0,
				'max_attempts'    => $max_attempts,
				'available_at'    => $available,
				'idempotency_key' => '' !== $idempotency_key ? $idempotency_key : null,
				'envelope'        => $envelope,
				'created_at'      => $now,
				'updated_at'      => $now,
			]
		);

		if ( $id > 0 ) {
			$this->logger->info( 'jobs', 'job enqueued', [ 'id' => $id, 'type' => $job_type, 'tenant' => $tenant_id, 'trace' => $trace_id ] );
		}
		return $id;
	}

	/**
	 * Claim up to $limit due jobs for processing, leasing each so a crashed worker's jobs come
	 * back after the lease expires. Returns the claimed rows.
	 *
	 * Phase 25 — tenant fairness: due jobs are taken round-robin across `group_key` (normally
	 * the tenant), so one tenant with a large backlog cannot starve the others when the claim
	 * budget is smaller than the queue. The conditional UPDATE keeps the claim atomic either
	 * way: two workers can never win the same row.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function claim( int $limit = 5, int $lease_seconds = self::DEFAULT_LEASE_SECONDS ): array {
		$now = current_time( 'mysql', true );
		$this->reclaim_expired_leases();
		if ( $limit < 1 ) {
			return [];
		}

		$groups = array_map(
			'strval',
			$this->db->column(
				'SELECT DISTINCT group_key FROM ' . $this->db->table( 'jobs' ) .
				' WHERE status = %s AND available_at <= %s
				  ORDER BY group_key ASC LIMIT 64',
				self::STATUS_PENDING,
				$now
			)
		);

		$claimed = [];
		// Round-robin: one job per group per pass, until the budget is spent or the pool is dry.
		while ( count( $claimed ) < $limit ) {
			$progress = false;
			foreach ( $groups as $group ) {
				if ( count( $claimed ) >= $limit ) {
					break;
				}
				$row = $this->db->row(
					'SELECT * FROM ' . $this->db->table( 'jobs' ) .
					' WHERE status = %s AND available_at <= %s AND group_key = %s
					  ORDER BY available_at ASC, id ASC LIMIT 1',
					self::STATUS_PENDING,
					$now,
					$group
				);
				if ( ! $row ) {
					continue;
				}
				$lease_until = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' UTC' ) + $lease_seconds );
				$won         = $this->db->update(
					'jobs',
					[
						'status'           => self::STATUS_CLAIMED,
						'claim_expires_at' => $lease_until,
						'attempts'         => (int) $row['attempts'] + 1,
						'updated_at'       => $now,
					],
					[
						'id'     => (int) $row['id'],
						'status' => self::STATUS_PENDING,
					]
				);
				if ( $won > 0 ) {
					$row['status']   = self::STATUS_CLAIMED;
					$row['attempts'] = (int) $row['attempts'] + 1;
					$claimed[]       = $row;
					$progress        = true;
				}
			}
			if ( ! $progress ) {
				break;
			}
		}
		return $claimed;
	}

	/**
	 * Execute claimed jobs through their registered handlers. Returns [done, failed, dead].
	 *
	 * @param array<int,array<string,mixed>> $claimed Rows from claim().
	 * @return array{0:int,1:int,2:int}
	 */
	public function process( array $claimed ): array {
		$done = 0;
		$failed = 0;
		$dead = 0;

		foreach ( $claimed as $row ) {
			$id     = (int) $row['id'];
			$result = $this->run_one( $row );

			if ( 'done' === $result ) {
				$this->complete( $id );
				++$done;
			} elseif ( 'dead' === $result ) {
				++$dead;
			} else {
				++$failed;
			}
		}
		return [ $done, $failed, $dead ];
	}

	/** Mark a claimed job finished. */
	public function complete( int $job_id ): void {
		$this->db->update(
			'jobs',
			[
				'status'           => self::STATUS_DONE,
				'claim_expires_at' => null,
				'updated_at'       => current_time( 'mysql', true ),
			],
			[ 'id' => $job_id ]
		);
	}

	/**
	 * Cancel a job that has not started yet. A claimed (running) job cannot be cancelled through
	 * this path — its handler owns the outcome.
	 */
	public function cancel( int $job_id ): bool {
		$changed = $this->db->update(
			'jobs',
			[
				'status'     => self::STATUS_CANCELLED,
				'updated_at' => current_time( 'mysql', true ),
			],
			[
				'id'     => $job_id,
				'status' => self::STATUS_PENDING,
			]
		);
		return $changed > 0;
	}

	/**
	 * Phase 27 — observability: queue totals by status plus the age of the oldest job still
	 * waiting, so the dashboard can flag a drain that stopped keeping up.
	 *
	 * @return array{pending:int,claimed:int,done:int,dead:int,cancelled:int,oldest_pending_age_seconds:int}
	 */
	public function stats(): array {
		$out = [
			'pending'                    => 0,
			'claimed'                    => 0,
			'done'                       => 0,
			'dead'                       => 0,
			'cancelled'                  => 0,
			'oldest_pending_age_seconds' => 0,
		];

		$rows = $this->db->results(
			'SELECT status, COUNT(*) AS total FROM ' . $this->db->table( 'jobs' ) . ' GROUP BY status'
		);
		foreach ( $rows as $row ) {
			$status = (string) $row['status'];
			if ( isset( $out[ $status ] ) ) {
				$out[ $status ] = (int) $row['total'];
			}
		}

		$oldest = $this->db->scalar(
			'SELECT MIN(available_at) FROM ' . $this->db->table( 'jobs' ) . ' WHERE status = %s',
			self::STATUS_PENDING
		);
		if ( is_string( $oldest ) && '' !== $oldest ) {
			$out['oldest_pending_age_seconds'] = max( 0, time() - strtotime( $oldest . ' UTC' ) );
		}
		return $out;
	}

	/**
	 * Phase 27 — the dead-letter backlog, most recent first, for inspection and replay.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function dead_letters( int $limit = 30 ): array {
		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'jobs' ) . ' WHERE status = %s ORDER BY updated_at DESC, id DESC LIMIT %d',
			self::STATUS_DEAD,
			$limit
		);
	}

	/**
	 * Phase 27 — controlled replay: a dead-lettered job is deliberately put back in the queue
	 * with its attempts reset. The idempotency key is kept on purpose — replay IS the same
	 * logical operation, so the protection against duplicate delivery must survive it.
	 */
	public function replay( int $job_id ): bool {
		$row = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'jobs' ) . ' WHERE id = %d AND status = %s',
			$job_id,
			self::STATUS_DEAD
		);
		if ( ! $row ) {
			return false;
		}
		$changed = $this->db->update(
			'jobs',
			[
				'status'           => self::STATUS_PENDING,
				'attempts'         => 0,
				'claim_expires_at' => null,
				'available_at'     => current_time( 'mysql', true ),
				'last_error'       => null,
				'updated_at'       => current_time( 'mysql', true ),
			],
			[
				'id'     => $job_id,
				'status' => self::STATUS_DEAD,
			]
		);
		if ( $changed > 0 ) {
			$this->logger->info( 'jobs', 'job replayed', [ 'id' => $job_id, 'type' => (string) $row['job_type'] ] );
		}
		return $changed > 0;
	}

	/**
	 * Return expired-lease (crashed-worker) jobs to the queue, or dead-letter them when they are
	 * out of attempts. Returns [returned, dead].
	 *
	 * @return array{0:int,1:int}
	 */
	public function reclaim_expired_leases(): array {
		$now     = current_time( 'mysql', true );
		$expired = $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'jobs' ) . '
			 WHERE status = %s AND claim_expires_at IS NOT NULL AND claim_expires_at <= %s',
			self::STATUS_CLAIMED,
			$now
		);

		$returned = 0;
		$dead     = 0;
		foreach ( $expired as $row ) {
			$id = (int) $row['id'];
			if ( (int) $row['attempts'] >= (int) $row['max_attempts'] ) {
				$this->dead_letter( $id, 'lease expired after final attempt' );
				++$dead;
				continue;
			}
			$this->db->update(
				'jobs',
				[
					'status'           => self::STATUS_PENDING,
					'claim_expires_at' => null,
					'updated_at'       => $now,
				],
				[
					'id'     => $id,
					'status' => self::STATUS_CLAIMED,
				]
			);
			++$returned;
		}
		return [ $returned, $dead ];
	}

	/** Move a job to the dead-letter state with a reason. */
	public function dead_letter( int $job_id, string $reason ): void {
		$this->db->update(
			'jobs',
			[
				'status'           => self::STATUS_DEAD,
				'claim_expires_at' => null,
				'last_error'       => $reason,
				'updated_at'       => current_time( 'mysql', true ),
			],
			[ 'id' => $job_id ]
		);
		$this->logger->error( 'jobs', 'job dead-lettered', [ 'id' => $job_id, 'reason' => $reason ] );
	}

	/**
	 * Retry delay for a given attempt: exponential backoff, capped, plus jitter in [0, base).
	 * Jitter breaks the thundering herd when many jobs fail at once against one outage.
	 */
	public function retry_delay_seconds( int $attempt ): int {
		$exponential = self::BACKOFF_BASE_SECONDS * ( 2 ** max( 0, $attempt - 1 ) );
		$capped      = min( self::BACKOFF_CAP_SECONDS, $exponential );
		$jitter      = wp_rand( 0, self::BACKOFF_BASE_SECONDS - 1 );
		return $capped + $jitter;
	}

	// ------------------------------------------------------------- internals

	/** @return array<string,mixed>|null */
	private function find_active_by_key( string $queue, string $key ): ?array {
		return $this->db->row(
			'SELECT id FROM ' . $this->db->table( 'jobs' ) . '
			 WHERE queue = %s AND idempotency_key = %s AND status IN (%s, %s)',
			$queue,
			$key,
			self::STATUS_PENDING,
			self::STATUS_CLAIMED
		);
	}

	/**
	 * Run one claimed job through its handler. Returns 'done', 'retry' or 'dead'.
	 */
	private function run_one( array $row ): string {
		$id       = (int) $row['id'];
		$job_type = (string) $row['job_type'];
		$envelope = Envelope::open( (string) $row['envelope'] );

		if ( null === $envelope ) {
			$this->dead_letter( $id, 'malformed envelope' );
			return 'dead';
		}
		if ( $envelope['v'] > Envelope::VERSION ) {
			// A newer schema than this worker understands: never guess — isolate it.
			$this->dead_letter( $id, 'unsupported envelope version ' . $envelope['v'] );
			return 'dead';
		}
		if ( ! isset( $this->handlers[ $job_type ] ) ) {
			$this->dead_letter( $id, 'no handler registered for ' . $job_type );
			return 'dead';
		}

		try {
			( $this->handlers[ $job_type ] )(
				$envelope['payload'],
				new JobContext(
					$id,
					(int) $row['tenant_id'],
					$envelope['trace_id'],
					(string) ( $envelope['meta']['idempotency_key'] ?? '' ),
					(int) $row['attempts'],
					(string) ( $envelope['meta']['group'] ?? '' )
				)
			);
			return 'done';
		} catch ( \Throwable $e ) {
			return $this->mark_failed( $row, $e->getMessage() );
		}
	}

	/** Record a failed attempt; retry with backoff or dead-letter. */
	private function mark_failed( array $row, string $error ): string {
		$id       = (int) $row['id'];
		$attempts = (int) $row['attempts'];
		$max      = (int) $row['max_attempts'];

		if ( $attempts >= $max ) {
			$this->dead_letter( $id, $error );
			return 'dead';
		}

		$now   = current_time( 'mysql', true );
		$retry = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' UTC' ) + $this->retry_delay_seconds( $attempts ) );

		$this->db->update(
			'jobs',
			[
				'status'           => self::STATUS_PENDING,
				'claim_expires_at' => null,
				'available_at'     => $retry,
				'last_error'       => $error,
				'updated_at'       => $now,
			],
			[ 'id' => $id ]
		);
		$this->logger->warning( 'jobs', 'job attempt failed, scheduled retry', [ 'id' => $id, 'attempt' => $attempts, 'retry_at' => $retry, 'error' => $error ] );
		return 'retry';
	}
}
