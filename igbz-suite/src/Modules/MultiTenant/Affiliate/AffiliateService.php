<?php
namespace IGBZ\Suite\Modules\MultiTenant\Affiliate;

use IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService;
use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Two-tier affiliate / referral engine.
 *
 * Port note: the nopCommerce original had TWO competing implementations wired up simultaneously
 * (GamificationAndAffiliateService and AffiliateMarketingService + AffiliateCommissionOrderConsumer),
 * both DI-registered, which risked double-paying commissions. This is the single code path.
 * Commission rows carry a UNIQUE (order_id, affiliate_id, tier) index so a replayed order hook
 * can never pay twice.
 */
final class AffiliateService {

	public const COOKIE = 'igbz_ref';

	public const STATUS_PENDING  = 'pending';
	public const STATUS_APPROVED = 'approved';
	public const STATUS_PAID     = 'paid';
	public const STATUS_REJECTED = 'rejected';

	public function __construct( private Db $db, private WalletService $wallet, private Logger $logger ) {}

	// ------------------------------------------------------------- accounts

	/** @return array<string,mixed>|null */
	public function find( int $affiliate_id ): ?array {
		return $this->db->row( 'SELECT * FROM ' . $this->db->table( 'affiliates' ) . ' WHERE id = %d AND tenant_id = %d', $affiliate_id, igbz()->tenancy()->id() );
	}

	/** @return array<string,mixed>|null */
	public function find_by_code( string $code ): ?array {
		return $this->db->row( 'SELECT * FROM ' . $this->db->table( 'affiliates' ) . ' WHERE code = %s', $code );
	}

