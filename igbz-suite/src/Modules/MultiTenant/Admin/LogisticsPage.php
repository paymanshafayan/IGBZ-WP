<?php
namespace IGBZ\Suite\Modules\MultiTenant\Admin;

use IGBZ\Suite\Modules\MultiTenant\Logistics\HttpShippingAdapter;
use IGBZ\Suite\Modules\MultiTenant\Logistics\LogisticsService;
use IGBZ\Suite\Support\Admin\Menu;
use IGBZ\Suite\Support\Admin\View;
use IGBZ\Suite\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Shipments screen: create a shipment for an order, hand it to the carrier,
 * confirm delivery with the delivery PIN.
 */
final class LogisticsPage {

	public const SLUG = 'igbz-logistics';

	private const PER_PAGE = 30;

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 17 );
	}

	public function add_page(): void {
		Menu::add( self::SLUG, __( 'Logistics', 'igbz-suite' ), [ $this, 'render' ], Capabilities::MANAGE_SUITE );
	}

	public function render(): void {
		// Print labels directly when ?igbz_labels=<id> (window.open target).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['igbz_labels'] ) ) {
			$gid = (int) $_GET['igbz_labels'];
			echo igbz()->get( 'logistics.labels' )->render_labels( $gid, (int) igbz()->tenancy()->id() );
			exit;
		}

		$this->handle_post();

		View::open(
			__( 'Logistics', 'igbz-suite' ),
			__( 'Shipments, delivery PINs and carrier hand-off.', 'igbz-suite' )
		);

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( (string) $_GET['status'] ) : '';
		// phpcs:enable

		$this->render_create_form();
		$this->render_label_groups();
		$this->render_list( $status );

		View::close();
	}

	private function render_create_form(): void {
		$service = $this->logistics();
		echo '<h2>' . esc_html__( 'Create shipment', 'igbz-suite' ) . '</h2>';
		echo '<form method="post" style="max-width:520px">';
		wp_nonce_field( 'igbz_logistics_create' );
		printf( '<input type="hidden" name="igbz_log_action" value="create" />' );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="order_id">' . esc_html__( 'Order id', 'igbz-suite' ) . '</label></th><td><input type="number" id="order_id" name="order_id" min="1" required class="small-text" /></td></tr>';
		echo '<tr><th><label for="recipient_name">' . esc_html__( 'Recipient', 'igbz-suite' ) . '</label></th><td><input type="text" id="recipient_name" name="recipient_name" class="regular-text" /></td></tr>';
		echo '<tr><th><label for="recipient_phone">' . esc_html__( 'Phone', 'igbz-suite' ) . '</label></th><td><input type="text" id="recipient_phone" name="recipient_phone" class="regular-text" /></td></tr>';
		echo '<tr><th><label for="recipient_address">' . esc_html__( 'Address', 'igbz-suite' ) . '</label></th><td><textarea id="recipient_address" name="recipient_address" rows="2" class="large-text"></textarea></td></tr>';
		echo '<tr><th><label for="city">' . esc_html__( 'City', 'igbz-suite' ) . '</label></th><td><input type="text" id="city" name="city" class="regular-text" /></td></tr>';
		echo '<tr><th><label for="weight_kg">' . esc_html__( 'Weight (kg)', 'igbz-suite' ) . '</label></th><td><input type="number" id="weight_kg" name="weight_kg" min="0" step="0.1" value="0" class="small-text" /></td></tr>';
		echo '<tr><th><label for="is_cod">' . esc_html__( 'Cash on delivery', 'igbz-suite' ) . '</label></th><td><input type="checkbox" id="is_cod" name="is_cod" value="1" /></td></tr>';
		echo '</tbody></table>';

		// Quick preview of the route rules.
		$express = $service->categorize_route( 1, 'تهران', false );
		$heavy   = $service->categorize_route( 50, 'شیراز', false );
		printf( '<p class="description">%s</p>', esc_html( sprintf( 'Route preview — express: %s (%s IRT); heavy: %s (%s IRT).', $express['carrier'], number_format( $express['cost_irt'], 0 ), $heavy['carrier'], number_format( $heavy['cost_irt'], 0 ) ) ) );

		submit_button( __( 'Create shipment', 'igbz-suite' ) );
		echo '</form>';
	}

	private function render_label_groups(): void {
		$labels = igbz()->get( 'logistics.labels' );
		$groups = $labels->groups( (int) igbz()->tenancy()->id() );

		echo '<h2>' . esc_html__( 'Label printing', 'igbz-suite' ) . '</h2>';
		echo '<form method="post" style="max-width:520px">';
		wp_nonce_field( 'igbz_labels_create' );
		printf( '<input type="hidden" name="igbz_log_action" value="labels" />' );
		echo '<p><input type="text" name="group_title" class="regular-text" placeholder="' . esc_attr__( 'Group title', 'igbz-suite' ) . '" required /> ';
		echo '<select name="route_type"><option value="">' . esc_html__( 'All routes', 'igbz-suite' ) . '</option><option value="express">express</option><option value="national">national</option><option value="heavy">heavy</option></select> ';
		submit_button( __( 'Create group & print', 'igbz-suite' ), 'secondary', '', false );
		echo '</p></form>';

		if ( $groups ) {
			echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__( 'Title', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Status', 'igbz-suite' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $groups as $g ) {
				printf(
					'<tr><td>%1$d</td><td>%2$s</td><td>%3$s</td><td><a class="button button-small" target="_blank" href="%4$s">%5$s</a></td></tr>',
					(int) $g['id'],
					esc_html( (string) $g['title'] ),
					esc_html( (string) $g['status'] ),
					esc_url( add_query_arg( [ 'igbz_labels' => (int) $g['id'] ], admin_url( 'admin.php' ) ) ),
					esc_html__( 'Print', 'igbz-suite' )
				);
			}
			echo '</tbody></table>';
		}
	}

	private function render_list( string $status ): void {
		$service  = $this->logistics();
		$rows     = $service->shipments( (int) igbz()->tenancy()->id(), $status, self::PER_PAGE );

		echo '<h2>' . esc_html__( 'Shipments', 'igbz-suite' ) . '</h2>';

		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No shipments yet.', 'igbz-suite' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__( 'Order', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Route', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Carrier', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Tracking', 'igbz-suite' ) . '</th><th>' . esc_html__( 'PIN', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Status', 'igbz-suite' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			printf(
				'<tr><td>#%1$d</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td><td>%7$s</td><td>',
				(int) $row['id'],
				esc_html( (string) $row['order_id'] ),
				esc_html( (string) $row['route_type'] ),
				esc_html( (string) $row['carrier'] ),
				esc_html( (string) $row['tracking_code'] ),
				esc_html( (string) $row['delivery_pin'] ),
				esc_html( (string) $row['status'] )
			);

			if ( LogisticsService::STATUS_DRAFT === $row['status'] ) {
				printf(
					'<form method="post" style="display:inline">%s<input type="hidden" name="igbz_log_action" value="register" /><input type="hidden" name="shipment_id" value="%d" /><button class="button button-small">%s</button></form> ',
					wp_nonce_field( 'igbz_logistics_register', '_wpnonce', true, false ),
					(int) $row['id'],
					esc_html__( 'Register', 'igbz-suite' )
				);
			}
			if ( LogisticsService::STATUS_REGISTERED === $row['status'] ) {
				printf(
					'<form method="post" style="display:inline">%s<input type="hidden" name="igbz_log_action" value="deliver" /><input type="hidden" name="shipment_id" value="%d" /><button class="button button-small">%s</button></form>',
					wp_nonce_field( 'igbz_logistics_deliver', '_wpnonce', true, false ),
					(int) $row['id'],
					esc_html__( 'Mark delivered', 'igbz-suite' )
				);
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function handle_post(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$action = isset( $_POST['igbz_log_action'] ) ? sanitize_key( (string) $_POST['igbz_log_action'] ) : '';
		// phpcs:enable
		if ( '' === $action ) {
			return;
		}

		$service = $this->logistics();

		if ( 'create' === $action ) {
			View::check_nonce( 'igbz_logistics_create' );
			$id = $service->create_shipment(
				[
					'tenant_id'         => (int) igbz()->tenancy()->id(),
					'order_id'          => (int) ( $_POST['order_id'] ?? 0 ),
					'recipient_name'    => sanitize_text_field( (string) ( $_POST['recipient_name'] ?? '' ) ),
					'recipient_phone'   => sanitize_text_field( (string) ( $_POST['recipient_phone'] ?? '' ) ),
					'recipient_address' => sanitize_textarea_field( (string) ( $_POST['recipient_address'] ?? '' ) ),
					'city'              => sanitize_text_field( (string) ( $_POST['city'] ?? '' ) ),
					'weight_kg'         => (float) ( $_POST['weight_kg'] ?? 0 ),
					'is_cod'            => (int) ( isset( $_POST['is_cod'] ) ? 1 : 0 ),
				]
			);
			View::notice( $id > 0 ? __( 'Shipment created.', 'igbz-suite' ) : __( 'Could not create shipment.', 'igbz-suite' ), $id > 0 ? 'success' : 'error' );
			return;
		}

		if ( 'register' === $action ) {
			View::check_nonce( 'igbz_logistics_register' );
			$id    = (int) ( $_POST['shipment_id'] ?? 0 );
			$adapter = $this->adapter();
			if ( ! $adapter ) {
				View::notice( __( 'Configure a shipping carrier (Settings → Logistics).', 'igbz-suite' ), 'error' );
				return;
			}
			$result = $service->register_with_carrier( $id, $adapter );
			View::notice( $result['ok'] ? sprintf( 'Tracking: %s', $result['tracking_code'] ) : $result['message'], $result['ok'] ? 'success' : 'error' );
			return;
		}

		if ( 'labels' === $action ) {
			View::check_nonce( 'igbz_labels_create' );
			$id = igbz()->get( 'logistics.labels' )->create_group(
				(int) igbz()->tenancy()->id(),
				get_current_user_id(),
				sanitize_text_field( (string) ( $_POST['group_title'] ?? '' ) ),
				sanitize_key( (string) ( $_POST['route_type'] ?? '' ) )
			);
			View::notice( $id > 0 ? __( 'Label group created.', 'igbz-suite' ) : __( 'No shipments matched.', 'igbz-suite' ), $id > 0 ? 'success' : 'error' );
			return;
		}

		if ( 'deliver' === $action ) {
			View::check_nonce( 'igbz_logistics_deliver' );
			$ok = $service->mark_delivered( (int) ( $_POST['shipment_id'] ?? 0 ) );
			View::notice( $ok ? __( 'Shipment delivered.', 'igbz-suite' ) : __( 'Could not confirm delivery.', 'igbz-suite' ), $ok ? 'success' : 'error' );
		}
	}

	private function logistics(): LogisticsService {
		return igbz()->get( 'logistics' );
	}

	private function adapter(): ?HttpShippingAdapter {
		$settings = igbz()->settings();
		if ( '' !== $settings->string( 'logistics.tapin_api_key' ) ) {
			return new HttpShippingAdapter( 'tapin', 'Tapin', 'logistics.tapin', igbz()->get( 'http' ) );
		}
		if ( '' !== $settings->string( 'logistics.postex_api_key' ) ) {
			return new HttpShippingAdapter( 'postex', 'Postex', 'logistics.postex', igbz()->get( 'http' ) );
		}
		return null;
	}
}
