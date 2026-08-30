<?php
/**
 * Phase 24 — the five-minute sweeps run as independent queued jobs. The beat enqueues (idempotent
 * per time slot, so WP-Cron's duplicate deliveries are absorbed), the runner drains with leases,
 * failures retry with backoff, and a service that keeps failing ends up dead-lettered — never
 * silently lost, never blocking the shared cron request. Phase 50: the Instagram module's beat
 * is now exactly the two VIP sweeps; the social migration round rides the hourly beat.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\Instagram\InstagramModule;
use IGBZ\Suite\Modules\MultiTenant\MultiTenantModule;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Jobs\JobQueue;
use IGBZ\Suite\Support\Jobs\QueueRunner;
use IGBZ\Suite\Support\Plugin;

/** Records method calls instead of doing the real sweep. */
final class CronJobsSpy {
	public int $calls = 0;

	/** @var bool When true the sweep throws — simulates a failing dependency. */
	public bool $fail = false;

	public function tick(): void {
		if ( $this->fail ) {
			throw new RuntimeException( 'sweep failed' );
		}
		++$this->calls;
	}

	public function publish_due(): void {
		$this->tick();
	}

	public function expire_due(): void {
		$this->tick();
	}

	public function reconcile(): int {
		$this->tick();
		return 0; // an empty round: the continuation contract ends quietly.
	}

	public function process_pending(): void {
		$this->tick();
	}

	/** Phase 55: the daily insight-retention prune. */
	public function prune(): int {
		$this->tick();
		return 0;
	}
}

final class CronJobsTest extends TestCase {

	private JobQueueDb $wpdb;
	private JobQueue $queue;
	private QueueRunner $runner;

	public function run(): void {
		$this->slot_key_is_stable_inside_its_window();
		$this->five_minute_beat_enqueues_two_independent_jobs_once();
		$this->runner_drains_the_beat_end_to_end();
		$this->hook_entry_point_survives_wordpress_arguments();
		$this->marketplace_sync_respects_the_switch_at_run_time();
		$this->runner_stops_at_its_job_budget();
		$this->failing_sweep_is_retried_then_dead_lettered();
		$this->daily_beat_enqueues_the_insight_prune_once();
	}

	/**
	 * Regression: the first live beat crashed with a TypeError because WP hands every hook
	 * callback at least one argument. The hook wrapper must swallow them and still drain.
	 */
	private function hook_entry_point_survives_wordpress_arguments(): void {
		$this->fresh();

		$this->with_clean_container( function (): void {
			$spy = new CronJobsSpy();
			igbz()->bind( 'vip.posts', static fn () => $spy );
			( new InstagramModule() )->register_queue_handlers( $this->queue );
			$this->queue->enqueue( 'ig.vip.publish_due', [], [ 'idempotency_key' => 'hook-arg' ] );

			// Simulate WP calling the hook with its usual stray empty-string argument.
			$this->runner->on_beat();

			$this->assert_same( 1, $spy->calls, 'the hook wrapper drains despite WP hook arguments' );
		} );
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

	private function slot_key_is_stable_inside_its_window(): void {
		$this->fresh();

		$a = JobQueue::slot();
		$b = JobQueue::slot();
		$this->assert_same( $a, $b, 'the same five-minute window yields the same slot key' );
		$this->assert_true( 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $a ), 'the slot key is a full timestamp window' );

		$parts    = gmdate( 'i s', strtotime( $a . ' UTC' ) );
		$minute   = (int) substr( $parts, 0, 2 );
		$second   = (int) substr( $parts, 3, 2 );
		$this->assert_same( 0, $second, 'the window key sits on the window start (seconds zero)' );
		$this->assert_same( 0, $minute % 5, 'the window key sits on a five-minute boundary' );
	}

