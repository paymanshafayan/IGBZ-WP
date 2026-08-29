<?php
/**
 * Phase 48 — international commerce: the tenant-scoped memory serves exact
 * matches without asking the provider, glossary terms survive the round-trip
 * behind placeholders, currency/timezone only accept honest values, consent
 * is a dated ledger that gates cross-border processing, and the gateway
 * registry lists nothing that is not configured.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\MultiTenant\Translation\IntlCommerceService;
use IGBZ\Suite\Modules\MultiTenant\Translation\IntlGatewayService;
use IGBZ\Suite\Modules\MultiTenant\Translation\TranslationMemoryService;
use IGBZ\Suite\Modules\MultiTenant\Translation\TranslationService;
use IGBZ\Suite\Modules\MultiTenant\Translation\TranslatorAdapterInterface;
use IGBZ\Suite\Support\Db;

if ( ! function_exists( 'wc_get_product' ) ) {
	/** Test stand-in: products registered in the global basket. */
	function wc_get_product( int $product_id ) {
		return $GLOBALS['igbz_test_products'][ $product_id ] ?? null;
	}
}

/** The last meta value another test's stub recorded for this post+key. */
function igbz_intl_meta( int $post_id, string $meta_key ) {
	foreach ( array_reverse( IgbzHposStub::$postmeta_writes ) as $write ) {
		if ( $write[0] === $post_id && $write[1] === $meta_key ) {
			return $write[2];
		}
	}
	return null;
}

/** Minimal product stand-in for the translation flow. */
final class IntlProduct {

	public function __construct(
		private int $id,
		private string $name,
		private string $short,
		private string $long
	) {}

	public function get_id(): int { return $this->id; }
	public function get_name(): string { return $this->name; }
	public function get_short_description(): string { return $this->short; }
	public function get_description(): string { return $this->long; }
}

/** A translator that counts every call and returns prefixed answers. */
final class ScriptedTranslator implements TranslatorAdapterInterface {

	public int $calls = 0;

	/** @var array<int,array<int,string>> */
	public array $seen = [];

	public function is_configured(): bool {
		return true;
	}

	public function translate( array $fields, string $target_language ): array {
		++$this->calls;
		$this->seen[] = $fields;

		return [ 'ok' => true, 'translated' => array_map( static fn ( string $f ): string => 'TR(' . $f . ')', array_values( $fields ) ), 'error' => '' ];
	}
}

/** In-memory engine for memory, glossary and consent. */
final class IntlDb extends wpdb {

	/** @var array<string,array<int,array<string,mixed>>> */
	public array $tables = [ 'ig_translation_memory' => [], 'ig_glossary_terms' => [], 'ig_intl_consents' => [] ];

	private int $next_id = 1;

