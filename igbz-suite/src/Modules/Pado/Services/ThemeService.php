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
	 * Persist a theme record in igbz_themes. Does NOT move/install files — caller
	 * writes the zip to storage_dir()/<slug>.zip first, then calls this.
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
		$this->db->insert( 'themes', $insert );
		return (int) $this->db->insert_id;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get( int $id ): ?array {
		$row = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'themes' ) . ' WHERE id = %d', $id );
		return $row ?: null;
	}

	/**
	 * Store and validate a zip without executing it. The archive is rejected before extraction if
	 * any member escapes the temporary directory; validation then scans the extracted bytes.
	 * @return array{ok:bool,id:int,validation:array<string,mixed>,error:string}
	 */
	public function ingest_zip( array $file, int $tenant_id, int $approval_request_id = 0 ): array {
		if ( empty( $file['tmp_name'] ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return [ 'ok' => false, 'id' => 0, 'validation' => [], 'error' => 'فایل ZIP دریافت نشد.' ];
		}
		if ( 'zip' !== strtolower( (string) pathinfo( (string) ( $file['name'] ?? '' ), PATHINFO_EXTENSION ) ) ) {
			return [ 'ok' => false, 'id' => 0, 'validation' => [], 'error' => 'فقط فایل ZIP پذیرفته می‌شود.' ];
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( (string) $file['tmp_name'], \ZipArchive::CHECKCONS ) ) {
			return [ 'ok' => false, 'id' => 0, 'validation' => [], 'error' => 'آرشیو ZIP معتبر نیست.' ];
		}
		$tmp = trailingslashit( get_temp_dir() ) . 'igbz-theme-' . wp_generate_uuid4();
		wp_mkdir_p( $tmp );
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = (string) ( $zip->getNameIndex( $i ) ?: '' );
			if ( '' === $name || false !== strpos( str_replace( '\\\\', '/', $name ), '..' ) || str_starts_with( $name, '/' ) ) {
				$zip->close();
				$this->remove_tree( $tmp );
				return [ 'ok' => false, 'id' => 0, 'validation' => [], 'error' => 'مسیر ناامن داخل ZIP پیدا شد.' ];
			}
		}
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
		if ( ! $row || ! is_readable( (string) $row['zip_path'] ) ) { return [ 'ok' => false, 'error' => 'فایل قالب یافت نشد.' ]; }
		$zip = new \ZipArchive();
		if ( true !== $zip->open( (string) $row['zip_path'], \ZipArchive::CHECKCONS ) ) { return [ 'ok' => false, 'error' => 'آرشیو قالب معتبر نیست.' ]; }
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
		if ( ! $row ) { return [ 'ok' => false, 'error' => 'قالب یافت نشد.' ]; }
		$installed = wp_get_themes();
		$slug = sanitize_title( (string) $row['slug'] );
		if ( ! isset( $installed[ $slug ] ) ) { $preview = $this->install_preview( $id ); if ( ! $preview['ok'] ) { return $preview; } }
		$previous = get_option( 'igbz_previous_theme_slug', get_stylesheet() );
		update_option( 'igbz_previous_theme_slug', $previous, false );
		switch_theme( $slug );
		$this->db->update( 'themes', [ 'status' => self::STATUS_LIVE, 'updated_at' => current_time( 'mysql', true ) ], [ 'id' => $id ] );
		return [ 'ok' => true, 'error' => '' ];
	}

	public function rollback(): array {
		$previous = sanitize_title( (string) get_option( 'igbz_previous_theme_slug', '' ) );
		if ( '' === $previous || ! isset( wp_get_themes()[ $previous ] ) ) { return [ 'ok' => false, 'error' => 'قالب قبلی برای بازگشت یافت نشد.' ]; }
		switch_theme( $previous );
		$this->db->query( 'UPDATE ' . $this->db->table( 'themes' ) . ' SET status = %s WHERE status = %s', self::STATUS_ARCHIVED, self::STATUS_LIVE );
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
