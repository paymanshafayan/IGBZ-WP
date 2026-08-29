<?php
/**
 * Phase 38 — domain registration, signed callback and compensation: registration only runs
 * on a paid order, a provider failure refunds idempotently and journals the event, callback
 * signatures are verified, and backup polling settles stuck orders through the same path.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\Domain\DomainService;
use IGBZ\Suite\Modules\Fx\FxWalletService;
use IGBZ\Suite\Support\Db;

/** In-memory engine for orders, journal and the FX wallet. */
final class DomainRegDb extends wpdb {

	/** @var array<string,array<int,array<string,mixed>>> */
	public array $tables = [
		'ig_domain_orders'  => [],
		'ig_domain_journal' => [],
		'fx_wallets'        => [],
		'fx_ledger'         => [],
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

		if ( str_contains( $sql, 'ig_domain_orders' ) && preg_match( "/WHERE id = '?(\d+)'?/", $sql, $m ) ) {
			return $this->tables['ig_domain_orders'][ (int) $m[1] ] ?? null;
		}

		if ( str_contains( $sql, 'fx_wallets' ) ) {
			foreach ( $this->tables['fx_wallets'] as $row ) {
				if ( preg_match( "/tenant_id = '?(\d+)'?/", $sql, $m ) && (string) $row['tenant_id'] === $m[1] ) {
					return $row;
				}
			}
			return null;
		}

		if ( str_contains( $sql, 'fx_ledger' ) ) {
			$rows = $this->matching( 'fx_ledger', $sql, [ 'tenant_id', 'reason', 'reference' ] );
			return $rows[0] ?? null;
		}

		return parent::get_row( $sql, $output );
	}

	public function get_results( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'ig_domain_orders' ) && preg_match( "/status = '([^']*)'/", $sql, $m ) ) {
			$out = array_values( array_filter(
				$this->tables['ig_domain_orders'],
				static fn ( $r ): bool => (string) $r['status'] === $m[1]
			) );
			usort( $out, static fn ( $a, $b ): int => (int) $a['id'] <=> (int) $b['id'] );
			return array_slice( $out, 0, 100 );
		}

		if ( str_contains( $sql, 'ig_domain_journal' ) && preg_match( "/order_id = '?(\d+)'?/", $sql, $m ) ) {
			return array_values( array_filter(
				$this->tables['ig_domain_journal'],
				static fn ( $r ): bool => (string) $r['order_id'] === $m[1]
			) );
		}

		return parent::get_results( $sql, $output );
	}

	public function get_var( string $sql ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'COUNT(*)' ) && str_contains( $sql, 'igbz_fx_ledger' ) ) {
			return (string) count( $this->matching( 'fx_ledger', $sql, [ 'tenant_id', 'reason', 'reference' ] ) );
		}

		return parent::get_var( $sql );
	}

	public function insert( string $table, array $data, $format = null ): int|bool {
		$this->queries[] = 'INSERT INTO ' . $table;
		$this->last_write = [ 'table' => $table, 'data' => $data ];
		$this->writes[]   = $this->last_write;

		foreach ( [ 'ig_domain_orders', 'ig_domain_journal', 'fx_wallets', 'fx_ledger' ] as $name ) {
			if ( str_contains( $table, $name ) ) {
				$this->insert_id = $this->seed( $name, $data );
				return 1;
			}
		}

		return parent::insert( $table, $data, $format );
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		$this->queries[] = 'UPDATE ' . $table;

		foreach ( [ 'ig_domain_orders', 'ig_domain_journal', 'fx_wallets', 'fx_ledger' ] as $name ) {
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

	public function query( string $sql ): int|bool {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'INSERT INTO' ) && str_contains( $sql, 'ON DUPLICATE KEY UPDATE' ) ) {
			if ( preg_match( "/VALUES \('([^']*)', '([^']*)', '([^']*)'\)/", $sql, $m ) ) {
				$tenant  = (int) $m[1];
				$balance = (float) $m[2];

				foreach ( $this->tables['fx_wallets'] as $id => $row ) {
					if ( (int) $row['tenant_id'] === $tenant ) {
						$this->tables['fx_wallets'][ $id ]['balance_usd'] = $balance;
						return 1;
					}
				}
				$this->seed( 'fx_wallets', [ 'tenant_id' => $tenant, 'balance_usd' => $balance, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ] );
				return 1;
			}
		}

		return parent::query( $sql );
	}

	/** @return array<int,array<string,mixed>> */
	private function matching( string $table, string $sql, array $columns ): array {
		$out = [];
		foreach ( $this->tables[ $table ] ?? [] as $row ) {
			foreach ( $columns as $column ) {
				if ( preg_match( '/\b' . preg_quote( $column, '/' ) . " = '([^']*)'/", $sql, $m ) && (string) ( $row[ $column ] ?? '' ) !== $m[1] ) {
					continue 2;
				}
			}
			$out[] = $row;
		}
		return $out;
	}
}

