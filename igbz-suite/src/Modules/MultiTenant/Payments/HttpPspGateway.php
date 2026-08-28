<?php
namespace IGBZ\Suite\Modules\MultiTenant\Payments;

use IGBZ\Suite\Support\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Config-driven PSP gateway for the banks the fixed four do not cover.
 *
 * Iranian banks and PSP aggregators (Mellat, Saman, Parsian, Pasargad, ...)
 * all follow the same shape — send a payment request, redirect the buyer to
 * the bank page, verify the callback — but differ in URL, auth header and
 * JSON field names. Rather than one class per bank, this gateway is driven
 * by settings: base URL, send/verify paths, auth scheme, and dotted JSON
 * paths for the response fields. It registers as `payments.httppsp` and is
 * configured on the Payments settings tab (endpoint/fields documented there).
 */
final class HttpPspGateway implements GatewayInterface, RefundableGatewayInterface {

	public function __construct( private Http $http ) {}

	public function id(): string {
		return 'httppsp';
	}

	public function title(): string {
		return __( 'HTTP PSP (configurable)', 'igbz-suite' );
	}

	public function required_settings(): array {
		return [ 'payments.httppsp.api_key', 'payments.httppsp.send_url', 'payments.httppsp.verify_url' ];
	}

	public function is_configured(): bool {
		return '' !== $this->api_key() && '' !== $this->send_url();
	}

	public function request( float $amount, string $callback_url, array $context = [] ): PaymentRequestResult {
		if ( ! $this->is_configured() ) {
			return PaymentRequestResult::failure( 'not_configured', __( 'The HTTP PSP gateway is not configured.', 'igbz-suite' ) );
		}

		$rial = Money::to_rial( $amount );

		// Phase 30: when the caller supplies an idempotency key it rides along both in the body
		// and as a header, so PSPs that deduplicate creation honour it; ones that ignore it do no harm.
		$idempotency_key = (string) ( $context['idempotency_key'] ?? '' );

		$payload = array_filter(
			[
				'api_key'     => $this->api_key(),
				'amount'      => $rial,
				'amount_rial' => $rial,
				'callback'    => $callback_url,
				'callback_url' => $callback_url,
				'mobile'      => (string) ( $context['mobile'] ?? '' ),
				'order_id'    => (string) ( $context['order_id'] ?? '' ),
				'description' => mb_substr( (string) ( $context['description'] ?? '' ), 0, 255 ),
				'idempotency_key' => $idempotency_key,
			],
			static fn ( $v ) => '' !== $v && null !== $v
		);

		$headers = $this->headers();
		if ( '' !== $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}

		$response = $this->http->post( $this->send_url(), [ 'json' => $payload, 'headers' => $headers, 'channel' => 'payments', 'timeout' => PspHttp::timeout() ] );
		$body     = $response->json();

		$token = $this->field( $body, 'token' );
		$url   = $this->field( $body, 'redirect_url' );
		if ( '' !== $token && '' !== $url ) {
			return PaymentRequestResult::ok( $token, $url );
		}
		if ( '' !== $token && '' === $url && $this->redirect_base() ) {
			return PaymentRequestResult::ok( $token, rtrim( $this->redirect_base(), '/' ) . '/' . rawurlencode( $token ) );
		}

		[ $code, $message ] = GatewayErrors::classify(
			$response->ok(),
			$response->error_message(),
			$body,
			(string) ( $body['error_code'] ?? $body['status'] ?? '' ),
			(string) ( $body['error_message'] ?? $body['message'] ?? '' ),
			'request_failed',
			__( 'The PSP rejected the payment request.', 'igbz-suite' )
		);
		return PaymentRequestResult::failure( $code, $message );
	}

