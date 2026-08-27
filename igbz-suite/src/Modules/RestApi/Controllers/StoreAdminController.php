<?php
namespace IGBZ\Suite\Modules\RestApi\Controllers;

use IGBZ\Suite\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * The store-owner side of the mobile app: manage your own catalogue from the phone.
 *
 * Port of nop's `AdminCategoriesController`, `AdminCustomersController` and
 * `AdminProductDownloadsController`. Those three inherited a base class that did not exist in the
 * source tree, so none of them ever ran; they are rebuilt here on the real base controller with a
 * permission callback on every route and hard tenant scoping, which the originals lacked (any
 * authenticated vendor could read every other vendor's customers).
 */
final class StoreAdminController extends BaseController {

	public function register_routes(): void {
		$ns    = self::NAMESPACE;
		$owner = [ $this, 'can_manage_tenant' ];

		register_rest_route( $ns, '/admin/products', $this->route( 'GET', [ $this, 'products' ], $owner ) );
		register_rest_route( $ns, '/admin/products', $this->route( 'POST', [ $this, 'save_product' ], $owner ) );
		register_rest_route( $ns, '/admin/products/(?P<id>\d+)', $this->route( 'POST', [ $this, 'save_product' ], $owner ) );
		register_rest_route( $ns, '/admin/products/(?P<id>\d+)', $this->route( 'DELETE', [ $this, 'delete_product' ], $owner ) );
		register_rest_route( $ns, '/admin/products/(?P<id>\d+)/image', $this->route( 'POST', [ $this, 'upload_product_image' ], $owner ) );
		register_rest_route( $ns, '/admin/categories/tree', $this->route( 'GET', [ $this, 'category_tree' ], $owner ) );
		register_rest_route( $ns, '/admin/categories', $this->route( 'POST', [ $this, 'save_category' ], $owner ) );
		register_rest_route(
			$ns,
			'/admin/categories/(?P<id>\d+)',
			$this->route( 'DELETE', [ $this, 'delete_category' ], $owner )
		);

		register_rest_route( $ns, '/admin/customers', $this->route( 'GET', [ $this, 'search_customers' ], $owner ) );
		register_rest_route(
			$ns,
			'/admin/customers/(?P<id>\d+)',
			$this->route( 'GET', [ $this, 'customer' ], $owner )
		);

		register_rest_route( $ns, '/admin/orders', $this->route( 'GET', [ $this, 'orders' ], $owner ) );
		register_rest_route(
			$ns,
			'/admin/orders/(?P<id>\d+)/status',
			$this->route( 'POST', [ $this, 'set_order_status' ], $owner )
		);

		register_rest_route(
			$ns,
			'/admin/products/(?P<id>\d+)/downloads',
			$this->route( 'GET', [ $this, 'downloads' ], $owner )
		);
		register_rest_route(
			$ns,
			'/admin/products/(?P<id>\d+)/downloads/upload',
			$this->route( 'POST', [ $this, 'upload_download' ], $owner )
		);
		register_rest_route(
			$ns,
			'/admin/products/(?P<id>\d+)/downloads/(?P<key>[a-f0-9]{32})',
			$this->route( 'DELETE', [ $this, 'delete_download' ], $owner )
		);

		register_rest_route( $ns, '/admin/summary', $this->route( 'GET', [ $this, 'summary' ], $owner ) );
	}

	// ------------------------------------------------------------- products

	public function products( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! function_exists( 'wc_get_products' ) ) { return $this->fail( 'igbz_no_woocommerce', __( 'WooCommerce is not active.', 'igbz-suite' ), 503 ); }
		[ $page, $per_page, $offset ] = $this->page_args( $request );
		$args = [ 'limit' => $per_page, 'offset' => $offset, 'paginate' => true, 'status' => [ 'publish', 'draft', 'pending' ], 'orderby' => 'date', 'order' => 'DESC' ];
		$tenant_id = $this->scoped_tenant_id( $request );
		if ( $tenant_id > 0 && ! Capabilities::current_user_can( Capabilities::MANAGE_TENANTS ) ) {
			$args['meta_key'] = '_igbz_tenant_id'; $args['meta_value'] = (string) $tenant_id;
		}
		$result = wc_get_products( $args );
		$products = is_object( $result ) ? (array) ( $result->products ?? [] ) : [];
		$total = is_object( $result ) && isset( $result->total ) ? (int) $result->total : count( $products );
		$items = array_map( static function ( \WC_Product $product ): array {
			return [ 'id' => $product->get_id(), 'name' => $product->get_name(), 'sku' => $product->get_sku(), 'status' => $product->get_status(), 'price' => (float) $product->get_price(), 'regular_price' => (float) $product->get_regular_price(), 'stock_status' => $product->get_stock_status(), 'image_url' => $product->get_image_id() ? wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' ) : '' ];
		}, $products );
		return $this->paged( $items, $total, $page, $per_page );
	}

	public function save_product( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! class_exists( '\\WC_Product_Simple' ) ) { return $this->fail( 'igbz_no_woocommerce', __( 'WooCommerce is not active.', 'igbz-suite' ), 503 ); }
		$id = (int) $request->get_param( 'id' );
		$product = $id ? $this->guard_product( $id, $request ) : new \WC_Product_Simple();
		if ( $product instanceof \WP_REST_Response ) { return $product; }
		$tenant_id = $this->scoped_tenant_id( $request );
		if ( $id <= 0 && $tenant_id <= 0 ) { return $this->fail( 'igbz_no_tenant', __( 'A tenant context is required to create a product.', 'igbz-suite' ), 400 ); }
		if ( null !== $request->get_param( 'name' ) ) { $product->set_name( sanitize_text_field( (string) $request->get_param( 'name' ) ) ); }
		if ( null !== $request->get_param( 'description' ) ) { $product->set_description( wp_kses_post( (string) $request->get_param( 'description' ) ) ); }
		if ( null !== $request->get_param( 'regular_price' ) ) { $product->set_regular_price( wc_format_decimal( $request->get_param( 'regular_price' ) ) ); }
		if ( null !== $request->get_param( 'sale_price' ) ) { $product->set_sale_price( wc_format_decimal( $request->get_param( 'sale_price' ) ) ); }
		if ( null !== $request->get_param( 'status' ) ) { $product->set_status( in_array( $request->get_param( 'status' ), [ 'publish', 'draft', 'pending' ], true ) ? (string) $request->get_param( 'status' ) : 'draft' ); }
		if ( null !== $request->get_param( 'stock_status' ) ) { $product->set_stock_status( in_array( $request->get_param( 'stock_status' ), [ 'instock', 'outofstock', 'onbackorder' ], true ) ? (string) $request->get_param( 'stock_status' ) : 'instock' ); }
		$product_id = $product->save();
		if ( $id <= 0 ) { update_post_meta( $product_id, '_igbz_tenant_id', $tenant_id ); }
		return $this->ok( [ 'id' => $product_id, 'name' => $product->get_name() ], $id ? 200 : 201 );
	}

	public function delete_product( \WP_REST_Request $request ): \WP_REST_Response {
		$product = $this->guard_product( (int) $request->get_param( 'id' ), $request );
		if ( $product instanceof \WP_REST_Response ) { return $product; }
		$product->delete( true );
		return $this->ok( [ 'deleted' => true ] );
	}

	public function upload_product_image( \WP_REST_Request $request ): \WP_REST_Response {
		$product = $this->guard_product( (int) $request->get_param( 'id' ), $request );
		if ( $product instanceof \WP_REST_Response ) { return $product; }
		if ( empty( $request->get_file_params()['image'] ) ) { return $this->fail( 'igbz_no_file', __( 'No image was uploaded.', 'igbz-suite' ) ); }
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attachment_id = media_handle_upload( 'image', $product->get_id() );
		if ( is_wp_error( $attachment_id ) ) { return $this->fail( 'igbz_upload_failed', $attachment_id->get_error_message() ); }
		$product->set_image_id( (int) $attachment_id );
		$product->save();
		return $this->ok( [ 'id' => (int) $attachment_id, 'url' => (string) wp_get_attachment_image_url( $attachment_id, 'full' ) ] );
	}

	// ---------------------------------------------------------- categories

	public function category_tree( \WP_REST_Request $request ): \WP_REST_Response {
		$terms = get_terms(
			[
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 500,
			]
		);

		if ( is_wp_error( $terms ) ) {
			return $this->fail( 'igbz_taxonomy_error', $terms->get_error_message(), 500 );
		}

		$tenant_id = $this->scoped_tenant_id( $request );
		$nodes     = [];

		foreach ( $terms as $term ) {
			// A tenant only sees the categories it owns plus the shared ones the platform defines.
			$owner = (int) get_term_meta( $term->term_id, '_igbz_tenant_id', true );
			if ( $tenant_id > 0 && $owner > 0 && $owner !== $tenant_id ) {
				continue;
			}

			$nodes[] = [
				'id'        => (int) $term->term_id,
				'parent_id' => (int) $term->parent,
				'name'      => $term->name,
				'slug'      => $term->slug,
				'count'     => (int) $term->count,
				'shared'    => 0 === $owner,
				'image'     => $this->term_image( (int) $term->term_id ),
			];
		}

		return $this->ok(
			[
				'items' => $nodes,
				'tree'  => $this->build_tree( $nodes, 0 ),
			]
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $nodes
	 * @return array<int,array<string,mixed>>
	 */
	private function build_tree( array $nodes, int $parent_id ): array {
		$branch = [];

		foreach ( $nodes as $node ) {
			if ( (int) $node['parent_id'] !== $parent_id ) {
				continue;
			}
			$node['children'] = $this->build_tree( $nodes, (int) $node['id'] );
			$branch[]         = $node;
		}

		return $branch;
	}

	private function term_image( int $term_id ): string {
		$attachment_id = (int) get_term_meta( $term_id, 'thumbnail_id', true );

		return $attachment_id > 0 ? (string) wp_get_attachment_image_url( $attachment_id, 'medium' ) : '';
	}

	public function save_category( \WP_REST_Request $request ): \WP_REST_Response {
		$tenant_id = $this->scoped_tenant_id( $request );
		$id        = (int) $request->get_param( 'id' );
		$name      = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$parent    = (int) $request->get_param( 'parent_id' );

		if ( '' === $name ) {
			return $this->fail( 'igbz_missing_name', __( 'A category name is required.', 'igbz-suite' ) );
		}

		if ( $id > 0 ) {
			$guard = $this->guard_term( $id, $tenant_id );
			if ( $guard instanceof \WP_REST_Response ) {
				return $guard;
			}

			$result = wp_update_term( $id, 'product_cat', [ 'name' => $name, 'parent' => $parent ] );
		} else {
			$result = wp_insert_term( $name, 'product_cat', [ 'parent' => $parent ] );
		}

		if ( is_wp_error( $result ) ) {
			return $this->fail( 'igbz_term_error', $result->get_error_message() );
		}

		$term_id = (int) $result['term_id'];

		if ( $tenant_id > 0 && 0 === (int) get_term_meta( $term_id, '_igbz_tenant_id', true ) ) {
			update_term_meta( $term_id, '_igbz_tenant_id', $tenant_id );
		}

		$description = $request->get_param( 'description' );
		if ( null !== $description ) {
			wp_update_term( $term_id, 'product_cat', [ 'description' => wp_kses_post( (string) $description ) ] );
		}

		$image_id = (int) $request->get_param( 'image_id' );
		if ( $image_id > 0 ) {
			update_term_meta( $term_id, 'thumbnail_id', $image_id );
		}

		return $this->ok( [ 'ok' => true, 'id' => $term_id ] );
	}

	public function delete_category( \WP_REST_Request $request ): \WP_REST_Response {
		$id    = (int) $request->get_param( 'id' );
		$guard = $this->guard_term( $id, $this->scoped_tenant_id( $request ) );
		if ( $guard instanceof \WP_REST_Response ) {
			return $guard;
		}

		$deleted = wp_delete_term( $id, 'product_cat' );

		return is_wp_error( $deleted )
			? $this->fail( 'igbz_term_error', $deleted->get_error_message() )
			: $this->ok( [ 'ok' => true ] );
	}

	/** Refuse to touch a shared category or another tenant's category. */
	private function guard_term( int $term_id, int $tenant_id ): ?\WP_REST_Response {
		$term = get_term( $term_id, 'product_cat' );
		if ( ! $term || is_wp_error( $term ) ) {
			return $this->fail( 'igbz_not_found', __( 'Category not found.', 'igbz-suite' ), 404 );
		}

		if ( Capabilities::current_user_can( Capabilities::MANAGE_TENANTS ) ) {
			return null;
		}

		$owner = (int) get_term_meta( $term_id, '_igbz_tenant_id', true );
		if ( $owner !== $tenant_id || 0 === $tenant_id ) {
			return $this->fail( 'igbz_forbidden', __( 'This category does not belong to your store.', 'igbz-suite' ), 403 );
		}

		return null;
	}

	// ----------------------------------------------------------- customers

	public function search_customers( \WP_REST_Request $request ): \WP_REST_Response {
		[ $page, $per_page, $offset ] = $this->page_args( $request );

		$term      = sanitize_text_field( (string) $request->get_param( 'q' ) );
		$tenant_id = $this->scoped_tenant_id( $request );

		// Only customers who have actually bought from this store, so a vendor cannot enumerate
		// the whole user table by paging through an empty query.
		$ids = $this->customer_ids_for_tenant( $tenant_id );
		if ( $tenant_id > 0 && ! $ids ) {
			return $this->paged( [], 0, $page, $per_page );
		}

		$args = [
			'number'  => $per_page,
			'offset'  => $offset,
			'orderby' => 'registered',
			'order'   => 'DESC',
			'fields'  => 'ID',
		];

		if ( $tenant_id > 0 ) {
			$args['include'] = $ids;
		}

		if ( '' !== $term ) {
			$args['search']         = '*' . $term . '*';
			$args['search_columns'] = [ 'user_login', 'user_email', 'display_name', 'user_nicename' ];
		}

		$query = new \WP_User_Query( $args );
		$items = [];

		foreach ( (array) $query->get_results() as $user_id ) {
			$items[] = $this->customer_payload( (int) $user_id, $tenant_id, false );
		}

		return $this->paged( $items, (int) $query->get_total(), $page, $per_page );
	}

	public function customer( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id   = (int) $request->get_param( 'id' );
		$tenant_id = $this->scoped_tenant_id( $request );

		if ( ! get_userdata( $user_id ) ) {
			return $this->fail( 'igbz_not_found', __( 'Customer not found.', 'igbz-suite' ), 404 );
		}

		if ( $tenant_id > 0
			&& ! Capabilities::current_user_can( Capabilities::MANAGE_TENANTS )
			&& ! in_array( $user_id, $this->customer_ids_for_tenant( $tenant_id ), true ) ) {
			return $this->fail( 'igbz_forbidden', __( 'This customer has never ordered from your store.', 'igbz-suite' ), 403 );
		}

		return $this->ok( $this->customer_payload( $user_id, $tenant_id, true ) );
	}

	/** @return int[] */
	private function customer_ids_for_tenant( int $tenant_id ): array {
		if ( $tenant_id <= 0 ) {
			return [];
		}

		$cache_key = 'igbz_tenant_customers_' . $tenant_id;
		$cached    = wp_cache_get( $cache_key, 'igbz' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		// Orders carry the tenant on the order itself; HPOS and legacy posts both expose meta.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT CAST(cust.meta_value AS UNSIGNED)
				 FROM {$wpdb->postmeta} AS tenant
				 INNER JOIN {$wpdb->postmeta} AS cust
				         ON cust.post_id = tenant.post_id AND cust.meta_key = '_customer_user'
				 WHERE tenant.meta_key = '_igbz_tenant_id' AND tenant.meta_value = %s",
				(string) $tenant_id
			)
		);

		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );

		if ( ! $ids && function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders(
				[
					'limit'      => 500,
					'return'     => 'objects',
					'meta_key'   => '_igbz_tenant_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => (string) $tenant_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				]
			);
			foreach ( $orders as $order ) {
				$customer = (int) $order->get_customer_id();
				if ( $customer > 0 ) {
					$ids[] = $customer;
				}
			}
			$ids = array_values( array_unique( $ids ) );
		}

		wp_cache_set( $cache_key, $ids, 'igbz', 300 );

		return $ids;
	}

	/** @return array<string,mixed> */
	private function customer_payload( int $user_id, int $tenant_id, bool $detailed ): array {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return [];
		}

		$payload = [
			'id'         => $user_id,
			'name'       => $user->display_name,
			'email'      => $user->user_email,
			'phone'      => (string) get_user_meta( $user_id, 'igbz_phone', true ),
			'registered' => $user->user_registered,
			'avatar'     => get_avatar_url( $user_id, [ 'size' => 96 ] ),
		];

		if ( ! $detailed ) {
			return $payload;
		}

		$payload['billing'] = [
			'first_name' => (string) get_user_meta( $user_id, 'billing_first_name', true ),
			'last_name'  => (string) get_user_meta( $user_id, 'billing_last_name', true ),
			'city'       => (string) get_user_meta( $user_id, 'billing_city', true ),
			'state'      => (string) get_user_meta( $user_id, 'billing_state', true ),
			'address_1'  => (string) get_user_meta( $user_id, 'billing_address_1', true ),
			'postcode'   => (string) get_user_meta( $user_id, 'billing_postcode', true ),
		];

		$orders = $this->orders_for( $tenant_id, [ 'customer_id' => $user_id, 'limit' => 20 ] );
		$spent  = 0.0;
		$lines  = [];

		foreach ( $orders as $order ) {
			$spent  += (float) $order->get_total();
			$lines[] = $this->order_summary( $order );
		}

		$payload['orders']      = $lines;
		$payload['order_count'] = count( $lines );
		$payload['total_spent'] = round( $spent, 2 );

		if ( igbz()->has( 'wallet' ) ) {
			$payload['wallet_balance'] = igbz()->get( 'wallet' )->balance( $user_id, $tenant_id );
		}

		return $payload;
	}

	// -------------------------------------------------------------- orders

	public function orders( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return $this->fail( 'igbz_no_woocommerce', __( 'WooCommerce is not active.', 'igbz-suite' ), 503 );
		}

		[ $page, $per_page, $offset ] = $this->page_args( $request );

		$args = [
			'limit'  => $per_page,
			'offset' => $offset,
			'paged'  => $page,
		];

		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( '' !== $status ) {
			$args['status'] = $status;
		}

		$orders = $this->orders_for( $this->scoped_tenant_id( $request ), $args );
		$items  = [];

		foreach ( $orders as $order ) {
			$items[] = $this->order_summary( $order );
		}

		return $this->paged( $items, count( $items ), $page, $per_page );
	}

	public function set_order_status( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return $this->fail( 'igbz_no_woocommerce', __( 'WooCommerce is not active.', 'igbz-suite' ), 503 );
		}

		$order = wc_get_order( (int) $request->get_param( 'id' ) );
		if ( ! $order ) {
			return $this->fail( 'igbz_not_found', __( 'Order not found.', 'igbz-suite' ), 404 );
		}

		$tenant_id = $this->scoped_tenant_id( $request );
		if ( $tenant_id > 0
			&& ! Capabilities::current_user_can( Capabilities::MANAGE_TENANTS )
			&& (int) $order->get_meta( '_igbz_tenant_id' ) !== $tenant_id ) {
			return $this->fail( 'igbz_forbidden', __( 'This order belongs to another store.', 'igbz-suite' ), 403 );
		}

		$status  = sanitize_key( (string) $request->get_param( 'status' ) );
		$allowed = array_map(
			static fn ( string $key ): string => str_replace( 'wc-', '', $key ),
			array_keys( wc_get_order_statuses() )
		);

		if ( ! in_array( $status, $allowed, true ) ) {
			return $this->fail( 'igbz_bad_status', __( 'Unknown order status.', 'igbz-suite' ) );
		}

		$order->update_status(
			$status,
			sprintf(
				/* translators: %s: user display name */
				__( 'Changed from the mobile app by %s.', 'igbz-suite' ),
				wp_get_current_user()->display_name
			)
		);

		return $this->ok( [ 'ok' => true, 'status' => $order->get_status() ] );
	}

	/**
	 * @param array<string,mixed> $args
	 * @return \WC_Order[]
	 */
	private function orders_for( int $tenant_id, array $args = [] ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return [];
		}

		$args = array_merge( [ 'limit' => 20, 'orderby' => 'date', 'order' => 'DESC' ], $args );

		if ( $tenant_id > 0 && ! Capabilities::current_user_can( Capabilities::MANAGE_TENANTS ) ) {
			$args['meta_key']   = '_igbz_tenant_id'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$args['meta_value'] = (string) $tenant_id; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		}

		$orders = wc_get_orders( $args );

		return is_array( $orders ) ? $orders : [];
	}

	/** @return array<string,mixed> */
	private function order_summary( \WC_Order $order ): array {
		return [
			'id'           => $order->get_id(),
			'number'       => $order->get_order_number(),
			'status'       => $order->get_status(),
			'status_label' => wc_get_order_status_name( $order->get_status() ),
			'total'        => (float) $order->get_total(),
			'currency'     => $order->get_currency(),
			'item_count'   => $order->get_item_count(),
			'customer'     => $order->get_formatted_billing_full_name(),
			'created_at'   => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : '',
		];
	}

	// ----------------------------------------------------------- downloads

	public function downloads( \WP_REST_Request $request ): \WP_REST_Response {
		$product = $this->guard_product( (int) $request->get_param( 'id' ), $request );
		if ( $product instanceof \WP_REST_Response ) {
			return $product;
		}

		return $this->ok( [ 'items' => $this->download_payload( $product ) ] );
	}

	/**
	 * nop's version wrote the uploaded file straight into the web root and returned its public URL,
	 * so any purchased file could be fetched without buying it. Here the file goes through
	 * `wp_handle_upload()` into a protected sub-directory and WooCommerce serves it through its own
	 * signed download handler.
	 */
	public function upload_download( \WP_REST_Request $request ): \WP_REST_Response {
		$product = $this->guard_product( (int) $request->get_param( 'id' ), $request );
		if ( $product instanceof \WP_REST_Response ) {
			return $product;
		}

		$files = $request->get_file_params();
		$file  = $files['file'] ?? null;

		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) ) {
			return $this->fail( 'igbz_no_file', __( 'No file was uploaded.', 'igbz-suite' ) );
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		add_filter( 'upload_dir', [ $this, 'downloads_dir' ] );
		$moved = wp_handle_upload( $file, [ 'test_form' => false ] );
		remove_filter( 'upload_dir', [ $this, 'downloads_dir' ] );

		if ( ! is_array( $moved ) || isset( $moved['error'] ) ) {
			return $this->fail( 'igbz_upload_failed', (string) ( $moved['error'] ?? __( 'The upload failed.', 'igbz-suite' ) ) );
		}

		$download = new \WC_Product_Download();
		$download->set_id( wp_generate_uuid4() );
		$download->set_name( sanitize_file_name( (string) ( $file['name'] ?? basename( $moved['file'] ) ) ) );
		$download->set_file( $moved['url'] );

		$existing                       = $product->get_downloads();
		$existing[ $download->get_id() ] = $download;

		$product->set_downloads( $existing );
		$product->set_downloadable( true );
		if ( '' === (string) $product->get_download_limit() || -1 === (int) $product->get_download_limit() ) {
			$product->set_download_limit( 5 );
		}
		$product->save();

		igbz()->logger()->info(
			'api',
			'Product download uploaded from the app',
			[ 'product_id' => $product->get_id(), 'file' => $download->get_name() ]
		);

		return $this->ok( [ 'ok' => true, 'items' => $this->download_payload( $product ) ] );
	}

	public function delete_download( \WP_REST_Request $request ): \WP_REST_Response {
		$product = $this->guard_product( (int) $request->get_param( 'id' ), $request );
		if ( $product instanceof \WP_REST_Response ) {
			return $product;
		}

		$key       = (string) $request->get_param( 'key' );
		$downloads = $product->get_downloads();

		if ( ! isset( $downloads[ $key ] ) ) {
			return $this->fail( 'igbz_not_found', __( 'That file is not attached to this product.', 'igbz-suite' ), 404 );
		}

		unset( $downloads[ $key ] );
		$product->set_downloads( $downloads );
		$product->save();

		return $this->ok( [ 'ok' => true, 'items' => $this->download_payload( $product ) ] );
	}

	/**
	 * Keep uploaded product files out of the browsable uploads tree.
	 *
	 * @param array<string,string> $dirs
	 * @return array<string,string>
	 */
	public function downloads_dir( array $dirs ): array {
		$suffix = '/igbz-downloads';

		$dirs['subdir'] = $suffix;
		$dirs['path']   = $dirs['basedir'] . $suffix;
		$dirs['url']    = $dirs['baseurl'] . $suffix;

		if ( ! file_exists( $dirs['path'] ) ) {
			wp_mkdir_p( $dirs['path'] );
			// Belt and braces for Apache; WooCommerce also drops its own protection files here.
			file_put_contents( $dirs['path'] . '/.htaccess', "deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dirs['path'] . '/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		return $dirs;
	}

	/** @return array<int,array<string,mixed>> */
	private function download_payload( \WC_Product $product ): array {
		$items = [];

		foreach ( $product->get_downloads() as $key => $download ) {
			$items[] = [
				'key'     => (string) $key,
				'name'    => $download->get_name(),
				'file'    => $download->get_file(),
				'enabled' => $download->get_enabled(),
			];
		}

		return $items;
	}

	/** @return \WC_Product|\WP_REST_Response */
	private function guard_product( int $product_id, \WP_REST_Request $request ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return $this->fail( 'igbz_no_woocommerce', __( 'WooCommerce is not active.', 'igbz-suite' ), 503 );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return $this->fail( 'igbz_not_found', __( 'Product not found.', 'igbz-suite' ), 404 );
		}

		if ( Capabilities::current_user_can( Capabilities::MANAGE_TENANTS ) ) {
			return $product;
		}

		$tenant_id = $this->scoped_tenant_id( $request );
		$owner     = (int) $product->get_meta( '_igbz_tenant_id' );

		if ( $tenant_id <= 0 || $owner !== $tenant_id ) {
			return $this->fail( 'igbz_forbidden', __( 'This product belongs to another store.', 'igbz-suite' ), 403 );
		}

		return $product;
	}

	// ------------------------------------------------------------- summary

	/** A single call the app's dashboard screen can poll. */
	public function summary( \WP_REST_Request $request ): \WP_REST_Response {
		$tenant_id = $this->scoped_tenant_id( $request );
		$orders    = $this->orders_for( $tenant_id, [ 'limit' => 100 ] );

		$today    = 0.0;
		$revenue  = 0.0;
		$pending  = 0;
		$boundary = strtotime( 'today midnight' );

		foreach ( $orders as $order ) {
			$total    = (float) $order->get_total();
			$revenue += $total;

			$created = $order->get_date_created();
			if ( $created && $created->getTimestamp() >= $boundary ) {
				$today += $total;
			}

			if ( in_array( $order->get_status(), [ 'pending', 'processing', 'on-hold' ], true ) ) {
				++$pending;
			}
		}

		return $this->ok(
			[
				'tenant_id'      => $tenant_id,
				'currency'       => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
				'orders_recent'  => count( $orders ),
				'orders_pending' => $pending,
				'revenue_recent' => round( $revenue, 2 ),
				'revenue_today'  => round( $today, 2 ),
				'customers'      => count( $this->customer_ids_for_tenant( $tenant_id ) ),
				'devices'        => igbz()->has( 'api.devices' )
					? igbz()->get( 'api.devices' )->count( [ 'tenant_id' => $tenant_id ] )
					: 0,
				'latest'         => array_map( [ $this, 'order_summary' ], array_slice( $orders, 0, 5 ) ),
			]
		);
	}
}
