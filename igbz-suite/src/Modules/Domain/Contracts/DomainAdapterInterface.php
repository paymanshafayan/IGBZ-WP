<?php
namespace IGBZ\Suite\Modules\Domain\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Domain provider (registrar) adapter — phase 37.
 *
 * Vendor-agnostic, exactly like the PSP gateways and the FX payout adapters:
 * `igbz_register_domain_providers` feeds the registry and `domain.provider`
 * picks the active one. Phase 37 covers search, quote and the idempotent
 * order; phase 38 covers register/callback/compensation on the same contract.
 *
 * `register()` must NEVER be called before the order is paid — reservation is
 * local (an `ig_domain_orders` row), registration is post-payment only.
 */
interface DomainAdapterInterface {

	/** Stable adapter id, stored in `domain.provider`. */
	public function id(): string;

	public function title(): string;

	/** Whether the operator has entered what this adapter needs. */
	public function is_configured(): bool;

	/**
	 * Availability search. Returns candidate names that can be ordered.
	 *
	 * @return array{ok:bool,results:array<int,array{name:string,price:float,currency:string}>,error:string}
	 */
	public function search( string $term ): array;

	/**
	 * Price one exact name. ttl_minutes is the provider's own hold time; the
	 * service clamps it to `domain.quote_ttl_minutes` afterwards.
	 *
	 * @return array{ok:bool,price:float,currency:string,ttl_minutes:int,error:string}
	 */
	public function quote( string $name ): array;

	/**
	 * Register a paid order with the provider. Phase 38 territory — the
	 * service never calls this before the order is `paid`.
	 *
	 * @param array<string,mixed> $order A row of ig_domain_orders.
	 * @return array{ok:bool,reference:string,error:string}
	 */
	public function register( array $order ): array;

	/**
	 * Phase 38 backup polling: ask the provider for the verdict of an order
	 * still marked `registering`. state is registered|failed|unknown; a
	 * provider that cannot answer returns unknown and nothing is guessed.
	 *
	 * @param array<string,mixed> $order A row of ig_domain_orders.
	 * @return array{state:string,reference:string,error:string}
	 */
	public function query( array $order ): array;
}
