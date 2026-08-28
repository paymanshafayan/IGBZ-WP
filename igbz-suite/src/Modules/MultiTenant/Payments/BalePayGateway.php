<?php
namespace IGBZ\Suite\Modules\MultiTenant\Payments;

use IGBZ\Suite\Support\Http;

defined( 'ABSPATH' ) || exit;

/**
 * BalePay — payment through the Bale messenger wallet (بله).
 *
 * Flow per Bale's bot API: sendInvoice/createInvoiceLink with the bot's
 * provider token; the customer pays inside Bale with their wallet; the bot
 * receives a SuccessfulPayment update carrying provider_payment_charge_id.
 * Because there is no classic callback URL, verification is driven by the
 * webhook endpoint the bot delivers updates to.
 */
final class BalePayGateway implements GatewayInterface {

	private const API_BASE = 'https://tapi.bale.ai';

	public function __construct( private Http $http ) {}

	public function id(): string {
		return 'balepay';
	}

	public function title(): string {
		return __( 'Bale wallet (بله)', 'igbz-suite' );
	}

	public function required_settings(): array {
		return [ 'bale.provider_token', 'bale.bot_token' ];
	}

	public function is_configured(): bool {
		return '' !== igbz()->settings()->string( 'bale.provider_token' );
	}

	public function request( float $amount, string $callback_url, array $context = [] ): PaymentRequestResult {
		if ( ! $this->is_configured() ) {
			return PaymentRequestResult::failure( 'not_configured', __( 'BalePay is not configured.', 'igbz-suite' ) );
		}

		$bot_token = igbz()->settings()->string( 'bale.bot_token' );
		if ( '' === $bot_token ) {
			return PaymentRequestResult::failure( 'not_configured', __( 'Bale bot token is missing.', 'igbz-suite' ) );
		}

		$response = $this->http->post(
			self::API_BASE . '/bot' . $bot_token . '/createInvoiceLink',
			[
				'json'    => [
					'title'          => mb_substr( (string) ( $context['description'] ?? __( 'Store payment', 'igbz-suite' ) ), 0, 32 ),
					'description'    => mb_substr( (string) ( $context['description'] ?? 'Order payment' ), 0, 255 ),
					'payload'        => 'igbz:' . (string) ( $context['order_id'] ?? '' ) . ':' . time(),
					'provider_token' => igbz()->settings()->string( 'bale.provider_token' ),
					'prices'         => [ [ 'label' => 'Order', 'amount' => Money::to_rial( $amount ) ] ],
				],
				'headers' => [ 'Accept' => 'application/json' ],
				'channel' => 'payments',
				'timeout' => PspHttp::timeout(),
			]
		);
		$body = $response->json();

		if ( ! $response->ok() || empty( $body['result'] ) ) {
			return PaymentRequestResult::failure(
				(string) ( $body['description'] ?? 'balepay_failed' ),
				(string) ( $body['description'] ?? __( 'BalePay rejected the invoice request.', 'igbz-suite' ) )
			);
		}

		return PaymentRequestResult::ok( (string) $body['result'], (string) $body['result'] );
	}

	/**
	 * Verification is confirmed via the Bale webhook (SuccessfulPayment).
	 * The callback handler stores the charge id in the payments row; here we
	 * accept it when present and paid.
	 */
	public function verify( float $amount, array $callback_params ): PaymentVerifyResult {
		$charge = (string) ( $callback_params['provider_payment_charge_id'] ?? $callback_params['charge_id'] ?? '' );
		$ok     = (bool) ( $callback_params['successful'] ?? ( '' !== $charge ) );

		if ( ! $ok || '' === $charge ) {
			return PaymentVerifyResult::failure( 'not_paid', __( 'The Bale payment is not confirmed yet.', 'igbz-suite' ) );
		}

		return PaymentVerifyResult::ok( $charge, '', 0.0 );
	}
}
