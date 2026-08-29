<?php
namespace IGBZ\Suite\Modules\Fx\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Automatic foreign-currency payout.
 *
 * The provider pays the tenant's Manus/ManyChat bills on the operator's
 * behalf (virtual card funded with USDT, an exchange, or anything else the
 * operator wires up). The adapter is deliberately vendor-agnostic, like the
 * PSP gateways: `igbz_register_fx_payout_providers` feeds the registry and
 * `fx.payout_provider` picks the active one.
 *
 * Reconciliation comes from the provider's own card/webhook events, not from
 * the Manus/ManyChat APIs — neither exposes a public "bill" endpoint.
 */
interface FxPayoutAdapterInterface {

	/** Stable adapter id, stored in `fx.payout_provider`. */
	public function id(): string;

	public function title(): string;

	/** Whether the operator has entered what this adapter needs. */
	public function is_configured(): bool;

	/**
	 * Pay one bill. Implementations should be idempotent per bill id.
	 *
	 * Phase 36: when the charge was sent but the answer is unknown (a timeout
	 * or transport failure after submission), return ok=false with
	 * `state => 'pending'` — the bill then stays debited and pending instead
	 * of being refunded, and reconcile() or the webhook settles the doubt.
	 * Adapters MAY also implement
	 * `query( array $bill ): array{state:string,reference:string}` with state
	 * settled|failed|unknown; reconcile() uses it when present.
	 *
	 * @param array<string,mixed> $bill A row of fx_bills.
	 * @return array{ok:bool,reference:string,error:string,state?:string}
	 */
	public function pay( array $bill ): array;

	/** Current card balance in USD (or 0 when the provider cannot report it). */
	public function card_balance(): float;

	/** Handle a provider webhook (success/failure of a payout). */
	public function webhook( array $payload ): void;
}
