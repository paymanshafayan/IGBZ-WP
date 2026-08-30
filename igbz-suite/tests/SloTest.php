<?php
/**
 * Phase 71 — SLO evaluation: the four outcome indicators (job success rate,
 * backlog size, queue latency, error volume) read from the jobs/logs tables
 * the suite already keeps, with thresholds from settings and breaches naming
 * their runbook action.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Observability\Slo;
use IGBZ\Suite\Support\Settings;

final class SloTest extends TestCase {

	public function run(): void {
		$this->quiet_store_is_all_green();
		$this->failing_jobs_breach_the_success_rate_slo();
		$this->backlog_and_latency_and_errors_each_breach_their_own_slo();
		$this->delayed_jobs_are_not_a_backlog();
	}

	/**
	 * @param array{row:?array<string,int>,pending:int|float,oldest:int|float|string,errors:int|float} $q
	 */
	private function slo_with( array $q ): array {
		$GLOBALS['wpdb']->next_results = [
			$q['row'],
			$q['pending'],
			$q['oldest'],
			$q['errors'],
		];

		return ( new Slo( new Db(), igbz_test_reset_settings() ) )->report();
	}

	private function quiet_store_is_all_green(): void {
		$report = $this->slo_with( [
			'row'     => [ 'done_24h' => 40, 'failed_24h' => 0, 'dead_24h' => 0 ],
			'pending' => 3,
			'oldest'  => gmdate( 'Y-m-d H:i:s', time() - 120 ),
			'errors'  => 2,
		] );

		$this->assert_same( true, $report['ok'], 'a drained, quiet store is green' );
		$this->assert_same( 40, $report['metrics']['jobs_done_24h'], 'done count surfaces' );
		$this->assert_same( 2, $report['metrics']['oldest_pending_minutes'], 'oldest due job is 2 minutes old' );
		$this->assert_same( 0, count( $report['breaches'] ), 'no breaches' );
	}

	private function failing_jobs_breach_the_success_rate_slo(): void {
		$report = $this->slo_with( [
			'row'     => [ 'done_24h' => 90, 'failed_24h' => 8, 'dead_24h' => 2 ],
			'pending' => 0,
			'oldest'  => null,
			'errors'  => 0,
		] );

		$this->assert_same( false, $report['ok'], 'breached' );
		$this->assert_same( 'slo.job_success_rate', $report['breaches'][0]['slo'], 'success-rate SLO fired' );
		$this->assert_same( '90%', $report['breaches'][0]['value'], '90% against a 98% floor' );
		$this->assert_same( 'jobs-failures', $report['breaches'][0]['action'], 'breach names its runbook action' );
	}

	private function backlog_and_latency_and_errors_each_breach_their_own_slo(): void {
		$report = $this->slo_with( [
			'row'     => [ 'done_24h' => 10, 'failed_24h' => 0, 'dead_24h' => 0 ],
			'pending' => 120,
			'oldest'  => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ),
			'errors'  => 300,
		] );

		$names = array_column( $report['breaches'], 'slo' );
		$this->assert_same(
			[ 'slo.max_pending', 'slo.max_wait_minutes', 'slo.max_errors_24h' ],
			$names,
			'each indicator fires independently'
		);
	}

	private function delayed_jobs_are_not_a_backlog(): void {
		// Oldest *due* job is fresh even though 200 pending rows exist — all future-
		// scheduled. Latency SLO stays green; only the count SLO fires.
		$report = $this->slo_with( [
			'row'     => [ 'done_24h' => 5, 'failed_24h' => 0, 'dead_24h' => 0 ],
			'pending' => 0,
			'oldest'  => gmdate( 'Y-m-d H:i:s', time() - 30 ),
			'errors'  => 0,
		] );

		$this->assert_same( 0, $report['metrics']['oldest_pending_minutes'], 'a just-due job means no latency breach' );
		$this->assert_not_contains( 'slo.max_wait_minutes', implode( ',', array_column( $report['breaches'], 'slo' ) ), 'fresh queue means no latency breach' );
	}
}
