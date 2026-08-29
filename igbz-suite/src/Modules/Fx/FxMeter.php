<?php
namespace IGBZ\Suite\Modules\Fx;

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Per-task credit gate for the FX module.
 *
 * The client's hard constraint: FX must never queue a social task. The gate
 * only checks the tenant's own credit at dispatch time — enough credit and
 * the task goes out immediately, not enough and it is refused on the spot
 * with a "top up" message. There is no cross-tenant queue and no debt.
 *
 * The price comes from fx_prices (seeded, editable by the operator). A spent
 * task the provider never accepted is refunded via release(), which returns
 * the exact amount that was debited (read back from the ledger) so a price
 * change in between cannot short-change the tenant.
 */
final class FxMeter {

	public function __construct(
		private Db $db,
		private FxWalletService $wallet,
		private Logger $logger
	) {}

	/** Price of one unit of a service, or null when unpriced/inactive. */
	public function price( string $service ): ?float {
		$row = $this->db->row(
			'SELECT price_usd FROM ' . $this->db->table( 'fx_prices' ) . '
			 WHERE service = %s AND is_active = 1 ORDER BY id DESC LIMIT 1',
			$service
		);

		return $row ? (float) $row['price_usd'] : null;
	}

	/**
	 * @return array{ok:bool,balance:float,error:string}
	 */
	public function consume( int $tenant_id, string $service, string $reference ): array {
		$price = $this->price( $service );
		if ( null === $price || $price <= 0 ) {
			return [ 'ok' => false, 'balance' => $this->wallet->balance( $tenant_id )['balance_usd'], 'error' => 'unpriced' ];
		}

		$result = $this->wallet->debit(
			$tenant_id,
			$price,
			FxWalletService::REASON_TASK,
			$reference,
			[ 'service' => $service, 'price_usd' => $price ]
		);

		if ( ! $result['ok'] ) {
			$this->logger->warning(
				'fx',
				'Task refused: insufficient FX credit',
				[ 'tenant_id' => $tenant_id, 'service' => $service, 'error' => $result['error'] ]
			);
		}

		return $result;
	}

	/**
	 * Charge for a delivered direct message. Uses its own (reason, reference)
	 * pair so it is idempotent per funnel hit: settle() is the single writer
	 * of a delivery, and this runs once per transition into delivered = 1.
	 */
	public function charge_delivery( int $tenant_id, string $reference, string $service = 'dm_delivery' ): array {
		return $this->consume( $tenant_id, $service, $reference );
	}

	/**
	 * Refund a task the provider never accepted. Idempotent: the refund row uses its
	 * own (reason, reference) pair, so a double release credits once.
	 */
	public function release( int $tenant_id, string $service, string $reference ): void {
		$spent = $this->db->scalar(
			'SELECT amount_usd FROM ' . $this->db->table( 'fx_ledger' ) . '
			 WHERE tenant_id = %d AND reason = %s AND reference = %s LIMIT 1',
			$tenant_id,
			FxWalletService::REASON_TASK,
			$reference
		);

		$amount = (float) $spent;
		if ( $amount >= 0 ) {
			return;
		}

		$this->wallet->credit(
			$tenant_id,
			-1 * $amount,
			FxWalletService::REASON_REFUND,
			'refund:' . $reference,
			[ 'service' => $service ]
		);
	}
}
