<?php
namespace IGBZ\Suite\Modules\MultiTenant\Translation;

use IGBZ\Suite\Support\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Config-driven machine-translation provider (Tarjomyar / Farazin / Deepfa).
 */
final class HttpTranslationAdapter implements TranslatorAdapterInterface {

	public function __construct( private Http $http ) {}

	public function is_configured(): bool {
		return '' !== igbz()->settings()->string( 'translation.api_key' )
			&& '' !== igbz()->settings()->string( 'translation.base_url' );
	}

	/**
	 * @param string[] $fields
	 * @return array{ok:bool,translated:string[],error:string}
	 */
	public function translate( array $fields, string $target_language ): array {
		if ( ! $this->is_configured() ) {
			return [ 'ok' => false, 'translated' => [], 'error' => __( 'Translation provider is not configured.', 'igbz-suite' ) ];
		}

		$scheme   = igbz()->settings()->string( 'translation.auth_scheme', 'Bearer' );
		$response = $this->http->post(
			rtrim( igbz()->settings()->string( 'translation.base_url' ), '/' ) . igbz()->settings()->string( 'translation.path', '/v1/translate' ),
			[
				'json'    => [ 'targetLanguage' => $target_language, 'fields' => array_values( $fields ) ],
				'headers' => [ 'Authorization' => ( '' === $scheme ? '' : $scheme . ' ' ) . igbz()->settings()->string( 'translation.api_key' ), 'Accept' => 'application/json' ],
				'channel' => 'translation',
				'timeout' => 60,
			]
		);
		if ( ! $response->ok() ) {
			return [ 'ok' => false, 'translated' => [], 'error' => $response->error_message() ];
		}

		$body  = $response->json();
		$value = $body;
		$path  = trim( igbz()->settings()->string( 'translation.result_json_path', 'translatedFields' ) );
		foreach ( explode( '.', $path ) as $seg ) {
			if ( ! is_array( $value ) || ! array_key_exists( $seg, $value ) ) {
				return [ 'ok' => false, 'translated' => [], 'error' => __( 'The translation provider response was invalid.', 'igbz-suite' ) ];
			}
			$value = $value[ $seg ];
		}

		if ( ! is_array( $value ) ) {
			return [ 'ok' => false, 'translated' => [], 'error' => __( 'The translation provider response was invalid.', 'igbz-suite' ) ];
		}

		return [ 'ok' => true, 'translated' => array_map( 'strval', $value ), 'error' => '' ];
	}
}
