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

		$owner = $this->tenant_owner( (int) $row['tenant_id'] );
		$ok    = $wallet->credit(
			$owner,
			(float) $row['amount'],
			\IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService::REASON_TOPUP,
			'master:' . (int) $row['id'],
			[ 'master_payment' => (int) $row['id'], 'order' => (int) $row['order_id'] ],
			(int) $row['tenant_id']
		);

		if ( ! $ok ) {
			// Idempotent key exists -> already released.
			$this->db->update(
				'ig_master_payments',
				[ 'status' => self::STATUS_RELEASED, 'released_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ],
				[ 'id' => (int) $row['id'] ]
			);
			return false;
		}

		$this->db->update(
			'ig_master_payments',
			[ 'status' => self::STATUS_RELEASED, 'released_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => (int) $row['id'] ]
		);
		$this->logger->info( 'master_payment', 'Payment released to admin wallet', [ 'payment_id' => (int) $row['id'], 'amount' => (float) $row['amount'] ] );

		return true;
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

	/** @return array{ok:bool,error:string} */
	public function refund( int $payment_id, int $tenant_id = 0 ): array {
		$this->db->update(
			'ig_master_payments',
			[ 'status' => self::STATUS_REFUNDED, 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $payment_id, 'tenant_id' => $tenant_id ]
		);
		$this->logger->info( 'master_payment', 'Payment refunded', [ 'payment_id' => $payment_id ] );

		return [ 'ok' => true, 'error' => '' ];
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
	/** Admin requests a withdrawal from the released balance to card/bank. */
	public function request_withdrawal( int $tenant_id, int $user_id, float $amount, string $method = 'card', string $detail = '' ): array {
		if ( $amount <= 0 ) {
			return [ 'ok' => false, 'error' => __( 'Invalid amount.', 'igbz-suite' ) ];
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

		$ok = $wallet->debit(
			$user_id,
			$amount,
			'withdrawal',
			'withdraw:' . time() . ':' . $user_id,
			[ 'method' => $method ],
			$tenant_id
		);
		if ( ! $ok ) {
			return [ 'ok' => false, 'error' => __( 'Could not reserve the amount.', 'igbz-suite' ) ];
		}

		$this->db->insert(
			'ig_master_withdrawals',
			[
				'tenant_id'  => $tenant_id,
				'user_id'    => $user_id,
				'amount'     => $amount,
				'method'     => $method,
				'status'     => 'pending',
				'detail'     => mb_substr( $detail, 0, 255 ),
				'created_at' => current_time( 'mysql', true ),
				'updated_at' => current_time( 'mysql', true ),
			]
		);

		return [ 'ok' => true, 'error' => '' ];
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

