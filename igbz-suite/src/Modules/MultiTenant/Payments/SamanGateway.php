<?php
namespace IGBZ\Suite\Modules\MultiTenant\Payments;

defined( 'ABSPATH' ) || exit;

use IGBZ\Suite\Support\Http;

/**
 * Saman (SEP) direct gateway — REST with 3-key RSA encryption.
 *
 *   Request: POST https://sep.shaparak.ir/onlinepg/onlinepg
 *     form: Token = RSA_encrypt(TerminalId;OrderId;Amount;RedirectAddress)
 *     -> { token } ; redirect https://sep.shaparak.ir/payment.aspx?Token=
 *   Verify: POST /verifyTxnRandomSessionkey/Execute
 *     { Token } with RSA signature -> { Success:true, RefNum, ... }
 */
final class SamanGateway extends AbstractIpgGateway {

	private const TOKEN_URL   = 'https://sep.shaparak.ir/onlinepg/onlinepg';
	private const VERIFY_URL  = 'https://sep.shaparak.ir/verifyTxnRandomSessionkey/Execute';
	private const START_URL   = 'https://sep.shaparak.ir/payment.aspx?Token=';

	public function __construct( Http $http ) {
		parent::__construct( $http, 'payments.saman' );
	}

	public function id(): string {
		return 'saman';
	}

	public function title(): string {
		return __( 'Saman (SEP)', 'igbz-suite' );
	}

	public function required_settings(): array {
		return [ 'payments.saman.terminal_id', 'payments.saman.public_key', 'payments.saman.private_key' ];
	}

	private function encrypt_token( int $amount, string $order_id, string $callback ): string {
		$plain = implode( ';', [ $this->cfg( 'terminal_id' ), $order_id, $amount, $callback ] );
		$enc   = '';
		$key   = openssl_pkey_get_public( $this->cfg( 'public_key' ) );
		if ( $key && openssl_public_encrypt( $plain, $enc, $key, OPENSSL_PKCS1_PADDING ) ) {
			return base64_encode( $enc );
		}
		return '';
	}

	public function request( float $amount, string $callback_url, array $context = [] ): PaymentRequestResult {
		$token = $this->encrypt_token( Money::to_rial( $amount ), (string) ( $context['order_id'] ?? 'ORD-' . time() ), $callback_url );
		if ( '' === $token ) {
			return PaymentRequestResult::failure( 'encrypt_failed', __( 'Saman encryption failed — check the RSA public key.', 'igbz-suite' ) );
		}

		$response = $this->http->post( self::TOKEN_URL, [ 'body' => 'Token=' . rawurlencode( $token ), 'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ], 'channel' => 'payments', 'timeout' => PspHttp::timeout() ] );
		$body     = $response->json();
		$tok      = (string) ( $body['token'] ?? $body['Token'] ?? '' );

		if ( $response->ok() && '' !== $tok ) {
			return PaymentRequestResult::ok( $tok, self::START_URL . rawurlencode( $tok ) );
		}

		return PaymentRequestResult::failure( 'saman_failed', (string) ( $body['status'] ?? __( 'Saman rejected the request.', 'igbz-suite' ) ) );
	}

	public function verify( float $amount, array $callback_params ): PaymentVerifyResult {
		$tok = (string) ( $callback_params['Token'] ?? $callback_params['token'] ?? '' );
		if ( '' === $tok ) {
			return PaymentVerifyResult::failure( 'missing_token', __( 'Saman did not return a token.', 'igbz-suite' ) );
		}

		$plain = implode( ';', [ $tok, $this->cfg( 'terminal_id' ) ] );
		$sign  = '';
		$key   = openssl_pkey_get_private( $this->cfg( 'private_key' ) );
		if ( $key && openssl_sign( $plain, $sign, $key, OPENSSL_ALGO_SHA1 ) ) {
			$sign = base64_encode( $sign );
		}

		$response = $this->http->post(
			self::VERIFY_URL,
			[ 'json' => [ 'Token' => $tok, 'SignData' => $sign ], 'headers' => [ 'Accept' => 'application/json' ], 'channel' => 'payments', 'timeout' => PspHttp::timeout() ]
		);
		$body = $response->json();

		if ( $response->ok() && (bool) ( $body['Success'] ?? false ) ) {
			return PaymentVerifyResult::ok( (string) ( $body['RefNum'] ?? $tok ), (string) ( $body['SecurePan'] ?? '' ), 0.0 );
		}

		return PaymentVerifyResult::failure( 'verify_failed', (string) ( $body['ErrorDesc'] ?? __( 'Saman could not verify.', 'igbz-suite' ) ) );
	}
}
