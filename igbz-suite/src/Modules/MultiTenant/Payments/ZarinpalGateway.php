<?php
namespace IGBZ\Suite\Modules\MultiTenant\Payments;

use IGBZ\Suite\Support\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Zarinpal Payment Gateway, REST API v4.
 *
 * Documented endpoints (zarinpal.com/docs/paymentGateway):
 *   POST https://payment.zarinpal.com/pg/v4/payment/request.json
 *   POST https://payment.zarinpal.com/pg/v4/payment/verify.json
 *   POST https://payment.zarinpal.com/pg/v4/payment/inquiry.json
 *   GET  https://www.zarinpal.com/pg/StartPay/{authority}
 * Amounts are sent in Rial as integers; Zarinpal returns code 100 on success and 101 when a
 * transaction was already verified.
 */
final class ZarinpalGateway implements GatewayInterface {

	private const LIVE_BASE    = 'https://payment.zarinpal.com/pg/v4/payment/';
	private const SANDBOX_BASE = 'https://sandbox.zarinpal.com/pg/v4/payment/';
	private const LIVE_START    = 'https://www.zarinpal.com/pg/StartPay/';
	private const SANDBOX_START = 'https://sandbox.zarinpal.com/pg/StartPay/';

	public function __construct( private Http $http ) {}

	public function id(): string {
		return 'zarinpal';
	}

	public function title(): string {
		return __( 'Zarinpal', 'igbz-suite' );
	}

	public function required_settings(): array {
		return [ 'payments.zarinpal.merchant_id' ];
	}

	public function is_configured(): bool {
		return 36 === strlen( igbz()->settings()->string( 'payments.zarinpal.merchant_id' ) );
	}

	private function sandbox(): bool {
		return igbz()->settings()->bool( 'payments.zarinpal.sandbox', false );
	}

	private function base(): string {
		return $this->sandbox() ? self::SANDBOX_BASE : self::LIVE_BASE;
	}

	private function start(): string {
		return $this->sandbox() ? self::SANDBOX_START : self::LIVE_START;
	}

	/** Zarinpal settles in Rial; a Toman-priced store converts with payments.currency_multiplier. */
	private function to_rial( float $amount ): int {
		return Money::to_rial( $amount );
	}

	public function request( float $amount, string $callback_url, array $context = [] ): PaymentRequestResult {
		if ( ! $this->is_configured() ) {
			return PaymentRequestResult::failure( 'not_configured', __( 'Zarinpal merchant id is missing or invalid.', 'igbz-suite' ) );
		}

		$payload = [
			'merchant_id'  => igbz()->settings()->required( 'payments.zarinpal.merchant_id' ),
			'amount'       => $this->to_rial( $amount ),
			'callback_url' => $callback_url,
			'description'  => mb_substr( (string) ( $context['description'] ?? __( 'Online payment', 'igbz-suite' ) ), 0, 255 ),
		];

		$metadata = array_filter(
			[
				'mobile' => (string) ( $context['mobile'] ?? '' ),
				'email'  => (string) ( $context['email'] ?? '' ),
			]
		);
		if ( $metadata ) {
			$payload['metadata'] = $metadata;
		}

		$response = $this->http->post(
			$this->base() . 'request.json',
			[
				'json'    => $payload,
				'headers' => [ 'Accept' => 'application/json' ],
				'channel' => 'payments',
				'timeout' => PspHttp::timeout(),
			]
		);

		$body = $response->json();
		$data = is_array( $body['data'] ?? null ) ? $body['data'] : [];

		if ( 100 === (int) ( $data['code'] ?? 0 ) && ! empty( $data['authority'] ) ) {
			$authority = (string) $data['authority'];
			return PaymentRequestResult::ok( $authority, $this->start() . $authority );
		}

		// Phase 30: a transport timeout must read as transient (`network_timeout`), not as a
		// permanent PSP rejection — the shared classifier keeps the provider's own code otherwise.
		[ $code, $message ] = GatewayErrors::classify(
			$response->ok(),
			$response->error_message(),
			$body,
			(string) $this->first_error_code( $body ),
			$this->first_error_message( $body ),
			'request_failed',
			__( 'Zarinpal rejected the payment request.', 'igbz-suite' )
		);
		return PaymentRequestResult::failure( $code, $message );
	}

	public function verify( float $amount, array $callback_params ): PaymentVerifyResult {
		$authority = (string) ( $callback_params['Authority'] ?? $callback_params['authority'] ?? '' );
		$status    = strtoupper( (string) ( $callback_params['Status'] ?? $callback_params['status'] ?? '' ) );

		if ( '' === $authority ) {
			return PaymentVerifyResult::failure( 'missing_authority', __( 'Missing payment authority.', 'igbz-suite' ) );
		}
		if ( 'OK' !== $status ) {
			return PaymentVerifyResult::failure( 'cancelled', __( 'The payment was cancelled by the user.', 'igbz-suite' ) );
		}

		$response = $this->http->post(
			$this->base() . 'verify.json',
			[
				'json'    => [
					'merchant_id' => igbz()->settings()->required( 'payments.zarinpal.merchant_id' ),
					'amount'      => $this->to_rial( $amount ),
					'authority'   => $authority,
				],
				'headers' => [ 'Accept' => 'application/json' ],
				'channel' => 'payments',
				'timeout' => PspHttp::timeout(),
			]
		);

		$body = $response->json();
		$data = is_array( $body['data'] ?? null ) ? $body['data'] : [];
		$code = (int) ( $data['code'] ?? 0 );

		if ( 100 === $code ) {
			return PaymentVerifyResult::ok(
				(string) ( $data['ref_id'] ?? '' ),
				(string) ( $data['card_pan'] ?? '' ),
				(float) ( $data['fee'] ?? 0 )
			);
		}
		if ( 101 === $code ) {
			return PaymentVerifyResult::duplicate( (string) ( $data['ref_id'] ?? '' ) );
		}

		[ $err_code, $err_message ] = GatewayErrors::classify(
			$response->ok(),
			$response->error_message(),
			$body,
			(string) ( $this->first_error_code( $body ) ?: $code ),
			$this->first_error_message( $body ),
			'verify_failed',
			__( 'Zarinpal could not verify this payment.', 'igbz-suite' )
		);
		return PaymentVerifyResult::failure( $err_code, $err_message );
	}

	/** @param array<string,mixed> $body */
	private function first_error_code( array $body ): string {
		$errors = $body['errors'] ?? [];
		if ( is_array( $errors ) && isset( $errors['code'] ) ) {
			return (string) $errors['code'];
		}
		if ( is_array( $errors ) && isset( $errors[0]['code'] ) ) {
			return (string) $errors[0]['code'];
		}
		return '';
	}

	/** @param array<string,mixed> $body */
	private function first_error_message( array $body ): string {
		$errors = $body['errors'] ?? [];
		if ( is_array( $errors ) && isset( $errors['message'] ) ) {
			return (string) $errors['message'];
		}
		if ( is_array( $errors ) && isset( $errors[0]['message'] ) ) {
			return (string) $errors[0]['message'];
		}
		return '';
	}
}
