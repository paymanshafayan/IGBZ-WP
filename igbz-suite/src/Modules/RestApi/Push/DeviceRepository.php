<?php
namespace IGBZ\Suite\Modules\RestApi\Push;

use IGBZ\Suite\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * The `devices` table: one row per installed app instance.
 *
 * The nop version stored FCM tokens as a comma separated customer attribute, which meant a token
 * could never be attributed to a device, pruned or counted. Here `device_id` is unique, so
 * re-registering after a reinstall updates the row instead of piling up duplicates, and a token
 * rejected by FCM can be cleared precisely.
 */
final class DeviceRepository {

	public function __construct( private Db $db ) {}

	/**
	 * Insert or update a device. Returns the row id.
	 *
	 * @param array{device_id:string,user_id?:int,tenant_id?:int,platform?:string,fcm_token?:string,app_version?:string,locale?:string} $data
	 */
	public function register( array $data ): int {
		$device_id = substr( sanitize_text_field( (string) ( $data['device_id'] ?? '' ) ), 0, 128 );
		if ( '' === $device_id ) {
			return 0;
		}

		$now  = current_time( 'mysql', true );
		$row  = $this->find( $device_id );
		$save = [
			'tenant_id'    => (int) ( $data['tenant_id'] ?? 0 ),
			'user_id'      => (int) ( $data['user_id'] ?? 0 ),
			'platform'     => substr( sanitize_key( (string) ( $data['platform'] ?? '' ) ), 0, 16 ),
			'app_version'  => substr( sanitize_text_field( (string) ( $data['app_version'] ?? '' ) ), 0, 32 ),
			'locale'       => substr( sanitize_text_field( (string) ( $data['locale'] ?? '' ) ), 0, 10 ),
			'last_seen_at' => $now,
		];

		// An empty token means "just checking in": never wipe a working token by accident.
		$token = substr( sanitize_text_field( (string) ( $data['fcm_token'] ?? '' ) ), 0, 255 );
		if ( '' !== $token ) {
			$save['fcm_token'] = $token;
		}

		// Phase 12: the biometric signature contract. The app enrols a device key once (while
		// the biometric prompt is unlocked); it is stored encrypted and never echoed back.
		$key = (string) ( $data['signing_key'] ?? '' );
		if ( '' !== $key ) {
			$save['signing_key'] = \IGBZ\Suite\Support\Crypto::encrypt( substr( $key, 0, 191 ) );
		}

		if ( $row ) {
			$this->db->update( 'devices', $save, [ 'id' => (int) $row['id'] ] );

			return (int) $row['id'];
		}

		$save['device_id']  = $device_id;
		$save['fcm_token']  = $save['fcm_token'] ?? '';
		$save['created_at'] = $now;

		return $this->db->insert( 'devices', $save );
	}

	public function find( string $device_id ): ?array {
		return $this->db->row( 'SELECT * FROM ' . $this->db->table( 'devices' ) . ' WHERE device_id = %s', $device_id );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 *
	 * Phase 20: capped at the 100 most recently seen devices — a real person has a handful,
	 * and this is the only honest way to keep a forgotten-device leak from growing a user's
	 * result set without limit.
	 */
	public function for_user( int $user_id ): array {
		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'devices' ) . ' WHERE user_id = %d ORDER BY last_seen_at DESC LIMIT 100',
			$user_id
		);
	}

	/**
	 * Tokens to send to.
	 *
	 * @param array{tenant_id?:int,user_ids?:int[],device_ids?:int[],platform?:string,limit?:int} $filters
	 * @return array<int,array{id:int,fcm_token:string,user_id:int,platform:string}>
	 */
	public function targets( array $filters = [] ): array {
		$where  = [ "fcm_token <> ''" ];
		$params = [];

		if ( ! empty( $filters['tenant_id'] ) ) {
			$where[]  = 'tenant_id = %d';
			$params[] = (int) $filters['tenant_id'];
		}

		if ( ! empty( $filters['user_ids'] ) ) {
			$ids = array_values( array_unique( array_map( 'intval', (array) $filters['user_ids'] ) ) );
			if ( ! $ids ) {
				return [];
			}
			$where[] = 'user_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$params  = array_merge( $params, $ids );
		}

		if ( ! empty( $filters['device_ids'] ) ) {
			$rows = array_values( array_unique( array_map( 'intval', (array) $filters['device_ids'] ) ) );
			if ( ! $rows ) {
				return [];
			}
			$where[] = 'id IN (' . implode( ',', array_fill( 0, count( $rows ), '%d' ) ) . ')';
			$params  = array_merge( $params, $rows );
		}

		if ( ! empty( $filters['platform'] ) ) {
			$where[]  = 'platform = %s';
			$params[] = sanitize_key( (string) $filters['platform'] );
		}

		$limit = (int) ( $filters['limit'] ?? 2000 );
		$sql   = 'SELECT id, fcm_token, user_id, platform FROM ' . $this->db->table( 'devices' )
			. ' WHERE ' . implode( ' AND ', $where )
			. ' ORDER BY last_seen_at DESC LIMIT ' . max( 1, min( 10000, $limit ) );

		return $params ? $this->db->results( $sql, ...$params ) : $this->db->results( $sql );
	}

	/** Clear a token FCM told us is dead, keeping the device row for its last-seen history. */
	public function invalidate_token( int $device_id_row ): void {
		$this->db->update( 'devices', [ 'fcm_token' => '' ], [ 'id' => $device_id_row ] );
	}

	public function unregister( string $device_id, int $user_id = 0 ): bool {
		$where = [ 'device_id' => $device_id ];
		if ( $user_id > 0 ) {
			$where['user_id'] = $user_id;
		}

		return $this->db->delete( 'devices', $where ) > 0;
	}

	public function count( array $filters = [] ): int {
		$where  = [ '1=1' ];
		$params = [];

		if ( ! empty( $filters['with_token'] ) ) {
			$where[] = "fcm_token <> ''";
		}
		if ( ! empty( $filters['tenant_id'] ) ) {
			$where[]  = 'tenant_id = %d';
			$params[] = (int) $filters['tenant_id'];
		}

		$sql = 'SELECT COUNT(*) FROM ' . $this->db->table( 'devices' ) . ' WHERE ' . implode( ' AND ', $where );

		return (int) ( $params ? $this->db->scalar( $sql, ...$params ) : $this->db->scalar( $sql ) );
	}

	/**
	 * Devices that have not checked in for a long time are dead weight in every broadcast.
	 * Phase 20: trimmed in bounded batches so a stale backlog cannot lock the devices table.
	 */
	public function prune_stale( int $days = 180 ): int {
		return $this->db->delete_batches(
			'devices',
			'last_seen_at < %s',
			[ gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) ) ]
		);
	}
}
