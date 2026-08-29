<?php
namespace IGBZ\Suite\Modules\MultiTenant\Marketplace;

use IGBZ\Suite\Support\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Basalam (سلام API) adapter — products + Instagram-like content
 * (stories/posts) so content made for Instagram can also go to Basalam.
 */
final class BasalamAdapter implements MarketplaceAdapterInterface {

	public function __construct( private Http $http ) {}

	public function id(): string {
		return 'basalam';
	}

	public function title(): string {
		return 'Basalam';
	}

	public function is_configured(): bool {
		return '' !== igbz()->settings()->string( 'basalam.api_key' )
			&& '' !== igbz()->settings()->string( 'basalam.base_url' );
	}

	public function upsert( array $product, array $mapping ): array {
		if ( ! $this->is_configured() ) {
			return [ 'ok' => false, 'remote_id' => '', 'message' => __( 'Basalam is not configured.', 'igbz-suite' ) ];
		}
		$response = $this->http->post(
			rtrim( igbz()->settings()->string( 'basalam.base_url' ), '/' ) . '/v1/products',
			[
				'json'    => [
					'name'        => (string) ( $product['name'] ?? '' ),
					'description' => (string) ( $product['description'] ?? '' ),
					'price'       => (int) ( $product['price_irt'] ?? 0 ),
					'stock'       => (int) ( $product['stock'] ?? 0 ),
					'category'    => (string) ( $mapping['remote_category'] ?? '' ),
					'images'      => (array) ( $product['images'] ?? [] ),
					'gharhe_id'   => igbz()->settings()->string( 'basalam.gharhe_id' ),
				],
				'headers' => [ 'Authorization' => 'Bearer ' . igbz()->settings()->string( 'basalam.api_key' ), 'Accept' => 'application/json' ],
				'channel' => 'marketplace',
				'timeout' => 30,
			]
		);
		$body = $response->json();
		if ( ! $response->ok() ) {
			return [ 'ok' => false, 'remote_id' => '', 'message' => (string) ( $body['message'] ?? $body['error'] ?? 'basalam_failed' ), 'http_status' => $response->status, 'retry_after' => $this->retry_after_of( $response ) ];
		}
		$remote = (string) ( $body['id'] ?? $body['product_id'] ?? $body['data']['id'] ?? '' );
		return '' !== $remote
			? [ 'ok' => true, 'remote_id' => $remote, 'message' => '', 'http_status' => $response->status, 'retry_after' => 0 ]
			: [ 'ok' => false, 'remote_id' => '', 'message' => __( 'Basalam did not return a product id.', 'igbz-suite' ), 'http_status' => $response->status, 'retry_after' => 0 ];
	}

	public function update_price_stock( string $remote_id, float $price_irt, int $stock ): array {
		if ( ! $this->is_configured() ) {
			return [ 'ok' => false, 'message' => __( 'Basalam is not configured.', 'igbz-suite' ) ];
		}
		$response = $this->http->post(
			rtrim( igbz()->settings()->string( 'basalam.base_url' ), '/' ) . '/v1/products/' . rawurlencode( $remote_id ),
			[
				'json'    => [ 'price' => (int) $price_irt, 'stock' => (int) $stock ],
				'headers' => [ 'Authorization' => 'Bearer ' . igbz()->settings()->string( 'basalam.api_key' ), 'Accept' => 'application/json' ],
				'channel' => 'marketplace',
				'timeout' => 30,
			]
		);
		return $response->ok()
			? [ 'ok' => true, 'message' => '', 'http_status' => $response->status, 'retry_after' => 0 ]
			: [ 'ok' => false, 'message' => $response->error_message(), 'http_status' => $response->status, 'retry_after' => $this->retry_after_of( $response ) ];
	}

	/** The Retry-After the marketplace asked for, in seconds (0 when it did not). */
	private function retry_after_of( \IGBZ\Suite\Support\HttpResponse $response ): int {
		foreach ( $response->headers as $name => $value ) {
			if ( 'retry-after' === strtolower( (string) $name ) ) {
				$seconds = (int) ( is_array( $value ) ? ( $value[0] ?? 0 ) : $value );
				return max( 0, min( $seconds, 3600 ) );
			}
		}
		return 0;
	}

	/** Publish Instagram-made content (post/story) to Basalam when enabled. */
	public function publish_content( array $content ): array {
		if ( ! $this->is_configured() || ! igbz()->settings()->bool( 'basalam.enabled', false ) ) {
			return [ 'ok' => false, 'remote_id' => '', 'message' => __( 'Basalam content publishing is disabled.', 'igbz-suite' ) ];
		}
		$response = $this->http->post(
			rtrim( igbz()->settings()->string( 'basalam.base_url' ), '/' ) . '/v1/content',
			[
				'json'    => [
					'type'      => (string) ( $content['kind'] ?? 'post' ),
					'caption'   => (string) ( $content['caption'] ?? '' ),
					'media_url' => (string) ( $content['media_url'] ?? '' ),
					'gharhe_id' => igbz()->settings()->string( 'basalam.gharhe_id' ),
				],
				'headers' => [ 'Authorization' => 'Bearer ' . igbz()->settings()->string( 'basalam.api_key' ), 'Accept' => 'application/json' ],
				'channel' => 'marketplace',
				'timeout' => 60,
			]
		);
		$body = $response->json();
		return $response->ok()
			? [ 'ok' => true, 'remote_id' => (string) ( $body['id'] ?? '' ), 'message' => '' ]
			: [ 'ok' => false, 'remote_id' => '', 'message' => $response->error_message() ];
	}
}
