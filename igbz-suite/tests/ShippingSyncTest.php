<?php
/**
 * Phase 45 — logistics provider sync: raw provider vocabularies map into the machine, a
 * target is reached by walking legal rungs (never skipping), the sweep is the retry, and
 * carrier callbacks must carry a valid HMAC before they change anything.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\MultiTenant\Logistics\LogisticsService;
use IGBZ\Suite\Modules\MultiTenant\Logistics\ShippingAdapterInterface;
use IGBZ\Suite\Modules\MultiTenant\Logistics\ShippingSyncService;
use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;

/** In-memory engine for shipments. */
final class SyncDb extends wpdb {

	/** @var array<string,array<int,array<string,mixed>>> */
	public array $tables = [ 'ig_shipments' => [] ];

	private int $next_id = 1;

	/** @param array<string,mixed> $row */
	public function seed( string $table, array $row ): int {
		$id                          = $this->next_id++;
		$row['id']                   = $id;
		$this->tables[ $table ][ $id ] = $row;

		return $id;
	}

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'ig_shipments' ) && preg_match( "/tracking_code = '([^']*)'/", $sql, $m ) ) {
			foreach ( $this->tables['ig_shipments'] as $row ) {
				if ( (string) $row['tracking_code'] === $m[1] ) {
					return $row;
				}
			}
			return null;
		}

		return parent::get_row( $sql, $output );
	}

	public function get_results( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'ig_shipments' ) ) {
			$out = [];
			foreach ( $this->tables['ig_shipments'] as $row ) {
				if ( '' === (string) $row['tracking_code'] ) {
					continue;
				}
				if ( ! in_array( (string) $row['status'], [ 'registered', 'in_transit', 'at_destination' ], true ) ) {
					continue;
				}
				$out[] = $row;
			}
			usort( $out, static fn ( $a, $b ): int => (int) $a['id'] <=> (int) $b['id'] );
			if ( preg_match( '/LIMIT (\d+)/', $sql, $m ) ) {
				$out = array_slice( $out, 0, (int) $m[1] );
			}
			return $out;
		}

		return parent::get_results( $sql, $output );
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		$this->queries[] = 'UPDATE ' . $table;

		if ( str_contains( $table, 'ig_shipments' ) ) {
			$changed = 0;
			foreach ( $this->tables['ig_shipments'] as $id => $row ) {
				$hit = true;
				foreach ( $where as $column => $value ) {
					if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) {
						$hit = false;
						break;
					}
				}
				if ( $hit ) {
					$this->tables['ig_shipments'][ $id ] = array_merge( $row, $data );
					++$changed;
				}
			}
			return $changed;
		}

		return parent::update( $table, $data, $where, $format, $where_format );
	}
}

/** An adapter whose track() answer is scripted. */
final class ScriptedCarrier implements ShippingAdapterInterface {

	public function __construct( public array $answer = [ 'status' => 'in_transit', 'detail' => '' ] ) {}

	public function id(): string {
		return 'carrier';
	}

	public function title(): string {
		return 'Carrier';
	}

	public function is_configured(): bool {
		return true;
	}

	public function register( array $shipment ): array {
		return [ 'ok' => true, 'tracking_code' => 'T1', 'message' => '' ];
	}

	public function track( string $tracking_code ): array {
		return $this->answer;
	}
}

final class ShippingSyncTest extends TestCase {

	private Db $db;
	private SyncDb $sdb;
	private ShippingSyncService $sync;

	private function boot(): void {
		igbz_test_reset_settings();

		$this->sdb         = new SyncDb();
		$GLOBALS['wpdb']   = $this->sdb;

		$this->db = new Db();
		$ref = new ReflectionProperty( Db::class, 'is_sqlite' );
		$ref->setValue( $this->db, true );

		$this->sync = new ShippingSyncService( $this->db, igbz()->settings(), new IGBZ\Suite\Support\Logger( igbz()->settings() ) );
	}

	private function shipment( string $status, string $tracking = 'T1' ): array {
		$id = $this->sdb->seed( 'ig_shipments', [
			'tenant_id' => 0, 'order_id' => 1, 'carrier' => 'c', 'tracking_code' => $tracking,
			'delivery_pin' => '1', 'status' => $status, 'route_type' => 'national', 'cost_irt' => 1,
			'is_cod' => 0, 'courier_id' => 0, 'recipient_name' => '', 'recipient_phone' => '', 'recipient_address' => '',
			'meta' => '{}', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		] );

		return $this->sdb->tables['ig_shipments'][ $id ];
	}

	public function run(): void {
		$this->test_provider_vocabularies_map_into_the_machine();
		$this->test_targets_are_reached_by_walking_legal_rungs();
		$this->test_the_sweep_is_the_retry_and_unknowns_stay();
		$this->test_callbacks_need_a_valid_signature();
	}

