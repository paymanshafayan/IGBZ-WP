<?php
namespace IGBZ\Suite\Modules\MultiTenant\Marketplace;

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Public product feeds for Iranian marketplaces (Torob, Emalls, Google Merchant) plus a link
 * registry that records the external id per product/channel.
 *
 * Feeds are read-only, cached, tenant-scoped and require no authentication - crawlers fetch
 *   /?igbz_feed=torob&tenant=12
 */
final class MarketplaceService {

	public const CHANNEL_TOROB  = 'torob';
	public const CHANNEL_EMALLS = 'emalls';
	public const CHANNEL_GOOGLE = 'google';

	private const CACHE_GROUP = 'igbz_feed';

	public function __construct( private Db $db, private Logger $logger ) {}

	/** @return array<string,string> */
	public function channels(): array {
		return apply_filters(
			'igbz_marketplace_channels',
			[
				self::CHANNEL_TOROB  => __( 'Torob', 'igbz-suite' ),
				self::CHANNEL_EMALLS => __( 'Emalls', 'igbz-suite' ),
				self::CHANNEL_GOOGLE => __( 'Google Merchant', 'igbz-suite' ),
			]
		);
	}

	public function is_channel_enabled( string $channel ): bool {
		return igbz()->settings()->bool( 'marketplace.' . $channel . '.enabled', false );
	}

	public function feed_url( string $channel, int $tenant_id = 0 ): string {
		return add_query_arg(
			array_filter( [ 'igbz_feed' => $channel, 'tenant' => $tenant_id ?: null ] ),
			home_url( '/' )
		);
	}

	// ------------------------------------------------------------ link registry

