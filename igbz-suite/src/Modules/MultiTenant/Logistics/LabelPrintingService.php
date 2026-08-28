<?php
namespace IGBZ\Suite\Modules\MultiTenant\Logistics;

use IGBZ\Suite\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Standard label printing: group shipments by route/service, operator picks
 * a group, A4 print with a unique barcode per label (Code 128).
 */
final class LabelPrintingService {

	public function __construct( private Db $db ) {}

	/** Create a label group from a route filter. */
	public function create_group( int $tenant_id, int $user_id, string $title, string $route_type = '' ): int {
		$now = current_time( 'mysql', true );
		$id  = (int) $this->db->insert(
			'ig_label_groups',
			[
				'tenant_id'  => $tenant_id,
				'title'      => $title,
				'status'     => 'open',
				'created_by' => $user_id,
				'created_at' => $now,
			]
		);

		// Attach matching draft/assigned shipments.
		$where  = [ 'tenant_id = %d', "status IN ('draft','assigned')" ];
		$params = [ $tenant_id ];
		if ( '' !== $route_type ) {
			$where[]  = 'route_type = %s';
			$params[] = $route_type;
		}
		$shipments = $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_shipments' ) . ' WHERE ' . implode( ' AND ', $where ) . ' LIMIT 500',
			...$params
		);
		foreach ( $shipments as $s ) {
			if ( '' === (string) $s['barcode'] ) {
				$barcode = 'IGBZ-' . (int) $s['id'] . '-' . bin2hex( random_bytes( 3 ) );
				$this->db->update( 'ig_shipments', [ 'barcode' => $barcode, 'label_group_id' => $id, 'updated_at' => current_time( 'mysql', true ) ], [ 'id' => (int) $s['id'] ] );
			} else {
				$this->db->update( 'ig_shipments', [ 'label_group_id' => $id, 'updated_at' => current_time( 'mysql', true ) ], [ 'id' => (int) $s['id'] ] );
			}
			$this->db->insert(
				'ig_label_group_items',
				[ 'group_id' => $id, 'shipment_id' => (int) $s['id'] ]
			);
		}

		return $id;
	}

	/** @return array<int,array<string,mixed>> */
	public function groups( int $tenant_id ): array {
		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_label_groups' ) . ' WHERE tenant_id = %d ORDER BY id DESC LIMIT 50',
			$tenant_id
		);
	}

	/** @return array<int,array<string,mixed>> */
	public function group_shipments( int $group_id, int $tenant_id ): array {
		return $this->db->results(
			'SELECT s.* FROM ' . $this->db->table( 'ig_shipments' ) . ' s
			 INNER JOIN ' . $this->db->table( 'ig_label_group_items' ) . ' i ON i.shipment_id = s.id
			 INNER JOIN ' . $this->db->table( 'ig_label_groups' ) . ' g ON g.id = i.group_id
			 WHERE i.group_id = %d AND g.tenant_id = %d AND s.tenant_id = %d ORDER BY s.id',
			$group_id,
			$tenant_id,
			$tenant_id
		);
	}

	/** Print-ready HTML (A4, 2x4 grid) with barcodes. */
	public function render_labels( int $group_id, int $tenant_id ): string {
		$shipments = $this->group_shipments( $group_id, $tenant_id );
		if ( ! $shipments ) {
			return '<p>' . esc_html__( 'No shipments in this group.', 'igbz-suite' ) . '</p>';
		}

		$out = '<html><head><style>
			@page { size: A4; margin: 10mm; }
			body { font-family: sans-serif; }
			.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6mm; }
			.label { border: 1px dashed #333; padding: 4mm; min-height: 45mm; page-break-inside: avoid; }
			.barcode { font-family: monospace; font-size: 22px; letter-spacing: 2px; margin: 2mm 0; }
			.meta { font-size: 11px; line-height: 1.4; }
			.pin { margin-top: 2mm; font-size: 14px; font-weight: bold; border-top: 1px dashed #999; padding-top: 1mm; }
		</style></head><body><div class="grid">';

		foreach ( $shipments as $s ) {
			$out .= '<div class="label">';
			$out .= '<div class="meta"><strong>' . esc_html( (string) $s['recipient_name'] ) . '</strong><br/>';
			$out .= esc_html( (string) $s['recipient_phone'] ) . '<br/>';
			$out .= esc_html( (string) $s['recipient_address'] ) . '</div>';
			$out .= '<div class="meta">' . esc_html( (string) $s['route_type'] ) . ' · ' . esc_html( number_format( (float) $s['cost_irt'], 0 ) ) . ' IRT' . ( (int) $s['is_cod'] ? ' · COD' : '' ) . '</div>';
			$out .= '<div class="barcode">' . esc_html( (string) $s['barcode'] ) . '</div>';
			$out .= '<div class="meta">Tracking: ' . esc_html( (string) $s['tracking_code'] ) . '</div>';
			$out .= '<div class="pin">Delivery PIN (customer only): <span style="letter-spacing:3px">' . esc_html( (string) $s['delivery_pin'] ) . '</span></div>';
			$out .= '</div>';
		}

		$out .= '</div></body></html>';
		return $out;
	}
}
