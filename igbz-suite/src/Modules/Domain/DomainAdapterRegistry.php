<?php
namespace IGBZ\Suite\Modules\Domain;

use IGBZ\Suite\Modules\Domain\Contracts\DomainAdapterInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Registry of domain providers — phase 37.
 *
 * Same pattern as the PSP gateways and the FX payout registry: adapters
 * register on `igbz_register_domain_providers`, `domain.provider` selects the
 * active one, and without a selection every operation fails honestly.
 */
final class DomainAdapterRegistry {

	/** @var array<string,DomainAdapterInterface> */
	private array $adapters = [];

	public function register( DomainAdapterInterface $adapter ): void {
		$this->adapters[ $adapter->id() ] = $adapter;
	}

	/** @return array<string,DomainAdapterInterface> */
	public function all(): array {
		return $this->adapters;
	}

	public function get( string $id ): ?DomainAdapterInterface {
		return $this->adapters[ $id ] ?? null;
	}

	public function active(): ?DomainAdapterInterface {
		$selected = (string) igbz()->settings()->string( 'domain.provider', '' );
		return '' !== $selected ? $this->get( $selected ) : null;
	}
}
