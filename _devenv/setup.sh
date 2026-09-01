#!/usr/bin/env bash
#
# Build the offline WordPress + WooCommerce test environment.
#
# Reads wordpress-*.zip and woocommerce-*.zip from _devenv/ (committed to the repo) so the
# environment can be rebuilt with no access to wordpress.org. Idempotent: safe to re-run.
#
# Usage:  bash _devenv/setup.sh [--force]
#
set -Eeuo pipefail

DEVENV="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$DEVENV/.." && pwd)"
WORK="$DEVENV/.work"

PLAYGROUND_VERSION="3.1.49"
PHPWASM_VERSION="3.1.49"

# Bundled-in-repo third-party plugins/themes served to the Playground (offline).
# NOTE (1406/05/31): Elementor + Hello Elementor were removed (~108 MB) to keep
# the repo lightweight. The harness now uses WordPress's bundled default
# theme (Twenty Twenty-Five) and only ships WooCommerce + igbz-suite out of
# the box. Elementor may be re-added later as an optional on-demand install.
BUNDLED_PLUGINS=()
BUNDLED_THEMES=()

die()  { printf '\n\033[31merror:\033[0m %s\n' "$*" >&2; exit 1; }
info() { printf '\033[36m==>\033[0m %s\n' "$*"; }
ok()   { printf '  \033[32mok\033[0m %s\n' "$*"; }

trap 'die "setup failed on line $LINENO"' ERR

FORCE=0
[ "${1:-}" = "--force" ] && FORCE=1

# ---------------------------------------------------------------------------
# 0. Prerequisites
# ---------------------------------------------------------------------------
command -v node >/dev/null || die "node is required but not installed"
command -v npm  >/dev/null || die "npm is required but not installed"
command -v python3 >/dev/null || die "python3 is required (used to serve the WordPress zip)"

info "node $(node -v), npm $(npm -v)"

# ---------------------------------------------------------------------------
# 1. Locate the zips
# ---------------------------------------------------------------------------
# Pick the newest match so wordpress-7.1.zip wins over wordpress-7.0.4.zip if both exist.
newest() { ls -1t $1 2>/dev/null | head -1; }

WP_ZIP="$(newest "$DEVENV/wordpress-*.zip" || true)"
WC_ZIP="$(newest "$DEVENV/woocommerce-*.zip" || true)"

fetch_if_missing() {
	local kind="$1" dest="$2" url="$3"
	info "$kind zip not found in _devenv/ — trying the network"
	if curl -sfL --max-time 300 -o "$dest.part" "$url" 2>/dev/null; then
		mv "$dest.part" "$dest"
		ok "downloaded $(basename "$dest")"
		printf '%s' "$dest"
	else
		rm -f "$dest.part"
		return 1
	fi
}

if [ -z "$WP_ZIP" ]; then
	WP_ZIP="$(fetch_if_missing WordPress "$DEVENV/wordpress-7.0.4.zip" \
		"https://wordpress.org/wordpress-7.0.4.zip")" || die \
"No WordPress zip found and it could not be downloaded (wordpress.org is blocked here).

Put the official zip at:
    _devenv/wordpress-7.0.4.zip
Get it from:
    https://wordpress.org/latest.zip"
fi

if [ -z "$WC_ZIP" ]; then
	WC_ZIP="$(fetch_if_missing WooCommerce "$DEVENV/woocommerce-11.0.1.zip" \
		"https://downloads.wordpress.org/plugin/woocommerce.11.0.1.zip")" || die \
"No WooCommerce zip found and it could not be downloaded (wordpress.org is blocked here).

Put the official zip at:
    _devenv/woocommerce-11.0.1.zip
Get it from:
    https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip"
fi

ok "WordPress zip:  $(basename "$WP_ZIP") ($(du -h "$WP_ZIP" | cut -f1))"
ok "WooCommerce zip: $(basename "$WC_ZIP") ($(du -h "$WC_ZIP" | cut -f1))"

# ---------------------------------------------------------------------------
# 2. Validate the zips before doing any work, so a bad upload fails clearly
# ---------------------------------------------------------------------------
info "validating zip contents"
python3 - "$WP_ZIP" "$WC_ZIP" <<'PY' || die "zip validation failed (see above)"
import re, sys, zipfile

wp_path, wc_path = sys.argv[1], sys.argv[2]
problems = []

def check(path, label):
    try:
        return zipfile.ZipFile(path)
    except Exception as e:
        problems.append(f"{label}: not a readable zip ({e})")
        return None

wp = check(wp_path, "WordPress")
wc = check(wc_path, "WooCommerce")

if wp:
    names = wp.namelist()
    ver = next((n for n in names if n.endswith("wp-includes/version.php")), None)
    if not ver:
        problems.append("WordPress: no wp-includes/version.php inside — is this really a WordPress zip?")
    else:
        src = wp.read(ver).decode("utf8", "replace")
        m = re.search(r"\$wp_version\s*=\s*'([^']+)'", src)
        nested = ver.split("wp-includes/")[0]
        print(f"  WordPress {m.group(1) if m else '?'}"
              + (f" (nested under '{nested}')" if nested else " (files at zip root)"))

