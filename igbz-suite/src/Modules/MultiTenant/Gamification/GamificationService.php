<?php
namespace IGBZ\Suite\Modules\MultiTenant\Gamification;

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;
use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Gamification — phase 41: points, rewards, expiry, caps, race, abuse and a
 * reconcilable event ledger.
 *
 * The ledger is append-only and idempotent per (user, reason, reference): a
 * replayed event can never mint points twice. Earning is bounded by a daily
 * cap; every credit can carry an expiry; spending is a negative ledger row,
 * so one SUM reconciles everything — an expired row simply stops counting.
 */
final class GamificationService {

	public const REASON_REDEMPTION = 'redemption';

	/**
	 * Credit points. Returns the ledger row (existing one on replay).
	 *
	 * @return array{ok:bool,row:array<string,mixed>,error:string}
	 */
	public function credit( int $tenant_id, int $user_id, int $points, string $reason, string $reference, int $ttl_days = 0 ): array {
		if ( $points <= 0 || '' === $reason || '' === $reference || $user_id <= 0 ) {
			return [ 'ok' => false, 'row' => [], 'error' => 'invalid_credit' ];
		}

		$existing = $this->ledger_row( $user_id, $reason, $reference );
		if ( null !== $existing ) {
			return [ 'ok' => true, 'row' => $existing, 'error' => '' ]; // replay — idempotent
		}

		// Abuse guard: what this user earned today plus this credit must stay
		// within gamification.daily_cap (0 disables the cap).
		$cap = max( 0, $this->settings->int( 'gamification.daily_cap', 0 ) );
		if ( $cap > 0 && $this->earned_today( $tenant_id, $user_id ) + $points > $cap ) {
			$this->logger->warning( 'gamification', 'Daily point cap blocked a credit', [ 'user_id' => $user_id, 'points' => $points, 'cap' => $cap ] );
			return [ 'ok' => false, 'row' => [], 'error' => 'daily_cap_reached' ];
		}

		if ( $ttl_days <= 0 ) {
			$ttl_days = $this->settings->int( 'gamification.point_ttl_days', 0 );
		}

		$id = $this->db->insert(
			'ig_points_ledger',
			[
				'tenant_id'  => $tenant_id,
				'user_id'    => $user_id,
				'reason'     => mb_substr( $reason, 0, 64 ),
				'reference'  => mb_substr( $reference, 0, 191 ),
				'points'     => $points,
				'expires_at' => $ttl_days > 0 ? gmdate( 'Y-m-d H:i:s', time() + $ttl_days * DAY_IN_SECONDS ) : null,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			]
		);

		$this->logger->info( 'gamification', 'Points credited', [ 'user_id' => $user_id, 'reason' => $reason, 'points' => $points ] );

		return [ 'ok' => true, 'row' => $this->db->row( 'SELECT * FROM ' . $this->db->table( 'ig_points_ledger' ) . ' WHERE id = %d', (int) $id ) ?? [], 'error' => '' ];
	}

	/**
	 * Spendable balance: every non-expired ledger row summed — spends are
	 * negative rows with no expiry, so one query reconciles earn, spend and
	 * expiry together.
	 */
	public function balance( int $tenant_id, int $user_id ): int {
		return (int) $this->db->scalar(
			'SELECT COALESCE( SUM( points ), 0 ) FROM ' . $this->db->table( 'ig_points_ledger' ) . "\n\t\t\t WHERE tenant_id = %d AND user_id = %d AND ( expires_at IS NULL OR expires_at > %s )",
			$tenant_id,
			$user_id,
			gmdate( 'Y-m-d H:i:s' )
		);
	}

	/** Points earned today (positive ledger rows created today), for the daily cap. */
	public function earned_today( int $tenant_id, int $user_id ): int {
		return (int) $this->db->scalar(
			'SELECT COALESCE( SUM( points ), 0 ) FROM ' . $this->db->table( 'ig_points_ledger' ) . "\n\t\t\t WHERE tenant_id = %d AND user_id = %d AND points > 0 AND created_at >= %s",
			$tenant_id,
			$user_id,
			gmdate( 'Y-m-d' ) . ' 00:00:00'
		);
	}

