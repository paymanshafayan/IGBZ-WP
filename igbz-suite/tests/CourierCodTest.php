<?php
/**
 * Phase 44 — courier delivery evidence and the COD money cycle: every delivery records its
 * proof on the row, cash and in-app COD settle only where the state machine allows, and the
 * payment ledger row follows the shipment's outcome.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\MultiTenant\Logistics\CourierService;
use IGBZ\Suite\Support\Db;

/** In-memory engine for shipments, couriers and COD payments. */
final class CodDb extends wpdb {

	/** @var array<string,array<int,array<string,mixed>>> */
	public array $tables = [
		'ig_shipments'    => [],
		'ig_couriers'     => [],
		'ig_cod_payments' => [],
	];

	private int $next_id = 1;

	/** @param array<string,mixed> $row */
	public function seed( string $table, array $row ): int {
		$id                        = $this->next_id++;
		$row['id']                 = $id;
		$this->tables[ $table ][ $id ] = $row;

		return $id;
	}

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'ig_shipments' ) && preg_match( "/WHERE id = '?(\d+)'?/", $sql, $m ) ) {
			$row = $this->tables['ig_shipments'][ (int) $m[1] ] ?? null;
			if ( null !== $row && str_contains( $sql, 'courier_id' ) && preg_match( "/courier_id = '?(\d+)'?/", $sql, $c ) && (string) $row['courier_id'] !== $c[1] ) {
				return null;
			}
			if ( null !== $row && str_contains( $sql, 'tenant_id' ) && preg_match( "/tenant_id = '?(\d+)'?/", $sql, $t ) && (string) $row['tenant_id'] !== $t[1] ) {
				return null;
			}
			return $row;
		}

		if ( str_contains( $sql, 'ig_couriers' ) && preg_match( "/user_id = '?(\d+)'?/", $sql, $m ) ) {
			foreach ( $this->tables['ig_couriers'] as $row ) {
				if ( (string) $row['user_id'] === $m[1] && (int) $row['is_active'] === 1 ) {
					return $row;
				}
			}
			return null;
		}

		return parent::get_row( $sql, $output );
	}

	public function insert( string $table, array $data, $format = null ): int|bool {
		$this->queries[] = 'INSERT INTO ' . $table;
		$this->last_write = [ 'table' => $table, 'data' => $data ];
		$this->writes[]   = $this->last_write;

		foreach ( [ 'ig_shipments', 'ig_couriers', 'ig_cod_payments' ] as $name ) {
			if ( str_contains( $table, $name ) ) {
				$this->insert_id = $this->seed( $name, $data );
				return 1;
			}
		}

		return parent::insert( $table, $data, $format );
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		$this->queries[] = 'UPDATE ' . $table;

		foreach ( [ 'ig_shipments', 'ig_couriers', 'ig_cod_payments' ] as $name ) {
			if ( ! str_contains( $table, $name ) ) {
				continue;
			}
			$changed = 0;
			foreach ( $this->tables[ $name ] as $id => $row ) {
				$hit = true;
				foreach ( $where as $column => $value ) {
					if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) {
						$hit = false;
						break;
					}
				}
				if ( $hit ) {
					$this->tables[ $name ][ $id ] = array_merge( $row, $data );
					++$changed;
				}
			}
			return $changed;
		}

		return parent::update( $table, $data, $where, $format, $where_format );
	}
}

final class CourierCodTest extends TestCase {

	private Db $db;
	private CodDb $cdb;
	private CourierService $couriers;

	private function boot(): void {
		igbz_test_reset_settings();

		$this->cdb         = new CodDb();
		$GLOBALS['wpdb']   = $this->cdb;

		$this->db = new Db();
		$ref = new ReflectionProperty( Db::class, 'is_sqlite' );
		$ref->setValue( $this->db, true );

		$this->couriers = new CourierService( $this->db, new IGBZ\Suite\Support\Logger( igbz()->settings() ) );

		$this->cdb->seed( 'ig_couriers', [ 'tenant_id' => 0, 'user_id' => 21, 'is_active' => 1, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ] );
	}

