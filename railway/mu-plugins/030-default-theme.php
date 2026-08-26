<?php
/**
 * Harness only. On first load, stage the default theme (WordPress bundled
 * default — Twenty Twenty-Five when available, falling back to the oldest
 * known core default) and WooCommerce store options. Theme switching runs
 * on `setup_theme` priority 0 so the target theme's functions.php loads
 * from scratch and we avoid the mid-request-switch fatal.
 *
 * 1406/05/31: Elementor + Hello Elementor removed (~108 MB saved).
 */
add_action( 'setup_theme', function () {
	$candidates = [ 'twentytwentyfive', 'twentytwentyfour', 'twentytwentythree' ];
	$target     = null;
	$themes     = wp_get_themes();
	foreach ( $candidates as $slug ) {
		if ( isset( $themes[ $slug ] ) ) { $target = $slug; break; }
	}
	if ( ! $target ) {
		foreach ( $themes as $slug => $_ ) {
			if ( str_starts_with( $slug, 'twenty' ) ) { $target = $slug; break; }
		}
	}
	if ( $target && ( get_option( 'stylesheet' ) !== $target || get_option( 'template' ) !== $target ) ) {
		switch_theme( $target );
	}
}, 0 );

add_action( 'wp_loaded', function () {
	// v6: Elementor removed; RTL + Persian + WC defaults still applied.
	$ver = 'v6';
	$prev = get_option( 'igbz_dev_shop_defaults' );
	if ( $prev === $ver ) { return; }
	if ( ! function_exists( 'switch_theme' ) ) { require_once ABSPATH . 'wp-includes/theme.php'; }

	// Permalinks: post-name.
	global $wp_rewrite;
	if ( get_option( 'permalink_structure' ) !== '/%postname%/' ) {
		update_option( 'permalink_structure', '/%postname%/' );
		if ( $wp_rewrite ) {
			$wp_rewrite->set_permalink_structure( '/%postname%/' );
			flush_rewrite_rules( false );
		}
	}

	// Site identity.
	if ( get_option( 'blogname' ) !== 'شاپ بیوتی — فروشگاه نمونه آرایشی' ) {
		update_option( 'blogname', 'شاپ بیوتی — فروشگاه نمونه آرایشی' );
	}

	// WooCommerce basics.
	if ( class_exists( 'WooCommerce' ) ) {
		update_option( 'woocommerce_default_country', 'IR' );
		// بدون این، فرم تسویه کشور را از geolocation می‌گیرد و «United States» پیش‌فرض
		// می‌شود (یافتهٔ تست ویژوال ۱۴۰۶/۰۶/۰۲) — پیش‌فرض مشتری = آدرس فروشگاه (IR)
		update_option( 'woocommerce_default_customer_address', 'base' );
		update_option( 'woocommerce_currency', 'IRR' );
		update_option( 'woocommerce_currency_pos', 'right_space' );
		update_option( 'woocommerce_price_thousand_sep', ',' );
		update_option( 'woocommerce_price_decimal_sep', '/' );
		update_option( 'woocommerce_price_num_decimals', 0 );
		update_option( 'woocommerce_weight_unit', 'kg' );
		update_option( 'woocommerce_dimension_unit', 'cm' );
		// Launch the store (turn off WooCommerce 11's "Coming soon" banner)
		update_option( 'woocommerce_coming_soon', 'no' );
		update_option( 'woocommerce_store_pages_only', 'no' );
		// Taxes off for the demo, COD enabled as a default gateway
		update_option( 'woocommerce_calc_taxes', 'no' );
		if ( ! get_option( 'woocommerce_cod_settings' ) ) {
			update_option( 'woocommerce_cod_settings', [
				'enabled' => 'yes',
				'title'   => 'پرداخت در محل',
			] );
		}

		$pages = [
			'woocommerce_shop_page_id'      => [ 'فروشگاه', '' ],
			'woocommerce_cart_page_id'      => [ 'سبد خرید', '' ],
			'woocommerce_checkout_page_id'  => [ 'تسویه حساب', '' ],
			'woocommerce_myaccount_page_id' => [ 'حساب کاربری من', '' ],
		];
		foreach ( $pages as $opt => [ $title, $content ] ) {
			$id = (int) get_option( $opt );
			$needs_update = ! $id || get_post_type( $id ) !== 'page' || get_post_status( $id ) !== 'publish';
			if ( ! $needs_update && get_the_title( $id ) !== $title ) {
				// Rename default WC pages (e.g. "Shop") to Persian in place
				wp_update_post( [ 'ID' => $id, 'post_title' => $title, 'post_name' => sanitize_title( $title ) ] );
			}
			if ( $needs_update ) {
				$id = wp_insert_post( [
					'post_type'    => 'page',
					'post_title'   => $title,
					'post_name'    => sanitize_title( $title ),
					'post_content' => $content,
					'post_status'  => 'publish',
					'post_author'  => 1,
				] );
				if ( $id && ! is_wp_error( $id ) ) { update_option( $opt, $id ); }
			}
		}

		// Make the Shop page the site homepage so visitors land on the catalog
		$shop_id = (int) get_option( 'woocommerce_shop_page_id' );
		if ( $shop_id ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $shop_id );
		}
		// Reading / time locale
		update_option( 'timezone_string', 'Asia/Tehran' );
		update_option( 'WPLANG', 'fa_IR' );
	}

	// RTL + Persian-friendly defaults for the demo storefront (regardless of
	// whether fa_IR language pack is installed in the sandbox).
	if ( ! is_admin() ) {
		add_filter( 'language_attributes', function ( $out ) {
			$out = preg_replace( '/dir=["\'][^"\']+["\']/', 'dir="rtl"', $out ) ?? $out;
			if ( strpos( $out, 'dir=' ) === false ) { $out .= ' dir="rtl"'; }
			return $out;
		}, 99 );
		add_action( 'wp_enqueue_scripts', function () {
			$css = 'html[dir="rtl"] body{font-family:Tahoma,Arial,sans-serif!important;text-align:right!important;}'
				. ' html[dir="rtl"] .site-header,html[dir="rtl"] .site-navigation,html[dir="rtl"] .woocommerce-breadcrumb,'
				. ' html[dir="rtl"] .woocommerce-result-count,html[dir="rtl"] .woocommerce-ordering,'
				. ' html[dir="rtl"] .products,html[dir="rtl"] .woocommerce ul.products,'
				. ' html[dir="rtl"] .product,html[dir="rtl"] .woocommerce-loop-product__title,'
				. ' html[dir="rtl"] .price,html[dir="rtl"] .add_to_cart_button,html[dir="rtl"] .button,'
				. ' html[dir="rtl"] .woocommerce-pagination,html[dir="rtl"] .widget,'
				. ' html[dir="rtl"] .entry-summary,html[dir="rtl"] .entry-content{text-align:right!important;}'
				. ' html[dir="rtl"] .woocommerce .woocommerce-ordering{float:left!important;}'
				. ' html[dir="rtl"] .woocommerce .woocommerce-result-count{float:right!important;}'
				. ' html[dir="rtl"] .onsale{left:auto!important;right:10px!important;}'
				. ' html[dir="rtl"] .woocommerce ul.products li.product,html[dir="rtl"] .woocommerce-page ul.products li.product{float:right!important;margin:0 0 2.992em 1.5em!important;}'
				. ' html[dir="rtl"] .woocommerce ul.products[class*=columns-] li.product.last,html[dir="rtl"] .woocommerce-page ul.products[class*=columns-] li.product.last{margin-left:0!important;}';
			wp_add_inline_style( 'woocommerce-general', $css );
			wp_add_inline_style( 'global-styles', $css );
		}, 99 );
	}

	update_option( 'igbz_dev_shop_defaults', $ver );
}, 50 );

