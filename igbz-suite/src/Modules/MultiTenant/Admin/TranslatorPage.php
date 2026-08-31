<?php
namespace IGBZ\Suite\Modules\MultiTenant\Admin;

use IGBZ\Suite\Modules\MultiTenant\Translation\TranslationService;
use IGBZ\Suite\Support\Admin\Menu;
use IGBZ\Suite\Support\Admin\View;
use IGBZ\Suite\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Auto-translation tool: pick a product and a target language.
 */
final class TranslatorPage {

	public const SLUG = 'igbz-translator';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 17 );
	}

	public function add_page(): void {
		Menu::add( self::SLUG, __( 'Translator', 'igbz-suite' ), [ $this, 'render' ], Capabilities::MANAGE_SUITE );
	}

	public function render(): void {
		$this->handle_post();

		View::open(
			__( 'Auto translator', 'igbz-suite' ),
			__( 'Translate products to Arabic, English, Turkish or Kurdish with one click (configured provider).', 'igbz-suite' )
		);

		$adapter = igbz()->get( 'translation.adapter' );
		if ( ! $adapter->is_configured() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Set translation.* on Settings → Translation.', 'igbz-suite' ) . '</p></div>';
		}

		echo '<form method="post" style="max-width:520px">';
		wp_nonce_field( 'igbz_translate' );
		printf( '<input type="hidden" name="igbz_tr_action" value="translate" />' );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="product_id">' . esc_html__( 'Product', 'igbz-suite' ) . '</label></th><td>';
		$products = wc_get_products( [ 'limit' => 200, 'status' => 'publish' ] );
		echo '<select id="product_id" name="product_id">';
		foreach ( $products as $product ) {
			printf( '<option value="%1$d">%2$s</option>', (int) $product->get_id(), esc_html( (string) $product->get_name() ) );
		}
		echo '</select></td></tr>';
		echo '<tr><th><label for="lang">' . esc_html__( 'Language', 'igbz-suite' ) . '</label></th><td><select id="lang" name="lang">';
		foreach ( [ 'en' => __( 'English', 'igbz-suite' ), 'ar' => __( 'Arabic', 'igbz-suite' ), 'tr' => __( 'Turkish', 'igbz-suite' ), 'ckb' => __( 'Kurdish', 'igbz-suite' ) ] as $code => $label ) {
			printf( '<option value="%1$s">%2$s</option>', esc_attr( $code ), esc_html( $label ) );
		}
		echo '</select></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Translate', 'igbz-suite' ) );
		echo '</form>';

		View::close();
	}

	private function handle_post(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$action = isset( $_POST['igbz_tr_action'] ) ? sanitize_key( (string) $_POST['igbz_tr_action'] ) : '';
		if ( 'translate' !== $action ) {
			return;
		}
		View::check_nonce( 'igbz_translate' );

		$result = igbz()->get( 'translation' )->translate_product(
			(int) ( $_POST['product_id'] ?? 0 ),
			sanitize_key( (string) ( $_POST['lang'] ?? 'en' ) )
		);
		View::notice( $result['ok'] ? __( 'Translated and stored.', 'igbz-suite' ) : $result['error'], $result['ok'] ? 'success' : 'error' );
	}
}
