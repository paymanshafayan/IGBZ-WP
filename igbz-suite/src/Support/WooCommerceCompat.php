<?php
/**
 * WooCommerce compatibility surface — phase 21 (HPOS / custom order tables).
 *
 * Single place that knows how the plugin coexists with WooCommerce's order
 * storage backends. Two rules are enforced everywhere else in the suite:
 *   1. Orders are only touched through the WooCommerce CRUD layer
 *      (wc_get_order / wc_get_orders / $order->get_meta / update_meta_data),
 *      so the same code works on legacy post-based storage and on HPOS.
 *   2. Storage differences (admin edit links, direct table names) go through
 *      this class, never inlined in pages or services.
 *
 * @package IGBZ\Suite\Support
 */

declare( strict_types = 1 );

namespace IGBZ\Suite\Support;

defined( 'ABSPATH' ) || exit;

final class WooCommerceCompat {

	/** Hook the compatibility declaration before WooCommerce initialises. */
	public static function register(): void {
		add_action( 'before_woocommerce_init', [ __CLASS__, 'declare_compatibility' ] );
	}

	/**
	 * Tell WooCommerce the suite works with custom order tables (HPOS) and with
	 * the block cart/checkout. Without this declaration WooCommerce flags the
	 * plugin as incompatible on the HPOS settings screen even when the code is
	 * CRUD-clean.
	 */
	public static function declare_compatibility(): void {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			return;
		}

		$file = defined( 'IGBZ_FILE' ) ? \IGBZ_FILE : __FILE__;

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', $file, true );
		// Checkout flows hook `woocommerce_checkout_order_created`, which fires in
		// both classic and block checkout — safe to declare alongside the blocks.
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', $file, true );
	}

	/** True when WooCommerce stores orders in the custom tables (HPOS). */
	public static function hpos_enabled(): bool {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Admin edit URL for an order, valid on both storage backends. Under HPOS
	 * orders are not posts, so `post.php?post=` links 404 — WooCommerce's own
	 * helper routes to the right screen per active storage (this is the same
	 * helper core uses to rewrite order edit links).
	 */
	public static function order_edit_url( int $order_id ): string {
		if ( $order_id <= 0 ) {
			return '';
		}

		if ( method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'get_order_admin_edit_url' ) ) {
			$url = \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url( $order_id );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}

		return admin_url( 'post.php?post=' . $order_id . '&action=edit' );
	}

	/**
	 * Physical orders table name when HPOS is active, or null. Always derived
	 * from OrdersTableDataStore so custom table prefixes are honoured.
	 */
	public static function orders_table_name(): ?string {
		if ( ! class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore' ) ) {
			return null;
		}

		$name = \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_orders_table_name();
		return is_string( $name ) && '' !== $name ? $name : null;
	}
}
