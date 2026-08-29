<?php
namespace IGBZ\Suite\Modules\Fx\Providers;

use IGBZ\Suite\Modules\Fx\Contracts\FxRampInterface;
use IGBZ\Suite\Support\Http;
use IGBZ\Suite\Support\Logger;
use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Generic Iranian-exchange ramp adapter.
 *
 * Every Iranian exchange exposes roughly the same three operations — read a
 * price, place a buy order, request a withdrawal — behind different URLs and
 * auth conventions, so the whole adapter is configuration-driven (the same
 * approach as the other config-driven HTTP adapters: base URL, price/buy/withdraw paths, the JSON
 * path to the price, and the auth header scheme. The defaults match Nobitex.
 *
 * The buy and withdraw requests are emitted with a `match`able reference in
 * the body so tests and the operator can trace them; a withdrawal that needs
 * OTP confirmation is reported as `ok=false` with the exchange's message and
 * the operator confirms it in the exchange app.
 */
final class HttpRampAdapter implements FxRampInterface {

	public function __construct(
		private Settings $settings,
		private Http $http,
		private Logger $logger
	) {}

	public function id(): string {
		return 'http';
	}

	public function title(): string {
		return 'Iranian exchange (HTTP)';
	}

	public function is_configured(): bool {
		return '' !== trim( $this->settings->string( 'fx.ramp_api_key', '' ) )
			&& '' !== trim( $this->settings->string( 'fx.ramp_base_url', '' ) );
	}

	public function usdt_price(): float {
		if ( ! $this->is_configured() ) {
			return 0.0;
		}

		$response = $this->http->get(
			$this->base() . $this->settings->string( 'fx.ramp_price_path', '/v2/otc/price' )
				. '?srcCurrency=usdt&dstCurrency=rls',
			[ 'headers' => $this->headers() ]
		);
		if ( ! $response->ok() ) {
			return 0.0;
		}

		$data = $response->json();
		$path = trim( $this->settings->string( 'fx.ramp_price_json_path', 'price' ) );

		$value = $data;
		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return 0.0;
			}
			$value = $value[ $segment ];
		}

		return (float) $value;
	}

	public function buy( float $amount_irt, string $reference ): array {
		if ( ! $this->is_configured() ) {
			return [ 'ok' => false, 'usdt_amount' => 0.0, 'reference' => '', 'error' => 'ramp_not_configured' ];
		}

		$price = $this->usdt_price();
		if ( $price <= 0 ) {
			return [ 'ok' => false, 'usdt_amount' => 0.0, 'reference' => '', 'error' => 'ramp_unpriced' ];
		}

		$response = $this->http->post(
			$this->base() . $this->settings->string( 'fx.ramp_buy_path', '/v2/otc/orders/create' ),
			[
				'headers' => $this->headers(),
				'body'    => wp_json_encode(
					[
						'type'        => 'buy',
						'srcCurrency' => 'usdt',
						'dstCurrency' => 'rls',
						'amount'      => round( $amount_irt, 0 ),
						'price'       => $price,
						'metadata'    => [ 'igbz_ref' => $reference ],
					]
				),
			]
		);

		if ( ! $response->ok() ) {
			$this->logger->error( 'fx', 'Ramp buy failed', [ 'reference' => $reference, 'status' => $response->status, 'error' => $response->error_message() ] );

			return [ 'ok' => false, 'usdt_amount' => 0.0, 'reference' => '', 'error' => $response->error_message() ];
		}

		$data = $response->json();
		$usdt = (float) ( $data['usdtAmount'] ?? $data['amount'] ?? 0 );
		$ref  = (string) ( $data['order']['id'] ?? $data['order_id'] ?? $data['id'] ?? '' );

		$this->logger->info( 'fx', 'Ramp buy placed', [ 'reference' => $reference, 'usdt' => $usdt, 'order' => $ref ] );

		return [ 'ok' => true, 'usdt_amount' => $usdt, 'reference' => $ref, 'error' => '' ];
	}

	public function withdraw( float $usdt_amount, string $address, string $reference ): array {
		if ( ! $this->is_configured() ) {
			return [ 'ok' => false, 'reference' => '', 'error' => 'ramp_not_configured' ];
		}
		if ( '' === trim( $address ) ) {
			return [ 'ok' => false, 'reference' => '', 'error' => 'no_deposit_address' ];
		}

		$response = $this->http->post(
			$this->base() . $this->settings->string( 'fx.ramp_withdraw_path', '/v2/profile/wallets/withdraw' ),
			[
				'headers' => $this->headers(),
				'body'    => wp_json_encode(
					[
						'currency' => 'USDT',
						'network'  => 'TRX',
						'address'  => $address,
						'amount'   => $usdt_amount,
						'metadata' => [ 'igbz_ref' => $reference ],
					]
				),
			]
		);

		if ( ! $response->ok() ) {
			$this->logger->warning( 'fx', 'Ramp withdrawal needs attention', [ 'reference' => $reference, 'status' => $response->status, 'error' => $response->error_message() ] );

			return [ 'ok' => false, 'reference' => '', 'error' => $response->error_message() ];
		}

		$data = $response->json();
		$ref  = (string) ( $data['withdrawId'] ?? $data['id'] ?? $data['reference'] ?? '' );

		$this->logger->info( 'fx', 'Ramp withdrawal requested', [ 'reference' => $reference, 'withdraw' => $ref ] );

		return [ 'ok' => true, 'reference' => $ref, 'error' => '' ];
	}

	private function base(): string {
		return rtrim( $this->settings->string( 'fx.ramp_base_url', 'https://api.nobitex.ir' ), '/' );
	}

	/** @return array<string,string> */
	private function headers(): array {
		$scheme = $this->settings->string( 'fx.ramp_auth_scheme', 'Token' );

		return [
			'Authorization' => ( '' === $scheme ? '' : $scheme . ' ' ) . $this->settings->string( 'fx.ramp_api_key', '' ),
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		];
	}
}
