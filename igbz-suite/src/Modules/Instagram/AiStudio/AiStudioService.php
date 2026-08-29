<?php
namespace IGBZ\Suite\Modules\Instagram\AiStudio;

use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Facade over the AI provider: runs a job and pulls the produced file into
 * the media library. Like the intake path, attachments are sideloaded
 * immediately because provider URLs are signed and expire.
 */
final class AiStudioService {

	public function __construct(
		private AiProviderInterface $provider,
		private Logger $logger
	) {}

	public function provider(): AiProviderInterface {
		return $this->provider;
	}

	/**
	 * @return array{ok:bool,attachment_id:int,url:string,error:string}
	 */
	public function enhance_product_image( string $image_url, string $background_preset = '', string $sku_code = '' ): array {
		return $this->run( $this->provider->enhance_image( $image_url, $background_preset, $sku_code ), 'enhance' );
	}

	/**
	 * @return array{ok:bool,attachment_id:int,url:string,error:string}
	 */
	public function remove_background( string $image_url ): array {
		return $this->run( $this->provider->remove_background( $image_url ), 'background' );
	}

	/**
	 * @return array{ok:bool,attachment_id:int,url:string,error:string}
	 */
	public function generate_video( string $product_title, string $description, string $image_url = '' ): array {
		return $this->run( $this->provider->generate_video( $product_title, $description, $image_url ), 'video' );
	}

	/**
	 * @return array{ok:bool,attachment_id:int,url:string,error:string}
	 */
	public function text_to_speech( string $text, string $voice = 'Female' ): array {
		return $this->run( $this->provider->text_to_speech( $text, $voice ), 'tts' );
	}

	/**
	 * @return array{ok:bool,attachment_id:int,url:string,error:string}
	 */
	public function generate_model_image( string $model_description, string $product_image_url = '', string $sku_code = '' ): array {
		return $this->run( $this->provider->generate_model_image( $model_description, $product_image_url, $sku_code ), 'model' );
	}

	/**
	 * @param array{ok:bool,url:string,error:string} $result
	 * @return array{ok:bool,attachment_id:int,url:string,error:string}
	 */
	private function run( array $result, string $kind ): array {
		if ( ! $result['ok'] ) {
			$this->logger->error( 'ai_studio', 'AI studio job failed', [ 'kind' => $kind, 'error' => $result['error'] ] );
			return [ 'ok' => false, 'attachment_id' => 0, 'url' => '', 'error' => $result['error'] ];
		}

		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// Phase 10: provider-supplied URLs still go through the SSRF gate before WordPress
		// fetches them on our behalf.
		if ( ! \IGBZ\Suite\Support\UrlGuard::is_safe( (string) $result['url'] ) ) {
			$this->logger->log( \IGBZ\Suite\Support\Logger::WARNING, 'security', 'AI studio sideload blocked by URL guard' );
			return [ 'ok' => false, 'attachment_id' => 0, 'url' => '', 'error' => 'Blocked by the SSRF guard.' ];
		}

		$attachment_id = media_sideload_image( $result['url'], 0, 'AI studio ' . $kind, 'id' );
		if ( is_wp_error( $attachment_id ) ) {
			$this->logger->error( 'ai_studio', 'Sideload failed', [ 'kind' => $kind, 'error' => $attachment_id->get_error_message() ] );
			return [ 'ok' => false, 'attachment_id' => 0, 'url' => $result['url'], 'error' => $attachment_id->get_error_message() ];
		}

		$url = (string) wp_get_attachment_url( (int) $attachment_id );
		$this->logger->info( 'ai_studio', 'AI asset stored', [ 'kind' => $kind, 'attachment_id' => (int) $attachment_id ] );

		return [ 'ok' => true, 'attachment_id' => (int) $attachment_id, 'url' => $url, 'error' => '' ];
	}
}
