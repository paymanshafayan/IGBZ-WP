<?php
/**
 * Phase 27 — the queue under pressure.
 *
 * A loud tenant must not starve the others, two workers can never win the same row, a provider
 * outage retries with backoff and ends in the dead letter (never silently lost), a dead job can
 * be deliberately replayed, and the stats/dead-letter views tell the operator what is going on.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Jobs\JobQueue;
use IGBZ\Suite\Support\Jobs\QueueRunner;

final class QueueLoadTest extends TestCase {

	private JobQueueDb $wpdb;
	private JobQueue $queue;
	private QueueRunner $runner;

	public function run(): void {
		$this->load_drains_fairly_without_starvation();
		$this->two_workers_claim_disjoint_sets();
		$this->provider_outage_retries_then_dead_letters();
		$this->replay_brings_dead_jobs_back_into_the_queue();
		$this->stats_reports_totals_and_oldest_age();
		$this->dead_letters_list_most_recent_first();
	}

	private function fresh(): void {
		igbz_test_reset_settings();
		$this->wpdb      = new JobQueueDb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->queue     = new JobQueue( new Db(), igbz()->get( 'logger' ) );
		$this->runner    = new QueueRunner( $this->queue, igbz()->get( 'logger' ) );
		igbz()->bind( 'jobs', fn () => $this->queue );
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

	private function load_drains_fairly_without_starvation(): void {
		$this->fresh();

		// Tenant 7 floods the queue; tenants 8 and 9 are small.
		for ( $i = 0; $i < 30; ++$i ) {
			$this->queue->enqueue( 'carts.sweep', [], [ 'tenant_id' => 7, 'group' => '7' ] );
		}
		foreach ( [ 8, 9 ] as $tenant ) {
			for ( $i = 0; $i < 5; ++$i ) {
				$this->queue->enqueue( 'carts.sweep', [], [ 'tenant_id' => $tenant, 'group' => (string) $tenant ] );
			}
		}

		// A claim budget far smaller than the backlog: every tenant still gets its share.
		$claimed = $this->queue->claim( 15 );
		$shares  = [ 7 => 0, 8 => 0, 9 => 0 ];
		foreach ( $claimed as $row ) {
			++$shares[ (int) $row['tenant_id'] ];
		}
		$this->assert_same( 5, $shares[8], 'the small tenant gets its full fair share' );
		$this->assert_same( 5, $shares[9], 'the other small tenant gets its full fair share' );
		$this->assert_same( 5, $shares[7], 'the loud tenant gets an equal share, not the whole budget' );

		// Drain the rest in rounds: nothing is ever skipped.
		$drained = 15;
		while ( true ) {
			$next = $this->queue->claim( 10 );
			if ( [] === $next ) {
				break;
			}
			$drained += count( $next );
		}
		$this->assert_same( 40, $drained, 'every job is claimed exactly once across the rounds' );
	}

	private function two_workers_claim_disjoint_sets(): void {
		$this->fresh();

		for ( $i = 0; $i < 8; ++$i ) {
			$this->queue->enqueue( 'feed.sync', [ 'i' => $i ] );
		}

		// Worker A grabs a batch; worker B arrives for the same pool moments later.
		$first  = $this->queue->claim( 5 );
		$second = $this->queue->claim( 5 );

		$ids_first  = array_map( static fn ( array $r ) => (int) $r['id'], $first );
		$ids_second = array_map( static fn ( array $r ) => (int) $r['id'], $second );
		$this->assert_same( 5, count( $ids_first ), 'the first worker claims a full batch' );
		$this->assert_same( 3, count( $ids_second ), 'the second worker gets what is left' );
		$this->assert_same( [], array_intersect( $ids_first, $ids_second ), 'no job is won twice' );

		$third = $this->queue->claim( 5 );
		$this->assert_same( 0, count( $third ), 'a third worker finds the pool dry' );
	}

	private function provider_outage_retries_then_dead_letters(): void {
		$this->fresh();

		$this->queue->register( 'load.fail', static function (): void {
			throw new RuntimeException( 'downstream outage' );
		} );
		$this->queue->enqueue( 'load.fail', [], [ 'max_attempts' => 2, 'idempotency_key' => 'outage' ] );

		// Attempt 1 fails: back into the queue with a future available_at (jittered backoff).
		$this->runner->run();
		$this->assert_same( 1, count( $this->jobs( JobQueue::STATUS_PENDING ) ), 'the failed attempt is rescheduled, never dropped' );
		$row = $this->jobs( JobQueue::STATUS_PENDING )[0];
		$this->assert_true( strtotime( (string) $row['available_at'] . ' UTC' ) > time(), 'the retry respects the backoff window' );
		$this->assert_same( 'downstream outage', (string) $row['last_error'], 'the failure reason is kept on the row' );

		// Time travel past the backoff; attempt 2 exhausts max_attempts -> dead letter.
		$this->wpdb->tables['jobs'][ (int) $row['id'] ]['available_at'] = gmdate( 'Y-m-d H:i:s', time() - 5 );
		$this->runner->run();

		$dead = $this->jobs( JobQueue::STATUS_DEAD );
		$this->assert_same( 1, count( $dead ), 'the final failure is dead-lettered' );
		$this->assert_same( 'downstream outage', (string) $dead[0]['last_error'], 'the dead letter keeps the reason' );
		$this->assert_same( 2, (int) $dead[0]['attempts'], 'the dead letter records the attempt count' );
	}

	private function replay_brings_dead_jobs_back_into_the_queue(): void {
		$this->fresh();

		$ran = 0;
		$this->queue->register( 'load.replayed', function () use ( &$ran ): void {
			++$ran;
		} );
		$id = $this->queue->enqueue( 'load.replayed', [], [ 'idempotency_key' => 'replay-me' ] );
		$this->queue->dead_letter( $id, 'poison' );
		$this->assert_same( 1, count( $this->jobs( JobQueue::STATUS_DEAD ) ), 'the job sits in the dead letter' );

		$this->assert_true( $this->queue->replay( $id ), 'a dead job can be replayed deliberately' );
		$this->assert_true( ! $this->queue->replay( $id ), 'a job that is no longer dead refuses replay' );

		$row = $this->wpdb->tables['jobs'][ $id ];
		$this->assert_same( JobQueue::STATUS_PENDING, (string) $row['status'], 'replay re-queues' );
		$this->assert_same( 0, (int) $row['attempts'], 'replay resets the attempt count' );
		$this->assert_same( 'replay-me', (string) $row['idempotency_key'], 'the idempotency key survives replay — same logical operation' );

		$this->runner->run();
		$this->assert_same( 1, $ran, 'the replayed job runs through its handler' );
		$this->assert_same( JobQueue::STATUS_DONE, (string) $this->wpdb->tables['jobs'][ $id ]['status'], 'the replayed job completes' );
	}

	private function stats_reports_totals_and_oldest_age(): void {
		$this->fresh();

		$empty = $this->queue->stats();
		$this->assert_same( 0, (int) $empty['pending'], 'an empty queue reports zero backlog' );
		$this->assert_same( 0, (int) $empty['oldest_pending_age_seconds'], 'an empty queue has no waiting age' );

		$a = $this->queue->enqueue( 'a.one', [], [ 'delay_seconds' => 60 ] );
		$this->queue->enqueue( 'a.two', [] );
		$b = $this->queue->enqueue( 'a.done', [] );
		$this->queue->complete( $b );
		$c = $this->queue->enqueue( 'a.dead', [] );
		$this->queue->dead_letter( $c, 'boom' );

		$stats = $this->queue->stats();
		$this->assert_same( 2, (int) $stats['pending'], 'pending counts only waiting jobs' );
		$this->assert_same( 1, (int) $stats['done'], 'done is counted' );
		$this->assert_same( 1, (int) $stats['dead'], 'dead is counted' );
		$this->assert_true( (int) $stats['oldest_pending_age_seconds'] >= 0, 'the oldest waiting job has a non-negative age' );
		unset( $a );
	}

	private function dead_letters_list_most_recent_first(): void {
		$this->fresh();

		$older = $this->queue->enqueue( 'dl.older', [] );
		$this->queue->dead_letter( $older, 'first' );
		$this->wpdb->tables['jobs'][ $older ]['updated_at'] = gmdate( 'Y-m-d H:i:s', time() - 3600 );

		$newer = $this->queue->enqueue( 'dl.newer', [] );
		$this->queue->dead_letter( $newer, 'second' );

		$list = $this->queue->dead_letters();
		$this->assert_same( 2, count( $list ), 'both dead letters are listed' );
		$this->assert_same( $newer, (int) $list[0]['id'], 'the most recent dead letter comes first' );
		$this->assert_same( 1, count( $this->queue->dead_letters( 1 ) ), 'the list respects its limit' );
	}
}
