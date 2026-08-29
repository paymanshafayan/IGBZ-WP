<?php
/**
 * Phase 61 — the signed-artefact release pipeline: a stored theme zip is signed
 * the moment it lands (HMAC-SHA256 over its SHA-256, Crypto module only), the
 * signature is re-verified at preview install and at live activation, a file
 * edited on disk after ingest is refused, and the structural comparison answers
 * "what changed in the layout" between two renders.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\Pado\Services\ThemeReleaseService;
use IGBZ\Suite\Modules\Pado\Services\ThemeService;
use IGBZ\Suite\Support\Db;

require_once __DIR__ . '/ThemeRoutingTest.php';

/** The release service with its network seam pointed at the test world. */
final class ThemeReleaseServiceSpy extends ThemeReleaseService {
	public static string $html_a = '';
	public static string $html_b = '';

	protected function fetch( string $url ): string {
		return str_contains( $url, 'example-a' ) ? self::$html_a : self::$html_b;
	}
}

final class ThemeReleaseTest extends TestCase {

	private ThemeDb $db;

	public function run(): void {
		$this->signing_stamps_and_verifies();
		$this->a_file_edited_after_signing_is_refused();
		$this->an_unsigned_artifact_is_refused();
		$this->a_missing_artifact_is_refused();
		$this->a_rotated_key_invalidates_old_signatures();
		$this->the_preview_install_refuses_a_tampered_artifact();
		$this->the_live_activation_refuses_a_tampered_artifact();
		$this->block_signatures_are_ordered_and_clean();
		$this->the_snapshot_diff_reports_what_changed();
	}

	// ------------------------------------------------------------ scenarios

	private function signing_stamps_and_verifies(): void {
		$row = $this->signed_row( 301 );
		$this->assert_same( 64, strlen( (string) $row['meta']['artifact']['sha256'] ), 'the SHA-256 of the file is stamped' , 'the invariant holds' );
		$this->assert_same( 64, strlen( (string) $row['meta']['artifact']['signature'] ), 'the HMAC signature is stamped' , 'the invariant holds' );
		$this->assert_true( ( new ThemeReleaseService( new Db(), igbz()->get( 'logger' ) ) )->verify( $row['row'] )['ok'], 'the fresh signature verifies' , 'the invariant holds' );
	}

	private function a_file_edited_after_signing_is_refused(): void {
		$s = new ThemeReleaseService( new Db(), igbz()->get( 'logger' ) );
		$row = $this->signed_row( 302 );
		file_put_contents( (string) $row['row']['zip_path'], 'tampered bytes' );
		$v = $s->verify( $row['row'] );
		$this->assert_false( $v['ok'], 'a zip edited on disk after ingest never verifies again' , 'the invariant holds' );
		$this->assert_same( 'signature_mismatch', $v['error'], 'the refusal is explicit' );
	}

	private function an_unsigned_artifact_is_refused(): void {
		$row = $this->unsigned_row( 303 );
		$v = ( new ThemeReleaseService( new Db(), igbz()->get( 'logger' ) ) )->verify( $row );
		$this->assert_false( $v['ok'], 'a legacy unsigned artefact never reaches preview or live' , 'the invariant holds' );
		$this->assert_same( 'unsigned_artifact', $v['error'], 'the refusal is explicit' );
	}

	private function a_missing_artifact_is_refused(): void {
		$row = $this->unsigned_row( 304 );
		$row['zip_path'] = '/nonexistent/ghost.zip';
		$v = ( new ThemeReleaseService( new Db(), igbz()->get( 'logger' ) ) )->verify( $row );
		$this->assert_false( $v['ok'], 'a missing file is honest about it' , 'the invariant holds' );
		$this->assert_same( 'artifact_missing', $v['error'], 'the refusal is explicit' );
	}

	private function a_rotated_key_invalidates_old_signatures(): void {
		$s = new ThemeReleaseService( new Db(), igbz()->get( 'logger' ) );
		$row = $this->signed_row( 305 );
		$old = (string) get_option( 'igbz_theme_signing_key', '' );
		update_option( 'igbz_theme_signing_key', str_repeat( 'a', 64 ), false ); // key rotation
		$v = $s->verify( $row['row'] );
		update_option( 'igbz_theme_signing_key', $old, false ); // put the site back
		$this->assert_false( $v['ok'], 'signatures are bound to the site key — rotation invalidates them' , 'the invariant holds' );
		$this->assert_same( 'signature_mismatch', $v['error'], 'the invariant holds' );
	}

