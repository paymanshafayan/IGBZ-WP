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

	/** Phase 36: the payout was sent and the provider's answer is not in yet. */
	public const STATUS_PENDING  = 'pending';

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

		// Phase 36 — human approval: a bill above the operator-set threshold is
		// never auto-settled; the manual path (settle_bill_manually) stays the
		// only way to pay it. Zero disables the gate.
		$threshold = (float) $this->settings->float( 'fx.payout_approval_threshold_usd', 0 );
		if ( $threshold > 0 && (float) $bill['amount_usd'] > $threshold ) {
			$this->logger->warning( 'fx', 'Bill exceeds the payout approval threshold', [ 'bill_id' => (int) $bill['id'], 'amount_usd' => (float) $bill['amount_usd'], 'threshold' => $threshold ] );
			return [ 'ok' => false, 'status' => self::STATUS_DUE, 'error' => 'requires_approval' ];
		}

		// Phase 36 — risk cap: what is already committed today (paid or still
		// pending) plus this bill must stay within fx.payout_daily_cap_usd.
		// Zero disables the cap.
		$cap = (float) $this->settings->float( 'fx.payout_daily_cap_usd', 0 );
		if ( $cap > 0 && $this->today_committed_usd() + (float) $bill['amount_usd'] > $cap ) {
			$this->logger->warning( 'fx', 'Daily payout cap reached, bill held back', [ 'bill_id' => (int) $bill['id'], 'amount_usd' => (float) $bill['amount_usd'], 'committed_usd' => $this->today_committed_usd(), 'cap' => $cap ] );
			return [ 'ok' => false, 'status' => self::STATUS_DUE, 'error' => 'daily_cap_reached' ];
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

		$result = null;
		try {
			$result = $adapter->pay( $bill );
		} catch ( \Throwable $e ) {
			$result = [ 'ok' => false, 'reference' => '', 'error' => $e->getMessage(), 'state' => 'pending' ];
			$this->logger->error( 'fx', 'Payout adapter threw, outcome unknown', [ 'bill_id' => (int) $bill['id'], 'error' => $e->getMessage() ] );
		}

		// Phase 36 — unknown outcome: a transport failure after the charge was
		// sent is NOT a failure. The bill goes `pending` with its debit kept;
		// refunding now would pay twice when the provider did in fact charge.
		// reconcile() and the provider webhook settle the doubt later.
		if ( ! $result['ok'] && 'pending' === (string) ( $result['state'] ?? '' ) ) {
			$this->db->update(
				'fx_bills',
				[ 'status' => self::STATUS_PENDING ],
				[ 'id' => (int) $bill['id'] ]
			);

			return [ 'ok' => false, 'status' => self::STATUS_PENDING, 'error' => (string) $result['error'] ];
		}

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

	/**
	 * The service key a provider maps to for pricing.
	 *
	 * Phase 50: the legacy monthly subscriptions are gone with their providers —
	 * the single social provider bills through its own plan model, not the FX
	 * monthly bill. The mechanism stays (create_monthly_bill short-circuits on
	 * an empty service) for whatever replaces it.
	 */
	public function service_for( array $account ): string {
		return '';
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

	/**
	 * Phase 36 — resolve a pending payout once the truth is known (provider
	 * webhook or reconcile query). Exactly one outcome ever applies:
	 *
	 *  - ok  → the bill is paid, carrying the provider reference.
	 *  - !ok → the wallet is refunded and the bill returns to `due`.
	 *
	 * Bills that are not pending are untouched, so a replayed webhook is inert.
	 *
	 * @return array{ok:bool,status:string,error:string}
	 */
	public function resolve_payout( array $bill, bool $ok, string $reference = '' ): array {
		$fresh = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'fx_bills' ) . ' WHERE id = %d',
			(int) $bill['id']
		);
		if ( null === $fresh || self::STATUS_PENDING !== (string) $fresh['status'] ) {
			return [ 'ok' => false, 'status' => null === $fresh ? 'missing' : (string) $fresh['status'], 'error' => 'not_pending' ];
		}

		if ( $ok ) {
			$this->db->update(
				'fx_bills',
				[
					'status'     => self::STATUS_PAID,
					'paid_at'    => current_time( 'mysql', true ),
					'payout_ref' => mb_substr( $reference, 0, 191 ),
				],
				[ 'id' => (int) $bill['id'] ]
			);
			$this->logger->info( 'fx', 'Pending payout confirmed', [ 'bill_id' => (int) $bill['id'], 'payout_ref' => $reference ] );

			return [ 'ok' => true, 'status' => self::STATUS_PAID, 'error' => '' ];
		}

		$this->wallet->credit(
			(int) $fresh['tenant_id'],
			(float) $fresh['amount_usd'],
			FxWalletService::REASON_REFUND,
			'refund:bill:' . (int) $bill['id'],
			[ 'bill_id' => (int) $bill['id'], 'outcome' => 'failed_after_pending' ]
		);
		$this->db->update(
			'fx_bills',
			[ 'status' => self::STATUS_DUE ],
			[ 'id' => (int) $bill['id'] ]
		);
		$this->logger->warning( 'fx', 'Pending payout failed, wallet refunded', [ 'bill_id' => (int) $bill['id'] ] );

		return [ 'ok' => false, 'status' => self::STATUS_DUE, 'error' => 'payout_failed' ];
	}

	/**
	 * Phase 36 — daily reconciliation of pending payouts. Bills whose adapter
	 * implements query() get a fresh verdict and are resolved through
	 * resolve_payout(); the rest stay pending until a webhook arrives. Never
	 * guesses: an unknown verdict keeps the bill pending and is only counted.
	 *
	 * @return array{scanned:int,resolved:int,refunded:int,unresolved:int}
	 */
	public function reconcile(): array {
		$out     = [ 'scanned' => 0, 'resolved' => 0, 'refunded' => 0, 'unresolved' => 0 ];
		$adapter = $this->payouts->active();

		foreach ( $this->pending_bills() as $bill ) {
			++$out['scanned'];

			if ( null === $adapter || ! method_exists( $adapter, 'query' ) ) {
				++$out['unresolved'];
				continue;
			}

			$verdict = $adapter->query( $bill );
			$state   = (string) ( $verdict['state'] ?? 'unknown' );

			if ( 'settled' === $state ) {
				$this->resolve_payout( $bill, true, (string) ( $verdict['reference'] ?? (string) ( $bill['payout_ref'] ?? '' ) ) );
				++$out['resolved'];
				continue;
			}

			if ( 'failed' === $state ) {
				$this->resolve_payout( $bill, false );
				++$out['resolved'];
				++$out['refunded'];
				continue;
			}

			++$out['unresolved'];
		}

		return $out;
	}

	/** @return array<int,array<string,mixed>> */
	public function pending_bills( int $limit = 100 ): array {
		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'fx_bills' ) . '\n\t\t\t WHERE status = %s ORDER BY id ASC LIMIT %d',
			self::STATUS_PENDING,
			$limit
		);
	}

	/** USD already committed today: paid today plus everything still pending. */
	public function today_committed_usd(): float {
		$today = gmdate( 'Y-m-d' );

		return (float) $this->db->scalar(
			"SELECT COALESCE( SUM( amount_usd ), 0 ) FROM {$this->db->table( 'fx_bills' )} WHERE status = 'pending' OR ( status = 'paid' AND paid_at >= '{$today} 00:00:00' ) "
		);
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
