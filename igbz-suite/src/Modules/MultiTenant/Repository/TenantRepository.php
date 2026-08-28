<?php
namespace IGBZ\Suite\Modules\MultiTenant\Repository;

use IGBZ\Suite\Support\Db;

defined( 'ABSPATH' ) || exit;

final class TenantRepository {

	public function __construct( private Db $db ) {}

	public function find( int $id ): ?Tenant {
		if ( $id <= 0 ) {
			return null;
		}
		$cached = wp_cache_get( 'tenant_' . $id, 'igbz' );
		if ( is_array( $cached ) ) {
			return Tenant::from_row( $cached );
		}
		$row = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'tenants' ) . ' WHERE id = %d', $id );
		if ( ! $row ) {
			return null;
		}
		wp_cache_set( 'tenant_' . $id, $row, 'igbz', 300 );
		return Tenant::from_row( $row );
	}

	public function find_by_slug( string $slug ): ?Tenant {
		$row = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'tenants' ) . ' WHERE slug = %s', $slug );
		return $row ? Tenant::from_row( $row ) : null;
	}

	public function find_by_domain( string $domain ): ?Tenant {
		$domains = $this->db->table( 'tenant_domains' );
		$tenants = $this->db->table( 'tenants' );
		$row     = $this->db->row(
			"SELECT t.* FROM {$tenants} t INNER JOIN {$domains} d ON d.tenant_id = t.id WHERE d.domain = %s AND d.verified_at IS NOT NULL AND t.status IN (%s, %s) LIMIT 1",
			strtolower( $domain ),
			Tenant::STATUS_ACTIVE,
			Tenant::STATUS_TRIAL
		);
		return $row ? Tenant::from_row( $row ) : null;
	}

	public function find_primary_for_user( int $user_id ): ?Tenant {
		if ( $user_id <= 0 ) {
			return null;
		}
		$tenants = $this->db->table( 'tenants' );
		$members = $this->db->table( 'tenant_members' );
		$row     = $this->db->row(
			"SELECT t.* FROM {$tenants} t WHERE t.owner_user_id = %d
			 UNION SELECT t2.* FROM {$tenants} t2 INNER JOIN {$members} m ON m.tenant_id = t2.id WHERE m.user_id = %d
			 LIMIT 1",
			$user_id,
			$user_id
		);
		return $row ? Tenant::from_row( $row ) : null;
	}

	/** @return int[] */
	public function tenant_ids_for_user( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return [];
		}
		$tenants = $this->db->table( 'tenants' );
		$members = $this->db->table( 'tenant_members' );
		return array_map(
			'intval',
			$this->db->column(
				"SELECT id FROM {$tenants} WHERE owner_user_id = %d
				 UNION SELECT tenant_id FROM {$members} WHERE user_id = %d",
				$user_id,
				$user_id
			)
		);
	}

	public function user_belongs_to( int $user_id, int $tenant_id ): bool {
		return in_array( $tenant_id, $this->tenant_ids_for_user( $user_id ), true );
	}

	/**
	 * @param array{status?:string,search?:string,limit?:int,offset?:int,plan_id?:int} $args
	 * @return Tenant[]
	 */
	public function all( array $args = [] ): array {
		$where  = [ '1=1' ];
		$params = [];
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['plan_id'] ) ) {
			$where[]  = 'plan_id = %d';
			$params[] = (int) $args['plan_id'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $this->db->wpdb()->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(name LIKE %s OR slug LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}
		$params[] = (int) ( $args['limit'] ?? 50 );
		$params[] = (int) ( $args['offset'] ?? 0 );

		$rows = $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'tenants' ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d',
			...$params
		);
		return array_map( [ Tenant::class, 'from_row' ], $rows );
	}

	public function count( array $args = [] ): int {
		$where  = [ '1=1' ];
		$params = [];
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		return (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'tenants' ) . ' WHERE ' . implode( ' AND ', $where ),
			...$params
		);
	}

	/** @param array<string,mixed> $data */
	public function create( array $data ): int {
		$now  = current_time( 'mysql', true );
		$slug = $this->unique_slug( (string) ( $data['slug'] ?? $data['name'] ?? 'store' ) );

		$id = $this->db->insert(
			'tenants',
			[
				'slug'          => $slug,
				'name'          => (string) ( $data['name'] ?? $slug ),
				'owner_user_id' => (int) ( $data['owner_user_id'] ?? 0 ),
				'status'        => (string) ( $data['status'] ?? Tenant::STATUS_PENDING ),
				'plan_id'       => (int) ( $data['plan_id'] ?? 0 ),
				'theme'         => (string) ( $data['theme'] ?? '' ),
				'logo_url'      => esc_url_raw( (string) ( $data['logo_url'] ?? '' ) ),
				'primary_color' => (string) ( $data['primary_color'] ?? '' ),
				'currency'      => (string) ( $data['currency'] ?? 'IRT' ),
				'locale'        => (string) ( $data['locale'] ?? 'fa_IR' ),
				'settings'      => wp_json_encode( (array) ( $data['settings'] ?? [] ) ),
				'trial_ends_at' => $data['trial_ends_at'] ?? null,
				'created_at'    => $now,
				'updated_at'    => $now,
			]
		);

		if ( $id > 0 ) {
			do_action( 'igbz_tenant_created', $id, $data );
		}
		return $id;
	}

	/** @param array<string,mixed> $data */
	public function update( int $id, array $data ): bool {
		$allowed = [ 'name', 'status', 'plan_id', 'theme', 'logo_url', 'primary_color', 'currency', 'locale', 'trial_ends_at', 'owner_user_id' ];
		$payload = array_intersect_key( $data, array_flip( $allowed ) );
		if ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
			$payload['settings'] = wp_json_encode( $data['settings'] );
		}
		if ( ! $payload ) {
			return false;
		}
		$payload['updated_at'] = current_time( 'mysql', true );
		$ok = $this->db->update( 'tenants', $payload, [ 'id' => $id ] ) >= 0;
		wp_cache_delete( 'tenant_' . $id, 'igbz' );
		do_action( 'igbz_tenant_updated', $id, $payload );
		return $ok;
	}

	public function set_status( int $id, string $status ): bool {
		return $this->update( $id, [ 'status' => $status ] );
	}

	public function delete( int $id ): bool {
		wp_cache_delete( 'tenant_' . $id, 'igbz' );
		do_action( 'igbz_tenant_deleted', $id );
		return $this->db->delete( 'tenants', [ 'id' => $id ] ) > 0;
	}

	public function unique_slug( string $base ): string {
		$slug = sanitize_title( $base );
		if ( '' === $slug ) {
			$slug = 'store';
		}
		$candidate = $slug;
		$i         = 1;
		while ( null !== $this->db->scalar( 'SELECT id FROM ' . $this->db->table( 'tenants' ) . ' WHERE slug = %s', $candidate ) ) {
			$candidate = $slug . '-' . ( ++$i );
		}
		return $candidate;
	}

	// -------------------------------------------------------------- members

	public function add_member( int $tenant_id, int $user_id, string $role = 'staff' ): bool {
		$existing = $this->db->scalar(
			'SELECT id FROM ' . $this->db->table( 'tenant_members' ) . ' WHERE tenant_id = %d AND user_id = %d',
			$tenant_id,
			$user_id
		);
		if ( $existing ) {
			return $this->db->update( 'tenant_members', [ 'role' => $role ], [ 'id' => (int) $existing ] ) >= 0;
		}
		return $this->db->insert(
			'tenant_members',
			[
				'tenant_id'  => $tenant_id,
				'user_id'    => $user_id,
				'role'       => $role,
				'created_at' => current_time( 'mysql', true ),
			]
		) > 0;
	}

	public function remove_member( int $tenant_id, int $user_id ): bool {
		return $this->db->delete( 'tenant_members', [ 'tenant_id' => $tenant_id, 'user_id' => $user_id ] ) > 0;
	}

	/** @return array<int,array<string,mixed>> */
	public function members( int $tenant_id ): array {
		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'tenant_members' ) . ' WHERE tenant_id = %d ORDER BY id',
			$tenant_id
		);
	}

	// -------------------------------------------------------------- domains

	public function add_domain( int $tenant_id, string $domain, bool $primary = false ): int {
		// Phase 17: keep only the host part of whatever was typed, lowercase it, and accept a
		// hostname shape only — anything else is input we refuse to store, so a crafted
		// string can never enter the routing table.
		$domain = strtolower( trim( (string) preg_replace( '#^https?://#', '', $domain ), '/' ) );
		$domain = (string) preg_replace( '#/.*$#', '', $domain );
		if ( '' === $domain || strlen( $domain ) > 190 || ! preg_match( '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $domain ) ) {
			return 0;
		}

		// Phase 17: one domain maps to exactly one tenant. A second holder would make the
		// resolver's LIMIT 1 choice non-deterministic and could serve the wrong store.
		$holder = $this->db->scalar(
			'SELECT tenant_id FROM ' . $this->db->table( 'tenant_domains' ) . ' WHERE domain = %s LIMIT 1',
			$domain
		);
		if ( $holder && (int) $holder !== $tenant_id ) {
			return 0;
		}
		if ( $holder ) {
			$existing = $this->db->scalar(
				'SELECT id FROM ' . $this->db->table( 'tenant_domains' ) . ' WHERE domain = %s AND tenant_id = %d LIMIT 1',
				$domain,
				$tenant_id
			);
			return (int) $existing;
		}

		if ( $primary ) {
			$this->db->query( 'UPDATE ' . $this->db->table( 'tenant_domains' ) . ' SET is_primary = 0 WHERE tenant_id = %d', $tenant_id );
		}
		return $this->db->insert(
			'tenant_domains',
			[
				'tenant_id'          => $tenant_id,
				'domain'             => $domain,
				'is_primary'         => $primary ? 1 : 0,
				'verification_token' => \IGBZ\Suite\Support\Crypto::token( 16 ),
				'created_at'         => current_time( 'mysql', true ),
			]
		);
	}

	public function verify_domain( int $domain_id ): bool {
		return $this->db->update( 'tenant_domains', [ 'verified_at' => current_time( 'mysql', true ) ], [ 'id' => $domain_id ] ) >= 0;
	}

	public function delete_domain( int $domain_id ): bool {
		return $this->db->delete( 'tenant_domains', [ 'id' => $domain_id ] ) > 0;
	}

	/** @return array<int,array<string,mixed>> */
	public function domains( int $tenant_id ): array {
		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'tenant_domains' ) . ' WHERE tenant_id = %d ORDER BY is_primary DESC, id',
			$tenant_id
		);
	}

	public function primary_domain( int $tenant_id ): string {
		$row = $this->db->row(
			'SELECT domain FROM ' . $this->db->table( 'tenant_domains' ) . ' WHERE tenant_id = %d AND verified_at IS NOT NULL ORDER BY is_primary DESC, id LIMIT 1',
			$tenant_id
		);
		return $row ? (string) $row['domain'] : '';
	}
}
