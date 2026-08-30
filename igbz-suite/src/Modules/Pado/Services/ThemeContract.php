<?php
namespace IGBZ\Suite\Modules\Pado\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 60 — the approved FSE contract for Pado's default theme output
 * (ADR-0003, output type 1): a PHP-free block child theme on an approved core
 * parent. This class is the backend word of that contract — the shipped
 * `igbz-suite/themes/igbz-store-theme` skeleton is the golden fixture, and
 * `validate_php_free()` is the strict gate an artefact must pass before it may
 * ride the low-risk preview/live path.
 *
 * Enforcement is backend-only (قاعدهٔ دائمی پروژه: بررسی محدودیت‌ها همیشه در
 * بک‌اند) and byte-based: nothing here ever executes theme code.
 */
class ThemeContract {

	/** Block parents a Pado child may ride on (approved core FSE parents). */
	public const APPROVED_PARENTS = [ 'twentytwentyfive' ];

	/** theme.json `version` values the contract accepts. */
	public const SCHEMA_VERSIONS = [ 3 ];

	/** Templates every accepted artefact must ship (hierarchy + WooCommerce contract). */
	public const REQUIRED_TEMPLATES = [
		'index', 'page', 'single', '404',
		'archive-product', 'single-product',
	];

	/** Registered parts every accepted artefact must ship. */
	public const REQUIRED_PARTS = [ 'header', 'footer' ];

	/** Extensions that must not appear anywhere in a type-1 artefact. */
	public const FORBIDDEN_EXTENSIONS = [ 'php', 'js', 'mjs', 'jsx', 'ts', 'tsx', 'vue', 'phar', 'phtml' ];

	/**
	 * The shipped golden skeleton — the single source of truth for "accepted".
	 * Test-visible so the fixture and the validator can never drift apart.
	 */
	public static function golden_dir(): string {
		return dirname( __DIR__, 4 ) . '/themes/igbz-store-theme';
	}

	public function __construct( private ?ThemeValidator $validator = null ) {}

	/**
	 * Strict acceptance for the block-child output: everything the base validator
	 * enforces, plus the PHP/JS/network-free contract on top.
	 *
	 * @return array{ok:bool,errors:string[],warnings:string[],meta:array<string,mixed>}
	 */
	public function validate_php_free( string $dir ): array {
		$errors   = [];
		$warnings = [];

		$base = ( $this->validator ?? new ThemeValidator() )->validate( $dir );
		foreach ( (array) ( $base['errors'] ?? [] ) as $error ) {
			$errors[] = (string) $error;
		}
		foreach ( (array) ( $base['warnings'] ?? [] ) as $warning ) {
			$warnings[] = (string) $warning;
		}
		$meta               = is_array( $base['meta'] ?? null ) ? $base['meta'] : [];
		$meta['contract']   = 'php_free_block_child';
		$meta['parent']     = '';

		if ( ! is_dir( $dir ) ) {
			return [ 'ok' => false, 'errors' => $errors, 'warnings' => $warnings, 'meta' => $meta ];
		}

		$style = (string) @file_get_contents( $dir . '/style.css' );
		if ( '' === $style ) {
			$errors[] = 'قرارداد قالب فرزند بلوکی: style.css یافت نشد.';
			return [ 'ok' => false, 'errors' => $errors, 'warnings' => $warnings, 'meta' => $meta ];
		}

		// 1. The declared parent must be on the approved list.
		$template = '';
		if ( preg_match( '/^\s*Template\s*:\s*([A-Za-z0-9\-_]+)/im', $style, $m ) ) {
			$template = strtolower( (string) $m[1] );
		}
		$meta['parent'] = $template;
		if ( '' === $template ) {
			$errors[] = 'قرارداد قالب فرزند بلوکی: هدر Template (قالب مادر) الزامی است.';
		} elseif ( ! in_array( $template, self::APPROVED_PARENTS, true ) ) {
			$errors[] = sprintf( 'قالب مادر «%s» در لیست مصوب قرارداد نیست.', esc_html( $template ) );
		}

		// 2. theme.json must exist, parse, and carry an approved schema version.
		$theme_json = (string) @file_get_contents( $dir . '/theme.json' );
		$decoded    = '' !== $theme_json ? json_decode( $theme_json, true ) : null;
		if ( ! is_array( $decoded ) ) {
			$errors[] = 'قرارداد قالب فرزند بلوکی: theme.json معتبر الزامی است.';
		} elseif ( ! in_array( (int) ( $decoded['version'] ?? 0 ), self::SCHEMA_VERSIONS, true ) ) {
			$errors[] = sprintf( 'نسخهٔ theme.json (%s) در قرارداد مجاز نیست.', esc_html( (string) ( $decoded['version'] ?? '' ) ) );
		}

		// 3. The required block templates and registered parts.
		foreach ( self::REQUIRED_TEMPLATES as $slug ) {
			if ( ! is_file( $dir . '/templates/' . $slug . '.html' ) ) {
				$errors[] = sprintf( 'قالب بلوکی اجباری یافت نشد: templates/%s.html', esc_html( $slug ) );
			}
		}
		foreach ( self::REQUIRED_PARTS as $slug ) {
			if ( ! is_file( $dir . '/parts/' . $slug . '.html' ) ) {
				$errors[] = sprintf( 'بخش اجباری یافت نشد: parts/%s.html', esc_html( $slug ) );
			}
		}

		// 4. RTL is part of the acceptance contract, not a warning.
		if ( ! is_file( $dir . '/rtl.css' ) ) {
			$errors[] = 'قرارداد پذیرش: فایل rtl.css الزامی است.';
		}

		// 5. Walk the tree: no executable extensions, no network addresses.
		$remote = '/https?:\/\/(?!schemas\.wp\.org)/i';
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) { continue; }
			$rel = ltrim( str_replace( $dir, '', $file->getPathname() ), '/' );
			$ext = strtolower( (string) pathinfo( $rel, PATHINFO_EXTENSION ) );

			if ( in_array( $ext, self::FORBIDDEN_EXTENSIONS, true ) ) {
				$errors[] = sprintf( 'خروجی نوع ۱ هرگز کد اجرایی نمی‌برد؛ فایل ممنوع: %s', esc_html( $rel ) );
				continue;
			}

			if ( in_array( $ext, [ 'css', 'json', 'html', 'htm', 'txt', 'md', 'svg' ], true ) ) {
				$content = (string) @file_get_contents( $file->getPathname() );
				if ( preg_match( '/<\?php|<\?=/i', $content ) ) {
					$errors[] = sprintf( 'کد PHP در فایل غیر-PHP یافت شد: %s', esc_html( $rel ) );
				}
				// The only tolerated network address is the wp.org schema namespace,
				// which is an identifier, never fetched by the browser.
				$scanned = preg_replace( '/"\$schema"\s*:\s*"https:\/\/schemas\.wp\.org[^"]*"/i', '', $content ) ?? $content;
				if ( preg_match( $remote, $scanned ) ) {
					$errors[] = sprintf( 'نشانی شبکه‌ای در فایل — قرارداد فاقد CDN است: %s', esc_html( $rel ) );
				}
			}
		}

		return [ 'ok' => empty( $errors ), 'errors' => $errors, 'warnings' => $warnings, 'meta' => $meta ];
	}
}
