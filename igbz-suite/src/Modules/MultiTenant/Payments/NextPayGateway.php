<?php
namespace IGBZ\Suite\Modules\MultiTenant\Payments;

use IGBZ\Suite\Support\Http;

defined( 'ABSPATH' ) || exit;

/**
 * NextPay gateway (nextpay.org/nx/docs).
 *
 *   POST https://nextpay.org/nx/gateway/token   -> {code:-1, trans_id}
 *   GET  https://nextpay.org/nx/gateway/payment/{trans_id}
 *   POST https://nextpay.org/nx/gateway/verify  -> {code:0, amount, card_holder, Shaparak_Ref_Id, ...}
 *
 * Two quirks worth remembering: creating a token succeeds on code -1 (not 0), while verification
 * succeeds on code 0. Amounts are sent in Rial with an explicit `currency` so the store's own
 * Toman/Rial setting can never be misread on NextPay's side.
 */
final class NextPayGateway implements GatewayInterface {

	private const TOKEN_URL   = 'https://nextpay.org/nx/gateway/token';
	private const VERIFY_URL  = 'https://nextpay.org/nx/gateway/verify';
	private const PAYMENT_URL = 'https://nextpay.org/nx/gateway/payment/';

	/** Token creation returns -1 on success. */
	private const TOKEN_OK = -1;

	/** Verification returns 0 on success. */
	private const VERIFY_OK = 0;

	public function __construct( private Http $http ) {}

	public function id(): string {
		return 'nextpay';
	}

	public function title(): string {
		return __( 'NextPay', 'igbz-suite' );
	}

	public function required_settings(): array {
		return [ 'payments.nextpay.api_key' ];
	}

	public function is_configured(): bool {
		return '' !== igbz()->settings()->string( 'payments.nextpay.api_key' );
	}

	public function request( float $amount, string $callback_url, array $context = [] ): PaymentRequestResult {
		if ( ! $this->is_configured() ) {
			return PaymentRequestResult::failure( 'not_configured', __( 'The NextPay API key is missing.', 'igbz-suite' ) );
		}

		$response = $this->http->post(
			self::TOKEN_URL,
			[
				'json'    => array_filter(
					[
						'api_key'        => igbz()->settings()->required( 'payments.nextpay.api_key' ),
						'order_id'       => (string) ( $context['order_id'] ?? uniqid( 'igbz', true ) ),
						'amount'         => Money::to_rial( $amount ),
						'currency'       => 'IRR',
						'callback_uri'   => $callback_url,
						'customer_phone' => (string) ( $context['mobile'] ?? '' ),
						'payer_name'     => (string) ( $context['name'] ?? '' ),
						'payer_desc'     => mb_substr( (string) ( $context['description'] ?? '' ), 0, 255 ),
					],
					static fn( $value ) => '' !== $value && null !== $value
				),
				'headers' => [ 'Accept' => 'application/json' ],
				'channel' => 'payments',
				'timeout' => PspHttp::timeout(),
			]
		);

		$body = $response->json();
		$code = (int) ( $body['code'] ?? -100 );

		if ( self::TOKEN_OK === $code && ! empty( $body['trans_id'] ) ) {
			$trans_id = (string) $body['trans_id'];
			return PaymentRequestResult::ok( $trans_id, self::PAYMENT_URL . rawurlencode( $trans_id ) );
		}

		return PaymentRequestResult::failure( (string) $code, self::message( $code ) );
	}

	public function verify( float $amount, array $callback_params ): PaymentVerifyResult {
		// NextPay POSTs trans_id and order_id back to the callback URL.
		$trans_id = (string) ( $callback_params['trans_id'] ?? $callback_params['transaction_id'] ?? '' );
		if ( '' === $trans_id ) {
			return PaymentVerifyResult::failure( 'missing_params', __( 'NextPay did not return a transaction id.', 'igbz-suite' ) );
		}

		$response = $this->http->post(
			self::VERIFY_URL,
			[
				'json'    => [
					'api_key'  => igbz()->settings()->required( 'payments.nextpay.api_key' ),
					'trans_id' => $trans_id,
					'amount'   => Money::to_rial( $amount ),
					'currency' => 'IRR',
				],
				'headers' => [ 'Accept' => 'application/json' ],
				'channel' => 'payments',
				'timeout' => PspHttp::timeout(),
			]
		);

		$body = $response->json();
		$code = (int) ( $body['code'] ?? -100 );

		if ( self::VERIFY_OK === $code ) {
			// The amount NextPay confirms is authoritative; a mismatch means the callback was forged
			// or the order was edited mid-payment, and must not be treated as paid.
			$confirmed = (int) ( $body['amount'] ?? 0 );
			$expected  = Money::to_rial( $amount );
			if ( $confirmed > 0 && $confirmed !== $expected ) {
				return PaymentVerifyResult::failure(
					'amount_mismatch',
					sprintf(
						/* translators: 1: amount confirmed by the gateway, 2: expected amount */
						__( 'NextPay confirmed %1$s Rial but the payment was for %2$s Rial.', 'igbz-suite' ),
						number_format_i18n( $confirmed ),
						number_format_i18n( $expected )
					)
				);
			}

			return PaymentVerifyResult::ok(
				(string) ( $body['Shaparak_Ref_Id'] ?? $body['shaparak_ref_id'] ?? $trans_id ),
				(string) ( $body['card_holder'] ?? '' ),
				0.0
			);
		}

		// -25 is "this trans_id was already used": the payment did go through, so treat it as a
		// duplicate callback rather than a failure and let the caller stay idempotent.
		if ( -25 === $code ) {
			return PaymentVerifyResult::duplicate( $trans_id );
		}

		return PaymentVerifyResult::failure( (string) $code, self::message( $code ) );
	}

	/** Documented NextPay result codes. */
	private static function message( int $code ): string {
		$messages = [
			-1  => __( 'The transaction is ready to be sent to the bank.', 'igbz-suite' ),
			-2  => __( 'The transaction was sent to the bank and is awaiting the customer.', 'igbz-suite' ),
			-3  => __( 'The bank has not returned a result for this transaction yet.', 'igbz-suite' ),
			-4  => __( 'The payment was cancelled by the customer.', 'igbz-suite' ),
			-20 => __( 'The NextPay API key was not sent.', 'igbz-suite' ),
			-21 => __( 'The transaction id was empty.', 'igbz-suite' ),
			-22 => __( 'The amount was not sent.', 'igbz-suite' ),
			-23 => __( 'The callback URL was not sent.', 'igbz-suite' ),
			-24 => __( 'The amount is not a valid number.', 'igbz-suite' ),
			-25 => __( 'This transaction has already been paid or cannot be paid again.', 'igbz-suite' ),
			-26 => __( 'The transaction id was not sent.', 'igbz-suite' ),
			-30 => __( 'The amount is below the NextPay minimum.', 'igbz-suite' ),
			-32 => __( 'The callback URL is malformed.', 'igbz-suite' ),
			-33 => __( 'The NextPay API key is not valid.', 'igbz-suite' ),
			-34 => __( 'The transaction id is not valid.', 'igbz-suite' ),
		];

		return $messages[ $code ] ?? sprintf(
			/* translators: %d: gateway result code */
			__( 'NextPay rejected the request (code %d).', 'igbz-suite' ),
			$code
		);
	}
}
