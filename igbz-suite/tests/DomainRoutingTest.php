<?php
use IGBZ\Suite\Modules\MultiTenant\Repository\TenantRepository;
use IGBZ\Suite\Support\Db;

/**
 * Persisting double for the routing path. The SELECT doubles mirror the SQL semantics the
 * repository emits: the domain lookup honours verified_at and tenant status, the slug lookup
 * is unfiltered (the resolver decides routability), and the writes persist.
 */
final class DomainRoutingDb extends wpdb {

	/** @var array<int,array<string,mixed>> */
	public array $tenants = [];
	/** @var array<int,array<string,mixed>> */
	public array $tenant_domains = [];

	private int $next_id = 50;

	public function seed_tenant( array $row ): int {
		$id                   = (int) ( $row['id'] ?? $this->next_id++ );
		$row['id']            = $id;
		$this->tenants[ $id ] = $row + [ 'status' => 'active', 'plan_id' => 0, 'currency' => 'IRT', 'locale' => 'fa', 'trial_ends_at' => null ];
		return $id;
	}

	public function seed_domain( int $tenant_id, string $domain, bool $verified = true ): int {
		$id                              = $this->next_id++;
		$this->tenant_domains[ $id ]    = [
			'id'                 => $id,
			'tenant_id'          => $tenant_id,
			'domain'             => $domain,
			'is_primary'         => 0,
			'verification_token' => 'tok',
			'verified_at'        => $verified ? '2026-01-01 00:00:00' : null,
		];
		return $id;
	}

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'INNER JOIN' ) && preg_match( "/d\.domain = '([^']+)'/", $sql, $m ) ) {
			foreach ( $this->tenant_domains as $d ) {
				if ( $d['domain'] === $m[1] && null !== $d['verified_at'] ) {
					$t = $this->tenants[ $d['tenant_id'] ] ?? null;
					if ( $t && in_array( (string) $t['status'], [ 'active', 'trial' ], true ) ) {
						return $t;
					}
				}
			}
			return null;
		}

		if ( preg_match( "/WHERE slug = '([^']+)'/", $sql, $m ) ) {
			foreach ( $this->tenants as $t ) {
				if ( $t['slug'] === $m[1] ) {
					return $t;
				}
			}
			return null;
		}

		if ( preg_match( '/WHERE id = (\d+)/', $sql, $m ) ) {
			return $this->tenants[ (int) $m[1] ] ?? null;
		}

		return null;
	}

	public function get_results( string $sql, $output = null ) {
		$this->queries[] = $sql;
		return [];
	}

	public function get_var( string $sql ) {
		$this->queries[] = $sql;

		if ( preg_match( "/FROM \w*igbz_tenant_domains WHERE domain = '([^']+)'/", $sql, $m ) ) {
			foreach ( $this->tenant_domains as $d ) {
				if ( $d['domain'] === $m[1] ) {
					return str_contains( $sql, 'SELECT tenant_id' ) ? (string) $d['tenant_id'] : (string) $d['id'];
				}
			}
			return null;
		}

		return null;
	}

	public function insert( string $table, array $data, $format = null ): int|bool {
		if ( ! str_contains( $table, 'tenant_domains' ) ) {
			return parent::insert( $table, $data, $format );
		}
		$id                             = $this->next_id++;
		$this->insert_id                = $id;
		$data['id']                     = $id;
		$this->tenant_domains[ $id ]    = $data + [ 'verified_at' => null, 'is_primary' => 0 ];
		return 1;
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		if ( ! str_contains( $table, 'tenant_domains' ) ) {
			return parent::update( $table, $data, $where, $format, $where_format );
		}
		$changed = 0;
		foreach ( $this->tenant_domains as $id => $row ) {
			foreach ( $where as $column => $value ) {
				if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) {
					continue 2;
				}
			}
			$this->tenant_domains[ $id ] = array_merge( $row, $data );
			++$changed;
		}
		return $changed;
	}

	public function delete( string $table, array $where, $where_format = null ): int|bool {
		if ( ! str_contains( $table, 'tenant_domains' ) ) {
			return parent::delete( $table, $where, $where_format );
		}
		$removed = 0;
		foreach ( $this->tenant_domains as $id => $row ) {
			foreach ( $where as $column => $value ) {
				if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) {
					continue 2;
				}
			}
			unset( $this->tenant_domains[ $id ] );
			++$removed;
		}
		return $removed;
	}

	public function query( string $sql ): int|bool {
		$this->queries[] = $sql;
		return 1;
	}
}

/**
 * Phase 17: a visitor's request resolves to a tenant only through a verified domain or an
 * active slug — never to a suspended/expired store — and the domain table stays a strict
 * 1:1 mapping with sanitized input, so no crafted host or duplicate claim can route to the
 * wrong store.
 */
final class DomainRoutingTest extends TestCase {

