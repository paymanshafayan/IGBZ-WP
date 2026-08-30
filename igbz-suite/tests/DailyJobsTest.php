<?php
/**
 * Phase 26 — the daily work runs as independent queued jobs.
 *
 * Renewals, commissions, settlements and cleanups enqueue under the daily slot key (duplicate
 * beats absorbed), drain in the same daily beat, and the bounded ones apply the queue's
 * canonical continuation contract: a full batch re-queues the next round under a derived key,
 * capped, so nothing loops forever and nothing blocks the shared daily cron request.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\Fx\FxModule;
use IGBZ\Suite\Modules\MultiTenant\MultiTenantModule;
use IGBZ\Suite\Modules\RestApi\RestApiModule;
use IGBZ\Suite\Support\Cron;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Jobs\JobContext;
use IGBZ\Suite\Support\Jobs\JobQueue;
use IGBZ\Suite\Support\Jobs\QueueRunner;
use IGBZ\Suite\Support\Plugin;

/** Scripted batch sizes so the continuation contract can be driven. */
final class DailyCountingSpy {
	public int $calls = 0;

	/** @var array<int,int> Scripted return values, consumed in order (then 0). */
	public array $returns = [];

	public function process_due_renewals(): int {
		return $this->next();
	}

	public function process_pending_commissions(): int {
		return $this->next();
	}

	public function release_due(): int {
		return $this->next();
	}

	public function settle_due( int $limit = 50 ): int {
		return $this->next();
	}

	public function expire_past_grace( int $limit = 100 ): int {
		return 0; // Phase 32: the grace sweep rides the plans job but the spy counts renewals.
	}

	/** @return array{repaired:int,missing_credit:int,stale_disputes:int} */
	public function reconcile(): array {
		return [ 'repaired' => 0, 'missing_credit' => 0, 'stale_disputes' => 0 ];
	}

	public int $bill_calls = 0;

	public function bill_accounts(): void {
		++$this->bill_calls;
	}

	private function next(): int {
		++$this->calls;
		return (int) ( $this->returns ? array_shift( $this->returns ) : 0 );
	}
}

/** Records plain calls. */
final class DailyCallSpy {
	public int $calls = 0;

	/** @var array<int,int> */
	public array $args = [];

	public function flush_cache(): void {
		++$this->calls;
	}

	public function bill_accounts(): void {
		++$this->calls;
	}

	public function collect_all(): void {
		++$this->calls;
	}

	public function ensure_card_funded(): void {
		++$this->calls;
	}

	public function prune_expired( int $grace_days = 7 ): int {
		++$this->calls;
		$this->args[] = $grace_days;
		return 0;
	}

	public function prune_stale( int $days = 180 ): int {
		++$this->calls;
		$this->args[] = $days;
		return 0;
	}
}

final class DailyJobsTest extends TestCase {

	private JobQueueDb $wpdb;
	private JobQueue $queue;
	private QueueRunner $runner;

	public function run(): void {
		$this->daily_slot_key_lands_on_midnight();
		$this->daily_beat_enqueues_the_multitenant_set_once();
		$this->renewals_continue_until_partial_and_stop_at_cap();
		$this->master_release_gate_is_checked_at_run_time();
		$this->api_prune_runs_both_cleanups_with_the_retention_floor();
		$this->housekeeping_beat_enqueues_and_drains_the_body();
		$this->fx_daily_beat_enqueues_three_jobs_and_settle_continues();
		$this->continue_round_is_the_canonical_contract();
	}

	private function fresh(): void {
		igbz_test_reset_settings();
		$this->wpdb      = new JobQueueDb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->queue     = new JobQueue( new Db(), igbz()->get( 'logger' ) );
		$this->runner    = new QueueRunner( $this->queue, igbz()->get( 'logger' ) );
		igbz()->bind( 'jobs', fn () => $this->queue );
	}

	/** Snapshot/restore the container so spy services never leak into later cases. */
	private function with_clean_container( callable $fn ): void {
		$factories_ref = new ReflectionProperty( Plugin::class, 'factories' );
		$resolved_ref  = new ReflectionProperty( Plugin::class, 'resolved' );
		$factories     = $factories_ref->getValue( igbz() );
		$cache         = $resolved_ref->getValue( igbz() );

		try {
			$fn();
		} finally {
			$factories_ref->setValue( igbz(), $factories );
			$resolved_ref->setValue( igbz(), $cache );
		}
	}