	public function verify( float $amount, array $callback_params ): PaymentVerifyResult {
		$token = (string) ( $callback_params['token'] ?? $callback_params['authority'] ?? '' );
		if ( '' === $token ) {
			return PaymentVerifyResult::failure( 'missing_params', __( 'The PSP did not return a token.', 'igbz-suite' ) );
		}

		if ( ! $this->verify_url() ) {
			// Some PSPs confirm synchronously in the callback (status field).
			$status = (string) ( $callback_params['status'] ?? '' );
			if ( in_array( strtolower( $status ), [ 'ok', 'success', '1', 'paid' ], true ) ) {
				return PaymentVerifyResult::ok( $token, (string) ( $callback_params['card_pan'] ?? '' ), 0.0 );
			}
			return PaymentVerifyResult::failure( 'cancelled', __( 'The payment was not confirmed.', 'igbz-suite' ) );
		}

		$response = $this->http->post(
			$this->verify_url(),
			[ 'json' => [ 'api_key' => $this->api_key(), 'token' => $token, 'amount' => Money::to_rial( $amount ) ], 'headers' => $this->headers(), 'channel' => 'payments', 'timeout' => PspHttp::timeout() ]
		);
		$body = $response->json();

		$confirmed = (float) $this->field( $body, 'amount' );
		$expected  = Money::to_rial( $amount );
		if ( $confirmed > 0 && abs( $confirmed - $expected ) > 1 ) {
			return PaymentVerifyResult::failure( 'amount_mismatch', __( 'The PSP confirmed a different amount.', 'igbz-suite' ) );
		}

		$status = (string) $this->field( $body, 'status' );
		if ( in_array( strtolower( $status ), [ 'ok', 'success', '1', 'paid', 'done' ], true ) || '' !== (string) $this->field( $body, 'ref_id' ) ) {
			return PaymentVerifyResult::ok( (string) $this->field( $body, 'ref_id' ) ?: $token, (string) $this->field( $body, 'card_pan' ), 0.0 );
		}

		[ $code, $message ] = GatewayErrors::classify(
			$response->ok(),
			$response->error_message(),
			$body,
			(string) ( $body['error_code'] ?? '' ),
			(string) ( $body['error_message'] ?? '' ),
			'verify_failed',
			__( 'The PSP could not verify this payment.', 'igbz-suite' )
		);
		return PaymentVerifyResult::failure( $code, $message );
	}

	/**
	 * Phase 30: config-driven refund — only active when the merchant configures a refund URL,
	 * i.e. their PSP actually exposes one. The idempotency key is mandatory: a replayed refund
	 * with the same key must never move money twice.
	 */
	public function refund( string $reference_id, float $amount, array $context = [] ): PaymentRefundResult {
		$url = $this->refund_url();
		if ( '' === $url || '' === $reference_id || $amount <= 0 ) {
			return PaymentRefundResult::failure(
				GatewayErrors::NOT_CONFIGURED,
				__( 'This PSP has no configured refund endpoint.', 'igbz-suite' )
			);
		}

		$idempotency_key = (string) ( $context['idempotency_key'] ?? '' );
		$headers         = $this->headers();
		if ( '' !== $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}

		$payload = array_filter(
			[
				'api_key'         => $this->api_key(),
				'reference_id'    => $reference_id,
				'token'           => $reference_id,
				'amount'          => Money::to_rial( $amount ),
				'idempotency_key' => $idempotency_key,
			],
			static fn ( $v ) => '' !== $v && null !== $v
		);

		$response = $this->http->post( $url, [ 'json' => $payload, 'headers' => $headers, 'channel' => 'payments', 'timeout' => PspHttp::timeout() ] );
		$body     = $response->json();

		$ref = (string) $this->field( $body, 'refund_ref' );
		if ( '' === $ref ) {
			$ref = (string) $this->field( $body, 'ref_id' );
		}
		if ( $response->ok() && '' !== $ref ) {
			return PaymentRefundResult::ok( $ref );
		}

		[ $code, $message ] = GatewayErrors::classify(
			$response->ok(),
			$response->error_message(),
			$body,
			(string) ( $body['error_code'] ?? '' ),
			(string) ( $body['error_message'] ?? '' ),
			'refund_failed',
			__( 'The PSP refused the refund.', 'igbz-suite' )
		);
		return PaymentRefundResult::failure( $code, $message );
	}

	private function api_key(): string {
		return igbz()->settings()->string( 'payments.httppsp.api_key' );
	}

	private function send_url(): string {
		return igbz()->settings()->string( 'payments.httppsp.send_url' );
	}

	private function verify_url(): string {
		return igbz()->settings()->string( 'payments.httppsp.verify_url' );
	}

	private function redirect_base(): string {
		return igbz()->settings()->string( 'payments.httppsp.redirect_base' );
	}

	private function refund_url(): string {
		return igbz()->settings()->string( 'payments.httppsp.refund_url' );
	}

	/** @return array<string,string> */
	private function headers(): array {
		$scheme = igbz()->settings()->string( 'payments.httppsp.auth_scheme', 'Bearer' );
		return [
			'Authorization' => ( '' === $scheme ? '' : $scheme . ' ' ) . $this->api_key(),
			'Accept'        => 'application/json',
		];
	}

	/**
	 * Read a field from the response, honouring a configured dotted path
	 * (payments.httppsp.field_token / field_redirect / field_status ...).
	 *
	 * @param array<string,mixed> $body
	 */
	private function field( array $body, string $what ): string {
		$path = igbz()->settings()->string( 'payments.httppsp.field_' . $what, '' );
		if ( '' === $path ) {
			$value = $body[ $what ] ?? '';
			return is_scalar( $value ) ? (string) $value : '';
		}
		$value = $body;
		foreach ( explode( '.', $path ) as $seg ) {
			if ( ! is_array( $value ) || ! array_key_exists( $seg, $value ) ) {
				return '';
			}
			$value = $value[ $seg ];
		}
		return is_scalar( $value ) ? (string) $value : '';
	}
}