/** A registrar whose register() answer is scripted. */
final class RegisteringStubAdapter implements \IGBZ\Suite\Modules\Domain\Contracts\DomainAdapterInterface {

	public function __construct(
		public array $register_result = [ 'ok' => true, 'reference' => 'reg:1', 'error' => '' ],
		public array $query_result = [ 'state' => 'unknown', 'reference' => '', 'error' => '' ]
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
		return [ 'ok' => true, 'results' => [], 'error' => '' ];
	}

	public function quote( string $name ): array {
		return [ 'ok' => true, 'price' => 9.5, 'currency' => 'USD', 'ttl_minutes' => 60, 'error' => '' ];
	}

	public function register( array $order ): array {
		return $this->register_result;
	}

	public function query( array $order ): array {
		return $this->query_result;
	}
}

final class DomainRegistrationTest extends TestCase {

	private Db $db;
	private DomainRegDb $ddb;
	private RegisteringStubAdapter $adapter;
	private DomainService $service;
	private FxWalletService $wallet;

	private function boot(): void {
		igbz_test_reset_settings();
		igbz()->settings()->set( 'domain.provider', 'stub' );

		$this->ddb         = new DomainRegDb();
		$GLOBALS['wpdb']   = $this->ddb;

		$this->db = new Db();
		$ref = new ReflectionProperty( Db::class, 'is_sqlite' );
		$ref->setValue( $this->db, true );

		$this->adapter = new RegisteringStubAdapter();
		$registry      = new \IGBZ\Suite\Modules\Domain\DomainAdapterRegistry();
		$registry->register( $this->adapter );

		$this->wallet  = new FxWalletService( $this->db );
		$this->service = new DomainService( $this->db, igbz()->settings(), $registry, new IGBZ\Suite\Support\Logger( igbz()->settings() ), $this->wallet );

		$this->ddb->seed( 'fx_wallets', [ 'tenant_id' => 7, 'balance_usd' => 50.0, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ] );
	}

	private function paid_order(): array {
		$id = $this->ddb->seed( 'ig_domain_orders', [
			'tenant_id'       => 7,
			'domain_id'       => 0,
			'action'          => 'register',
			'amount'          => 9.5,
			'status'          => DomainService::ORDER_PAID,
			'provider_ref'    => '',
			'idempotency_key' => 'k:' . $this->ddb->insert_id,
			'created_at'      => gmdate( 'Y-m-d H:i:s' ),
		] );

		return $this->ddb->tables['ig_domain_orders'][ $id ];
	}

	public function run(): void {
		$this->test_registration_refuses_anything_that_is_not_paid();
		$this->test_a_successful_registration_records_its_evidence();
		$this->test_a_failed_registration_refunds_idempotently_and_journals();
		$this->test_callback_signatures_are_verified();
		$this->test_provider_verdicts_apply_through_one_path();
		$this->test_backup_polling_settles_stuck_orders();
	}

	public function test_registration_refuses_anything_that_is_not_paid(): void {
		$this->boot();
		$order = $this->paid_order();
		$this->ddb->tables['ig_domain_orders'][ (int) $order['id'] ]['status'] = DomainService::ORDER_RESERVED;

		$result = $this->service->register_paid( $order );

		$this->assert_false( $result['ok'], 'a reservation cannot be registered' );
		$this->assert_same( 'not_paid', $result['error'], 'the refusal names the gate' );
		$this->assert_same( DomainService::ORDER_RESERVED, $this->ddb->tables['ig_domain_orders'][ (int) $order['id'] ]['status'], 'the order is untouched' );
	}

	public function test_a_successful_registration_records_its_evidence(): void {
		$this->boot();
		$order = $this->paid_order();

		$result = $this->service->register_paid( $order );

		$this->assert_true( $result['ok'], 'the provider accepts' );
		$this->assert_same( DomainService::ORDER_REGISTERED, $result['status'], 'the order is registered' );
		$this->assert_same( 'reg:1', $this->ddb->tables['ig_domain_orders'][ (int) $order['id'] ]['provider_ref'], 'the provider reference lands' );

		$events = array_column( $this->ddb->tables['ig_domain_journal'], 'event' );
		$this->assert_true( in_array( 'register_started', $events, true ), 'the attempt is journaled' );
		$this->assert_true( in_array( 'registered', $events, true ), 'the success is journaled' );

		$replay = $this->service->register_paid( $order );
		$this->assert_true( $replay['ok'], 'a replay stays honest' );
		$this->assert_same( DomainService::ORDER_REGISTERED, $replay['status'], 'the replay reports registered' );
	}

