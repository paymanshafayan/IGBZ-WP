<?php
/**
 * Phase 70 — the product health/readiness endpoint: 200 only when the
 * database answers AND the suite booted; drift is visible but degrades,
 * it never turns a serving store red (and never hides a broken one green).
 */

declare( strict_types = 1 );

use IGBZ\Suite\Support\HealthEndpoint;

final class HealthEndpointTest extends TestCase {

	public function run(): void {
		update_option( 'igbz_db_version', IGBZ_DB_VERSION ); // a migrated store
		$this->healthy_store_answers_200_with_the_full_document();
		$this->dead_database_answers_503();
		$this->schema_drift_degrades_but_serves();
	}

	private function healthy_store_answers_200_with_the_full_document(): void {
		$endpoint = new HealthEndpoint( $this->db_with( '1' ), fn (): int => count( \IGBZ\Suite\Support\Schema::tables() ) );
		$snap     = $endpoint->snapshot();

		$this->assert_same( 200, $snap['status'], 'a healthy store is ready for traffic' );
		$this->assert_same( true, $snap['data']['db'], 'db check included' );
		$this->assert_same( true, $snap['data']['igbz_loaded'], 'igbz booted' );
		$this->assert_same( false, $snap['data']['degraded'], 'no drift' );
		$this->assert_same( (string) count( \IGBZ\Suite\Support\Schema::tables() ) . '/' . (string) count( \IGBZ\Suite\Support\Schema::tables() ), (string) $snap['data']['igbz_tables'], 'full schema inventory' );
		// no secrets in the document — a probe must be safely public.
		$json = (string) wp_json_encode( $snap['data'] );
		$this->assert_not_contains( 'password', strtolower( $json ), 'no credential fields' );
	}

	private function dead_database_answers_503(): void {
		$endpoint = new HealthEndpoint( $this->db_with( null ), fn (): int => 0 );
		$snap     = $endpoint->snapshot();

		$this->assert_same( 503, $snap['status'], 'a dead database must not receive traffic' );
		$this->assert_same( false, $snap['ok'], 'not ok' );
		$this->assert_same( true, $snap['data']['degraded'], 'degraded flag set' );
		$this->assert_false( isset( $snap['data']['igbz_tables'] ), 'the inventory is skipped when the db is down (cheap 503)' );
	}

	private function schema_drift_degrades_but_serves(): void {
		$full = count( \IGBZ\Suite\Support\Schema::tables() );

		$missing_table = new HealthEndpoint( $this->db_with( '1' ), fn (): int => $full - 1 );
		$snap          = $missing_table->snapshot();

		$this->assert_same( 200, $snap['status'], 'readiness: the store still serves' );
		$this->assert_same( true, $snap['data']['degraded'], 'but the drift is visible to alarms' );
	}

	/** A Db whose scalar() returns the queued value (the SELECT 1 probe). */
	private function db_with( ?string $select_one ): \IGBZ\Suite\Support\Db {
		$GLOBALS['wpdb'] = new class() extends wpdb {
			public function __construct() {}
		};
		$GLOBALS['wpdb']->next_results = [ $select_one ];

		return new \IGBZ\Suite\Support\Db();
	}
}