if wc:
    names = wc.namelist()
    main = next((n for n in names if re.fullmatch(r"[^/]+/woocommerce\.php", n)), None)
    if not main:
        problems.append("WooCommerce: no <folder>/woocommerce.php inside — is this really the WooCommerce plugin zip?")
    else:
        src = wc.read(main).decode("utf8", "replace")
        v  = re.search(r"^\s*\*\s*Version:\s*(.+)$", src, re.M)
        wpr= re.search(r"^\s*\*\s*Requires at least:\s*(.+)$", src, re.M)
        print(f"  WooCommerce {v.group(1).strip() if v else '?'}"
              f" (requires WP {wpr.group(1).strip() if wpr else '?'})")
        # The built plugin must contain its vendor autoloader; a git export will not.
        if not any(n.endswith("vendor/autoload.php") for n in names):
            problems.append(
                "WooCommerce: vendor/autoload.php missing. This looks like a source checkout "
                "rather than the released plugin zip; it will not run.")

for p in problems:
    print("  PROBLEM: " + p, file=sys.stderr)
sys.exit(1 if problems else 0)
PY
ok "zips look correct"

# ---------------------------------------------------------------------------
# 3. Node tooling (Playground CLI + php-wasm CLI for the unit tests)
# ---------------------------------------------------------------------------
mkdir -p "$WORK"
cd "$WORK"

if [ ! -f "$WORK/node_modules/@wp-playground/cli/wp-playground.js" ] || [ "$FORCE" = "1" ]; then
	info "installing Playground CLI + php-wasm (npm; this is the only network dependency)"
	[ -f package.json ] || echo '{"name":"igbz-devenv","private":true}' > package.json
	npm install --no-audit --no-fund --loglevel=error \
		"@wp-playground/cli@$PLAYGROUND_VERSION" \
		"@php-wasm/cli@$PHPWASM_VERSION" \
		|| die "npm install failed — is registry.npmjs.org reachable?"
	ok "node tooling installed"
else
	ok "node tooling already present (use --force to reinstall)"
fi

# ---------------------------------------------------------------------------
# 4. Extract WooCommerce
# ---------------------------------------------------------------------------
WC_DIR="$WORK/woocommerce"
WC_STAMP="$WORK/.woocommerce.stamp"
WC_ID="$(basename "$WC_ZIP")-$(stat -c %s "$WC_ZIP")"

if [ ! -f "$WC_STAMP" ] || [ "$(cat "$WC_STAMP")" != "$WC_ID" ] || [ "$FORCE" = "1" ]; then
	info "extracting WooCommerce"
	rm -rf "$WC_DIR" "$WORK/.wc-tmp"
	mkdir -p "$WORK/.wc-tmp"
	python3 -c "import sys,zipfile; zipfile.ZipFile(sys.argv[1]).extractall(sys.argv[2])" \
		"$WC_ZIP" "$WORK/.wc-tmp"
	# The zip contains a single top-level folder; that folder is the plugin.
	inner="$(find "$WORK/.wc-tmp" -mindepth 2 -maxdepth 2 -name woocommerce.php -printf '%h\n' | head -1)"
	[ -n "$inner" ] || die "could not locate woocommerce.php inside the zip"
	mv "$inner" "$WC_DIR"
	rm -rf "$WORK/.wc-tmp"
	echo "$WC_ID" > "$WC_STAMP"
	ok "WooCommerce extracted ($(find "$WC_DIR" -type f | wc -l) files)"
else
	ok "WooCommerce already extracted"
fi

# ---------------------------------------------------------------------------
# 5. Publish the WordPress zip for the local HTTP server
# ---------------------------------------------------------------------------
# The Playground CLI's --wp flag accepts an http(s) URL, and that code path runs *before* its
# blocked api.wordpress.org lookup. run.sh serves this directory and points --wp at it.
info "publishing the WordPress zip for the local server"
mkdir -p "$WORK/serve"
find "$WORK/serve" -name 'wordpress-*.zip' -delete
cp "$WP_ZIP" "$WORK/serve/$(basename "$WP_ZIP")"
echo "$(basename "$WP_ZIP")" > "$WORK/.wp-zip-name"
ok "served as $(basename "$WP_ZIP")"

# ---------------------------------------------------------------------------
# 6. mu-plugins used by the harness
# ---------------------------------------------------------------------------
info "writing harness mu-plugins"
mkdir -p "$WORK/mu"

cat > "$WORK/mu/000-activate.php" <<'PHP'
<?php
/**
 * Harness only. Force-activate WooCommerce and the plugin under test, in that
 * order, so dependencies are fully loaded before igbz-suite boots.
 * (Elementor removed 1406/05/31 — repo-size decision.)
 */
