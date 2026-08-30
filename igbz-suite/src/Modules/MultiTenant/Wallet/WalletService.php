<?php
namespace IGBZ\Suite\Modules\MultiTenant\Wallet;

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Unified wallet ledger.
 *
 * Port note: the nopCommerce original computed the balance by summing ledger rows in memory with
 * no transaction or row lock, so two concurrent debits could both pass the "enough balance" test
 * and overdraw the wallet. Here every mutation runs inside a SQL transaction guarded by a MySQL
 * named lock, and a denormalised balance row is kept in sync so reads are O(1).
 *
 * Idempotency is enforced at the database level by the UNIQUE (tenant_id, user_id, reason,
 * reference_code) index - replaying the same gateway callback can never double credit.
 */
final class WalletService {

	public const REASON_TOPUP       = 'topup';
	public const REASON_ORDER_PAY   = 'order_payment';
	public const REASON_REFUND      = 'refund';
	public const REASON_CASHBACK    = 'cashback';
	public const REASON_COMMISSION  = 'affiliate_commission';
	public const REASON_PAYOUT      = 'affiliate_payout';
	public const REASON_BNPL_PAY    = 'bnpl_installment';
	public const REASON_SUBSCRIPTION = 'subscription';
	public const REASON_PROMO       = 'promotion';
	public const REASON_ADJUSTMENT  = 'manual_adjustment';
	public const REASON_IG_REWARD   = 'instagram_reward';

	/** Phase 28 — the reservation lifecycle. */
	public const REASON_RESERVE         = 'reserve';
	public const REASON_RESERVE_RELEASE = 'reserve_release';
	public const REASON_RESERVE_SETTLE  = 'reserve_settlement';

	public const DIRECTION_CREDIT = 'credit';
	public const DIRECTION_DEBIT  = 'debit';

	/** Phase 28: a record-only entry (zero amount) — currently the settlement mark. */
	public const DIRECTION_MARK = 'mark';

	public function __construct( private Db $db, private Logger $logger ) {}

	public function balance( int $user_id, int $tenant_id = 0 ): float {
		$value = $this->db->scalar(
			'SELECT balance FROM ' . $this->db->table( 'wallet_balances' ) . ' WHERE user_id = %d AND tenant_id = %d',
			$user_id,
			$tenant_id
		);
		if ( null !== $value ) {
			return round( (float) $value, 4 );
		}
		return $this->recalculate( $user_id, $tenant_id );
	}

	/** Rebuild the cached balance from the ledger (repair / migration helper). */
	public function recalculate( int $user_id, int $tenant_id = 0 ): float {
		$sum = (float) $this->db->scalar(
			'SELECT COALESCE(SUM(amount),0) FROM ' . $this->db->table( 'wallet_ledger' ) . ' WHERE user_id = %d AND tenant_id = %d',
			$user_id,
			$tenant_id
		);
		$this->write_balance( $user_id, $tenant_id, $sum );
		return round( $sum, 4 );
	}

	/**
	 * Phase 28 — the balance invariant for one wallet: the cached balance must equal the ledger
	 * sum. The ledger is the source of truth; the balance row only exists so reads are O(1).
	 */
	public function check_invariant( int $user_id, int $tenant_id = 0 ): bool {
		$cached = $this->db->scalar(
			'SELECT balance FROM ' . $this->db->table( 'wallet_balances' ) . ' WHERE user_id = %d AND tenant_id = %d',
			$user_id,
			$tenant_id
		);
		if ( null === $cached ) {
			return true; // No cache row yet — nothing can have drifted.
		}
		$sum = (float) $this->db->scalar(
			'SELECT COALESCE(SUM(amount),0) FROM ' . $this->db->table( 'wallet_ledger' ) . ' WHERE user_id = %d AND tenant_id = %d',
			$user_id,
			$tenant_id
		);
		return abs( (float) $cached - round( $sum, 4 ) ) <= 0.0001;
	}

