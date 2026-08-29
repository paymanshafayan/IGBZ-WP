<?php
namespace IGBZ\Suite\Modules\MultiTenant\Logistics;

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Courier app backend: the courier's own shipments, sequential routing with
 * an 'arrived' button, barcode scanning as a helper, customer-PIN delivery,
 * COD paths (customer app / cash / gateway / card-to-card), live tracking
 * and chat.
 *
 * Security: the courier only ever sees their own shipments; the delivery PIN
 * is never exposed to the courier — it is only compared server-side.
 */
final class CourierService {

	public function __construct(
		private Db $db,
		private Logger $logger
	) {}

	public function courier_for_user( int $user_id ): ?array {
		$row = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'ig_couriers' ) . ' WHERE user_id = %d AND is_active = 1 LIMIT 1', $user_id );
		return $row ?: null;
	}

	/** Assign a courier to a shipment. */
	public function assign( int $shipment_id, int $courier_user_id ): bool {
		$courier = $this->courier_for_user( $courier_user_id );
		if ( ! $courier ) {
			return false;
		}
		// Phase 43: only a draft can be handed to a courier.
		$row = $this->db->row( 'SELECT status FROM ' . $this->db->table( 'ig_shipments' ) . ' WHERE id = %d', $shipment_id );
		if ( ! $row || ! LogisticsService::can_transition( (string) $row['status'], LogisticsService::STATUS_ASSIGNED ) ) {
			return false;
		}
		$this->db->update(
			'ig_shipments',
			[
				'courier_id' => (int) $courier['id'],
				'status'     => 'assigned',
				'updated_at' => current_time( 'mysql', true ),
			],
			[ 'id' => $shipment_id ]
		);
		return true;
	}

	/** @return array<int,array<string,mixed>> */
	public function my_shipments( int $courier_id, string $status = '' ): array {
		$where  = [ 'courier_id = %d' ];
		$params = [ $courier_id ];
		if ( '' !== $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}
		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_shipments' ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id ASC LIMIT 200',
			...$params
		);
	}

	public function by_barcode( string $barcode, int $courier_id ): ?array {
		$row = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'ig_shipments' ) . ' WHERE barcode = %s AND courier_id = %d LIMIT 1',
			$barcode,
			$courier_id
		);
		return $row ?: null;
	}

	/** Sequential routing: plan the optimal order (nearest neighbour on lat/lng). */
	public function plan_route( int $courier_id, int $tenant_id ): array {
		$shipments = $this->my_shipments( $courier_id, 'assigned' );
		if ( count( $shipments ) < 2 ) {
			$ids = array_map( static fn ( $r ) => (int) $r['id'], $shipments );
			$this->save_route( $courier_id, $tenant_id, $ids, [] );
			return [ 'ok' => true, 'shipment_ids' => $ids ];
		}

		// Nearest neighbour over shipment coordinates (from meta).
		$points = [];
		foreach ( $shipments as $s ) {
			$meta   = json_decode( (string) ( $s['meta'] ?? '{}' ), true );
			$points[ (int) $s['id'] ] = [ (float) ( $meta['lat'] ?? 0 ), (float) ( $meta['lng'] ?? 0 ) ];
		}

		$ordered = [];
		$current = array_key_first( $points );
		$rest    = array_keys( $points );
		while ( $rest ) {
			$ordered[] = $current;
			$rest      = array_values( array_diff( $rest, [ $current ] ) );
			if ( ! $rest ) {
				break;
			}
			$best    = $rest[0];
			$best_d  = PHP_FLOAT_MAX;
			foreach ( $rest as $cand ) {
				$d = $this->distance( $points[ $current ], $points[ $cand ] );
				if ( $d < $best_d ) {
					$best_d = $d;
					$best   = $cand;
				}
			}
			$current = $best;
		}

		$this->save_route( $courier_id, $tenant_id, $ordered, $points );
		return [ 'ok' => true, 'shipment_ids' => $ordered ];
	}

	/** 'Arrived at destination' — open the shipment page (sequential flow). */
	public function arrived( int $shipment_id, int $courier_id ): bool {
		$row = $this->db->row(
			'SELECT id, status FROM ' . $this->db->table( 'ig_shipments' ) . ' WHERE id = %d AND courier_id = %d',
			$shipment_id,
			$courier_id
		);
		if ( ! $row ) {
			return false;
		}
		// Phase 43: arriving requires having been on the way (or assigned).
		if ( ! LogisticsService::can_transition( (string) $row['status'], LogisticsService::STATUS_AT_DESTINATION ) ) {
			return false;
		}
		$this->db->update(
			'ig_shipments',
			[ 'status' => 'at_destination', 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $shipment_id ]
		);
		return true;
	}

	/**
	 * Confirm delivery with the customer's PIN (never shown to the courier).
	 * Phase 44: the proof of delivery ($proof — a photo reference, signature id
	 * or whatever the courier app captured) is stored on the row, so a COD
	 * dispute can be answered from the shipment itself.
	 */
	public function deliver( int $shipment_id, int $courier_id, string $pin, string $proof = '' ): array {
		$shipment = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'ig_shipments' ) . ' WHERE id = %d AND courier_id = %d',
			$shipment_id,
			$courier_id
		);
		if ( ! $shipment ) {
			return [ 'ok' => false, 'error' => 'not_found' ];
		}
		if ( '' !== (string) $shipment['delivery_pin'] && ! hash_equals( (string) $shipment['delivery_pin'], $pin ) ) {
			return [ 'ok' => false, 'error' => 'wrong_pin' ];
		}
		// Phase 43: delivery is legal only from at_destination.
		if ( ! LogisticsService::can_transition( (string) $shipment['status'], LogisticsService::STATUS_DELIVERED ) ) {
			return [ 'ok' => false, 'error' => 'bad_state' ];
		}

		$this->db->update(
			'ig_shipments',
			[
				'status'     => 'delivered',
				'pod_ref'    => mb_substr( $proof, 0, 191 ),
				'pod_at'     => current_time( 'mysql', true ),
				'updated_at' => current_time( 'mysql', true ),
			],
			[ 'id' => $shipment_id ]
		);
		$this->logger->info( 'courier', 'Shipment delivered', [ 'shipment_id' => $shipment_id, 'pod' => '' !== $proof ] );

		return [ 'ok' => true, 'error' => '' ];
	}

	/**
	 * COD: record a payment method for a COD shipment. Returns the next step
	 * (gateway link to SMS, or card numbers to SMS, or done).
	 *
	 * @return array{ok:bool,next:string,gateway_link:string,error:string}
	 */
	public function cod( int $shipment_id, int $courier_id, string $method, string $card_ref = '' ): array {
		$shipment = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'ig_shipments' ) . ' WHERE id = %d AND courier_id = %d',
			$shipment_id,
			$courier_id
		);
		if ( ! $shipment ) {
			return [ 'ok' => false, 'next' => '', 'gateway_link' => '', 'error' => 'not_found' ];
		}
		if ( ! (int) $shipment['is_cod'] ) {
			return [ 'ok' => false, 'next' => '', 'gateway_link' => '', 'error' => 'not_cod' ];
		}

		$amount = (float) $shipment['cost_irt'];
		$ref    = 'cod:' . $shipment_id . ':' . gmdate( 'ymdHis' );

		if ( 'cash' === $method ) {
			// Phase 44: cash settles only where the machine allows delivery.
			if ( ! LogisticsService::can_transition( (string) $shipment['status'], LogisticsService::STATUS_DELIVERED ) ) {
				return [ 'ok' => false, 'next' => '', 'gateway_link' => '', 'error' => 'bad_state' ];
			}
			$this->save_cod( $shipment, 'cash', 'paid', $amount, $ref, '', $card_ref );
			$this->db->update(
				'ig_shipments',
				[ 'status' => 'delivered', 'pod_ref' => 'cod-cash:' . $ref, 'pod_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ],
				[ 'id' => $shipment_id ]
			);
			return [ 'ok' => true, 'next' => 'done', 'gateway_link' => '', 'error' => '' ];
		}

		if ( 'gateway' === $method ) {
			// Build a gateway payment link for the customer.
			$payments = igbz()->has( 'payments' ) ? igbz()->get( 'payments' ) : null;
			$link     = '';
			if ( $payments ) {
				$result = $payments->start(
					$amount,
					'cod',
					[ 'tenant_id' => (int) $shipment['tenant_id'], 'order_id' => (int) $shipment['order_id'], 'description' => 'COD shipment #' . $shipment_id ],
					''
				);
				if ( $result['ok'] ) {
					$link = (string) $result['redirect_url'];
				}
			}
			$this->save_cod( $shipment, 'gateway', 'pending', $amount, $ref, $link, '' );
			return [ 'ok' => true, 'next' => 'gateway', 'gateway_link' => $link, 'error' => '' ];
		}

		if ( 'card' === $method ) {
			$this->save_cod( $shipment, 'card', 'pending', $amount, $ref, '', '' );
			return [ 'ok' => true, 'next' => 'card_ref', 'gateway_link' => '', 'error' => '' ];
		}

		return [ 'ok' => false, 'next' => '', 'gateway_link' => '', 'error' => 'unknown_method' ];
	}

	/** Customer-app COD: the customer scanned the barcode and paid in-app. */
	public function cod_app_paid( int $shipment_id, string $charge_ref ): array {
		$tenant   = igbz()->tenancy()->id();
		$shipment = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'ig_shipments' ) . ' WHERE id = %d AND tenant_id = %d', $shipment_id, $tenant );
		if ( ! $shipment ) {
			return [ 'ok' => false, 'error' => 'not_found' ];
		}
		// Phase 44: in-app payment settles only where the machine allows delivery.
		if ( ! LogisticsService::can_transition( (string) $shipment['status'], LogisticsService::STATUS_DELIVERED ) ) {
			return [ 'ok' => false, 'error' => 'bad_state' ];
		}
		$this->save_cod( $shipment, 'app', 'paid', (float) $shipment['cost_irt'], 'cod-app:' . $shipment_id, '', $charge_ref );
		$this->db->update(
			'ig_shipments',
			[ 'status' => 'delivered', 'pod_ref' => 'cod-app:' . $shipment_id, 'pod_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $shipment_id, 'tenant_id' => $tenant ]
		);

		return [ 'ok' => true, 'error' => '' ];
	}

	/** Record live GPS position from the courier app. */
	public function track( int $shipment_id, float $lat, float $lng, int $tenant_id ): void {
		$this->db->insert(
			'ig_courier_tracking',
			[
				'tenant_id'   => $tenant_id,
				'shipment_id' => $shipment_id,
				'lat'         => $lat,
				'lng'         => $lng,
				'at'          => current_time( 'mysql', true ),
			]
		);
	}

	/** @return array<int,array<string,mixed>> */
	public function tracking( int $shipment_id, int $limit = 100 ): array {
		return $this->db->results(
			'SELECT lat, lng, at FROM ' . $this->db->table( 'ig_courier_tracking' ) . ' WHERE shipment_id = %d ORDER BY id ASC LIMIT %d',
			$shipment_id,
			$limit
		);
	}

	/** Chat between courier and customer. */
	public function send_chat( int $shipment_id, string $sender, string $body, int $tenant_id, int $courier_id = 0 ): int {
		// Ownership gate: a chat message can only land on a shipment bound to this courier.
		$owned = $this->db->row(
			'SELECT id FROM ' . $this->db->table( 'ig_shipments' ) . ' WHERE id = %d AND courier_id = %d',
			$shipment_id,
			$courier_id
		);
		if ( ! $owned ) {
			return 0;
		}
		return (int) $this->db->insert(
			'ig_courier_chat',
			[
				'tenant_id'   => $tenant_id,
				'shipment_id' => $shipment_id,
				'sender'      => $sender,
				'body'        => mb_substr( $body, 0, 2000 ),
				'created_at'  => current_time( 'mysql', true ),
			]
		);
	}

	/** @return array<int,array<string,mixed>> */
	public function chat( int $shipment_id, int $courier_id ): array {
		$owned = $this->db->row(
			'SELECT id FROM ' . $this->db->table( 'ig_shipments' ) . ' WHERE id = %d AND courier_id = %d',
			$shipment_id,
			$courier_id
		);
		if ( ! $owned ) {
			return [];
		}
		return $this->db->results(
			'SELECT sender, body, created_at FROM ' . $this->db->table( 'ig_courier_chat' ) . ' WHERE shipment_id = %d ORDER BY id ASC',
			$shipment_id
		);
	}

	// ------------------------------------------------------------ helpers

	private function save_cod( array $shipment, string $method, string $status, float $amount, string $ref, string $link, string $card_ref ): void {
		$this->db->insert(
			'ig_cod_payments',
			[
				'tenant_id'         => (int) $shipment['tenant_id'],
				'shipment_id'       => (int) $shipment['id'],
				'method'            => $method,
				'status'            => $status,
				'amount'            => $amount,
				'ref'               => $ref,
				'gateway_link'      => $link,
				'card_transfer_ref' => $card_ref,
				'created_at'        => current_time( 'mysql', true ),
				'updated_at'        => current_time( 'mysql', true ),
			]
		);
	}

	private function save_route( int $courier_id, int $tenant_id, array $ids, array $points ): void {
		$this->db->insert(
			'ig_courier_routes',
			[
				'tenant_id'    => $tenant_id,
				'courier_id'   => $courier_id,
				'shipment_ids' => wp_json_encode( $ids ),
				'payload'      => wp_json_encode( $points ),
				'created_at'   => current_time( 'mysql', true ),
			]
		);
	}

	/** @return array{0:float,1:float} */
	private function distance( array $a, array $b ): float {
		return sqrt( ( $a[0] - $b[0] ) ** 2 + ( $a[1] - $b[1] ) ** 2 );
	}
}