	private function five_minute_beat_enqueues_two_independent_jobs_once(): void {
		$this->fresh();

		( new InstagramModule() )->run_five_minutes();
		$rows = array_values( $this->wpdb->tables['jobs'] );
		$this->assert_same( 4, count( $rows ), 'one beat enqueues the two VIP sweeps and the two publisher sweeps as separate jobs' );

		$types = array_map( static fn ( array $r ): string => (string) $r['job_type'], $rows );
		sort( $types );
		$this->assert_same(
			[ 'ig.content_publish.publish_due', 'ig.content_publish.reconcile', 'ig.vip.expire_due', 'ig.vip.publish_due' ],
			$types,
			'exactly the VIP sweeps and the phase-53 publisher sweeps are enqueued (phase 50 removed the legacy content/intake ticks)'
		);

		$keys = array_unique( array_map( static fn ( array $r ): string => (string) $r['idempotency_key'], $rows ) );
		$this->assert_same( 1, count( $keys ), 'all four share the beat slot key' );

		// WP-Cron delivers a beat at least once; a duplicate must be absorbed.
		( new InstagramModule() )->run_five_minutes();
		$this->assert_same( 4, count( $this->wpdb->tables['jobs'] ), 'a duplicate beat in the same window enqueues nothing new' );
	}

	private function runner_drains_the_beat_end_to_end(): void {
		$this->fresh();

		$this->with_clean_container( function (): void {
			$vip       = new CronJobsSpy();
			$publisher = new CronJobsSpy();
			igbz()->bind( 'vip.posts', static fn () => $vip );
			igbz()->bind( 'ig.content_publish', static fn () => $publisher );

			( new InstagramModule() )->register_queue_handlers( $this->queue );
			( new InstagramModule() )->run_five_minutes();

			$totals = $this->runner->run();

			$this->assert_same( 4, $totals['done'], 'the runner completes all four sweeps' );
			$this->assert_same( 0, $totals['failed'] + $totals['dead'], 'nothing failed' );
			$this->assert_same( 2, $vip->calls, 'both VIP sweeps ran (publish + expire)' );
			$this->assert_same( 2, $publisher->calls, 'both publisher sweeps ran (due + reconcile)' );

			foreach ( $this->wpdb->tables['jobs'] as $row ) {
				$this->assert_same( 'done', (string) $row['status'], 'every drained job ends done' );
			}
		} );
	}

	private function marketplace_sync_respects_the_switch_at_run_time(): void {
		$this->fresh();

		$this->with_clean_container( function (): void {
			$sync = new CronJobsSpy();
			igbz()->bind( 'marketplace.sync', static fn () => $sync );
			( new MultiTenantModule() )->register_queue_handlers( $this->queue );

			// Enqueue happens regardless of the switch...
			igbz()->settings()->set( 'marketplace.enabled', false );
			( new MultiTenantModule() )->marketplace_tick();
			$this->assert_same( 1, count( $this->wpdb->tables['jobs'] ), 'the beat enqueues the sync job' );

			// ...but the switch is honoured at run time.
			$totals = $this->runner->run();
			$this->assert_same( 1, $totals['done'], 'the job completes' );
			$this->assert_same( 0, $sync->calls, 'a disabled marketplace does no sync work' );

			// Enabled again in the next window: real work resumes without redeployment.
			igbz()->settings()->set( 'marketplace.enabled', true );
			( new MultiTenantModule() )->marketplace_tick();
			$this->runner->run();
			$this->assert_same( 1, $sync->calls, 're-enabling resumes the sync work' );
		} );
	}

