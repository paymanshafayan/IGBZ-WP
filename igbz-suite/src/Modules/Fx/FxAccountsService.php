<?php
namespace IGBZ\Suite\Modules\Fx;

use IGBZ\Suite\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Per-tenant foreign accounts (the social provider account per tenant, or
 * the tenant's own provider account). Each row maps to a provider and a
 * billing day; the monthly bill is created from it and settled from the
 * tenant's FX wallet. Phase 50: legacy providers no longer bill — the
 * mapping machinery stays for the single provider's plan model.
 */
final class FxAccountsService {

	public function __construct( private Db $db ) {}

	/** @return array<int,array<string,mixed>> */
	public function all( int $tenant_id = 0 ): array {
		if ( $tenant_id > 0 ) {
			return $this->db->results(
				'SELECT * FROM ' . $this->db->table( 'fx_accounts' ) . ' WHERE tenant_id = %d ORDER BY id',
				$tenant_id
			);
		}
		return $this->db->results( 'SELECT * FROM ' . $this->db->table( 'fx_accounts' ) . ' ORDER BY id LIMIT 500' ); // Phase 20: bounded list.
	}

	/** @return array<int,array<string,mixed>> */
	public function active(): array {
		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'fx_accounts' ) . ' WHERE status = %s ORDER BY id',
			'active'
		);
	}

	public function get( int $id ): ?array {
		$row = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'fx_accounts' ) . ' WHERE id = %d AND tenant_id = %d', $id, igbz()->tenancy()->id() );
		return $row ?: null;
	}

	public function create( int $tenant_id, string $provider, string $provider_account_id, int $billing_day = 1 ): int {
		return (int) $this->db->insert(
			'fx_accounts',
			[
				'tenant_id'           => $tenant_id,
				'provider'            => $provider,
				'provider_account_id' => $provider_account_id,
				'status'              => 'active',
				'billing_day'         => $billing_day,
				'created_at'          => current_time( 'mysql', true ),
				'updated_at'          => current_time( 'mysql', true ),
			]
		);
	}

	public function update( int $id, array $data ): void {
		$data['updated_at'] = current_time( 'mysql', true );
		$this->db->update( 'fx_accounts', $data, [ 'id' => $id, 'tenant_id' => igbz()->tenancy()->id() ] );
	}
}
