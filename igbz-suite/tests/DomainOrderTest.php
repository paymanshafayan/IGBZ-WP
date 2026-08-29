<?php
/**
 * Phase 37 — domain commerce: search validates its input and fails honestly without a
 * provider, a quote is a price with a deadline, the order is idempotent per (tenant, key),
 * and a reservation never touches the provider before payment.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\Domain\Contracts\DomainAdapterInterface;
use IGBZ\Suite\Modules\Domain\DomainAdapterRegistry;
use IGBZ\Suite\Modules\Domain\DomainService;
use IGBZ\Suite\Support\Db;

/** In-memory engine for quotes + orders. */
final class DomainsDb extends wpdb {

	/** @var array<string,array<int,array<string,mixed>>> */
	public array $tables = [
		'ig_domain_quotes' => [],
		'ig_domain_orders' => [],
	];

	private int $next_id = 1;

	/** @param array<string,mixed> $row */
	public function seed( string $table, array $row ): int {
		$id                          = $this->next_id++;
		$row['id']                   = $id;
		$this->tables[ $table ][ $id ] = $row;

		return $id;
	}

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'ig_domain_orders' ) ) {
			if ( preg_match( "/WHERE id = '?(\d+)'?/", $sql, $m ) ) {
				return $this->tables['ig_domain_orders'][ (int) $m[1] ] ?? null;
			}
			if ( preg_match( "/WHERE tenant_id = '?(\d+)'? AND idempotency_key = '([^']*)'/", $sql, $m ) ) {
				foreach ( $this->tables['ig_domain_orders'] as $row ) {
					if ( (string) $row['tenant_id'] === $m[1] && (string) $row['idempotency_key'] === $m[2] ) {
						return $row;
					}
				}
				return null;
			}
		}

		if ( str_contains( $sql, 'ig_domain_quotes' ) && preg_match( "/WHERE id = '?(\d+)'?/", $sql, $m ) ) {
			return $this->tables['ig_domain_quotes'][ (int) $m[1] ] ?? null;
		}

		return parent::get_row( $sql, $output );
	}

	public function get_results( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'ig_domain_quotes' ) ) {
			$out = [];
			foreach ( $this->tables['ig_domain_quotes'] as $row ) {
				if ( preg_match( "/tenant_id = '?(\d+)'? AND name = '([^']*)'/", $sql, $m )
					&& (string) $row['tenant_id'] === $m[1] && (string) $row['name'] === $m[2] ) {
					$out[] = $row;
				}
			}
			usort( $out, static fn ( $a, $b ): int => (int) $b['id'] <=> (int) $a['id'] );
			return array_slice( $out, 0, 1 );
		}

		return parent::get_results( $sql, $output );
	}

	public function insert( string $table, array $data, $format = null ): int|bool {
		$this->queries[] = 'INSERT INTO ' . $table;
		$this->last_write = [ 'table' => $table, 'data' => $data ];
		$this->writes[]   = $this->last_write;

		foreach ( [ 'ig_domain_quotes', 'ig_domain_orders' ] as $name ) {
			if ( str_contains( $table, $name ) ) {
				$this->insert_id = $this->seed( $name, $data );
				return 1;
			}
		}

		return parent::insert( $table, $data, $format );
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		$this->queries[] = 'UPDATE ' . $table;

		foreach ( [ 'ig_domain_quotes', 'ig_domain_orders' ] as $name ) {
			if ( ! str_contains( $table, $name ) ) {
				continue;
			}
			$changed = 0;
			foreach ( $this->tables[ $name ] as $id => $row ) {
				$hit = true;
				foreach ( $where as $column => $value ) {
					if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) {
						$hit = false;
						break;
					}
				}
				if ( $hit ) {
					$this->tables[ $name ][ $id ] = array_merge( $row, $data );
					++$changed;
				}
			}
			return $changed;
		}

		return parent::update( $table, $data, $where, $format, $where_format );
	}
}

/** A registrar that prices names and records every register() call. */
final class StubDomainAdapter implements DomainAdapterInterface {

	public array $registered = [];

	public function __construct(
		public array $quote_result = [ 'ok' => true, 'price' => 9.5, 'currency' => 'usd', 'ttl_minutes' => 60, 'error' => '' ]
	) {}

	public function id(): string {
		return 'stub';
	}

	public function title(): string {
		return 'Stub Registrar';
	}

	public function is_configured(): bool {
		return true;
	}

	public function search( string $term ): array {
		return [
			'ok'      => true,
			'results' => [
				[ 'name' => $term . '.com', 'price' => 12.0, 'currency' => 'USD' ],
				[ 'name' => $term . '.ir', 'price' => 2.0, 'currency' => 'USD' ],
			],
			'error'   => '',
		];
	}

	public function quote( string $name ): array {
		return $this->quote_result;
	}

	public function register( array $order ): array {
		$this->registered[] = (int) $order['id'];
		return [ 'ok' => true, 'reference' => 'reg:' . (int) $order['id'], 'error' => '' ];
	}

	public function query( array $order ): array {
		return [ 'state' => 'unknown', 'reference' => '', 'error' => '' ];
	}
}

final class DomainOrderTest extends TestCase {

	private Db $db;
	private DomainsDb $ddb;
	private StubDomainAdapter $adapter;
	private DomainService $service;

	private function boot(): void {
		igbz_test_reset_settings();
		igbz()->settings()->set( 'domain.provider', 'stub' );

		$this->ddb         = new DomainsDb();
		$GLOBALS['wpdb']   = $this->ddb;

		$this->db = new Db();
		$ref = new ReflectionProperty( Db::class, 'is_sqlite' );
		$ref->setValue( $this->db, true );

		$this->adapter = new StubDomainAdapter();
		$registry      = new DomainAdapterRegistry();
		$registry->register( $this->adapter );

		$this->service = new DomainService( $this->db, igbz()->settings(), $registry, new IGBZ\Suite\Support\Logger( igbz()->settings() ), new IGBZ\Suite\Modules\Fx\FxWalletService( $this->db ) );
	}

