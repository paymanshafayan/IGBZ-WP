<?php
namespace IGBZ\Suite\Modules\MultiTenant\MasterPayment;

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Master payment gateway (escrow).
 *
 * Holds the customer's money until the shipment is delivered + a release
 * window (default 24h) passes with no dispute, then releases it to the
 * admin's internal wallet. Disputes (from the customer app or support)
 * freeze the payment for review. A digital agreement is the precondition
 * for using the gateway.
 */
final class MasterPaymentService {

	public const STATUS_HELD     = 'held';
	public const STATUS_RELEASED = 'released';
	public const STATUS_DISPUTED = 'disputed';
	public const STATUS_REFUNDED = 'refunded';

	public function __construct(
		private Db $db,
		private Logger $logger
	) {}

	/** @return array{ok:bool,payment_id:int,error:string} */
	public function hold( int $tenant_id, int $order_id, float $amount, string $currency = 'IRT', string $gateway_ref = '', string $phase = 'rial' ): array {
		$existing = $this->db->row(
			'SELECT id FROM ' . $this->db->table( 'ig_master_payments' ) . ' WHERE order_id = %d AND phase = %s AND tenant_id = %d',
			$order_id,
			$phase,
			$tenant_id
		);
		if ( $existing ) {
			return [ 'ok' => false, 'payment_id' => (int) $existing['id'], 'error' => 'already_held' ];
		}

		$release_hours = (int) igbz()->settings()->int( 'master_payment.release_hours', 24 );
		$now           = current_time( 'mysql', true );

		$id = (int) $this->db->insert(
			'ig_master_payments',
			[
				'tenant_id'    => $tenant_id,
				'order_id'     => $order_id,
				'phase'        => $phase,
				'amount'       => $amount,
				'currency'     => $currency,
				'status'       => self::STATUS_HELD,
				'hold_until'   => gmdate( 'Y-m-d H:i:s', time() + $release_hours * HOUR_IN_SECONDS ),
				'gateway_ref'  => $gateway_ref,
				'created_at'   => $now,
				'updated_at'   => $now,
			]
		);
		$this->logger->info( 'master_payment', 'Payment held', [ 'payment_id' => $id, 'order' => $order_id, 'amount' => $amount ] );

		return [ 'ok' => true, 'payment_id' => $id, 'error' => '' ];
	}

