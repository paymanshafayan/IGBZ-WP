<?php
/**
 * Phase 39 — domain lifecycle: renewal is tenant-scoped and provider-failure-safe, warnings
 * fire once per expiry cycle, the expiry ladder walks active → grace → redemption → released
 * exactly one rung per sweep and deactivates the tenant mapping on release, and provider
 * reconciliation reports drift instead of fixing it silently.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\MultiTenant\Domain\DomainService;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Http;
use IGBZ\Suite\Support\Logger;

/** In-memory engine for domains, journal and tenant mappings. */
final class DomainLifeDb extends wpdb {

	/** @var array<string,array<int,array<string,mixed>>> */
	public array $tables = [
		'ig_domains'        => [],
		'ig_domain_journal' => [],
		'tenant_domains'    => [],
	];

	private int $next_id = 1;

	/** @param array<string,mixed> $row */
	public function seed( string $table, array $row ): int {
		$id                          = $this->next_id++;
		$row['id']                   = $id;
		$this->tables[ $table ][ $id ] = $row;

		return $id;
	}

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'ig_domains' ) && preg_match( "/WHERE id = '?(\d+)'? AND tenant_id = '?(\d+)'?/", $sql, $m ) ) {
			$row = $this->tables['ig_domains'][ (int) $m[1] ] ?? null;
			return null !== $row && (string) $row['tenant_id'] === $m[2] ? $row : null;
		}

		if ( str_contains( $sql, 'tenant_domains' ) && preg_match( "/domain = '([^']*)' AND tenant_id = '?(\d+)'?/", $sql, $m ) ) {
			foreach ( $this->tables['tenant_domains'] as $row ) {
				if ( (string) $row['domain'] === $m[1] && (string) $row['tenant_id'] === $m[2] ) {
					return $row;
				}
			}
			return null;
		}

		return parent::get_row( $sql, $output );
	}

	public function get_results( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'ig_domains' ) ) {
			$out = [];
			foreach ( $this->tables['ig_domains'] as $row ) {
				if ( str_contains( $sql, "status IN ('active','grace','redemption')" ) ) {
					if ( ! in_array( (string) $row['status'], [ 'active', 'grace', 'redemption' ], true ) || null === $row['expires_at'] ) {
						continue;
					}
				} elseif ( str_contains( $sql, "type IN ('registered','transferred')" ) ) {
					if ( ! in_array( (string) $row['type'], [ 'registered', 'transferred' ], true ) || '' === (string) $row['provider_ref'] || 'released' === (string) $row['status'] ) {
						continue;
					}
				} elseif ( str_contains( $sql, 'renewal' ) || ( str_contains( $sql, "status = 'active'" ) && str_contains( $sql, 'expires_at <=' ) ) ) {
					if ( 'active' !== (string) $row['status'] || null === $row['expires_at'] ) {
						continue;
					}
					if ( preg_match( "/expires_at <= '([^']*)'/", $sql, $m ) && strcmp( (string) $row['expires_at'], $m[1] ) > 0 ) {
						continue;
					}
				} else {
					continue;
				}
				$out[] = $row;
			}
			usort( $out, static fn ( $a, $b ): int => (int) $a['id'] <=> (int) $b['id'] );
			return $out;
		}

		if ( str_contains( $sql, 'ig_domain_journal' ) ) {
			return array_values( $this->tables['ig_domain_journal'] );
		}

		return parent::get_results( $sql, $output );
	}

	public function get_var( string $sql ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'COUNT(*)' ) && str_contains( $sql, 'ig_domain_journal' ) ) {
			$count = 0;
			foreach ( $this->tables['ig_domain_journal'] as $row ) {
				if ( preg_match( "/order_id = '?(\d+)'? AND event = '([^']*)' AND detail = '([^']*)'/", $sql, $m )
					&& (string) $row['order_id'] === $m[1] && (string) $row['event'] === $m[2] && (string) $row['detail'] === $m[3] ) {
					++$count;
				}
			}
			return (string) $count;
		}

		return parent::get_var( $sql );
	}

	public function insert( string $table, array $data, $format = null ): int|bool {
		$this->queries[] = 'INSERT INTO ' . $table;
		$this->last_write = [ 'table' => $table, 'data' => $data ];
		$this->writes[]   = $this->last_write;

		foreach ( [ 'ig_domains', 'ig_domain_journal', 'tenant_domains' ] as $name ) {
			if ( str_contains( $table, $name ) ) {
				$this->insert_id = $this->seed( $name, $data );
				return 1;
			}
		}

		return parent::insert( $table, $data, $format );
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		$this->queries[] = 'UPDATE ' . $table;

		foreach ( [ 'ig_domains', 'ig_domain_journal', 'tenant_domains' ] as $name ) {
			if ( ! str_contains( $table, $name ) ) {
				continue;
			}
			$changed = 0;
			foreach ( $this->tables[ $name ] as $id => $row ) {
				$hit = true;
				foreach ( $where as $column => $value ) {
					if ( null === $value ) {
						if ( null !== ( $row[ $column ] ?? null ) ) {
							$hit = false;
							break;
						}
						continue;
					}
					if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) {
						$hit = false;
						break;
					}
				}
				if ( $hit ) {
					$this->tables[ $name ][ $id ] = array_merge( $row, $data );
					++$changed;
				}
			}
			return $changed;
		}

		return parent::update( $table, $data, $where, $format, $where_format );
	}
}

