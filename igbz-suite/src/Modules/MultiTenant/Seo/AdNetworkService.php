<?php
namespace IGBZ\Suite\Modules\MultiTenant\Seo;

use IGBZ\Suite\Support\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Advertorial publishing (Triboon API) — config-driven.
 */
final class AdNetworkService implements AdvertorialPublisherInterface {

	public function __construct( private Http $http ) {}

	public function is_configured(): bool {
		return '' !== igbz()->settings()->string( 'seo.triboon_api_key' )
			&& '' !== igbz()->settings()->string( 'seo.triboon_base_url' );
	}

	/**
	 * @return array{ok:bool,reference:string,message:string}
	 */
	public function publish_advertorial( string $title, string $body_html, array $target_media = [] ): array {
		if ( ! $this->is_configured() ) {
			return [ 'ok' => false, 'reference' => '', 'message' => __( 'Triboon is not configured.', 'igbz-suite' ) ];
		}

		$response = $this->http->post(
			rtrim( igbz()->settings()->string( 'seo.triboon_base_url' ), '/' ) . '/v1/advertorials',
			[
				'json'    => [
					'title'        => $title,
					'body_html'    => $body_html,
					'target_media' => $target_media,
				],
				'headers' => [ 'Authorization' => 'Bearer ' . igbz()->settings()->string( 'seo.triboon_api_key' ), 'Accept' => 'application/json' ],
				'channel' => 'seo',
				'timeout' => 30,
			]
		);
		$body = $response->json();

		if ( ! $response->ok() ) {
			return [ 'ok' => false, 'reference' => '', 'message' => (string) ( $body['message'] ?? $body['error'] ?? 'triboon_failed' ) ];
		}

		return [
			'ok'        => true,
			'reference' => (string) ( $body['id'] ?? $body['order_id'] ?? '' ),
			'message'   => '',
		];
	}
}
