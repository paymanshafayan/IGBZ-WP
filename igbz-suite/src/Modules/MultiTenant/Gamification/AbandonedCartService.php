<?php
namespace IGBZ\Suite\Modules\MultiTenant\Gamification;

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Abandoned-cart recovery: watch carts, remind after N hours with a limited
 * coupon, once. Reminders go through the notification dispatcher (SMS/push)
 * when available; otherwise a log entry is written.
 */
final class AbandonedCartService {

	public const STATUS_OPEN = 'open';
	public const STATUS_SENT = 'sent';

	public function __construct(
		private Db $db,
		private Logger $logger
	) {}

	public function watch( int $user_id, string $session_key, float $total ): void {
		$existing = $this->db->row(
			'SELECT id FROM ' . $this->db->table( 'ig_abandoned_carts' ) . '
			 WHERE session_key = %s AND status = %s AND tenant_id = %d AND user_id = %d LIMIT 1',
			$session_key,
			self::STATUS_OPEN,
			igbz()->tenancy()->id(),
			$user_id
		);
		if ( $existing ) {
			$this->db->update(
				'ig_abandoned_carts',
				[ 'cart_total' => $total, 'updated_at' => current_time( 'mysql', true ) ],
				[ 'id' => (int) $existing['id'] ]
			);
			return;
		}

		$now = current_time( 'mysql', true );
		$this->db->insert(
			'ig_abandoned_carts',
			[
				'tenant_id'   => (int) igbz()->tenancy()->id(),
				'user_id'     => $user_id,
				'session_key' => $session_key,
				'cart_total'  => $total,
				'status'      => self::STATUS_OPEN,
				'created_at'  => $now,
				'updated_at'  => $now,
			]
		);
	}

	/** Cron sweep: remind carts older than the threshold, once each. */
	public function sweep(): int {
		$after_hours = (int) igbz()->settings()->int( 'abandoned_cart.remind_after_hours', 6 );
		$cutoff      = gmdate( 'Y-m-d H:i:s', time() - $after_hours * HOUR_IN_SECONDS );

		$rows = $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_abandoned_carts' ) . '
			 WHERE status = %s AND updated_at <= %s ORDER BY id ASC LIMIT 50',
			self::STATUS_OPEN,
			$cutoff
		);

		$sent = 0;
		foreach ( $rows as $row ) {
			if ( $this->remind( $row ) ) {
				++$sent;
			}
		}

		return $sent;
	}

	/** @param array<string,mixed> $row */
	private function remind( array $row ): bool {
		if ( 0 === (int) $row['user_id'] ) {
			$this->db->update( 'ig_abandoned_carts', [ 'status' => self::STATUS_SENT, 'updated_at' => current_time( 'mysql', true ) ], [ 'id' => (int) $row['id'] ] );
			return false;
		}

		$percent = (float) igbz()->settings()->float( 'abandoned_cart.discount_percent', 30 );
		$code    = igbz()->settings()->string( 'abandoned_cart.coupon_prefix', 'CART' ) . '-' . (int) $row['user_id'] . '-' . gmdate( 'ymdHis' );

		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( $percent );
		$coupon->set_individual_use( true );
		$coupon->set_usage_limit( 1 );
		$coupon->set_date_expires( time() + 2 * HOUR_IN_SECONDS );
		$coupon->save();

		$this->db->update(
			'ig_abandoned_carts',
			[
				'status'           => self::STATUS_SENT,
				'coupon_code'      => $code,
				'reminder_sent_at' => current_time( 'mysql', true ),
				'updated_at'       => current_time( 'mysql', true ),
			],
			[ 'id' => (int) $row['id'] ]
		);

		// Deliver through whatever notification path exists (push/SMS); log as the fallback.
		if ( igbz()->has( 'api.notifications' ) ) {
			igbz()->get( 'api.notifications' )->send_to_user(
				(int) $row['user_id'],
				sprintf( /* translators: %s: coupon code */ __( 'Your cart is waiting! Use %s for %s%% off — valid for 2 hours.', 'igbz-suite' ), $code, (string) $percent ),
				'abandoned_cart'
			);
		} else {
			$this->logger->info( 'gamification', 'Abandoned-cart reminder', [ 'user_id' => (int) $row['user_id'], 'code' => $code ] );
		}

		return true;
	}

	/** @return array<int,array<string,mixed>> */
	public function carts( int $limit = 50 ): array {
		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_abandoned_carts' ) . ' ORDER BY id DESC LIMIT %d',
			$limit
		);
	}
}