	/**
	 * Phase 28 — reconciliation: bounded keyset walk over every cached balance, comparing it
	 * against the ledger and repairing drift. Safe to run daily; it never touches a wallet that
	 * is already consistent.
	 *
	 * @return array{checked:int,repaired:int}
	 */
	public function reconcile_all( int $batch = 200 ): array {
		$checked  = 0;
		$repaired = 0;
		$after    = 0;

		do {
			$rows = $this->db->results(
				'SELECT * FROM ' . $this->db->table( 'wallet_balances' ) . ' WHERE id > %d ORDER BY id ASC LIMIT %d',
				$after,
				$batch
			);
			foreach ( $rows as $row ) {
				++$checked;
				$user_id   = (int) $row['user_id'];
				$tenant_id = (int) $row['tenant_id'];
				$sum       = round(
					(float) $this->db->scalar(
						'SELECT COALESCE(SUM(amount),0) FROM ' . $this->db->table( 'wallet_ledger' ) . ' WHERE user_id = %d AND tenant_id = %d',
						$user_id,
						$tenant_id
					),
					4
				);
				if ( abs( (float) $row['balance'] - $sum ) > 0.0001 ) {
					$this->write_balance( $user_id, $tenant_id, $sum );
					++$repaired;
					$this->logger->warning( 'wallet', 'balance drift repaired by reconciliation', [ 'user_id' => $user_id, 'tenant_id' => $tenant_id, 'cached' => (float) $row['balance'], 'ledger' => $sum ] );
				}
			}
			$after = $rows ? (int) end( $rows )['id'] : 0;
		} while ( $rows && $after > 0 );

		return [ 'checked' => $checked, 'repaired' => $repaired ];
	}

	/**
	 * Credit a wallet. Idempotent on (tenant, user, reason, reference_code).
	 *
	 * @param array<string,mixed> $meta
	 */
	public function credit(
		int $user_id,
		float $amount,
		string $reason,
		string $reference_code = '',
		array $meta = [],
		int $tenant_id = 0,
		int $order_id = 0,
		string $note = ''
	): WalletResult {
		return $this->post( $user_id, abs( $amount ), $reason, $reference_code, $meta, $tenant_id, $order_id, $note, false );
	}

	/**
	 * Debit a wallet, refusing to overdraw unless the store explicitly allows negative balances.
	 *
	 * @param array<string,mixed> $meta
	 */
	public function debit(
		int $user_id,
		float $amount,
		string $reason,
		string $reference_code = '',
		array $meta = [],
		int $tenant_id = 0,
		int $order_id = 0,
		string $note = ''
	): WalletResult {
		return $this->post( $user_id, -abs( $amount ), $reason, $reference_code, $meta, $tenant_id, $order_id, $note, true );
	}

	/** Convenience wrapper mirroring the original TryDebitAsync signature. */
	public function try_debit( int $user_id, float $amount, string $reason, string $reference_code, int $tenant_id = 0 ): bool {
		return $this->debit( $user_id, $amount, $reason, $reference_code, [], $tenant_id )->success;
	}

	/**
	 * Phase 28 — reserve funds: a plain debit under the `reserve` reason moves the amount out of
	 * the available balance so concurrent payment flows cannot double-spend it. Idempotent on
	 * the reference code, like every other ledger operation.
	 */
	public function reserve( int $user_id, float $amount, string $reference_code, int $tenant_id = 0, string $note = '' ): WalletResult {
		return $this->debit( $user_id, $amount, self::REASON_RESERVE, $reference_code, [], $tenant_id, 0, $note );
	}

	/**
	 * Phase 28 — release a reservation: the reserved amount comes back. A reservation can be
	 * consumed exactly once (release OR settle); the ledger itself enforces it — the release is
	 * idempotent on the reference code, and a settled reservation refuses to be released.
	 */
	public function release_reserve( int $user_id, string $reference_code, int $tenant_id = 0 ): WalletResult {
		$reservation = $this->find_entry( $user_id, $tenant_id, self::REASON_RESERVE, $reference_code );
		if ( ! $reservation ) {
			return WalletResult::failure( 'unknown_reservation', __( 'No such reservation.', 'igbz-suite' ) );
		}
		if ( $this->find_entry( $user_id, $tenant_id, self::REASON_RESERVE_SETTLE, $reference_code ) ) {
			return WalletResult::failure( 'already_settled', __( 'This reservation was already settled.', 'igbz-suite' ) );
		}
		return $this->credit( $user_id, abs( (float) $reservation['amount'] ), self::REASON_RESERVE_RELEASE, $reference_code, [], $tenant_id );
	}

