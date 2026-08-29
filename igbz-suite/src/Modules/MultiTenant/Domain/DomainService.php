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

	// Phase 39 lifecycle: active → grace → redemption → released. Pending and registered
	// flows keep their own statuses; the sweep only walks this ladder.
	public const STATUS_ACTIVE     = 'active';
	public const STATUS_GRACE      = 'grace';
	public const STATUS_REDEMPTION = 'redemption';
	public const STATUS_RELEASED   = 'released';

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
	public function verify_dns( int $domain_id, int $tenant_id ): bool {
		$row = $this->db->row( 'SELECT tenant_id, name FROM ' . $this->db->table( 'ig_domains' ) . ' WHERE id = %d AND tenant_id = %d', $domain_id, $tenant_id );
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
		$this->db->update( 'ig_domains', [ 'dns_verified' => 1, 'status' => 'active', 'updated_at' => current_time( 'mysql', true ) ], [ 'id' => $domain_id, 'tenant_id' => $tenant_id ] );
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

	/**
	 * Phase 39 — renew one domain, tenant-scoped. The row must belong to the
	 * caller's tenant (BOLA guard) and must not already be released. When a
	 * provider is configured the renewal goes through it first — a provider
	 * failure means no local extension, because the registry never moved.
	 * Without a provider the operator manages the registry elsewhere and the
	 * local bookkeeping is extended deliberately (manual path, journaled).
	 *
	 * @return array{ok:bool,expires_at:string,error:string}
	 */
	public function renew( int $tenant_id, int $domain_id, int $years = 1, bool $auto = false ): array {
		$years = max( 1, min( 10, $years ) );
		$row   = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'ig_domains' ) . ' WHERE id = %d AND tenant_id = %d',
			$domain_id,
			$tenant_id
		);
		if ( null === $row ) {
			return [ 'ok' => false, 'expires_at' => '', 'error' => 'domain_not_found' ];
		}
		if ( self::STATUS_RELEASED === (string) $row['status'] ) {
			return [ 'ok' => false, 'expires_at' => '', 'error' => 'released' ];
		}

		if ( $this->is_configured() && '' !== (string) $row['provider_ref'] ) {
			$base     = rtrim( igbz()->settings()->string( 'domain.provider_base_url' ), '/' );
			$response = $this->http->post(
				$base . '/v1/domains/' . rawurlencode( (string) $row['provider_ref'] ) . '/renew',
				[
					'json'    => [ 'years' => $years, 'api_key' => igbz()->settings()->string( 'domain.provider_api_key' ) ],
					'headers' => $this->headers(),
					'channel' => 'domain',
					'timeout' => 60,
				]
			);
			if ( ! $response->ok() ) {
				$this->logger->error( 'domain', 'Provider renewal failed, nothing extended', [ 'domain_id' => $domain_id, 'error' => $response->error_message() ] );
				return [ 'ok' => false, 'expires_at' => '', 'error' => $response->error_message() ];
			}
		}

		$now     = time();
		$current = strtotime( (string) ( $row['expires_at'] ?? '' ) . ' UTC' );
		$base    = max( $now, false === $current ? $now : $current );
		$expires = gmdate( 'Y-m-d H:i:s', $base + $years * YEAR_IN_SECONDS );

		$this->db->update(
			'ig_domains',
			[
				'status'     => self::STATUS_ACTIVE,
				'expires_at' => $expires,
				'auto_renew' => $auto ? 1 : (int) ( $row['auto_renew'] ?? 0 ),
				'updated_at' => current_time( 'mysql', true ),
			],
			[ 'id' => $domain_id, 'tenant_id' => $tenant_id ]
		);
		$this->journal( $tenant_id, $domain_id, 'renewed', $expires );
		$this->logger->info( 'domain', 'Domain renewed', [ 'domain_id' => $domain_id, 'tenant_id' => $tenant_id, 'years' => $years, 'expires_at' => $expires ] );

		return [ 'ok' => true, 'expires_at' => $expires, 'error' => '' ];
	}

	/**
	 * Phase 39 — renewal warnings for active domains expiring within
	 * domain.renewal_warning_days (default 14). Idempotent per expiry date:
	 * the journal carries the date, and a second run in the same cycle adds
	 * nothing.
	 *
	 * @return array<int,int> domain ids warned this run
	 */
	public function notify_renewals(): array {
		$window = max( 1, igbz()->settings()->int( 'domain.renewal_warning_days', 14 ) );
		$rows   = $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_domains' ) . '\n\t\t\t WHERE status = %s AND expires_at IS NOT NULL AND expires_at <= %s',
			self::STATUS_ACTIVE,
			gmdate( 'Y-m-d H:i:s', time() + $window * DAY_IN_SECONDS )
		);

		$warned = [];
		foreach ( $rows as $row ) {
			if ( strtotime( (string) $row['expires_at'] . ' UTC' ) < time() ) {
				continue; // Already expired — the expiry sweep owns it now.
			}
			$expires = (string) $row['expires_at'];
			$already = (int) $this->db->scalar(
				'SELECT COUNT(*) FROM ' . $this->db->table( 'ig_domain_journal' ) . '\n\t\t\t\t WHERE order_id = %d AND event = %s AND detail = %s',
				(int) $row['id'],
				'renewal_warning',
				$expires
			);
			if ( $already > 0 ) {
				continue;
			}
			$this->journal( (int) $row['tenant_id'], (int) $row['id'], 'renewal_warning', $expires );
			$this->logger->info( 'domain', 'Renewal warning sent', [ 'domain_id' => (int) $row['id'], 'tenant_id' => (int) $row['tenant_id'], 'expires_at' => $expires ] );
			$warned[] = (int) $row['id'];
		}

		return $warned;
	}

	/**
	 * Phase 39 — the expiry ladder, walked once and only forward:
	 * expired active → grace → redemption → released. Each rung is journaled;
	 * release also drops the tenant mapping's verification so a dead domain
	 * stops gating anything. Transitions are status-gated, so a re-run is inert.
	 *
	 * @return array{grace:int,redemption:int,released:int}
	 */
	public function run_expiry_sweep(): array {
		$grace_days       = max( 0, igbz()->settings()->int( 'domain.grace_days', 30 ) );
		$redemption_days  = max( 1, igbz()->settings()->int( 'domain.redemption_days', 30 ) );
		$now              = time();
		$out              = [ 'grace' => 0, 'redemption' => 0, 'released' => 0 ];

		$rows = $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_domains' ) . "\n\t\t\t WHERE status IN ('active','grace','redemption') AND expires_at IS NOT NULL"
		);

		foreach ( $rows as $row ) {
			$expired_at = strtotime( (string) $row['expires_at'] . ' UTC' );
			if ( false === $expired_at || $expired_at > $now ) {
				continue;
			}

			$status = (string) $row['status'];
			if ( self::STATUS_ACTIVE === $status ) {
				$this->transition( $row, self::STATUS_GRACE, 'grace' );
				++$out['grace'];
				continue;
			}

			if ( self::STATUS_GRACE === $status && $now >= $expired_at + $grace_days * DAY_IN_SECONDS ) {
				$this->transition( $row, self::STATUS_REDEMPTION, 'redemption' );
				++$out['redemption'];
				continue;
			}

			if ( self::STATUS_REDEMPTION === $status && $now >= $expired_at + ( $grace_days + $redemption_days ) * DAY_IN_SECONDS ) {
				$this->transition( $row, self::STATUS_RELEASED, 'released' );
				$this->db->update(
					'tenant_domains',
					[ 'verified_at' => null ],
					[ 'tenant_id' => (int) $row['tenant_id'], 'domain' => (string) $row['name'] ]
				);
				++$out['released'];
			}
		}

		return $out;
	}

	/**
	 * Phase 39 — reconciliation against the provider: for every registered
	 * domain with a provider ref, ask the registry for its expiry and compare.
	 * Mismatches are REPORTED, never silently fixed — the operator decides.
	 * Without a configured provider the sweep is honest about doing nothing.
	 *
	 * @return array{scanned:int,mismatches:array<int,array<string,mixed>>,errors:int}
	 */
	public function reconcile(): array {
		$out = [ 'scanned' => 0, 'mismatches' => [], 'errors' => 0 ];
		if ( ! $this->is_configured() ) {
			return $out;
		}

		$base = rtrim( igbz()->settings()->string( 'domain.provider_base_url' ), '/' );
		$rows = $this->db->results(
			"SELECT * FROM " . $this->db->table( 'ig_domains' ) . "\n\t\t\t WHERE type IN ('registered','transferred') AND provider_ref <> '' AND status <> %s",
			self::STATUS_RELEASED
		);

		foreach ( $rows as $row ) {
			++$out['scanned'];
			$response = $this->http->get(
				$base . '/v1/domains/' . rawurlencode( (string) $row['provider_ref'] ),
				[ 'headers' => $this->headers(), 'channel' => 'domain', 'timeout' => 25 ]
			);
			if ( ! $response->ok() ) {
				++$out['errors'];
				$this->logger->warning( 'domain', 'Reconcile query failed', [ 'domain_id' => (int) $row['id'], 'error' => $response->error_message() ] );
				continue;
			}

			$body     = $response->json();
			$provider = strtotime( (string) ( $body['expires_at'] ?? '' ) . ' UTC' );
			$local    = strtotime( (string) ( $row['expires_at'] ?? '' ) . ' UTC' );
			if ( false === $provider || false === $local || abs( $provider - $local ) > DAY_IN_SECONDS ) {
				$out['mismatches'][] = [
					'domain_id'      => (int) $row['id'],
					'tenant_id'      => (int) $row['tenant_id'],
					'name'           => (string) $row['name'],
					'local_expires'  => (string) $row['expires_at'],
					'provider_expires' => (string) ( $body['expires_at'] ?? '' ),
				];
				$this->journal( (int) $row['tenant_id'], (int) $row['id'], 'reconcile_mismatch', (string) ( $body['expires_at'] ?? '' ) );
			}
		}

		return $out;
	}

	/** @param array<string,mixed> $row */
	private function transition( array $row, string $status, string $event ): void {
		$this->db->update(
			'ig_domains',
			[ 'status' => $status, 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => (int) $row['id'], 'tenant_id' => (int) $row['tenant_id'] ]
		);
		$this->journal( (int) $row['tenant_id'], (int) $row['id'], $event, (string) $row['expires_at'] );
		$this->logger->info( 'domain', 'Domain lifecycle transition', [ 'domain_id' => (int) $row['id'], 'status' => $status ] );
	}

	/** Phase 39 journal — ig_domain_journal doubles as the per-domain event log (order_id carries the domain id). */
	private function journal( int $tenant_id, int $subject_id, string $event, string $detail ): void {
		$this->db->insert(
			'ig_domain_journal',
			[
				'tenant_id'  => $tenant_id,
				'order_id'   => $subject_id,
				'event'      => $event,
				'detail'     => mb_substr( $detail, 0, 255 ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			]
		);
	}

	private function sync_tenant_domain( int $tenant_id, string $domain, bool $verified ): int {
		$domain = strtolower( trim( preg_replace( '#^https?://#', '', $domain ), '/' ) );
		// Tenant-scoped: the same domain name may sit in another tenant's (stale) mapping, and
		// flipping that row's verified_at would verify the wrong tenant.
		$existing = $this->db->row(
			'SELECT id FROM ' . $this->db->table( 'tenant_domains' ) . ' WHERE domain = %s AND tenant_id = %d LIMIT 1',
			$domain,
			$tenant_id
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
