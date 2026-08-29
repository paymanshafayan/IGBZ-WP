<?php
namespace IGBZ\Suite\Modules\MultiTenant\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Tenant product head tags: meta title/description, canonical and robots.
 *
 * One rule above all: the canonical URL is the one real page, and pages that
 * are not honestly sellable are noindexed instead of pretending otherwise.
 */
final class HeadTagsService {

	public function __construct( private SeoService $seo ) {}

	/**
	 * @param \WC_Product $product
	 * @return array{meta_title:string,meta_description:string,canonical:string,robots:string,html:string}
	 */
	public function product_head( $product ): array {
		$seo       = $this->seo->generate( (string) $product->get_name(), (string) $product->get_description() );
		$permalink = (string) get_permalink( $product->get_id() );
		$sellable  = (float) $product->get_price() > 0;

		$html  = '<title>' . esc_html( $seo['meta_title'] ) . '</title>' . "\n";
		$html .= '<meta name="description" content="' . esc_attr( $seo['meta_description'] ) . '" />' . "\n";
		if ( '' !== $permalink ) {
			$html .= '<link rel="canonical" href="' . esc_url( $permalink ) . '" />' . "\n";
		}
		$robots = $sellable ? 'index,follow' : 'noindex,follow';
		$html .= '<meta name="robots" content="' . esc_attr( $robots ) . '" />';

		return [
			'meta_title'       => $seo['meta_title'],
			'meta_description' => $seo['meta_description'],
			'canonical'        => $permalink,
			'robots'           => $robots,
			'html'             => $html,
		];
	}
}