add_action( 'plugins_loaded', function () {
	$want = [
		'woocommerce/woocommerce.php',
		'igbz-suite/igbz-suite.php',
	];
	$have = (array) get_option( 'active_plugins', [] );
	$new  = array_values( array_unique( array_merge( $want, array_diff( $have, $want ) ) ) );
	// Defensive: also drop elementor from active list so stale DB options don't fatal.
	$new  = array_values( array_filter( $new, fn( $p ) => strpos( $p, 'elementor' ) === false ) );
	if ( $new !== $have ) {
		update_option( 'active_plugins', $new );
	}
}, 0 );
PHP

cat > "$WORK/mu/010-enable-modules.php" <<'PHP'
<?php
/**
 * Harness only. Turn on every registered module once, so every admin screen
 * and REST route exists (including the new Pado module added in v19).
 * Uses Modules::all() so future modules are auto-enabled without touching
 * this file — but we bump the guard option to force a re-sync when the list
 * grows.
 */
add_action( 'igbz_booted', function () {
	$guard = 'igbz_devenv_modules_on_v3';
	if ( get_option( $guard ) ) { return; }
	if ( ! class_exists( '\\IGBZ\\Suite\\Support\\Modules' ) ) { return; }
	update_option( \IGBZ\Suite\Support\Modules::OPTION, \IGBZ\Suite\Support\Modules::all() );
	update_option( $guard, 1 );
} );
PHP

cat > "$WORK/mu/015-no-emoji-cdn.php" <<'PHP'
<?php
/**
 * Harness only. s.w.org (WordPress emoji CDN) is blocked in this sandbox, so
 * the emoji-to-<img> conversion produced broken images in both wp-admin and
 * the storefront (finding of the 1406/06/02 visual test). Disable it and let
 * native system emoji render instead. No production impact.
 */
remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
remove_action( 'wp_print_styles', 'print_emoji_styles' );
remove_action( 'admin_print_styles', 'print_emoji_styles' );
add_filter( 'emoji_svg_url', '__return_false' );
PHP

cat > "$WORK/mu/020-healthcheck.php" <<'PHP'
<?php
/**
 * Health probe observer (phase 70). The product endpoint
 * (IGBZ\Suite\Support\HealthEndpoint) answers /?igbz_health=1 with 200/503
 * semantics; this mu-plugin only matters when the SUITE ITSELF failed to boot —
 * then it still answers, honestly red (503), because a probe that goes silent
 * exactly when the plugin is broken tells the orchestrator nothing.
 */
add_action( 'init', function () {
	if ( ! isset( $_GET['igbz_health'] ) ) { return; }

	if ( class_exists( 'IGBZ\\Suite\\Support\\HealthEndpoint' ) ) {
		// The suite is up — its own endpoint owns the document and the status code.
		return;
	}

	global $wpdb, $wp_version;
	$db_ok = false;
	if ( isset( $wpdb ) ) {
		$checked = $wpdb->get_var( 'SELECT 1' );
		$db_ok   = ( '1' === (string) $checked );
	}

	status_header( $db_ok ? 200 : 503 );
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Cache-Control: no-cache, no-store, must-revalidate' );
	echo wp_json_encode(
		[
			'ok'          => false, // igbz itself did not boot — never green
			'degraded'    => true,
			'db'          => $db_ok,
			'wp'          => $wp_version,
			'php'         => PHP_VERSION,
			'igbz_loaded' => false,
		],
		JSON_UNESCAPED_UNICODE
	);
	exit;
}, 1 );
PHP

cat > "$WORK/mu/030-default-theme.php" <<'PHP'
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
// Force fa_IR for the whole sandbox (admin + storefront). The WPLANG option is
// ignored by WordPress when no fa_IR core pack is installed, which is exactly
// the sandbox's case (wordpress.org is unreachable) — the `locale` filter is
// the reliable path and is what load_plugin_textdomain() keys off.
add_filter( 'locale', static fn () => 'fa_IR' );

