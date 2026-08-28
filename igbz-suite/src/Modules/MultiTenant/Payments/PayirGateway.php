<?php
namespace IGBZ\Suite\Modules\MultiTenant\Payments;

use IGBZ\Suite\Support\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Pay.ir gateway (docs.pay.ir/gateway).
 *
 *   POST https://pay.ir/pg/send   -> {status:1, token}
 *   GET  https://pay.ir/pg/{token}
 *   POST https://pay.ir/pg/verify -> {status:1, amount, transId, cardNumber, ...}
 *
 * Amounts are Rial and must be at least 10,000. The sandbox is reached with the literal API key
 * "test", which is what the "sandbox" checkbox switches to.
 */
final class PayirGateway implements GatewayInterface {

	private const SEND_URL     = 'https://pay.ir/pg/send';
	private const VERIFY_URL   = 'https://pay.ir/pg/verify';
	private const REDIRECT_URL = 'https://pay.ir/pg/';

	/** Pay.ir rejects anything under 10,000 Rial outright. */
	private const MIN_RIAL = 10000;

	public function __construct( private Http $http ) {}

	public function id(): string {
		return 'payir';
	}

	public function title(): string {
		return __( 'Pay.ir', 'igbz-suite' );
	}

	public function required_settings(): array {
		return [ 'payments.payir.api_key' ];
	}

	public function is_configured(): bool {
		return '' !== $this->api_key();
	}

	private function api_key(): string {
		if ( igbz()->settings()->bool( 'payments.payir.sandbox', false ) ) {
			return 'test';
		}
		return igbz()->settings()->string( 'payments.payir.api_key' );
	}

	public function request( float $amount, string $callback_url, array $context = [] ): PaymentRequestResult {
		if ( ! $this->is_configured() ) {
			return PaymentRequestResult::failure( 'not_configured', __( 'The Pay.ir API key is missing.', 'igbz-suite' ) );
		}

		$rial = Money::to_rial( $amount );
		if ( $rial < self::MIN_RIAL ) {
			return PaymentRequestResult::failure(
				'amount_too_low',
				sprintf(
					/* translators: %s: minimum amount in Rial */
					__( 'Pay.ir requires at least %s Rial.', 'igbz-suite' ),
					number_format_i18n( self::MIN_RIAL )
				)
			);
		}

		$response = $this->http->post(
			self::SEND_URL,
			[
				'json'    => array_filter(
					[
						'api'          => $this->api_key(),
						'amount'       => $rial,
						'redirect'     => $callback_url,
						'mobile'       => (string) ( $context['mobile'] ?? '' ),
						'factorNumber' => (string) ( $context['order_id'] ?? '' ),
						'description'  => mb_substr( (string) ( $context['description'] ?? '' ), 0, 255 ),
					],
					static fn( $value ) => '' !== $value && null !== $value
				),
				'headers' => [ 'Accept' => 'application/json' ],
				'channel' => 'payments',
				'timeout' => PspHttp::timeout(),
			]
		);

		$body = $response->json();

		if ( 1 === (int) ( $body['status'] ?? 0 ) && ! empty( $body['token'] ) ) {
			$token = (string) $body['token'];
			return PaymentRequestResult::ok( $token, self::REDIRECT_URL . rawurlencode( $token ) );
		}

		return PaymentRequestResult::failure(
			(string) ( $body['errorCode'] ?? 'request_failed' ),
			(string) ( $body['errorMessage'] ?? __( 'Pay.ir rejected the payment request.', 'igbz-suite' ) )
		);
	}

	public function verify( float $amount, array $callback_params ): PaymentVerifyResult {
		$token  = (string) ( $callback_params['token'] ?? '' );
		$status = (int) ( $callback_params['status'] ?? 0 );

		if ( '' === $token ) {
			return PaymentVerifyResult::failure( 'missing_params', __( 'Pay.ir did not return a token.', 'igbz-suite' ) );
		}
		if ( 1 !== $status ) {
			return PaymentVerifyResult::failure( 'cancelled', __( 'The payment was cancelled or failed at Pay.ir.', 'igbz-suite' ) );
		}

		$response = $this->http->post(
			self::VERIFY_URL,
			[
				'json'    => [ 'api' => $this->api_key(), 'token' => $token ],
				'headers' => [ 'Accept' => 'application/json' ],
				'channel' => 'payments',
				'timeout' => PspHttp::timeout(),
			]
		);

		$body = $response->json();

		if ( 1 === (int) ( $body['status'] ?? 0 ) ) {
			// Pay.ir echoes the amount it actually captured; refuse to settle on a mismatch.
			$confirmed = (int) ( $body['amount'] ?? 0 );
			$expected  = Money::to_rial( $amount );
			if ( $confirmed > 0 && $confirmed !== $expected ) {
				return PaymentVerifyResult::failure(
					'amount_mismatch',
					sprintf(
						/* translators: 1: amount confirmed by the gateway, 2: expected amount */
						__( 'Pay.ir confirmed %1$s Rial but the payment was for %2$s Rial.', 'igbz-suite' ),
						number_format_i18n( $confirmed ),
						number_format_i18n( $expected )
					)
				);
			}

			return PaymentVerifyResult::ok(
				(string) ( $body['transId'] ?? $token ),
				(string) ( $body['cardNumber'] ?? '' ),
				0.0
			);
		}

		// Verifying the same token twice returns error code 8; the first verification already paid.
		if ( 8 === (int) ( $body['errorCode'] ?? 0 ) ) {
			return PaymentVerifyResult::duplicate( $token );
		}

		return PaymentVerifyResult::failure(
			(string) ( $body['errorCode'] ?? 'verify_failed' ),
			(string) ( $body['errorMessage'] ?? __( 'Pay.ir could not verify this payment.', 'igbz-suite' ) )
		);
	}
}
