<?php
namespace IGBZ\Suite\Modules\RestApi\Controllers;

use IGBZ\Suite\Modules\RestApi\Pagination\CursorCodec;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only catalogue for the mobile client.
 *
 *   GET /igbz/v1/catalog/categories
 *   GET /igbz/v1/catalog/products      ?search=&category=&min_price=&max_price=&on_sale=&featured=&orderby=&page=&per_page=
 *   GET /igbz/v1/catalog/products/{id}
 *   GET /igbz/v1/catalog/search-suggest?q=
 *
 * Everything is scoped to the current tenant through the `_igbz_tenant_id` product meta, so one
 * store's app can never list another store's products.
 */
final class CatalogController extends BaseController {

	public function register_routes(): void {
		$ns = self::NAMESPACE;

		register_rest_route( $ns, '/catalog/categories', $this->route( 'GET', [ $this, 'categories' ] ) );
		register_rest_route( $ns, '/catalog/products', $this->route( 'GET', [ $this, 'products' ], null, $this->cursor_args() ) );
		register_rest_route( $ns, '/catalog/products/(?P<id>\d+)', $this->route( 'GET', [ $this, 'product' ] ) );
		register_rest_route( $ns, '/catalog/search-suggest', $this->route( 'GET', [ $this, 'suggest' ] ) );
	}

	public function categories(): \WP_REST_Response {
		$terms = get_terms(
			[
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
			]
		);

		if ( is_wp_error( $terms ) ) {
			return $this->ok( [ 'categories' => [] ] );
		}

		$nodes = [];
		foreach ( $terms as $term ) {
			$thumbnail_id = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );

			$nodes[] = [
				'id'        => $term->term_id,
				'parent_id' => $term->parent,
				'name'      => $term->name,
				'slug'      => $term->slug,
				'count'     => $term->count,
				'image_url' => $thumbnail_id > 0 ? (string) wp_get_attachment_image_url( $thumbnail_id, 'woocommerce_thumbnail' ) : '',
			];
		}

		return $this->ok(
			[
				'categories' => $nodes,
				'tree'       => $this->tree( $nodes, 0 ),
			]
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $nodes
	 * @return array<int,array<string,mixed>>
	 */
	private function tree( array $nodes, int $parent_id ): array {
		$branch = [];
		foreach ( $nodes as $node ) {
			if ( (int) $node['parent_id'] !== $parent_id ) {
				continue;
			}
			$node['children'] = $this->tree( $nodes, (int) $node['id'] );
			$branch[]         = $node;
		}
		return $branch;
	}

	public function products( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return $this->fail( 'woocommerce_missing', __( 'WooCommerce is not active.', 'igbz-suite' ), 503 );
		}

		$position = $this->cursor_position( $request, CursorCodec::KIND_PRODUCTS );
		if ( $position instanceof \WP_REST_Response ) {
			return $position;
		}

		if ( null !== $position ) {
			return $this->products_by_cursor( $request, $position );
		}

		[ $page, $per_page, ] = $this->page_args( $request );

		$args = [
			'status'   => 'publish',
			'limit'    => $per_page,
			'page'     => $page,
			'paginate' => true,
			'orderby'  => $this->orderby( (string) $request->get_param( 'orderby' ) ),
			'order'    => 'ASC' === strtoupper( (string) $request->get_param( 'order' ) ) ? 'ASC' : 'DESC',
		];

		$this->product_filters( $request, $args );

		$result   = wc_get_products( $args );
		$products = is_object( $result ) ? ( $result->products ?? [] ) : (array) $result;
		$total    = is_object( $result ) && isset( $result->total ) ? (int) $result->total : count( $products );

		$items = [];
		foreach ( $products as $product ) {
			$items[] = $this->summary( $product );
		}

		return $this->paged( $items, $total, $page, $per_page );
	}

