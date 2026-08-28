<?php
namespace IGBZ\Suite\Modules\MultiTenant\Payments;

defined( 'ABSPATH' ) || exit;

use IGBZ\Suite\Support\Http;

/**
 * Iran Kish direct gateway — REST v3 with a terminal key.
 *
 *   Request: POST https://ikc.shaparak.ir/api/v3/token/payment
 *     { terminalId, amount, orderId, callbackUrl } with bearer key -> { token }
 *     redirect https://ikc.shaparak.ir/ipg/StartPay/{token}
 *   Verify:  POST /api/v3/token/verify { terminalId, token } -> { responseCode:0 }
 */
final class IranKishGateway extends AbstractIpgGateway {

	private const API    = 'https://ikc.shaparak.ir/api/v3';
	private const START  = 'https://ikc.shaparak.ir/ipg/StartPay/';

	public function __construct( Http $http ) {
		parent::__construct( $http, 'payments.irankish' );
	}

	public function id(): string {
		return 'irankish';
	}

	public function title(): string {
		return __( 'Iran Kish', 'igbz-suite' );
	}

	public function required_settings(): array {
		return [ 'payments.irankish.terminal_id', 'payments.irankish.api_key' ];
	}

	private function headers(): array {
		return [ 'Accept' => 'application/json', 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $this->cfg( 'api_key' ) ];
	}

	public function request( float $amount, string $callback_url, array $context = [] ): PaymentRequestResult {
		$response = $this->http->post(
			self::API . '/token/payment',
			[
				'json'    => [
					'terminalId'  => (int) $this->cfg( 'terminal_id' ),
					'amount'      => Money::to_rial( $amount ),
					'orderId'     => (string) ( $context['order_id'] ?? 'ORD-' . time() ),
					'callbackUrl' => $callback_url,
				],
				'headers' => $this->headers(),
				'channel' => 'payments',
				'timeout' => PspHttp::timeout(),
			]
		);
		$body = $response->json();
		$token = (string) ( $body['result']['token'] ?? $body['token'] ?? '' );

		if ( $response->ok() && '' !== $token ) {
			return PaymentRequestResult::ok( $token, self::START . rawurlencode( $token ) );
		}

		return PaymentRequestResult::failure( (string) ( $body['resultCode'] ?? 'irankish_failed' ), (string) ( $body['message'] ?? __( 'Iran Kish rejected the request.', 'igbz-suite' ) ) );
	}

	public function verify( float $amount, array $callback_params ): PaymentVerifyResult {
		$token = (string) ( $callback_params['token'] ?? '' );
		if ( '' === $token ) {
			return PaymentVerifyResult::failure( 'missing_token', __( 'Iran Kish did not return a token.', 'igbz-suite' ) );
		}

		$response = $this->http->post(
			self::API . '/token/verify',
			[ 'json' => [ 'terminalId' => (int) $this->cfg( 'terminal_id' ), 'token' => $token ], 'headers' => $this->headers(), 'channel' => 'payments', 'timeout' => PspHttp::timeout() ]
		);
		$body = $response->json();

		if ( $response->ok() && 0 === (int) ( $body['responseCode'] ?? 1 ) ) {
			return PaymentVerifyResult::ok( (string) ( $body['referenceId'] ?? $body['retrievalReferenceNumber'] ?? $token ), (string) ( $body['cardPan'] ?? '' ), 0.0 );
		}

		return PaymentVerifyResult::failure( (string) ( $body['responseCode'] ?? 'verify_failed' ), (string) ( $body['message'] ?? __( 'Iran Kish could not verify.', 'igbz-suite' ) ) );
	}
}
