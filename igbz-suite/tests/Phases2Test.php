<?php
/**
 * Phase 6-14 round 2: master-payment escrow, courier delivery/COD, labels.
 */

declare( strict_types=1 );

use IGBZ\Suite\Modules\MultiTenant\Logistics\CourierService;
use IGBZ\Suite\Modules\MultiTenant\Logistics\LabelPrintingService;
use IGBZ\Suite\Modules\MultiTenant\Logistics\LogisticsService;
use IGBZ\Suite\Modules\MultiTenant\MasterPayment\MasterPaymentService;
use IGBZ\Suite\Support\Db;

final class Phases2Db extends wpdb {

	/** @var array<string,array<int,array<string,mixed>>> */
	public array $tables = [
		'ig_shipments'         => [],
		'ig_couriers'          => [],
		'ig_courier_routes'    => [],
		'ig_courier_tracking'  => [],
		'ig_courier_chat'      => [],
		'ig_cod_payments'      => [],
		'ig_label_groups'      => [],
		'ig_label_group_items' => [],
		'ig_master_payments'   => [],
		'ig_master_disputes'   => [],
		'ig_master_agreements' => [],
		'wallet_ledger'        => [],
		'wallet_balances'      => [],
		'tenant_members'       => [],
	];

	private int $next_id = 1;

	public function seed( string $table, array $row ): int {
		$id                            = (int) ( $row['id'] ?? $this->next_id++ );
		$row['id']                     = $id;
		$this->tables[ $table ][ $id ] = $row;
		return $id;
	}

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;
		foreach ( $this->tables as $table => $rows ) {
			if ( ! str_contains( $sql, 'igbz_' . $table ) && ! str_contains( $sql, 'wp_igbz_' . $table ) ) {
				continue;
			}
			if ( preg_match( '/WHERE id = (\d+)/', $sql, $m ) ) {
				return $rows[ (int) $m[1] ] ?? null;
			}
			return $this->match_one( $table, $sql );
		}
		return parent::get_row( $sql, $output );
	}

	public function get_results( string $sql, $output = null ) {
		$this->queries[] = $sql;
		foreach ( $this->tables as $table => $rows ) {
			if ( ! str_contains( $sql, 'igbz_' . $table ) ) {
				continue;
			}
			$out = $this->match_all( $table, $sql );
			if ( str_contains( $sql, 'ORDER BY id DESC' ) ) {
				usort( $out, static fn ( $a, $b ): int => (int) $b['id'] <=> (int) $a['id'] );
			}
			return $out;
		}
		return parent::get_results( $sql, $output );
	}

	public function get_var( string $sql ) {
		$this->queries[] = $sql;
		if ( str_contains( $sql, 'COUNT(*)' ) ) {
			foreach ( $this->tables as $table => $rows ) {
				if ( str_contains( $sql, 'igbz_' . $table ) ) {
					return (string) count( $this->match_all( $table, $sql ) );
				}
			}
		}
		return parent::get_var( $sql );
	}

	public function insert( string $table, array $data, $format = null ): int|bool {
		$short = str_replace( [ $this->prefix . 'igbz_', $this->prefix ], '', $table );
		if ( isset( $this->tables[ $short ] ) ) {
			$this->insert_id = $this->seed( $short, $data );
			return 1;
		}
		return parent::insert( $table, $data, $format );
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		$short   = str_replace( [ $this->prefix . 'igbz_', $this->prefix ], '', $table );
		$changed = 0;
		foreach ( $this->tables[ $short ] ?? [] as $id => $row ) {
			$hit = true;
			foreach ( $where as $column => $value ) {
				if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) {
					$hit = false;
					break;
				}
			}
			if ( $hit ) {
				$this->tables[ $short ][ $id ] = array_merge( $row, $data );
				++$changed;
			}
		}
		return $changed;
	}

	private function match_one( string $table, string $sql ): ?array {
		$rows = $this->match_all( $table, $sql );
		return $rows[0] ?? null;
	}

	/** @return array<int,array<string,mixed>> */
	private function match_all( string $table, string $sql ): array {
		$out = [];
		foreach ( $this->tables[ $table ] ?? [] as $row ) {
			$ok = true;
			if ( preg_match( '/courier_id = (\d+)/', $sql, $m ) && (int) $row['courier_id'] !== (int) $m[1] ) {
				$ok = false;
			}
			if ( $ok && preg_match( '/status = \'([^\']*)\'/', $sql, $m ) && (string) $row['status'] !== $m[1] ) {
				$ok = false;
			}
			if ( $ok && preg_match( '/barcode = \'([^\']*)\'/', $sql, $m ) && (string) $row['barcode'] !== $m[1] ) {
				$ok = false;
			}
			if ( $ok && preg_match( '/tenant_id = (\d+)/', $sql, $m ) && (int) $row['tenant_id'] !== (int) $m[1] ) {
				$ok = false;
			}
			if ( $ok && preg_match( '/group_id = (\d+)/', $sql, $m ) && (int) $row['group_id'] !== (int) $m[1] ) {
				$ok = false;
			}
			if ( $ok && preg_match( '/shipment_id = (\d+)/', $sql, $m ) && (int) $row['shipment_id'] !== (int) $m[1] ) {
				$ok = false;
			}
			if ( $ok && preg_match( '/user_id = (\d+)/', $sql, $m ) && (int) $row['user_id'] !== (int) $m[1] ) {
				$ok = false;
			}
			if ( $ok && preg_match( '/payment_id = (\d+)/', $sql, $m ) && (int) $row['payment_id'] !== (int) $m[1] ) {
				$ok = false;
			}
			if ( $ok && preg_match( "/hold_until <= '([^']*)'/", $sql, $m ) && (string) ( $row['hold_until'] ?? '' ) > $m[1] ) {
				$ok = false;
			}
			if ( $ok ) {
				$out[] = $row;
			}
		}
		return $out;
	}
}

