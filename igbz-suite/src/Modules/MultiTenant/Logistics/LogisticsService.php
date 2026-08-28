<?php
namespace IGBZ\Suite\Modules\MultiTenant\Logistics;

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;
use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Shipment lifecycle: route categorisation, delivery PIN, carrier hand-off.
 *
 * The nop original categorised routes with hard-coded if/else costs; here the
 * thresholds and costs are settings so every shop tunes its own contract with
 * its couriers. The delivery PIN is generated with random_int (cryptographic),
 * per the project's "no predictable security value" rule.
 */
final class LogisticsService {

	public const STATUS_DRAFT    = 'draft';
	public const STATUS_REGISTERED = 'registered';
	public const STATUS_IN_TRANSIT = 'in_transit';
	public const STATUS_DELIVERED  = 'delivered';
	public const STATUS_FAILED     = 'failed';

	public function __construct(
		private Db $db,
		private Settings $settings,
		private Logger $logger
	) {}

	/**
	 * @return array{route_type:string,carrier:string,cost_irt:float,delivery_pin_required:bool}
	 */
	public function categorize_route( float $weight_kg, string $city, bool $express = false ): array {
		$heavy_threshold = (float) $this->settings->float( 'logistics.weight_threshold_kg', 30 );
		$express_cities  = array_map( 'trim', explode( ',', $this->settings->string( 'logistics.express_cities', 'تهران' ) ) );

		if ( $weight_kg > $heavy_threshold ) {
			return [
				'route_type'           => 'heavy',
				'carrier'              => __( 'Freight / heavy courier', 'igbz-suite' ),
				'cost_irt'             => (float) $this->settings->float( 'logistics.heavy_cost_irt', 150000 ),
				'delivery_pin_required' => true,
			];
		}
		if ( $express || in_array( $city, $express_cities, true ) ) {
			return [
				'route_type'           => 'express',
				'carrier'              => __( 'Express courier (in-city)', 'igbz-suite' ),
				'cost_irt'             => (float) $this->settings->float( 'logistics.express_cost_irt', 65000 ),
				'delivery_pin_required' => true,
			];
		}
		return [
			'route_type'           => 'national',
			'carrier'              => __( 'National post', 'igbz-suite' ),
			'cost_irt'             => (float) $this->settings->float( 'logistics.national_cost_irt', 45000 ),
			'delivery_pin_required' => false,
		];
	}

	/** Cryptographic delivery PIN (random_int, not a predictable sequence). */
	public function generate_delivery_pin(): string {
		$digits = max( 3, min( 8, $this->settings->int( 'logistics.delivery_pin_digits', 4 ) ) );
		$min    = 10 ** ( $digits - 1 );
		$max    = ( 10 ** $digits ) - 1;

		return (string) random_int( $min, $max );
	}

	/** @param array<string,mixed> $data */
	public function create_shipment( array $data ): int {
		$route = $this->categorize_route(
			(float) ( $data['weight_kg'] ?? 0 ),
			(string) ( $data['city'] ?? '' ),
			(bool) ( $data['express'] ?? false )
		);

		return (int) $this->db->insert(
			'ig_shipments',
			[
				'tenant_id'         => (int) ( $data['tenant_id'] ?? igbz()->tenancy()->id() ),
				'order_id'          => (int) ( $data['order_id'] ?? 0 ),
				'carrier'           => (string) ( $data['carrier'] ?? $route['carrier'] ),
				'tracking_code'     => (string) ( $data['tracking_code'] ?? '' ),
				'delivery_pin'      => (string) ( $data['delivery_pin'] ?? $this->generate_delivery_pin() ),
				'status'            => self::STATUS_DRAFT,
				'route_type'        => $route['route_type'],
				'cost_irt'          => (float) ( $data['cost_irt'] ?? $route['cost_irt'] ),
				'is_cod'            => (int) ( $data['is_cod'] ?? 0 ),
				'recipient_name'    => (string) ( $data['recipient_name'] ?? '' ),
				'recipient_phone'   => (string) ( $data['recipient_phone'] ?? '' ),
				'recipient_address' => (string) ( $data['recipient_address'] ?? '' ),
				'meta'              => wp_json_encode( [ 'route' => $route ] ),
				'created_at'        => current_time( 'mysql', true ),
				'updated_at'        => current_time( 'mysql', true ),
			]
		);
	}

	/**
	 * Hand a draft shipment to the active carrier adapter.
	 *
	 * @return array{ok:bool,tracking_code:string,message:string}
	 */
	public function register_with_carrier( int $shipment_id, ShippingAdapterInterface $adapter ): array {
		$tenant   = igbz()->tenancy()->id();
		$shipment = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'ig_shipments' ) . ' WHERE id = %d AND tenant_id = %d', $shipment_id, $tenant );
		if ( ! $shipment ) {
			return [ 'ok' => false, 'tracking_code' => '', 'message' => __( 'Shipment not found.', 'igbz-suite' ) ];
		}

		$result = $adapter->register( $shipment );
		if ( ! $result['ok'] ) {
			$this->db->update(
				'ig_shipments',
				[ 'status' => self::STATUS_FAILED, 'meta' => wp_json_encode( [ 'error' => $result['message'] ] ), 'updated_at' => current_time( 'mysql', true ) ],
				[ 'id' => $shipment_id, 'tenant_id' => $tenant ]
			);
			return $result;
		}

		$this->db->update(
			'ig_shipments',
			[
				'status'        => self::STATUS_REGISTERED,
				'tracking_code' => $result['tracking_code'],
				'updated_at'    => current_time( 'mysql', true ),
			],
			[ 'id' => $shipment_id, 'tenant_id' => $tenant ]
		);
		$this->logger->info( 'logistics', 'Shipment registered', [ 'shipment_id' => $shipment_id, 'tracking' => $result['tracking_code'] ] );

		return $result;
	}

	/** Mark delivered once the carrier (or the courier's PIN confirm) says so. */
	public function mark_delivered( int $shipment_id, string $pin = '' ): bool {
		$tenant   = igbz()->tenancy()->id();
		$shipment = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'ig_shipments' ) . ' WHERE id = %d AND tenant_id = %d', $shipment_id, $tenant );
		if ( ! $shipment ) {
			return false;
		}
		if ( '' !== $pin && ! hash_equals( (string) $shipment['delivery_pin'], $pin ) ) {
			return false;
		}

		$this->db->update(
			'ig_shipments',
			[ 'status' => self::STATUS_DELIVERED, 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $shipment_id, 'tenant_id' => $tenant ]
		);

		return true;
	}

	/** @return array<int,array<string,mixed>> */
	public function shipments( int $tenant_id, string $status = '', int $limit = 50 ): array {
		$where  = [ 'tenant_id = %d' ];
		$params = [ $tenant_id ];
		if ( '' !== $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_shipments' ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d',
			...array_merge( $params, [ $limit ] )
		);
	}

	public function get( int $id ): ?array {
		$row = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'ig_shipments' ) . ' WHERE id = %d AND tenant_id = %d', $id, igbz()->tenancy()->id() );
		return $row ?: null;
	}
}