	/** The shared search/category/featured/sale/price/tenant filters of the product grid. */
	private function product_filters( \WP_REST_Request $request, array &$args ): void {
		$search = (string) $request->get_param( 'search' );
		if ( '' !== $search ) {
			$args['s'] = sanitize_text_field( $search );
		}

		$category = (string) $request->get_param( 'category' );
		if ( '' !== $category ) {
			$args['category'] = [ sanitize_title( $category ) ];
		}

		if ( $request->get_param( 'featured' ) ) {
			$args['featured'] = true;
		}
		if ( $request->get_param( 'on_sale' ) ) {
			$args['include'] = wc_get_product_ids_on_sale();
		}

		$meta_query = [];

		$tenant_id = $this->scoped_tenant_id( $request );
		if ( $tenant_id > 0 ) {
			$meta_query[] = [ 'key' => '_igbz_tenant_id', 'value' => $tenant_id ];
		}

		$min = (float) $request->get_param( 'min_price' );
		$max = (float) $request->get_param( 'max_price' );
		if ( $min > 0 || $max > 0 ) {
			$meta_query[] = [
				'key'     => '_price',
				'value'   => [ $min, $max > 0 ? $max : PHP_INT_MAX ],
				'type'    => 'NUMERIC',
				'compare' => 'BETWEEN',
			];
		}

		if ( $meta_query ) {
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
	}

	/**
	 * Phase 67 — keyset pagination for the product grid: strictly before the cursor's
	 * (date_created, id) tuple, DESC — stable while products publish and unpublish around
	 * the client. A cursor only addresses the default (date) ordering: with price or
	 * popularity order the tuple would not be a valid position, so that combination is
	 * refused loudly instead of returning a wrong slice.
	 *
	 * @param array<string,int|string> $position
	 */
	private function products_by_cursor( \WP_REST_Request $request, array $position ): \WP_REST_Response {
		if ( 'date' !== $this->orderby( (string) $request->get_param( 'orderby' ) ) ) {
			return $this->fail( 'igbz_validation', __( 'Cursor pagination applies to the default (date) ordering only.', 'igbz-suite' ), 400 );
		}

		$limit     = $this->cursor_limit( $request, 20 );
		$before_ts = (int) ( $position['t'] ?? 0 );
		$before_id = (int) ( $position['i'] ?? 0 );

		$args = [
			'status'   => 'publish',
			'limit'    => $limit + 1,
			'orderby'  => 'date',
			'order'    => 'DESC',
		];
		$this->product_filters( $request, $args );

		if ( $position ) {
			$fetched = array_merge(
				(array) wc_get_products( $args + [ 'date_created' => '<' . $before_ts ] ),
				(array) wc_get_products( $args + [ 'date_created' => $before_ts . '...' . $before_ts ] )
			);
		} else {
			$fetched = (array) wc_get_products( $args );
		}

		$batch = [];
		foreach ( $fetched as $product ) {
			$ts = (int) ( $product->get_date_created() ? $product->get_date_created()->getTimestamp() : 0 );
			$id = (int) $product->get_id();
			if ( $position && ( $ts > $before_ts || ( $ts === $before_ts && $id >= $before_id ) ) ) {
				continue;
			}
			$batch[ $id ] = [ 'item' => $this->summary( $product ), 'cursor' => [ 't' => $ts, 'i' => $id ] ];
		}

		usort( $batch, static fn ( array $a, array $b ) => $b['cursor']['t'] <=> $a['cursor']['t'] ?: $b['cursor']['i'] <=> $a['cursor']['i'] );

		return $this->cursor_page( array_values( $batch ), $limit, CursorCodec::KIND_PRODUCTS );
	}

	private function orderby( string $requested ): string {
		return match ( $requested ) {
			'price'      => 'price',
			'popularity' => 'popularity',
			'rating'     => 'rating',
			'title'      => 'title',
			default      => 'date',
		};
	}

	public function product( \WP_REST_Request $request ): \WP_REST_Response {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $request->get_param( 'id' ) ) : null;
		if ( ! $product || 'publish' !== $product->get_status() ) {
			return $this->fail( 'not_found', __( 'Product not found.', 'igbz-suite' ), 404 );
		}

		$tenant_id = $this->scoped_tenant_id( $request );
		$owner     = (int) $product->get_meta( '_igbz_tenant_id' );
		if ( $tenant_id > 0 && $owner > 0 && $owner !== $tenant_id ) {
			return $this->fail( 'not_found', __( 'Product not found.', 'igbz-suite' ), 404 );
		}

		$payload = $this->summary( $product );

		$payload['description']       = wp_kses_post( $product->get_description() );
		$payload['short_description'] = wp_kses_post( $product->get_short_description() );
		$payload['sku']               = $product->get_sku();
		$payload['stock_status']      = $product->get_stock_status();
		$payload['stock_quantity']    = $product->get_stock_quantity();
		$payload['attributes']        = $this->attributes( $product );
		$payload['gallery']           = array_values(
			array_filter(
				array_map(
					static fn ( $id ): string => (string) wp_get_attachment_image_url( (int) $id, 'large' ),
					$product->get_gallery_image_ids()
				)
			)
		);
		$payload['categories']        = array_values(
			array_map(
				static function ( int $term_id ): array {
					$term = get_term( $term_id, 'product_cat' );
					return [
						'id'   => $term_id,
						'name' => $term instanceof \WP_Term ? $term->name : '',
						'slug' => $term instanceof \WP_Term ? $term->slug : '',
					];
				},
				$product->get_category_ids()
			)
		);

		if ( $product->is_type( 'variable' ) ) {
			$payload['variations'] = $this->variations( $product );
		}

		// Instalment preview: the app shows "from X per month" straight on the product page.
		if ( igbz()->has( 'bnpl' ) && igbz()->settings()->bool( 'bnpl.enabled', false ) ) {
			$quote                 = igbz()->get( 'bnpl' )->quote( (float) $product->get_price() );
			$payload['instalment'] = [
				'count'   => count( $quote['installments'] ),
				'monthly' => $quote['installments'] ? (float) $quote['installments'][0]['amount'] : 0.0,
				'total'   => (float) $quote['total'],
			];
		}

		return $this->ok( $payload );
	}

