<?php
/**
 * Phase 71 — trace correlation: every log line carries the request/job trace
 * id, and a queued job runs under the trace it was enqueued with, so the
 * async boundary stops losing the thread.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Support\Jobs\JobQueue;
use IGBZ\Suite\Support\Logger;
use IGBZ\Suite\Support\Settings;
use IGBZ\Suite\Support\Trace;

final class TraceTest extends TestCase {

	public function run(): void {
		$this->logger_stamps_the_request_trace_on_every_line();
		$this->an_explicit_trace_is_never_overwritten();
		$this->fork_runs_under_the_job_trace_then_restores();
	}

	private function logger_stamps_the_request_trace_on_every_line(): void {
		Trace::reset();
		$GLOBALS['wpdb']->next_results = [];

		$logger = new Logger( $this->settings() );
		$logger->warning( 'test', 'something odd', [ 'order' => 12 ] );

		$context = json_decode( (string) $GLOBALS['wpdb']->last_write['data']['context'], true );
		$this->assert_same( 32, strlen( (string) ( $context['request_id'] ?? '' ) ), 'a minted trace id rides along' );
		$this->assert_same( Trace::id(), (string) $context['request_id'], 'and it is the ambient request trace' );
		$this->assert_same( 12, $context['order'], 'caller context preserved' );
	}

	private function an_explicit_trace_is_never_overwritten(): void {
		Trace::reset();
		$GLOBALS['wpdb']->next_results = [];

		$logger = new Logger( $this->settings() );
		$logger->error( 'test', 'explicit', [ 'request_id' => 'caller-set-1234' ] );

		$context = json_decode( (string) $GLOBALS['wpdb']->last_write['data']['context'], true );
		$this->assert_same( 'caller-set-1234', (string) $context['request_id'], 'caller wins over the ambient trace' );
	}

	private function fork_runs_under_the_job_trace_then_restores(): void {
		Trace::reset();
		$outer = Trace::id();

		$seen = Trace::fork( 'envelope-trace-9', fn (): string => Trace::id() );

		$this->assert_same( 'envelope-trace-9', $seen, 'inside the fork the job trace is current' );
		$this->assert_same( $outer, Trace::id(), 'and the request trace is restored afterwards' );
	}

	private function settings(): Settings {
		// log.level default (info) lets warnings/errors through untouched.
		return igbz_test_reset_settings();
	}
}
