<?php
/**
 * Phase 68 — the Persian pack: the compiled .mo catalog and the FaLocale
 * primitives (digits, glyph hygiene, نیم‌فاصله, toman, Jalali calendar), plus
 * the store-front wiring decisions (IRT currency, checkout country fallback).
 */

declare( strict_types = 1 );

use IGBZ\Suite\Support\FaLocale;
use IGBZ\Suite\Support\FaStorefront;

final class FaLocaleTest extends TestCase {

	public function run(): void {
		$this->the_compiled_catalog_is_valid_and_persian();
		$this->digits_convert();
		$this->arabic_glyphs_are_normalized();
		$this->zwnj_is_fixed_conservatively();
		$this->toman_is_formatted();
		$this->the_jalali_calendar_is_exact();
		$this->the_toman_currency_is_registered();
		$this->the_checkout_country_only_fills_the_empty_case();
	}

	// ------------------------------------------------------------ the catalog

	/** Minimal little-endian MO reader — exactly what WordPress has to parse. */
	private function mo_read( string $path ): array {
		$raw = file_get_contents( $path );
		$this->assert_true( is_string( $raw ) && strlen( $raw ) > 28, 'the .mo exists' );
		$magic = unpack( 'V1', substr( (string) $raw, 0, 4 ) )[1];
		$this->assert_same(0x950412DE, $magic, 'the magic is the GNU MO magic' );

		$count  = unpack( 'V1', substr( (string) $raw, 8, 4 ) )[1];
		$offset = unpack( 'V1', substr( (string) $raw, 12, 4 ) )[1];
		$map    = [];
		for ( $i = 0; $i < $count; ++$i ) {
			$l1 = unpack( 'V1', substr( (string) $raw, $offset + $i * 8, 4 ) )[1];
			$o1 = unpack( 'V1', substr( (string) $raw, $offset + $i * 8 + 4, 4 ) )[1];
			$l2 = unpack( 'V1', substr( (string) $raw, $offset + $count * 8 + $i * 8, 4 ) )[1];
			$o2 = unpack( 'V1', substr( (string) $raw, $offset + $count * 8 + $i * 8 + 4, 4 ) )[1];
			$map[ substr( (string) $raw, $o1, $l1 ) ] = substr( (string) $raw, $o2, $l2 );
		}

		return $map;
	}

	private function the_compiled_catalog_is_valid_and_persian(): void {
		$path = __DIR__ . '/../languages/igbz-suite-fa_IR.mo';
		$mo   = $this->mo_read( $path );

		$this->assert_contains( 'Language: fa_IR', $mo[''] ?? '', 'the catalog declares fa_IR (the header WordPress matches the locale against)' );
		$this->assert_contains( 'X-Domain: igbz-suite', $mo[''] ?? '', 'the catalog is for the plugin domain' );

		// The mobile-app surface: what the app actually shows.
		$this->assert_same('احراز هویت لازم است.', $mo['Authentication is required.'] ?? null, 'the auth error is Persian' );
		$this->assert_same('توکن دسترسی منقضی شده است؛ از توکن نوسازی استفاده کنید.', $mo['The access token has expired. Use the refresh token.'] ?? null, 'the expiry error is Persian' );
		$this->assert_same('درخواست‌ها بیش از حد زیاد است؛ کمی آرام‌تر.', $mo['Too many requests. Please slow down.'] ?? null, 'rate limiting is Persian' );
		$this->assert_same('ارسال کد', $mo['Send code'] ?? null, 'the OTP script string is Persian' );

		// Placeholders must survive translation byte-for-byte.
		$toman = $mo['The minimum top-up is %s.'] ?? '';
		$this->assert_contains( '%s', $toman, 'sprintf placeholders survive' );

		$translated = 0;
		foreach ( $mo as $id => $str ) {
			if ( '' !== $id && '' !== $str ) { ++$translated; }
		}
		$this->assert_true( $translated >= 300, 'the pack covers the whole mobile + storefront surface' );
	}

	// -------------------------------------------------------------- FaLocale

	private function digits_convert(): void {
		$this->assert_same( '۱۴۰۵/۰۶/۰۸', FaLocale::persian_digits( '1405/06/08' ), 'a date converts' );
		$this->assert_same( '۰۱۲۳۴۵۶۷۸۹', FaLocale::persian_digits( '0123456789' ), 'every digit converts' );
		$this->assert_same( 'abc', FaLocale::persian_digits( 'abc' ), 'letters pass through' );
	}

	private function arabic_glyphs_are_normalized(): void {
		$this->assert_same('علی کودا', FaLocale::normalize( 'علي كودا' ), 'arabic yeh/kaf become persian' );
		$this->assert_same( 'مدرسه', FaLocale::normalize( 'مدرسة' ), 'tā marbūṭa normalizes to heh' );
		$this->assert_same( '۴۵', FaLocale::normalize( '٤٥' ), 'arabic-indic digits are an arabic glyph and normalize unconditionally' );
		$this->assert_same( '۴۵', FaLocale::normalize( '45', true ), 'western digits convert on demand' );
		$this->assert_same( '45', FaLocale::normalize( '45' ), 'and only on demand' );
	}