	public function run(): void {
		$this->test_search_validates_and_fails_honestly_without_a_provider();
		$this->test_a_quote_is_a_price_with_a_deadline();
		$this->test_an_expired_quote_cannot_create_an_order();
		$this->test_the_order_is_idempotent_per_tenant_and_key();
		$this->test_reservation_never_registers_before_payment();
	}

	public function test_search_validates_and_fails_honestly_without_a_provider(): void {
		$this->boot();

		$bad = $this->service->search( 'نمونه!' );
		$this->assert_false( $bad['ok'], 'a bad term is refused' );
		$this->assert_same( 'invalid_term', $bad['error'], 'the refusal names the reason' );

		igbz()->settings()->set( 'domain.provider', '' );
		$none = $this->service->search( 'shop' );
		$this->assert_false( $none['ok'], 'no provider means no search' );
		$this->assert_same( 'no_domain_provider', $none['error'], 'the refusal names the gap' );

		igbz()->settings()->set( 'domain.provider', 'stub' );
		$good = $this->service->search( 'shop' );
		$this->assert_true( $good['ok'], 'a configured provider answers' );
		$this->assert_same( 2, count( $good['results'] ), 'candidates come back' );
	}

	public function test_a_quote_is_a_price_with_a_deadline(): void {
		$this->boot();

		$bad = $this->service->quote( 7, 'nodots' );
		$this->assert_false( $bad['ok'], 'a name without a TLD is refused' );

		$quote = $this->service->quote( 7, 'shop.example.com' );
		$this->assert_true( $quote['ok'], 'a valid name is priced' );
		$this->assert_same( 9.5, (float) $quote['quote']['price'], 'the provider price lands' );
		$this->assert_same( 'USD', (string) $quote['quote']['currency'], 'the currency is normalized' );

		$expires = strtotime( (string) $quote['quote']['expires_at'] . ' UTC' );
		$this->assert_true( $expires > time(), 'the quote carries a deadline' );
		$this->assert_true( $expires <= time() + 15 * 60 + 5, 'the deadline is clamped to the configured TTL' );

		$this->adapter->quote_result = [ 'ok' => true, 'price' => 0, 'currency' => 'USD', 'ttl_minutes' => 60, 'error' => '' ];
		$zero = $this->service->quote( 7, 'free.example.com' );
		$this->assert_false( $zero['ok'], 'a zero price is refused' );
		$this->assert_same( 'invalid_price', $zero['error'], 'nothing is orderable at zero' );
	}

	public function test_an_expired_quote_cannot_create_an_order(): void {
		$this->boot();

		$this->ddb->seed( 'ig_domain_quotes', [
			'tenant_id'  => 7,
			'name'       => 'old.example.com',
			'price'      => 9.5,
			'currency'   => 'USD',
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
			'created_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
		] );

		$order = $this->service->order( 7, 'old.example.com', 'k:1' );
		$this->assert_false( $order['ok'], 'an expired quote is dead' );
		$this->assert_same( 'no_valid_quote', $order['error'], 'the refusal names the deadline' );
		$this->assert_same( 0, count( $this->ddb->tables['ig_domain_orders'] ), 'no reservation is created' );
	}

	public function test_the_order_is_idempotent_per_tenant_and_key(): void {
		$this->boot();
		$this->service->quote( 7, 'shop.example.com' );

		$first = $this->service->order( 7, 'shop.example.com', 'k:100' );
		$this->assert_true( $first['ok'], 'the first request reserves' );
		$this->assert_true( $first['created'], 'the first request creates' );
		$this->assert_same( DomainService::ORDER_RESERVED, (string) $first['order']['status'], 'the order is a reservation' );
		$this->assert_same( 9.5, (float) $first['order']['amount'], 'the order carries the quoted price' );

		$replay = $this->service->order( 7, 'shop.example.com', 'k:100' );
		$this->assert_true( $replay['ok'], 'the replay succeeds' );
		$this->assert_false( $replay['created'], 'the replay creates nothing' );
		$this->assert_same( (int) $first['order']['id'], (int) $replay['order']['id'], 'the replay returns the same order' );
		$this->assert_same( 1, count( $this->ddb->tables['ig_domain_orders'] ), 'one key, one reservation' );

		$missing = $this->service->order( 7, 'shop.example.com', '' );
		$this->assert_false( $missing['ok'], 'an order without a key is refused' );
		$this->assert_same( 'missing_idempotency_key', $missing['error'], 'the refusal names the missing key' );
	}

	public function test_reservation_never_registers_before_payment(): void {
		$this->boot();
		$this->service->quote( 7, 'shop.example.com' );

		$order = $this->service->order( 7, 'shop.example.com', 'k:200' );
		$this->assert_same( 0, count( $this->adapter->registered ), 'a reservation never calls the provider' );

		$paid = $this->service->confirm_paid( $order['order'] );
		$this->assert_true( $paid['ok'], 'payment flips the order' );
		$this->assert_same( DomainService::ORDER_PAID, (string) $this->ddb->tables['ig_domain_orders'][ (int) $order['order']['id'] ]['status'], 'the order is paid' );

		$replay = $this->service->confirm_paid( $order['order'] );
		$this->assert_true( $replay['ok'], 'a replayed confirmation stays honest' );
		$this->assert_same( DomainService::ORDER_PAID, $replay['status'], 'the replay reports paid' );
	}
}
