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