	/**
	 * Phase 55: the daily beat enqueues the insight-retention prune under the daily slot
	 * key — a duplicate beat adds nothing — and the registered handler runs it once.
	 */
	private function daily_beat_enqueues_the_insight_prune_once(): void {
		$this->fresh();

		$this->with_clean_container( function (): void {
			$spy = new CronJobsSpy();
			$vip = new CronJobsSpy();
			igbz()->bind( 'ig.growth_insights', static fn () => $spy );
			igbz()->bind( 'vip.posts', static fn () => $vip );
			( new InstagramModule() )->register_queue_handlers( $this->queue );

			( new InstagramModule() )->run_daily();
			$rows = array_values( $this->wpdb->tables['jobs'] );
			$types = array_map( static fn ( array $r ): string => (string) $r['job_type'], $rows );
			sort( $types );
			$this->assert_same(
				[ 'ig.insights.prune', 'ig.vip.reconcile' ],
				$types,
				'the daily beat enqueues the VIP reconcile and the phase-55 insight prune'
			);

			( new InstagramModule() )->run_daily();
			$this->assert_same( 2, count( $this->wpdb->tables['jobs'] ), 'a duplicate daily beat enqueues nothing new' );

			$totals = $this->runner->run();
			$this->assert_same( 2, $totals['done'], 'both daily jobs drain' );
			$this->assert_same( 1, $spy->calls, 'the prune ran exactly once' );
			$this->assert_same( 1, $vip->calls, 'the VIP reconcile ran once (an empty round ends quietly)' );
		} );
	}

	private function runner_stops_at_its_job_budget(): void {
		$this->fresh();

		$this->with_clean_container( function (): void {
			$spy = new CronJobsSpy();
			igbz()->bind( 'vip.posts', static fn () => $spy );
			( new InstagramModule() )->register_queue_handlers( $this->queue );

			for ( $i = 0; $i < 3; ++$i ) {
				$this->queue->enqueue( 'ig.vip.publish_due', [], [ 'idempotency_key' => 'budget-' . $i ] );
			}

			$first = $this->runner->run( 2 );
			$this->assert_same( 2, $first['done'], 'the budget stops the runner at two jobs' );
			$this->assert_same( 1, count( array_filter( $this->wpdb->tables['jobs'], static fn ( array $r ): bool => 'pending' === $r['status'] ) ), 'the third job stays queued' );

			$second = $this->runner->run( 2 );
			$this->assert_same( 1, $second['done'], 'the next beat picks up the remainder' );
			$this->assert_same( 3, $spy->calls, 'no sweep ran twice, none was lost' );
		} );
	}

	private function failing_sweep_is_retried_then_dead_lettered(): void {
		$this->fresh();

		$this->with_clean_container( function (): void {
			$sync = new CronJobsSpy();
			$sync->fail = true;
			igbz()->bind( 'marketplace.sync', static fn () => $sync );
			( new MultiTenantModule() )->register_queue_handlers( $this->queue );

			$this->queue->enqueue( 'marketplace.sync', [], [ 'idempotency_key' => 'doomed', 'max_attempts' => 2 ] );

			$first = $this->runner->run();
			$this->assert_same( 1, $first['failed'], 'the first failure is a scheduled retry' );
			$row = array_values( $this->wpdb->tables['jobs'] )[0];
			$this->assert_same( 'pending', (string) $row['status'], 'the failed job waits out its backoff' );
			$this->assert_true( $row['available_at'] > current_time( 'mysql', true ), 'the retry sits in the future' );
			$this->assert_same( 0, $sync->calls, 'a failing sweep counts as a call but records no success' );

			// Still inside the backoff window: the runner must not touch it early.
			$early = $this->runner->run();
			$this->assert_same( 0, $early['done'] + $early['failed'] + $early['dead'], 'backoff is honoured — no early retry' );

			// Fast-forward past the backoff: the final attempt dead-letters.
			$id = (int) $row['id'];
			$this->wpdb->tables['jobs'][ $id ]['available_at'] = gmdate( 'Y-m-d H:i:s', time() - 1 );
			$final = $this->runner->run();
			$this->assert_same( 1, $final['dead'], 'the final failure dead-letters' );
			$this->assert_same( 'dead', (string) $this->wpdb->tables['jobs'][ $id ]['status'], 'the poison sweep is isolated' );
			$this->assert_contains( 'sweep failed', (string) $this->wpdb->tables['jobs'][ $id ]['last_error'], 'the dead letter keeps the reason' );
		} );
	}
}
