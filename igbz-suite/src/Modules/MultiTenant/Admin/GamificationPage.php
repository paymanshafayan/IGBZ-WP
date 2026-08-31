<?php
namespace IGBZ\Suite\Modules\MultiTenant\Admin;

use IGBZ\Suite\Modules\MultiTenant\Gamification\AbandonedCartService;
use IGBZ\Suite\Modules\MultiTenant\Gamification\GamificationService;
use IGBZ\Suite\Support\Admin\Menu;
use IGBZ\Suite\Support\Admin\View;
use IGBZ\Suite\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Gamification screen: spin-wheel test + abandoned-cart list.
 */
final class GamificationPage {

	public const SLUG = 'igbz-gamification';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 17 );
	}

	public function add_page(): void {
		Menu::add( self::SLUG, __( 'Gamification', 'igbz-suite' ), [ $this, 'render' ], Capabilities::MANAGE_SUITE );
	}

	public function render(): void {
		$this->handle_post();

		View::open(
			__( 'Gamification', 'igbz-suite' ),
			__( 'Spin & win and abandoned-cart recovery.', 'igbz-suite' )
		);

		echo '<h2>' . esc_html__( 'Spin wheel', 'igbz-suite' ) . '</h2>';
		echo '<form method="post" style="max-width:420px">';
		wp_nonce_field( 'igbz_gami_spin' );
		printf( '<input type="hidden" name="igbz_gami_action" value="spin" />' );
		printf( '<p>%s <input type="number" name="user_id" min="1" value="%d" class="small-text" required /></p>',
			esc_html__( 'Spin for user id', 'igbz-suite' ),
			get_current_user_id()
		);
		submit_button( __( 'Spin (test)', 'igbz-suite' ) );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Abandoned carts', 'igbz-suite' ) . '</h2>';
		$rows = $this->carts()->carts( 50 );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No carts tracked yet.', 'igbz-suite' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__( 'User', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Total', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Status', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Coupon', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Reminded', 'igbz-suite' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				printf(
					'<tr><td>%1$d</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td></tr>',
					(int) $row['id'],
					esc_html( (string) $row['user_id'] ),
					esc_html( number_format( (float) $row['cart_total'], 0 ) ),
					esc_html__( (string) $row['status'], 'igbz-suite' ),
					esc_html( (string) $row['coupon_code'] ),
					esc_html( (string) ( $row['reminder_sent_at'] ?? '' ) )
				);
			}
			echo '</tbody></table>';
		}

		View::close();
	}

	private function handle_post(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$action = isset( $_POST['igbz_gami_action'] ) ? sanitize_key( (string) $_POST['igbz_gami_action'] ) : '';
		if ( 'spin' !== $action ) {
			return;
		}
		View::check_nonce( 'igbz_gami_spin' );

		$result = $this->spin()->spin( max( 1, (int) ( $_POST['user_id'] ?? 0 ) ) );
		View::notice(
			$result['ok'] ? sprintf( 'Coupon %s — %s%% off', $result['coupon_code'], (string) $result['percent'] ) : $result['message'],
			$result['ok'] ? 'success' : 'error'
		);
	}

	private function spin(): GamificationService {
		return igbz()->get( 'gamification' );
	}

	private function carts(): AbandonedCartService {
		return igbz()->get( 'gamification.carts' );
	}
}
