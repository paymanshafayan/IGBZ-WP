<?php
namespace IGBZ\Suite\Modules\MultiTenant\Translation;

use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * One-click product translation.
 *
 * Translates name + short/long description and stores the result as product
 * meta keyed by language (the same storage the intake TranslationBridge
 * uses), so multilingual plugins can pick it up later.
 *
 * Phase 48 wired in the tenant-scoped translation memory and glossary: an
 * exact memory hit is served without ever asking the provider, locked
 * glossary terms travel behind placeholders and come back restored, and
 * every fresh provider answer becomes memory for the next identical segment.
 */
final class TranslationService {

	public function __construct(
		private TranslatorAdapterInterface $adapter,
		private Logger $logger,
		private ?TranslationMemoryService $memory = null
	) {}

	/**
	 * @return array{ok:bool,language:string,error:string}
	 */
	public function translate_product( int $product_id, string $language, int $tenant_id = 0 ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return [ 'ok' => false, 'language' => $language, 'error' => __( 'Product not found.', 'igbz-suite' ) ];
		}

		$tenant_id = $tenant_id > 0 ? $tenant_id : (int) igbz()->tenancy()->id();
		$fields    = [
			(string) $product->get_name(),
			(string) $product->get_short_description(),
			(string) $product->get_description(),
		];

		$translated = $this->translate_fields( $fields, $language, $tenant_id );
		if ( null === $translated ) {
			$this->logger->error( 'translation', 'Product translation failed', [ 'product_id' => $product_id ] );
			return [ 'ok' => false, 'language' => $language, 'error' => __( 'Translation provider failed.', 'igbz-suite' ) ];
		}

		$lang_key = 'igbz_translation_' . sanitize_key( $language );
		update_post_meta( $product_id, $lang_key, [
			'name'              => $translated[0] ?? '',
			'short_description' => $translated[1] ?? '',
			'description'       => $translated[2] ?? '',
			'translated_at'     => current_time( 'mysql', true ),
		] );
		$this->logger->info( 'translation', 'Product translated', [ 'product_id' => $product_id, 'language' => $language ] );

		return [ 'ok' => true, 'language' => $language, 'error' => '' ];
	}

	/**
	 * Translate the fields: memory first, provider only for what memory does
	 * not already hold. Returns null when the provider fails.
	 *
	 * @param array<int,string> $fields
	 * @return array<int,string>|null
	 */
	private function translate_fields( array $fields, string $language, int $tenant_id ): ?array {
		if ( null === $this->memory ) {
			$result = $this->adapter->translate( $fields, $language );
			return $result['ok'] ? array_values( (array) $result['translated'] ) : null;
		}

		$terms   = $this->memory->terms( $tenant_id, $language );
		$missing = [];
		$out     = [];

		foreach ( $fields as $index => $field ) {
			$hit = '' !== trim( $field ) ? $this->memory->lookup( $tenant_id, $language, $field ) : null;
			if ( null !== $hit ) {
				$out[ $index ] = $hit;
				continue;
			}
			$missing[ $index ] = $field;
			$out[ $index ]     = '';
		}

		if ( [] !== $missing ) {
			$masked = [];
			$restores = [];
			foreach ( $missing as $index => $field ) {
				$guard              = $this->memory->protect( $field, $terms );
				$masked[ $index ]   = $guard['masked'];
				$restores[ $index ] = $guard['restore'];
			}

			$result = $this->adapter->translate( array_values( $masked ), $language );
			if ( ! $result['ok'] ) {
				return null;
			}

			$answers = array_values( (array) $result['translated'] );
			$cursor  = 0;
			foreach ( $missing as $index => $field ) {
				$answer      = (string) ( $answers[ $cursor++ ] ?? '' );
				$answer      = $this->memory->restore( $answer, $restores[ $index ] );
				$out[ $index ] = $answer;
				if ( '' !== trim( $field ) && '' !== trim( $answer ) ) {
					$this->memory->remember( $tenant_id, $language, $field, $answer );
				}
			}
		}

		return $out;
	}
}
