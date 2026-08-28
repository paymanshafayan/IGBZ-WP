<?php
use IGBZ\Suite\Support\Logger;
use IGBZ\Suite\Support\TenantOffboarding;
use IGBZ\Suite\Support\Db;

/**
 * Phase 13: a deleted tenant takes every tenant-scoped row with it, and the log never carries
 * the PII it was supposed to protect.
 */
final class OffboardingTest extends TestCase {

	public function run(): void {
		$this->offboarding_sweeps_every_scoped_table();
		$this->pii_is_masked_at_ingestion();
	}

	private function offboarding_sweeps_every_scoped_table(): void {
		igbz_test_reset_settings();
		$wpdb          = new wpdb();
		$GLOBALS['wpdb'] = $wpdb;

		$offboarding = new TenantOffboarding( new Db(), new Logger( igbz()->settings() ) );
		$deleted     = $offboarding->purge( 4 );

		$sweeps = array_values( array_filter( $wpdb->queries, static fn ( $q ) => str_starts_with( (string) $q, 'DELETE FROM' ) ) );
		$this->assert_same( count( TenantOffboarding::TABLES ), count( $sweeps ), 'every scoped table is swept' );
		foreach ( $sweeps as $sql ) {
			if ( ! str_contains( (string) $sql, "tenant_id = '4'" ) ) {
				$this->fail( 'a sweep escaped without the tenant condition: ' . $sql );
			}
		}
		$this->assert_true( $deleted >= count( TenantOffboarding::TABLES ), 'deletions are counted' );
		$this->assert_same( 0, $offboarding->purge( 0 ), 'no tenant id, no sweep' );

		// The sweep itself is an auditable security event.
		$audited = false;
		foreach ( $wpdb->writes as $write ) {
			if ( str_ends_with( (string) $write['table'], 'igbz_logs' ) && str_contains( (string) ( $write['data']['channel'] ?? '' ), 'security' ) ) {
				$audited = true;
			}
		}
		$this->assert_true( $audited, 'offboarding is written to the security audit channel' );
	}

	private function pii_is_masked_at_ingestion(): void {
		$redacted = Logger::redact(
			[
				'api_key'     => 'very-secret',
				'phone'       => '+98 912 345 6789',
				'email'       => 'alice@example.com',
				'home_address' => 'Tehran, some street',
				'nested'      => [ 'customer_email' => 'bob@example.com' ],
				'plain'       => 'keep me',
			]
		);

		$this->assert_true( ! str_contains( (string) $redacted['api_key'], 'very-secret' ), 'secrets stay masked' );
		$this->assert_same( '***6789', $redacted['phone'], 'phone keeps only its last four digits' );
		$this->assert_same( 'a***@example.com', $redacted['email'], 'email keeps its first letter and domain' );
		$this->assert_same( '[PII]', $redacted['home_address'], 'addresses are fully replaced' );
		$this->assert_same( 'b***@example.com', $redacted['nested']['customer_email'], 'nested PII is masked too' );
		$this->assert_same( 'keep me', $redacted['plain'], 'ordinary context survives' );
	}
}
