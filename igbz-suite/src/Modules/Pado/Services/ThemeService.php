<?php
namespace IGBZ\Suite\Modules\Pado\Services;

use IGBZ\Suite\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Persists theme artefacts generated/uploaded by Pado, and moves validated
 * zips into a safe place on disk before they are ever installed/previewed.
 */
final class ThemeService {

	public const STATUS_DRAFT    = 'draft';
	public const STATUS_PREVIEW  = 'preview';
	public const STATUS_LIVE     = 'live';
	public const STATUS_REJECTED = 'rejected';
	public const STATUS_ARCHIVED = 'archived';

	public const SOURCE_PADO = 'pado';
	public const SOURCE_UPLOAD   = 'upload';

	private Db $db;
	private string $upload_dir;

	public function __construct( Db $db ) {
		$this->db = $db;
		$wp_upload = wp_upload_dir();
		$this->upload_dir = trailingslashit( (string) ( $wp_upload['basedir'] ?? WP_CONTENT_DIR . '/uploads' ) ) . 'igbz/themes/';
		if ( ! wp_mkdir_p( $this->upload_dir ) ) {
			// Fall back to WP_CONTENT_DIR if the uploads folder is not writable yet
			$this->upload_dir = trailingslashit( WP_CONTENT_DIR ) . 'igbz-themes/';
			wp_mkdir_p( $this->upload_dir );
		}
	}

	public function storage_dir(): string {
		return $this->upload_dir;
	}

	/**
	 * Phase 15: zips live under a per-tenant folder so two stores uploading the same slug
	 * can never overwrite each other. Callers must write to storage_dir_for( tenant )/slug.zip.
	 */
	public function storage_dir_for( int $tenant_id ): string {
		$dir = $this->upload_dir . 't' . max( 0, $tenant_id ) . '/';
		wp_mkdir_p( $dir );
		return $dir;
	}

