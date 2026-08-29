<?php
namespace IGBZ\Suite\Modules\MultiTenant\Admin;

use IGBZ\Suite\Modules\MultiTenant\Seo\AdNetworkService;
use IGBZ\Suite\Modules\MultiTenant\Seo\ProductFeedService;
use IGBZ\Suite\Modules\MultiTenant\Seo\SeoService;
use IGBZ\Suite\Support\Admin\Menu;
use IGBZ\Suite\Support\Admin\View;
use IGBZ\Suite\Support\Capabilities;
use IGBZ\Suite\Support\TenantScope;

defined( 'ABSPATH' ) || exit;

/**
 * SEO screen: meta generation tool, retargeting feed links, advertorial.
 */
final class SeoPage {

	public const SLUG = 'igbz-seo';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 17 );
		add_action( 'template_redirect', [ $this, 'serve_feed' ] );
	}

	/** Serve the retargeting feeds at ?igbz_feed=yektanet|tapsell. */
	public function serve_feed(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$feed = isset( $_GET['igbz_feed'] ) ? sanitize_key( (string) $_GET['igbz_feed'] ) : '';
		if ( '' === $feed ) {
			return;
		}
		$service = new ProductFeedService();
		if ( 'yektanet' === $feed ) {
			header( 'Content-Type: application/xml; charset=utf-8' );
			echo $service->xml();
			exit;
		}
		if ( 'tapsell' === $feed ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode( $service->json() );
			exit;
		}
	}

	public function add_page(): void {
		Menu::add( self::SLUG, __( 'SEO & ads', 'igbz-suite' ), [ $this, 'render' ], Capabilities::MANAGE_SUITE );
	}

	public function render(): void {
		$this->handle_post();

		View::open(
			__( 'SEO & advertising', 'igbz-suite' ),
			__( 'Meta generation, retargeting feeds and advertorial publishing.', 'igbz-suite' )
		);

		echo '<h2>' . esc_html__( 'Product meta', 'igbz-suite' ) . '</h2>';
		echo '<form method="post" style="max-width:560px">';
		wp_nonce_field( 'igbz_seo_meta' );
		printf( '<input type="hidden" name="igbz_seo_action" value="meta" />' );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="pprod">' . esc_html__( 'Product (optional save)', 'igbz-suite' ) . '</label></th><td><select id="pprod" name="product_id"><option value="0">' . esc_html__( '— preview only —', 'igbz-suite' ) . '</option>';
		foreach ( wc_get_products( [ 'limit' => 100, 'status' => 'publish' ] ) as $prod ) {
			printf( '<option value="%1$d">%2$s</option>', (int) $prod->get_id(), esc_html( (string) $prod->get_name() ) );
		}
		echo '</select></td></tr>';
		echo '<tr><th><label for="pname">' . esc_html__( 'Product name', 'igbz-suite' ) . '</label></th><td><input type="text" id="pname" name="name" class="regular-text" required /></td></tr>';
		echo '<tr><th><label for="pdesc">' . esc_html__( 'Description', 'igbz-suite' ) . '</label></th><td><textarea id="pdesc" name="description" rows="3" class="large-text"></textarea></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Generate meta', 'igbz-suite' ) );
		echo '</form>';

		$last = get_transient( TenantScope::cache_key( 'igbz_seo_last' ) );
		if ( $last ) {
			delete_transient( TenantScope::cache_key( 'igbz_seo_last' ) );
			echo '<table class="widefat striped"><tbody>';
			foreach ( $last as $label => $value ) {
				printf( '<tr><th>%1$s</th><td>%2$s</td></tr>', esc_html( $label ), esc_html( (string) $value ) );
			}
			echo '</tbody></table>';
		}

		echo '<h2>' . esc_html__( 'Retargeting feeds', 'igbz-suite' ) . '</h2>';
		$feed = new ProductFeedService();
		printf(
			'<p><a class="button" href="%1$s">%2$s</a> <a class="button" href="%3$s">%4$s</a></p>',
			esc_url( add_query_arg( 'igbz_feed', 'yektanet', home_url( '/' ) ) ),
			esc_html__( 'Yektanet XML feed', 'igbz-suite' ),
			esc_url( add_query_arg( 'igbz_feed', 'tapsell', home_url( '/' ) ) ),
			esc_html__( 'Tapsell JSON feed', 'igbz-suite' )
		);
		unset( $feed );

		echo '<h2>' . esc_html__( 'Advertorial (Triboon)', 'igbz-suite' ) . '</h2>';
		echo '<form method="post" style="max-width:560px">';
		wp_nonce_field( 'igbz_seo_triboon' );
		printf( '<input type="hidden" name="igbz_seo_action" value="triboon" />' );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="atitle">' . esc_html__( 'Title', 'igbz-suite' ) . '</label></th><td><input type="text" id="atitle" name="title" class="regular-text" /></td></tr>';
		echo '<tr><th><label for="abody">' . esc_html__( 'Body HTML', 'igbz-suite' ) . '</label></th><td><textarea id="abody" name="body" rows="4" class="large-text"></textarea></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Publish advertorial', 'igbz-suite' ) );
		echo '</form>';

		View::close();
	}

	private function handle_post(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$action = isset( $_POST['igbz_seo_action'] ) ? sanitize_key( (string) $_POST['igbz_seo_action'] ) : '';
		if ( '' === $action ) {
			return;
		}

		if ( 'meta' === $action ) {
			View::check_nonce( 'igbz_seo_meta' );
			$name = sanitize_text_field( (string) ( $_POST['name'] ?? '' ) );
			$desc = sanitize_textarea_field( (string) ( $_POST['description'] ?? '' ) );
			$meta = ( new SeoService() )->generate( $name, $desc );

			// Save onto a real product when one is chosen (fixes the nop gap).
			$product_id = (int) ( $_POST['product_id'] ?? 0 );
			if ( $product_id > 0 ) {
				update_post_meta( $product_id, 'igbz_seo_title', sanitize_text_field( $meta['meta_title'] ) );
				update_post_meta( $product_id, 'igbz_seo_description', sanitize_textarea_field( $meta['meta_description'] ) );
				View::notice( __( 'Meta generated and saved onto the product.', 'igbz-suite' ), 'success' );
			} else {
				set_transient( TenantScope::cache_key( 'igbz_seo_last' ), $meta, 60 );
			}
			return;
		}

		if ( 'triboon' === $action ) {
			View::check_nonce( 'igbz_seo_triboon' );
			$result = ( new AdNetworkService( igbz()->get( 'http' ) ) )->publish_advertorial(
				sanitize_text_field( (string) ( $_POST['title'] ?? '' ) ),
				wp_kses_post( (string) ( $_POST['body'] ?? '' ) )
			);
			View::notice( $result['ok'] ? sprintf( 'Ref: %s', $result['reference'] ) : $result['message'], $result['ok'] ? 'success' : 'error' );
		}
	}
}
