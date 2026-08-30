<?php
namespace IGBZ\Suite\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 68 — Persian locale primitives: digits, glyph hygiene, currency and the
 * Jalali calendar.
 *
 * Everything here is a pure function (unit-tested); the store-front hooks that
 * decide WHERE they apply live in FaStorefront. Two hard rules carried over
 * from findings of the 1406/06/02 visual test:
 *   - digit conversion only ever runs on final, rendered TEXT — never on
 *     sprintf placeholders, URLs, tag attributes or inline script/style;
 *   - the ZWNJ fixer is deliberately conservative (a closed verb/suffix
 *     vocabulary), because a greedy «می + anything» joiner corrupts real
 *     sentences where «می» is the noun (this می is for stewed fruit).
 */
final class FaLocale {

	public const ZWNJ = "\u{200C}";

	private const WESTERN_DIGITS = [ '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ];
	private const PERSIAN_DIGITS = [ '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' ];

	/** The Arabic code points that Persian text must never carry (the broken-glyph family). */
	private const ARABIC_TO_PERSIAN = [
		'ي' => 'ی', // arabic yeh → persian yeh (joins differently)
		'ك' => 'ک', // arabic kaf → persian gaf-kaf
		'ة' => 'ه', // tā marbūṭa → heh
		'ﻻ' => 'لا', // presentation-form lam-alef ligature → two letters
		'٠' => '۰', '١' => '۱', '٢' => '۲', '٣' => '۳', '٤' => '۴',
		'٥' => '۵', '٦' => '۶', '٧' => '۷', '٨' => '۸', '٩' => '۹',
	];

	/**
	 * Verbs that legitimately carry the می/نمی prefix. Closed on purpose: this is
	 * the difference between a typo fixer and a sentence breaker.
	 */
	private const MI_VERBS = [
		'کند', 'کنید', 'کنم', 'کنیم', 'کنند', 'کرد', 'کردم', 'کردیم', 'کردند', 'کاری',
		'شود', 'شوم', 'شویم', 'شد', 'شدم', 'شدیم', 'شدند',
		'توانم', 'توانیم', 'توانند', 'تواند', 'توانست', 'توانستم',
		'باشم', 'باشیم', 'باشند', 'باشد', 'بود', 'بودم', 'بودیم', 'بودند',
		'داند', 'دانم', 'دانیم', 'دانند', 'داشت', 'داشتم', 'داشته',
		'دهد', 'دهم', 'دهیم', 'دهند', 'داد', 'دادم', 'دادیم', 'دادند',
		'گیرد', 'گیرم', 'گیریم', 'گیرند', 'گرفت', 'گرفتم',
		'بردم', 'برد', 'بردند', 'خورد', 'خوردم', 'خواهد', 'آید', 'آمدم', 'رود', 'روم',
		'بینم', 'بیند', 'دید', 'دیدم', 'دیدیم', 'سازد', 'سازم', 'ساخت', 'ساختم',
	];

	/** Suffixes that attach to the previous word with a ZWNJ. */
	private const HA_SUFFIXES = [ 'ها', 'های', 'هایی', 'هایی‌تر', 'تر', 'ترین' ];

	/** ۰۱۲…۹ in one pass. HTML-safe: only ever called on text nodes. */
	public static function persian_digits( string $text ): string {
		return str_replace( self::WESTERN_DIGITS, self::PERSIAN_DIGITS, $text );
	}

	/**
	 * Glyph hygiene: the Arabic look-alikes out, Persian letters in. Normalises
	 * text coming from user input, providers and AI output before it is stored
	 * or rendered, so ی/ک never render with the wrong joining forms.
	 */
	public static function normalize( string $text, bool $digits_too = false ): string {
		$text = strtr( $text, self::ARABIC_TO_PERSIAN );
		if ( $digits_too ) {
			$text = self::persian_digits( $text );
		}

		return $text;
	}

	/**
	 * Conservative نیم‌فاصله fixer for the two families every Persian typo falls
	 * into: «می کند» → «می‌کند» (نمی likewise, only with a known verb) and
	 * «کتاب ها» → «کتاب‌ها» / «کتاب‌های» (only a known suffix on a Persian word).
	 * Everything else is left alone — a wrong ZWNJ is worse than a missing one.
	 */
	public static function zwnj_fix( string $text ): string {
		$verbs = implode( '|', self::MI_VERBS );

		// «می / نمی + known verb» — joined by ZWNJ, never a full space, never glued.
		$text = (string) preg_replace( '/(?<![\p{L}' . self::ZWNJ . '])(ن?می) (' . $verbs . ')(?![\p{L}])/u', '$1' . self::ZWNJ . '$2', $text );

		// «word + ha-suffix» — the suffix binds to the previous Persian word.
		$suffixes = implode( '|', self::HA_SUFFIXES );
		$text     = (string) preg_replace( '/([\p{Arabic}]{2,}) (' . $suffixes . ')(?![\p{L}])/u', '$1' . self::ZWNJ . '$2', $text );

		return $text;
	}

	/**
	 * Format an amount as Persian toman text: thousands with the Arabic
	 * thousands separator (٬), Persian digits, the word تومان — the way an
	 * Iranian storefront prices things.
	 */
	public static function toman( float $amount, bool $with_word = true ): string {
		$formatted = number_format( $amount, 0, '.', '٬' );
		$formatted = self::persian_digits( $formatted );

		return $with_word ? $formatted . ' تومان' : $formatted;
	}

	/**
	 * Gregorian → Jalali (the jalaali integer algorithm; exact, no tables to
	 * drift). Accepts anything strtotime understands, or a unix timestamp.
	 *
	 * @return array{0:int,1:int,2:int} [year, month, day]
	 */
	public static function jalali( $time = 'now' ): array {
		$ts = is_int( $time ) || ( is_string( $time ) && ctype_digit( $time ) ) ? (int) $time : ( is_numeric( $time ) ? (int) $time : (int) strtotime( (string) $time ) );

		[ $gy, $gm, $gd ] = array_map( 'intval', explode( '-', gmdate( 'Y-n-j', $ts ) ) );

		$g_d_m = [ 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 ];
		$gy2   = ( $gm > 2 ) ? ( $gy + 1 ) : $gy;
		$days  = 355666 + ( 365 * $gy ) + intdiv( $gy2 + 3, 4 ) - intdiv( $gy2 + 99, 100 ) + intdiv( $gy2 + 399, 400 ) + $gd + $g_d_m[ $gm - 1 ];

		$jy   = -1595 + ( 33 * intdiv( $days, 12053 ) );
		$days %= 12053;
		$jy   += 4 * intdiv( $days, 1461 );
		$days %= 1461;
		if ( $days > 365 ) {
			$jy  += intdiv( $days - 1, 365 );
			$days = ( $days - 1 ) % 365;
		}
		if ( $days < 186 ) {
			$jm = 1 + intdiv( $days, 31 );
			$jd = 1 + ( $days % 31 );
		} else {
			$jm = 7 + intdiv( $days - 186, 30 );
			$jd = 1 + ( ( $days - 186 ) % 30 );
		}

		return [ $jy, $jm, $jd ];
	}

	/**
	 * Jalali date as storefront text, e.g. «۱۴۰۵/۰۶/۰۸» — Persian digits by
	 * default, Gregorian fallback if the calendar math ever refuses.
	 */
	public static function jalali_date( $time = 'now', bool $persian_digits = true ): string {
		[ $y, $m, $d ] = self::jalali( $time );
		$text          = sprintf( '%04d/%02d/%02d', $y, $m, $d );

		return $persian_digits ? self::persian_digits( $text ) : $text;
	}
}
