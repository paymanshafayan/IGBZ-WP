<?php
namespace IGBZ\Suite\Modules\RestApi\Controllers;

defined( 'ABSPATH' ) || exit;

/**
 * Courier app API (phase 7): shipments, sequential routing, arrived,
 * barcode lookup, PIN delivery, COD paths, live tracking and chat.
 * All routes are scoped to the authenticated courier's own shipments.
 */
final class CourierController extends BaseController {

	public function register_routes(): void {
		$ns   = self::NAMESPACE;
		$auth = [ $this, 'is_logged_in' ];

		register_rest_route( $ns, '/courier/me', $this->route( 'GET', [ $this, 'me' ], $auth ) );
		register_rest_route( $ns, '/courier/shipments', $this->route( 'GET', [ $this, 'shipments' ], $auth ) );
		register_rest_route( $ns, '/courier/shipments/(?P<barcode>[A-Za-z0-9-]+)', $this->route( 'GET', [ $this, 'by_barcode' ], $auth ) );
		register_rest_route( $ns, '/courier/routes/plan', $this->route( 'POST', [ $this, 'plan_route' ], $auth ) );
		register_rest_route( $ns, '/courier/shipments/(?P<id>\d+)/arrived', $this->route( 'POST', [ $this, 'arrived' ], $auth ) );
		register_rest_route( $ns, '/courier/shipments/(?P<id>\d+)/deliver', $this->route( 'POST', [ $this, 'deliver' ], $auth ) );
		register_rest_route( $ns, '/courier/shipments/(?P<id>\d+)/cod', $this->route( 'POST', [ $this, 'cod' ], $auth ) );
		register_rest_route( $ns, '/courier/tracking/(?P<id>\d+)', $this->route( 'POST', [ $this, 'track' ], $auth ) );
		register_rest_route( $ns, '/courier/chat/(?P<id>\d+)', $this->route( 'GET', [ $this, 'chat_get' ], $auth ) );
		register_rest_route( $ns, '/courier/chat/(?P<id>\d+)/send', $this->route( 'POST', [ $this, 'chat_send' ], $auth ) );

		// Customer-facing: shipment status + live tracking + chat.
		register_rest_route( $ns, '/shipments/(?P<id>\d+)/status', $this->route( 'GET', [ $this, 'shipment_status' ], $auth ) );
		register_rest_route( $ns, '/shipments/(?P<id>\d+)/tracking', $this->route( 'GET', [ $this, 'shipment_tracking' ], $auth ) );
		register_rest_route( $ns, '/checkout/cod-pay', $this->route( 'POST', [ $this, 'cod_app_pay' ], $auth ) );
	}

	private function courier(): \IGBZ\Suite\Modules\MultiTenant\Logistics\CourierService {
		return igbz()->get( 'logistics.courier' );
	}

	private function courier_or_error(): array|false {
		$courier = $this->courier()->courier_for_user( get_current_user_id() );
		if ( ! $courier ) {
			return false;
		}
		return $courier;
	}

	public function me(): \WP_REST_Response {
		$courier = $this->courier_or_error();
		if ( ! $courier ) {
			return $this->fail( 'not_a_courier', __( 'This account is not an active courier.', 'igbz-suite' ), 403 );
		}
		return $this->ok( $courier );
	}

	public function shipments( \WP_REST_Request $request ): \WP_REST_Response {
		$courier = $this->courier_or_error();
		if ( ! $courier ) {
			return $this->fail( 'not_a_courier', __( 'Not a courier.', 'igbz-suite' ), 403 );
		}
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$rows   = $this->courier()->my_shipments( (int) $courier['id'], $status );
		foreach ( $rows as &$r ) {
			unset( $r['delivery_pin'] ); // never expose the PIN
		}
		return $this->ok( [ 'items' => $rows ] );
	}

	public function by_barcode( \WP_REST_Request $request ): \WP_REST_Response {
		$courier = $this->courier_or_error();
		if ( ! $courier ) {
			return $this->fail( 'not_a_courier', __( 'Not a courier.', 'igbz-suite' ), 403 );
		}
		$row = $this->courier()->by_barcode( (string) $request->get_param( 'barcode' ), (int) $courier['id'] );
		if ( ! $row ) {
			return $this->fail( 'not_found', __( 'Shipment not found for this barcode.', 'igbz-suite' ), 404 );
		}
		unset( $row['delivery_pin'] );
		return $this->ok( $row );
	}

	public function plan_route(): \WP_REST_Response {
		$courier = $this->courier_or_error();
		if ( ! $courier ) {
			return $this->fail( 'not_a_courier', __( 'Not a courier.', 'igbz-suite' ), 403 );
		}
		$result = $this->courier()->plan_route( (int) $courier['id'], (int) $courier['tenant_id'] );
		return $this->ok( $result );
	}

	public function arrived( \WP_REST_Request $request ): \WP_REST_Response {
		$courier = $this->courier_or_error();
		if ( ! $courier ) {
			return $this->fail( 'not_a_courier', __( 'Not a courier.', 'igbz-suite' ), 403 );
		}
		$ok = $this->courier()->arrived( (int) $request->get_param( 'id' ), (int) $courier['id'] );
		return $ok ? $this->ok( [ 'ok' => true ] ) : $this->fail( 'not_found', __( 'Shipment not found.', 'igbz-suite' ), 404 );
	}

