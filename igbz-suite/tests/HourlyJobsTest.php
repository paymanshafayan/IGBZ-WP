<?php
/**
 * Phase 25 — the hourly sweeps run as tenant-fair queued jobs.
 *
 * One job per active tenant per sweep (fan-out, absorbed per tenant by the hourly slot key),
 * claims taken round-robin across tenant groups so a loud tenant cannot starve the others, and
 * a capped continuation contract: while a batch comes back full the handler re-queues the next
 * round, bounded by a round cap so no tenant can loop forever inside one drain.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\Hub\HubModule;
use IGBZ\Suite\Modules\Instagram\InstagramModule;
use IGBZ\Suite\Modules\MultiTenant\Bnpl\BnplService;
use IGBZ\Suite\Modules\MultiTenant\Gamification\AbandonedCartService;
use IGBZ\Suite\Modules\MultiTenant\MultiTenantModule;
use IGBZ\Suite\Modules\MultiTenant\Repository\Tenant;
use IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Jobs\JobQueue;
use IGBZ\Suite\Support\Jobs\QueueRunner;
use IGBZ\Suite\Support\Logger;
use IGBZ\Suite\Support\Plugin;

/** Returns scripted batch sizes so the continuation contract can be driven. */
final class HourlyBnplSpy {
	public int $calls = 0;

	/** @var array<int,int> Scripted return values, consumed in order (then 0). */
	public array $returns = [];

	public function process_overdue( int $tenant_id = 0 ): int {
		return $this->count();
	}

	public function send_reminders( int $tenant_id = 0 ): int {
		return $this->count();
	}

	private function count(): int {
		++$this->calls;
		return (int) ( $this->returns ? array_shift( $this->returns ) : 0 );
	}
}




/** Scripted distributed-round sizes for the migration continuation test. */
final class HourlyMigrationSpy {
	public int $calls = 0;

	/** @var array<int,int> Scripted return values, consumed in order (then 0). */
	public array $returns = [];

	public function run_distributed_round( int $limit = 20 ): int {
		++$this->calls;
		return (int) ( $this->returns ? array_shift( $this->returns ) : 0 );
	}
}

/** Fake tenant directory for the fan-out. */
final class HourlyTenantDirectory {
	/** @var array<int,Tenant> */
	public array $tenants = [];

	/** @param array<string,mixed> $args @return array<int,Tenant> */
	public function all( array $args = [] ): array {
		return $this->tenants;
	}
}

final class HourlyJobsTest extends TestCase {

	private JobQueueDb $wpdb;
	private JobQueue $queue;
	private QueueRunner $runner;

