<?php
namespace IGBZ\Suite\Modules\MultiTenant\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Bounded product sitemap. Cheap and honest: one URL per real, published,
 * sellable product — never more than the cap, never thin placeholder pages.
 */
final class SitemapService {

	public const HARD_CAP = 1000;

	/** @return array<int,string> */
	public function urls( int $limit = 500 ): array {
		$limit = max( 1, min( $limit, self::HARD_CAP ) );

		$products = wc_get_products(
			[
				'status' => 'publish',
				'limit'  => $limit,
				'orderby' => 'ID',
				'order'   => 'ASC',
			]
		);

		$urls = [];
		foreach ( $products as $product ) {
			if ( (float) $product->get_price() <= 0 ) {
				continue;
			}
			$permalink = (string) get_permalink( $product->get_id() );
			if ( '' !== $permalink ) {
				$urls[] = $permalink;
			}
		}

		return $urls;
	}

	public function xml( int $limit = 500 ): string {
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		foreach ( $this->urls( $limit ) as $url ) {
			$xml .= '  <url><loc>' . esc_url( $url ) . '</loc></url>' . "\n";
		}
		$xml .= '</urlset>';

		return $xml;
	}
}
