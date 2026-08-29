<?php
namespace IGBZ\Suite\Modules\MultiTenant\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * Anything that can translate a batch of fields into a target language.
 * Phase 48: the translation flow depends on the contract, not the HTTP
 * adapter, so memory and glossary behaviour are testable on their own.
 */
interface TranslatorAdapterInterface {

	public function is_configured(): bool;

	/**
	 * @param array<int,string> $fields
	 * @return array{ok:bool,translated:string[],error:string}
	 */
	public function translate( array $fields, string $target_language ): array;
}
