<?php
namespace IGBZ\Suite\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 68 — the Persian storefront layer, as product code.
 *
 * Until now the playground demo faked Persian pricing/digits in a harness
 * mu-plugin that its own header said "must be replaced by the real translation
 * pack in production". This class IS that replacement for everything a store
 * owner expects a Persian shop to do out of the box:
 *
 *  - a real «تومان» (IRT) currency registered with WooCommerce (IRR keeps its
 *    proper ریال symbol — a shop that prices in tomans should select IRT, not
 *    relabel rials);
 *  - new stores default the customer's address to the store base country, and
 *    the checkout country falls back to it for guests and users with no saved
 *    address (the US-by-geolocation bug the 1406/06/02 visual test caught);
 *  - optional Persian digits on the rendered storefront — text nodes only.
 *
 * The plugin's own string translations ship as languages/igbz-suite-fa_IR.po/.mo
 * (the fa_IR pack, phase 68); WooCommerce core's own translation packs come
 * from WordPress.org in production and are intentionally not duplicated here.
 */
final class FaStorefront {

	public static function register(): void {
		add_filter( 'woocommerce_currencies', [ __CLASS__, 'add_toman_currency' ] );
		add_filter( 'woocommerce_currency_symbol', [ __CLASS__, 'currency_symbol' ], 99, 2 );

		// A brand-new store should greet customers with the store's own country,
		// not a geolocated guess. (Only fires while the option is unset, so an
		// explicit store choice always wins.)
		add_filter( 'default_option_woocommerce_default_customer_address', static fn (): string => 'base' );

		add_filter( 'default_checkout_billing_country', [ __CLASS__, 'checkout_country' ] );
		add_filter( 'default_checkout_shipping_country', [ __CLASS__, 'checkout_country' ] );

		add_action( 'template_redirect', [ __CLASS__, 'start_digit_buffer' ] );
	}

	/** @param array<string,string> $currencies */
	public static function add_toman_currency( array $currencies ): array {
		if ( igbz()->settings()->bool( 'i18n.toman_currency', true ) ) {
			$currencies['IRT'] = __( 'Iranian Toman', 'igbz-suite' );
		}

		return $currencies;
	}

	public static function currency_symbol( string $symbol, string $currency ): string {
		return match ( $currency ) {
			'IRT' => 'تومان',
			'IRR' => 'ریال',
			default => $symbol,
		};
	}

	/**
	 * The checkout country default for guests and for logged-in customers
	 * without a saved address: the store's base country. A saved address is
	 * never overridden — the filter only fills the empty case.
	 */
	public static function checkout_country( $value ): string {
		if ( ! igbz()->settings()->bool( 'i18n.checkout_base_country', true ) ) {
			return (string) $value;
		}
		if ( (string) $value !== '' ) {
			return (string) $value;
		}

		$base = function_exists( 'wc_get_base_location' ) ? wc_get_base_location() : [];
		$code = is_array( $base ) ? (string) ( $base['country'] ?? '' ) : '';

		return '' !== $code ? $code : (string) $value;
	}

	/**
	 * Persian digits on the storefront, ported from the harness with every scar
	 * it earned: inline <script>/<style> and full tags are left intact (a
	 * converted width="۲۴" or /uploads/۲۰۲۶/ URL breaks the page, and Persian
	 * digits inside JS are a SyntaxError), HTML entities are left intact, and
	 * admin/AJAX/cron/REST never see a conversion — the mobile app parses JSON
	 * numbers.
	 */
	public static function start_digit_buffer(): void {
		if ( ! igbz()->settings()->bool( 'i18n.persian_front_digits', true ) ) {
			return;
		}
		if ( is_admin() || wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		ob_start(
			static function ( $html ) {
				if ( ! is_string( $html ) || '' === $html ) {
					return $html;
				}

				return (string) preg_replace_callback(
					'/(<script\b[^>]*>.*?<\/script>|<style\b[^>]*>.*?<\/style>|<[^>]*>|&#x?[0-9a-fA-F]+;)|([0-9]+)/si',
					static function ( array $m ): string {
						return isset( $m[1] ) && '' !== $m[1] ? $m[1] : FaLocale::persian_digits( $m[0] );
					},
					$html
				);
			},
			0
		);
	}
}
