<?php
/**
 * Phase 43 — the delivery state machine: transitions outside the map are impossible no
 * matter who asks — a draft cannot be delivered, a carrier hand-off only leaves a draft,
 * arriving requires having been on the way, and delivered/failed are terminal.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\MultiTenant\Logistics\CourierService;
use IGBZ\Suite\Modules\MultiTenant\Logistics\LogisticsService;
use IGBZ\Suite\Support\Db;

/** In-memory engine for shipments + couriers. */
final class LogiDb extends wpdb {

	/** @var array<string,array<int,array<string,mixed>>> */
	public array $tables = [
		'ig_shipments' => [],
		'ig_couriers'  => [],
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
			if ( null !== $row && str_contains( $sql, 'tenant_id' ) && preg_match( "/tenant_id = '?(\d+)'?/", $sql, $t ) && (string) $row['tenant_id'] !== $t[1] ) {
				return null;
			}
			if ( null !== $row && str_contains( $sql, 'courier_id' ) && preg_match( "/courier_id = '?(\d+)'?/", $sql, $c ) && (string) $row['courier_id'] !== $c[1] ) {
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

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		$this->queries[] = 'UPDATE ' . $table;

		foreach ( [ 'ig_shipments', 'ig_couriers' ] as $name ) {
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

final class LogisticsStateMachineTest extends TestCase {

	private Db $db;
	private LogiDb $ldb;
	private LogisticsService $logi;
	private CourierService $couriers;

	private function boot(): void {
		igbz_test_reset_settings();

		$this->ldb         = new LogiDb();
		$GLOBALS['wpdb']   = $this->ldb;

		$this->db = new Db();
		$ref = new ReflectionProperty( Db::class, 'is_sqlite' );
		$ref->setValue( $this->db, true );

		$settings       = igbz()->settings();
		$logger         = new IGBZ\Suite\Support\Logger( $settings );
		$this->logi     = new LogisticsService( $this->db, $settings, $logger );
		$this->couriers = new CourierService( $this->db, $logger );
	}

	private function shipment( string $status, array $extra = [] ): array {
		$id = $this->ldb->seed( 'ig_shipments', array_merge( [
			'tenant_id'     => 0,
			'order_id'      => 1,
			'carrier'       => 'post',
			'tracking_code' => '',
			'delivery_pin'  => '1234',
			'status'        => $status,
			'route_type'    => 'national',
			'cost_irt'      => 45000,
			'is_cod'        => 0,
			'courier_id'    => 0,
			'recipient_name' => 'r', 'recipient_phone' => '09120000000', 'recipient_address' => 'a',
			'meta'          => '{}',
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
		], $extra ) );

		return $this->ldb->tables['ig_shipments'][ $id ];
	}

	public function run(): void {
		$this->test_the_transition_table_is_the_law();
		$this->test_delivery_is_refused_before_at_destination();
		$this->test_the_courier_flow_walks_the_machine();
		$this->test_terminal_states_accept_nothing();
	}

	public function test_the_transition_table_is_the_law(): void {
		$this->boot();

		$this->assert_true( LogisticsService::can_transition( 'draft', 'assigned' ), 'a draft can be handed to a courier' );
		$this->assert_true( LogisticsService::can_transition( 'draft', 'registered' ), 'a draft can be handed to a carrier' );
		$this->assert_true( LogisticsService::can_transition( 'assigned', 'in_transit' ), 'a courier can depart' );
		$this->assert_true( LogisticsService::can_transition( 'in_transit', 'at_destination' ), 'a moving shipment arrives' );
		$this->assert_true( LogisticsService::can_transition( 'at_destination', 'delivered' ), 'arrival can become delivery' );

		$this->assert_false( LogisticsService::can_transition( 'draft', 'delivered' ), 'a draft can never be delivered' );
		$this->assert_false( LogisticsService::can_transition( 'draft', 'at_destination' ), 'a draft never teleports' );
		$this->assert_false( LogisticsService::can_transition( 'assigned', 'registered' ), 'a courier-assigned shipment cannot be carrier-registered' );
		$this->assert_false( LogisticsService::can_transition( 'anything-unknown', 'delivered' ), 'unknown states refuse everything' );
	}

	public function test_delivery_is_refused_before_at_destination(): void {
		$this->boot();
		$draft = $this->shipment( 'draft' );

		$this->assert_false( $this->logi->mark_delivered( (int) $draft['id'], '1234' ), 'a draft refuses delivery even with the right PIN' );
		$this->assert_same( 'draft', $this->ldb->tables['ig_shipments'][ (int) $draft['id'] ]['status'], 'the status never moved' );

		$there = $this->shipment( 'at_destination' );
		$this->assert_false( $this->logi->mark_delivered( (int) $there['id'], '9999' ), 'a wrong PIN is still a wrong PIN' );
		$this->assert_true( $this->logi->mark_delivered( (int) $there['id'], '1234' ), 'arrival + PIN delivers' );
		$this->assert_same( 'delivered', $this->ldb->tables['ig_shipments'][ (int) $there['id'] ]['status'], 'the machine walked the last rung' );
	}

	public function test_the_courier_flow_walks_the_machine(): void {
		$this->boot();
		$this->ldb->seed( 'ig_couriers', [ 'tenant_id' => 0, 'user_id' => 21, 'is_active' => 1, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ] );

		$draft = $this->shipment( 'draft' );
		$this->assert_true( $this->couriers->assign( (int) $draft['id'], 21 ), 'a draft is assignable' );
		$this->assert_false( $this->couriers->assign( (int) $draft['id'], 21 ), 'an assigned shipment is not re-assigned' );

		$this->assert_true( $this->couriers->arrived( (int) $draft['id'], 1 ), 'arriving from assigned is legal (courier went straight out)' );
		$this->ldb->tables['ig_shipments'][ (int) $draft['id'] ]['status'] = 'in_transit';
		$this->assert_true( $this->couriers->arrived( (int) $draft['id'], 1 ), 'a moving shipment can arrive too' );

		$this->assert_same( 'wrong_pin', $this->couriers->deliver( (int) $draft['id'], 1, '0000' )['error'], 'the PIN still gates' );
		$this->assert_true( $this->couriers->deliver( (int) $draft['id'], 1, '1234' )['ok'], 'arrival + PIN delivers' );

		$fresh = $this->shipment( 'draft' );
		$this->ldb->tables['ig_shipments'][ (int) $fresh['id'] ]['courier_id'] = 1;
		$this->assert_same( 'bad_state', $this->couriers->deliver( (int) $fresh['id'], 1, '1234' )['error'], 'delivery straight from draft is refused by the machine' );
	}

	public function test_terminal_states_accept_nothing(): void {
		$this->boot();

		foreach ( [ 'delivered', 'failed' ] as $terminal ) {
			foreach ( array_keys( LogisticsService::TRANSITIONS ) as $to ) {
				$this->assert_false( LogisticsService::can_transition( $terminal, $to ), "{$terminal} is terminal against {$to}" );
			}
		}
	}
}
