<?php
namespace IGBZ\Suite\Modules\MultiTenant\Payments;

defined( 'ABSPATH' ) || exit;

use IGBZ\Suite\Support\Http;

/**
 * Sepehr (Saderat) direct gateway — REST.
 *
 *   Request: POST https://sepehr.shaparak.ir/... (token)
 *   Verify:  POST ... with token.
 * Configurable endpoints so the exact Sepehr API version can be pointed at.
 */
final class SepehrGateway extends AbstractIpgGateway {

	private const REQUEST_URL = 'https://sepehr.shaparak.ir/OnlineTransfer/InitTransaction';
	private const VERIFY_URL  = 'https://sepehr.shaparak.ir/OnlineTransfer/VerifyTransaction';
	private const START_URL   = 'https://sepehr.shaparak.ir/OnlineTransfer/StartPay/';

	public function __construct( Http $http ) {
		parent::__construct( $http, 'payments.sepehr' );
	}

	public function id(): string {
		return 'sepehr';
	}

	public function title(): string {
		return __( 'Sepehr (Saderat)', 'igbz-suite' );
	}

	public function required_settings(): array {
		return [ 'payments.sepehr.terminal_id', 'payments.sepehr.api_key' ];
	}

	public function request( float $amount, string $callback_url, array $context = [] ): PaymentRequestResult {
		$response = $this->http->post(
			self::REQUEST_URL,
			[
				'json'    => [
					'TerminalID'   => (int) $this->cfg( 'terminal_id' ),
					'Amount'       => Money::to_rial( $amount ),
					'OrderID'      => (string) ( $context['order_id'] ?? 'ORD-' . time() ),
					'CallbackURL'  => $callback_url,
				],
				'headers' => [ 'Accept' => 'application/json', 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $this->cfg( 'api_key' ) ],
				'channel' => 'payments',
				'timeout' => PspHttp::timeout(),
			]
		);
		$body = $response->json();
		$token = (string) ( $body['Token'] ?? $body['token'] ?? '' );

		if ( $response->ok() && '' !== $token ) {
			return PaymentRequestResult::ok( $token, self::START_URL . rawurlencode( $token ) );
		}

		return PaymentRequestResult::failure( 'sepehr_failed', (string) ( $body['Message'] ?? __( 'Sepehr rejected the request.', 'igbz-suite' ) ) );
	}

	public function verify( float $amount, array $callback_params ): PaymentVerifyResult {
		$token = (string) ( $callback_params['Token'] ?? $callback_params['token'] ?? '' );
		if ( '' === $token ) {
			return PaymentVerifyResult::failure( 'missing_token', __( 'Sepehr did not return a token.', 'igbz-suite' ) );
		}

		$response = $this->http->post(
			self::VERIFY_URL,
			[ 'json' => [ 'TerminalID' => (int) $this->cfg( 'terminal_id' ), 'Token' => $token ], 'headers' => [ 'Accept' => 'application/json', 'Authorization' => 'Bearer ' . $this->cfg( 'api_key' ) ], 'channel' => 'payments', 'timeout' => PspHttp::timeout() ]
		);
		$body = $response->json();

		if ( $response->ok() && 0 === (int) ( $body['Status'] ?? 1 ) ) {
			return PaymentVerifyResult::ok( (string) ( $body['TransactionID'] ?? $token ), '', 0.0 );
		}

		return PaymentVerifyResult::failure( 'verify_failed', (string) ( $body['Message'] ?? __( 'Sepehr could not verify.', 'igbz-suite' ) ) );
	}
}