	/** @return array<string,mixed>|null */
	public function reward( int $tenant_id, string $slug ): ?array {
		return $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'ig_point_rewards' ) . ' WHERE tenant_id = %d AND slug = %s',
			$tenant_id,
			$slug
		);
	}

	/**
	 * Redeem a reward: balance check → negative ledger row (idempotent per
	 * user + idempotency key) → issued redemption. One ledger, so the spend
	 * is reconcilable by the same SUM as the balance.
	 *
	 * @return array{ok:bool,redemption:array<string,mixed>,error:string}
	 */
	public function redeem( int $tenant_id, int $user_id, string $slug, string $idempotency_key ): array {
		if ( '' === $idempotency_key ) {
			return [ 'ok' => false, 'redemption' => [], 'error' => 'missing_idempotency_key' ];
		}

		$existing = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'ig_reward_redemptions' ) . ' WHERE user_id = %d AND idempotency_key = %s',
			$user_id,
			$idempotency_key
		);
		if ( null !== $existing ) {
			return [ 'ok' => true, 'redemption' => $existing, 'error' => '' ]; // replay — idempotent
		}

		$reward = $this->reward( $tenant_id, $slug );
		if ( null === $reward || (int) $reward['is_active'] !== 1 || (int) $reward['cost_points'] <= 0 ) {
			return [ 'ok' => false, 'redemption' => [], 'error' => 'reward_unavailable' ];
		}

		$cost = (int) $reward['cost_points'];
		if ( $this->balance( $tenant_id, $user_id ) < $cost ) {
			return [ 'ok' => false, 'redemption' => [], 'error' => 'insufficient_points' ];
		}

		$spend = $this->db->insert(
			'ig_points_ledger',
			[
				'tenant_id'  => $tenant_id,
				'user_id'    => $user_id,
				'reason'     => self::REASON_REDEMPTION,
				'reference'  => $idempotency_key,
				'points'     => -$cost,
				'expires_at' => null,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			]
		);
		if ( 0 === $spend ) {
			return [ 'ok' => false, 'redemption' => [], 'error' => 'ledger_rejected' ];
		}

		$id = $this->db->insert(
			'ig_reward_redemptions',
			[
				'tenant_id'       => $tenant_id,
				'user_id'         => $user_id,
				'reward_id'       => (int) $reward['id'],
				'points_spent'    => $cost,
				'idempotency_key' => $idempotency_key,
				'status'          => 'issued',
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			]
		);

		$this->logger->info( 'gamification', 'Reward redeemed', [ 'user_id' => $user_id, 'slug' => $slug, 'points_spent' => $cost ] );

		return [ 'ok' => true, 'redemption' => $this->db->row( 'SELECT * FROM ' . $this->db->table( 'ig_reward_redemptions' ) . ' WHERE id = %d', (int) $id ) ?? [], 'error' => '' ];
	}

	/**
	 * Race / leaderboard: top earners within the window. Negative rows
	 * (spends) are excluded — a race counts earning, not shopping.
	 *
	 * @return array<int,array{user_id:int,points:int}>
	 */
	public function race( int $tenant_id, int $window_days = 30, int $limit = 10 ): array {
		$rows = $this->db->results(
			'SELECT user_id, SUM( points ) AS points FROM ' . $this->db->table( 'ig_points_ledger' ) . "\n\t\t\t WHERE tenant_id = %d AND points > 0 AND created_at >= %s GROUP BY user_id ORDER BY points DESC LIMIT %d",
			$tenant_id,
			gmdate( 'Y-m-d H:i:s', time() - max( 1, $window_days ) * DAY_IN_SECONDS ),
			max( 1, $limit )
		);

		return array_map(
			static fn ( $r ): array => [ 'user_id' => (int) $r['user_id'], 'points' => (int) $r['points'] ],
			$rows
		);
	}

	/**
	 * Reconciliation report — the ledger must explain itself:
	 *  - expired_now: rows past their deadline (no longer counting),
	 *  - negative_balances: users whose SUM dropped below zero (must never
	 *    happen; a bug or tampering signal),
	 *  - duplicate_keys: redemptions sharing a user + key (unique-index
	 *    violations that somehow landed).
	 *
	 * @return array{expired_now:int,negative_balances:int,duplicate_keys:int}
	 */
	public function reconcile(): array {
		return [
			'expired_now'        => (int) $this->db->scalar(
				'SELECT COUNT(*) FROM ' . $this->db->table( 'ig_points_ledger' ) . ' WHERE expires_at IS NOT NULL AND expires_at <= %s',
				gmdate( 'Y-m-d H:i:s' )
			),
			'negative_balances'  => (int) $this->db->scalar(
				'SELECT COUNT(*) FROM ( SELECT user_id, SUM( points ) AS total FROM ' . $this->db->table( 'ig_points_ledger' ) . "\n\t\t\t\t WHERE expires_at IS NULL OR expires_at > %s GROUP BY user_id HAVING SUM( points ) < 0 ) negatives",
				gmdate( 'Y-m-d H:i:s' )
			),
			'duplicate_keys'     => (int) $this->db->scalar(
				'SELECT COUNT(*) FROM ( SELECT user_id, idempotency_key FROM ' . $this->db->table( 'ig_reward_redemptions' ) . "\n\t\t\t\t WHERE idempotency_key IS NOT NULL GROUP BY user_id, idempotency_key HAVING COUNT(*) > 1 ) dupes"
			),
		];
	}

	/** @return array<string,mixed>|null */
	private function ledger_row( int $user_id, string $reason, string $reference ): ?array {
		return $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'ig_points_ledger' ) . ' WHERE user_id = %d AND reason = %s AND reference = %s',
			$user_id,
			$reason,
			$reference
		);
	}

	public function __construct(
		private Db $db,
		private Settings $settings,
		private Logger $logger
	) {}
}
