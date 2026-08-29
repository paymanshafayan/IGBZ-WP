<?php
namespace IGBZ\Suite\Modules\MultiTenant\Payments;

use IGBZ\Suite\Support\Http;

defined( 'ABSPATH' ) || exit;

/**
 * IDPay gateway (api.idpay.ir v1.1).
 *
 *   POST https://api.idpay.ir/v1.1/payment          -> {id, link}
 *   POST https://api.idpay.ir/v1.1/payment/verify   -> {status, track_id, payment:{card_no,...}}
 * Header X-API-KEY plus X-SANDBOX: 1 when testing. Status 100/101/200 mean paid.
 */
final class IdPayGateway implements GatewayInterface {

	private const BASE = 'https://api.idpay.ir/v1.1/';

	public function __construct( private Http $http ) {}

	public function id(): string {
		return 'idpay';
	}

	public function title(): string {
		return __( 'IDPay', 'igbz-suite' );
	}

	public function required_settings(): array {
		return [ 'payments.idpay.api_key' ];
	}

	public function is_configured(): bool {
		return '' !== igbz()->settings()->string( 'payments.idpay.api_key' );
	}

	/** @return array<string,string> */
	private function headers(): array {
		$headers = [
			'X-API-KEY'    => igbz()->settings()->required( 'payments.idpay.api_key' ),
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		];
		if ( igbz()->settings()->bool( 'payments.idpay.sandbox', false ) ) {
			$headers['X-SANDBOX'] = '1';
		}
		return $headers;
	}

	/** IDPay expects Rial. */
	private function to_rial( float $amount ): int {
		return Money::to_rial( $amount );
	}

	public function request( float $amount, string $callback_url, array $context = [] ): PaymentRequestResult {
		if ( ! $this->is_configured() ) {
			return PaymentRequestResult::failure( 'not_configured', __( 'IDPay API key is missing.', 'igbz-suite' ) );
		}

		$response = $this->http->post(
			self::BASE . 'payment',
			[
				'json'    => array_filter(
					[
						'order_id' => (string) ( $context['order_id'] ?? uniqid( 'igbz', true ) ),
						'amount'   => $this->to_rial( $amount ),
						'callback' => $callback_url,
						'name'     => (string) ( $context['name'] ?? '' ),
						'phone'    => (string) ( $context['mobile'] ?? '' ),
						'mail'     => (string) ( $context['email'] ?? '' ),
						'desc'     => mb_substr( (string) ( $context['description'] ?? '' ), 0, 255 ),
					],
					static fn( $value ) => '' !== $value && null !== $value
				),
				'headers' => $this->headers(),
				'channel' => 'payments',
				'timeout' => PspHttp::timeout(),
			]
		);

		$body = $response->json();
		if ( ! empty( $body['id'] ) && ! empty( $body['link'] ) ) {
			return PaymentRequestResult::ok( (string) $body['id'], (string) $body['link'] );
		}

		return PaymentRequestResult::failure(
			(string) ( $body['error_code'] ?? 'request_failed' ),
			(string) ( $body['error_message'] ?? __( 'IDPay rejected the payment request.', 'igbz-suite' ) )
		);
	}

	public function verify( float $amount, array $callback_params ): PaymentVerifyResult {
		$id       = (string) ( $callback_params['id'] ?? '' );
		$order_id = (string) ( $callback_params['order_id'] ?? '' );
		$status   = (int) ( $callback_params['status'] ?? 0 );

		if ( '' === $id || '' === $order_id ) {
			return PaymentVerifyResult::failure( 'missing_params', __( 'Incomplete callback from IDPay.', 'igbz-suite' ) );
		}
		if ( 10 !== $status && $status < 100 ) {
			return PaymentVerifyResult::failure( 'cancelled', __( 'The payment was not completed.', 'igbz-suite' ) );
		}

		$response = $this->http->post(
			self::BASE . 'payment/verify',
			[
				'json'    => [ 'id' => $id, 'order_id' => $order_id ],
				'headers' => $this->headers(),
				'channel' => 'payments',
				'timeout' => PspHttp::timeout(),
			]
		);

		$body   = $response->json();
		$result = (int) ( $body['status'] ?? 0 );

		if ( in_array( $result, [ 100, 101, 200 ], true ) ) {
			$payment = is_array( $body['payment'] ?? null ) ? $body['payment'] : [];
			if ( 101 === $result ) {
				return PaymentVerifyResult::duplicate( (string) ( $body['track_id'] ?? '' ) );
			}
			return PaymentVerifyResult::ok(
				(string) ( $body['track_id'] ?? '' ),
				(string) ( $payment['card_no'] ?? '' ),
				0.0
			);
		}

		return PaymentVerifyResult::failure(
			(string) ( $body['error_code'] ?? $result ),
			(string) ( $body['error_message'] ?? __( 'IDPay could not verify this payment.', 'igbz-suite' ) )
		);
	}
}
