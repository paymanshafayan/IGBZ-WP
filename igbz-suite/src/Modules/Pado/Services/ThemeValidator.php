<?php
namespace IGBZ\Suite\Modules\Pado\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Backend validation gate for theme .zip artefacts produced (or uploaded) by Pado.
 *
 * This is a defence-in-depth gate. Even though the model is instructed to emit a
 * FSE child theme with a strict output contract, we never trust the bytes —
 * every zip is scanned before being accepted for preview, and any violation
 * rejects the upload with a machine-readable verdict.
 *
 * Rules enforced (all in the backend, never in JS — قاعده دائمی پروژه):
 *  1. Extension whitelist (php, css, json, html, txt, svg, jpg, jpeg, png, gif, webp, woff, woff2, ttf).
 *  2. Hard blacklist of executable PHP patterns: eval(, base64_decode(, assert(, system(,
 *     exec(, shell_exec(, passthru(, popen(, proc_open(, pcntl_, <script language=php,
 *     ` backtick operator, curl_init(/fopen(/file_get_contents( to external URLs,
 *     <? inside non-php files.
 *  3. Maximum uncompressed size (10 MB default) and maximum file count (2048).
 *  4. Required skeleton files: style.css (with valid Theme Name header) and
 *     either theme.json OR index.php.
 *  5. RTL stylesheet presence (rtl.css OR a comment in style.css noting RTL).
 *  6. Forbidden network calls in PHP files (wp_remote_get/fopen to external hosts).
 *  7. theme.json (when present) must be valid JSON and parse without errors.
 *
 * IMPORTANT: This gate never executes uploaded PHP — it only inspects bytes.
 */
final class ThemeValidator {

	public const DEFAULT_MAX_BYTES = 10 * 1024 * 1024; // 10 MB
	public const DEFAULT_MAX_FILES = 2048;

	/** @var string[] */
	private const ALLOWED_EXTENSIONS = [
		'php', 'css', 'json', 'html', 'htm', 'txt', 'md',
		'svg', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'ico',
		'woff', 'woff2', 'ttf', 'otf', 'eot',
		'pot', 'po', 'mo',
	];

	/** @var string[] patterns that are NEVER allowed in any file inside the zip */
	private const FORBIDDEN_PATTERNS = [
		'eval(',
		'base64_decode(',
		'base64_encode(',
		'gzinflate(',
		'str_rot13(',
		'assert(',
		'create_function(',
		'preg_replace\s*\(.*/e',
		'system(',
		'exec(',
		'passthru(',
		'proc_open(',
		'popen(',
		'pcntl_',
		'posix_kill',
		'shell_exec(',
		'<script\s+language\s*=\s*[\'"]php',
		'`[^`]+`\s*;?\s*$',        // backtick operator — crude; supplemented below
		'include\s*\(?\s*\$',       // variable include
		'require\s*\(?\s*\$',
		'curl_init\s*\(',
		'wp_remote_get\s*\(\s*[\'"]https?://',  // remote fetch in theme code
		'wp_remote_post\s*\(\s*[\'"]https?://',
		'file_get_contents\s*\(\s*[\'"]https?://',
		'fopen\s*\(\s*[\'"]https?://',
		'fsockopen\s*\(',
		'stream_socket_client\s*\(',
	];

	private int $max_bytes;
	private int $max_files;

	/** @var string[] */
	private array $errors = [];
	/** @var string[] */
	private array $warnings = [];

	public function __construct( int $max_bytes = self::DEFAULT_MAX_BYTES, int $max_files = self::DEFAULT_MAX_FILES ) {
		$this->max_bytes = $max_bytes;
		$this->max_files = $max_files;
	}

	/**
	 * Validate an uploaded/extracted theme directory on disk. Returns a verdict
	 * array suitable for JSON/logging. Callers decide preview/accept/reject.
	 *
	 * @param string $dir Absolute path to the extracted theme root.
	 * @return array{ok:bool,errors:string[],warnings:string[],meta:array{files:int,bytes:int,has_theme_json:bool,has_rtl:bool,has_php:bool,has_style_css:bool}}
	 */
	public function validate( string $dir ): array {
		$this->errors   = [];
		$this->warnings = [];

		if ( ! is_dir( $dir ) ) {
			$this->errors[] = 'مسیر قالب یافت نشد.';
			return $this->verdict( 0, 0, false, false, false, false );
		}

		$files_count    = 0;
		$total_bytes    = 0;
		$has_style_css  = false;
		$has_theme_json = false;
		$has_index_php  = false;
		$has_rtl        = false;
		$has_php        = false;
		$found_theme_name = false;

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			++$files_count;
			if ( $files_count > $this->max_files ) {
				$this->errors[] = sprintf( 'تعداد فایل‌ها از سقف مجاز (%d) بیشتر است.', $this->max_files );
				break;
			}
			$path    = $file->getPathname();
			$rel     = $this->unprefix( $path, $dir );
			$size    = $file->getSize();
			$total_bytes += (int) $size;
			if ( $total_bytes > $this->max_bytes ) {
				$this->errors[] = sprintf( 'حجم کل قالب از سقف مجاز (%s) بیشتر است.', size_format( $this->max_bytes ) );
				break;
			}

			$ext = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, self::ALLOWED_EXTENSIONS, true ) ) {
				$this->errors[] = sprintf( 'پسوند غیرمجاز: %s', esc_html( $rel ) );
				continue;
			}

			// Block path traversal
			if ( false !== strpos( $rel, '..' ) || 0 === strpos( $rel, '/' ) ) {
				$this->errors[] = sprintf( 'مسیر نامعتبر در آرشیو: %s', esc_html( $rel ) );
				continue;
			}

			$basename = strtolower( basename( $rel ) );
			if ( 'style.css' === $basename ) {
				$has_style_css = true;
				$content = (string) @file_get_contents( $path );
				if ( preg_match( '/Theme\s*Name\s*:/i', $content ) ) {
					$found_theme_name = true;
				}
				// Accept explicit rtl.css presence OR a note in style.css
				if ( preg_match( '/rtl/i', $content ) ) {
					$has_rtl = true;
				}
				$this->scan_text( $content, $rel, [ 'css' ] );
			} elseif ( 'theme.json' === $basename ) {
				$has_theme_json = true;
				$content = (string) @file_get_contents( $path );
				json_decode( $content );
				if ( JSON_ERROR_NONE !== json_last_error() ) {
					$this->errors[] = sprintf( 'فایل theme.json نامعتبر است: %s', json_last_error_msg() );
				}
				$this->scan_text( $content, $rel, [ 'json' ] );
			} elseif ( 'rtl.css' === $basename ) {
				$has_rtl = true;
				$this->scan_text( (string) @file_get_contents( $path ), $rel, [ 'css' ] );
			} elseif ( 'index.php' === $basename ) {
				$has_index_php = true;
			}

			if ( 'php' === $ext ) {
				$has_php = true;
				$content = (string) @file_get_contents( $path );
				// Silence on non-svg executables that sneak in
				if ( false !== strpos( $content, '<?' ) ) {
					$this->scan_text( $content, $rel, [ 'php' ] );
				}
				// Hard block of PHP files outside theme root? We allow them but they must be silent/side-effect-free.
			}
		}

		if ( ! $has_style_css ) {
			$this->errors[] = 'فایل style.css یافت نشد (تم وردپرس به آن نیاز دارد).';
		} elseif ( ! $found_theme_name ) {
			$this->errors[] = 'هدر Theme Name در style.css یافت نشد.';
		}
		if ( ! $has_theme_json && ! $has_index_php ) {
			$this->errors[] = 'قالب باید حداقل یکی از theme.json یا index.php را داشته باشد.';
		}
		if ( ! $has_rtl ) {
			$this->warnings[] = 'فایل rtl.css یا نشانه‌ای از راست‌چین در style.css یافت نشد — قالب به‌صورت LTR به‌نظر خواهد رسید.';
		}
		if ( $has_php && $has_theme_json ) {
			// FSE child themes can ship a functions.php — we just flag it for review
			$this->warnings[] = 'قالب شامل فایل PHP است (اغلب functions.php). این فایل‌ها قبل از انتشار زنده توسط تیم بازبینی می‌شوند.';
		}

		return $this->verdict( $files_count, $total_bytes, $has_theme_json, $has_rtl, $has_php, $has_style_css );
	}

	/**
	 * @param string[] $kinds 'php' | 'css' | 'json'
	 */
	private function scan_text( string $content, string $rel, array $kinds ): void {
		// Normalize line endings for robust matching
		$haystack = str_replace( [ "\r\n", "\r" ], "\n", $content );
		foreach ( self::FORBIDDEN_PATTERNS as $pat ) {
			// Skip patterns that don't apply to CSS/JSON
			if ( ! in_array( 'php', $kinds, true ) && ! in_array( $pat, [ 'eval(', 'base64_decode(' ], true ) ) {
				// In CSS/JSON we still catch obvious injections
				if ( false === strpos( $pat, 'script' ) && false === strpos( $pat, 'base64' ) ) {
					continue;
				}
			}
			if ( preg_match( '#' . $pat . '#im', $haystack ) ) {
				// Backtick false positives in CSS (template literals don't apply) — tighten
				if ( '`[^`]+`' === substr( $pat, 0, 8 ) && ! in_array( 'php', $kinds, true ) ) {
					continue;
				}
				$this->errors[] = sprintf( 'الگوی غیرمجاز %s در فایل %s یافت شد.', str_replace( '\\s*', ' ', $pat ), esc_html( $rel ) );
				break;
			}
		}
		// Catch <? opening tags inside non-php files (a common injection trick)
		if ( ! in_array( 'php', $kinds, true ) && preg_match( '/<\?php|<\?=/i', $haystack ) ) {
			$this->errors[] = sprintf( 'کد PHP در فایل غیر-PHP یافت شد: %s', esc_html( $rel ) );
		}
	}

	/**
	 * @return array{ok:bool,errors:string[],warnings:string[],meta:array{files:int,bytes:int,has_theme_json:bool,has_rtl:bool,has_php:bool,has_style_css:bool}}
	 */
	private function verdict( int $files, int $bytes, bool $has_theme_json, bool $has_rtl, bool $has_php, bool $has_style_css ): array {
		return [
			'ok'       => empty( $this->errors ),
			'errors'   => $this->errors,
			'warnings' => $this->warnings,
			'meta'     => [
				'files'          => $files,
				'bytes'          => $bytes,
				'has_theme_json' => $has_theme_json,
				'has_rtl'        => $has_rtl,
				'has_php'        => $has_php,
				'has_style_css'  => $has_style_css,
			],
		];
	}

	private function unprefix( string $path, string $prefix ): string {
		$prefix = rtrim( $prefix, '/' ) . '/';
		if ( 0 === strpos( $path, $prefix ) ) {
			return substr( $path, strlen( $prefix ) );
		}
		return $path;
	}
}