	/**
	 * Phase 28 — settle a reservation: the reserved money was consumed (an order finalized, a
	 * payout executed). The reservation debit already moved the funds, so settlement writes a
	 * zero-amount `mark` entry that permanently records the reservation as consumed — releasing
	 * it afterwards is refused. The mark is idempotent on the reference code.
	 */
	public function settle_reserve( int $user_id, string $reference_code, int $tenant_id = 0 ): WalletResult {
		$reservation = $this->find_entry( $user_id, $tenant_id, self::REASON_RESERVE, $reference_code );
		if ( ! $reservation ) {
			return WalletResult::failure( 'unknown_reservation', __( 'No such reservation.', 'igbz-suite' ) );
		}
		if ( $this->find_entry( $user_id, $tenant_id, self::REASON_RESERVE_RELEASE, $reference_code ) ) {
			return WalletResult::failure( 'already_released', __( 'This reservation was already released.', 'igbz-suite' ) );
		}
		return $this->post( $user_id, 0.0, self::REASON_RESERVE_SETTLE, $reference_code, [], $tenant_id, 0, '', false, true );
	}

	/**
	 * Phase 28 — refund against an earlier debit. Refund entries carry the reference convention
	 * `refund:{original_ref}:{refund_ref}`, and the sum of refunds against one original can
	 * never exceed that original's amount — over-refunding is refused before anything is posted.
	 * Each refund stays idempotent on its own full reference code.
	 */
	public function refund( int $user_id, string $original_reference_code, float $amount, string $refund_reference_code, int $tenant_id = 0, int $order_id = 0 ): WalletResult {
		if ( $amount <= 0 ) {
			return WalletResult::failure( 'zero_amount', __( 'Amount must be greater than zero.', 'igbz-suite' ) );
		}
		$original = $this->db->row(
			'SELECT id, amount FROM ' . $this->db->table( 'wallet_ledger' ) . '
			 WHERE user_id = %d AND tenant_id = %d AND reference_code = %s AND amount < 0
			 ORDER BY id ASC LIMIT 1',
			$user_id,
			$tenant_id,
			$original_reference_code
		);
		if ( ! $original ) {
			return WalletResult::failure( 'unknown_original', __( 'No such debit to refund against.', 'igbz-suite' ) );
		}

		$prefix = 'refund:' . $original_reference_code . ':';

		// A replayed refund must report as a duplicate — idempotency first, guards after.
		$existing = $this->find_entry( $user_id, $tenant_id, self::REASON_REFUND, $prefix . $refund_reference_code );
		if ( $existing ) {
			return WalletResult::duplicate( (int) $existing['id'], (float) $existing['balance_after'] );
		}
		$refunded = abs(
			(float) $this->db->scalar(
				'SELECT COALESCE(SUM(amount),0) FROM ' . $this->db->table( 'wallet_ledger' ) . '
				 WHERE user_id = %d AND tenant_id = %d AND reason = %s AND reference_code LIKE %s',
				$user_id,
				$tenant_id,
				self::REASON_REFUND,
				esc_like( $prefix ) . '%'
			)
		);
		if ( $refunded + $amount > abs( (float) $original['amount'] ) + 0.0001 ) {
			return WalletResult::failure( 'over_refund', __( 'Refund exceeds the original debit.', 'igbz-suite' ) );
		}

		return $this->credit( $user_id, $amount, self::REASON_REFUND, $prefix . $refund_reference_code, [ 'original_reference' => $original_reference_code ], $tenant_id, $order_id );
	}