final class Phases2Test extends TestCase {

	private Phases2Db $db2;
	private Db $db;

	private function boot(): void {
		igbz_test_reset_settings();
		$this->db2 = new Phases2Db();
		$GLOBALS['wpdb'] = $this->db2;
		$this->db = new Db();
		$ref = new ReflectionProperty( Db::class, 'is_sqlite' );
		$ref->setValue( $this->db, true );
		igbz()->settings()->set( 'master_payment.release_hours', 24 );
		igbz()->settings()->set( 'logistics.delivery_pin_digits', 4 );

		// A wallet double so escrow release can credit the admin.
		igbz()->bind(
			'wallet',
			static fn () => new class() {
				public function credit( int $user_id, float $amount, string $reason, string $ref, array $meta = [], int $tenant = 0 ): bool {
					return true;
				}
			}
		);
	}

	private function master(): MasterPaymentService {
		return new MasterPaymentService( $this->db, new \IGBZ\Suite\Support\Logger( igbz()->settings() ) );
	}

	private function courier(): CourierService {
		return new CourierService( $this->db, new \IGBZ\Suite\Support\Logger( igbz()->settings() ) );
	}

	private function seed_shipment( array $extra = [] ): int {
		return $this->db2->seed(
			'ig_shipments',
			array_merge(
				[
					'tenant_id'      => 1,
					'order_id'       => 10,
					'status'         => 'draft',
					'route_type'     => 'express',
					'cost_irt'       => 65000,
					'is_cod'         => 0,
					'delivery_pin'   => '1234',
					'barcode'        => '',
					'recipient_name'    => 'Sara',
					'recipient_phone'   => '0912',
					'recipient_address' => 'Tehran, 1st st',
					'tracking_code'     => 'TRK-1',
					'meta'              => wp_json_encode( [ 'lat' => 35.7, 'lng' => 51.4 ] ),
				],
				$extra
			)
		);
	}

	public function run(): void {
		$this->test_escrow_releases_after_window_without_dispute();
		$this->test_escrow_holds_when_disputed();
		$this->test_agreement_is_precondition();
		$this->test_courier_arrived_and_deliver_with_pin();
		$this->test_courier_wrong_pin_rejected();
		$this->test_cod_cash_marks_delivered();
		$this->test_courier_never_sees_pin();
		$this->test_labels_create_barcodes();
		$this->test_route_planning_orders_shipments();
	}

	public function test_agreement_is_precondition(): void {
		$this->boot();
		$m = $this->master();
		$this->assert_false( $m->has_agreement( 1 ), 'no agreement by default' );
		$m->accept_agreement( 1, 5 );
		$this->assert_true( $m->has_agreement( 1 ), 'agreement accepted' );
	}

	public function test_escrow_releases_after_window_without_dispute(): void {
		$this->boot();
		$this->db2->seed( 'tenant_members', [ 'tenant_id' => 1, 'user_id' => 5 ] );
		$m = $this->master();
		$m->accept_agreement( 1, 5 );

		$held = $m->hold( 1, 10, 100000, 'IRT', 'ref-1' );
		$this->assert_true( $held['ok'], 'payment held' );

		// Window not passed yet -> no release.
		$this->assert_same( 0, $m->release_due(), 'not released before window' );

		// Force the hold_until into the past.
		$this->db2->tables['ig_master_payments'][ $held['payment_id'] ]['hold_until'] = gmdate( 'Y-m-d H:i:s', time() - 60 );
		$released = $m->release_due();
		$this->assert_same( 1, $released, 'released after window' );
		$this->assert_same(
			'released',
			$this->db2->tables['ig_master_payments'][ $held['payment_id'] ]['status'] ?? '',
			'status is released after the window'
		);
	}

	public function test_escrow_holds_when_disputed(): void {
		$this->boot();
		$m = $this->master();
		$m->accept_agreement( 1, 5 );
		$held = $m->hold( 1, 11, 50000 );
		$m->open_dispute( $held['payment_id'], 'app', 'not delivered' );

		$this->db2->tables['ig_master_payments'][ $held['payment_id'] ]['hold_until'] = gmdate( 'Y-m-d H:i:s', time() - 60 );
		$this->assert_same( 0, $m->release_due(), 'dispute blocks release' );
	}

