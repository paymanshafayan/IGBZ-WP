<?php
namespace IGBZ\Suite\Modules\Instagram\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 52 — the one place a product registration writes to WooCommerce.
 *
 * Isolating the single `wc_*` seam behind this interface keeps the 13-step state
 * machine honest and testable without WooCommerce loaded, and gives a future
 * provider (a headless catalogue, a staging mirror) a clean drop-in point. The
 * default factory only ever creates DRAFT products: nothing a registration
 * produces can reach a shopper until a human approves it in the state machine.
 */
interface WooProductFactory {

	/**
	 * Create a draft product from approved listing copy.
	 *
	 * @param array<string,mixed> $copy Keys: title, description, price, sku (optional).
	 * @return int The new product id, or 0 when WooCommerce cannot create it.
	 */
	public function create_draft( array $copy ): int;

	/**
	 * Delete a draft product during compensation. Only drafts are touched — a product
	 * that already went live is left for the operator, never force-removed.
	 */
	public function delete_draft( int $product_id ): bool;

	/** True when the factory can currently create products (WooCommerce active). */
	public function is_available(): bool;
}

/**
 * The real WooCommerce implementation. Every call guards on the WC functions so a
 * site without WooCommerce fails the commerce step cleanly instead of fatalling.
 */
final class WooCommerceDraftFactory implements WooProductFactory {

	public function create_draft( array $copy ): int {
		if ( ! $this->is_available() ) {
			return 0;
		}

		$title = trim( (string) ( $copy['title'] ?? '' ) );
		if ( '' === $title ) {
			return 0;
		}

		// We deliberately use the plain WP+WC path rather than the REST API: this plugin
		// already runs inside the site, so no consumer keys and no network hop are needed.
		$post_id = wp_insert_post(
			[
				'post_type'   => 'product',
				'post_status' => 'draft',
				'post_title'  => $title,
			]
		);
		if ( empty( $post_id ) || is_wp_error( $post_id ) ) {
			return 0;
		}

		wc_setup_product_post( $post_id );

		$product = wc_get_product( $post_id );
		if ( ! $product instanceof \WC_Product ) {
			return 0;
		}
		$product->set_name( $title );
		$product->set_description( (string) ( $copy['description'] ?? '' ) );
		if ( isset( $copy['price'] ) && '' !== (string) $copy['price'] ) {
			$product->set_regular_price( (string) $copy['price'] );
			$product->set_price( (string) $copy['price'] );
		}
		$product->set_status( 'draft' );
		if ( ! empty( $copy['sku'] ) ) {
			$product->set_sku( (string) $copy['sku'] );
		}
		$product->save();

		return (int) $post_id;
	}

	public function delete_draft( int $product_id ): bool {
		if ( ! $this->is_available() || $product_id <= 0 ) {
			return false;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product || 'draft' !== $product->get_status() ) {
			// Never touch anything that is live or no longer exists.
			return false;
		}
		return (bool) wp_delete_post( $product_id, true );
	}

	public function is_available(): bool {
		return class_exists( '\WC' ) && function_exists( 'wc_get_product' ) && function_exists( 'wc_setup_product_post' );
	}
}
