<?php
/**
 * Phase 73 — the release gate verdicts and the operator's queue pause lever.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Support\Jobs\QueueRunner;
use IGBZ\Suite\Support\Release\ReleaseGate;

final class ReleaseGateTest extends TestCase {

	public function run(): void {
		$this->green_on_healthy_doc();
		$this->degraded_warns_but_serves();
		$this->red_on_503_or_broken_doc();
		$this->red_retries_then_a_late_green_counts();
		$this->queue_pause_freezes_the_drain_and_resumes_cleanly();
	}

	private function gate(): ReleaseGate {
		return new ReleaseGate();
	}

	private function fetch_with( array $responses ): callable {
		return static function ( string $url ) use ( &$responses ): array {
			return array_shift( $responses ) ?? [ 'code' => 0, 'body' => '' ];
		};
	}

	private function healthy_body(): string {
		return (string) wp_json_encode( [ 'ok' => true, 'data' => [ 'degraded' => false ] ] );
	}

	private function degraded_body(): string {
		return (string) wp_json_encode( [ 'ok' => true, 'data' => [ 'degraded' => true ] ] );
	}

	private function green_on_healthy_doc(): void {
		$v = $this->gate()->verify( $this->fetch_with( [ [ 'code' => 200, 'body' => $this->healthy_body() ] ] ), 'https://shop.test/?igbz_health=1' );
		$this->assert_same( 'green', $v['state'], '200+ok+serving = verified' );
		$this->assert_same( true, $v['ok'], 'gate passes' );
		$this->assert_same( 1, $v['attempts'], 'green does not retry' );
	}

	private function degraded_warns_but_serves(): void {
		$v = $this->gate()->verify( $this->fetch_with( [ [ 'code' => 200, 'body' => $this->degraded_body() ] ] ), 'https://shop.test/?igbz_health=1' );
		$this->assert_same( 'degraded', $v['state'], 'drift is visible' );
		$this->assert_same( true, $v['ok'], '…but traffic keeps flowing (phase 70 semantics)' );
	}

	private function red_on_503_or_broken_doc(): void {
		$v = $this->gate()->verify( $this->fetch_with( [ [ 'code' => 503, 'body' => '' ] ] ), 'u', [ 'tries' => 2, 'sleep' => 0 ] );
		$this->assert_same( 'red', $v['state'], '503 is red' );
		$this->assert_same( 2, $v['attempts'], 'red retried until the budget ran out' );

		$v = $this->gate()->verify( $this->fetch_with( [ [ 'code' => 200, 'body' => 'not json' ] ] ), 'u', [ 'tries' => 1 ] );
		$this->assert_same( 'red', $v['state'], '200 with a broken document is still red' );
	}

	private function red_retries_then_a_late_green_counts(): void {
		$fetch = $this->fetch_with( [
			[ 'code' => 0, 'body' => '' ],            // still booting
			[ 'code' => 0, 'body' => '' ],
			[ 'code' => 200, 'body' => $this->healthy_body() ],
		] );
		$v = $this->gate()->verify( $fetch, 'u', [ 'tries' => 6, 'sleep' => 0 ] );
		$this->assert_same( 'green', $v['state'], 'a slow boot that comes healthy verifies' );
		$this->assert_same( 3, $v['attempts'], 'third probe ended it' );
	}

	private function queue_pause_freezes_the_drain_and_resumes_cleanly(): void {
		$settings = igbz_test_reset_settings();
		$GLOBALS['wpdb']->queries = [];

		$runner = new QueueRunner( igbz()->get( 'jobs' ), igbz()->get( 'logger' ) );

		$settings->set( 'flags.queue_paused', true );
		$totals = $runner->run();

		$this->assert_same( true, (bool) $totals['paused'], 'pause is reported back to the operator' );
		$this->assert_same( 0, $totals['rounds'], 'nothing drained' );
		$this->assert_same( 0, count( array_filter( $this->wpdb_queries(), fn ( string $q ): bool => str_contains( $q, 'SELECT' ) && str_contains( $q, 'jobs' ) ) ), 'no claim query even ran' );

		$settings->set( 'flags.queue_paused', false );
		$totals = $runner->run();
		$this->assert_false( isset( $totals['paused'] ), 'resumed: normal totals shape' );
	}

	/** @return array<int,string> */
	private function wpdb_queries(): array {
		return array_map( 'strval', $GLOBALS['wpdb']->queries ?? [] );
	}
}
