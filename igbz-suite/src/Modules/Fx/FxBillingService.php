<?php
namespace IGBZ\Suite\Modules\Fx;

use IGBZ\Suite\Modules\Fx\Contracts\FxPayoutAdapterInterface;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;
use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Monthly bills and automatic settlement (phase 2).
 *
 * For every active fx_account a monthly bill is created on its billing day
 * (default: the 1st) from the fx_prices entry of the matching service, and
 * the daily cron tries to pay due bills through the configured payout
 * adapter. The tenant's wallet must cover the bill; when it does not, the
 * bill stays `due` and the tenant is told to top up — no debt, no queue.
 *
 * Settlement honours the same idempotency discipline as the rest of the FX
 * layer: the ledger entry carries the bill id, so a re-run cannot pay twice,
 * and the payout adapter is asked once per bill.
 */
final class FxBillingService {

	public const STATUS_DUE      = 'due';
	public const STATUS_PAID     = 'paid';
	public const STATUS_UNPAID   = 'unpaid';

	/** @return array<int,array<string,mixed>> */
	public function due_bills( int $limit = 50 ): array {
		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'fx_bills' ) . '
			 WHERE status = %s ORDER BY id ASC LIMIT %d',
			self::STATUS_DUE,
			$limit
		);
	}

	/**
	 * Create the monthly bill for one account if none exists for the period.
	 * Returns the bill id, or 0 when there is nothing to bill.
	 */
	public function create_monthly_bill( array $account ): int {
		$service = $this->service_for( $account );
		if ( '' === $service ) {
			return 0;
		}

		$now    = time();
		$period = gmdate( 'Y-m-01', $now );

		$existing = (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'fx_bills' ) . '
			 WHERE fx_account_id = %d AND period_start = %s',
			(int) $account['id'],
			$period
		);
		if ( $existing > 0 ) {
			return 0;
		}

		$price = $this->meter->price( $service );
		if ( null === $price || $price <= 0 ) {
			return 0;
		}

		$id = $this->db->insert(
			'fx_bills',
			[
				'tenant_id'     => (int) $account['tenant_id'],
				'fx_account_id' => (int) $account['id'],
				'period_start'  => $period,
				'period_end'    => gmdate( 'Y-m-t', $now ),
				'amount_usd'    => $price,
				'status'        => self::STATUS_DUE,
				'created_at'    => current_time( 'mysql', true ),
			]
		);

		return (int) $id;
	}

	/**
	 * Try to pay one due bill: debit the tenant's wallet, then ask the payout
	 * adapter. When the adapter refuses, refund the wallet and leave the bill
	 * `due` for the next run.
	 *
	 * @return array{ok:bool,status:string,error:string}
	 */
	public function settle_bill( array $bill ): array {
		$adapter = $this->payouts->active();
		if ( ! $adapter || ! $adapter->is_configured() ) {
			return [ 'ok' => false, 'status' => self::STATUS_DUE, 'error' => 'no_payout_adapter' ];
		}

		$reference = 'bill:' . (int) $bill['id'];

		$spend = $this->wallet->debit(
			(int) $bill['tenant_id'],
			(float) $bill['amount_usd'],
			FxWalletService::REASON_SUBSCRIPTION,
			$reference,
			[ 'bill_id' => (int) $bill['id'] ]
		);
		if ( ! $spend['ok'] ) {
			$this->db->update(
				'fx_bills',
				[ 'status' => self::STATUS_UNPAID ],
				[ 'id' => (int) $bill['id'] ]
			);

			return [ 'ok' => false, 'status' => self::STATUS_UNPAID, 'error' => $spend['error'] ];
		}

		$result = $adapter->pay( $bill );
		if ( ! $result['ok'] ) {
			$this->wallet->credit(
				(int) $bill['tenant_id'],
				(float) $bill['amount_usd'],
				FxWalletService::REASON_REFUND,
				'refund:' . $reference,
				[ 'bill_id' => (int) $bill['id'], 'payout_error' => $result['error'] ]
			);
			$this->logger->error( 'fx', 'Payout failed, wallet refunded', [ 'bill_id' => (int) $bill['id'], 'error' => $result['error'] ] );

			return [ 'ok' => false, 'status' => self::STATUS_DUE, 'error' => $result['error'] ];
		}

		$this->db->update(
			'fx_bills',
			[
				'status'     => self::STATUS_PAID,
				'paid_at'    => current_time( 'mysql', true ),
				'payout_ref' => mb_substr( (string) ( $result['reference'] ?? '' ), 0, 191 ),
			],
			[ 'id' => (int) $bill['id'] ]
		);

		$this->logger->info( 'fx', 'Bill paid', [ 'bill_id' => (int) $bill['id'], 'tenant_id' => (int) $bill['tenant_id'], 'amount_usd' => (float) $bill['amount_usd'], 'payout_ref' => (string) ( $result['reference'] ?? '' ) ] );

		return [ 'ok' => true, 'status' => self::STATUS_PAID, 'error' => '' ];
	}

	/** The service key a provider maps to for pricing. */
	public function service_for( array $account ): string {
		return 'manus' === (string) ( $account['provider'] ?? '' )
			? 'manus_monthly'
			: ( 'manychat' === (string) ( $account['provider'] ?? '' ) ? 'manychat_monthly' : '' );
	}

	/**
	 * Operator-initiated manual settlement. When both API adapters are down
	 * (or the operator prefers to pay from their own dashboard), this marks a
	 * due bill paid with an explicit `manual:` payout ref, debiting the
	 * tenant's wallet exactly like the automatic path.
	 *
	 * @return array{ok:bool,status:string,error:string}
	 */
	public function settle_bill_manually( array $bill, int $operator_user_id ): array {
		$reference = 'bill:' . (int) $bill['id'];

		$spend = $this->wallet->debit(
			(int) $bill['tenant_id'],
			(float) $bill['amount_usd'],
			FxWalletService::REASON_SUBSCRIPTION,
			$reference,
			[ 'bill_id' => (int) $bill['id'], 'manual' => true, 'operator' => $operator_user_id ]
		);
		if ( ! $spend['ok'] ) {
			return [ 'ok' => false, 'status' => self::STATUS_UNPAID, 'error' => $spend['error'] ];
		}

		$this->db->update(
			'fx_bills',
			[
				'status'     => self::STATUS_PAID,
				'paid_at'    => current_time( 'mysql', true ),
				'payout_ref' => 'manual:' . $operator_user_id,
			],
			[ 'id' => (int) $bill['id'] ]
		);

		$this->logger->info( 'fx', 'Bill settled manually', [ 'bill_id' => (int) $bill['id'], 'operator' => $operator_user_id ] );

		return [ 'ok' => true, 'status' => self::STATUS_PAID, 'error' => '' ];
	}

	/**
	 * Daily cron: create the month's bill for every active account that does
	 * not have one yet, then try to settle every due bill.
	 */
	public function run_daily(): void {
		$this->bill_accounts();
		$this->settle_due();
	}

	/**
	 * Phase 26 — billing half of the daily sweep: create the month's bill for every active
	 * account that has none yet. create_monthly_bill() is itself idempotent (one bill per
	 * account per period), so a re-run is harmless.
	 */
	public function bill_accounts(): void {
		foreach ( $this->accounts->active() as $account ) {
			$this->create_monthly_bill( $account );
		}
	}

	/**
	 * Phase 26 — settlement half of the daily sweep: settle up to one bounded batch of due
	 * bills. Returns how many were visited so the caller can apply the continuation contract
	 * (a full batch means more bills may wait).
	 */
	public function settle_due( int $limit = 50 ): int {
		$bills = $this->due_bills( $limit );
		foreach ( $bills as $bill ) {
			$this->settle_bill( $bill );
		}
		return count( $bills );
	}

	public function __construct(
		private Db $db,
		private Settings $settings,
		private FxWalletService $wallet,
		private FxMeter $meter,
		private FxPayoutRegistry $payouts,
		private FxAccountsService $accounts,
		private Logger $logger
	) {}
}