	private function shipment( string $status, int $is_cod = 0 ): array {
		$id = $this->cdb->seed( 'ig_shipments', [
			'tenant_id' => 0, 'order_id' => 1, 'carrier' => 'courier', 'tracking_code' => '',
			'delivery_pin' => '4321', 'status' => $status, 'route_type' => 'express', 'cost_irt' => 650000,
			'is_cod' => $is_cod, 'courier_id' => 1, 'pod_ref' => '', 'pod_at' => null,
			'recipient_name' => 'r', 'recipient_phone' => '09120000000', 'recipient_address' => 'a',
			'meta' => '{}', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		] );

		return $this->cdb->tables['ig_shipments'][ $id ];
	}

	private function payment(): ?array {
		$rows = array_values( $this->cdb->tables['ig_cod_payments'] );
		return $rows[0] ?? null;
	}

	public function run(): void {
		$this->test_delivery_records_its_proof();
		$this->test_cash_cod_settles_only_where_the_machine_allows();
		$this->test_in_app_cod_respects_the_machine_and_pays_the_ledger();
		$this->test_non_cod_and_unknown_methods_are_refused();
	}

	public function test_delivery_records_its_proof(): void {
		$this->boot();
		$row = $this->shipment( 'at_destination' );

		$result = $this->couriers->deliver( (int) $row['id'], 1, '4321', 'photo:abc123' );

		$this->assert_true( $result['ok'], 'the PIN delivers' );
		$this->assert_same( 'photo:abc123', $this->cdb->tables['ig_shipments'][ (int) $row['id'] ]['pod_ref'], 'the proof lands on the row' );
		$this->assert_true( null !== $this->cdb->tables['ig_shipments'][ (int) $row['id'] ]['pod_at'], 'the proof carries a moment' );
	}

	public function test_cash_cod_settles_only_where_the_machine_allows(): void {
		$this->boot();
		$draft = $this->shipment( 'draft', 1 );

		$blocked = $this->couriers->cod( (int) $draft['id'], 1, 'cash' );
		$this->assert_false( $blocked['ok'], 'cash cannot settle a draft' );
		$this->assert_same( 'bad_state', $blocked['error'], 'the machine names the refusal' );
		$this->assert_same( 0, count( $this->cdb->tables['ig_cod_payments'] ), 'no payment row for a blocked settlement' );

		$this->cdb->tables['ig_shipments'][ (int) $draft['id'] ]['status'] = 'at_destination';
		$paid = $this->couriers->cod( (int) $draft['id'], 1, 'cash' );
		$this->assert_true( $paid['ok'], 'cash settles at the destination' );
		$this->assert_same( 'done', $paid['next'], 'the cycle closes' );
		$this->assert_same( 'delivered', $this->cdb->tables['ig_shipments'][ (int) $draft['id'] ]['status'], 'the shipment is delivered' );
		$this->assert_same( 'paid', $this->payment()['status'] ?? null, 'the ledger row says paid' );
		$this->assert_same( 650000.0, (float) ( $this->payment()['amount'] ?? 0 ), 'the ledger row carries the amount' );
	}

	public function test_in_app_cod_respects_the_machine_and_pays_the_ledger(): void {
		$this->boot();
		$row = $this->shipment( 'in_transit', 1 );

		$blocked = $this->couriers->cod_app_paid( (int) $row['id'], 'ch:1' );
		$this->assert_false( $blocked['ok'], 'in-app pay cannot deliver from the road' );

		$this->cdb->tables['ig_shipments'][ (int) $row['id'] ]['status'] = 'at_destination';
		$paid = $this->couriers->cod_app_paid( (int) $row['id'], 'ch:1' );
		$this->assert_true( $paid['ok'], 'in-app pay settles at the destination' );
		$this->assert_same( 'paid', $this->payment()['status'] ?? null, 'the ledger row says paid' );
		$this->assert_same( 'app', $this->payment()['method'] ?? null, 'the method is recorded' );
	}

	public function test_non_cod_and_unknown_methods_are_refused(): void {
		$this->boot();
		$plain = $this->shipment( 'at_destination', 0 );

		$this->assert_same( 'not_cod', $this->couriers->cod( (int) $plain['id'], 1, 'cash' )['error'], 'a prepaid shipment is not COD' );

		$cod = $this->shipment( 'at_destination', 1 );
		$this->assert_same( 'unknown_method', $this->couriers->cod( (int) $cod['id'], 1, 'barter' )['error'], 'unknown methods are refused' );
	}
}
