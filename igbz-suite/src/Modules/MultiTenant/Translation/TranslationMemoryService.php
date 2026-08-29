<?php
namespace IGBZ\Suite\Modules\MultiTenant\Translation;

use IGBZ\Suite\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Tenant-scoped translation memory + glossary (phase 48).
 *
 * Two research-backed disciplines:
 * - the memory stores exact-match segments per tenant and language; a hit
 *   means the provider is never asked again for the same sentence;
 * - glossary terms are the do-not-translate list: they travel to the
 *   provider behind placeholders and come back restored, so locked brand
 *   terms survive the round-trip untouched.
 */
final class TranslationMemoryService {

	public const PLACEHOLDER_PREFIX = '[[IGBZ_TERM_';

	public function __construct( private Db $db ) {}

	private function hash_of( string $source ): string {
		return hash( 'sha256', $source );
	}

	/** Exact-match lookup. Returns the stored translation or null. */
	public function lookup( int $tenant_id, string $target_language, string $source ): ?string {
		$row = $this->db->row(
			'SELECT translated_text FROM ' . $this->db->table( 'ig_translation_memory' ) . '
			 WHERE tenant_id = %d AND target_language = %s AND source_hash = %s LIMIT 1',
			$tenant_id,
			$target_language,
			$this->hash_of( $source )
		);

		return $row ? (string) $row['translated_text'] : null;
	}

	/** Remember one exact-match segment (insert-or-update per tenant/language/hash). */
	public function remember( int $tenant_id, string $target_language, string $source, string $translated ): void {
		if ( '' === trim( $source ) || '' === trim( $translated ) ) {
			return;
		}

		$hash = $this->hash_of( $source );
		$now  = current_time( 'mysql', true );

		$existing = $this->db->row(
			'SELECT id FROM ' . $this->db->table( 'ig_translation_memory' ) . '
			 WHERE tenant_id = %d AND target_language = %s AND source_hash = %s LIMIT 1',
			$tenant_id,
			$target_language,
			$hash
		);

		if ( $existing ) {
			$this->db->update(
				'ig_translation_memory',
				[ 'translated_text' => $translated, 'updated_at' => $now ],
				[ 'id' => (int) $existing['id'] ]
			);
			return;
		}

		$this->db->insert(
			'ig_translation_memory',
			[
				'tenant_id'       => $tenant_id,
				'target_language' => $target_language,
				'source_hash'     => $hash,
				'source_text'     => $source,
				'translated_text' => $translated,
				'created_at'      => $now,
				'updated_at'      => $now,
			]
		);
	}

	// ---------------------------------------------------------------- glossary

	/** Add or update one locked term for a target language. */
	public function set_term( int $tenant_id, string $language, string $term, string $translation ): void {
		$existing = $this->db->row(
			'SELECT id FROM ' . $this->db->table( 'ig_glossary_terms' ) . '
			 WHERE tenant_id = %d AND language = %s AND term = %s LIMIT 1',
			$tenant_id,
			$language,
			$term
		);

		if ( $existing ) {
			$this->db->update( 'ig_glossary_terms', [ 'translation' => $translation ], [ 'id' => (int) $existing['id'] ] );
			return;
		}

		$this->db->insert(
			'ig_glossary_terms',
			[
				'tenant_id'   => $tenant_id,
				'language'    => $language,
				'term'        => $term,
				'translation' => $translation,
				'created_at'  => current_time( 'mysql', true ),
			]
		);
	}

	/** @return array<int,array{term:string,translation:string}> longest terms first, so nesting masks correctly */
	public function terms( int $tenant_id, string $language ): array {
		$rows = $this->db->results(
			'SELECT term, translation FROM ' . $this->db->table( 'ig_glossary_terms' ) . '
			 WHERE tenant_id = %d AND language = %s ORDER BY CHAR_LENGTH(term) DESC LIMIT 500',
			$tenant_id,
			$language
		);

		$out = [];
		foreach ( $rows as $row ) {
			if ( '' !== trim( (string) $row['term'] ) ) {
				$out[] = [ 'term' => (string) $row['term'], 'translation' => (string) $row['translation'] ];
			}
		}

		return $out;
	}

	/**
	 * Mask glossary terms with placeholders before the provider sees the text.
	 *
	 * @param array<int,array{term:string,translation:string}> $terms
	 * @return array{masked:string,restore:array<string,string>}
	 */
	public function protect( string $text, array $terms ): array {
		$restore = [];
		$index   = 0;

		foreach ( $terms as $entry ) {
			$term = $entry['term'];
			if ( '' === $term || ! str_contains( $text, $term ) ) {
				continue;
			}
			$placeholder                    = self::PLACEHOLDER_PREFIX . $index . ']]';
			$text                           = str_replace( $term, $placeholder, $text );
			$restore[ $placeholder ]        = '' !== $entry['translation'] ? $entry['translation'] : $term;
			++$index;
		}

		return [ 'masked' => $text, 'restore' => $restore ];
	}

	/** @param array<string,string> $restore */
	public function restore( string $translated, array $restore ): string {
		foreach ( $restore as $placeholder => $term ) {
			$translated = str_replace( $placeholder, $term, $translated );
		}

		return $translated;
	}
}