// Force RTL. Without the fa_IR core pack, WP_Locale resolves text direction
// from _x('ltr','text direction') = 'ltr', so is_rtl() is false and the admin
// body never gets the .rtl class. Setting $GLOBALS['text_direction'] here
// (mu-plugins load before WP_Locale is instantiated) makes is_rtl() true,
// which mirrors the admin chrome and applies the panel's .rtl CSS.
$GLOBALS['text_direction'] = 'rtl';

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
	// v7: WPLANG/timezone moved OUT of the WooCommerce guard — on the very first
	// request WooCommerce is not loaded yet at this hook, so the guard used to
	// skip the fa_IR locale and then the version stamp made every later request
	// early-return. The admin panel stayed en_US (found 1406/06/10).
	$ver = 'v7';
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

	// Timezone + locale always apply (independent of WooCommerce readiness).
	update_option( 'timezone_string', 'Asia/Tehran' );
	if ( get_option( 'WPLANG' ) !== 'fa_IR' ) {
		update_option( 'WPLANG', 'fa_IR' );
	}

	// WooCommerce basics.
	if ( class_exists( 'WooCommerce' ) ) {
		update_option( 'woocommerce_default_country', 'IR' );
		// بدون این، فرم تسویه کشور را از geolocation می‌گیرد و «United States» پیش‌فرض
		// می‌شود (یافتهٔ تست ویژوال ۱۴۰۶/۰۶/۰۲) — پیش‌فرض مشتری = آدرس فروشگاه (IR)
		update_option( 'woocommerce_default_customer_address', 'base' );
		update_option( 'woocommerce_currency', 'IRT' ); // phase 68: the suite registers IRT (تومان) itself
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

PHP

# ---------------------------------------------------------------------------
# 6b. Sample-shop seeder (25 cosmetics products + 100 customers)
# ---------------------------------------------------------------------------
# Written to the mu-plugins dir so it only runs on the Playground harness,
# not in production. Guarded by an option so it is strictly one-shot.
cat > "$WORK/mu/031-fix-customer-address.php" <<'PHP'
<?php
/**
 * Harness only. Ensure checkout defaults to the store base country (IR), not
 * geolocated US (finding of the 1406/06/02 visual test). Also flushes stale
 * WC sessions once, because the customer session caches the old geolocated
 * country even after the option changes. Idempotent via guard option.
 */
add_action( 'init', function () {
	if ( get_option( 'woocommerce_default_customer_address' ) !== 'base' ) {
		update_option( 'woocommerce_default_customer_address', 'base' );
	}
	if ( ! get_option( 'igbz_devenv_addrfix_v1' ) ) {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_sessions" ); // phpcs:ignore
		update_option( 'igbz_devenv_addrfix_v1', 1 );
	}
} );
PHP

cat > "$WORK/mu/100-seed-sample-shop.php" <<'PHP'
<?php
/**
 * Harness only. Seeds the dev environment with a sample cosmetics store:
 * 25 simple products across realistic categories, plus 100 customer accounts
 * with Persian names and a default password. Safe to re-run: the presence of
 * the igbz_seeded_v1 option skips everything on subsequent boots.
 *
 * Runs late on wp_loaded (after 030-default-theme has created WC pages),
 * and wraps each logical block in try/catch so a
 * single bad record never fatal-flags the whole bootstrap.
 */
add_action( 'wp_loaded', function () {
	if ( get_option( 'igbz_seeded_v1' ) ) { return; }
	if ( ! class_exists( 'WooCommerce' ) ) { return; }
	if ( ! get_role( 'customer' ) ) { return; } // WooCommerce not ready yet.

	// Elementor Kit guard removed 1406/05/31 — Elementor no longer bundled.

	$summary = [ 'products' => 0, 'customers' => 0, 'orders' => 0, 'errors' => 0 ];
	$now = current_time( 'mysql', true );

	try {
	// ----- 1. Product categories -----
	$cat_defs = [
		'مراقبت پوست'      => 'پاک‌کننده، مرطوب‌کننده و ضدآفتاب',
		'مراقبت مو'        => 'شامپو، نرم‌کننده و ماسک مو',
		'آرایش صورت'       => 'کرم‌پودر، پنکیک، کانسیلر، رژگونه',
		'آرایش چشم'        => 'سایه، خط‌چشم، ریمل، ابرو',
		'آرایش لب'         => 'رژ لب، بالم لب، خط لب',
		'عطر و ادکلن'      => 'عطرهای زنانه و مردانه',
		'بهداشت بدن'       => 'شاور ژل، لوسیون، دئودورانت',
		'ابزار آرایش'      => 'براش، پد پاک‌کننده، کیف',
	];
	$cat_ids = [];
	foreach ( $cat_defs as $name => $desc ) {
		$existing = term_exists( $name, 'product_cat' );
		if ( $existing ) { $cat_ids[ $name ] = (int) $existing['term_id']; continue; }
		$r = wp_insert_term( $name, 'product_cat', [ 'description' => $desc ] );
		if ( ! is_wp_error( $r ) ) { $cat_ids[ $name ] = (int) $r['term_id']; }
	}

	// ----- 2. Placeholder PNG (solid gradient, generated with PHP image primitives if available) -----
	$placeholder_url = '';
	$placeholder_id  = 0;
	if ( function_exists( 'imagecreatetruecolor' ) ) {
		$upload = wp_upload_dir();
		if ( empty( $upload['error'] ) ) {
			$file = trailingslashit( $upload['path'] ) . 'igbz-sample-placeholder.png';
			if ( ! file_exists( $file ) ) {
				$w = 800; $h = 800;
				$im = @imagecreatetruecolor( $w, $h );
				if ( $im ) {
					for ( $y = 0; $y < $h; $y++ ) {
						$r = 236 - (int)( $y * 0.08 );
						$g = 72  + (int)( $y * 0.05 );
						$b = 153 - (int)( $y * 0.02 );
						$c = imagecolorallocate( $im, max(0,min(255,$r)), max(0,min(255,$g)), max(0,min(255,$b)) );
						imageline( $im, 0, $y, $w, $y, $c );
					}
					// Centered product-mark.
					$white = imagecolorallocate( $im, 255, 255, 255 );
					$bx = (int)( $w*0.18 ); $by = (int)( $h*0.18 );
					imagefilledrectangle( $im, $bx, $by, $w-$bx, $h-$by, imagecolorallocatealpha( $im, 255, 255, 255, 60 ) );
					imagerectangle( $im, $bx, $by, $w-$bx, $h-$by, $white );
					imagestring( $im, 5, (int)( $w*0.32 ), (int)( $h*0.48 ), 'IGBZ SAMPLE', $white );
					imagepng( $im, $file );
					imagedestroy( $im );
				}
			}
			if ( file_exists( $file ) ) {
				$ft = wp_check_filetype( basename( $file ), null );
				$attach = [
					'guid'           => $upload['url'] . '/' . basename( $file ),
					'post_mime_type' => $ft['type'],
					'post_title'     => 'تصویر نمونه محصول',
					'post_content'   => '',
					'post_status'    => 'inherit',
				];
				$aid = wp_insert_attachment( $attach, $file );
				if ( $aid && ! is_wp_error( $aid ) ) {
					require_once ABSPATH . 'wp-admin/includes/image.php';
					wp_update_attachment_metadata( $aid, wp_generate_attachment_metadata( $aid, $file ) );
					$placeholder_id  = $aid;
					$placeholder_url = wp_get_attachment_url( $aid );
				}
			}
		}
	}

	// ----- 3. 25 simple products -----
	$products = [
		// name, category, regular price (IRR), sale price|null, short desc
		[ 'کرم مرطوب‌کننده صورت ۱۰۰میل',          'مراقبت پوست',  3200000,  2800000, 'آبرسان قوی برای پوست خشک و حساس' ],
		[ 'سرم ویتامین سی روشن‌کننده ۳۰میل',      'مراقبت پوست',  4500000,  null,    'روشن‌کننده و ضد لک با ویتامین سی پایدار' ],
		[ 'ضدآفتاب فاقد چربی SPF50 ۵۰میل',         'مراقبت پوست',  1950000,  1750000, 'مناسب پوست چرب و مستعد جوش' ],
		[ 'پاک‌کننده میسلار واتر ۴۰۰میل',          'مراقبت پوست',  1250000,  null,    'پاک‌کننده آرایش چشم و صورت بدون نیاز به آبکشی' ],
		[ 'کرم دور چشم ضدچروک ۱۵میل',              'مراقبت پوست',  2700000,  null,    'کاهش تیرگی و پف زیر چشم' ],
		[ 'شامپو ترمیم‌کننده مو آسیب‌دیده ۴۰۰میل', 'مراقبت مو',    1800000,  1550000, 'با کراتین و آرگان برای موهای وزدار' ],
		[ 'نرم‌کننده مو ابریشمی ۳۰۰میل',          'مراقبت مو',    1450000,  null,    'نرم‌کننده بدون سولفات برای مو رنگ‌شده' ],
		[ 'ماسک مو روغن آرگان ۲۵۰میل',            'مراقبت مو',    2100000,  1890000, 'تغذیه عمیق و درخشندگی فوری' ],
		[ 'کرم‌پودر مات با پوشش متوسط',            'آرایش صورت',  3100000,  null,    'ماندگاری ۲۴ساعته، مناسب انواع پوست' ],
		[ 'پنکیک فشرده پودری SPF15',               'آرایش صورت',  1750000,  1500000, 'فینیش طبیعی و کنترل چربی' ],
		[ 'کانسیلر مایع با پوشش بالا',             'آرایش صورت',  1400000,  null,    'پوشاننده تیرگی، لک و جای جوش' ],
		[ 'رژگونه مایع گل‌بهی',                    'آرایش صورت',  1250000,  null,    'بافت سبک و طبیعی، ماندگاری بالا' ],
		[ 'پالت سایه چشم ۱۲رنگ نود',              'آرایش چشم',    2950000,  2650000, 'رنگ‌های مات و براق مناسب آرایش روزانه' ],
		[ 'ریمل حجم‌دهنده و بلندکننده',           'آرایش چشم',     950000,  null,    'ضدریزش و ضدلک تا ۱۲ساعت' ],
		[ 'خط چشم مایع مشکی ضدآب',                 'آرایش چشم',     850000,  null,    'نوک نمدی ظریف برای خط چشم حرفه‌ای' ],
		[ 'مداد ابرو با برس دوسر',                 'آرایش چشم',     720000,  620000,  '۳ رنگ قهوه‌ای، بلوند و مشکی' ],
		[ 'رژ لب مات مخملی ۲۴ساعته',              'آرایش لب',      980000,  null,    'بافت نرم، بدون خشکی لب' ],
		[ 'بالم لب مغذی با عسل و وازلین',          'آرایش لب',      450000,  null,    'ترمیم لب‌های ترک‌خورده' ],
		[ 'خط لب ضدخش رنگ رز',                     'آرایش لب',      550000,  null,    'ماندگاری بالا و بافت کرمی' ],
		[ 'عطر زنانه گل رز ۱۰۰میل',                'عطر و ادکلن',  5800000,  5200000, 'رایحه گلی و شیرین با ماندگاری ۸ساعته' ],
		[ 'ادکلن مردانه چوبی-شرقی ۱۰۰میل',         'عطر و ادکلن',  6200000,  null,    'خنک و جذاب برای استفاده روزمره' ],
		[ 'شاور ژل کرمی شیر و عسل ۵۰۰میل',        'بهداشت بدن',   780000,  null,    'شست‌وشوی ملایم با حفظ رطوبت پوست' ],
		[ 'لوسیون بدن مغذی شی باتر ۴۰۰میل',       'بهداشت بدن',  1100000,  null,    'نرم‌کننده قوی برای پوست خشک' ],
		[ 'دئودورانت رول‌آن بانوان ۲۴ساعته',        'بهداشت بدن',   620000,  560000,  'فاقد الکل، ضدلک لباس' ],
		[ 'ست ۱۲قلمی براش آرایش حرفه‌ای',          'ابزار آرایش', 1900000,  1650000, 'براش‌های با موی مصنوعی نرم' ],
	];

	$n = 0;
	$customer_count = 0;
	$order_idx = 0;
	foreach ( $products as [ $name, $cat, $reg, $sale, $desc ] ) {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_slug( sanitize_title( $name ) . '-' . ( $n + 1 ) );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_description( '<p>' . esc_html( $desc ) . ' — محصول نمونه جهت تست و نمایش فروشگاه.</p>' );
		$product->set_short_description( esc_html( $desc ) );
		$product->set_regular_price( (string) $reg );
		if ( $sale ) { $product->set_sale_price( (string) $sale ); }
		$product->set_price( (string) ( $sale ?? $reg ) );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 50 + wp_rand( 0, 200 ) );
		$product->set_stock_status( 'instock' );
		$product->set_weight( (string) ( 0.1 + ( wp_rand( 5, 80 ) / 100 ) ) );
		$product->set_sku( 'SAMPLE-' . str_pad( (string)( $n + 1 ), 4, '0', STR_PAD_LEFT ) );
		$product->set_sold_individually( false );
		if ( $placeholder_id ) { $product->set_image_id( $placeholder_id ); }
		$pid = $product->save();
		if ( $pid && ! is_wp_error( $pid ) && isset( $cat_ids[ $cat ] ) ) {
			wp_set_object_terms( $pid, [ (int) $cat_ids[ $cat ] ], 'product_cat' );
		}
		$n++;
	}

	// ----- 4. 100 customers -----
	$first_female = [ 'مریم','سارا','زهرا','فاطمه','نگار','پریسا','نیلوفر','الناز','آناهیتا','آتنا','مهسا','شقایق','نازنین','یاسمن','رؤیا','ترانه','بهار','ملیکا','پانته‌آ','هستی','محدثه','ریحانه','آیلین','سپیده','هلیا' ];
	$first_male   = [ 'علی','محمدرضا','امیر','حسین','سینا','آرش','مهدی','بهرام','نیما','کامران','رضا','پوریا','سهیل','بهزاد','شاهین','فرزاد','آرمین','کیان','محمد','پدرام','میلاد','سامان','یاشار','حامد','مبین' ];
	$last_names   = [ 'احمدی','محمدی','رضایی','کریمی','موسوی','حسینی','نوری','صادقی','فرجی','عباسی','مرادی','اکبری','رحیمی','نظری','سلطانی','شریفی','شیرازی','زاده','کاظمی','قاسمی','جعفری','پارسا','نیکو','بهروز','رستمی' ];

	$customer_count = 0;
	$password = wp_hash_password( 'Customer123!' );
	for ( $i = 1; $i <= 100; $i++ ) {
		$female = ( $i % 2 === 0 );
		$first  = $female ? $first_female[ $i % count( $first_female ) ] : $first_male[ $i % count( $first_male ) ];
		$last   = $last_names[ ( $i * 7 + 3 ) % count( $last_names ) ];
		$email  = sprintf( 'customer%03d@example.com', $i );
		if ( email_exists( $email ) ) { continue; }
		$user_id = wp_insert_user( [
			'user_login'   => $email,
			'user_email'   => $email,
			'user_pass'    => 'Customer123!',
			'first_name'   => $first,
			'last_name'    => $last,
			'display_name' => $first . ' ' . $last,
			'nickname'     => $first . ' ' . $last,
			'role'         => 'customer',
		] );
		if ( is_wp_error( $user_id ) ) { continue; }
		// Phone (0912/0935/0930/0901) — fake but valid format.
		$prefixes = [ '0912','0935','0930','0901','0990','0903' ];
		$phone = $prefixes[ $i % count( $prefixes ) ] . str_pad( (string) ( 1000000 + ( $i * 137 ) % 9000000 ), 7, '0', STR_PAD_LEFT );
		update_user_meta( $user_id, 'billing_phone',     $phone );
		update_user_meta( $user_id, 'billing_first_name', $first );
		update_user_meta( $user_id, 'billing_last_name',  $last );
		update_user_meta( $user_id, 'billing_country',   'IR' );
		update_user_meta( $user_id, 'billing_city',       $i % 3 === 0 ? 'کرج' : 'تهران' );
		update_user_meta( $user_id, 'billing_address_1', 'خیابان نمونه، پلاک ' . ( 10 + $i ) );
		update_user_meta( $user_id, 'billing_postcode',  sprintf( '%010d', 1000000000 + $i * 137 % 900000000 ) );
		$customer_count++;
	}

	// ----- 5. A handful of processing/completed orders for realism -----
	global $wpdb;
	$customer_ids = get_users( [ 'role' => 'customer', 'fields' => 'ID', 'number' => 12 ] );
	$product_ids  = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' ORDER BY ID ASC LIMIT 15" );
	$statuses     = [ 'processing','processing','completed','completed','on-hold','completed' ];
	$order_idx    = 0;
	foreach ( $customer_ids as $cid ) {
		if ( $order_idx >= 8 ) { break; }
		$order = wc_create_order( [ 'customer_id' => (int) $cid, 'status' => 'pending' ] );
		if ( is_wp_error( $order ) ) { continue; }
		$pick = array_slice( $product_ids, wp_rand( 0, max( 0, count( $product_ids ) - 3 ) ), wp_rand( 1, 3 ) );
		foreach ( $pick as $pid ) {
			$order->add_product( wc_get_product( (int) $pid ), wp_rand( 1, 3 ) );
		}
		$order->set_address( [
			'first_name' => get_user_meta( $cid, 'billing_first_name', true ),
			'last_name'  => get_user_meta( $cid, 'billing_last_name',  true ),
			'address_1'  => get_user_meta( $cid, 'billing_address_1',  true ),
			'city'       => get_user_meta( $cid, 'billing_city',       true ),
			'postcode'   => get_user_meta( $cid, 'billing_postcode',   true ),
			'country'    => 'IR',
			'phone'      => get_user_meta( $cid, 'billing_phone',       true ),
			'email'      => get_userdata( $cid )->user_email,
		], 'billing' );
		$order->calculate_totals();
		$order->update_status( $statuses[ $order_idx % count( $statuses ) ] );
		$order_idx++;
	}

	$summary = [
		'products'  => (int) ( $n ?? 0 ),
		'customers' => (int) ( $customer_count ?? 0 ),
		'orders'    => (int) ( $order_idx ?? 0 ),
		'at'        => $now,
	];
	} catch ( \Throwable $e ) {
		$summary['fatal'] = $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
	}
	// Always mark as seeded — the site is meant for demos, not for re-seeding
	// every request. If a partial run happened, bump 'v1' -> 'v2' to reseed.
	update_option( 'igbz_seeded_v1', $summary );
}, 60 );
PHP

