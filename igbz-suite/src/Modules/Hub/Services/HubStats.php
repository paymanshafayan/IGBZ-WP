<?php
namespace IGBZ\Suite\Modules\Hub\Services;

use IGBZ\Suite\Modules\MultiTenant\Plans\PlanService;
use IGBZ\Suite\Modules\MultiTenant\Repository\Tenant;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\WooCommerceCompat;

defined( 'ABSPATH' ) || exit;

/**
 * Platform-wide aggregates for the mother site.
 *
 * Port note: the nopCommerce MasterSiteAdminController used to invent numbers
 * (activeStoresCount * 850000 for MRR, hardcoded uptime, a fake average setup time). Everything
 * here is computed from real rows; anything that has no data source is simply not reported.
 */
final class HubStats {

	private const CACHE_KEY = 'igbz_hub_stats';

	public function __construct( private Db $db ) {}

	/**
	 * @return array{
	 *   tenants:int, active_tenants:int, suspended_tenants:int, domains:int, pending_domains:int,
	 *   subscriptions:int, mrr:float, currency:string, orders:int, revenue:float, refreshed_at:string
	 * }
	 */
	public function summary( bool $fresh = false ): array {
		if ( ! $fresh ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$summary = $this->compute();

		set_transient( self::CACHE_KEY, $summary, max( 300, igbz()->settings()->int( 'hub.sync_interval', 3600 ) ) );

		return $summary;
	}

	public function flush(): void {
		delete_transient( self::CACHE_KEY );
	}

	/** @return array<string,mixed> */
	private function compute(): array {
		$tenants = $this->db->table( 'tenants' );
		$domains = $this->db->table( 'tenant_domains' );

		$counts = $this->db->row(
			'SELECT COUNT(*) AS total,
					SUM(CASE WHEN status IN (%s, %s) THEN 1 ELSE 0 END) AS active,
					SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS suspended
			 FROM ' . $tenants,
			Tenant::STATUS_ACTIVE,
			Tenant::STATUS_TRIAL,
			Tenant::STATUS_SUSPENDED
		) ?? [];

		$domain_counts = $this->db->row(
			'SELECT COUNT(*) AS total, SUM(CASE WHEN verified_at IS NULL THEN 1 ELSE 0 END) AS pending FROM ' . $domains
		) ?? [];

		[ $subscriptions, $mrr ] = $this->recurring_revenue();
		[ $orders, $revenue ]    = $this->order_totals();

		return [
			'tenants'           => (int) ( $counts['total'] ?? 0 ),
			'active_tenants'    => (int) ( $counts['active'] ?? 0 ),
			'suspended_tenants' => (int) ( $counts['suspended'] ?? 0 ),
			'domains'           => (int) ( $domain_counts['total'] ?? 0 ),
			'pending_domains'   => (int) ( $domain_counts['pending'] ?? 0 ),
			'subscriptions'     => $subscriptions,
			'mrr'               => $mrr,
			'currency'          => igbz()->settings()->string( 'general.default_currency', 'IRT' ),
			'orders'            => $orders,
			'revenue'           => $revenue,
			'refreshed_at'      => current_time( 'mysql', true ),
		];
	}

	/**
	 * Real monthly recurring revenue: every active subscription joined to its plan and
	 * normalised to a monthly figure.
	 *
	 * @return array{0:int,1:float}
	 */
	private function recurring_revenue(): array {
		$rows = $this->db->results(
			'SELECT p.price AS price, p.billing_period AS billing_period, COUNT(*) AS cnt
			 FROM ' . $this->db->table( 'subscriptions' ) . ' s
			 INNER JOIN ' . $this->db->table( 'plans' ) . ' p ON p.id = s.plan_id
			 WHERE s.status IN (%s, %s)
			 GROUP BY p.price, p.billing_period',
			PlanService::STATUS_ACTIVE,
			PlanService::STATUS_TRIALING
		);

		$count = 0;
		$mrr   = 0.0;
		foreach ( $rows as $row ) {
			$cnt    = (int) $row['cnt'];
			$count += $cnt;
			$mrr   += $cnt * self::monthly_price( (float) $row['price'], (string) $row['billing_period'] );
		}

		return [ $count, round( $mrr, 2 ) ];
	}

	public static function monthly_price( float $price, string $period ): float {
		return match ( $period ) {
			'weekly'     => $price * 4.33,
			'quarterly'  => $price / 3,
			'six_months' => $price / 6,
			'yearly'     => $price / 12,
			'lifetime'   => 0.0,
			default      => $price,
		};
	}

	/**
	 * Total paid orders across every tenant. Uses the WooCommerce CRUD so HPOS stores work too.
	 *
	 * @return array{0:int,1:float}
	 */
	private function order_totals(): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return [ 0, 0.0 ];
		}

		$paged = wc_get_orders(
			[
				'limit'    => 1,
				'paginate' => true,
				'return'   => 'ids',
				'status'   => [ 'processing', 'completed' ],
			]
		);
		$count = is_object( $paged ) && isset( $paged->total ) ? (int) $paged->total : 0;

		global $wpdb;
		$revenue = 0.0;
		$orders_table = WooCommerceCompat::hpos_enabled() ? WooCommerceCompat::orders_table_name() : null;
		if ( null !== $orders_table ) {
			// HPOS stores live in the custom tables; the name comes from
			// OrdersTableDataStore so custom prefixes are honoured.
			$revenue = (float) $wpdb->get_var(
				"SELECT COALESCE(SUM(total_amount),0) FROM {$orders_table} WHERE status IN ('wc-processing','wc-completed')"
			); // phpcs:ignore WordPress.DB
		} else {
			$revenue = (float) $wpdb->get_var(
				"SELECT COALESCE(SUM(pm.meta_value),0)
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = '_order_total' AND p.post_type = 'shop_order'
				   AND p.post_status IN ('wc-processing','wc-completed')"
			); // phpcs:ignore WordPress.DB
		}

		return [ $count, round( $revenue, 2 ) ];
	}

	/** Orders belonging to one tenant. */
	public function tenant_order_count( int $tenant_id ): int {
		if ( ! function_exists( 'wc_get_orders' ) || $tenant_id <= 0 ) {
			return 0;
		}
		$paged = wc_get_orders(
			[
				'limit'      => 1,
				'paginate'   => true,
				'return'     => 'ids',
				'status'     => [ 'processing', 'completed' ],
				'meta_query' => [ [ 'key' => '_igbz_tenant_id', 'value' => $tenant_id ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);
		return is_object( $paged ) && isset( $paged->total ) ? (int) $paged->total : 0;
	}
}
