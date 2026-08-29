<?php
namespace IGBZ\Suite\Modules\MultiTenant\Plans;

use IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * SaaS plans and tenant subscriptions: quota enforcement, renewal from the wallet, grace periods
 * and downgrade-on-failure.
 */
final class PlanService {

	public const STATUS_ACTIVE    = 'active';
	public const STATUS_TRIALING  = 'trialing';
	public const STATUS_PAST_DUE  = 'past_due';
	public const STATUS_CANCELLED = 'cancelled';
	public const STATUS_EXPIRED   = 'expired';
	public const STATUS_SUSPENDED = 'suspended';

	public function __construct( private Db $db, private WalletService $wallet, private Logger $logger ) {}

	// ------------------------------------------------------------------ plans

	/** @return array<int,array<string,mixed>> */
	public function plans( bool $only_active = true ): array {
		$sql = 'SELECT * FROM ' . $this->db->table( 'plans' );
		if ( $only_active ) {
			$sql .= ' WHERE is_active = 1';
		}
		return $this->db->results( $sql . ' ORDER BY sort_order, price' );
	}

	/** @return array<string,mixed>|null */
	public function plan( int $id ): ?array {
		return $this->db->row( 'SELECT * FROM ' . $this->db->table( 'plans' ) . ' WHERE id = %d', $id );
	}

	/** @return array<string,mixed>|null */
	public function plan_by_slug( string $slug ): ?array {
		return $this->db->row( 'SELECT * FROM ' . $this->db->table( 'plans' ) . ' WHERE slug = %s', $slug );
	}

	/** @param array<string,mixed> $data */
	public function save_plan( array $data, int $id = 0 ): int {
		$now     = current_time( 'mysql', true );
		$payload = [
			'slug'           => sanitize_title( (string) ( $data['slug'] ?? $data['name'] ?? 'plan' ) ),
			'name'           => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'description'    => wp_kses_post( (string) ( $data['description'] ?? '' ) ),
			'price'          => (float) ( $data['price'] ?? 0 ),
			'currency'       => (string) ( $data['currency'] ?? 'IRT' ),
			'billing_period' => in_array( $data['billing_period'] ?? 'monthly', [ 'monthly', 'quarterly', 'yearly', 'lifetime' ], true ) ? (string) $data['billing_period'] : 'monthly',
			'trial_days'     => (int) ( $data['trial_days'] ?? 0 ),
			'max_products'   => (int) ( $data['max_products'] ?? 0 ),
			'max_orders'     => (int) ( $data['max_orders'] ?? 0 ),
			'max_staff'      => (int) ( $data['max_staff'] ?? 0 ),
			'features'       => wp_json_encode( (array) ( $data['features'] ?? [] ) ),
			'is_active'      => empty( $data['is_active'] ) ? 0 : 1,
			'sort_order'     => (int) ( $data['sort_order'] ?? 0 ),
			'updated_at'     => $now,
		];

		if ( $id > 0 ) {
			$this->db->update( 'plans', $payload, [ 'id' => $id ] );
			return $id;
		}
		$payload['created_at'] = $now;
		return $this->db->insert( 'plans', $payload );
	}