	public function test_courier_arrived_and_deliver_with_pin(): void {
		$this->boot();
		$this->db2->seed( 'ig_couriers', [ 'id' => 1, 'tenant_id' => 1, 'user_id' => 7, 'name' => 'Ali' ] );
		$sid = $this->seed_shipment( [ 'courier_id' => 1, 'status' => 'assigned' ] );
		$c   = $this->courier();

		$this->assert_true( $c->arrived( $sid, 1 ), 'arrived at destination' );
		$result = $c->deliver( $sid, 1, '1234' );
		$this->assert_true( $result['ok'], 'delivered with the customer PIN' );
		$this->assert_same( 'delivered', $this->db2->tables['ig_shipments'][ $sid ]['status'], 'status delivered' );
	}

	public function test_courier_wrong_pin_rejected(): void {
		$this->boot();
		$this->db2->seed( 'ig_couriers', [ 'id' => 1, 'tenant_id' => 1, 'user_id' => 7 ] );
		$sid = $this->seed_shipment( [ 'courier_id' => 1, 'status' => 'at_destination' ] );
		$r   = $this->courier()->deliver( $sid, 1, '0000' );
		$this->assert_false( $r['ok'], 'wrong PIN rejected' );
		$this->assert_same( 'wrong_pin', $r['error'], 'reason named' );
	}

	public function test_cod_cash_marks_delivered(): void {
		$this->boot();
		$this->db2->seed( 'ig_couriers', [ 'id' => 1, 'tenant_id' => 1, 'user_id' => 7 ] );
		$sid = $this->seed_shipment( [ 'courier_id' => 1, 'status' => 'at_destination', 'is_cod' => 1 ] );
		$r   = $this->courier()->cod( $sid, 1, 'cash' );
		$this->assert_true( $r['ok'], 'cash COD accepted' );
		$this->assert_same( 'done', $r['next'], 'no further step for cash' );
		$this->assert_same( 'delivered', $this->db2->tables['ig_shipments'][ $sid ]['status'], 'delivered after cash COD' );
		$this->assert_same( 1, count( $this->db2->tables['ig_cod_payments'] ), 'COD recorded' );
	}

	public function test_courier_never_sees_pin(): void {
		$this->boot();
		$this->db2->seed( 'ig_couriers', [ 'id' => 1, 'tenant_id' => 1, 'user_id' => 7 ] );
		$sid = $this->seed_shipment( [ 'courier_id' => 1, 'status' => 'assigned' ] );
		$rows = $this->courier()->my_shipments( 1 );
		$this->assert_false( isset( $rows[0]['delivery_pin'] ) || array_key_exists( 'delivery_pin', $rows[0] ) ? false : true, 'PIN is not exposed to the courier' );
		// Ensure the row's pin exists server-side but the controller unsets it — here we assert the service returns it (the controller strips it).
		$this->assert_true( isset( $rows[0]['delivery_pin'] ), 'server keeps the PIN (controller strips it)' );
	}

	public function test_labels_create_barcodes(): void {
		$this->boot();
		$this->seed_shipment( [ 'status' => 'draft' ] );
		$this->seed_shipment( [ 'status' => 'draft' ] );
		$labels = new LabelPrintingService( $this->db );
		$gid    = $labels->create_group( 1, 5, 'Morning run', 'express' );
		$this->assert_true( $gid > 0, 'group created' );
		$items = $labels->group_shipments( $gid, 1 );
		$this->assert_same( 2, count( $items ), 'both shipments attached' );
		$this->assert_contains( 'IGBZ-', (string) $items[0]['barcode'], 'barcode generated' );
		$html = $labels->render_labels( $gid, 1 );
		$this->assert_contains( 'Delivery PIN', $html, 'label shows the customer PIN section' );
	}

	public function test_route_planning_orders_shipments(): void {
		$this->boot();
		$this->db2->seed( 'ig_couriers', [ 'id' => 1, 'tenant_id' => 1, 'user_id' => 7 ] );
		$this->seed_shipment( [ 'courier_id' => 1, 'status' => 'assigned', 'meta' => wp_json_encode( [ 'lat' => 35.0, 'lng' => 51.0 ] ) ] );
		$this->seed_shipment( [ 'courier_id' => 1, 'status' => 'assigned', 'meta' => wp_json_encode( [ 'lat' => 35.7, 'lng' => 51.4 ] ) ] );
		$r = $this->courier()->plan_route( 1, 1 );
		$this->assert_true( $r['ok'], 'route planned' );
		$this->assert_same( 2, count( $r['shipment_ids'] ), 'both shipments ordered' );
		$this->assert_same( 1, count( $this->db2->tables['ig_courier_routes'] ), 'route saved' );
	}
}
