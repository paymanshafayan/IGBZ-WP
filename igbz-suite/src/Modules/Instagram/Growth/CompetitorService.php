<?php
namespace IGBZ\Suite\Modules\Instagram\Growth;

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Competitor tracking, first version (phase 55).
 *
 * DESIGN-INSTAGRAM-PADO-ZERNIO §11 is the contract: the manager introduces public
 * professional handles; growth history is built only from timed snapshots; public data
 * never mixes with the connected account's insights (separate tables, separate service);
 * and metrics nobody outside the competitor can know — reach, saves, sales — are simply
 * not fields here. Each snapshot carries an evidence link and a note, so every number
 * keeps its proof next to it.
 */
final class CompetitorService {

	public function __construct(
		private Db $db,
		private Logger $logger
	) {}

	// ----------------------------------------------------------- competitors

	/**
	 * @param array<string,mixed> $data
	 * @return array{ok:bool,id:int,error:string}
	 */
	public function save_competitor( int $tenant_id, array $data ): array {
		if ( $tenant_id <= 0 ) {
			return [ 'ok' => false, 'id' => 0, 'error' => 'no_tenant' ];
		}

		$handle   = strtolower( ltrim( trim( sanitize_text_field( (string) ( $data['handle'] ?? '' ) ) ), '@' ) );
		$platform = sanitize_key( (string) ( $data['platform'] ?? 'instagram' ) );
		if ( '' === $handle || strlen( $handle ) > 64 || '' === $platform ) {
			return [ 'ok' => false, 'id' => 0, 'error' => 'bad_handle' ];
		}

		$existing = $this->db->scalar(
			"SELECT id FROM {$this->db->table('ig_competitors')} WHERE tenant_id = %d AND platform = %s AND handle = %s",
			$tenant_id,
			$platform,
			$handle
		);

		$now = current_time( 'mysql', true );
		if ( null !== $existing ) {
			$this->db->update(
				'ig_competitors',
				[
					'display_name' => sanitize_text_field( (string) ( $data['display_name'] ?? '' ) ),
					'notes'        => '' === trim( (string) ( $data['notes'] ?? '' ) ) ? null : wp_kses_post( (string) $data['notes'] ),
					'is_active'    => isset( $data['is_active'] ) ? (int) (bool) $data['is_active'] : 1,
					'updated_at'   => $now,
				],
				[ 'id' => (int) $existing, 'tenant_id' => $tenant_id ]
			);
			return [ 'ok' => true, 'id' => (int) $existing, 'error' => '' ];
		}

		$id = $this->db->insert( 'ig_competitors', [
			'tenant_id'     => $tenant_id,
			'platform'      => $platform,
			'handle'        => $handle,
			'display_name'  => sanitize_text_field( (string) ( $data['display_name'] ?? '' ) ),
			'notes'         => '' === trim( (string) ( $data['notes'] ?? '' ) ) ? null : wp_kses_post( (string) $data['notes'] ),
			'is_active'     => isset( $data['is_active'] ) ? (int) (bool) $data['is_active'] : 1,
			'created_at'    => $now,
			'updated_at'    => $now,
		] );

		return $id > 0
			? [ 'ok' => true, 'id' => $id, 'error' => '' ]
			: [ 'ok' => false, 'id' => 0, 'error' => 'insert_failed' ];
	}

	/** @return array<int,array<string,mixed>> */
	public function list( int $tenant_id, bool $active_only = false ): array {
		$table = $this->db->table( 'ig_competitors' );
		$sql   = "SELECT * FROM {$table} WHERE tenant_id = %d";
		if ( $active_only ) {
			$sql .= ' AND is_active = 1';
		}
		return $this->db->results( $sql . ' ORDER BY handle ASC LIMIT 200', $tenant_id );
	}

	/** @return array<string,mixed>|null */
	public function get( int $tenant_id, int $competitor_id ): ?array {
		return $this->db->row(
			"SELECT * FROM {$this->db->table('ig_competitors')} WHERE tenant_id = %d AND id = %d",
			$tenant_id,
			$competitor_id
		);
	}

	/** @return array{ok:bool,error:string} */
	public function delete( int $tenant_id, int $competitor_id ): array {
		$row = $this->get( $tenant_id, $competitor_id );
		if ( null === $row ) {
			return [ 'ok' => false, 'error' => 'not_found' ];
		}

		// The snapshots are the evidence; removing the competitor removes them too.
		$this->db->query(
			"DELETE FROM {$this->db->table('ig_competitor_snapshots')} WHERE competitor_id = %d AND tenant_id = %d",
			$competitor_id,
			$tenant_id
		);
		$this->db->delete( 'ig_competitors', [ 'id' => $competitor_id, 'tenant_id' => $tenant_id ] );

		return [ 'ok' => true, 'error' => '' ];
	}

	// ------------------------------------------------------------ snapshots

	/**
	 * @param array<string,mixed> $data
	 * @return array{ok:bool,error:string}
	 */
	public function record_snapshot( int $tenant_id, int $competitor_id, array $data ): array {
		$competitor = $this->get( $tenant_id, $competitor_id );
		if ( null === $competitor ) {
			return [ 'ok' => false, 'error' => 'not_found' ];
		}

		$captured_for = trim( (string) ( $data['captured_for'] ?? '' ) );
		$captured_for = '' !== $captured_for ? gmdate( 'Y-m-d', strtotime( $captured_for ) ) : gmdate( 'Y-m-d' );
		if ( false === strtotime( $captured_for ) ) {
			return [ 'ok' => false, 'error' => 'bad_date' ];
		}

		$fields = [
			'followers'       => max( 0, (int) ( $data['followers'] ?? 0 ) ),
			'posts'           => max( 0, (int) ( $data['posts'] ?? 0 ) ),
			'engagement_rate' => max( 0.0, (float) ( $data['engagement_rate'] ?? 0 ) ),
			'evidence_url'    => esc_url_raw( (string) ( $data['evidence_url'] ?? '' ) ),
			'note'            => '' === trim( (string) ( $data['note'] ?? '' ) ) ? null : sanitize_textarea_field( (string) $data['note'] ),
		];

		// One snapshot per competitor per day; re-submitting the day corrects it.
		$existing = $this->db->scalar(
			"SELECT id FROM {$this->db->table('ig_competitor_snapshots')} WHERE competitor_id = %d AND captured_for = %s",
			$competitor_id,
			$captured_for
		);

		if ( null !== $existing ) {
			$this->db->update( 'ig_competitor_snapshots', $fields, [ 'id' => (int) $existing ] );
			return [ 'ok' => true, 'error' => '' ];
		}

		$id = $this->db->insert( 'ig_competitor_snapshots', $fields + [
			'tenant_id'     => $tenant_id,
			'competitor_id' => $competitor_id,
			'captured_for'  => $captured_for,
			'created_at'    => current_time( 'mysql', true ),
		] );

		return $id > 0
			? [ 'ok' => true, 'error' => '' ]
			: [ 'ok' => false, 'error' => 'insert_failed' ];
	}

	/** @return array<int,array<string,mixed>> */
	public function snapshots( int $tenant_id, int $competitor_id ): array {
		return $this->db->results(
			"SELECT captured_for,followers,posts,engagement_rate,evidence_url,note,created_at FROM {$this->db->table('ig_competitor_snapshots')} WHERE tenant_id = %d AND competitor_id = %d ORDER BY captured_for ASC LIMIT 1000",
			$tenant_id,
			$competitor_id
		);
	}
}
