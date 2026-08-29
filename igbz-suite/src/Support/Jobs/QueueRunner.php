<?php
/**
 * Phase 24 — the cron side of the durable queue.
 *
 * Every five-minute beat drains whatever is due, bounded twice: a job budget and a wall-clock
 * budget that ends before the next beat is due, so the runner can never overlap itself into a
 * pile-up. Anything it does not finish simply stays queued — the queue is the source of truth,
 * not the cron run. Leases of workers that died between beats are reclaimed before each claim.
 *
 * @package IGBZ\Suite\Support\Jobs
 */

declare( strict_types = 1 );

namespace IGBZ\Suite\Support\Jobs;

use IGBZ\Suite\Support\Cron;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

final class QueueRunner {

	public const DEFAULT_JOB_BUDGET  = 100;
	public const DEFAULT_TIME_BUDGET = 240;

	public function __construct( private JobQueue $queue, private Logger $logger ) {}

	public function register(): void {
		// Late priority: the same beat enqueues first (module ticks), then drains.
		add_action( Cron::HOOK_FIVE_MINUTES, [ $this, 'on_beat' ], 50 );
		// Phase 25: the hourly beat fans out the tenant sweeps; drain there too so the work
		// starts in the same request instead of waiting up to five minutes for the next beat.
		add_action( Cron::HOOK_HOURLY, [ $this, 'on_beat' ], 50 );
		// Phase 26: same reasoning for the daily beat — renewals, settlements and cleanups
		// enqueue there and drain in the same request.
		add_action( Cron::HOOK_DAILY, [ $this, 'on_beat' ], 50 );
	}

	/**
	 * Hook entry point. WordPress passes the action's arguments to every callback (an empty
	 * string when there are none), so the budgeted drain lives behind a no-argument wrapper —
	 * a typed signature here crashed the first live beat.
	 */
	public function on_beat(): void {
		$this->run();
	}

	/**
	 * Drain due jobs within budget.
	 *
	 * @return array{done:int,failed:int,dead:int,rounds:int}
	 */
	public function run( int $job_budget = self::DEFAULT_JOB_BUDGET, int $time_budget_seconds = self::DEFAULT_TIME_BUDGET ): array {
		$deadline = time() + max( 1, $time_budget_seconds );
		$totals   = [ 'done' => 0, 'failed' => 0, 'dead' => 0, 'rounds' => 0 ];

		while ( $totals['done'] + $totals['failed'] + $totals['dead'] < $job_budget && time() < $deadline ) {
			$room    = min( 10, $job_budget - ( $totals['done'] + $totals['failed'] + $totals['dead'] ) );
			$claimed = $this->queue->claim( $room );
			if ( [] === $claimed ) {
				break;
			}

			[ $done, $failed, $dead ] = $this->queue->process( $claimed );
			$totals['done']   += $done;
			$totals['failed'] += $failed;
			$totals['dead']   += $dead;
			++$totals['rounds'];
		}

		if ( $totals['rounds'] > 0 ) {
			$this->logger->info( 'jobs', 'queue drained', $totals );
		}
		return $totals;
	}
}
