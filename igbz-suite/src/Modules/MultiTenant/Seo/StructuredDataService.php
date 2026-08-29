<?php
namespace IGBZ\Suite\Modules\MultiTenant\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Product structured data (schema.org JSON-LD).
 *
 * Built on two research-backed rules (phase 47):
 * - only the Google-required core is emitted: name, image and an Offer with
 *   price as a plain numeric string, an ISO 4217 currency and a full
 *   schema.org availability URL;
 * - content parity: nothing is emitted that is not genuinely on the page —
 *   no fabricated ratings, no invented brand, price 0 never pretends to be
 *   a merchant listing.
 */
final class StructuredDataService {

	/**
	 * @param \WC_Product $product
	 * @return array<string,mixed> an embed-ready JSON-LD payload ([] when the page cannot honestly be a Product)
	 */
	public function product_jsonld( $product ): array {
		$name = trim( (string) $product->get_name() );
		if ( '' === $name ) {
			return [];
		}

		$image_id = (int) $product->get_image_id();
		$image    = $image_id > 0 ? (string) wp_get_attachment_url( $image_id ) : '';

		$price = (float) $product->get_price();
		$stock = (int) $product->get_stock_quantity();

		$jsonld = [
			'@context' => 'https://schema.org',
			'@type'    => 'Product',
			'name'     => $name,
		];
		if ( '' !== $image ) {
			$jsonld['image'] = $image;
		}

		$description = wp_strip_all_tags( (string) $product->get_description() );
		$description = trim( preg_replace( '/\s+/u', ' ', $description ) );
		if ( '' !== $description ) {
			$jsonld['description'] = mb_substr( $description, 0, 300 );
		}

		$sku = (string) $product->get_sku();
		if ( '' !== $sku ) {
			$jsonld['sku'] = $sku;
		}

		// A product that cannot be bought is not a merchant listing; emit no offer.
		if ( $price <= 0 ) {
			return $jsonld;
		}

		$offer = [
			'@type'         => 'Offer',
			// Price must be a plain numeric string — never a currency symbol, never a thousands separator.
			'price'         => number_format( $price, 2, '.', '' ),
			'priceCurrency' => 'IRR',
			'availability'  => $stock > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
		];

		$permalink = (string) get_permalink( $product->get_id() );
		if ( '' !== $permalink ) {
			$offer['url'] = $permalink;
		}

		$jsonld['offers'] = $offer;

		return $jsonld;
	}

	/** @param array<string,mixed> $jsonld */
	public function script_tag( array $jsonld ): string {
		if ( [] === $jsonld ) {
			return '';
		}
		return '<script type="application/ld+json">' . wp_json_encode( $jsonld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>';
	}
}