	/** @return array<int,array<string,mixed>> */
	private function jobs( string $status = '' ): array {
		$out = [];
		foreach ( $this->wpdb->tables['jobs'] as $row ) {
			if ( '' === $status || $row['status'] === $status ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	private function daily_slot_key_lands_on_midnight(): void {
		$this->fresh();

		$slot = JobQueue::slot( DAY_IN_SECONDS );
		$this->assert_true( 1 === preg_match( '/^\d{4}-\d{2}-\d{2} 00:00:00$/', $slot ), 'the daily slot key sits on midnight UTC' );
		$this->assert_same( $slot, JobQueue::slot( DAY_IN_SECONDS ), 'the same day yields the same key' );
	}

	private function daily_beat_enqueues_the_multitenant_set_once(): void {
		$this->fresh();

		$module = new MultiTenantModule();
		$module->run_daily();
		$module->master_payment_tick();
		$module->run_daily(); // Duplicate beat.

		$jobs  = $this->jobs();
		$types = array_map( static fn ( array $j ) => (string) $j['job_type'], $jobs );
		sort( $types );
		$this->assert_same(
			[ 'affiliate.commissions', 'bnpl.reconcile', 'fx.billing.reconcile', 'marketplace.flush', 'master.reconcile', 'master.release', 'plans.renewals', 'wallet.reconcile' ],
			$types,
			'the daily set is one slot-keyed job each, master_payment_tick shares the master.release key'
		);

		foreach ( $jobs as $job ) {
			$this->assert_true(
				1 === preg_match( '/^\d{4}-\d{2}-\d{2} 00:00:00$/', (string) $job['idempotency_key'] ),
				'daily jobs carry the bare daily slot key'
			);
		}
	}

	private function renewals_continue_until_partial_and_stop_at_cap(): void {
		$this->fresh();

		$this->with_clean_container( function (): void {
			$plans          = new DailyCountingSpy();
			$plans->returns = [ 100, 40 ];
			igbz()->bind( 'plans', static fn () => $plans );

			( new MultiTenantModule() )->register_queue_handlers( $this->queue );
			$this->queue->enqueue( 'plans.renewals', [ 'round' => 0 ], [ 'idempotency_key' => 'D:renewals' ] );
			$this->runner->run();

			$this->assert_same( 2, $plans->calls, 'a full renewal batch re-queues; the partial one ends the sweep' );
			$keys = array_map( static fn ( array $j ) => (string) $j['idempotency_key'], $this->jobs( JobQueue::STATUS_DONE ) );
			sort( $keys );
			$this->assert_same( [ 'D:renewals', 'D:renewals:r1' ], $keys, 'the continuation derives its key from the round before it' );

			// Pathological: the batch is always full — the round cap stops the loop.
			$capped          = new DailyCountingSpy();
			$capped->returns = array_fill( 0, 50, 100 );
			igbz()->bind( 'plans', static fn () => $capped );
			$this->queue->enqueue( 'plans.renewals', [ 'round' => 0 ], [ 'idempotency_key' => 'D:cap' ] );
			$this->runner->run();
			$this->assert_same( 11, $capped->calls, 'rounds 0..10 run, then the cap stops the loop' );
			$this->assert_same( 0, count( $this->jobs( JobQueue::STATUS_PENDING ) ), 'nothing is left queued' );
		} );
	}

	private function master_release_gate_is_checked_at_run_time(): void {
		$this->fresh();

		$this->with_clean_container( function (): void {
			$master = new DailyCountingSpy();
			igbz()->bind( 'master.payment', static fn () => $master );
			( new MultiTenantModule() )->register_queue_handlers( $this->queue );

			igbz()->settings()->set( 'master_payment.enabled', false );
			$this->queue->enqueue( 'master.release', [], [ 'idempotency_key' => 'mr:off' ] );
			$this->runner->run();
			$this->assert_same( 0, $master->calls, 'a disabled master gateway releases nothing' );
			$this->assert_same( 1, count( $this->jobs( JobQueue::STATUS_DONE ) ), 'the job still completes' );

			igbz()->settings()->set( 'master_payment.enabled', true );
			$this->queue->enqueue( 'master.release', [], [ 'idempotency_key' => 'mr:on' ] );
			$this->runner->run();
			$this->assert_same( 1, $master->calls, 're-enabling at run time releases in the same beat' );
		} );
	}

	private function api_prune_runs_both_cleanups_with_the_retention_floor(): void {
		$this->fresh();

		$this->with_clean_container( function (): void {
			$tokens  = new DailyCallSpy();
			$devices = new DailyCallSpy();
			igbz()->bind( 'api.tokens', static fn () => $tokens );
			igbz()->bind( 'api.devices', static fn () => $devices );
			( new RestApiModule() )->register_queue_handlers( $this->queue );

			// A retention below the floor must not reach the device prune.
			igbz()->settings()->set( 'api.device_retention_days', 5 );
			$this->queue->enqueue( 'api.prune', [], [ 'idempotency_key' => 'prune:1' ] );
			$this->runner->run();

			$this->assert_same( 1, $tokens->calls, 'expired tokens are pruned' );
			$this->assert_same( 1, $devices->calls, 'stale devices are pruned' );
			$this->assert_same( 30, (int) $devices->args[0], 'the device retention floor holds at 30 days' );
		} );
	}

	private function housekeeping_beat_enqueues_and_drains_the_body(): void {
		$this->fresh();

		$this->with_clean_container( function (): void {
			igbz()->bind( 'jobs.runner', fn () => $this->runner );
			( new Cron() )->register();
			// Phase 72: point the backup at a directory that cannot exist, so this
			// test stays about the housekeeping body (the backup failing honestly —
			// loudly, as a retryable job — is covered by BackupTest + the SLO panel).
			igbz()->bind( 'backup', static fn (): \IGBZ\Suite\Support\Backup\BackupService =>
				new \IGBZ\Suite\Support\Backup\BackupService( new \IGBZ\Suite\Support\Db(), igbz()->settings(), igbz()->logger(), '/nonexistent-igbz-backup-dir' ) );

			( new Cron() )->housekeeping();
			( new Cron() )->housekeeping(); // Duplicate beat.
			// Phase 72: the daily beat carries housekeeping AND the encrypted backup;
			// each has its own slot key, so duplicates of the same beat still absorb.
			$this->assert_same( 2, count( $this->jobs() ), 'the daily slot keys absorb the duplicate beat' );
			$this->assert_same( 'cron.housekeeping', (string) $this->jobs()[0]['job_type'], 'housekeeping is a queued job' );
			$this->assert_same( 'cron.backup', (string) $this->jobs()[1]['job_type'], 'the backup rides the same beat' );

			$this->wpdb->queries = [];
			$this->runner->run();
			$this->assert_same( 1, count( $this->jobs( JobQueue::STATUS_DONE ) ), 'the housekeeping job completes' );

			$otp = $tokens = false;
			foreach ( $this->wpdb->queries as $sql ) {
				if ( str_contains( (string) $sql, 'igbz_otp_codes' ) ) {
					$otp = true;
				}
				if ( str_contains( (string) $sql, 'igbz_api_tokens' ) ) {
					$tokens = true;
				}
			}
			$this->assert_true( $otp, 'the OTP cleanup runs inside the job' );
			$this->assert_true( $tokens, 'the API-token cleanup runs inside the job' );
		} );
	}

	private function fx_daily_beat_enqueues_three_jobs_and_settle_continues(): void {
		$this->fresh();

		$this->with_clean_container( function (): void {
			$billing          = new DailyCountingSpy();
			$billing->returns = [ 50, 20 ];
			$spy              = new DailyCallSpy();
			igbz()->bind( 'fx.billing', static fn () => $billing );
			igbz()->bind( 'fx.ramp', static fn () => $spy );

			$module = new FxModule();
			$module->run_daily();
			$module->run_daily(); // Duplicate beat.
			$types = array_map( static fn ( array $j ) => (string) $j['job_type'], $this->jobs() );
			sort( $types );
			$this->assert_same( [ 'fx.billing.bills', 'fx.billing.settle', 'fx.ramp.fund' ], $types, 'the FX daily set enqueues once per job' );

			$module->register_queue_handlers( $this->queue );
			$this->runner->run();

			$this->assert_same( 2, $billing->calls, 'a full settle batch re-queues; the partial one ends it' );
			$this->assert_same( 1, $billing->bill_calls, 'the billing half runs once from its own job' );
			$this->assert_same( 1, $spy->calls, 'the ramp card-funding runs from its own job' );
			$this->assert_same( 0, count( $this->jobs( JobQueue::STATUS_PENDING ) ), 'the FX day drained completely' );
		} );
	}

	private function continue_round_is_the_canonical_contract(): void {
		$this->fresh();

		$ctx = new JobContext( 1, 7, 'trace', 'BASE', 1, '7' );

		// Below batch: nothing happens.
		$this->queue->continue_round( $ctx, [ 'round' => 0 ], 'x.sweep', 99, 100 );
		$this->assert_same( 0, count( $this->jobs() ), 'a partial batch never continues' );

		// Past the cap: nothing happens.
		$this->queue->continue_round( $ctx, [ 'round' => 10 ], 'x.sweep', 100, 100, 10 );
		$this->assert_same( 0, count( $this->jobs() ), 'the round cap stops a pathological backlog' );

		// Full batch inside the cap: one continuation with a derived key, tenant and group intact.
		$this->queue->continue_round( $ctx, [ 'round' => 2 ], 'x.sweep', 100, 100 );
		$jobs = $this->jobs();
		$this->assert_same( 1, count( $jobs ), 'a full batch enqueues exactly one continuation' );
		$this->assert_same( 'BASE:r3', (string) $jobs[0]['idempotency_key'], 'the key derives from the current round' );
		$this->assert_same( 7, (int) $jobs[0]['tenant_id'], 'the tenant survives the continuation' );
		$this->assert_same( '7', (string) $jobs[0]['group_key'], 'the fairness group survives the continuation' );
		$this->assert_same( 3, (int) ( json_decode( (string) $jobs[0]['envelope'], true )['payload']['round'] ?? 0 ), 'the next round travels in the payload' );

		// Control-plane jobs (empty group) keep an empty group.
		$plane = new JobContext( 2, 0, 'trace', 'PLANE', 1, '' );
		$this->queue->continue_round( $plane, [ 'round' => 0 ], 'x.plane', 20, 20 );
		$plane_jobs = array_values( array_filter( $this->jobs(), static fn ( array $j ) => 'x.plane' === $j['job_type'] ) );
		$this->assert_same( '', (string) $plane_jobs[0]['group_key'], 'control-plane continuations stay ungrouped' );
	}
}
