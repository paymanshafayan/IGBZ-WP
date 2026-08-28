<?php
namespace IGBZ\Suite\Modules\Instagram\Admin;

use IGBZ\Suite\Modules\Instagram\AiStudio\AiStudioService;
use IGBZ\Suite\Modules\Instagram\AiStudio\HttpAiStudioProvider;
use IGBZ\Suite\Support\Admin\Menu;
use IGBZ\Suite\Support\Admin\View;
use IGBZ\Suite\Support\Capabilities;
use IGBZ\Suite\Support\TenantScope;

defined( 'ABSPATH' ) || exit;

/**
 * AI content studio: enhance a product photo, remove its background, make a
 * short video, a Persian voice-over, or a model photo. Output is stored in
 * the media library.
 */
final class AiStudioPage {

	public const SLUG = 'igbz-ai-studio';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 18 );
	}

	public function add_page(): void {
		Menu::add( self::SLUG, __( 'AI studio', 'igbz-suite' ), [ $this, 'render' ], Capabilities::MANAGE_INSTAGRAM );
	}

	public function render(): void {
		$this->handle_post();

		View::open(
			__( 'AI content studio', 'igbz-suite' ),
			__( 'Image enhance, background removal, short videos, Persian voice-over and model photos through the configured AI provider.', 'igbz-suite' )
		);

		if ( ! $this->studio()->provider()->is_configured() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'The AI studio provider is not configured. Set ai_studio.* on Settings → Instagram → AI studio.', 'igbz-suite' ) . '</p></div>';
		}

		$result = get_transient( TenantScope::cache_key( 'igbz_ai_studio_last' ) );
		if ( $result ) {
			delete_transient( TenantScope::cache_key( 'igbz_ai_studio_last' ) );
			View::notice( $result['error'] ?: sprintf( 'Stored as attachment #%d', (int) $result['attachment_id'] ), $result['ok'] ? 'success' : 'error' );
		}

		echo '<form method="post" style="max-width:560px">';
		wp_nonce_field( 'igbz_ai_studio' );
		printf( '<input type="hidden" name="igbz_ai_action" value="run" />' );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="kind">' . esc_html__( 'Tool', 'igbz-suite' ) . '</label></th><td><select id="kind" name="kind">';
		foreach (
			[
				'enhance'  => __( 'Enhance product image', 'igbz-suite' ),
				'background' => __( 'Remove background', 'igbz-suite' ),
				'video'    => __( 'Generate short video', 'igbz-suite' ),
				'tts'      => __( 'Persian voice-over', 'igbz-suite' ),
				'model'    => __( 'Model photo', 'igbz-suite' ),
			] as $value => $label
		) {
			printf( '<option value="%1$s">%2$s</option>', esc_attr( $value ), esc_html( $label ) );
		}
		echo '</select></td></tr>';
		echo '<tr><th><label for="image_url">' . esc_html__( 'Image URL', 'igbz-suite' ) . '</label></th><td><input type="url" id="image_url" name="image_url" class="regular-text" /></td></tr>';
		echo '<tr><th><label for="text">' . esc_html__( 'Text / title', 'igbz-suite' ) . '</label></th><td><textarea id="text" name="text" rows="3" class="large-text"></textarea></td></tr>';
		echo '<tr><th><label for="sku">' . esc_html__( 'SKU code (watermark)', 'igbz-suite' ) . '</label></th><td><input type="text" id="sku" name="sku" class="regular-text" /></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Run', 'igbz-suite' ) );
		echo '</form>';

		View::close();
	}

	private function handle_post(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['igbz_ai_action'] ) ) {
			return;
		}
		View::check_nonce( 'igbz_ai_studio' );

		$kind   = sanitize_key( (string) ( $_POST['kind'] ?? '' ) );
		$url    = esc_url_raw( (string) ( $_POST['image_url'] ?? '' ) );
		$text   = sanitize_textarea_field( (string) ( $_POST['text'] ?? '' ) );
		$sku    = sanitize_text_field( (string) ( $_POST['sku'] ?? '' ) );
		$studio = $this->studio();

		$result = match ( $kind ) {
			'background' => $studio->remove_background( $url ),
			'video'      => $studio->generate_video( $text, $text, $url ),
			'tts'        => $studio->text_to_speech( $text ),
			'model'      => $studio->generate_model_image( $text, $url, $sku ),
			default      => $studio->enhance_product_image( $url, 'studio', $sku ),
		};

		set_transient( TenantScope::cache_key( 'igbz_ai_studio_last' ), [ 'ok' => $result['ok'], 'attachment_id' => $result['attachment_id'], 'error' => $result['error'] ], 60 );
	}

	private function studio(): AiStudioService {
		return igbz()->get( 'ai.studio' );
	}
}
