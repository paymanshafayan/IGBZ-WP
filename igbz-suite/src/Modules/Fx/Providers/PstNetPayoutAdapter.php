<?php
namespace IGBZ\Suite\Modules\Fx\Providers;

use IGBZ\Suite\Modules\Fx\Contracts\FxPayoutAdapterInterface;
use IGBZ\Suite\Support\Http;
use IGBZ\Suite\Support\Logger;
use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * PST.NET payout adapter — the operator's virtual-card provider.
 *
 * PST.NET is chosen as the primary adapter because it is the only option with
 * a real, documented developer API covering exactly what automatic settlement
 * needs: issuing a card, reading the card number/CVV, topping up with USDT and
 * checking the balance. Cards carry their own BINs, so AI subscriptions
 * (the social provider, AI subscriptions) accept them.
 *
 * The company running this product has a registered entity in Northern Cyprus,
 * so the cards are held and paid by that entity — the compliance layer is
 * theirs, not a grey-market workaround.
 *
 * Endpoints are configurable (fx.pstnet_base_url) because the sandbox and
 * production hosts differ; the sandbox default is what the public docs use.
 */

final class PstNetPayoutAdapter implements FxPayoutAdapterInterface {

	private const DEFAULT_BASE = 'https://api.pst.net';

	public function __construct(
		private Settings $settings,
		private Http $http,
		private Logger $logger
	) {}

	public function id(): string {
		return 'pstnet';
	}

	public function title(): string {
		return 'PST.NET';
	}

	public function is_configured(): bool {
		return '' !== trim( $this->settings->string( 'fx.pstnet_api_key', '' ) )
			&& '' !== trim( $this->settings->string( 'fx.pstnet_card_id', '' ) );
	}

	/**
	 * Pay one bill by charging the tenant's card at PST.NET.
	 *
	 * This is the automatic path: the adapter asks PST to charge the card that
	 * pays the provider subscription. `reference` is the PST transaction
	 * id so the bill row can be reconciled later.
	 *
	 * @param array<string,mixed> $bill
	 * @return array{ok:bool,reference:string,error:string}
	 */
	public function pay( array $bill ): array {
		if ( ! $this->is_configured() ) {
			return [ 'ok' => false, 'reference' => '', 'error' => 'pstnet_not_configured' ];
		}

		$response = $this->http->post(
			$this->base() . '/cards/' . rawurlencode( $this->card_id() ) . '/charge',
			[
				'headers' => $this->headers(),
				'body'    => wp_json_encode(
					[
						'amount'   => (float) $bill['amount_usd'],
						'currency' => 'USD',
						'metadata' => [ 'igbz_bill_id' => (int) $bill['id'] ],
					]
				),
			]
		);

		if ( ! $response->ok() ) {
			$this->logger->error( 'fx', 'PST.NET charge failed', [ 'bill_id' => (int) $bill['id'], 'status' => $response->status, 'error' => $response->error_message() ] );

			return [ 'ok' => false, 'reference' => '', 'error' => $response->error_message() ];
		}

		$data = $response->json();
		$ref  = (string) ( $data['id'] ?? $data['transaction_id'] ?? $data['reference'] ?? '' );

		$this->logger->info( 'fx', 'PST.NET charge accepted', [ 'bill_id' => (int) $bill['id'], 'reference' => $ref ] );

		return [ 'ok' => true, 'reference' => $ref, 'error' => '' ];
	}

	public function card_balance(): float {
		if ( ! $this->is_configured() ) {
			return 0.0;
		}

		$response = $this->http->get(
			$this->base() . '/cards/' . rawurlencode( $this->card_id() ),
			[ 'headers' => $this->headers() ]
		);
		if ( ! $response->ok() ) {
			return 0.0;
		}

		$data = $response->json();

		return (float) ( $data['balance'] ?? $data['available_balance'] ?? 0 );
	}

	public function webhook( array $payload ): void {
		// A charge confirmation or a top-up notification. The bill was already
		// marked paid when the charge call succeeded; a webhook here is mostly
		// for reconciliation and logging.
		$bill_id = (int) ( $payload['metadata']['igbz_bill_id'] ?? $payload['bill_id'] ?? 0 );
		$this->logger->info( 'fx', 'PST.NET webhook', [ 'bill_id' => $bill_id, 'event' => $payload['event'] ?? 'unknown' ] );
	}

	private function base(): string {
		return rtrim( $this->settings->string( 'fx.pstnet_base_url', self::DEFAULT_BASE ), '/' );
	}

	private function card_id(): string {
		return (string) $this->settings->string( 'fx.pstnet_card_id', '' );
	}

	/** @return array<string,string> */
	private function headers(): array {
		return [
			'Authorization' => 'Bearer ' . $this->settings->string( 'fx.pstnet_api_key', '' ),
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		];
	}
}