	/**
	 * Persist a theme record in igbz_themes. Does NOT move/install files — caller
	 * writes the zip to storage_dir_for( tenant_id )/<slug>.zip first, then calls this.
	 *
	 * @param array<string,mixed> $data
	 */
	public function record( array $data ): int {
		$now    = current_time( 'mysql', true );
		$insert = [
			'tenant_id'           => (int) ( $data['tenant_id'] ?? 0 ),
			'slug'                => sanitize_title( (string) ( $data['slug'] ?? 'theme-' . wp_generate_password( 8, false ) ) ),
			'name'                => substr( (string) ( $data['name'] ?? '' ), 0, 191 ),
			'source'              => in_array( $data['source'] ?? self::SOURCE_PADO, [ self::SOURCE_PADO, self::SOURCE_UPLOAD ], true ) ? $data['source'] : self::SOURCE_PADO,
			'zip_path'            => (string) ( $data['zip_path'] ?? '' ),
			'size_bytes'          => (int) ( $data['size_bytes'] ?? 0 ),
			'status'              => (string) ( $data['status'] ?? self::STATUS_DRAFT ),
			'validation'          => is_array( $data['validation'] ?? null ) ? wp_json_encode( $data['validation'], JSON_UNESCAPED_UNICODE ) : null,
			'preview_url'         => (string) ( $data['preview_url'] ?? '' ),
			'approval_request_id' => (int) ( $data['approval_request_id'] ?? 0 ),
			'generated_by'        => substr( (string) ( $data['generated_by'] ?? '' ), 0, 64 ),
			'prompt'              => (string) ( $data['prompt'] ?? '' ),
			'metadata'            => is_array( $data['metadata'] ?? null ) ? wp_json_encode( $data['metadata'], JSON_UNESCAPED_UNICODE ) : null,
			'created_at'          => $now,
			'updated_at'          => $now,
		];
		// Phase 60 live-smoke finding: Db has no insert_id property (it wraps wpdb),
		// so this used to read null and every real-stack ingest record reported
		// failure. Db::insert() already returns the inserted id.
		return $this->db->insert( 'themes', $insert );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get( int $id ): ?array {
		$row = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'themes' ) . ' WHERE id = %d', $id );
		return $row ?: null;
	}

	private function belongs_to_current_tenant( int $tenant_id ): bool {
		if ( current_user_can( 'manage_options' ) ) { return true; }
		$current = function_exists( 'igbz' ) ? (int) igbz()->tenancy()->id() : 0;
		return 0 === $current || $current === $tenant_id;
	}

	/** @return array<int,array<string,mixed>> */
	public function list( int $tenant_id = 0, int $limit = 50 ): array {
		$sql = 'SELECT * FROM ' . $this->db->table( 'themes' ) . ' WHERE tenant_id = %d ORDER BY id DESC LIMIT %d';
		return $this->db->results( $sql, $tenant_id, max( 1, min( 200, $limit ) ) );
	}

	/**
	 * Store and validate a zip without executing it. The archive is rejected before extraction if
	 * any member escapes the temporary directory; validation then scans the extracted bytes.
	 * @return array{ok:bool,id:int,validation:array<string,mixed>,error:string}
	 */
	public function ingest_zip( array $file, int $tenant_id, int $approval_request_id = 0 ): array {
		if ( ! $this->belongs_to_current_tenant( $tenant_id ) ) {
			return [ 'ok' => false, 'id' => 0, 'validation' => [], 'error' => 'دسترسی به این فروشگاه مجاز نیست.' ];
		}
		if ( empty( $file['tmp_name'] ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return [ 'ok' => false, 'id' => 0, 'validation' => [], 'error' => 'فایل ZIP دریافت نشد.' ];
		}
		if ( 'zip' !== strtolower( (string) pathinfo( (string) ( $file['name'] ?? '' ), PATHINFO_EXTENSION ) ) ) {
			return [ 'ok' => false, 'id' => 0, 'validation' => [], 'error' => 'فقط فایل ZIP پذیرفته می‌شود.' ];
		}
		if ( ! \IGBZ\Suite\Support\ArchiveGuard::looks_like_zip( (string) $file['tmp_name'] ) ) {
			return [ 'ok' => false, 'id' => 0, 'validation' => [], 'error' => 'امضای فایل ZIP معتبر نیست.' ];
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( (string) $file['tmp_name'], \ZipArchive::CHECKCONS ) ) {
			return [ 'ok' => false, 'id' => 0, 'validation' => [], 'error' => 'آرشیو ZIP معتبر نیست.' ];
		}
		// Phase 11: entry count, uncompressed size and name safety live in one gate.
		$guard = \IGBZ\Suite\Support\ArchiveGuard::check(
			$zip,
			ThemeValidator::DEFAULT_MAX_FILES,
			ThemeValidator::DEFAULT_MAX_BYTES
		);
		if ( ! $guard['ok'] ) {
			$zip->close();
			return [ 'ok' => false, 'id' => 0, 'validation' => [], 'error' => 'آرشیو ZIP از نگهبان عبور نکرد.' ];
		}
		$tmp = trailingslashit( get_temp_dir() ) . 'igbz-theme-' . wp_generate_uuid4();
		wp_mkdir_p( $tmp );
		$zip->extractTo( $tmp );
		$zip->close();
		$validation_dir = $tmp;
		$children = array_values( array_filter( scandir( $tmp ) ?: [], static fn ( string $name ): bool => '.' !== $name && '..' !== $name ) );
		if ( ! file_exists( $tmp . '/style.css' ) && 1 === count( $children ) && is_dir( $tmp . '/' . $children[0] ) ) {
			$validation_dir = $tmp . '/' . $children[0];
		}
		$validator = new ThemeValidator();
		$validation = $validator->validate( $validation_dir );
		if ( ! $validation['ok'] ) {
			$this->remove_tree( $tmp );
			return [ 'ok' => false, 'id' => 0, 'validation' => $validation, 'error' => implode( ' ', $validation['errors'] ) ];
		}

		/*
		 * Phase 60: an artefact that declares itself a child of an approved block
		 * parent is claiming the low-risk type-1 output — so it must pass the full
		 * PHP-free FSE contract (no PHP/JS, required templates/parts, no network
		 * addresses). Classic uploads without an approved parent header are judged
		 * by the base rules only, exactly as before.
		 */
		$style_header = (string) @file_get_contents( $validation_dir . '/style.css' );
		if ( preg_match( '/^\s*Template\s*:\s*([A-Za-z0-9\-_]+)/im', $style_header, $m )
			&& in_array( strtolower( (string) $m[1] ), ThemeContract::APPROVED_PARENTS, true ) ) {
			$strict = ( new ThemeContract( $validator ) )->validate_php_free( $validation_dir );
			if ( ! $strict['ok'] ) {
				$this->remove_tree( $tmp );
				return [ 'ok' => false, 'id' => 0, 'validation' => $strict, 'error' => implode( ' ', $strict['errors'] ) ];
			}
			$validation = $strict;
		}
		$slug = sanitize_title( pathinfo( (string) $file['name'], PATHINFO_FILENAME ) ) . '-' . gmdate( 'YmdHis' );
		$stored = $this->upload_dir . $slug . '.zip';
		if ( ! move_uploaded_file( (string) $file['tmp_name'], $stored ) && ! copy( (string) $file['tmp_name'], $stored ) ) {
			$this->remove_tree( $tmp );
			return [ 'ok' => false, 'id' => 0, 'validation' => $validation, 'error' => 'ذخیرهٔ فایل ZIP ناموفق بود.' ];
		}
		$this->remove_tree( $tmp );
		$id = $this->record( [
			'tenant_id' => $tenant_id, 'slug' => $slug, 'name' => sanitize_text_field( (string) $file['name'] ),
			'source' => self::SOURCE_UPLOAD, 'zip_path' => $stored, 'size_bytes' => filesize( $stored ) ?: 0,
			'status' => self::STATUS_PREVIEW, 'validation' => $validation, 'approval_request_id' => $approval_request_id,
			'generated_by' => 'admin-upload',
		] );
		return [ 'ok' => $id > 0, 'id' => $id, 'validation' => $validation, 'error' => $id > 0 ? '' : 'ثبت قالب ناموفق بود.' ];
	}

	private function remove_tree( string $dir ): void {
		if ( ! is_dir( $dir ) ) { return; }
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $item ) { $item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() ); }
		@rmdir( $dir );
	}

	public function install_preview( int $id ): array {
		$row = $this->get( $id );
		if ( $row && ! $this->belongs_to_current_tenant( (int) $row['tenant_id'] ) ) { return [ 'ok' => false, 'error' => 'دسترسی به این قالب مجاز نیست.' ]; }
		if ( ! $row || ! is_readable( (string) $row['zip_path'] ) ) { return [ 'ok' => false, 'error' => 'فایل قالب یافت نشد.' ]; }
		$zip = new \ZipArchive();
		if ( true !== $zip->open( (string) $row['zip_path'], \ZipArchive::CHECKCONS ) ) { return [ 'ok' => false, 'error' => 'آرشیو قالب معتبر نیست.' ]; }
		// Defense in depth: the zip was judged once at ingest; judge it again at extraction.
		$guard = \IGBZ\Suite\Support\ArchiveGuard::check( $zip );
		if ( ! $guard['ok'] ) {
			$zip->close();
			return [ 'ok' => false, 'error' => 'آرشیو قالب از نگهبان عبور نکرد.' ];
		}
		$tmp = trailingslashit( get_temp_dir() ) . 'igbz-preview-' . wp_generate_uuid4();
		wp_mkdir_p( $tmp );
		$zip->extractTo( $tmp ); $zip->close();
		$root = $tmp;
		$children = array_values( array_filter( scandir( $tmp ) ?: [], static fn ( string $n ): bool => '.' !== $n && '..' !== $n ) );
		if ( ! file_exists( $tmp . '/style.css' ) && 1 === count( $children ) && is_dir( $tmp . '/' . $children[0] ) ) { $root = $tmp . '/' . $children[0]; }
		$theme_root = trailingslashit( get_theme_root() ) . sanitize_title( (string) $row['slug'] );
		if ( is_dir( $theme_root ) ) { $this->remove_tree( $theme_root ); }
		wp_mkdir_p( $theme_root );
		$this->copy_tree( $root, $theme_root );
		$this->remove_tree( $tmp );
		$this->db->update( 'themes', [ 'status' => self::STATUS_PREVIEW, 'preview_url' => add_query_arg( 'igbz_theme_preview', rawurlencode( (string) $row['slug'] ), home_url( '/' ) ), 'updated_at' => current_time( 'mysql', true ) ], [ 'id' => $id ] );
		return [ 'ok' => true, 'error' => '' ];
	}

	public function activate_live( int $id ): array {
		$row = $this->get( $id );
		if ( $row && ! $this->belongs_to_current_tenant( (int) $row['tenant_id'] ) ) { return [ 'ok' => false, 'error' => 'دسترسی به این قالب مجاز نیست.' ]; }
		if ( ! $row ) { return [ 'ok' => false, 'error' => 'قالب یافت نشد.' ]; }
		$installed = wp_get_themes();
		$slug = sanitize_title( (string) $row['slug'] );
		if ( ! isset( $installed[ $slug ] ) ) { $preview = $this->install_preview( $id ); if ( ! $preview['ok'] ) { return $preview; } }
		$tenant_id = (int) ( $row['tenant_id'] ?? 0 );
		if ( $tenant_id <= 0 ) { return [ 'ok' => false, 'error' => 'قالب به هیچ فروشگاهی تعلق ندارد.' ]; }

		// Phase 18: activation is per-tenant state, never a global switch_theme() — one store
		// going live with its theme must not repaint every other store or the mother site.
		// The tenant's theme column is the source of truth; TenantThemeRouter applies it at
		// request time. The previous slug (tenant's own, or the site stylesheet as first
		// fallback) is remembered for rollback.
		$current = (string) ( $this->db->scalar( 'SELECT theme FROM ' . $this->db->table( 'tenants' ) . ' WHERE id = %d', $tenant_id ) ?? '' );
		$previous = '' !== $current ? $current : get_stylesheet();
		update_option( 'igbz_previous_theme_slug_' . $tenant_id, $previous, false );

		$this->db->update( 'tenants', [ 'theme' => $slug, 'updated_at' => current_time( 'mysql', true ) ], [ 'id' => $tenant_id ] );
		$this->db->query(
			'UPDATE ' . $this->db->table( 'themes' ) . ' SET status = %s WHERE status = %s AND tenant_id = %d AND id != %d',
			self::STATUS_ARCHIVED,
			self::STATUS_LIVE,
			$tenant_id,
			$id
		);
		$this->db->update( 'themes', [ 'status' => self::STATUS_LIVE, 'updated_at' => current_time( 'mysql', true ) ], [ 'id' => $id ] );
		return [ 'ok' => true, 'error' => '' ];
	}

	public function rollback( int $tenant_id = 0 ): array {
		$tenant_id = (int) $tenant_id;
		if ( $tenant_id <= 0 ) { return [ 'ok' => false, 'error' => 'بازگشت قالب بدون فروشگاه ممکن نیست.' ]; }

		$previous = sanitize_title( (string) get_option( 'igbz_previous_theme_slug_' . $tenant_id, '' ) );
		if ( '' === $previous || ! isset( wp_get_themes()[ $previous ] ) ) { return [ 'ok' => false, 'error' => 'قالب قبلی برای بازگشت یافت نشد.' ]; }

		// Phase 18: rollback restores this tenant's own theme and archives only this
		// tenant's live theme — the global UPDATE used to archive other stores' live themes.
		$this->db->update( 'tenants', [ 'theme' => $previous, 'updated_at' => current_time( 'mysql', true ) ], [ 'id' => $tenant_id ] );
		$this->db->query( 'UPDATE ' . $this->db->table( 'themes' ) . ' SET status = %s WHERE status = %s AND tenant_id = %d', self::STATUS_ARCHIVED, self::STATUS_LIVE, $tenant_id );
		delete_option( 'igbz_previous_theme_slug_' . $tenant_id );
		return [ 'ok' => true, 'error' => '' ];
	}

	private function copy_tree( string $from, string $to ): void {
		foreach ( array_values( array_filter( scandir( $from ) ?: [], static fn ( string $n ): bool => '.' !== $n && '..' !== $n ) ) as $name ) {
			$source = $from . '/' . $name; $target = $to . '/' . $name;
			if ( is_dir( $source ) ) { wp_mkdir_p( $target ); $this->copy_tree( $source, $target ); }
			elseif ( is_file( $source ) ) { copy( $source, $target ); }
		}
	}

	public function set_status( int $id, string $status ): bool {
		if ( ! in_array( $status, [ self::STATUS_DRAFT, self::STATUS_PREVIEW, self::STATUS_LIVE, self::STATUS_REJECTED, self::STATUS_ARCHIVED ], true ) ) {
			return false;
		}
		return (bool) $this->db->update(
			'themes',
			[ 'status' => $status, 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $id ]
		);
	}
}
