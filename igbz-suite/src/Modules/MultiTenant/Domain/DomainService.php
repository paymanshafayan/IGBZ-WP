<?php
namespace IGBZ\Suite\Modules\MultiTenant\Domain;

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Http;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Domain service: search/register/transfer domains through a reference
 * provider API (IRNIC resellers for .ir, ICANN registrars for international).
 * Also manages the mother-site subdomain default and DNS verification that
 * gates the bank gateway and Google/Bing registration.
 */
final class DomainService {

	public function __construct(
		private Db $db,
		private Http $http,
		private Logger $logger
	) {}

	public function is_configured(): bool {
		return '' !== igbz()->settings()->string( 'domain.provider_api_key' );
	}

	/** Search availability + price through the provider API. */
	public function search( string $query, string $tld = 'ir' ): array {
		if ( ! $this->is_configured() ) {
			return [ 'ok' => false, 'results' => [], 'error' => __( 'Domain provider is not configured.', 'igbz-suite' ) ];
		}
		$base = rtrim( igbz()->settings()->string( 'domain.provider_base_url' ), '/' );
		$response = $this->http->get(
			$base . '/v1/domains/search?q=' . rawurlencode( $query ) . '&tld=' . rawurlencode( $tld ),
			[ 'headers' => $this->headers(), 'channel' => 'domain', 'timeout' => 25 ]
		);
		$body = $response->json();
		return $response->ok()
			? [ 'ok' => true, 'results' => (array) ( $body['results'] ?? $body['data'] ?? [] ), 'error' => '' ]
			: [ 'ok' => false, 'results' => [], 'error' => $response->error_message() ];
	}

	/** Register a domain. Returns domain row id. */
	public function register( int $tenant_id, string $name, string $tld = 'ir', int $years = 1 ): array {
		if ( ! $this->is_configured() ) {
			return [ 'ok' => false, 'domain_id' => 0, 'error' => __( 'Domain provider is not configured.', 'igbz-suite' ) ];
		}
		$search = $this->search( $name, $tld );
		if ( ! $search['ok'] ) {
			return [ 'ok' => false, 'domain_id' => 0, 'error' => $search['error'] ];
		}
		$price = 0.0;
		foreach ( $search['results'] as $r ) {
			if ( ( $r['tld'] ?? $tld ) === $tld && ( $r['available'] ?? false ) ) {
				$price = (float) ( $r['price_irt'] ?? 0 );
				break;
			}
		}
		if ( $price <= 0 ) {
			return [ 'ok' => false, 'domain_id' => 0, 'error' => __( 'Domain is not available.', 'igbz-suite' ) ];
		}

		$base = rtrim( igbz()->settings()->string( 'domain.provider_base_url' ), '/' );
		$response = $this->http->post(
			$base . '/v1/domains/register',
			[
				'json'    => [ 'name' => $name, 'tld' => $tld, 'years' => $years, 'api_key' => igbz()->settings()->string( 'domain.provider_api_key' ) ],
				'headers' => $this->headers(),
				'channel' => 'domain',
				'timeout' => 60,
			]
		);
		$body = $response->json();
		if ( ! $response->ok() ) {
			return [ 'ok' => false, 'domain_id' => 0, 'error' => $response->error_message() ];
		}

		$now = current_time( 'mysql', true );
		$id  = (int) $this->db->insert(
			'ig_domains',
			[
				'tenant_id'    => $tenant_id,
				'name'         => $name . '.' . $tld,
				'type'         => 'registered',
				'status'       => 'pending',
				'provider_ref' => (string) ( $body['id'] ?? '' ),
				'expires_at'   => gmdate( 'Y-m-d H:i:s', time() + $years * YEAR_IN_SECONDS ),
				'created_at'   => $now,
				'updated_at'   => $now,
			]
		);
		$this->db->insert(
			'ig_domain_orders',
			[
				'tenant_id'    => $tenant_id,
				'domain_id'    => $id,
				'action'       => 'register',
				'amount'       => $price,
				'status'       => 'pending',
				'provider_ref' => (string) ( $body['id'] ?? '' ),
				'created_at'   => $now,
			]
		);
		$this->sync_tenant_domain( $tenant_id, $name . '.' . $tld, false );

		return [ 'ok' => true, 'domain_id' => $id, 'error' => '' ];
	}

	/** Set the mother-site subdomain (default, free). */
	public function use_subdomain( int $tenant_id, string $slug ): array {
		$name = $slug . '.' . igbz()->settings()->string( 'domain.mother_subdomain', 'igbz.ir' );
		$now  = current_time( 'mysql', true );
		$id   = (int) $this->db->insert(
			'ig_domains',
			[
				'tenant_id'    => $tenant_id,
				'name'         => $name,
				'type'         => 'subdomain',
				'status'       => 'active',
				'dns_verified' => 1,
				'created_at'   => $now,
				'updated_at'   => $now,
			]
		);
		// The tenant resolver reads the canonical tenant_domains mapping; keep it in sync with
		// the domain-service registry so a newly provisioned store is routable immediately.
		$mapping_id = igbz()->get( 'tenants' )->add_domain( $tenant_id, $name, true );
		if ( $mapping_id > 0 ) {
			igbz()->get( 'tenants' )->verify_domain( $mapping_id );
		}
		return [ 'ok' => $id > 0 && $mapping_id > 0, 'domain_id' => $id, 'error' => $id > 0 && $mapping_id > 0 ? '' : __( 'The subdomain could not be mapped.', 'igbz-suite' ) ];
	}