	/**
	 * Phase 31 — refund the customer from the escrow, full or partial.
	 *
	 * Money in escrow never entered a wallet, so a refund is pure state: the running total
	 * `refunded_amount` grows (with an optimistic-lock UPDATE, so two racing refunds cannot both
	 * win) and when it reaches the held amount the payment flips to `refunded`. Double-fulfilment
	 * is guarded twice: a released payment refuses refunds, and the total can never exceed the
	 * original amount.
	 *
	 * @param float|null $amount null refunds the remainder in full.
	 * @return array{ok:bool,error:string,refunded?:float}
	 */
	public function refund( int $payment_id, int $tenant_id = 0, ?float $amount = null ): array {
		$row = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'ig_master_payments' ) . ' WHERE id = %d AND tenant_id = %d',
			$payment_id,
			$tenant_id
		);
		if ( ! $row ) {
			return [ 'ok' => false, 'error' => 'payment_not_found' ];
		}
		if ( ! in_array( (string) $row['status'], [ self::STATUS_HELD, self::STATUS_DISPUTED ], true ) ) {
			// released = already fulfilled to the owner; refunded = nothing left to return.
			return [ 'ok' => false, 'error' => self::STATUS_RELEASED === $row['status'] ? 'already_released' : 'already_refunded' ];
		}

		$total     = (float) $row['amount'];
		$refunded  = (float) ( $row['refunded_amount'] ?? 0 );
		$requested = $amount ?? ( $total - $refunded );
		if ( $requested <= 0 ) {
			return [ 'ok' => false, 'error' => 'invalid_amount' ];
		}
		if ( $refunded + $requested > $total + 0.0001 ) {
			return [ 'ok' => false, 'error' => 'over_refund' ];
		}

		// Optimistic lock: only one writer may move the running total.
		$changed = $this->db->update(
			'ig_master_payments',
			[ 'refunded_amount' => $refunded + $requested, 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $payment_id, 'refunded_amount' => $refunded ]
		);
		if ( 0 === $changed ) {
			return [ 'ok' => false, 'error' => 'lost_race' ];
		}

		$new_total = $refunded + $requested;
		$full      = $new_total >= $total - 0.0001;
		if ( $full ) {
			$this->db->update(
				'ig_master_payments',
				[ 'status' => self::STATUS_REFUNDED, 'updated_at' => current_time( 'mysql', true ) ],
				[ 'id' => $payment_id ]
			);
		}
		$this->logger->info( 'master_payment', 'Escrow refund', [ 'payment_id' => $payment_id, 'amount' => $requested, 'total_refunded' => $new_total, 'full' => $full ] );

		return [ 'ok' => true, 'error' => '', 'refunded' => $new_total ];
	}

	/**
	 * Phase 31 — settle a dispute: release to the owner or refund the customer.
	 *
	 * @return array{ok:bool,error:string}
	 */
	public function resolve_dispute( int $dispute_id, int $tenant_id, string $verdict, string $note = '' ): array {
		$dispute = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'ig_master_disputes' ) . ' WHERE id = %d AND tenant_id = %d',
			$dispute_id,
			$tenant_id
		);
		if ( ! $dispute ) {
			return [ 'ok' => false, 'error' => 'dispute_not_found' ];
		}
		if ( 'open' !== (string) $dispute['status'] ) {
			return [ 'ok' => false, 'error' => 'already_resolved' ];
		}
		if ( ! in_array( $verdict, [ 'release', 'refund' ], true ) ) {
			return [ 'ok' => false, 'error' => 'invalid_verdict' ];
		}

		$payment_id = (int) $dispute['payment_id'];
		$payment    = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'ig_master_payments' ) . ' WHERE id = %d AND tenant_id = %d',
			$payment_id,
			$tenant_id
		);
		if ( ! $payment ) {
			return [ 'ok' => false, 'error' => 'payment_not_found' ];
		}

		if ( 'refund' === $verdict ) {
			$out = $this->refund( $payment_id, $tenant_id );
			if ( ! $out['ok'] ) {
				return [ 'ok' => false, 'error' => (string) $out['error'] ];
			}
		} elseif ( self::STATUS_DISPUTED === (string) $payment['status'] ) {
			// Put the money back on the release track instead of forcing an immediate credit —
			// the sweep stays the single writer that moves money, so there is one path to audit.
			$this->db->update(
				'ig_master_payments',
				[ 'status' => self::STATUS_HELD, 'hold_until' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ],
				[ 'id' => $payment_id, 'status' => self::STATUS_DISPUTED ]
			);
		}

		$this->db->update(
			'ig_master_disputes',
			[ 'status' => 'resolved', 'resolved_at' => current_time( 'mysql', true ), 'reason' => mb_substr( $note, 0, 255 ), 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $dispute_id, 'status' => 'open' ]
		);
		$this->logger->info( 'master_payment', 'Dispute resolved', [ 'dispute_id' => $dispute_id, 'verdict' => $verdict ] );

		return [ 'ok' => true, 'error' => '' ];
	}

	/**
	 * Phase 31 — escrow reconciliation: every payment the sweep marked released MUST have its
	 * wallet credit; anything missing is repaired (the credit is idempotent by reference) and
	 * counted. Also surfaces disputes left open too long. Nothing here is ever silently fixed.
	 *
	 * @return array{repaired:int,missing_credit:int,stale_disputes:int}
	 */
	public function reconcile(): array {
		$report = [ 'repaired' => 0, 'missing_credit' => 0, 'stale_disputes' => 0 ];
		$wallet = igbz()->has( 'wallet' ) ? igbz()->get( 'wallet' ) : null;

		$released = $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_master_payments' ) . ' WHERE status = %s ORDER BY id ASC LIMIT 500',
			self::STATUS_RELEASED
		);
		foreach ( $released as $row ) {
			$reference = 'master:' . (int) $row['id'];
			$entry     = $this->db->row(
				'SELECT id FROM ' . $this->db->table( 'wallet_ledger' ) . ' WHERE reason = %s AND reference_code = %s',
				\IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService::REASON_TOPUP,
				$reference
			);
			if ( $entry ) {
				continue;
			}
			++$report['missing_credit'];
			if ( $wallet ) {
				$owner = $this->tenant_owner( (int) $row['tenant_id'] );
				$wallet->credit(
					$owner,
					(float) $row['amount'] - (float) ( $row['refunded_amount'] ?? 0 ),
					\IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService::REASON_TOPUP,
					$reference,
					[ 'master_payment' => (int) $row['id'], 'order' => (int) $row['order_id'], 'repaired' => true ],
					(int) $row['tenant_id']
				);
				++$report['repaired'];
			}
			$this->logger->error( 'master_payment', 'Released escrow without wallet credit — repaired', [ 'payment_id' => (int) $row['id'] ] );
		}

		$stale_days = max( 1, (int) igbz()->settings()->int( 'master_payment.stale_dispute_days', 14 ) );
		$report['stale_disputes'] = (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'ig_master_disputes' ) . ' WHERE status = %s AND created_at <= %s',
			'open',
			gmdate( 'Y-m-d H:i:s', time() - $stale_days * DAY_IN_SECONDS )
		);

		return $report;
	}

	/** Cron: release held payments whose window passed with no open dispute. */
	public function release_due(): int {
		$rows = $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_master_payments' ) . '
			 WHERE status = %s AND hold_until <= %s ORDER BY id ASC LIMIT 100',
			self::STATUS_HELD,
			current_time( 'mysql', true )
		);

		$released = 0;
		foreach ( $rows as $row ) {
			if ( $this->has_open_dispute( (int) $row['id'] ) ) {
				continue;
			}
			if ( $this->release( $row ) ) {
				++$released;
			}
		}

		return $released;
	}

	/** @param array<string,mixed> $row */
	private function release( array $row ): bool {
		// Credit the admin's internal wallet (tenant owner).
		$wallet = igbz()->has( 'wallet' ) ? igbz()->get( 'wallet' ) : null;
		if ( ! $wallet ) {
			return false;
		}

		// Phase 31: the owner receives the un-refunded remainder only — a partial refund has
		// already returned its share to the customer.
		$remaining = (float) $row['amount'] - (float) ( $row['refunded_amount'] ?? 0 );

		$owner = $this->tenant_owner( (int) $row['tenant_id'] );
		$ok    = $wallet->credit(
			$owner,
			$remaining,
			\IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService::REASON_TOPUP,
			'master:' . (int) $row['id'],
			[ 'master_payment' => (int) $row['id'], 'order' => (int) $row['order_id'] ],
			(int) $row['tenant_id']
		);

		if ( ! $ok ) {
			// Idempotent key exists -> already released. Phase 31: the status flip is now
			// conditional, so a second sweep can never re-mark an already-released row.
			return 0 < $this->flip_released( (int) $row['id'] );
		}

		// Phase 31: conditional flip — two racing sweeps can never both claim the same row.
		$flipped = $this->flip_released( (int) $row['id'] );
		if ( 0 === $flipped ) {
			// Someone else flipped first; our credit above is a harmless idempotent duplicate.
			return false;
		}
		$this->logger->info( 'master_payment', 'Payment released to admin wallet', [ 'payment_id' => (int) $row['id'], 'amount' => (float) $row['amount'] ] );

		return true;
	}

	/** Credit the owner with the un-refunded remainder only. */
	private function flip_released( int $payment_id ): int {
		return $this->db->update(
			'ig_master_payments',
			[ 'status' => self::STATUS_RELEASED, 'released_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $payment_id, 'status' => self::STATUS_HELD ]
		);
	}

	/** @return array{ok:bool,dispute_id:int,error:string} */
	public function open_dispute( int $payment_id, string $source, string $reason, int $tenant_id = 0 ): array {
		// The dispute must land on a payment this tenant actually owns.
		$owned = $this->db->row(
			'SELECT id FROM ' . $this->db->table( 'ig_master_payments' ) . ' WHERE id = %d AND tenant_id = %d',
			$payment_id,
			$tenant_id
		);
		if ( ! $owned ) {
			return [ 'ok' => false, 'dispute_id' => 0, 'error' => 'payment_not_found' ];
		}
		$now = current_time( 'mysql', true );
		$id  = (int) $this->db->insert(
			'ig_master_disputes',
			[
				'tenant_id'  => $tenant_id,
				'payment_id' => $payment_id,
				'source'     => $source,
				'reason'     => mb_substr( $reason, 0, 255 ),
				'status'     => 'open',
				'created_at' => $now,
				'updated_at' => $now,
			]
		);
		$this->db->update(
			'ig_master_payments',
			[ 'status' => self::STATUS_DISPUTED, 'updated_at' => $now ],
			[ 'id' => $payment_id, 'tenant_id' => $tenant_id ]
		);
		$this->logger->warning( 'master_payment', 'Dispute opened', [ 'payment_id' => $payment_id, 'source' => $source ] );

		return [ 'ok' => true, 'dispute_id' => $id, 'error' => '' ];
	}

	/** Digital agreement: precondition for using the master gateway. */
	public function accept_agreement( int $tenant_id, int $user_id, string $type = 'escrow' ): array {
		$content = wp_json_encode(
			[
				'type'    => $type,
				'version' => '1.0',
				'terms'   => 'Funds are held by the company until 24h after delivery with no dispute; release to the admin wallet; FX settled to the Cyprus account with a small fee.',
			]
		);
		$this->db->insert(
			'ig_master_agreements',
			[
				'tenant_id'     => $tenant_id,
				'type'          => $type,
				'version'       => '1.0',
				'accepted_by'   => $user_id,
				'accepted_at'   => current_time( 'mysql', true ),
				'ip'            => (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ),
				'content_hash'  => hash( 'sha256', $content ),
			]
		);

		return [ 'ok' => true, 'error' => '' ];
	}

	public function has_agreement( int $tenant_id, string $type = 'escrow' ): bool {
		$row = $this->db->row(
			'SELECT id FROM ' . $this->db->table( 'ig_master_agreements' ) . ' WHERE tenant_id = %d AND type = %s',
			$tenant_id,
			$type
		);
		return null !== $row;
	}

	private function has_open_dispute( int $payment_id ): bool {
		$count = (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'ig_master_disputes' ) . ' WHERE payment_id = %d AND status = %s',
			$payment_id,
			'open'
		);
		return $count > 0;
	}

	private function tenant_owner( int $tenant_id ): int {
		$row = $this->db->row(
			'SELECT user_id FROM ' . $this->db->table( 'tenant_members' ) . ' WHERE tenant_id = %d ORDER BY id ASC LIMIT 1',
			$tenant_id
		);
		return $row ? (int) $row['user_id'] : 0;
	}

	/** @return array<int,array<string,mixed>> */
	public function payments( int $tenant_id, int $limit = 50 ): array {
		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_master_payments' ) . ' WHERE tenant_id = %d ORDER BY id DESC LIMIT %d',
			$tenant_id,
			$limit
		);
	}

	/** @return array<int,array<string,mixed>> */
	public function disputes( int $tenant_id, int $limit = 50 ): array {
		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_master_disputes' ) . ' WHERE tenant_id = %d ORDER BY id DESC LIMIT %d',
			$tenant_id,
			$limit
		);
	}
	/**
	 * Admin requests a withdrawal from the released balance to card/bank.
	 *
	 * Phase 31: callers may pass an idempotency key; a replayed request with the same key
	 * returns the existing withdrawal instead of debiting the wallet twice (enforced by the
	 * per-tenant unique index, with the wallet reference keyed the same way).
	 */
	public function request_withdrawal( int $tenant_id, int $user_id, float $amount, string $method = 'card', string $detail = '', string $idempotency_key = '' ): array {
		if ( $amount <= 0 ) {
			return [ 'ok' => false, 'error' => __( 'Invalid amount.', 'igbz-suite' ) ];
		}

		if ( '' !== $idempotency_key ) {
			$existing = $this->db->row(
				'SELECT id FROM ' . $this->db->table( 'ig_master_withdrawals' ) . ' WHERE tenant_id = %d AND idempotency_key = %s',
				$tenant_id,
				$idempotency_key
			);
			if ( $existing ) {
				return [ 'ok' => true, 'error' => '', 'duplicate' => true, 'withdrawal_id' => (int) $existing['id'] ];
			}
		}
		$wallet = igbz()->has( 'wallet' ) ? igbz()->get( 'wallet' ) : null;
		if ( ! $wallet ) {
			return [ 'ok' => false, 'error' => __( 'Wallet unavailable.', 'igbz-suite' ) ];
		}
		$balance = $wallet->balance( $user_id );
		$available = (float) ( is_array( $balance ) ? ( $balance['balance'] ?? 0 ) : $balance );
		if ( $available < $amount ) {
			return [ 'ok' => false, 'error' => __( 'Insufficient wallet balance.', 'igbz-suite' ) ];
		}

		// The wallet reference carries the idempotency key when present — the ledger's unique
		// reference then absorbs any race the table insert did not already catch.
		$reference = '' !== $idempotency_key
			? 'withdraw:' . $tenant_id . ':' . $idempotency_key
			: 'withdraw:' . time() . ':' . $user_id;

		$ok = $wallet->debit(
			$user_id,
			$amount,
			'withdrawal',
			$reference,
			[ 'method' => $method ],
			$tenant_id
		);
		if ( ! $ok ) {
			return [ 'ok' => false, 'error' => __( 'Could not reserve the amount.', 'igbz-suite' ) ];
		}

		$withdrawal_id = (int) $this->db->insert(
			'ig_master_withdrawals',
			[
				'tenant_id'       => $tenant_id,
				'user_id'         => $user_id,
				'amount'          => $amount,
				'method'          => $method,
				'status'          => 'pending',
				'idempotency_key' => '' !== $idempotency_key ? $idempotency_key : null,
				'detail'          => mb_substr( $detail, 0, 255 ),
				'created_at'      => current_time( 'mysql', true ),
				'updated_at'      => current_time( 'mysql', true ),
			]
		);

		return [ 'ok' => true, 'error' => '', 'withdrawal_id' => $withdrawal_id ];
	}

	/** @return array<int,array<string,mixed>> */
	public function withdrawals( int $tenant_id, int $limit = 50 ): array {
		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_master_withdrawals' ) . ' WHERE tenant_id = %d ORDER BY id DESC LIMIT %d',
			$tenant_id,
			$limit
		);
	}
}

