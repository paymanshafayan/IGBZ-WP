<?php
use IGBZ\Suite\Support\Capabilities;
use IGBZ\Suite\Support\TenantScope;

/**
 * Phase 14: a client-supplied tenant id is a request, not an identity. Store owners stay
 * pinned to the tenant resolved from the request; only platform admins may aim a screen
 * elsewhere.
 */
final class TenantResolutionTest extends TestCase {

	public function run(): void {
		igbz_test_reset_settings();
		$this->seed_tenants();

		$this->owner_is_pinned_to_the_resolved_tenant();
		$this->platform_admin_may_aim_elsewhere();
	}

	private function seed_tenants(): void {
		// The plain stub does not persist inserts, so resolve over an in-memory table:
		// every equality pair in the SQL must hold on the row.
		$wpdb = new class() extends wpdb {
			public array $rows = TenantResolutionTest::TENANTS;

			public function get_row( string $sql, $output = null ) {
				$this->queries[] = $sql;
				foreach ( $this->rows as $row ) {
					$ok = true;
					if ( preg_match_all( "/\b([a-z_]+) = '([^']*)'/", $sql, $pairs, PREG_SET_ORDER ) ) {
						foreach ( $pairs as $p ) {
							if ( (string) ( $row[ $p[1] ] ?? '' ) !== $p[2] ) {
								$ok = false;
								break;
							}
						}
					}
					if ( $ok ) {
						return $row;
					}
				}
				return null;
			}
		};
		$GLOBALS['wpdb']                = $wpdb;
		$GLOBALS['igbz_test_user_id']   = 5;
		$GLOBALS['igbz_test_capabilities'] = [];
	}

	public const TENANTS = [
		[ 'id' => 1, 'slug' => 't1', 'name' => 'T1', 'owner_user_id' => 1, 'status' => 'active', 'plan_id' => 0, 'currency' => 'IRT', 'locale' => 'fa' ],
		[ 'id' => 2, 'slug' => 't2', 'name' => 'T2', 'owner_user_id' => 2, 'status' => 'active', 'plan_id' => 0, 'currency' => 'IRT', 'locale' => 'fa' ],
	];

	private function owner_is_pinned_to_the_resolved_tenant(): void {
		igbz()->tenancy()->force( 1 );

		// The owner asks for tenant 99 in the URL; the answer must still be their own tenant.
		$this->assert_same( 1, TenantScope::page_tenant_id( 99 ), 'a tenant owner cannot aim a screen at another tenant' );
		$this->assert_same( 1, TenantScope::page_tenant_id( null ), 'no request means the resolved tenant' );
	}

	private function platform_admin_may_aim_elsewhere(): void {
		igbz()->tenancy()->force( 1 );
		igbz_test_grant( 5, Capabilities::MANAGE_TENANTS );

		$this->assert_same( 2, TenantScope::page_tenant_id( 2 ), 'a platform admin may act on an explicit tenant' );
		$this->assert_same( 1, TenantScope::page_tenant_id( null ), 'without an explicit id the resolved tenant wins' );

		igbz()->tenancy()->force( null );
	}
}