	public function test_provider_vocabularies_map_into_the_machine(): void {
		$this->boot();

		$this->assert_same( LogisticsService::STATUS_IN_TRANSIT, $this->sync->map_status( 'Shipping' ), 'carrier words map case-insensitively' );
		$this->assert_same( LogisticsService::STATUS_DELIVERED, $this->sync->map_status( 'distribute' ), 'provider dialects map too' );
		$this->assert_same( LogisticsService::STATUS_FAILED, $this->sync->map_status( 'returned' ), 'returns are failures' );
		$this->assert_same( '', $this->sync->map_status( 'teleported' ), 'unknown words change nothing' );

		igbz()->settings()->set( 'logistics.status_map_weird', LogisticsService::STATUS_AT_DESTINATION );
		$this->assert_same( LogisticsService::STATUS_AT_DESTINATION, $this->sync->map_status( 'weird' ), 'shops can teach the map' );
	}

	public function test_targets_are_reached_by_walking_legal_rungs(): void {
		$this->boot();

		$path = ShippingSyncService::path( LogisticsService::STATUS_REGISTERED, LogisticsService::STATUS_DELIVERED );
		$this->assert_same( [ 'in_transit', 'at_destination', 'delivered' ], $path, 'the path walks every rung' );

		$this->assert_same( [], ShippingSyncService::path( 'delivered', 'in_transit' ), 'terminal states have no path' );
		$this->assert_same( [ 'assigned', 'at_destination', 'delivered' ], ShippingSyncService::path( 'draft', 'delivered' ), 'a draft reaches delivery only through the courier lane' );
		$this->assert_false( LogisticsService::can_transition( 'draft', 'delivered' ), 'but never in a single step' );

		$row = $this->shipment( 'registered' );
		$this->assert_true( $this->sync->advance_to( $row, LogisticsService::STATUS_DELIVERED ), 'a carrier jumping to delivered still walks the ladder' );
		$this->assert_same( 'delivered', $this->sdb->tables['ig_shipments'][ (int) $row['id'] ]['status'], 'the ladder ends where asked' );

		$delivered = $this->sdb->tables['ig_shipments'][ (int) $row['id'] ];
		$this->assert_false( $this->sync->advance_to( $delivered, LogisticsService::STATUS_IN_TRANSIT ), 'a delivered shipment never moves back' );
	}

	public function test_the_sweep_is_the_retry_and_unknowns_stay(): void {
		$this->boot();
		$row = $this->shipment( 'registered' );

		$adapter = new ScriptedCarrier( [ 'status' => 'unknown', 'detail' => 'timeout' ] );
		$first   = $this->sync->sync_tracking( $adapter );
		$this->assert_same( 1, $first['scanned'], 'the sweep asks the adapter' );
		$this->assert_same( 0, $first['advanced'], 'an unknown answer advances nothing' );
		$this->assert_same( 'registered', $this->sdb->tables['ig_shipments'][ (int) $row['id'] ]['status'], 'the shipment waits for the next try' );

		$adapter->answer = [ 'status' => 'shipping', 'detail' => '' ];
		$second = $this->sync->sync_tracking( $adapter );
		$this->assert_same( 1, $second['advanced'], 'the retry succeeds where the first try could not' );
		$this->assert_same( 'in_transit', $this->sdb->tables['ig_shipments'][ (int) $row['id'] ]['status'], 'the machine moved one rung' );
	}

	public function test_callbacks_need_a_valid_signature(): void {
		$this->boot();
		igbz()->settings()->set( 'logistics.callback_secret', 'carrier-secret' );
		$row  = $this->shipment( 'in_transit' );
		$body = '{"tracking":"T1","status":"arrived"}';

		$forged = $this->sync->apply_carrier_callback( 'T1', 'arrived', $body, 'deadbeef' );
		$this->assert_false( $forged['ok'], 'a forged callback is refused' );
		$this->assert_same( 'bad_signature', $forged['error'], 'the refusal names the signature' );

		$good = $this->sync->apply_carrier_callback( 'T1', 'arrived', $body, Crypto::hmac( $body, 'carrier-secret' ) );
		$this->assert_true( $good['ok'], 'a signed callback lands' );
		$this->assert_true( $good['advanced'], 'the machine advanced' );
		$this->assert_same( 'at_destination', $this->sdb->tables['ig_shipments'][ (int) $row['id'] ]['status'], 'arrival landed through the callback' );

		$weird = $this->sync->apply_carrier_callback( 'T1', 'hovercraft', $body, Crypto::hmac( $body, 'carrier-secret' ) );
		$this->assert_false( $weird['ok'], 'an unmapped verdict is refused honestly' );
		$this->assert_same( 'unmapped_status', $weird['error'], 'the refusal names the gap' );
	}
}
