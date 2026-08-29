<?php
namespace IGBZ\Suite\Modules\MultiTenant\Logistics;

use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;
use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Logistics provider sync — phase 45: status mapping, signed carrier
 * callbacks, retry (the sweep is the retry) and reconciliation.
 *
 * Provider vocabularies differ; the machine does not. Every raw status is
 * mapped through STATUS_MAP before touching a shipment, and a target status
 * is reached by walking the machine's legal rungs — a carrier that jumps
 * straight to delivered still passes through at_destination, because
 * skipping rungs is how phantom deliveries are born.
 */
final class ShippingSyncService {

	/**
	 * Default provider vocabulary → machine status. Unknown words map to ''
	 * (leave the shipment alone). Shops tune per-carrier quirks through
	 * logistics.status_map_<raw> settings.
	 */
	public const STATUS_MAP = [
		'delivered'      => LogisticsService::STATUS_DELIVERED,
		'distribute'     => LogisticsService::STATUS_DELIVERED,
		'in_transit'     => LogisticsService::STATUS_IN_TRANSIT,
		'transit'        => LogisticsService::STATUS_IN_TRANSIT,
		'shipping'       => LogisticsService::STATUS_IN_TRANSIT,
		'picked_up'      => LogisticsService::STATUS_IN_TRANSIT,
		'arrived'        => LogisticsService::STATUS_AT_DESTINATION,
		'at_destination' => LogisticsService::STATUS_AT_DESTINATION,
		'registered'     => LogisticsService::STATUS_REGISTERED,
		'accepted'       => LogisticsService::STATUS_REGISTERED,
		'failed'         => LogisticsService::STATUS_FAILED,
		'returned'       => LogisticsService::STATUS_FAILED,
		'cancelled'      => LogisticsService::STATUS_FAILED,
		'canceled'       => LogisticsService::STATUS_FAILED,
	];

	/** Map a raw provider status to a machine status ('' = unknown, leave alone). */
	public function map_status( string $raw ): string {
		$raw = strtolower( trim( $raw ) );
		if ( '' === $raw || 'unknown' === $raw ) {
			return '';
		}

		$override = $this->settings->string( 'logistics.status_map_' . $raw, '' );
		if ( '' !== $override ) {
			return $override;
		}

		return self::STATUS_MAP[ $raw ] ?? '';
	}

	/**
	 * Reconciliation sweep — the retry mechanism: every run asks the adapter
	 * for each trackable shipment's live status and advances the machine.
	 * Transient adapter errors are counted, not punished; the next sweep
	 * tries again.
	 *
	 * @return array{scanned:int,advanced:int,errors:int,unknown:int}
	 */
	public function sync_tracking( ShippingAdapterInterface $adapter, int $limit = 50 ): array {
		$out  = [ 'scanned' => 0, 'advanced' => 0, 'errors' => 0, 'unknown' => 0 ];
		$rows = $this->db->results(
			"SELECT * FROM " . $this->db->table( 'ig_shipments' ) . "\n\t\t\t WHERE tracking_code <> '' AND status IN ('registered','in_transit','at_destination') ORDER BY id ASC LIMIT %d",
			$limit
		);

		foreach ( $rows as $shipment ) {
			++$out['scanned'];

			$answer = null;
			try {
				$answer = $adapter->track( (string) $shipment['tracking_code'] );
			} catch ( \Throwable $e ) {
				$answer = [ 'status' => 'unknown', 'detail' => $e->getMessage() ];
			}

			$target = $this->map_status( (string) ( $answer['status'] ?? '' ) );
			if ( '' === $target ) {
				++$out['unknown'];
				$this->logger->info( 'logistics', 'Carrier status left unmapped', [ 'shipment_id' => (int) $shipment['id'], 'raw' => (string) ( $answer['status'] ?? '' ) ] );
				continue;
			}
			if ( 'unknown' === strtolower( trim( (string) ( $answer['status'] ?? '' ) ) ) ) {
				++$out['errors'];
				continue;
			}

			if ( $this->advance_to( $shipment, $target ) ) {
				++$out['advanced'];
			}
		}

		return $out;
	}

	/**
	 * Carrier webhook — HMAC-SHA256 over the raw body with
	 * logistics.callback_secret; an unverifiable callback is treated as never
	 * received. The verdict then walks the machine exactly like the sweep.
	 *
	 * @return array{ok:bool,error:string,advanced:bool}
	 */
	public function apply_carrier_callback( string $tracking_code, string $raw_status, string $raw_body, string $signature ): array {
		$secret = (string) $this->settings->get( 'logistics.callback_secret', '' );
		if ( '' === $secret || '' === $signature || ! hash_equals( Crypto::hmac( $raw_body, $secret ), strtolower( trim( $signature ) ) ) ) {
			return [ 'ok' => false, 'error' => 'bad_signature', 'advanced' => false ];
		}

		$shipment = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'ig_shipments' ) . ' WHERE tracking_code = %s LIMIT 1',
			$tracking_code
		);
		if ( null === $shipment ) {
			return [ 'ok' => false, 'error' => 'not_found', 'advanced' => false ];
		}

		$target = $this->map_status( $raw_status );
		if ( '' === $target ) {
			return [ 'ok' => false, 'error' => 'unmapped_status', 'advanced' => false ];
		}

		return [ 'ok' => true, 'error' => '', 'advanced' => $this->advance_to( $shipment, $target ) ];
	}

	/**
	 * Walk the machine from the shipment's current status to $target along
	 * legal rungs. Returns true when anything moved. Terminal or unreachable
	 * targets move nothing — the machine stays the authority.
	 *
	 * @param array<string,mixed> $shipment
	 */
	public function advance_to( array $shipment, string $target ): bool {
		$path = self::path( (string) $shipment['status'], $target );
		if ( [] === $path ) {
			return false;
		}

		$current = (string) $shipment['status'];
		$moved   = false;
		foreach ( $path as $next ) {
			if ( ! LogisticsService::can_transition( $current, $next ) ) {
				break; // Never skip a rung.
			}
			$current = $next;
			$moved   = true;
		}

		if ( ! $moved || $current === (string) $shipment['status'] ) {
			return false;
		}

		$this->db->update(
			'ig_shipments',
			[ 'status' => $current, 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => (int) $shipment['id'] ]
		);
		$this->logger->info( 'logistics', 'Shipment advanced', [ 'shipment_id' => (int) $shipment['id'], 'from' => (string) $shipment['status'], 'to' => $current ] );

		return true;
	}

	/**
	 * Shortest legal path between two statuses (BFS over TRANSITIONS). Empty
	 * array when the target is unreachable or already reached.
	 *
	 * @return array<int,string>
	 */
	public static function path( string $from, string $to ): array {
		if ( $from === $to ) {
			return [];
		}

		$queue = [ [ $from, [] ] ];
		$seen  = [ $from => true ];

		while ( [] !== $queue ) {
			[ $status, $trail ] = array_shift( $queue );
			foreach ( LogisticsService::TRANSITIONS[ $status ] ?? [] as $next ) {
				if ( isset( $seen[ $next ] ) ) {
					continue;
				}
				$step = array_merge( $trail, [ $next ] );
				if ( $next === $to ) {
					return $step;
				}
				$seen[ $next ] = true;
				$queue[]       = [ $next, $step ];
			}
		}

		return [];
	}

	public function __construct(
		private Db $db,
		private Settings $settings,
		private Logger $logger
	) {}
}
