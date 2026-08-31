<?php
namespace IGBZ\Suite\Modules\MultiTenant\Admin;

use IGBZ\Suite\Modules\MultiTenant\MasterPayment\MasterPaymentService;
use IGBZ\Suite\Support\Admin\Menu;
use IGBZ\Suite\Support\Admin\View;
use IGBZ\Suite\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Master payment (escrow) screen: payments, disputes, agreement.
 */
final class MasterPaymentPage {

	public const SLUG = 'igbz-master-payment';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 17 );
	}

	public function add_page(): void {
		Menu::add( self::SLUG, __( 'Master payment', 'igbz-suite' ), [ $this, 'render' ], Capabilities::MANAGE_SUITE );
	}

	public function render(): void {
		$this->handle_post();

		View::open(
			__( 'Master payment (escrow)', 'igbz-suite' ),
			__( 'Held funds, releases and disputes. The digital agreement is the precondition.', 'igbz-suite' )
		);

		$tenant = (int) igbz()->tenancy()->id();
		$master = igbz()->get( 'master.payment' );

		if ( ! $master->has_agreement( $tenant ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'No digital agreement accepted yet — the master gateway is not active for this store.', 'igbz-suite' ) . '</p>';
			echo '<form method="post" style="margin-top:8px">';
			wp_nonce_field( 'igbz_master_agree' );
			printf( '<input type="hidden" name="igbz_mp_action" value="agree" />' );
			submit_button( __( 'Accept the digital agreement', 'igbz-suite' ), 'secondary', '', false );
			echo '</form></div>';
		}

		echo '<h2>' . esc_html__( 'Payments', 'igbz-suite' ) . '</h2>';
		$rows = $master->payments( $tenant, 50 );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No master payments yet.', 'igbz-suite' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__( 'Order', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Phase', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Amount', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Status', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Hold until', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Released', 'igbz-suite' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				printf(
					'<tr><td>%1$d</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td><td>%7$s</td></tr>',
					(int) $row['id'],
					esc_html( (string) $row['order_id'] ),
					esc_html( (string) $row['phase'] ),
					esc_html( number_format( (float) $row['amount'], 0 ) ),
					esc_html__( (string) $row['status'], 'igbz-suite' ),
					esc_html( (string) ( $row['hold_until'] ?? '' ) ),
					esc_html( (string) ( $row['released_at'] ?? '' ) )
				);
			}
			echo '</tbody></table>';
		}

		echo '<h2>' . esc_html__( 'Disputes', 'igbz-suite' ) . '</h2>';
		$disputes = $master->disputes( $tenant, 50 );
		if ( ! $disputes ) {
			echo '<p>' . esc_html__( 'No disputes.', 'igbz-suite' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__( 'Payment', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Source', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Reason', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Status', 'igbz-suite' ) . '</th></tr></thead><tbody>';
			foreach ( $disputes as $d ) {
				printf(
					'<tr><td>%1$d</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>',
					(int) $d['id'],
					esc_html( (string) $d['payment_id'] ),
					esc_html( (string) $d['source'] ),
					esc_html( (string) $d['reason'] ),
					esc_html__( (string) $d['status'], 'igbz-suite' )
				);
			}
			echo '</tbody></table>';
		}

		echo '<h2>' . esc_html__( 'Withdrawals', 'igbz-suite' ) . '</h2>';
		$wds = $master->withdrawals( $tenant, 50 );
		if ( ! $wds ) {
			echo '<p>' . esc_html__( 'No withdrawal requests.', 'igbz-suite' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__( 'User', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Amount', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Method', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Status', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Detail', 'igbz-suite' ) . '</th></tr></thead><tbody>';
			foreach ( $wds as $w ) {
				printf(
					'<tr><td>%1$d</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td></tr>',
					(int) $w['id'],
					esc_html( (string) $w['user_id'] ),
					esc_html( number_format( (float) $w['amount'], 0 ) ),
					esc_html( (string) $w['method'] ),
					esc_html__( (string) $w['status'], 'igbz-suite' ),
					esc_html( (string) $w['detail'] )
				);
			}
			echo '</tbody></table>';
		}

		View::close();
	}

	private function handle_post(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$action = isset( $_POST['igbz_mp_action'] ) ? sanitize_key( (string) $_POST['igbz_mp_action'] ) : '';
		if ( 'agree' !== $action ) {
			return;
		}
		View::check_nonce( 'igbz_master_agree' );
		$result = igbz()->get( 'master.payment' )->accept_agreement( (int) igbz()->tenancy()->id(), get_current_user_id() );
		View::notice( $result['ok'] ? __( 'Agreement accepted — master gateway active.', 'igbz-suite' ) : __( 'Failed.', 'igbz-suite' ), $result['ok'] ? 'success' : 'error' );
	}
}
