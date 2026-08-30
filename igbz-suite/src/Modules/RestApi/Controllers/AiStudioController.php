<?php
namespace IGBZ\Suite\Modules\RestApi\Controllers;

defined( 'ABSPATH' ) || exit;

/**
 * Customer-facing AI studio (the app): view credit balance and spend credits
 * on content generation through the configured AI provider.
 *
 *   GET  /igbz/v1/ai/credits
 *   POST /igbz/v1/ai/studio/generate   { kind, image_url?, text?, sku? }
 */
final class AiStudioController extends BaseController {

	public function register_routes(): void {
		$ns   = self::NAMESPACE;
		$auth = [ $this, 'is_logged_in' ];

		register_rest_route( $ns, '/ai/credits', $this->route( 'GET', [ $this, 'credits' ], $auth ) );
		register_rest_route( $ns, '/ai/studio/generate', $this->route( 'POST', [ $this, 'generate' ], $auth ) );
	}

	public function credits(): \WP_REST_Response {
		$user_id = get_current_user_id();

		return $this->ok(
			[
				'balance'   => igbz()->has( 'ai.credits' ) ? igbz()->get( 'ai.credits' )->balance( $user_id ) : 0.0,
				'enabled'   => igbz()->settings()->bool( 'ai_credits.enabled', true ),
			]
		);
	}

	public function generate( \WP_REST_Request $request ): \WP_REST_Response {
		// Phase 67: generation spends credits — a retry must replay, not spend twice.
		return $this->with_idempotency( $request, fn (): \WP_REST_Response => $this->do_generate( $request ) );
	}

	private function do_generate( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = get_current_user_id();

		if ( ! igbz()->settings()->bool( 'ai_credits.enabled', true ) ) {
			return $this->fail( 'disabled', __( 'The AI studio is disabled.', 'igbz-suite' ), 403 );
		}
		if ( ! igbz()->has( 'ai.credits' ) || ! igbz()->has( 'ai.studio' ) ) {
			return $this->fail( 'unavailable', __( 'The AI studio is not available on this site.', 'igbz-suite' ), 403 );
		}

		$kind  = sanitize_key( (string) $request->get_param( 'kind' ) );
		$url   = esc_url_raw( (string) $request->get_param( 'image_url' ) );
		$text  = sanitize_textarea_field( (string) $request->get_param( 'text' ) );
		$sku   = sanitize_text_field( (string) $request->get_param( 'sku' ) );

		// Fixed price per job (in credit units); adjust with a setting later.
		$price = 1.0;
		$ref   = 'ai-studio:' . $user_id . ':' . gmdate( 'ymdHis' ) . ':' . bin2hex( random_bytes( 3 ) );

		$spend = igbz()->get( 'ai.credits' )->spend( $user_id, $price, $ref );
		if ( ! $spend['ok'] ) {
			return $this->fail( 'insufficient_credits', __( 'Not enough AI credits. Buy more or make a purchase to earn credits.', 'igbz-suite' ), 402 );
		}

		/** @var \IGBZ\Suite\Modules\Instagram\AiStudio\AiStudioService $studio */
		$studio = igbz()->get( 'ai.studio' );
		$result = match ( $kind ) {
			'background' => $studio->remove_background( $url ),
			'video'      => $studio->generate_video( $text, $text, $url ),
			'tts'        => $studio->text_to_speech( $text ),
			'model'      => $studio->generate_model_image( $text, $url, $sku ),
			default      => $studio->enhance_product_image( $url, 'studio', $sku ),
		};

		if ( ! $result['ok'] ) {
			// Refund the spent credit when the provider failed.
			igbz()->get( 'ai.credits' )->ledger( $user_id, $price, 'refund', 'refund:' . $ref );
			return $this->fail( 'generation_failed', $result['error'], 502 );
		}

		return $this->ok(
			[
				'ok'            => true,
				'attachment_id' => $result['attachment_id'],
				'url'           => $result['url'],
				'balance'       => igbz()->get( 'ai.credits' )->balance( $user_id ),
			],
			201
		);
	}
}