final class DomainLifecycleTest extends TestCase {

	private Db $db;
	private DomainLifeDb $ddb;
	private DomainService $service;

	private function boot(): void {
		igbz_test_reset_settings();
		$GLOBALS['igbz_test_http'] = [];

		$this->ddb         = new DomainLifeDb();
		$GLOBALS['wpdb']   = $this->ddb;

		$this->db = new Db();
		$ref = new ReflectionProperty( Db::class, 'is_sqlite' );
		$ref->setValue( $this->db, true );

		$settings      = igbz()->settings();
		$this->service = new DomainService( $this->db, new Http( new Logger( $settings ) ), new Logger( $settings ) );
	}

	private function domain( string $status, string $expires, array $extra = [] ): array {
		$id = $this->ddb->seed( 'ig_domains', array_merge( [
			'tenant_id'    => 7,
			'name'         => 'shop.example.ir',
			'type'         => 'registered',
			'status'       => $status,
			'provider_ref' => '',
			'dns_verified' => 1,
			'auto_renew'   => 0,
			'expires_at'   => $expires,
			'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'   => gmdate( 'Y-m-d H:i:s' ),
		], $extra ) );

		return $this->ddb->tables['ig_domains'][ $id ];
	}

	public function run(): void {
		$this->test_renew_is_tenant_scoped_and_extends_from_the_later_date();
		$this->test_a_provider_failure_never_extends_the_local_bookkeeping();
		$this->test_renewal_warnings_fire_once_per_expiry_cycle();
		$this->test_the_expiry_ladder_walks_one_rung_per_sweep();
		$this->test_reconciliation_reports_drift_without_fixing_it();
	}

	public function test_renew_is_tenant_scoped_and_extends_from_the_later_date(): void {
		$this->boot();
		$future = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
		$mine   = $this->domain( 'active', $future );
		$this->domain( 'active', $future, [ 'tenant_id' => 8 ] );

		$stolen = $this->service->renew( 7, 2 );
		$this->assert_false( $stolen['ok'], 'another tenant\'s domain is invisible' );
		$this->assert_same( 'domain_not_found', $stolen['error'], 'the refusal names the gate' );

		$result = $this->service->renew( 7, (int) $mine['id'], 1 );
		$this->assert_true( $result['ok'], 'the owner renews' );
		$expected = gmdate( 'Y-m-d H:i:s', strtotime( $future . ' UTC' ) + YEAR_IN_SECONDS );
		$this->assert_same( $expected, $result['expires_at'], 'the extension starts from the current expiry, not today' );

		$dead = $this->domain( 'released', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );
		$this->assert_false( $this->service->renew( 7, (int) $dead['id'] )['ok'], 'a released domain cannot be renewed' );

		$events = array_column( $this->ddb->tables['ig_domain_journal'], 'event' );
		$this->assert_true( in_array( 'renewed', $events, true ), 'the renewal is journaled' );
	}

	public function test_a_provider_failure_never_extends_the_local_bookkeeping(): void {
		$this->boot();
		igbz()->settings()->set( 'domain.provider_api_key', 'key' );
		igbz()->settings()->set( 'domain.provider_base_url', 'https://registrar.test' );
		$GLOBALS['igbz_test_http'][] = [ 'match' => '/renew', 'status' => 500, 'body' => '{"error":"registry_down"}' ];

		$row    = $this->domain( 'active', gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS ), [ 'provider_ref' => 'r1' ] );
		$result = $this->service->renew( 7, (int) $row['id'] );

