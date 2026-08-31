<?php
namespace IGBZ\Suite\Modules\MultiTenant\Admin;

use IGBZ\Suite\Modules\MultiTenant\Marketplace\CategoryMappingService;
use IGBZ\Suite\Modules\MultiTenant\Marketplace\MarketplaceSyncService;
use IGBZ\Suite\Support\Admin\Menu;
use IGBZ\Suite\Support\Admin\View;
use IGBZ\Suite\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Marketplace sync screen: category mapping editor + pending queue.
 */
final class MarketplacePage {

	public const SLUG = 'igbz-marketplaces';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 17 );
	}

	public function add_page(): void {
		Menu::add( self::SLUG, __( 'Marketplaces', 'igbz-suite' ), [ $this, 'render' ], Capabilities::MANAGE_SUITE );
	}

	public function render(): void {
		$this->handle_post();

		View::open(
			__( 'Marketplaces', 'igbz-suite' ),
			__( 'Digikala / Divar sync: category mapping and the pending queue.', 'igbz-suite' )
		);

		$this->render_mapping_form();
		$this->render_queue();

		View::close();
	}

	private function render_mapping_form(): void {
		$tenant = (int) igbz()->tenancy()->id();
		$rows   = $this->mappings()->all( $tenant );

		echo '<h2>' . esc_html__( 'Category mapping', 'igbz-suite' ) . '</h2>';
		echo '<form method="post" style="max-width:640px">';
		wp_nonce_field( 'igbz_marketplace_map' );
		printf( '<input type="hidden" name="igbz_mkt_action" value="map" />' );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Marketplace', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Local category', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Remote category', 'igbz-suite' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td><input type="text" name="remote[%3$d]" value="%4$s" class="regular-text" /></td></tr>',
				esc_html__( (string) $row['marketplace'], 'igbz-suite' ),
				esc_html( (string) $row['local_category'] ),
				(int) $row['id'],
				esc_attr( (string) $row['remote_category'] )
			);
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Add a new row:', 'igbz-suite' ) . '</p>';
		echo '<p><select name="new_marketplace"><option value="digikala">' . esc_html__( 'Digikala', 'igbz-suite' ) . '</option><option value="divar">' . esc_html__( 'Divar', 'igbz-suite' ) . '</option></select> ';
		printf( '<input type="text" name="new_local" placeholder="%s" /> ', esc_attr__( 'local category', 'igbz-suite' ) );
		printf( '<input type="text" name="new_remote" placeholder="%s" /> ', esc_attr__( 'remote category id', 'igbz-suite' ) );
		submit_button( __( 'Save mappings', 'igbz-suite' ), 'secondary', '', false );
		echo '</p></form>';
	}

	private function render_queue(): void {
		echo '<h2>' . esc_html__( 'Sync queue', 'igbz-suite' ) . '</h2>';

		$rows = $this->sync()->pending( 50 );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Queue is empty.', 'igbz-suite' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__( 'Product', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Market', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Status', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Attempts', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Error', 'igbz-suite' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			printf(
				'<tr><td>%1$d</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td></tr>',
				(int) $row['id'],
				esc_html( (string) $row['product_id'] ),
				esc_html( (string) $row['marketplace'] ),
				esc_html__( (string) $row['status'], 'igbz-suite' ),
				(int) $row['attempts'],
				esc_html( (string) $row['last_error'] )
			);
		}
		echo '</tbody></table>';
	}

	private function handle_post(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$action = isset( $_POST['igbz_mkt_action'] ) ? sanitize_key( (string) $_POST['igbz_mkt_action'] ) : '';
		// phpcs:enable
		if ( 'map' !== $action ) {
			return;
		}
		View::check_nonce( 'igbz_marketplace_map' );

		$tenant  = (int) igbz()->tenancy()->id();
		$service = $this->mappings();

		$remotes = isset( $_POST['remote'] ) && is_array( $_POST['remote'] ) ? $_POST['remote'] : [];
		foreach ( $remotes as $id => $remote ) {
			$row = igbz()->db()->row( 'SELECT * FROM ' . igbz()->db()->table( 'ig_category_mapping' ) . ' WHERE id = %d', max( 1, (int) $id ) );
			if ( $row ) {
				$service->set( $tenant, (string) $row['marketplace'], (string) $row['local_category'], sanitize_text_field( (string) $remote ) );
			}
		}

		$new_market = isset( $_POST['new_marketplace'] ) ? sanitize_key( (string) $_POST['new_marketplace'] ) : '';
		$new_local  = isset( $_POST['new_local'] ) ? sanitize_text_field( (string) $_POST['new_local'] ) : '';
		$new_remote = isset( $_POST['new_remote'] ) ? sanitize_text_field( (string) $_POST['new_remote'] ) : '';
		if ( '' !== $new_local && in_array( $new_market, [ 'digikala', 'divar' ], true ) ) {
			$service->set( $tenant, $new_market, $new_local, $new_remote );
		}

		View::notice( __( 'Mappings saved.', 'igbz-suite' ) );
	}

	private function mappings(): CategoryMappingService {
		return igbz()->get( 'marketplace.mappings' );
	}

	private function sync(): MarketplaceSyncService {
		return igbz()->get( 'marketplace.sync' );
	}
}