	private function zwnj_is_fixed_conservatively(): void {
		$zwnj = FaLocale::ZWNJ;

		$this->assert_same('می' . $zwnj . 'کند', FaLocale::zwnj_fix( 'می کند' ), 'می + known verb joins' );
		$this->assert_same('نمی' . $zwnj . 'شود', FaLocale::zwnj_fix( 'نمی شود' ), 'نمی joins too' );
		$this->assert_same('کتاب' . $zwnj . 'ها', FaLocale::zwnj_fix( 'کتاب ها' ), 'the ها suffix joins' );
		$this->assert_same('کتاب' . $zwnj . 'های', FaLocale::zwnj_fix( 'کتاب های' ), 'as does های' );

		// The cases a greedy fixer destroys:
		$this->assert_same('این می میوه است', FaLocale::zwnj_fix( 'این می میوه است' ), '«می» the noun is never joined' );
		$this->assert_same( 'می' . $zwnj . 'رود؟ نه، میخانه است', FaLocale::zwnj_fix( 'می رود؟ نه، میخانه است' ), 'joined only with a known verb (رود); میخانه untouched' );
		$this->assert_same('دیتابیس users جدول', FaLocale::zwnj_fix( 'دیتابیس users جدول' ), 'latin words are not touched' );
	}

	private function toman_is_formatted(): void {
		$this->assert_same( '۱٬۲۵۰٬۰۰۰ تومان', FaLocale::toman( 1250000 ), 'grouped, persian digits, with the word' );
		$this->assert_same( '۹۹۹', FaLocale::toman( 999.4, false ), 'no rounding up, no word' );
		$this->assert_same( '۰ تومان', FaLocale::toman( 0 ), 'zero is a price too' );
	}

	private function the_jalali_calendar_is_exact(): void {
		// Anchors: Nowruz boundaries across a leap year and a normal year.
		$this->assert_same([ 1403, 1, 1 ], FaLocale::jalali( strtotime( '2024-03-20 12:00:00 UTC' ) ), 'Nowruz 1403' );
		$this->assert_same([ 1404, 1, 1 ], FaLocale::jalali( strtotime( '2025-03-21 12:00:00 UTC' ) ), 'Nowruz 1404' );
		$this->assert_same([ 1405, 1, 1 ], FaLocale::jalali( strtotime( '2026-03-21 12:00:00 UTC' ) ), 'Nowruz 1405' );
		$this->assert_same([ 1405, 6, 8 ], FaLocale::jalali( strtotime( '2026-08-30 12:00:00 UTC' ) ), 'a mid-Shahrivar day' );
		$this->assert_same([ 1405, 12, 29 ], FaLocale::jalali( strtotime( '2027-03-20 12:00:00 UTC' ) ), 'the last day of a non-leap Persian year' );
		$this->assert_same('۱۴۰۵/۰۶/۰۸', FaLocale::jalali_date( strtotime( '2026-08-30 12:00:00 UTC' ) ), 'formatted with Persian digits' );
	}

	// ---------------------------------------------------------- storefront

	private function the_toman_currency_is_registered(): void {
		igbz_test_reset_settings();

		$currencies = FaStorefront::add_toman_currency( [ 'IRR' => 'Iranian Rial' ] );
		$this->assert_same( 'Iranian Toman', $currencies['IRT'] ?? '', 'IRT is registered (English here; the Persian name is the catalog\'s job)' );
		$mo = $this->mo_read( __DIR__ . '/../languages/igbz-suite-fa_IR.mo' );
		$this->assert_same( 'تومان ایرانی', $mo['Iranian Toman'] ?? null, 'the fa_IR catalog carries the Persian currency name' );

		$this->assert_same('تومان', FaStorefront::currency_symbol( '﷼', 'IRT' ), 'the IRT symbol is تومان' );
		$this->assert_same('ریال', FaStorefront::currency_symbol( '﷼', 'IRR' ), 'IRR keeps ریال — not a silent relabel' );
		$this->assert_same('$', FaStorefront::currency_symbol( '$', 'USD' ), 'other currencies pass through' );

		igbz()->settings()->set( 'i18n.toman_currency', false );
		$this->assert_same([ 'IRR' => 'Iranian Rial' ], FaStorefront::add_toman_currency( [ 'IRR' => 'Iranian Rial' ] ), 'the registration is switchable' );
	}

	private function the_checkout_country_only_fills_the_empty_case(): void {
		igbz_test_reset_settings();

		// No WooCommerce in the unit environment: an empty default stays empty
		// (the product never invents a country it cannot know).
		$this->assert_same('', FaStorefront::checkout_country( '' ), 'without WooCommerce nothing is invented' );

		// A saved or geolocated value is never overridden.
		$this->assert_same('DE', FaStorefront::checkout_country( 'DE' ), 'an existing value passes through' );

		igbz()->settings()->set( 'i18n.checkout_base_country', false );
		$this->assert_same('US', FaStorefront::checkout_country( 'US' ), 'the fallback is switchable' );
	}
}
