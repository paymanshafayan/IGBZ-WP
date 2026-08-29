<?php
use IGBZ\Suite\Modules\RestApi\Auth\Authenticator;
use IGBZ\Suite\Support\TenantScope;

/**
 * Phase 15: shared memory (cache/transient) and shared rate budgets must be namespaced per
 * tenant. One store's flash data, flow list or loud client can never collide with — or starve —
 * another store.
 */
final class TenantIsolationTest extends TestCase {

	public function run(): void {
		igbz_test_reset_settings();
		$this->seed_tenants();

		$this->cache_keys_cannot_collide_across_tenants();
		$this->control_plane_gets_an_explicit_namespace();
		$this->tenant_rate_cap_stops_a_noisy_neighbour_without_starving_others();
	}

	private function seed_tenants(): void {
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
		$GLOBALS['wpdb']                    = $wpdb;
		$GLOBALS['igbz_test_user_id']       = 5;
		$GLOBALS['igbz_test_capabilities']  = [];
		$GLOBALS['igbz_test_transients']    = [];
	}

	private function cache_keys_cannot_collide_across_tenants(): void {
		igbz()->tenancy()->force( 1 );
		$tenant_one = TenantScope::cache_key( 'igbz_flash_5' );
		igbz()->tenancy()->force( 2 );
		$tenant_two = TenantScope::cache_key( 'igbz_flash_5' );

		$this->assert_same( 't1:igbz_flash_5', $tenant_one, 'logical keys are namespaced with the resolved tenant' );
		$this->assert_same( 't2:igbz_flash_5', $tenant_two, 'the same logical key resolves differently per tenant' );
		$this->assert_true( $tenant_one !== $tenant_two, 'two stores can never sit on one physical cache key' );
	}

	private function control_plane_gets_an_explicit_namespace(): void {
		igbz()->tenancy()->force( null );
		$this->assert_same( 't0:igbz_hub_vip_5', TenantScope::cache_key( 'igbz_hub_vip_5' ), 'tenant-less code lands in t0, never in tenant one' );
	}

	private function tenant_rate_cap_stops_a_noisy_neighbour_without_starving_others(): void {
		igbz()->settings()->set( 'api.rate_limit_per_minute', 3 );
		igbz()->settings()->set( 'api.tenant_rate_limit_per_minute', 4 );
		$GLOBALS['igbz_test_transients'] = [];

		$auth   = ( new ReflectionClass( Authenticator::class ) )->newInstanceWithoutConstructor();
	$method = new ReflectionMethod( Authenticator::class, 'within_rate_limit' );
	$allow = fn ( string $jti, int $tenant ): bool => $method->invoke( $auth, $jti, $tenant );

		// Tenant one floods with two devices.
		$this->assert_true( $allow( 'device-a', 1 ), 'tenant 1 device A request 1' );
		$this->assert_true( $allow( 'device-a', 1 ), 'tenant 1 device A request 2' );
		$this->assert_true( $allow( 'device-a', 1 ), 'tenant 1 device A request 3' );
		$this->assert_false( $allow( 'device-a', 1 ), 'device A then hits its own per-token budget' );

		// The per-tenant cap catches the second device even though its token budget is fresh.
		$this->assert_true( $allow( 'device-b', 1 ), 'tenant 1 device B request 1 lifts the tenant bucket to its cap' );
		$this->assert_false( $allow( 'device-b', 1 ), 'tenant 1 is now capped as a whole' );

		// Tenant two stayed completely untouched by tenant one's flood.
		$this->assert_true( $allow( 'device-c', 2 ), 'tenant 2 keeps its full budget while tenant 1 is capped' );
	}
}
