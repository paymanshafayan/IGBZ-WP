<?php
namespace IGBZ\Suite\Modules\MultiTenant\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 30 — refund is a capability, not a requirement.
 *
 * Iranian PSPs differ here: some expose a refund endpoint, some do not, and Zarinpal's own
 * refund service is suspended by central-bank order (researched 1406/06). Adapters implement
 * this interface only when their PSP genuinely supports it; callers check with `instanceof`
 * (see PaymentService::supports_refund()). This phase deliberately does NOT invent refund
 * calls for providers without a documented endpoint.
 */
interface RefundableGatewayInterface {

	/**
	 * Refund money to the payer.
	 *
	 * @param string              $reference_id The PSP reference of the original payment.
	 * @param float               $amount       Refund amount in the store currency (partial allowed).
	 * @param array<string,mixed> $context      Must carry `idempotency_key` — a replayed refund with
	 *                                          the same key must never move money twice.
	 */
	public function refund( string $reference_id, float $amount, array $context = [] ): PaymentRefundResult;
}