	/**
	 * @param array<string,mixed> $meta
	 * @param bool $record_only Phase 28: write a zero-amount `mark` entry (the settlement mark) —
	 *                          the only path that may post zero.
	 */
	private function post(
		int $user_id,
		float $signed_amount,
		string $reason,
		string $reference_code,
		array $meta,
		int $tenant_id,
		int $order_id,
		string $note,
		bool $enforce_funds,
		bool $record_only = false
	): WalletResult {
		if ( $user_id <= 0 ) {
			return WalletResult::failure( 'invalid_user', __( 'Invalid wallet owner.', 'igbz-suite' ) );
		}
		if ( ! $record_only && abs( $signed_amount ) < 0.0001 ) {
			return WalletResult::failure( 'zero_amount', __( 'Amount must be greater than zero.', 'igbz-suite' ) );
		}
		if ( '' === $reference_code ) {
			$reference_code = 'auto-' . \IGBZ\Suite\Support\Crypto::token( 8 );
		}

		$existing = $this->find_entry( $user_id, $tenant_id, $reason, $reference_code );
		if ( $existing ) {
			return WalletResult::duplicate( (int) $existing['id'], (float) $existing['balance_after'] );
		}

		$lock = sprintf( 'wallet_%d_%d', $tenant_id, $user_id );
		if ( ! $this->db->lock( $lock, 5 ) ) {
			return WalletResult::failure( 'lock_timeout', __( 'Wallet is busy, please retry.', 'igbz-suite' ) );
		}

		try {
			return $this->db->transaction(
				function () use ( $user_id, $tenant_id, $signed_amount, $reason, $reference_code, $meta, $order_id, $note, $enforce_funds, $record_only ) {
					$table = $this->db->table( 'wallet_balances' );

					// FOR UPDATE is MySQL-only row locking. SQLite has no such clause and the
					// sqlite-database-integration translator cannot parse it, so it is appended
					// only on MySQL. SQLite serialises writers at the database level anyway, and
					// Db::lock() already guards the MySQL path, so correctness is preserved.
					$for_update = $this->db->is_sqlite() ? '' : ' FOR UPDATE';
					$current    = $this->db->scalar(
						"SELECT balance FROM {$table} WHERE user_id = %d AND tenant_id = %d{$for_update}",
						$user_id,
						$tenant_id
					);
					if ( null === $current ) {
						$current = (float) $this->db->scalar(
							'SELECT COALESCE(SUM(amount),0) FROM ' . $this->db->table( 'wallet_ledger' ) . ' WHERE user_id = %d AND tenant_id = %d',
							$user_id,
							$tenant_id
						);
					}
					$current = (float) $current;
					$after   = $record_only ? round( $current, 4 ) : round( $current + $signed_amount, 4 );

					$allow_negative = igbz()->settings()->bool( 'wallet.allow_negative', false );
					if ( ! $record_only && $enforce_funds && ! $allow_negative && $after < -0.0001 ) {
						return WalletResult::failure(
							'insufficient_funds',
							__( 'Insufficient wallet balance.', 'igbz-suite' ),
							$current
						);
					}

					$currency = (string) ( igbz()->settings()->get( 'general.default_currency', 'IRT' ) );
					$id       = $this->db->insert(
						'wallet_ledger',
						[
							'tenant_id'      => $tenant_id,
							'user_id'        => $user_id,
							'amount'         => $signed_amount,
							'balance_after'  => $after,
							'currency'       => $currency,
							'direction'      => $record_only
								? self::DIRECTION_MARK
								: ( $signed_amount >= 0 ? self::DIRECTION_CREDIT : self::DIRECTION_DEBIT ),
							'reason'         => $reason,
							'reference_code' => $reference_code,
							'order_id'       => $order_id,
							'note'           => mb_substr( $note, 0, 255 ),
							'meta'           => wp_json_encode( $meta ),
							'created_by'     => get_current_user_id(),
							'created_at'     => current_time( 'mysql', true ),
						]
					);

					if ( 0 === $id ) {
						// The unique index rejected a concurrent duplicate - treat as idempotent success.
						$dup = $this->find_entry( $user_id, $tenant_id, $reason, $reference_code );
						if ( $dup ) {
							return WalletResult::duplicate( (int) $dup['id'], (float) $dup['balance_after'] );
						}
						throw new \RuntimeException( 'Wallet ledger insert failed: ' . $this->db->last_error() );
					}

					$this->write_balance( $user_id, $tenant_id, $after );

					$this->logger->info(
						'wallet',
						sprintf( '%s %s for user %d', $signed_amount >= 0 ? 'credit' : 'debit', (string) abs( $signed_amount ), $user_id ),
						[ 'tenant_id' => $tenant_id, 'reason' => $reason, 'entry_id' => $id, 'balance' => $after ]
					);

					do_action( 'igbz_wallet_entry_created', $id, $user_id, $signed_amount, $reason, $tenant_id );

					return WalletResult::ok( $id, $after );
				}
			);
		} catch ( \Throwable $e ) {
			$this->logger->error( 'wallet', 'Ledger write failed: ' . $e->getMessage(), [ 'user_id' => $user_id, 'reason' => $reason ] );
			return WalletResult::failure( 'exception', $e->getMessage() );
		} finally {
			$this->db->unlock( $lock );
		}
	}

