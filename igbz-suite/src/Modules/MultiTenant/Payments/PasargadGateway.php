<?php
namespace IGBZ\Suite\Modules\MultiTenant\Payments;

defined( 'ABSPATH' ) || exit;

use IGBZ\Suite\Support\Http;

/**
 * Pasargad direct gateway — REST with RSA token + sign.
 *
 *   Request: POST https://pep.shaparak.ir/ws-payment/rest/... (token via RSA)
 *   Verify:  POST .../VerifyPayment with RSA-signed payload.
 */
final class PasargadGateway extends AbstractIpgGateway {

	private const API   = 'https://pep.shaparak.ir/ws-payment/rest';
	private const START = 'https://pep.shaparak.ir/gateway.aspx';

	public function __construct( Http $http ) {
		parent::__construct( $http, 'payments.pasargad' );
	}

	public function id(): string {
		return 'pasargad';
	}

	public function title(): string {
		return __( 'Pasargad', 'igbz-suite' );
	}

	public function required_settings(): array {
		return [ 'payments.pasargad.merchant_code', 'payments.pasargad.terminal_code', 'payments.pasargad.private_key' ];
	}

	private function sign( string $data ): string {
		$sign = '';
		$key  = openssl_pkey_get_private( $this->cfg( 'private_key' ) );
		if ( $key && openssl_sign( $data, $sign, $key, OPENSSL_ALGO_SHA1 ) ) {
			return base64_encode( $sign );
		}
		return '';
	}

	public function request( float $amount, string $callback_url, array $context = [] ): PaymentRequestResult {
		$order_id  = (string) ( $context['order_id'] ?? 'ORD-' . time() );
		$invoice   = gmdate( 'Y/m/d H:i:s' );
		$plain     = $this->cfg( 'merchant_code' ) . '#' . $this->cfg( 'terminal_code' ) . '#' . $order_id . '#' . $invoice . '#' . $amount . '#' . $callback_url;
		$sign      = $this->sign( $plain );

		$response = $this->http->post(
			self::API . '/payment/psp/request',
			[
				'json'    => [
					'merchantCode'  => $this->cfg( 'merchant_code' ),
					'terminalCode'  => (int) $this->cfg( 'terminal_code' ),
					'invoiceNumber' => $order_id,
					'invoiceDate'   => $invoice,
					'amount'        => Money::to_rial( $amount ),
					'redirectAddress' => $callback_url,
					'sign'          => $sign,
				],
				'headers' => [ 'Accept' => 'application/json', 'Content-Type' => 'application/json' ],
				'channel' => 'payments',
				'timeout' => PspHttp::timeout(),
			]
		);
		$body = $response->json();
		$token = (string) ( $body['token'] ?? '' );

		if ( $response->ok() && '' !== $token ) {
			return PaymentRequestResult::ok( $token, self::START . '?n=' . rawurlencode( $token ) );
		}

		return PaymentRequestResult::failure( (string) ( $body['resultCode'] ?? 'pasargad_failed' ), (string) ( $body['message'] ?? __( 'Pasargad rejected the request.', 'igbz-suite' ) ) );
	}

	public function verify( float $amount, array $callback_params ): PaymentVerifyResult {
		$order_id = (string) ( $callback_params['iN'] ?? $callback_params['invoiceNumber'] ?? '' );
		$invoice  = (string) ( $callback_params['iD'] ?? gmdate( 'Y/m/d H:i:s' ) );
		$plain    = $this->cfg( 'merchant_code' ) . '#' . $this->cfg( 'terminal_code' ) . '#' . $order_id . '#' . $invoice;
		$sign     = $this->sign( $plain );

		$response = $this->http->post(
			self::API . '/payment/psp/verify',
			[
				'json'    => [ 'merchantCode' => $this->cfg( 'merchant_code' ), 'terminalCode' => (int) $this->cfg( 'terminal_code' ), 'invoiceNumber' => $order_id, 'invoiceDate' => $invoice, 'sign' => $sign ],
				'headers' => [ 'Accept' => 'application/json', 'Content-Type' => 'application/json' ],
				'channel' => 'payments',
				'timeout' => PspHttp::timeout(),
			]
		);
		$body = $response->json();

		if ( $response->ok() && (bool) ( $body['result'] ?? false ) ) {
			return PaymentVerifyResult::ok( (string) ( $body['transactionReferenceId'] ?? $order_id ), (string) ( $body['maskedCardNumber'] ?? '' ), 0.0 );
		}

		return PaymentVerifyResult::failure( 'verify_failed', (string) ( $body['message'] ?? __( 'Pasargad could not verify.', 'igbz-suite' ) ) );
	}
}