	public function test_a_failed_registration_refunds_idempotently_and_journals(): void {
		$this->boot();
		$this->adapter->register_result = [ 'ok' => false, 'reference' => '', 'error' => 'tld_unavailable' ];
		$order = $this->paid_order();

		$result = $this->service->register_paid( $order );

		$this->assert_false( $result['ok'], 'the provider refuses' );
		$this->assert_same( DomainService::ORDER_FAILED, $result['status'], 'the order is failed' );
		$this->assert_same( 59.5, $this->wallet->balance( 7 )['balance_usd'], 'the money comes back' );

		$events = array_column( $this->ddb->tables['ig_domain_journal'], 'event' );
		$this->assert_true( in_array( 'register_failed', $events, true ), 'the failure is journaled' );
		$this->assert_true( in_array( 'refunded', $events, true ), 'the refund is journaled' );

		// A replayed failure path cannot refund twice: the wallet credit is (reason, reference) idempotent.
		$this->service->refund_order( $this->ddb->tables['ig_domain_orders'][ (int) $order['id'] ] );
		$this->assert_same( 59.5, $this->wallet->balance( 7 )['balance_usd'], 'a replayed refund is a no-op' );
	}

	public function test_callback_signatures_are_verified(): void {
		$this->boot();
		igbz()->settings()->set( 'domain.webhook_secret', 'sekret' );
		$body = '{"order_id":1,"status":"registered"}';

		$this->assert_true(
			$this->service->verify_callback( $body, hash_hmac( 'sha256', $body, 'sekret' ) ),
			'a correctly signed callback passes'
		);
		$this->assert_false( $this->service->verify_callback( $body, 'deadbeef' ), 'a forged signature is refused' );

		igbz()->settings()->set( 'domain.webhook_secret', '' );
		$this->assert_false( $this->service->verify_callback( $body, hash_hmac( 'sha256', $body, 'sekret' ) ), 'no secret means no callback' );
	}

	public function test_provider_verdicts_apply_through_one_path(): void {
		$this->boot();
		$order = $this->paid_order();
		$this->ddb->tables['ig_domain_orders'][ (int) $order['id'] ]['status'] = DomainService::ORDER_REGISTERING;

		$ok = $this->service->apply_provider_result( (int) $order['id'], true, 'reg:42' );
		$this->assert_true( $ok['ok'], 'a success verdict lands' );
		$this->assert_same( DomainService::ORDER_REGISTERED, $ok['status'], 'the order is registered' );
		$this->assert_same( 'reg:42', $this->ddb->tables['ig_domain_orders'][ (int) $order['id'] ]['provider_ref'], 'the verdict reference lands' );

		$replay = $this->service->apply_provider_result( (int) $order['id'], false );
		$this->assert_true( $replay['ok'], 'a contradicting replay is inert' );
		$this->assert_same( DomainService::ORDER_REGISTERED, $replay['status'], 'the settled order stays settled' );
		$this->assert_same( 50.0, $this->wallet->balance( 7 )['balance_usd'], 'no refund for a registered domain' );

		$second = $this->paid_order();
		$this->ddb->tables['ig_domain_orders'][ (int) $second['id'] ]['status'] = DomainService::ORDER_REGISTERING;
		$bad = $this->service->apply_provider_result( (int) $second['id'], false );
		$this->assert_same( DomainService::ORDER_FAILED, $bad['status'], 'a failure verdict fails the order' );
		$this->assert_same( 59.5, $this->wallet->balance( 7 )['balance_usd'], 'the failure refunds' );
	}

	public function test_backup_polling_settles_stuck_orders(): void {
		$this->boot();
		$order = $this->paid_order();
		$this->ddb->tables['ig_domain_orders'][ (int) $order['id'] ]['status']    = DomainService::ORDER_REGISTERING;
		$this->ddb->tables['ig_domain_orders'][ (int) $order['id'] ]['created_at'] = gmdate( 'Y-m-d H:i:s', time() - 6 * 3600 );

		$this->adapter->query_result = [ 'state' => 'registered', 'reference' => 'reg:77', 'error' => '' ];
		$out = $this->service->poll_stuck();

		$this->assert_same( 1, $out['scanned'], 'the stuck order is visited' );
		$this->assert_same( 1, $out['resolved'], 'the verdict applies' );
		$this->assert_same( DomainService::ORDER_REGISTERED, $this->ddb->tables['ig_domain_orders'][ (int) $order['id'] ]['status'], 'polling settles the order' );

		// A young order is not stuck yet.
		$young = $this->paid_order();
		$this->ddb->tables['ig_domain_orders'][ (int) $young['id'] ]['status'] = DomainService::ORDER_REGISTERING;
		$this->adapter->query_result = [ 'state' => 'unknown', 'reference' => '', 'error' => '' ];
		$out2 = $this->service->poll_stuck();
		$this->assert_same( 0, $out2['scanned'], 'young orders are left alone' );
	}
}