# 040-rtl-demo — force RTL+Persian-ish fonts in the demo storefront so the
# sample shop renders right-to-left even before fa_IR language pack is installed.
cat > "$WORK/mu/040-rtl-demo.php" <<'PHP'
<?php
/**
 * Harness only. Force Persian/RTL appearance on the demo storefront regardless
 * of whether the fa_IR language pack is installed. No production impact.
 */
if ( is_admin() || wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
	return;
}

add_action( 'wp_head', function () {
	?>
<style id="igbz-rtl-demo">
html, body { direction: rtl !important; text-align: right !important; unicode-bidi: embed !important; font-family: Tahoma, Arial, sans-serif !important; }
.woocommerce .woocommerce-result-count { float: right !important; text-align: right !important; }
.woocommerce .woocommerce-ordering      { float: left !important; }
.woocommerce ul.products[class*=columns-] li.product,
.woocommerce-page ul.products[class*=columns-] li.product { float: right !important; margin: 0 0 2.992em 1.5em !important; text-align: right !important; }
.woocommerce ul.products[class*=columns-] li.product.last,
.woocommerce-page ul.products[class*=columns-] li.product.last { margin-left: 0 !important; }
.woocommerce ul.products li.product .price,
.woocommerce ul.products li.product .woocommerce-loop-product__title { text-align: right !important; }
.woocommerce ul.products li.product .button,
.woocommerce ul.products li.product .added_to_cart { float: right !important; margin: 0 0 0 1em !important; }
.woocommerce .onsale { right: 10px !important; left: auto !important; }
.site-header .site-branding, .site-header .site-navigation { float: right !important; }
.woocommerce-breadcrumb { direction: ltr !important; text-align: left !important; opacity: .8; }
/* رفع سرریز افقی صفحهٔ تسویه (یافتهٔ تست ویژوال ۱۴۰۶/۰۶/۰۲):
   ووکامرس بلاک، اینپوت مخفی address_2 را با left:-19481px پنهان می‌کند که در RTL
   عرض سند را ~۱۹هزار پیکسل بزرگ می‌کند. مخفی‌سازی درست: */
.wc-block-components-address-form__address_2-hidden-input {
	position: absolute !important;
	inset-inline-start: 0 !important;
	left: auto !important;
	clip: rect(0, 0, 0, 0) !important;
	clip-path: inset(50%) !important;
	width: 1px !important;
	height: 1px !important;
	overflow: hidden !important;
}
</style>
	<?php
}, 5 );
PHP