	/** @return array<string,mixed>|null */
	public function link( int $product_id, string $channel, int $tenant_id ): ?array {
		return $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'marketplace_links' ) . ' WHERE product_id = %d AND channel = %s AND tenant_id = %d',
			$product_id,
			$channel,
			$tenant_id
		);
	}

	public function save_link( int $product_id, string $channel, string $external_id, int $tenant_id = 0, string $status = 'synced', string $message = '' ): int {
		$existing = $this->link( $product_id, $channel, $tenant_id );
		$data     = [
			'tenant_id'      => $tenant_id,
			'product_id'     => $product_id,
			'channel'        => $channel,
			'external_id'    => $external_id,
			'last_synced_at' => current_time( 'mysql', true ),
			'sync_status'    => $status,
			'sync_message'   => mb_substr( $message, 0, 255 ),
		];

		if ( $existing ) {
			$this->db->update( 'marketplace_links', $data, [ 'id' => (int) $existing['id'] ] );
			return (int) $existing['id'];
		}
		return $this->db->insert( 'marketplace_links', $data );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 *
	 * Phase 20: bounded list. One store links each product to a handful of channels, so 5000
	 * rows is far above any realistic store while still keeping the query from ever being
	 * unbounded.
	 */
	public function links( int $tenant_id = 0, string $channel = '' ): array {
		if ( '' !== $channel ) {
			return $this->db->results(
				'SELECT * FROM ' . $this->db->table( 'marketplace_links' ) . ' WHERE tenant_id = %d AND channel = %s ORDER BY id DESC LIMIT 5000',
				$tenant_id,
				$channel
			);
		}
		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'marketplace_links' ) . ' WHERE tenant_id = %d ORDER BY id DESC LIMIT 5000',
			$tenant_id
		);
	}

	// ------------------------------------------------------------------ feeds

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function feed_items( string $channel, int $tenant_id = 0 ): array {
		$cache_key = $channel . ':' . $tenant_id;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$limit = igbz()->settings()->int( 'marketplace.feed_limit', 500 );
		$query = new \WP_Query(
			[
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'meta_query'     => $tenant_id > 0 ? [ [ 'key' => '_igbz_tenant_id', 'value' => $tenant_id ] ] : [], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		$items = [];
		foreach ( $query->posts as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product || ! $product->is_visible() ) {
				continue;
			}
			if ( 'yes' === get_post_meta( $product_id, '_igbz_feed_exclude', true ) ) {
				continue;
			}
			$items[] = $this->map_product( $product, $channel );
		}

		wp_cache_set( $cache_key, $items, self::CACHE_GROUP, igbz()->settings()->int( 'marketplace.cache_ttl', 900 ) );
		return $items;
	}

	/** @return array<string,mixed> */
	private function map_product( \WC_Product $product, string $channel ): array {
		$availability = $product->is_in_stock() ? 'in stock' : 'out of stock';

		$item = [
			'id'            => (string) $product->get_id(),
			'sku'           => $product->get_sku(),
			'title'         => wp_strip_all_tags( $product->get_name() ),
			'description'   => wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() ),
			'link'          => get_permalink( $product->get_id() ),
			'image'         => wp_get_attachment_url( (int) $product->get_image_id() ) ?: '',
			'price'         => (float) ( $product->get_regular_price() ?: $product->get_price() ),
			'sale_price'    => (float) ( $product->get_sale_price() ?: 0 ),
			'currency'      => get_woocommerce_currency(),
			'availability'  => $availability,
			'stock'         => $product->managing_stock() ? (int) $product->get_stock_quantity() : null,
			'brand'         => (string) get_post_meta( $product->get_id(), '_igbz_brand', true ),
			'categories'    => wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'names' ] ),
			'guarantee'     => (string) get_post_meta( $product->get_id(), '_igbz_guarantee', true ),
		];

		/**
		 * Adjust a single feed row.
		 *
		 * @param array<string,mixed> $item
		 */
		return (array) apply_filters( 'igbz_marketplace_feed_item', $item, $product, $channel );
	}

	/** Render the feed body for a channel. */
	public function render_feed( string $channel, int $tenant_id = 0 ): string {
		$items = $this->feed_items( $channel, $tenant_id );

		return match ( $channel ) {
			self::CHANNEL_GOOGLE => $this->render_google_xml( $items ),
			self::CHANNEL_EMALLS => $this->render_emalls_xml( $items ),
			default              => (string) wp_json_encode(
				[
					'store'       => get_bloginfo( 'name' ),
					'currency'    => get_woocommerce_currency(),
					'count'       => count( $items ),
					'generated_at' => gmdate( 'c' ),
					'products'    => $items,
				],
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			),
		};
	}

	public function feed_content_type( string $channel ): string {
		return in_array( $channel, [ self::CHANNEL_GOOGLE, self::CHANNEL_EMALLS ], true )
			? 'application/xml; charset=utf-8'
			: 'application/json; charset=utf-8';
	}

	/** @param array<int,array<string,mixed>> $items */
	private function render_google_xml( array $items ): string {
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel>' . "\n";
		$xml .= '<title>' . esc_html( get_bloginfo( 'name' ) ) . '</title>' . "\n";
		$xml .= '<link>' . esc_url( home_url( '/' ) ) . '</link>' . "\n";
		$xml .= '<description>' . esc_html( get_bloginfo( 'description' ) ) . '</description>' . "\n";

		foreach ( $items as $item ) {
			$price = $item['sale_price'] > 0 ? $item['sale_price'] : $item['price'];
			$xml  .= '<item>' . "\n";
			$xml  .= '<g:id>' . esc_html( (string) $item['id'] ) . '</g:id>' . "\n";
			$xml  .= '<title>' . esc_html( (string) $item['title'] ) . '</title>' . "\n";
			$xml  .= '<description>' . esc_html( mb_substr( (string) $item['description'], 0, 5000 ) ) . '</description>' . "\n";
			$xml  .= '<link>' . esc_url( (string) $item['link'] ) . '</link>' . "\n";
			$xml  .= '<g:image_link>' . esc_url( (string) $item['image'] ) . '</g:image_link>' . "\n";
			$xml  .= '<g:price>' . esc_html( $price . ' ' . $item['currency'] ) . '</g:price>' . "\n";
			$xml  .= '<g:availability>' . esc_html( (string) $item['availability'] ) . '</g:availability>' . "\n";
			$xml  .= '<g:condition>new</g:condition>' . "\n";
			if ( '' !== $item['brand'] ) {
				$xml .= '<g:brand>' . esc_html( (string) $item['brand'] ) . '</g:brand>' . "\n";
			}
			$xml .= '</item>' . "\n";
		}

		return $xml . '</channel></rss>';
	}

	/** @param array<int,array<string,mixed>> $items */
	private function render_emalls_xml( array $items ): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<products>\n";
		foreach ( $items as $item ) {
			$price = $item['sale_price'] > 0 ? $item['sale_price'] : $item['price'];
			$xml  .= "<product>\n";
			$xml  .= '<id>' . esc_html( (string) $item['id'] ) . '</id>' . "\n";
			$xml  .= '<name>' . esc_html( (string) $item['title'] ) . '</name>' . "\n";
			$xml  .= '<price>' . esc_html( (string) $price ) . '</price>' . "\n";
			$xml  .= '<url>' . esc_url( (string) $item['link'] ) . '</url>' . "\n";
			$xml  .= '<image>' . esc_url( (string) $item['image'] ) . '</image>' . "\n";
			$xml  .= '<availability>' . esc_html( (string) $item['availability'] ) . '</availability>' . "\n";
			$xml  .= "</product>\n";
		}
		return $xml . '</products>';
	}

	public function flush_cache(): void {
		foreach ( array_keys( $this->channels() ) as $channel ) {
			wp_cache_delete( $channel . ':0', self::CACHE_GROUP );
		}
		do_action( 'igbz_marketplace_cache_flushed' );
	}
}