	/** @return array<int,array<string,mixed>> */
	private function attributes( \WC_Product $product ): array {
		$out = [];
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}
			$out[] = [
				'name'    => wc_attribute_label( $attribute->get_name() ),
				'slug'    => $attribute->get_name(),
				'options' => $attribute->is_taxonomy()
					? array_values( array_map( static fn ( $term ) => $term->name, (array) $attribute->get_terms() ) )
					: array_values( (array) $attribute->get_options() ),
				'variation' => $attribute->get_variation(),
			];
		}
		return $out;
	}

	/** @return array<int,array<string,mixed>> */
	private function variations( \WC_Product $product ): array {
		$out = [];
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( (int) $child_id );
			if ( ! $variation ) {
				continue;
			}
			$image_id = (int) $variation->get_image_id();
			$out[]    = [
				'id'         => $variation->get_id(),
				'price'      => (float) $variation->get_price(),
				'attributes' => $variation->get_attributes(),
				'in_stock'   => $variation->is_in_stock(),
				'image_url'  => $image_id > 0 ? (string) wp_get_attachment_image_url( $image_id, 'woocommerce_single' ) : '',
			];
		}
		return $out;
	}

	/** @return array<string,mixed> */
	private function summary( \WC_Product $product ): array {
		$image_id = (int) $product->get_image_id();

		return [
			'id'             => $product->get_id(),
			'name'           => $product->get_name(),
			'slug'           => $product->get_slug(),
			'type'           => $product->get_type(),
			'price'          => (float) $product->get_price(),
			'regular_price'  => (float) $product->get_regular_price(),
			'sale_price'     => '' !== $product->get_sale_price() ? (float) $product->get_sale_price() : null,
			'on_sale'        => $product->is_on_sale(),
			'currency'       => get_woocommerce_currency(),
			'price_html'     => wp_strip_all_tags( (string) $product->get_price_html() ),
			'in_stock'       => $product->is_in_stock(),
			'rating'         => (float) $product->get_average_rating(),
			'review_count'   => (int) $product->get_review_count(),
			'permalink'      => get_permalink( $product->get_id() ),
			'image_url'      => $image_id > 0 ? (string) wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : wc_placeholder_img_src(),
			'is_downloadable' => $product->is_downloadable(),
		];
	}

	public function suggest( \WP_REST_Request $request ): \WP_REST_Response {
		$term = sanitize_text_field( (string) $request->get_param( 'q' ) );
		if ( strlen( $term ) < 2 || ! function_exists( 'wc_get_products' ) ) {
			return $this->ok( [ 'suggestions' => [] ] );
		}

		$args = [
			'status'  => 'publish',
			'limit'   => 8,
			's'       => $term,
			'orderby' => 'relevance',
		];

		$tenant_id = $this->scoped_tenant_id( $request );
		if ( $tenant_id > 0 ) {
			$args['meta_query'] = [ [ 'key' => '_igbz_tenant_id', 'value' => $tenant_id ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		$suggestions = [];
		foreach ( wc_get_products( $args ) as $product ) {
			$image_id      = (int) $product->get_image_id();
			$suggestions[] = [
				'id'        => $product->get_id(),
				'name'      => $product->get_name(),
				'price'     => (float) $product->get_price(),
				'image_url' => $image_id > 0 ? (string) wp_get_attachment_image_url( $image_id, 'woocommerce_gallery_thumbnail' ) : '',
			];
		}

		return $this->ok( [ 'suggestions' => $suggestions ] );
	}
}