	public function run(): void {
		$this->fair_claim_round_robins_across_tenants();
		$this->claim_without_groups_keeps_working();
		$this->fan_out_creates_one_job_per_tenant_and_absorbs_duplicate_beats();
		$this->hourly_beat_fans_out_three_sweeps_per_active_tenant();
		$this->full_batch_requeues_next_round_until_partial();
		$this->continuation_stops_at_the_round_cap();
		$this->carts_sweep_enabled_flag_is_checked_at_run_time();
		$this->scoped_sweeps_filter_sql_by_tenant();
		$this->hourly_ig_and_hub_beats_enqueue_slot_keyed_jobs();
		$this->migration_round_continues_while_full_and_stops_on_partial();
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

	private function fair_claim_round_robins_across_tenants(): void {
		$this->fresh();

		// Tenant 7 floods the queue (6 jobs); tenant 9 has only 2.
		for ( $i = 0; $i < 6; ++$i ) {
			$this->queue->enqueue( 'carts.sweep', [], [ 'tenant_id' => 7, 'group' => '7' ] );
		}
		for ( $i = 0; $i < 2; ++$i ) {
			$this->queue->enqueue( 'carts.sweep', [], [ 'tenant_id' => 9, 'group' => '9' ] );
		}

		$claimed = $this->queue->claim( 4 );
		$tenants = array_map( static fn ( array $row ) => (int) $row['tenant_id'], $claimed );
		$this->assert_same( [ 7, 9, 7, 9 ], $tenants, 'claims alternate between tenants instead of draining the loud one' );

		$rest = $this->queue->claim( 10 );
		$this->assert_same( 4, count( $rest ), 'whatever is left still gets claimed' );
		$left = array_map( static fn ( array $row ) => (int) $row['tenant_id'], $rest );
		$this->assert_same( [ 7, 7, 7, 7 ], $left, 'tenant 9 is exhausted after its fair share; 7 gets the rest' );
	}

	private function claim_without_groups_keeps_working(): void {
		$this->fresh();

		// Legacy jobs carry an empty group — they must still be claimable as one group.
		$this->queue->enqueue( 'marketplace.sync', [] );
		$this->queue->enqueue( 'marketplace.sync', [] );

		$claimed = $this->queue->claim( 1 );
		$this->assert_same( 1, count( $claimed ), 'an empty group_key is a valid group' );
		$this->assert_same( 'marketplace.sync', (string) $claimed[0]['job_type'], 'the legacy job type is intact' );
	}

	private function fan_out_creates_one_job_per_tenant_and_absorbs_duplicate_beats(): void {
		$this->fresh();

		$slot = '2026-08-28 10:00:00';
		$made = $this->queue->fan_out_tenants( 'carts.sweep', [ 3, 5, 5, 0, 7 ], [ 'slot' => $slot ] );
		$this->assert_same( 3, $made, 'one job per distinct positive tenant, duplicates and zero dropped' );
		$this->assert_same( 3, count( $this->jobs() ), 'three rows on disk' );

		$row = $this->jobs()[0];
		$this->assert_same( '3', (string) $row['group_key'], 'the group is the tenant, stamped on the row' );
		$this->assert_same( 3, (int) $row['tenant_id'], 'the tenant is stamped' );
		$this->assert_same( $slot . ':3', (string) $row['idempotency_key'], 'the key mixes the slot with the tenant' );

		$again = $this->queue->fan_out_tenants( 'carts.sweep', [ 3, 5, 7 ], [ 'slot' => $slot ] );
		$this->assert_same( 3, $again, 'the duplicate beat reports its jobs...' );
		$this->assert_same( 3, count( $this->jobs() ), '...but creates no new rows' );

		$this->queue->fan_out_tenants( 'carts.sweep', [ 3, 5, 7 ], [ 'slot' => '2026-08-28 11:00:00' ] );
		$this->assert_same( 6, count( $this->jobs() ), 'a new window fans out again' );
	}

	private function hourly_beat_fans_out_three_sweeps_per_active_tenant(): void {
		$this->fresh();

		$this->with_clean_container( function (): void {
			$directory          = new HourlyTenantDirectory();
			$directory->tenants = [
				new Tenant( 3, 'alpha', 'Alpha', 1, Tenant::STATUS_ACTIVE, 0, 'IRT', 'fa_IR' ),
				new Tenant( 4, 'beta', 'Beta', 1, Tenant::STATUS_TRIAL, 0, 'IRT', 'fa_IR', '', '', '', [], gmdate( 'Y-m-d H:i:s', time() + 86400 ) ),
				new Tenant( 5, 'gamma', 'Gamma', 1, Tenant::STATUS_SUSPENDED, 0, 'IRT', 'fa_IR' ),
				new Tenant( 6, 'delta', 'Delta', 1, Tenant::STATUS_TRIAL, 0, 'IRT', 'fa_IR', '', '', '', [], gmdate( 'Y-m-d H:i:s', time() - 86400 ) ),
			];
			igbz()->bind( 'tenants', static fn () => $directory );

			( new MultiTenantModule() )->run_hourly();

			$jobs = $this->jobs();
			$this->assert_same( 6, count( $jobs ), '3 sweep types × 2 active tenants (trial counts, expired trial does not)' );

			$types = array_unique( array_map( static fn ( array $j ) => (string) $j['job_type'], $jobs ) );
			sort( $types );
			$this->assert_same( [ 'bnpl.overdue', 'bnpl.reminders', 'carts.sweep' ], $types, 'the three hourly sweeps fan out' );

			foreach ( $jobs as $job ) {
				$this->assert_true(
					1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:00:00:(3|4)$/', (string) $job['idempotency_key'] ),
					'each fan-out key is the hourly slot mixed with the tenant'
				);
			}

			// A duplicate hourly beat (WP-Cron fires at least once) must not double the fan-out.
			( new MultiTenantModule() )->run_hourly();
			$this->assert_same( 6, count( $this->jobs() ), 'the duplicate beat is absorbed per tenant' );
		} );
	}

	private function full_batch_requeues_next_round_until_partial(): void {
		$this->fresh();

		$this->with_clean_container( function (): void {
			$spy           = new HourlyBnplSpy();
			$spy->returns  = [ 200, 200, 40 ];
			igbz()->bind( 'bnpl', static fn () => $spy );

			( new MultiTenantModule() )->register_queue_handlers( $this->queue );
			$this->queue->enqueue( 'bnpl.overdue', [ 'round' => 0 ], [ 'tenant_id' => 4, 'group' => '4', 'idempotency_key' => 'SLOT:4' ] );

			$this->runner->run();

			$this->assert_same( 3, $spy->calls, 'full batches re-queue; the partial batch ends the sweep' );

			$done = $this->jobs( JobQueue::STATUS_DONE );
			$this->assert_same( 3, count( $done ), 'every round ran and finished' );

			// Each round derives its key from the round before it — still stable per chain,
			// so a replayed round can never fork a duplicate continuation.
			$keys = array_map( static fn ( array $j ) => (string) $j['idempotency_key'], $done );
			sort( $keys );
			$this->assert_same( [ 'SLOT:4', 'SLOT:4:r1', 'SLOT:4:r1:r2' ], $keys, 'continuations derive stable keys round over round' );
		} );
	}

	private function continuation_stops_at_the_round_cap(): void {
		$this->fresh();

		$this->with_clean_container( function (): void {
			$spy          = new HourlyBnplSpy();
			$spy->returns = array_fill( 0, 50, 200 ); // Pathological: the batch is always full.
			igbz()->bind( 'bnpl', static fn () => $spy );

			( new MultiTenantModule() )->register_queue_handlers( $this->queue );
			$this->queue->enqueue( 'bnpl.reminders', [ 'round' => 0 ], [ 'tenant_id' => 4, 'group' => '4', 'idempotency_key' => 'CAP:4' ] );

			$this->runner->run();

			$this->assert_same( 11, $spy->calls, 'rounds 0..10 run, then the cap stops the loop' );
			$this->assert_same( 0, count( $this->jobs( JobQueue::STATUS_PENDING ) ), 'nothing is left queued' );
		} );
	}

	private function carts_sweep_enabled_flag_is_checked_at_run_time(): void {
		$this->fresh();

		$this->with_clean_container( function (): void {
			igbz()->bind( 'gamification.carts', static fn () => new AbandonedCartService( new Db(), igbz()->get( 'logger' ) ) );
			( new MultiTenantModule() )->register_queue_handlers( $this->queue );

			igbz()->settings()->set( 'abandoned_cart.enabled', false );
			$this->queue->enqueue( 'carts.sweep', [], [ 'tenant_id' => 4, 'group' => '4', 'idempotency_key' => 'off:4' ] );
			$this->wpdb->queries = [];
			$this->runner->run();
			$this->assert_true( ! $this->queried( 'ig_abandoned_carts' ), 'a sweep disabled at run time never touches the carts table' );
			$this->assert_same( 1, count( $this->jobs( JobQueue::STATUS_DONE ) ), 'the job still completes' );

			igbz()->settings()->set( 'abandoned_cart.enabled', true );
			$this->queue->enqueue( 'carts.sweep', [], [ 'tenant_id' => 4, 'group' => '4', 'idempotency_key' => 'on:4' ] );
			$this->wpdb->queries = [];
			$this->runner->run();
			$this->assert_true( $this->queried( 'ig_abandoned_carts' ), 're-enabling at run time takes effect immediately' );
			$this->assert_true( $this->queried( "tenant_id = '4'" ), 'the run-time sweep is scoped to the job tenant' );
		} );
	}

	private function queried( string $needle ): bool {
		foreach ( $this->wpdb->queries as $sql ) {
			if ( str_contains( (string) $sql, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	private function scoped_sweeps_filter_sql_by_tenant(): void {
		igbz_test_reset_settings();
		$wpdb            = new wpdb();
		$GLOBALS['wpdb'] = $wpdb;

		$db      = new Db();
		$logger  = new Logger( igbz()->settings() );
		$bnpl    = new BnplService( $db, new WalletService( $db, $logger ), $logger );
		$bnpl->process_overdue( 9 );
		$this->assert_true( str_contains( (string) end( $wpdb->queries ), "tenant_id = '9'" ), 'process_overdue scopes to its tenant' );

		$bnpl->send_reminders( 9 );
		$this->assert_true( str_contains( (string) end( $wpdb->queries ), "tenant_id = '9'" ), 'send_reminders scopes to its tenant' );

		$carts = new AbandonedCartService( $db, $logger );
		$carts->sweep( 9 );
		$this->assert_true( str_contains( (string) end( $wpdb->queries ), "tenant_id = '9'" ), 'the cart sweep scopes to its tenant' );

		// And tenant 0 keeps the legacy global scan — no tenant filter.
		$bnpl->process_overdue( 0 );
		$this->assert_true( ! str_contains( (string) end( $wpdb->queries ), 'tenant_id' ), 'tenant 0 keeps the legacy global scan' );
	}

	private function hourly_ig_and_hub_beats_enqueue_slot_keyed_jobs(): void {
		$this->fresh();

		( new InstagramModule() )->run_hourly();
		( new InstagramModule() )->run_hourly(); // Duplicate beat.
		( new HubModule() )->run_hourly();
		( new HubModule() )->run_hourly(); // Duplicate beat.

		$jobs  = $this->jobs();
		$types = array_map( static fn ( array $j ) => (string) $j['job_type'], $jobs );
		sort( $types );
		$this->assert_same( [ 'hub.tick', 'ig.social.migrate' ], $types, 'one slot-keyed job per control-plane sweep, duplicates absorbed (phase 50: the legacy funnel/insight sweeps are gone)' );

		foreach ( $jobs as $job ) {
			$this->assert_true(
				1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:00:00$/', (string) $job['idempotency_key'] ),
				'control-plane jobs carry the bare hourly slot key'
			);
		}
	}

	private function migration_round_continues_while_full_and_stops_on_partial(): void {
		$this->fresh();

		$this->with_clean_container( function (): void {
			$migration = new HourlyMigrationSpy();
			$migration->returns = [ 20, 20, 5 ];
			igbz()->bind( 'ig.social_migration', static fn () => $migration );
			( new InstagramModule() )->register_queue_handlers( $this->queue );

			$this->queue->enqueue( 'ig.social.migrate', [], [ 'idempotency_key' => 'mig-base' ] );

			// One job per run, so each round is observable on its own.
			// Round 0 comes back full (20/20): the canonical continuation contract re-queues round 1.
			$this->runner->run( 1 );
			$this->assert_same( 1, $migration->calls, 'the first round ran' );
			$follow_ups = $this->jobs( JobQueue::STATUS_PENDING );
			$this->assert_same( 1, count( $follow_ups ), 'a full round queues exactly one follow-up' );
			$this->assert_true( str_contains( (string) $follow_ups[0]['idempotency_key'], ':r1' ), 'the follow-up carries the round-1 key' );

			// Round 1 full again: round 2 queued. Round 2 partial (5/20): the wave ends.
			$this->runner->run( 1 );
			$this->assert_same( 2, $migration->calls, 'round 2 ran under its own key' );
			$this->assert_true( str_contains( (string) $this->jobs( JobQueue::STATUS_PENDING )[0]['idempotency_key'], ':r2' ), 'the follow-up carries the round-2 key' );

			$this->runner->run( 1 );
			$this->assert_same( 3, $migration->calls, 'three rounds ran (full, full, partial)' );
			$this->assert_same( 0, count( $this->jobs( JobQueue::STATUS_PENDING ) ), 'the migration wave drained completely' );
			foreach ( $this->jobs() as $job ) {
				$this->assert_same( 'done', (string) $job['status'], 'every migration round ends done' );
			}
		} );
	}
}