	public function delete_plan( int $id ): bool {
		$in_use = (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'subscriptions' ) . ' WHERE plan_id = %d AND status IN (%s,%s)',
			$id,
			self::STATUS_ACTIVE,
			self::STATUS_TRIALING
		);
		if ( $in_use > 0 ) {
			return false;
		}
		return $this->db->delete( 'plans', [ 'id' => $id ] ) > 0;
	}

	// ---------------------------------------------------------- subscriptions

	/** @return array<string,mixed>|null */
	public function active_subscription( int $tenant_id ): ?array {
		return $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'subscriptions' ) . '
			 WHERE tenant_id = %d AND status IN (%s,%s,%s)
			 ORDER BY id DESC LIMIT 1',
			$tenant_id,
			self::STATUS_ACTIVE,
			self::STATUS_TRIALING,
			self::STATUS_PAST_DUE
		);
	}

	public function subscribe( int $tenant_id, int $plan_id, bool $start_trial = true ): int {
		$plan = $this->plan( $plan_id );
		if ( ! $plan ) {
			throw new \RuntimeException( 'Plan not found.' );
		}

		$this->db->query(
			'UPDATE ' . $this->db->table( 'subscriptions' ) . ' SET status = %s, cancelled_at = %s, updated_at = %s WHERE tenant_id = %d AND status IN (%s,%s,%s)',
			self::STATUS_CANCELLED,
			current_time( 'mysql', true ),
			current_time( 'mysql', true ),
			$tenant_id,
			self::STATUS_ACTIVE,
			self::STATUS_TRIALING,
			self::STATUS_PAST_DUE
		);

		$trial_days = $start_trial ? (int) $plan['trial_days'] : 0;
		$status     = $trial_days > 0 ? self::STATUS_TRIALING : self::STATUS_ACTIVE;
		$now        = current_time( 'mysql', true );
		$ends       = gmdate( 'Y-m-d H:i:s', $this->period_end( time(), (string) $plan['billing_period'], $trial_days ) );

		$id = $this->db->insert(
			'subscriptions',
			[
				'tenant_id'       => $tenant_id,
				'plan_id'         => $plan_id,
				'status'          => $status,
				'starts_at'       => $now,
				'ends_at'         => $ends,
				'auto_renew'      => 1,
				'price_paid'      => $trial_days > 0 ? 0 : (float) $plan['price'],
				'last_invoice_at' => $trial_days > 0 ? null : $now,
				'created_at'      => $now,
				'updated_at'      => $now,
			]
		);

		( new \IGBZ\Suite\Modules\MultiTenant\Repository\TenantRepository( $this->db ) )->update(
			$tenant_id,
			[ 'plan_id' => $plan_id, 'status' => \IGBZ\Suite\Modules\MultiTenant\Repository\Tenant::STATUS_ACTIVE ]
		);

		do_action( 'igbz_subscription_started', $id, $tenant_id, $plan_id );
		return $id;
	}

	/**
	 * Phase 32 — manual suspension. The subscription (and the tenant) freezes but loses nothing:
	 * reactivate() puts it back on its remaining time. Conditional status flip — only a live
	 * subscription can be suspended, so two concurrent suspensions cannot double-fire the hooks.
	 */
	public function suspend( int $subscription_id, string $reason = '' ): bool {
		$changed = $this->db->query(
			'UPDATE ' . $this->db->table( 'subscriptions' ) . '
			 SET status = %s, updated_at = %s
			 WHERE id = %d AND status IN (%s,%s,%s)',
			self::STATUS_SUSPENDED,
			current_time( 'mysql', true ),
			$subscription_id,
			self::STATUS_ACTIVE,
			self::STATUS_TRIALING,
			self::STATUS_PAST_DUE
		);
		if ( ! $changed ) {
			return false;
		}

		$sub = $this->db->row( 'SELECT tenant_id FROM ' . $this->db->table( 'subscriptions' ) . ' WHERE id = %d', $subscription_id );
		if ( $sub ) {
			( new \IGBZ\Suite\Modules\MultiTenant\Repository\TenantRepository( $this->db ) )
				->set_status( (int) $sub['tenant_id'], \IGBZ\Suite\Modules\MultiTenant\Repository\Tenant::STATUS_SUSPENDED );
		}
		$this->logger->warning( 'plans', 'Subscription suspended', [ 'subscription_id' => $subscription_id, 'reason' => mb_substr( $reason, 0, 255 ) ] );
		do_action( 'igbz_subscription_suspended', $subscription_id );
		return true;
	}

	/**
	 * Phase 32 — bring a suspended subscription back. If its period already lapsed during the
	 * suspension it returns to `past_due` (the renewal sweep picks it up), otherwise `active`.
	 */
	public function reactivate( int $subscription_id ): bool {
		$sub = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'subscriptions' ) . ' WHERE id = %d', $subscription_id );
		if ( ! $sub || self::STATUS_SUSPENDED !== (string) $sub['status'] ) {
			return false;
		}

		$ends   = strtotime( (string) $sub['ends_at'] );
		$status = ( false !== $ends && $ends > time() ) ? self::STATUS_ACTIVE : self::STATUS_PAST_DUE;

		$this->db->update(
			'subscriptions',
			[ 'status' => $status, 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $subscription_id, 'status' => self::STATUS_SUSPENDED ]
		);

		( new \IGBZ\Suite\Modules\MultiTenant\Repository\TenantRepository( $this->db ) )
			->set_status( (int) $sub['tenant_id'], \IGBZ\Suite\Modules\MultiTenant\Repository\Tenant::STATUS_ACTIVE );

		$this->logger->info( 'plans', 'Subscription reactivated', [ 'subscription_id' => $subscription_id, 'status' => $status ] );
		do_action( 'igbz_subscription_reactivated', $subscription_id );
		return true;
	}

	/** Grace window for `past_due` subscriptions before expiry (days). */
	public function grace_days(): int {
		return max( 0, (int) igbz()->settings()->int( 'plans.grace_days', 7 ) );
	}

	/**
	 * Phase 32 — the grace sweep. A `past_due` subscription may keep serving until
	 * `ends_at + grace_days`; beyond that it expires and the tenant suspends. Bounded so the
	 * daily queue job can continue round by round like the renewal sweep.
	 */
	public function expire_past_grace( int $limit = 100 ): int {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $this->grace_days() * DAY_IN_SECONDS );

		$due = $this->db->results(
			'SELECT id, tenant_id FROM ' . $this->db->table( 'subscriptions' ) . '
			 WHERE status = %s AND ends_at IS NOT NULL AND ends_at <= %s
			 ORDER BY id ASC LIMIT %d',
			self::STATUS_PAST_DUE,
			$cutoff,
			$limit
		);

		$expired = 0;
		foreach ( $due as $row ) {
			$changed = $this->db->query(
				'UPDATE ' . $this->db->table( 'subscriptions' ) . '
				 SET status = %s, updated_at = %s WHERE id = %d AND status = %s',
				self::STATUS_EXPIRED,
				current_time( 'mysql', true ),
				(int) $row['id'],
				self::STATUS_PAST_DUE
			);
			if ( ! $changed ) {
				continue;
			}
			( new \IGBZ\Suite\Modules\MultiTenant\Repository\TenantRepository( $this->db ) )
				->set_status( (int) $row['tenant_id'], \IGBZ\Suite\Modules\MultiTenant\Repository\Tenant::STATUS_SUSPENDED );
			$this->logger->warning( 'plans', 'Subscription expired after grace', [ 'subscription_id' => (int) $row['id'] ] );
			do_action( 'igbz_subscription_expired', (int) $row['id'], (int) $row['tenant_id'] );
			++$expired;
		}
		return $expired;
	}

	public function cancel( int $subscription_id, bool $immediately = false ): bool {
		$now  = current_time( 'mysql', true );
		$data = [ 'auto_renew' => 0, 'cancelled_at' => $now, 'updated_at' => $now ];
		if ( $immediately ) {
			$data['status']  = self::STATUS_CANCELLED;
			$data['ends_at'] = $now;
		}
		$ok = $this->db->update( 'subscriptions', $data, [ 'id' => $subscription_id ] ) >= 0;
		do_action( 'igbz_subscription_cancelled', $subscription_id, $immediately );
		return $ok;
	}

	/**
	 * Charge one renewal from the tenant owner's wallet.
	 * Idempotent per (subscription, period) via the ledger reference code.
	 */
	public function renew( int $subscription_id ): bool {
		$sub = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'subscriptions' ) . ' WHERE id = %d AND tenant_id = %d', $subscription_id, igbz()->tenancy()->id() );
		if ( ! $sub ) {
			return false;
		}
		$plan = $this->plan( (int) $sub['plan_id'] );
		if ( ! $plan ) {
			return false;
		}

		$tenant = ( new \IGBZ\Suite\Modules\MultiTenant\Repository\TenantRepository( $this->db ) )->find( (int) $sub['tenant_id'] );
		if ( ! $tenant ) {
			return false;
		}

		$price = (float) $plan['price'];
		if ( $price <= 0 ) {
			$this->extend( $sub, $plan );
			return true;
		}

		$reference = sprintf( 'sub:%d:%s', $subscription_id, gmdate( 'Y-m', strtotime( (string) $sub['ends_at'] ) ) );
		$result    = $this->wallet->debit(
			$tenant->owner_user_id,
			$price,
			WalletService::REASON_SUBSCRIPTION,
			$reference,
			[ 'plan' => $plan['slug'] ],
			$tenant->id,
			0,
			sprintf( __( 'Subscription renewal: %s', 'igbz-suite' ), (string) $plan['name'] )
		);

		if ( ! $result->success ) {
			$failures = (int) $sub['renewal_failures'] + 1;
			$this->db->update(
				'subscriptions',
				[
					'status'           => self::STATUS_PAST_DUE,
					'renewal_failures' => $failures,
					'updated_at'       => current_time( 'mysql', true ),
				],
				[ 'id' => $subscription_id ]
			);
			$this->logger->warning( 'plans', 'Renewal failed', [ 'subscription_id' => $subscription_id, 'error' => $result->error_code ] );

			if ( $failures >= 3 ) {
				$this->db->update( 'subscriptions', [ 'status' => self::STATUS_EXPIRED ], [ 'id' => $subscription_id ] );
				( new \IGBZ\Suite\Modules\MultiTenant\Repository\TenantRepository( $this->db ) )
					->set_status( $tenant->id, \IGBZ\Suite\Modules\MultiTenant\Repository\Tenant::STATUS_SUSPENDED );
				do_action( 'igbz_subscription_expired', $subscription_id, $tenant->id );
			}
			return false;
		}

		$this->extend( $sub, $plan );
		do_action( 'igbz_subscription_renewed', $subscription_id, $tenant->id );
		return true;
	}

	/**
	 * @param array<string,mixed> $sub
	 * @param array<string,mixed> $plan
	 */
	private function extend( array $sub, array $plan ): void {
		$from = max( time(), strtotime( (string) $sub['ends_at'] ) );
		$this->db->update(
			'subscriptions',
			[
				'status'           => self::STATUS_ACTIVE,
				'ends_at'          => gmdate( 'Y-m-d H:i:s', $this->period_end( $from, (string) $plan['billing_period'] ) ),
				'price_paid'       => (float) $plan['price'],
				'last_invoice_at'  => current_time( 'mysql', true ),
				'renewal_failures' => 0,
				'updated_at'       => current_time( 'mysql', true ),
			],
			[ 'id' => (int) $sub['id'] ]
		);
	}

	private function period_end( int $from, string $period, int $trial_days = 0 ): int {
		if ( $trial_days > 0 ) {
			return $from + $trial_days * DAY_IN_SECONDS;
		}
		return match ( $period ) {
			'quarterly' => strtotime( '+3 months', $from ),
			'yearly'    => strtotime( '+1 year', $from ),
			'lifetime'  => strtotime( '+100 years', $from ),
			default     => strtotime( '+1 month', $from ),
		};
	}

	/** Cron entry point: renew everything that has lapsed. */
	public function process_due_renewals(): int {
		$due = $this->db->results(
			'SELECT id FROM ' . $this->db->table( 'subscriptions' ) . '
			 WHERE auto_renew = 1 AND status IN (%s,%s,%s) AND ends_at IS NOT NULL AND ends_at <= %s
			 LIMIT 100',
			self::STATUS_ACTIVE,
			self::STATUS_TRIALING,
			self::STATUS_PAST_DUE,
			current_time( 'mysql', true )
		);
		$count = 0;
		foreach ( $due as $row ) {
			if ( $this->renew( (int) $row['id'] ) ) {
				$count++;
			}
		}
		return $count;
	}

	// -------------------------------------------------------------- quotas

	/** @return array{allowed:bool,limit:int,used:int,feature:string} */
	public function check_quota( int $tenant_id, string $feature ): array {
		$sub  = $this->active_subscription( $tenant_id );
		$plan = $sub ? $this->plan( (int) $sub['plan_id'] ) : null;
		if ( ! $plan ) {
			return [ 'allowed' => false, 'limit' => 0, 'used' => 0, 'feature' => $feature ];
		}

		$limit = (int) ( $plan[ 'max_' . $feature ] ?? 0 );
		if ( 0 === $limit ) {
			return [ 'allowed' => true, 'limit' => 0, 'used' => 0, 'feature' => $feature ]; // unlimited
		}

		$used = match ( $feature ) {
			'products' => $this->count_meta_posts( $tenant_id, 'product' ),
			'orders'   => $this->count_orders_this_period( $tenant_id, $sub ),
			'staff'    => (int) $this->db->scalar( 'SELECT COUNT(*) FROM ' . $this->db->table( 'tenant_members' ) . ' WHERE tenant_id = %d', $tenant_id ),
			default    => 0,
		};

		return [ 'allowed' => $used < $limit, 'limit' => $limit, 'used' => $used, 'feature' => $feature ];
	}

	public function has_feature( int $tenant_id, string $feature ): bool {
		$sub  = $this->active_subscription( $tenant_id );
		$plan = $sub ? $this->plan( (int) $sub['plan_id'] ) : null;
		if ( ! $plan ) {
			return false;
		}
		$features = json_decode( (string) ( $plan['features'] ?? '[]' ), true );
		return is_array( $features ) && in_array( $feature, $features, true );
	}

	private function count_meta_posts( int $tenant_id, string $post_type ): int {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_igbz_tenant_id'
				 WHERE p.post_type = %s AND p.post_status != 'trash' AND pm.meta_value = %d",
				$post_type,
				$tenant_id
			)
		);
	}

	/** @param array<string,mixed> $sub */
	private function count_orders_this_period( int $tenant_id, array $sub ): int {
		$since = (string) ( $sub['last_invoice_at'] ?? $sub['starts_at'] );
		$orders = wc_get_orders(
			[
				'limit'      => -1,
				'return'     => 'ids',
				'date_created' => '>=' . strtotime( $since ),
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery
					[ 'key' => '_igbz_tenant_id', 'value' => $tenant_id ],
				],
			]
		);
		return is_array( $orders ) ? count( $orders ) : 0;
	}
}
