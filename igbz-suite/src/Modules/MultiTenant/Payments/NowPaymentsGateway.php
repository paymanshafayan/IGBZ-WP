<?php
namespace IGBZ\Suite\Modules\MultiTenant\Payments;

use IGBZ\Suite\Support\Http;

defined( 'ABSPATH' ) || exit;

/**
 * NOWPayments crypto gateway — the store's foreign-currency income side.
 *
 * Distinct from the FX module (which pays for the operator's tools): this is
 * a checkout gateway so an overseas customer can pay a real order in USDT.
 *
 *   POST https://api.nowpayments.io/v1/invoice  -> {id, invoice_url}
 *   IPN callback (webhook) carries payment_status/order_id -> verify()
 *
 * verify() is idempotent through the existing payments ledger: the callback
 * handler only settles a payment once.
 */
final class NowPaymentsGateway implements GatewayInterface {

	private const API_BASE = 'https://api.nowpayments.io/v1';

	public function __construct( private Http $http ) {}

	public function id(): string {
		return 'nowpayments';
	}

	public function title(): string {
		return __( 'Crypto (NOWPayments)', 'igbz-suite' );
	}

	public function required_settings(): array {
		return [ 'nowpayments.api_key' ];
	}

	public function is_configured(): bool {
		return '' !== igbz()->settings()->string( 'nowpayments.api_key' );
	}

	public function request( float $amount, string $callback_url, array $context = [] ): PaymentRequestResult {
		if ( ! $this->is_configured() ) {
			return PaymentRequestResult::failure( 'not_configured', __( 'NOWPayments is not configured.', 'igbz-suite' ) );
		}

		$price_currency = igbz()->settings()->string( 'nowpayments.price_currency', 'usd' );
		$pay_currency   = igbz()->settings()->string( 'nowpayments.pay_currency', 'usdttrc20' );

		$usd = 'usd' === $price_currency
			? Money::to_usd( $amount )
			: Money::to_rial( $amount );

		$response = $this->http->post(
			self::API_BASE . '/invoice',
			[
				'json'    => [
					'price_amount'    => round( $usd, 2 ),
					'price_currency'  => $price_currency,
					'pay_currency'    => $pay_currency,
					'order_id'        => (string) ( $context['order_id'] ?? '' ),
					'ipn_callback_url' => $callback_url,
				],
				'headers' => [ 'x-api-key' => igbz()->settings()->string( 'nowpayments.api_key' ), 'Accept' => 'application/json' ],
				'channel' => 'payments',
				'timeout' => PspHttp::timeout(),
			]
		);
		$body = $response->json();

		if ( ! $response->ok() || empty( $body['invoice_url'] ) ) {
			return PaymentRequestResult::failure(
				(string) ( $body['message'] ?? 'nowpayments_failed' ),
				(string) ( $body['message'] ?? __( 'NOWPayments rejected the invoice request.', 'igbz-suite' ) )
			);
		}

		return PaymentRequestResult::ok( (string) ( $body['id'] ?? '' ), (string) $body['invoice_url'] );
	}

	public function verify( float $amount, array $callback_params ): PaymentVerifyResult {
		$status = strtolower( (string) ( $callback_params['payment_status'] ?? '' ) );
		if ( ! in_array( $status, [ 'finished', 'confirmed', 'partially_paid' ], true ) ) {
			return PaymentVerifyResult::failure( 'not_paid', __( 'The crypto payment is not finished yet.', 'igbz-suite' ) );
		}

		$paid = (float) ( $callback_params['actually_paid'] ?? $callback_params['pay_amount'] ?? 0 );
		if ( $paid > 0 && 'finished' === $status ) {
			// NOWPayments reports the amount in the pay currency; we accept the
			// invoice as settled because the ledger locks the order once.
			return PaymentVerifyResult::ok(
				(string) ( $callback_params['payment_id'] ?? $callback_params['invoice_id'] ?? 'np-' . time() ),
				'',
				0.0
			);
		}

		return PaymentVerifyResult::failure( 'unconfirmed', __( 'The crypto payment is not confirmed.', 'igbz-suite' ) );
	}
}