# 050-fa-demo — ترجمهٔ موقت رشته‌های پرکاربرد ووکامرس + نماد «تومان» + ارقام
# فارسی در فرانت‌اند، برای اینکه فروشگاه نمونه بدون نیاز به بستهٔ زبان fa_IR
# خوانا باشد. در تولید این فایل استفاده نمی‌شود و باید با بستهٔ رسمی ترجمه
# جایگزین گردد. نکته: تبدیل ارقام فقط روی خروجی نهایی HTML انجام می‌شود تا
# جایگزین‌سازهای sprintf (مثل %1$s) و اسکریپت/استایل آسیب نبینند.
cat > "$WORK/mu/050-fa-demo.php" <<'PHP'
<?php
/**
 * Harness only. Localize a handful of WooCommerce front-end strings for the
 * Persian demo storefront without pulling in fa_IR translation packs (which
 * are unreachable from this sandbox). Real production localization must use
 * proper .mo/.po files — this is demo-only.
 */
if ( is_admin() || wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
	return;
}

// Translate WooCommerce strings via gettext filter.
add_filter( 'gettext_woocommerce', function ( $translated, $text, $domain ) {
	static $map = null;
	if ( $map === null ) {
		$map = [
			'Add to cart'                  => 'افزودن به سبد',
			'Shop'                         => 'فروشگاه',
			'Cart'                         => 'سبد خرید',
			'Checkout'                     => 'تسویه حساب',
			'My account'                   => 'حساب کاربری من',
			'Sale!'                        => 'حراج!',
			'Showing %1$d&ndash;%2$d of %3$d results' => 'نمایش %1$d–%2$d از %3$d محصول',
			'Showing all %d results'       => 'نمایش همهٔ %d محصول',
			'Default sorting'              => 'مرتب‌سازی پیش‌فرض',
			'Sort by popularity'           => 'محبوب‌ترین‌ها',
			'Sort by latest'               => 'جدیدترین‌ها',
			'Sort by price: low to high'   => 'ارزان‌ترین‌ها',
			'Sort by price: high to low'   => 'گران‌ترین‌ها',
			'Product categories'           => 'دسته‌بندی‌ها',
			'Search products…'             => 'جست‌وجوی محصول…',
			'View cart'                    => 'مشاهده سبد',
			'Added to cart'                => 'به سبد اضافه شد',
		];
	}
	return isset( $map[ $text ] ) ? $map[ $text ] : $translated;
}, 10, 3 );