	public function deliver( \WP_REST_Request $request ): \WP_REST_Response {
		$courier = $this->courier_or_error();
		if ( ! $courier ) {
			return $this->fail( 'not_a_courier', __( 'Not a courier.', 'igbz-suite' ), 403 );
		}
		$result = $this->courier()->deliver(
			(int) $request->get_param( 'id' ),
			(int) $courier['id'],
			(string) $request->get_param( 'pin' )
		);
		return $result['ok'] ? $this->ok( [ 'ok' => true ] ) : $this->fail( $result['error'], __( 'Delivery could not be confirmed.', 'igbz-suite' ), 400 );
	}

	public function cod( \WP_REST_Request $request ): \WP_REST_Response {
		$courier = $this->courier_or_error();
		if ( ! $courier ) {
			return $this->fail( 'not_a_courier', __( 'Not a courier.', 'igbz-suite' ), 403 );
		}
		$result = $this->courier()->cod(
			(int) $request->get_param( 'id' ),
			(int) $courier['id'],
			sanitize_key( (string) $request->get_param( 'method' ) ),
			sanitize_text_field( (string) $request->get_param( 'card_ref' ) )
		);
		return $result['ok'] ? $this->ok( $result ) : $this->fail( $result['error'], __( 'COD failed.', 'igbz-suite' ), 400 );
	}

	public function track( \WP_REST_Request $request ): \WP_REST_Response {
		$courier = $this->courier_or_error();
		if ( ! $courier ) {
			return $this->fail( 'not_a_courier', __( 'Not a courier.', 'igbz-suite' ), 403 );
		}
		$this->courier()->track(
			(int) $request->get_param( 'id' ),
			(float) $request->get_param( 'lat' ),
			(float) $request->get_param( 'lng' ),
			(int) $courier['tenant_id']
		);
		return $this->ok( [ 'ok' => true ] );
	}

	public function chat_get( \WP_REST_Request $request ): \WP_REST_Response {
		$courier = $this->courier_or_error();
		if ( ! $courier ) {
			return $this->fail( 'not_a_courier', __( 'Not a courier.', 'igbz-suite' ), 403 );
		}
		return $this->ok( [ 'items' => $this->courier()->chat( (int) $request->get_param( 'id' ), (int) $courier['id'] ) ] );
	}

	public function chat_send( \WP_REST_Request $request ): \WP_REST_Response {
		$courier = $this->courier_or_error();
		if ( ! $courier ) {
			return $this->fail( 'not_a_courier', __( 'Not a courier.', 'igbz-suite' ), 403 );
		}
		$id = (int) $this->courier()->send_chat(
			(int) $request->get_param( 'id' ),
			'courier',
			sanitize_textarea_field( (string) $request->get_param( 'body' ) ),
			(int) $courier['tenant_id'],
			(int) $courier['id']
		);
		return $this->ok( [ 'ok' => true, 'message_id' => $id ], 201 );
	}

	public function shipment_status( \WP_REST_Request $request ): \WP_REST_Response {
		$row = igbz()->db()->row(
			'SELECT status, tracking_code, updated_at FROM ' . igbz()->db()->table( 'ig_shipments' ) . ' WHERE id = %d AND tenant_id = %d',
			(int) $request->get_param( 'id' ),
			igbz()->tenancy()->id()
		);
		// The tracking code is the customer's bearer proof for a shipment they do not own by user id.
		if ( $row && ! hash_equals( (string) $row['tracking_code'], (string) $request->get_param( 'tracking_code' ) ) ) {
			$row = null;
		}
		return $row ? $this->ok( $row ) : $this->fail( 'not_found', __( 'Shipment not found.', 'igbz-suite' ), 404 );
	}

	public function shipment_tracking( \WP_REST_Request $request ): \WP_REST_Response {
		$row = igbz()->db()->row(
			'SELECT id, tracking_code FROM ' . igbz()->db()->table( 'ig_shipments' ) . ' WHERE id = %d AND tenant_id = %d',
			(int) $request->get_param( 'id' ),
			igbz()->tenancy()->id()
		);
		// Same bearer rule as the status endpoint: no tracking code, no live GPS trail.
		if ( ! $row || ! hash_equals( (string) $row['tracking_code'], (string) $request->get_param( 'tracking_code' ) ) ) {
			return $this->fail( 'not_found', __( 'Shipment not found.', 'igbz-suite' ), 404 );
		}
		return $this->ok( [ 'items' => $this->courier()->tracking( (int) $row['id'] ) ] );
	}

	public function cod_app_pay( \WP_REST_Request $request ): \WP_REST_Response {
		$barcode = (string) $request->get_param( 'shipment_barcode' );
		$shipment = igbz()->db()->row(
			'SELECT * FROM ' . igbz()->db()->table( 'ig_shipments' ) . ' WHERE barcode = %s AND tenant_id = %d LIMIT 1',
			$barcode,
			igbz()->tenancy()->id()
		);
		if ( ! $shipment ) {
			return $this->fail( 'not_found', __( 'Shipment not found.', 'igbz-suite' ), 404 );
		}
		$result = $this->courier()->cod_app_paid( (int) $shipment['id'], 'app:' . time() );
		return $result['ok'] ? $this->ok( [ 'ok' => true ] ) : $this->fail( $result['error'], __( 'COD failed.', 'igbz-suite' ), 400 );
	}
}