	private function the_preview_install_refuses_a_tampered_artifact(): void {
		$row = $this->signed_row( 306 );
		file_put_contents( (string) $row['row']['zip_path'], 'tampered bytes' );
		$service = new ThemeService( new Db() );
		$r = $service->install_preview( 306 );
		$this->assert_false( $r['ok'], 'preview never installs a tampered artefact' , 'the invariant holds' );
		$this->assert_true( false !== strpos( (string) $r['error'], 'امضا' ), 'the refusal names the signature' );
	}

	private function the_live_activation_refuses_a_tampered_artifact(): void {
		$row = $this->signed_row( 307 );
		file_put_contents( (string) $row['row']['zip_path'], 'tampered bytes' );
		$service = new ThemeService( new Db() );
		$r = $service->activate_live( 307 );
		$this->assert_false( $r['ok'], 'activation re-earns trust at its own boundary' , 'the invariant holds' );
		$this->assert_true( false !== strpos( (string) $r['error'], 'امضا' ), 'the refusal names the signature' );
	}

	private function block_signatures_are_ordered_and_clean(): void {
		$s = new ThemeReleaseService( new Db(), igbz()->get( 'logger' ) );
		$html = '<script>evil()</script><style>x{}</style>'
			. '<!-- wp:template-part {"slug":"header"} /-->'
			. '<!-- wp:group --><main><h1>فروشگاه</h1><h2>جدیدترین‌ها</h2><h2>جدیدترین‌ها</h2></main><!-- /wp:group -->';
		$sig = $s->block_signature( $html );
		$this->assert_same( 'block:wp:template-part', $sig[0], 'block markers come first, in order' , 'the invariant holds' );
		$this->assert_same( 'block:wp:group', $sig[1], 'the invariant holds' );
		$this->assert_same( 'h1:فروشگاه', $sig[2], 'headings carry their Persian text' , 'the invariant holds' );
		$this->assert_same( 4, count( $sig ), 'duplicates collapse and scripts/styles never sign' );
	}

	private function the_snapshot_diff_reports_what_changed(): void {
		ThemeReleaseServiceSpy::$html_a = '<!-- wp:template-part {"slug":"header"} /--><!-- wp:group --><h1>فروشگاه</h1><!-- /wp:group -->';
		ThemeReleaseServiceSpy::$html_b = '<!-- wp:template-part {"slug":"header"} /--><!-- wp:query --><h1>کاتالوگ</h1><!-- /wp:query -->';
		$d = ( new ThemeReleaseServiceSpy( new Db(), igbz()->get( 'logger' ) ) )->snapshot_diff( 'https://example-a/live', 'https://example-b/preview' );
		$this->assert_true( $d['ok'], 'both renders produced signatures' , 'the invariant holds' );
		$this->assert_same( [ 'block:wp:query', 'h1:کاتالوگ' ], $d['added'], 'the new block and the new heading are reported as added' );
		$this->assert_same( [ 'block:wp:group', 'h1:فروشگاه' ], array_values( $d['removed'] ), 'what disappeared is reported as removed' );
		$this->assert_same( 1, $d['common'], 'the shared header counts as common' );
	}

	// -------------------------------------------------------------- helpers

	/** @return array{row:array<string,mixed>,meta:array<string,mixed>} */
	private function signed_row( int $id ): array {
		$this->fresh_db();
		$zip = $this->zip_for( $id );
		$this->db->tables['themes'][ $id ] = [ 'id' => $id, 'tenant_id' => 1, 'slug' => 'signed-' . $id, 'status' => 'preview', 'zip_path' => $zip ];
		$service = new ThemeReleaseService( new Db(), igbz()->get( 'logger' ) );
		$service->sign( $this->db->tables['themes'][ $id ] );
		return [
			'row'  => $this->db->tables['themes'][ $id ],
			'meta' => json_decode( (string) $this->db->tables['themes'][ $id ]['metadata'], true ),
		];
	}

	/** @return array<string,mixed> */
	private function unsigned_row( int $id ): array {
		$this->fresh_db();
		$this->db->tables['themes'][ $id ] = [ 'id' => $id, 'tenant_id' => 1, 'slug' => 'unsigned-' . $id, 'status' => 'preview', 'zip_path' => $this->zip_for( $id ) ];
		return $this->db->tables['themes'][ $id ];
	}

	private function zip_for( int $id ): string {
		$path = rtrim( sys_get_temp_dir(), '/' ) . '/igbz-p61-artifact-' . $id . '.zip';
		$zip = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'style.css', "/*\nTheme Name: Release {$id}\n*/" );
		$zip->close();
		return $path;
	}

	private function fresh_db(): void {
		$this->db = new ThemeDb();
		$this->db->tables['themes'] = [];
		$GLOBALS['wpdb'] = $this->db;
		$GLOBALS['igbz_test_options'] = [];
	}
}
