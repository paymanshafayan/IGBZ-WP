<?php
/**
 * Phase 60 — the approved FSE contract for Pado's default theme output. The
 * shipped `igbz-suite/themes/igbz-store-theme` skeleton is the golden fixture:
 * it must pass the strict PHP-free gate as-is, and every tampering — a smuggled
 * PHP or JS file, a remote font address, a missing template or part, an
 * unapproved parent, a wrong schema version — must be refused in the backend.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\Pado\Services\ThemeContract;
use IGBZ\Suite\Modules\Pado\Services\ThemeValidator;

if ( ! function_exists( 'get_temp_dir' ) ) {
	function get_temp_dir(): string {
		return rtrim( sys_get_temp_dir(), '/' ) . '/';
	}
}

final class ThemeContractTest extends TestCase {

	public function run(): void {
		$this->the_golden_skeleton_passes_the_strict_gate();
		$this->a_smuggled_php_file_is_refused();
		$this->a_smuggled_js_file_is_refused();
		$this->a_remote_font_address_is_refused();
		$this->php_code_inside_a_template_is_refused();
		$this->a_missing_template_is_refused();
		$this->a_missing_part_is_refused();
		$this->an_unapproved_parent_is_refused();
		$this->a_wrong_schema_version_is_refused();
		$this->missing_rtl_is_refused_not_warned();
		$this->the_base_gate_still_accepts_a_classic_theme();
		$this->the_service_rejects_a_tampered_child_at_ingest();
	}

	// ------------------------------------------------------------ scenarios

	private function the_golden_skeleton_passes_the_strict_gate(): void {
		$v = ( new ThemeContract() )->validate_php_free( ThemeContract::golden_dir() );
		$this->assert_true( $v['ok'], 'the shipped skeleton is the golden fixture — it must pass its own contract' , 'the invariant holds' );
		$this->assert_same( [], $v['errors'], 'no errors on the golden fixture' );
		$this->assert_same( 'twentytwentyfive', (string) $v['meta']['parent'], 'the parent is the approved core block theme' );
		$this->assert_false( (bool) $v['meta']['has_php'], 'the golden artefact carries no PHP at all' );
	}

	private function a_smuggled_php_file_is_refused(): void {
		$dir = $this->copy_golden();
		file_put_contents( $dir . '/functions.php', "<?php add_action( 'init', function(){} );" );
		$v = ( new ThemeContract() )->validate_php_free( $dir );
		$this->assert_false( $v['ok'], 'a type-1 artefact never carries PHP — not even functions.php' , 'the invariant holds' );
		$this->assert_true( $this->mentions( $v, 'functions.php' ), 'the refusal names the file' );
	}

	private function a_smuggled_js_file_is_refused(): void {
		$dir = $this->copy_golden();
		mkdir( $dir . '/assets', 0777, true );
		file_put_contents( $dir . '/assets/tracker.js', 'fetch("https://collector.example");' );
		$v = ( new ThemeContract() )->validate_php_free( $dir );
		$this->assert_false( $v['ok'], 'a type-1 artefact never carries executable JavaScript' , 'the invariant holds' );
		$this->assert_true( $this->mentions( $v, 'tracker.js' ), 'the refusal names the file' );
	}

	private function a_remote_font_address_is_refused(): void {
		$dir = $this->copy_golden();
		file_put_contents( $dir . '/extra.css', "@import url('https://cdn.example/font.css');" );
		$v = ( new ThemeContract() )->validate_php_free( $dir );
		$this->assert_false( $v['ok'], 'the contract is CDN-free — no network addresses anywhere' , 'the invariant holds' );
		$this->assert_true( $this->mentions( $v, 'extra.css' ), 'the refusal names the file' );
	}

	private function php_code_inside_a_template_is_refused(): void {
		$dir = $this->copy_golden();
		file_put_contents( $dir . '/templates/index.html', "<!-- wp:paragraph --><p><?php echo 'x'; ?></p><!-- /wp:paragraph -->" );
		$v = ( new ThemeContract() )->validate_php_free( $dir );
		$this->assert_false( $v['ok'], 'PHP inside an HTML template is an injection — refused' , 'the invariant holds' );
	}

	private function a_missing_template_is_refused(): void {
		$dir = $this->copy_golden();
		unlink( $dir . '/templates/single-product.html' );
		$v = ( new ThemeContract() )->validate_php_free( $dir );
		$this->assert_false( $v['ok'], 'the WooCommerce templates are part of the acceptance contract' , 'the invariant holds' );
		$this->assert_true( $this->mentions( $v, 'single-product' ), 'the refusal names the missing template' );
	}

	private function a_missing_part_is_refused(): void {
		$dir = $this->copy_golden();
		unlink( $dir . '/parts/header.html' );
		$v = ( new ThemeContract() )->validate_php_free( $dir );
		$this->assert_false( $v['ok'], 'registered parts are part of the acceptance contract' , 'the invariant holds' );
		$this->assert_true( $this->mentions( $v, 'header' ), 'the refusal names the missing part' );
	}

	private function an_unapproved_parent_is_refused(): void {
		$dir = $this->copy_golden();
		$this->rewrite_header( $dir, 'Template: storefront-by-a-stranger' );
		$v = ( new ThemeContract() )->validate_php_free( $dir );
		$this->assert_false( $v['ok'], 'only approved block parents may be ridden' , 'the invariant holds' );
		$this->assert_true( $this->mentions( $v, 'لیست مصوب' ), 'the refusal says the parent is not on the approved list' );
	}

	private function a_wrong_schema_version_is_refused(): void {
		$dir = $this->copy_golden();
		$json = json_decode( (string) file_get_contents( $dir . '/theme.json' ), true );
		$json['version'] = 1;
		file_put_contents( $dir . '/theme.json', wp_json_encode( $json ) );
		$v = ( new ThemeContract() )->validate_php_free( $dir );
		$this->assert_false( $v['ok'], 'an old schema version does not satisfy the contract' , 'the invariant holds' );
		$this->assert_true( $this->mentions( $v, 'theme.json' ), 'the refusal names theme.json' );
	}

	private function missing_rtl_is_refused_not_warned(): void {
		$dir = $this->copy_golden();
		unlink( $dir . '/rtl.css' );
		$v = ( new ThemeContract() )->validate_php_free( $dir );
		$this->assert_false( $v['ok'], 'RTL is part of acceptance for the type-1 output, not a warning' , 'the invariant holds' );
		$this->assert_true( $this->mentions( $v, 'rtl.css' ), 'the refusal names rtl.css' );
	}

	private function the_base_gate_still_accepts_a_classic_theme(): void {
		$dir = $this->tmp_dir( 'classic' );
		file_put_contents( $dir . '/style.css', "/*\nTheme Name: Classic Control\n*/" );
		file_put_contents( $dir . '/index.php', "<?php // silent" );
		$v = ( new ThemeValidator() )->validate( $dir );
		$this->assert_true( $v['ok'], 'the base gate keeps accepting classic themes — BC' , 'the invariant holds' );

		$c = ( new ThemeContract() )->validate_php_free( $dir );
		$this->assert_false( $c['ok'], 'the strict contract is only for the type-1 output, and this one fails it honestly' , 'the invariant holds' );
	}

	private function the_service_rejects_a_tampered_child_at_ingest(): void {
		$dir = $this->copy_golden();
		file_put_contents( $dir . '/functions.php', '<?php // pretend helper' );
		$zip = $this->zip_of( $dir );

		// The ingest path with a faked upload whose tmp file really exists on disk.
		$service = new IGBZ\Suite\Modules\Pado\Services\ThemeService( new IGBZ\Suite\Support\Db() );
		$file = [ 'name' => 'tampered-child.zip', 'type' => 'application/zip', 'tmp_name' => $zip, 'error' => UPLOAD_ERR_OK, 'size' => (int) filesize( $zip ) ];
		$r = $service->ingest_zip( $file, 0 );
		$this->assert_false( $r['ok'], 'the ingest gate refuses a child of an approved parent that carries PHP' , 'the invariant holds' );
		$joined = implode( ' ', (array) ( $r['validation']['errors'] ?? [] ) );
		$this->assert_true( false !== strpos( $joined, 'functions.php' ), 'the strict refusal reached the caller' );
		unlink( $zip );
	}

	// -------------------------------------------------------------- helpers

	/** @return array<string,mixed> */
	private function tmp_dir( string $tag ): string {
		$dir = rtrim( sys_get_temp_dir(), '/' ) . '/igbz-p60-' . $tag . '-' . wp_generate_uuid4();
		mkdir( $dir, 0777, true ); // native: the bootstrap wp_mkdir_p is a no-op stub
		return $dir;
	}

	private function copy_golden(): string {
		$to = $this->tmp_dir( 'golden' );
		$from = ThemeContract::golden_dir();
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $from, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $it as $item ) {
			$dest = $to . '/' . $it->getSubPathName();
			if ( $item->isDir() ) { mkdir( $dest, 0777, true ); continue; }
			@mkdir( dirname( $dest ), 0777, true );
			copy( $item->getPathname(), $dest );
		}
		return $to;
	}

	private function rewrite_header( string $dir, string $line ): void {
		$path = $dir . '/style.css';
		$css = (string) file_get_contents( $path );
		$css = preg_replace( '/^\s*Template\s*:\s*[A-Za-z0-9\-_]+/im', $line, $css, 1 ) ?? $css;
		file_put_contents( $path, $css );
	}

	private function zip_of( string $dir ): string {
		$zip_path = $this->tmp_dir( 'zip' ) . '-src.zip';
		$zip = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $item ) {
			$zip->addFile( $item->getPathname(), $it->getSubPathName() );
		}
		$zip->close();
		return $zip_path;
	}

	/** @param array<string,mixed> $verdict */
	private function mentions( array $verdict, string $needle ): bool {
		foreach ( (array) ( $verdict['errors'] ?? [] ) as $error ) {
			if ( false !== strpos( (string) $error, $needle ) ) { return true; }
		}
		return false;
	}
}