	/** @return array<string,mixed>|null */
	private function find_entry( int $user_id, int $tenant_id, string $reason, string $reference_code ): ?array {
		return $this->db->row(
			'SELECT id, amount, balance_after FROM ' . $this->db->table( 'wallet_ledger' ) . '
			 WHERE user_id = %d AND tenant_id = %d AND reason = %s AND reference_code = %s',
			$user_id,
			$tenant_id,
			$reason,
			$reference_code
		);
	}

	private function write_balance( int $user_id, int $tenant_id, float $balance ): void {
		$currency = (string) igbz()->settings()->get( 'general.default_currency', 'IRT' );
		$this->db->upsert(
			'wallet_balances',
			[
				'tenant_id'  => $tenant_id,
				'user_id'    => $user_id,
				'balance'    => $balance,
				'currency'   => $currency,
				'updated_at' => current_time( 'mysql', true ),
			],
			[
				'balance'    => 'value',
				'updated_at' => 'value',
			],
			[ 'tenant_id', 'user_id' ]
		);
	}

	/**
	 * Move funds between two wallets atomically (used by affiliate payouts and refunds).
	 */
	public function transfer( int $from_user, int $to_user, float $amount, string $reason, string $reference_code, int $tenant_id = 0 ): WalletResult {
		$debit = $this->debit( $from_user, $amount, $reason, $reference_code . ':out', [ 'to' => $to_user ], $tenant_id );
		if ( ! $debit->success ) {
			return $debit;
		}
		$credit = $this->credit( $to_user, $amount, $reason, $reference_code . ':in', [ 'from' => $from_user ], $tenant_id );
		if ( ! $credit->success ) {
			// Compensate: the debit already committed, so post a reversing credit.
			$this->credit( $from_user, $amount, self::REASON_ADJUSTMENT, $reference_code . ':reversal', [ 'reason' => 'transfer_failed' ], $tenant_id );
		}
		return $credit;
	}

	/**
	 * @param array{tenant_id?:int,reason?:string,from?:string,to?:string,limit?:int,offset?:int,before_id?:int} $args
	 * @return array<int,array<string,mixed>>
	 */
	public function history( int $user_id, array $args = [] ): array {
		$where  = [ 'user_id = %d' ];
		$params = [ $user_id ];
		// Phase 67: keyset access for cursor pagination — rows strictly below the cursor id.
		if ( ! empty( $args['before_id'] ) ) {
			$where[]  = 'id < %d';
			$params[] = (int) $args['before_id'];
		}
		if ( isset( $args['tenant_id'] ) ) {
			$where[]  = 'tenant_id = %d';
			$params[] = (int) $args['tenant_id'];
		}
		if ( ! empty( $args['reason'] ) ) {
			$where[]  = 'reason = %s';
			$params[] = (string) $args['reason'];
		}
		if ( ! empty( $args['from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = (string) $args['from'];
		}
		if ( ! empty( $args['to'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = (string) $args['to'];
		}
		$params[] = (int) ( $args['limit'] ?? 50 );
		$params[] = (int) ( $args['offset'] ?? 0 );

		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'wallet_ledger' ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d',
			...$params
		);
	}

	/** @return array{credit:float,debit:float,net:float} */
	public function totals( int $tenant_id = 0 ): array {
		$row = $this->db->row(
			'SELECT
				COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END),0) AS credited,
				COALESCE(SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END),0) AS debited
			 FROM ' . $this->db->table( 'wallet_ledger' ) . ' WHERE tenant_id = %d',
			$tenant_id
		);
		$credit = (float) ( $row['credited'] ?? 0 );
		$debit  = (float) ( $row['debited'] ?? 0 );
		return [ 'credit' => $credit, 'debit' => $debit, 'net' => round( $credit - $debit, 4 ) ];
	}
}