	/** The bare logical name, whatever prefix the engine used. */
	private function logical( string $table ): string {
		foreach ( [ 'ig_translation_memory', 'ig_glossary_terms', 'ig_intl_consents' ] as $known ) {
			if ( str_ends_with( $table, $known ) ) {
				return $known;
			}
		}
		return $table;
	}

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'ig_translation_memory' )
			&& preg_match( "/tenant_id = '(\d+)' AND target_language = '([^']*)' AND source_hash = '([^']*)'/", $sql, $m ) ) {
			foreach ( $this->tables['ig_translation_memory'] as $row ) {
				if ( (string) $row['tenant_id'] === $m[1] && (string) $row['target_language'] === $m[2] && (string) $row['source_hash'] === $m[3] ) {
					return $row;
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'ig_glossary_terms' )
			&& preg_match( "/tenant_id = '(\d+)' AND language = '([^']*)' AND term = '([^']*)'/", $sql, $m ) ) {
			foreach ( $this->tables['ig_glossary_terms'] as $row ) {
				if ( (string) $row['tenant_id'] === $m[1] && (string) $row['language'] === $m[2] && (string) $row['term'] === $m[3] ) {
					return $row;
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'ig_intl_consents' )
			&& preg_match( "/tenant_id = '(\d+)' AND user_id = '(\d+)' AND purpose = '([^']*)'/", $sql, $m ) ) {
			foreach ( $this->tables['ig_intl_consents'] as $row ) {
				if ( (string) $row['tenant_id'] === $m[1] && (string) $row['user_id'] === $m[2] && (string) $row['purpose'] === $m[3] ) {
					return $row;
				}
			}
			return null;
		}

		return parent::get_row( $sql, $output );
	}

	public function get_results( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'ig_glossary_terms' ) && preg_match( "/tenant_id = '(\d+)' AND language = '([^']*)'/", $sql, $m ) ) {
			$out = [];
			foreach ( $this->tables['ig_glossary_terms'] as $row ) {
				if ( (string) $row['tenant_id'] === $m[1] && (string) $row['language'] === $m[2] ) {
					$out[] = $row;
				}
			}
			usort( $out, static fn ( $a, $b ): int => strlen( (string) $b['term'] ) <=> strlen( (string) $a['term'] ) );
			return $out;
		}

		return parent::get_results( $sql, $output );
	}

	public function insert( string $table, array $data, $format = null ): int|bool {
		$this->queries[] = 'INSERT ' . $table;
		$table = $this->logical( $table );

		if ( isset( $this->tables[ $table ] ) ) {
			$id = $this->next_id++;
			$data['id'] = $id;
			$this->tables[ $table ][ $id ] = $data;
			$this->insert_id = $id;
			return $id;
		}

		return parent::insert( $table, $data, $format );
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		$this->queries[] = 'UPDATE ' . $table;
		$table = $this->logical( $table );

		if ( isset( $this->tables[ $table ] ) ) {
			$changed = 0;
			foreach ( $this->tables[ $table ] as $id => $row ) {
				$hit = true;
				foreach ( $where as $column => $value ) {
					if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) {
						$hit = false;
						break;
					}
				}
				if ( $hit ) {
					$this->tables[ $table ][ $id ] = array_merge( $row, $data );
					++$changed;
				}
			}
			return $changed;
		}

		return parent::update( $table, $data, $where, $format, $where_format );
	}
}

final class IntlCommerceTest extends TestCase {

	private IntlDb $idb;
	private Db $db;
	private TranslationMemoryService $memory;

	private function boot(): void {
		igbz_test_reset_settings();
		$GLOBALS['igbz_test_products']   = [];
		IgbzHposStub::$postmeta_writes   = [];

		$this->idb       = new IntlDb();
		$GLOBALS['wpdb'] = $this->idb;

		$this->db = new Db();
		$ref = new ReflectionProperty( Db::class, 'is_sqlite' );
		$ref->setValue( $this->db, true );

		$this->memory = new TranslationMemoryService( $this->db );
	}

	private function service( TranslatorAdapterInterface $adapter ): TranslationService {
		return new TranslationService( $adapter, new IGBZ\Suite\Support\Logger( igbz()->settings() ), $this->memory );
	}

	public function run(): void {
		$this->test_a_memory_hit_never_asks_the_provider();
		$this->test_a_fresh_answer_becomes_memory();
		$this->test_glossary_terms_survive_the_round_trip();
		$this->test_currency_and_timezone_accept_only_honest_values();
		$this->test_consent_is_a_dated_ledger_that_gates_cross_border();
		$this->test_the_gateway_registry_lists_only_what_is_configured();
	}

	public function test_a_memory_hit_never_asks_the_provider(): void {
		$this->boot();
		$this->memory->remember( 3, 'en', 'کفش کوهنوردی', 'Mountaineering shoes' );

		$GLOBALS['igbz_test_products'][41] = new IntlProduct( 41, 'کفش کوهنوردی', 'توضیح کوتاه', 'توضیح بلند' );
		$adapter = new ScriptedTranslator();

		// The name is already remembered; only the two descriptions must reach the provider.
		$this->service( $adapter )->translate_product( 41, 'en', 3 );

		$this->assert_same( 1, $adapter->calls, 'one provider round for the missing segments' );
		$this->assert_same( 2, count( $adapter->seen[0] ), 'the remembered segment never travelled' );

		$meta = igbz_intl_meta( 41, 'igbz_translation_en' );
		$this->assert_same( 'Mountaineering shoes', $meta['name'], 'the stored memory wins' );
	}

	public function test_a_fresh_answer_becomes_memory(): void {
		$this->boot();
		$GLOBALS['igbz_test_products'][42] = new IntlProduct( 42, 'کوله', 'کوتاه', 'بلند' );
		$adapter = new ScriptedTranslator();
		$service = $this->service( $adapter );

		$service->translate_product( 42, 'en', 3 );
		$service->translate_product( 42, 'en', 3 );

		$this->assert_same( 1, $adapter->calls, 'the second identical run is served from memory' );
		$this->assert_same( 'TR(کوله)', $this->memory->lookup( 3, 'en', 'کوله' ), 'the fresh answer landed in the ledger' );
	}