		$this->assert_false( $result['ok'], 'the provider failure propagates' );
		$this->assert_same( 'active', $this->ddb->tables['ig_domains'][ (int) $row['id'] ]['status'], 'the row is untouched' );
		$this->assert_same( $row['expires_at'], $this->ddb->tables['ig_domains'][ (int) $row['id'] ]['expires_at'], 'nothing was extended' );
	}

	public function test_renewal_warnings_fire_once_per_expiry_cycle(): void {
		$this->boot();
		$soon   = $this->domain( 'active', gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ) );
		$far    = $this->domain( 'active', gmdate( 'Y-m-d H:i:s', time() + 60 * DAY_IN_SECONDS ) );
		$past   = $this->domain( 'active', gmdate( 'Y-m-d H:i:s', time() - 60 ) );

		$first = $this->service->notify_renewals();
		$this->assert_same( [ (int) $soon['id'] ], $first, 'only the windowed domain is warned' );

		$second = $this->service->notify_renewals();
		$this->assert_same( [], $second, 'the same cycle warns nothing twice' );

		$this->service->renew( 7, (int) $soon['id'], 1 );
		$third = $this->service->notify_renewals();
		$this->assert_false( in_array( (int) $soon['id'], $third, true ), 'a fresh cycle starts clean' );
		$this->assert_false( in_array( (int) $far['id'], $third, true ), 'far expiries stay quiet' );
		$this->assert_false( in_array( (int) $past['id'], $third, true ), 'expired domains belong to the sweep' );
	}

	public function test_the_expiry_ladder_walks_one_rung_per_sweep(): void {
		$this->boot();
		igbz()->settings()->set( 'domain.grace_days', '0' );
		igbz()->settings()->set( 'domain.redemption_days', '1' );

		$expired = gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS );
		$row     = $this->domain( 'active', $expired );
		$this->ddb->seed( 'tenant_domains', [ 'tenant_id' => 7, 'domain' => 'shop.example.ir', 'verified_at' => gmdate( 'Y-m-d H:i:s' ) ] );

		$one = $this->service->run_expiry_sweep();
		$this->assert_same( 1, $one['grace'], 'expired active enters grace' );
		$this->assert_same( 'grace', $this->ddb->tables['ig_domains'][ (int) $row['id'] ]['status'], 'one rung per sweep' );

		$two = $this->service->run_expiry_sweep();
		$this->assert_same( 1, $two['redemption'], 'grace past its days redeems' );

		$three = $this->service->run_expiry_sweep();
		$this->assert_same( 1, $three['released'], 'redemption past its days releases' );
		$this->assert_same( 'released', $this->ddb->tables['ig_domains'][ (int) $row['id'] ]['status'], 'the ladder ends at released' );
		$this->assert_same( null, $this->ddb->tables['tenant_domains'][1]['verified_at'], 'release deactivates the tenant mapping' );

		$four = $this->service->run_expiry_sweep();
		$this->assert_same( [ 'grace' => 0, 'redemption' => 0, 'released' => 0 ], $four, 'a re-run is inert' );

		$events = array_column( $this->ddb->tables['ig_domain_journal'], 'event' );
		foreach ( [ 'grace', 'redemption', 'released' ] as $expected ) {
			$this->assert_true( in_array( $expected, $events, true ), "the {$expected} rung is journaled" );
		}
	}

	public function test_reconciliation_reports_drift_without_fixing_it(): void {
		$this->boot();
		igbz()->settings()->set( 'domain.provider_api_key', 'key' );
		igbz()->settings()->set( 'domain.provider_base_url', 'https://registrar.test' );

		$local = gmdate( 'Y-m-d H:i:s', time() + 100 * DAY_IN_SECONDS );
		$row   = $this->domain( 'active', $local, [ 'provider_ref' => 'r1' ] );
		$GLOBALS['igbz_test_http'][] = [
			'match'  => '/v1/domains/r1',
			'status' => 200,
			'body'   => wp_json_encode( [ 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 400 * DAY_IN_SECONDS ) ] ),
		];

		$out = $this->service->reconcile();
		$this->assert_same( 1, $out['scanned'], 'the registered domain is checked' );
		$this->assert_same( 1, count( $out['mismatches'] ), 'the drift is reported' );
		$this->assert_same( $local, $this->ddb->tables['ig_domains'][ (int) $row['id'] ]['expires_at'], 'the local row is NOT silently fixed' );
		$this->assert_true(
			in_array( 'reconcile_mismatch', array_column( $this->ddb->tables['ig_domain_journal'], 'event' ), true ),
			'the mismatch is journaled'
		);
	}
}