	public function run(): void {
		igbz_test_reset_settings();
		$GLOBALS['igbz_test_user_id'] = 0;

		$this->suspended_store_stops_answering_on_its_path();
		$this->expired_trial_stops_answering_on_its_domain();
		$this->unverified_domain_does_not_route();
		$this->a_domain_maps_to_exactly_one_tenant();
		$this->domain_input_is_normalized_or_rejected();
	}

	private function db(): DomainRoutingDb {
		$db              = new DomainRoutingDb();
		$GLOBALS['wpdb'] = $db;
		unset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
		igbz()->tenancy()->force( null );
		return $db;
	}

	private function suspended_store_stops_answering_on_its_path(): void {
		$db = $this->db();
		$db->seed_tenant( [ 'id' => 1, 'slug' => 'live', 'name' => 'Live', 'owner_user_id' => 1, 'status' => 'active' ] );
		$db->seed_tenant( [ 'id' => 2, 'slug' => 'dead', 'name' => 'Dead', 'owner_user_id' => 2, 'status' => 'suspended' ] );
		igbz()->settings()->set( 'general.tenant_resolution', 'path' );

		$_SERVER['REQUEST_URI'] = '/store/live/';
		igbz()->tenancy()->force( null );
		$this->assert_same( 1, igbz()->tenancy()->id(), 'an active store resolves on its path' );

		$_SERVER['REQUEST_URI'] = '/store/dead/';
		igbz()->tenancy()->force( null );
		$this->assert_same( 0, igbz()->tenancy()->id(), 'a suspended store no longer resolves — serving stops the moment status flips' );
	}

	private function expired_trial_stops_answering_on_its_domain(): void {
		$db = $this->db();
		$db->seed_tenant( [ 'id' => 3, 'slug' => 'trial', 'name' => 'Trial', 'owner_user_id' => 3, 'status' => 'trial', 'trial_ends_at' => '2020-01-01 00:00:00' ] );
		$db->seed_domain( 3, 'trial.example.test', true );
		igbz()->settings()->set( 'general.tenant_resolution', 'domain' );

		$_SERVER['HTTP_HOST'] = 'trial.example.test';
		igbz()->tenancy()->force( null );
		$this->assert_same( 0, igbz()->tenancy()->id(), 'a verified domain still does not serve an expired trial' );

		$db->tenants[3]['trial_ends_at'] = null;
		igbz()->tenancy()->force( null );
		$this->assert_same( 3, igbz()->tenancy()->id(), 'the same domain serves once the trial is healthy again' );
	}

	private function unverified_domain_does_not_route(): void {
		$db = $this->db();
		$db->seed_tenant( [ 'id' => 4, 'slug' => 'claim', 'name' => 'Claim', 'owner_user_id' => 4, 'status' => 'active' ] );
		$db->seed_domain( 4, 'claim.example.test', false );
		igbz()->settings()->set( 'general.tenant_resolution', 'domain' );

		$_SERVER['HTTP_HOST'] = 'claim.example.test';
		igbz()->tenancy()->force( null );
		$this->assert_same( 0, igbz()->tenancy()->id(), 'host-header spoofing is useless without a verified record' );
	}

	private function a_domain_maps_to_exactly_one_tenant(): void {
		$db   = $this->db();
		$db->seed_tenant( [ 'id' => 5, 'slug' => 'a', 'name' => 'A', 'owner_user_id' => 5 ] );
		$db->seed_tenant( [ 'id' => 6, 'slug' => 'b', 'name' => 'B', 'owner_user_id' => 6 ] );
		$repo = new TenantRepository( new Db() );

		$first  = $repo->add_domain( 5, 'shared.example.test' );
		$second = $repo->add_domain( 6, 'shared.example.test' );
		$again  = $repo->add_domain( 5, 'shared.example.test' );

		$this->assert_true( $first > 0, 'the first tenant claims the domain' );
		$this->assert_same( 0, $second, 'a second tenant cannot claim the same domain' );
		$this->assert_same( $first, $again, 're-adding for the same tenant is idempotent' );
	}

	private function domain_input_is_normalized_or_rejected(): void {
		$db   = $this->db();
		$db->seed_tenant( [ 'id' => 7, 'slug' => 'c', 'name' => 'C', 'owner_user_id' => 7 ] );
		$repo = new TenantRepository( new Db() );

		$id = $repo->add_domain( 7, 'https://Shop.Example.com/some/path' );
		$this->assert_true( $id > 0, 'a URL-shaped input is accepted after normalization' );
		$stored = array_values( array_filter( $db->tenant_domains, static fn ( $d ): bool => (int) $d['id'] === $id ) );
		$this->assert_same( 'shop.example.com', (string) $stored[0]['domain'], 'only the lowercase host is stored' );

		$this->assert_same( 0, $repo->add_domain( 7, 'bad name!' ), 'hostnames with spaces or punctuation are rejected' );
		$this->assert_same( 0, $repo->add_domain( 7, '' ), 'empty input is rejected' );
	}
}