	public function test_glossary_terms_survive_the_round_trip(): void {
		$this->boot();
		$this->memory->set_term( 3, 'en', 'آریاپی', 'AriaPay' );
		$GLOBALS['igbz_test_products'][43] = new IntlProduct( 43, 'درگاه آریاپی', '', '' );
		$adapter = new ScriptedTranslator();

		$this->service( $adapter )->translate_product( 43, 'en', 3 );

		$this->assert_true( str_contains( $adapter->seen[0][0], '[[IGBZ_TERM_0]]' ), 'the locked term travelled behind a placeholder' );
		$meta = igbz_intl_meta( 43, 'igbz_translation_en' );
		$this->assert_true( str_contains( $meta['name'], 'AriaPay' ), 'the term came back as the locked translation' );
		$this->assert_false( str_contains( $meta['name'], '[[IGBZ_TERM' ), 'no placeholder leaked to the page' );
	}

	public function test_currency_and_timezone_accept_only_honest_values(): void {
		$this->boot();
		$commerce = new IntlCommerceService( $this->db );

		igbz()->settings()->set( 'intl.currency', 'usd' );
		$this->assert_same( 'USD', $commerce->currency( 3 ), 'a known code is accepted case-insensitively' );

		igbz()->settings()->set( 'intl.currency', 'XYZ' );
		$this->assert_same( 'IRR', $commerce->currency( 3 ), 'an invented code falls back to the home currency' );

		igbz()->settings()->set( 'intl.timezone', 'Europe/Paris' );
		$this->assert_same( 'Europe/Paris', $commerce->timezone( 3 ), 'a real IANA zone passes' );

		igbz()->settings()->set( 'intl.timezone', 'Mars/Olympus' );
		$this->assert_same( 'Asia/Tehran', $commerce->timezone( 3 ), 'an invented zone falls back home' );
	}

	public function test_consent_is_a_dated_ledger_that_gates_cross_border(): void {
		$this->boot();
		$commerce = new IntlCommerceService( $this->db );

		$this->assert_false( $commerce->crossborder_allowed( 3, 77 ), 'silence is not consent' );

		$commerce->grant_consent( 3, 77 );
		$this->assert_true( $commerce->crossborder_allowed( 3, 77 ), 'a grant opens the border' );

		$commerce->revoke_consent( 3, 77 );
		$this->assert_false( $commerce->crossborder_allowed( 3, 77 ), 'a revocation closes it again' );
		$this->assert_true( null !== $this->idb->tables['ig_intl_consents'][1]['revoked_at'], 'the revocation is dated' );

		$commerce->revoke_consent( 3, 78 );
		$this->assert_same( 2, count( $this->idb->tables['ig_intl_consents'] ), 'revoking the never-granted still leaves a dated row' );
	}

	public function test_the_gateway_registry_lists_only_what_is_configured(): void {
		$this->boot();
		igbz()->settings()->set( 'intl.psp_ids', 'stripe,acme' );
		igbz()->settings()->set( 'intl.psp_stripe_title', 'Stripe' );
		igbz()->settings()->set( 'intl.psp_stripe_enabled', 'yes' );
		igbz()->settings()->set( 'intl.psp_stripe_base_url', 'https://api.stripe.test' );
		igbz()->settings()->set( 'intl.psp_stripe_api_key', 'sk_test' );
		igbz()->settings()->set( 'intl.psp_stripe_currencies', 'USD,EUR' );
		igbz()->settings()->set( 'intl.psp_acme_enabled', 'yes' );

		$registry = new IntlGatewayService();
		$gateways = $registry->gateways();
		$this->assert_true( isset( $gateways['stripe'] ) && isset( $gateways['acme'] ), 'both registered gateways are listed' );
		$this->assert_true( $gateways['stripe']['configured'], 'stripe holds credentials' );
		$this->assert_false( $gateways['acme']['configured'], 'acme holds none' );

		$ready = $registry->available( 'USD' );
		$this->assert_true( isset( $ready['stripe'] ), 'the ready list keeps stripe' );
		$this->assert_false( isset( $ready['acme'] ), 'the ready list drops unconfigured acme' );

		$this->assert_same( [], $registry->available( 'XYZ' ), 'an unknown currency matches nothing' );
	}
}
