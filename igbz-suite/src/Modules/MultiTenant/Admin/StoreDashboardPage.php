<?php
namespace IGBZ\Suite\Modules\MultiTenant\Admin;

use IGBZ\Suite\Support\Admin\Menu;
use IGBZ\Suite\Support\Admin\View;
use IGBZ\Suite\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/** Tenant-owner dashboard; platform administrators keep the normal WordPress dashboard. */
final class StoreDashboardPage {

	public const SLUG = 'igbz-store-dashboard';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 5 );
		add_action( 'admin_init', [ $this, 'redirect_owner_dashboard' ] );
	}

	public function add_page(): void {
		if ( current_user_can( Capabilities::MANAGE_SUITE ) ) {
			Menu::add( self::SLUG, __( 'Store dashboard', 'igbz-suite' ), [ $this, 'render' ], Capabilities::MANAGE_OWN_TENANT );
			return;
		}
		// Tenant owners do not receive the platform-level IGBZ capability, so their
		// dashboard must be a standalone top-level menu rather than a hidden submenu.
		add_menu_page(
			__( 'Store dashboard', 'igbz-suite' ),
			__( 'Store dashboard', 'igbz-suite' ),
			Capabilities::MANAGE_OWN_TENANT,
			self::SLUG,
			[ $this, 'render' ],
			'dashicons-store',
			3
		);
	}

	public function redirect_owner_dashboard(): void {
		if ( wp_doing_ajax() || wp_doing_cron() || current_user_can( 'manage_options' ) || current_user_can( Capabilities::MANAGE_TENANTS ) ) {
			return;
		}
		if ( ! current_user_can( Capabilities::MANAGE_OWN_TENANT ) || empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
		if ( str_ends_with( $path, '/wp-admin/' ) || str_ends_with( $path, '/wp-admin/index.php' ) ) {
			wp_safe_redirect( Menu::url( self::SLUG ) );
			exit;
		}
	}

	public function render(): void {
		$tenant_id = igbz()->tenancy()->id();
		$tenant    = $tenant_id > 0 ? igbz()->tenancy()->repository()->find( $tenant_id ) : null;
		if ( ! $tenant ) {
			wp_die( esc_html__( 'No store is assigned to this account.', 'igbz-suite' ), 403 );
		}
		$product_count = 0;
		$order_count   = 0;
		if ( function_exists( 'wc_get_products' ) ) {
			$result = wc_get_products(
				[
					'limit'      => 1,
					'paginate'   => true,
					'return'     => 'ids',
					'status'     => [ 'publish', 'draft', 'pending' ],
					'meta_query' => [ [ 'key' => '_igbz_tenant_id', 'value' => $tenant_id ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				]
			);
			$product_count = is_object( $result ) && isset( $result->total ) ? (int) $result->total : 0;
			$result = wc_get_orders(
				[
					'limit'      => 1,
					'paginate'   => true,
					'return'     => 'ids',
					'meta_query' => [ [ 'key' => '_igbz_tenant_id', 'value' => $tenant_id ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				]
			);
			$order_count = is_object( $result ) && isset( $result->total ) ? (int) $result->total : 0;
		}
		View::open( __( 'Store dashboard', 'igbz-suite' ), sprintf( __( 'Welcome to %s.', 'igbz-suite' ), $tenant->name ) );
		echo '<div class="igbz-cards">';
		printf( '<div class="igbz-card"><strong>%d</strong><span>%s</span></div>', $product_count, esc_html__( 'Products', 'igbz-suite' ) );
		printf( '<div class="igbz-card"><strong>%d</strong><span>%s</span></div>', $order_count, esc_html__( 'Orders', 'igbz-suite' ) );
		echo '</div>';
		echo '<p>' . esc_html__( 'All store actions are scoped to the current tenant on the server.', 'igbz-suite' ) . '</p>';
		View::close();
	}
}