	/** Transfer an existing domain the admin already owns (auth code optional). */
	public function transfer( int $tenant_id, string $name, string $auth_code = '' ): array {
		if ( ! $this->is_configured() ) {
			return [ 'ok' => false, 'domain_id' => 0, 'error' => __( 'Domain provider is not configured.', 'igbz-suite' ) ];
		}
		$base = rtrim( igbz()->settings()->string( 'domain.provider_base_url' ), '/' );
		$response = $this->http->post(
			$base . '/v1/domains/transfer',
			[
				'json'    => [ 'name' => $name, 'auth_code' => $auth_code, 'api_key' => igbz()->settings()->string( 'domain.provider_api_key' ) ],
				'headers' => $this->headers(),
				'channel' => 'domain',
				'timeout' => 60,
			]
		);
		$body = $response->json();
		if ( ! $response->ok() ) {
			return [ 'ok' => false, 'domain_id' => 0, 'error' => $response->error_message() ];
		}
		$now = current_time( 'mysql', true );
		$id  = (int) $this->db->insert(
			'ig_domains',
			[
				'tenant_id'    => $tenant_id,
				'name'         => $name,
				'type'         => 'transferred',
				'status'       => 'pending',
				'provider_ref' => (string) ( $body['id'] ?? '' ),
				'created_at'   => $now,
				'updated_at'   => $now,
			]
		);
		$this->sync_tenant_domain( $tenant_id, $name, false );
		return [ 'ok' => true, 'domain_id' => $id, 'error' => '' ];
	}

	/** Mark DNS verified (gates bank gateway + Google/Bing). */
	public function verify_dns( int $domain_id ): bool {
		$row = $this->db->row( 'SELECT tenant_id, name FROM ' . $this->db->table( 'ig_domains' ) . ' WHERE id = %d', $domain_id );
		if ( ! $row || ! function_exists( 'dns_get_record' ) ) { return false; }
		$name = strtolower( rtrim( (string) $row['name'], '.' ) );
		$mapping = $this->db->row( 'SELECT id, verification_token FROM ' . $this->db->table( 'tenant_domains' ) . ' WHERE tenant_id = %d AND domain = %s LIMIT 1', (int) $row['tenant_id'], $name );
		$expected = strtolower( (string) ( igbz()->settings()->string( 'hub.cname_target', '' ) ?: wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) );
		$valid = false;
		foreach ( (array) @dns_get_record( '_igbz-verify.' . $name, DNS_TXT ) as $record ) {
			if ( isset( $record['txt'] ) && $mapping && hash_equals( (string) $mapping['verification_token'], trim( (string) $record['txt'] ) ) ) { $valid = true; break; }
		}
		if ( ! $valid ) {
			foreach ( (array) @dns_get_record( $name, DNS_CNAME ) as $record ) {
				if ( strtolower( rtrim( (string) ( $record['target'] ?? '' ), '.' ) ) === $expected ) { $valid = true; break; }
			}
		}
		if ( ! $valid ) { $this->logger->warning( 'domain', 'DNS verification refused', [ 'domain_id' => $domain_id ] ); return false; }
		$this->db->update( 'ig_domains', [ 'dns_verified' => 1, 'status' => 'active', 'updated_at' => current_time( 'mysql', true ) ], [ 'id' => $domain_id ] );
		if ( $mapping ) { $this->db->update( 'tenant_domains', [ 'verified_at' => current_time( 'mysql', true ) ], [ 'id' => (int) $mapping['id'] ] ); }
		return true;
	}

	public function has_verified_domain( int $tenant_id ): bool {
		$row = $this->db->row(
			'SELECT id FROM ' . $this->db->table( 'ig_domains' ) . ' WHERE tenant_id = %d AND status = %s AND dns_verified = 1 LIMIT 1',
			$tenant_id,
			'active'
		);
		return null !== $row;
	}

	/** @return array<int,array<string,mixed>> */
	public function domains( int $tenant_id ): array {
		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_domains' ) . ' WHERE tenant_id = %d ORDER BY id DESC',
			$tenant_id
		);
	}

	private function sync_tenant_domain( int $tenant_id, string $domain, bool $verified ): int {
		$domain = strtolower( trim( preg_replace( '#^https?://#', '', $domain ), '/' ) );
		$existing = $this->db->row(
			'SELECT id FROM ' . $this->db->table( 'tenant_domains' ) . ' WHERE domain = %s LIMIT 1',
			$domain
		);
		if ( $existing ) {
			if ( $verified ) {
				$this->db->update( 'tenant_domains', [ 'verified_at' => current_time( 'mysql', true ) ], [ 'id' => (int) $existing['id'] ] );
			}
			return (int) $existing['id'];
		}
		return (int) igbz()->get( 'tenants' )->add_domain( $tenant_id, $domain, false );
	}

	/** @return array<string,string> */
	private function headers(): array {
		return [ 'Authorization' => 'Bearer ' . igbz()->settings()->string( 'domain.provider_api_key' ), 'Accept' => 'application/json' ];
	}
}