// Phase 68: Persian digits on the storefront, the تومان (IRT) currency and the
// checkout country default are now PRODUCT behaviour (FaStorefront/FaLocale in
// igbz-suite) — this harness no longer duplicates them. Only the WooCommerce
// core string demo map above remains (core's own fa_IR packs come from
// WordPress.org in production and are unreachable from this sandbox).

PHP

ok "9 mu-plugins written (activator + no-emoji-cdn + modules + health + default-theme + RTL demo + FA demo + sample seeder)"

# ---------------------------------------------------------------------------
# 6c. Stage bundled plugins/themes into .work/ so run.sh can mount them.
# ---------------------------------------------------------------------------
info "staging bundled plugins/themes into .work/"
mkdir -p "$WORK/plugins" "$WORK/themes"

if [ ${#BUNDLED_PLUGINS[@]} -gt 0 ]; then
	for slug in "${BUNDLED_PLUGINS[@]}"; do
		src="$DEVENV/$slug"
		dst="$WORK/plugins/$slug"
		if [ -d "$src" ]; then
			rm -rf "$dst"
			cp -a "$src" "$dst"
			ok "plugin $slug staged ($(find "$dst" -type f | wc -l) files)"
		else
			die "missing bundled plugin source: $src"
		fi
	done
else
	ok "no bundled plugins (Elementor removed 1406/05/31)"
fi

if [ ${#BUNDLED_THEMES[@]} -gt 0 ]; then
	for slug in "${BUNDLED_THEMES[@]}"; do
		src="$DEVENV/$slug"
		dst="$WORK/themes/$slug"
		if [ -d "$src" ]; then
			rm -rf "$dst"
			cp -a "$src" "$dst"
			ok "theme $slug staged ($(find "$dst" -type f | wc -l) files)"
		else
			die "missing bundled theme source: $src"
		fi
	done
else
	ok "no bundled themes (Hello Elementor removed 1406/05/31; using WP core default)"
fi
cat <<EOF

$(printf '\033[32mEnvironment ready.\033[0m')

  Start the site :  bash _devenv/run.sh
  Run the tests  :  bash _devenv/test.sh

EOF
