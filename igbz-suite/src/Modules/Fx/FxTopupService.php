<?php
namespace IGBZ\Suite\Modules\Fx;

use IGBZ\Suite\Modules\MultiTenant\Payments\PaymentService;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;
use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Rial top-ups for the FX wallet.
 *
 * The store admin never touches a foreign card. They request a USD amount;
 * FxMath adds the operator's fee (fx.fee_percent, default 10) on top, the
 * current locked rate converts it to Rials, and PaymentService runs the
 * existing Iranian gateways with purpose = fx_topup. Credit is granted on
 * `igbz_payment_verified` — the same hook the VIP channel settles on — so
 * PaymentService::settle() itself is untouched.
 */
final class FxTopupService {

	public const PURPOSE = 'fx_topup';

	public function __construct(
		private Db $db,
		private Settings $settings,
		private PaymentService $payments,
		private FxWalletService $wallet,
		private FxRateService $rates,
		private Logger $logger
	) {}

	/** @return array{ok:bool,payment_id:int,redirect_url:string,error:string,amount_irt:float,gross_usd:float,net_usd:float} */
	public function start( int $tenant_id, int $user_id, float $usd_requested, string $gateway_id = '' ): array {
		// Phase 35: lock FIRST, then price from the locked row. The previous order quoted from the
		// live rate and locked afterwards — between those two reads the market could move and the
		// buyer would pay a price no locked rate ever justified. Now the quoted price and the
		// locked row are the same number by construction, and a missing rate refuses the top-up
		// instead of pricing it at zero.
		$rate_id = $this->rates->lock_rate();
		$locked  = $rate_id > 0 ? $this->rates->locked_rate( $rate_id ) : null;

		if ( null === $locked || (float) $locked['rate_applied'] <= 0 ) {
			return [
				'ok' => false, 'payment_id' => 0, 'redirect_url' => '', 'error' => __( 'FX is not priced yet: set the exchange rate in IGBZ → Settings → FX payments.', 'igbz-suite' ),
				'amount_irt' => 0, 'gross_usd' => 0, 'net_usd' => 0,
			];
		}

		$quote = FxMath::quote(
			$usd_requested,
			(float) $this->settings->float( 'fx.fee_percent', 10 ),
			(float) $locked['rate_applied']
		);

		$result = $this->payments->start(
			(float) $quote['amount_irt'],
			self::PURPOSE,
			[
				'tenant_id'   => $tenant_id,
				'user_id'     => $user_id,
				'fx_net_usd'  => $quote['net_usd'],
				'fx_gross_usd' => $quote['gross_usd'],
				'fx_fee_usd'  => $quote['fee_usd'],
				'fx_rate_id'  => $rate_id,
			],
			$gateway_id
		);

		return [
			'ok'          => $result['ok'],
			'payment_id'  => $result['payment_id'],
			'redirect_url' => $result['redirect_url'],
			'error'       => $result['error'],
			'amount_irt'  => (float) $quote['amount_irt'],
			'gross_usd'   => $quote['gross_usd'],
			'net_usd'     => $quote['net_usd'],
		];
	}

	/**
	 * Grant the credit. Hooked on `igbz_payment_verified`; idempotent because
	 * FxWalletService::credit() refuses a second write with the same
	 * (reason, reference).
	 */
	public function on_payment_verified( int $payment_id, $result = null ): void {
		$payment = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'payments' ) . ' WHERE id = %d',
			$payment_id
		);
		if ( ! $payment || self::PURPOSE !== (string) $payment['purpose'] ) {
			return;
		}

		$meta = json_decode( (string) ( $payment['meta'] ?? '{}' ), true );
		$meta = is_array( $meta ) ? $meta : [];

		$net_usd = (float) ( $meta['fx_net_usd'] ?? 0 );
		if ( $net_usd <= 0 ) {
			$this->logger->error( 'fx', 'Top-up verified without a net USD amount', [ 'payment_id' => $payment_id ] );
			return;
		}

		$this->wallet->credit(
			(int) $payment['tenant_id'],
			$net_usd,
			FxWalletService::REASON_TOPUP,
			'payment:' . (int) $payment['id'],
			[
				'gross_usd' => (float) ( $meta['fx_gross_usd'] ?? $net_usd ),
				'fee_usd'   => (float) ( $meta['fx_fee_usd'] ?? 0 ),
				'rate_id'   => (int) ( $meta['fx_rate_id'] ?? 0 ),
				'gateway'   => (string) $payment['gateway'],
				'ref_id'    => $result instanceof \IGBZ\Suite\Modules\MultiTenant\Payments\PaymentVerifyResult ? (string) $result->reference_id : '',
			],
			(int) $payment['user_id'],
			(float) $payment['amount'],
			(int) ( $meta['fx_rate_id'] ?? 0 )
		);

		$this->logger->info( 'fx', 'FX wallet credited', [ 'payment_id' => $payment_id, 'tenant_id' => (int) $payment['tenant_id'], 'net_usd' => $net_usd ] );
	}
}
