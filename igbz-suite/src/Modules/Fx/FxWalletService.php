<?php
namespace IGBZ\Suite\Modules\Fx;

use IGBZ\Suite\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Per-tenant USD credit wallet.
 *
 * Every mutation writes a durable ledger row first (fx_ledger) and then moves
 * the balance (fx_wallets), all under a named lock so two cron ticks cannot
 * spend the same credit twice. The ledger's UNIQUE (tenant_id, reason,
 * reference) key is the second line of defence: credit() and debit() re-check
 * it in code, so a duplicated webhook or a replayed job settles once.
 *
 * Ledger amounts are signed: top-ups and refunds are positive, spending is
 * negative. The wallet balance is the running sum.
 */
final class FxWalletService {

	public const REASON_TOPUP        = 'topup';
	public const REASON_FEE          = 'topup_fee';
	public const REASON_TASK         = 'task';
	public const REASON_REFUND       = 'task_refund';
	public const REASON_SUBSCRIPTION = 'subscription';

	public function __construct( private Db $db ) {}

	/** @return array{tenant_id:int,balance_usd:float} */
	public function balance( int $tenant_id ): array {
		$row = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'fx_wallets' ) . ' WHERE tenant_id = %d',
			$tenant_id
		);

		if ( ! $row ) {
			return [ 'tenant_id' => $tenant_id, 'balance_usd' => 0.0 ];
		}

		return [
			'tenant_id'   => (int) $row['tenant_id'],
			'balance_usd' => (float) $row['balance_usd'],
		];
	}

	/**
	 * Add credit. Idempotent per (reason, reference): a second call with the
	 * same reference is a no-op, so a replayed `igbz_payment_verified` cannot
	 * double-credit.
	 */
	public function credit(
		int $tenant_id,
		float $amount_usd,
		string $reason,
		string $reference,
		array $meta = [],
		int $user_id = 0,
		float $amount_irt = 0,
		int $rate_id = 0
	): bool {
		if ( $amount_usd <= 0 ) {
			return false;
		}

		if ( ! $this->db->lock( 'fx_wallet:' . $tenant_id, 5 ) ) {
			return false;
		}

		try {
			if ( $this->ledger_exists( $tenant_id, $reason, $reference ) ) {
				return false;
			}

			$this->db->insert(
				'fx_ledger',
				[
					'tenant_id'  => $tenant_id,
					'user_id'    => $user_id,
					'reason'     => $reason,
					'reference'  => $reference,
					'amount_usd' => $amount_usd,
					'amount_irt' => $amount_irt,
					'rate_id'    => $rate_id,
					'meta'       => wp_json_encode( $meta ),
					'created_at' => current_time( 'mysql', true ),
				]
			);

			$this->add_balance( $tenant_id, $amount_usd );

			return true;
		} finally {
			$this->db->unlock( 'fx_wallet:' . $tenant_id );
		}
	}

	/**
	 * Spend credit. Returns the new balance, or an error when the wallet is
	 * short. Idempotent per (reason, reference) as well.
	 *
	 * @return array{ok:bool,balance:float,error:string}
	 */
	public function debit(
		int $tenant_id,
		float $amount_usd,
		string $reason,
		string $reference,
		array $meta = [],
		int $user_id = 0
	): array {
		if ( $amount_usd <= 0 ) {
			return [ 'ok' => false, 'balance' => $this->balance( $tenant_id )['balance_usd'], 'error' => 'invalid_amount' ];
		}

		if ( ! $this->db->lock( 'fx_wallet:' . $tenant_id, 5 ) ) {
			return [ 'ok' => false, 'balance' => $this->balance( $tenant_id )['balance_usd'], 'error' => 'locked' ];
		}

		try {
			if ( $this->ledger_exists( $tenant_id, $reason, $reference ) ) {
				return [ 'ok' => false, 'balance' => $this->balance( $tenant_id )['balance_usd'], 'error' => 'duplicate' ];
			}

			$balance = $this->balance( $tenant_id )['balance_usd'];
			if ( $balance < $amount_usd ) {
				return [ 'ok' => false, 'balance' => $balance, 'error' => 'insufficient' ];
			}

			$this->db->insert(
				'fx_ledger',
				[
					'tenant_id'  => $tenant_id,
					'user_id'    => $user_id,
					'reason'     => $reason,
					'reference'  => $reference,
					'amount_usd' => -1 * $amount_usd,
					'amount_irt' => 0,
					'rate_id'    => 0,
					'meta'       => wp_json_encode( $meta ),
					'created_at' => current_time( 'mysql', true ),
				]
			);

			$this->add_balance( $tenant_id, -1 * $amount_usd );

			return [ 'ok' => true, 'balance' => $this->balance( $tenant_id )['balance_usd'], 'error' => '' ];
		} finally {
			$this->db->unlock( 'fx_wallet:' . $tenant_id );
		}
	}

	/** @return array<int,array<string,mixed>> */
	public function ledger( int $tenant_id, int $limit = 50, int $offset = 0, int $before_id = 0 ): array {
		// Phase 67: $before_id is the keyset filter for cursor pagination (id DESC).
		$sql  = 'SELECT * FROM ' . $this->db->table( 'fx_ledger' ) . ' WHERE tenant_id = %d';
		$args = [ $tenant_id ];
		if ( $before_id > 0 ) {
			$sql   .= ' AND id < %d';
			$args[] = $before_id;
		}
		$sql   .= ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$args[] = $limit;
		$args[] = $offset;

		return $this->db->results( $sql, ...$args );
	}

	private function ledger_exists( int $tenant_id, string $reason, string $reference ): bool {
		$found = $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'fx_ledger' ) . '
			 WHERE tenant_id = %d AND reason = %s AND reference = %s',
			$tenant_id,
			$reason,
			$reference
		);

		return (int) $found > 0;
	}

	private function add_balance( int $tenant_id, float $delta ): void {
		$current = $this->balance( $tenant_id )['balance_usd'];
		$next    = round( $current + $delta, 4 );

		$this->db->upsert(
			'fx_wallets',
			[
				'tenant_id'   => $tenant_id,
				'balance_usd' => $next,
				'updated_at'  => current_time( 'mysql', true ),
			],
			[ 'balance_usd' => $next, 'updated_at' => current_time( 'mysql', true ) ],
			[ 'tenant_id' ]
		);
	}
}