	/** @return array<string,mixed>|null */
	public function find_by_user( int $user_id, int $tenant_id = 0 ): ?array {
		return $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'affiliates' ) . ' WHERE user_id = %d AND tenant_id = %d',
			$user_id,
			$tenant_id
		);
	}

	public function enroll( int $user_id, int $tenant_id = 0, int $parent_affiliate_id = 0 ): array {
		$existing = $this->find_by_user( $user_id, $tenant_id );
		if ( $existing ) {
			return $existing;
		}

		// Phase 40 — self-referral gate: a user cannot be their own parent.
		if ( $parent_affiliate_id > 0 ) {
			$parent = $this->find( $parent_affiliate_id );
			if ( ! $parent || (int) $parent['user_id'] === $user_id ) {
				$parent_affiliate_id = 0;
			}
		}

		$code = $this->generate_code( $user_id );
		$this->db->insert(
			'affiliates',
			[
				'tenant_id'       => $tenant_id,
				'user_id'         => $user_id,
				'code'            => $code,
				'parent_id'       => $parent_affiliate_id,
				'tier'            => $parent_affiliate_id > 0 ? 2 : 1,
				'commission_rate' => (float) igbz()->settings()->get( 'affiliate.tier1_rate', 0 ),
				'status'          => 'active',
				'created_at'      => current_time( 'mysql', true ),
			]
		);

		$affiliate = $this->find_by_user( $user_id, $tenant_id ) ?? [];
		do_action( 'igbz_affiliate_enrolled', (int) ( $affiliate['id'] ?? 0 ), $user_id );
		return $affiliate;
	}

	private function generate_code( int $user_id ): string {
		do {
			$code   = strtoupper( substr( base_convert( (string) $user_id, 10, 36 ) . Crypto::token( 3 ), 0, 8 ) );
			$exists = $this->db->scalar( 'SELECT id FROM ' . $this->db->table( 'affiliates' ) . ' WHERE code = %s', $code );
		} while ( $exists );
		return $code;
	}

	public function referral_url( string $code, string $target = '' ): string {
		$base = '' !== $target ? $target : home_url( '/' );
		return add_query_arg( 'ref', rawurlencode( $code ), $base );
	}

	// -------------------------------------------------------------- tracking

	/** Store the referral in a cookie and record the click. Called on every front-end request. */
	public function capture_click(): void {
		// Read-only tracking parameter; no state change requires a nonce here.
		$code = isset( $_GET['ref'] ) ? sanitize_text_field( wp_unslash( $_GET['ref'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $code ) {
			return;
		}

		$affiliate = $this->find_by_code( $code );
		if ( ! $affiliate || 'active' !== $affiliate['status'] ) {
			return;
		}

		$days = (int) igbz()->settings()->get( 'affiliate.cookie_days', 30 );
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$code,
				[
					'expires'  => time() + $days * DAY_IN_SECONDS,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				]
			);
		}
		$_COOKIE[ self::COOKIE ] = $code;

		$this->db->insert(
			'referral_clicks',
			[
				'tenant_id'    => (int) $affiliate['tenant_id'],
				'affiliate_id' => (int) $affiliate['id'],
				'source'       => isset( $_GET['utm_source'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_source'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
				'landing_url'  => esc_url_raw( ( is_ssl() ? 'https://' : 'http://' ) . ( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '' ) . ( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' ) ),
				'ip_hash'      => $this->ip_hash(),
				'user_agent'   => isset( $_SERVER['HTTP_USER_AGENT'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '',
				'created_at'   => current_time( 'mysql', true ),
			]
		);

		$this->db->query( 'UPDATE ' . $this->db->table( 'affiliates' ) . ' SET clicks = clicks + 1 WHERE id = %d', (int) $affiliate['id'] );
	}

	private function ip_hash(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return '' === $ip ? '' : hash( 'sha256', $ip . ( defined( 'AUTH_SALT' ) ? AUTH_SALT : '' ) );
	}

	public function cookie_code(): string {
		return isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
	}

	/** Bind a newly registered user to the referring affiliate. */
	public function attach_referral_to_user( int $user_id ): void {
		$code = $this->cookie_code();
		if ( '' === $code ) {
			return;
		}
		$affiliate = $this->find_by_code( $code );
		if ( ! $affiliate || (int) $affiliate['user_id'] === $user_id ) {
			return;
		}

		update_user_meta( $user_id, '_igbz_referred_by', (int) $affiliate['id'] );
		$this->db->query( 'UPDATE ' . $this->db->table( 'affiliates' ) . ' SET signups = signups + 1 WHERE id = %d', (int) $affiliate['id'] );
		$this->db->query(
			'UPDATE ' . $this->db->table( 'referral_clicks' ) . ' SET converted_user_id = %d WHERE affiliate_id = %d AND converted_user_id = 0 ORDER BY id DESC LIMIT 1',
			$user_id,
			(int) $affiliate['id']
		);

		do_action( 'igbz_referral_converted', (int) $affiliate['id'], $user_id );
	}

	// ------------------------------------------------------------ commission

	/**
	 * Record commissions for an order across both tiers. Safe to call repeatedly.
	 */
	public function record_order_commission( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$customer_id = (int) $order->get_customer_id();
		$affiliate   = $this->resolve_order_affiliate( $order, $customer_id );
		if ( ! $affiliate ) {
			return;
		}
		if ( (int) $affiliate['user_id'] === $customer_id ) {
			return; // no self-referral
		}

		$tenant_id = (int) $order->get_meta( '_igbz_tenant_id' );
		$base      = (float) $order->get_subtotal() - (float) $order->get_total_discount();
		$base      = (float) apply_filters( 'igbz_affiliate_commission_base', $base, $order );
		if ( $base <= 0 ) {
			return;
		}

		$tier1_rate = (float) ( $affiliate['commission_rate'] > 0 ? $affiliate['commission_rate'] : igbz()->settings()->get( 'affiliate.tier1_rate', 0 ) );
		$this->insert_commission( (int) $affiliate['id'], $order_id, $customer_id, 1, $base, $tier1_rate, $tenant_id );

		$parent_id = (int) $affiliate['parent_id'];
		if ( $parent_id > 0 ) {
			$tier2_rate = (float) igbz()->settings()->get( 'affiliate.tier2_rate', 0 );
			if ( $tier2_rate > 0 ) {
				$this->insert_commission( $parent_id, $order_id, $customer_id, 2, $base, $tier2_rate, $tenant_id );
			}
		}
	}

	/** @return array<string,mixed>|null */
	private function resolve_order_affiliate( \WC_Order $order, int $customer_id ): ?array {
		$stored = (int) $order->get_meta( '_igbz_affiliate_id' );
		if ( $stored > 0 ) {
			return $this->find( $stored );
		}
		if ( $customer_id > 0 ) {
			$referred_by = (int) get_user_meta( $customer_id, '_igbz_referred_by', true );
			if ( $referred_by > 0 ) {
				return $this->find( $referred_by );
			}
		}
		$code = $this->cookie_code();
		return '' !== $code ? $this->find_by_code( $code ) : null;
	}

	private function insert_commission( int $affiliate_id, int $order_id, int $referred_user_id, int $tier, float $base, float $rate, int $tenant_id ): void {
		if ( $rate <= 0 ) {
			return;
		}
		$amount = round( $base * $rate / 100, 2 );
		if ( $amount <= 0 ) {
			return;
		}

		$id = $this->db->insert(
			'affiliate_commissions',
			[
				'tenant_id'        => $tenant_id,
				'affiliate_id'     => $affiliate_id,
				'order_id'         => $order_id,
				'referred_user_id' => $referred_user_id,
				'tier'             => $tier,
				'base_amount'      => $base,
				'rate'             => $rate,
				'amount'           => $amount,
				'status'           => self::STATUS_PENDING,
				'created_at'       => current_time( 'mysql', true ),
			]
		);

		if ( 0 === $id ) {
			return; // unique index rejected a duplicate - already recorded.
		}

		$this->logger->info( 'affiliate', 'Commission recorded', [ 'affiliate_id' => $affiliate_id, 'order_id' => $order_id, 'tier' => $tier, 'amount' => $amount ] );
		do_action( 'igbz_affiliate_commission_recorded', $id, $affiliate_id, $order_id, $amount );
	}

	/**
	 * Reverse commissions when an order is refunded or cancelled. Pending and
	 * approved-but-unpaid rows are rejected outright; rows already PAID stay
	 * paid — the money moved, so fraud_report() surfaces them instead of
	 * pretending the reversal happened.
	 */
	public function void_order_commission( int $order_id ): void {
		$this->db->query(
			'UPDATE ' . $this->db->table( 'affiliate_commissions' ) . '\n\t\t\t SET status = %s WHERE order_id = %d AND status IN ( %s, %s )',
			self::STATUS_REJECTED,
			$order_id,
			self::STATUS_PENDING,
			self::STATUS_APPROVED
		);
	}

	/**
	 * Phase 40 — fraud report: REPORT ONLY, nothing is punished automatically.
	 *
	 * Signals: self-referral commissions; one IP hash converting two or more
	 * distinct users; affiliates with at least affiliate.fraud_min_commissions
	 * (default 3) commissions and a rejected share above half; and paid
	 * commissions whose order the shop later refunded — the debt the void path
	 * cannot undo.
	 *
	 * @return array{self_referrals:int,shared_ip_groups:int,high_refund_affiliates:array<int,int>,paid_on_refunded_orders:int}
	 */
	public function fraud_report( int $tenant_id = 0 ): array {
		$commissions = 0 === $tenant_id
			? $this->db->results( 'SELECT * FROM ' . $this->db->table( 'affiliate_commissions' ) . ' LIMIT 5000' )
			: $this->db->results(
				'SELECT * FROM ' . $this->db->table( 'affiliate_commissions' ) . ' WHERE tenant_id = %d LIMIT 5000',
				$tenant_id
			);
		$affiliates = 0 === $tenant_id
			? $this->db->results( 'SELECT * FROM ' . $this->db->table( 'affiliates' ) . ' LIMIT 5000' )
			: $this->db->results(
				'SELECT * FROM ' . $this->db->table( 'affiliates' ) . ' WHERE tenant_id = %d LIMIT 5000',
				$tenant_id
			);

		$owner_of = [];
		foreach ( $affiliates as $a ) {
			$owner_of[ (int) $a['id'] ] = (int) $a['user_id'];
		}

		$self_referrals = 0;
		$per_affiliate  = [];
		foreach ( $commissions as $c ) {
			if ( ( $owner_of[ (int) $c['affiliate_id'] ] ?? 0 ) === (int) $c['referred_user_id'] && (int) $c['referred_user_id'] > 0 ) {
				++$self_referrals;
			}
			$aid = (int) $c['affiliate_id'];
			$per_affiliate[ $aid ]['total'] = ( $per_affiliate[ $aid ]['total'] ?? 0 ) + 1;
			if ( self::STATUS_REJECTED === (string) $c['status'] ) {
				$per_affiliate[ $aid ]['rejected'] = ( $per_affiliate[ $aid ]['rejected'] ?? 0 ) + 1;
			}
		}

		$min_commissions = max( 1, igbz()->settings()->int( 'affiliate.fraud_min_commissions', 3 ) );
		$high_refund     = [];
		foreach ( $per_affiliate as $aid => $stat ) {
			if ( $stat['total'] >= $min_commissions && ( $stat['rejected'] ?? 0 ) * 2 > $stat['total'] ) {
				$high_refund[] = $aid;
			}
		}
		sort( $high_refund );

		$clicks = 0 === $tenant_id
			? $this->db->results( 'SELECT ip_hash, converted_user_id FROM ' . $this->db->table( 'referral_clicks' ) . ' WHERE converted_user_id > 0 LIMIT 5000' )
			: $this->db->results(
				'SELECT ip_hash, converted_user_id FROM ' . $this->db->table( 'referral_clicks' ) . ' WHERE tenant_id = %d AND converted_user_id > 0 LIMIT 5000',
				$tenant_id
			);
		$by_ip = [];
		foreach ( $clicks as $click ) {
			$ip = (string) $click['ip_hash'];
			if ( '' === $ip ) {
				continue;
			}
			$by_ip[ $ip ][ (int) $click['converted_user_id'] ] = true;
		}
		$shared_ip_groups = 0;
		foreach ( $by_ip as $users ) {
			if ( count( $users ) > 1 ) {
				++$shared_ip_groups;
			}
		}

		$paid_on_refunded = 0;
		foreach ( $commissions as $c ) {
			if ( self::STATUS_PAID !== (string) $c['status'] ) {
				continue;
			}
			$order = wc_get_order( (int) $c['order_id'] );
			if ( $order && in_array( $order->get_status(), [ 'refunded', 'cancelled' ], true ) ) {
				++$paid_on_refunded;
			}
		}

		return [
			'self_referrals'          => $self_referrals,
			'shared_ip_groups'        => $shared_ip_groups,
			'high_refund_affiliates'  => $high_refund,
			'paid_on_refunded_orders' => $paid_on_refunded,
		];
	}

	/**
	 * Approve commissions that have passed the hold window, then credit the affiliate's wallet.
	 */
	public function process_pending_commissions(): int {
		$hold   = (int) igbz()->settings()->get( 'affiliate.approve_after_days', 7 );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $hold * DAY_IN_SECONDS );

		$rows = $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'affiliate_commissions' ) . '
			 WHERE status = %s AND created_at <= %s LIMIT 200',
			self::STATUS_PENDING,
			$cutoff
		);

		$paid = 0;
		foreach ( $rows as $row ) {
			$order = wc_get_order( (int) $row['order_id'] );
			if ( $order && in_array( $order->get_status(), [ 'refunded', 'cancelled', 'failed' ], true ) ) {
				$this->db->update( 'affiliate_commissions', [ 'status' => self::STATUS_REJECTED ], [ 'id' => (int) $row['id'] ] );
				continue;
			}

			$affiliate = $this->find( (int) $row['affiliate_id'] );
			if ( ! $affiliate ) {
				continue;
			}

			$result = $this->wallet->credit(
				(int) $affiliate['user_id'],
				(float) $row['amount'],
				WalletService::REASON_COMMISSION,
				'commission:' . (int) $row['id'],
				[ 'order_id' => (int) $row['order_id'], 'tier' => (int) $row['tier'] ],
				(int) $row['tenant_id'],
				(int) $row['order_id'],
				__( 'Affiliate commission', 'igbz-suite' )
			);

			if ( ! $result->success ) {
				continue;
			}

			$now = current_time( 'mysql', true );
			$this->db->update(
				'affiliate_commissions',
				[ 'status' => self::STATUS_PAID, 'approved_at' => $now, 'paid_at' => $now ],
				[ 'id' => (int) $row['id'] ]
			);
			$this->db->query(
				'UPDATE ' . $this->db->table( 'affiliates' ) . ' SET total_earned = total_earned + %f, total_paid = total_paid + %f WHERE id = %d',
				(float) $row['amount'],
				(float) $row['amount'],
				(int) $row['affiliate_id']
			);
			$paid++;
		}

		return $paid;
	}

	// ------------------------------------------------------------ reporting

	/** @return array{clicks:int,signups:int,orders:int,pending:float,paid:float,balance:float} */
	public function stats( int $affiliate_id ): array {
		$affiliate = $this->find( $affiliate_id );
		if ( ! $affiliate ) {
			return [ 'clicks' => 0, 'signups' => 0, 'orders' => 0, 'pending' => 0.0, 'paid' => 0.0, 'balance' => 0.0 ];
		}
		$row = $this->db->row(
			'SELECT
				COUNT(DISTINCT order_id) AS orders,
				COALESCE(SUM(CASE WHEN status = %s THEN amount ELSE 0 END),0) AS pending,
				COALESCE(SUM(CASE WHEN status = %s THEN amount ELSE 0 END),0) AS paid
			 FROM ' . $this->db->table( 'affiliate_commissions' ) . ' WHERE affiliate_id = %d',
			self::STATUS_PENDING,
			self::STATUS_PAID,
			$affiliate_id
		);
		return [
			'clicks'  => (int) $affiliate['clicks'],
			'signups' => (int) $affiliate['signups'],
			'orders'  => (int) ( $row['orders'] ?? 0 ),
			'pending' => (float) ( $row['pending'] ?? 0 ),
			'paid'    => (float) ( $row['paid'] ?? 0 ),
			'balance' => $this->wallet->balance( (int) $affiliate['user_id'], (int) $affiliate['tenant_id'] ),
		];
	}

	/** @return array<int,array<string,mixed>> */
	public function commissions( int $affiliate_id, int $limit = 50, int $offset = 0 ): array {
		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'affiliate_commissions' ) . ' WHERE affiliate_id = %d ORDER BY id DESC LIMIT %d OFFSET %d',
			$affiliate_id,
			$limit,
			$offset
		);
	}

	/** @return array<int,array<string,mixed>> */
	public function leaderboard( int $tenant_id = 0, int $limit = 10 ): array {
		return $this->db->results(
			'SELECT a.*, COALESCE(SUM(c.amount),0) AS earned
			 FROM ' . $this->db->table( 'affiliates' ) . ' a
			 LEFT JOIN ' . $this->db->table( 'affiliate_commissions' ) . ' c
				ON c.affiliate_id = a.id AND c.status = %s
			 WHERE a.tenant_id = %d
			 GROUP BY a.id ORDER BY earned DESC LIMIT %d',
			self::STATUS_PAID,
			$tenant_id,
			$limit
		);
	}
}
