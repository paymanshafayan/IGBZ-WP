<?php
/**
 * Phase 36 — FX payout hardening: bills above the approval threshold never auto-settle, the
 * daily risk cap holds bills back, an unknown payout outcome parks the bill pending instead of
 * refunding (a refund there would be a double payout), resolve_payout() applies exactly one
 * verdict, and reconcile() settles pending bills through the adapter's own verdicts.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\Fx\Contracts\FxPayoutAdapterInterface;
use IGBZ\Suite\Modules\Fx\FxAccountsService;
use IGBZ\Suite\Modules\Fx\FxBillingService;
use IGBZ\Suite\Modules\Fx\FxMeter;
use IGBZ\Suite\Modules\Fx\FxPayoutRegistry;
use IGBZ\Suite\Modules\Fx\FxWalletService;
use IGBZ\Suite\Support\Db;

/** In-memory engine for bills + the FX wallet. */
final class BillHardenDb extends wpdb {

	/** @var array<string,array<int,array<string,mixed>>> */
	public array $tables = [
		'fx_bills'   => [],
		'fx_wallets' => [],
		'fx_ledger'  => [],
	];

	private int $next_id = 1;

	/** @param array<string,mixed> $row */
	public function seed( string $table, array $row ): int {
		$id                        = $this->next_id++;
		$row['id']                 = $id;
		$this->tables[ $table ][ $id ] = $row;

		return $id;
	}

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;
		$table           = self::which( $sql );

		if ( 'fx_bills' === $table ) {
			return $this->tables[ $table ][ self::int_of( 'id', $sql ) ] ?? null;
		}

		if ( 'fx_wallets' === $table ) {
			$rows = $this->matching( $table, $sql, [ 'tenant_id' ] );
			return $rows[0] ?? null;
		}

		if ( 'fx_ledger' === $table ) {
			$rows = $this->matching( $table, $sql, [ 'tenant_id', 'reason', 'reference' ] );
			return $rows[0] ?? null;
		}

		return parent::get_row( $sql, $output );
	}

	public function get_results( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'igbz_fx_bills' ) && str_contains( $sql, 'status' ) ) {
			$wanted = self::value_of( 'status', $sql );
			$rows   = array_values( array_filter(
				$this->tables['fx_bills'],
				static fn ( $r ): bool => (string) $r['status'] === (string) $wanted
			) );
			usort( $rows, static fn ( $a, $b ): int => (int) $a['id'] <=> (int) $b['id'] );
			if ( preg_match( '/LIMIT (\d+)/', $sql, $m ) ) {
				$rows = array_slice( $rows, 0, (int) $m[1] );
			}
			return $rows;
		}

		return parent::get_results( $sql, $output );
	}

	public function get_var( string $sql ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'COUNT(*)' ) && str_contains( $sql, 'igbz_fx_ledger' ) ) {
			return (string) count( $this->matching( 'fx_ledger', $sql, [ 'tenant_id', 'reason', 'reference' ] ) );
		}

		if ( str_contains( $sql, 'SUM( amount_usd )' ) && str_contains( $sql, 'igbz_fx_bills' ) ) {
			$today = gmdate( 'Y-m-d' ) . ' 00:00:00';
			$sum   = 0.0;
			foreach ( $this->tables['fx_bills'] as $row ) {
				$pending = 'pending' === (string) $row['status'];
				$paid_today = 'paid' === (string) $row['status'] && (string) ( $row['paid_at'] ?? '' ) >= $today;
				if ( $pending || $paid_today ) {
					$sum += (float) $row['amount_usd'];
				}
			}
			return (string) $sum;
		}

		return parent::get_var( $sql );
	}

	public function insert( string $table, array $data, $format = null ): int|bool {
		$this->queries[] = 'INSERT INTO ' . $table;
		$this->last_write = [ 'table' => $table, 'data' => $data ];
		$this->writes[]   = $this->last_write;

		$short = self::which( 'igbz_' . str_replace( $this->prefix . 'igbz_', '', $table ) );
		if ( '' === $short ) {
			return parent::insert( $table, $data, $format );
		}

		$this->insert_id = $this->seed( $short, $data );

		return 1;
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		$this->queries[] = 'UPDATE ' . $table;

		$short   = self::which( 'igbz_' . str_replace( $this->prefix . 'igbz_', '', $table ) );
		$changed = 0;
		foreach ( $this->tables[ $short ] ?? [] as $id => $row ) {
			$hit = true;
			foreach ( $where as $column => $value ) {
				if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) {
					$hit = false;
					break;
				}
			}
			if ( $hit ) {
				$this->tables[ $short ][ $id ] = array_merge( $row, $data );
				++$changed;
			}
		}

		return $changed;
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

	private static function which( string $sql ): string {
		foreach ( [ 'fx_bills', 'fx_wallets', 'fx_ledger' ] as $name ) {
			if ( str_contains( $sql, 'igbz_' . $name ) ) {
				return $name;
			}
		}
		return '';
	}

	private static function value_of( string $column, string $sql ): ?string {
		return preg_match( '/\b' . preg_quote( $column, '/' ) . " = '([^']*)'/", $sql, $m ) ? $m[1] : null;
	}

	private static function int_of( string $column, string $sql ): int {
		return (int) self::value_of( $column, $sql );
	}

	/** @return array<int,array<string,mixed>> */
	private function matching( string $table, string $sql, array $columns ): array {
		$out = [];
		foreach ( $this->tables[ $table ] ?? [] as $row ) {
			foreach ( $columns as $column ) {
				$wanted = self::value_of( $column, $sql );
				if ( null !== $wanted && (string) ( $row[ $column ] ?? '' ) !== $wanted ) {
					continue 2;
				}
			}
			$out[] = $row;
		}
		return $out;
	}
}

/** A payout adapter whose answer is scripted; optionally answers reconcile queries. */
final class ScriptedPayoutAdapter implements FxPayoutAdapterInterface {

	public array $paid = [];

	public function __construct(
		public array $pay_result = [ 'ok' => true, 'reference' => 'stub:1', 'error' => '' ],
		public bool $throw_on_pay = false,
		public array $query_result = []
	) {}

	public function id(): string {
		return 'stub';
	}

	public function title(): string {
		return 'Stub';
	}

	public function is_configured(): bool {
		return true;
	}

	public function pay( array $bill ): array {
		if ( $this->throw_on_pay ) {
			throw new RuntimeException( 'socket went away mid-charge' );
		}
		$this->paid[] = (int) $bill['id'];
		return $this->pay_result;
	}

	public function query( array $bill ): array {
		return $this->query_result;
	}

	public function card_balance(): float {
		return 100.0;
	}

	public function webhook( array $payload ): void {}
}

final class FxBillingHardenTest extends TestCase {

	private Db $db;
	private BillHardenDb $fxdb;
	private ScriptedPayoutAdapter $adapter;
	private FxBillingService $billing;
	private FxWalletService $wallet;

	private function boot(): void {
		igbz_test_reset_settings();
		igbz()->settings()->set( 'fx.payout_provider', 'stub' );

		$this->fxdb          = new BillHardenDb();
		$GLOBALS['wpdb']     = $this->fxdb;

		$this->db = new Db();
		// is_sqlite() = true makes lock()/unlock() no-ops, exactly like the playground runtime.
		$ref = new ReflectionProperty( Db::class, 'is_sqlite' );
		$ref->setValue( $this->db, true );

		$this->adapter       = new ScriptedPayoutAdapter();
		$this->wallet        = new FxWalletService( $this->db );

		$settings = igbz()->settings();
		$logger   = new IGBZ\Suite\Support\Logger( $settings );
		$registry = new FxPayoutRegistry();
		$registry->register( $this->adapter );

		$this->billing = new FxBillingService(
			$this->db,
			$settings,
			$this->wallet,
			new FxMeter( $this->db, $this->wallet, $logger ),
			$registry,
			new FxAccountsService( $this->db ),
			$logger
		);

		$this->fxdb->seed( 'fx_wallets', [ 'tenant_id' => 7, 'balance_usd' => 100.0, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ] );
	}

	public function run(): void {
		$this->test_a_bill_above_the_approval_threshold_never_auto_settles();
		$this->test_the_daily_risk_cap_holds_a_bill_back();
		$this->test_an_unknown_payout_outcome_parks_the_bill_pending();
		$this->test_an_adapter_exception_is_treated_as_unknown();
		$this->test_resolve_payout_applies_exactly_one_verdict();
		$this->test_a_failed_pending_payout_refunds_and_returns_the_bill_to_due();
		$this->test_reconcile_asks_the_adapter_and_applies_its_verdicts();
		$this->test_reconcile_never_guesses_on_an_unknown_verdict();
	}

	private function due_bill( float $amount ): array {
		$id = $this->fxdb->seed( 'fx_bills', [
			'tenant_id'     => 7,
			'fx_account_id' => 3,
			'period_start'  => gmdate( 'Y-m-01' ),
			'period_end'    => gmdate( 'Y-m-t' ),
			'amount_usd'    => $amount,
			'status'        => FxBillingService::STATUS_DUE,
			'payout_ref'    => '',
			'paid_at'       => null,
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
		] );

		return $this->fxdb->tables['fx_bills'][ $id ];
	}

	public function test_a_bill_above_the_approval_threshold_never_auto_settles(): void {
		$this->boot();
		igbz()->settings()->set( 'fx.payout_approval_threshold_usd', '10' );
		$bill = $this->due_bill( 15 );

		$result = $this->billing->settle_bill( $bill );

		$this->assert_false( $result['ok'], 'a large bill is refused' );
		$this->assert_same( 'requires_approval', $result['error'], 'the refusal names the gate' );
		$this->assert_same( FxBillingService::STATUS_DUE, $this->fxdb->tables['fx_bills'][ (int) $bill['id'] ]['status'], 'the bill stays due' );
		$this->assert_same( 0, count( $this->adapter->paid ), 'the adapter is never asked' );
		$this->assert_same( 100.0, $this->wallet->balance( 7 )['balance_usd'], 'the wallet is untouched' );
	}

	public function test_the_daily_risk_cap_holds_a_bill_back(): void {
		$this->boot();
		igbz()->settings()->set( 'fx.payout_daily_cap_usd', '12' );
		// Eight dollars already went out today.
		$this->fxdb->seed( 'fx_bills', [
			'tenant_id' => 7, 'fx_account_id' => 2, 'amount_usd' => 8.0,
			'status' => 'paid', 'paid_at' => gmdate( 'Y-m-d H:i:s' ), 'payout_ref' => 'stub:0',
			'period_start' => gmdate( 'Y-m-01' ), 'period_end' => gmdate( 'Y-m-t' ), 'created_at' => gmdate( 'Y-m-d H:i:s' ),
		] );
		$bill = $this->due_bill( 5 );

		$result = $this->billing->settle_bill( $bill );

		$this->assert_false( $result['ok'], 'the cap blocks the payout' );
		$this->assert_same( 'daily_cap_reached', $result['error'], 'the refusal names the cap' );
		$this->assert_same( FxBillingService::STATUS_DUE, $this->fxdb->tables['fx_bills'][ (int) $bill['id'] ]['status'], 'the bill waits for tomorrow' );
		$this->assert_same( 0, count( $this->adapter->paid ), 'the adapter is never asked' );
	}

	public function test_an_unknown_payout_outcome_parks_the_bill_pending(): void {
		$this->boot();
		$this->adapter->pay_result = [ 'ok' => false, 'reference' => '', 'error' => 'timeout', 'state' => 'pending' ];
		$bill = $this->due_bill( 5 );

		$result = $this->billing->settle_bill( $bill );

		$this->assert_false( $result['ok'], 'no confirmation yet' );
		$this->assert_same( FxBillingService::STATUS_PENDING, $this->fxdb->tables['fx_bills'][ (int) $bill['id'] ]['status'], 'the bill sits pending' );
		$this->assert_same( 95.0, $this->wallet->balance( 7 )['balance_usd'], 'the debit is kept — refunding now would pay twice' );
	}

	public function test_an_adapter_exception_is_treated_as_unknown(): void {
		$this->boot();
		$this->adapter->throw_on_pay = true;
		$bill = $this->due_bill( 5 );

		$result = $this->billing->settle_bill( $bill );

		$this->assert_false( $result['ok'], 'a thrown adapter is not a settled bill' );
		$this->assert_same( FxBillingService::STATUS_PENDING, $this->fxdb->tables['fx_bills'][ (int) $bill['id'] ]['status'], 'the doubt parks the bill pending' );
		$this->assert_same( 95.0, $this->wallet->balance( 7 )['balance_usd'], 'no refund on unknown' );
	}

	public function test_resolve_payout_applies_exactly_one_verdict(): void {
		$this->boot();
		$this->adapter->pay_result = [ 'ok' => false, 'reference' => '', 'error' => 'timeout', 'state' => 'pending' ];
		$bill = $this->due_bill( 5 );
		$this->billing->settle_bill( $bill );

		$first = $this->billing->resolve_payout( $bill, true, 'psp:900' );
		$this->assert_true( $first['ok'], 'the verdict lands' );
		$this->assert_same( 'paid', $this->fxdb->tables['fx_bills'][ (int) $bill['id'] ]['status'], 'the bill is paid' );
		$this->assert_same( 'psp:900', $this->fxdb->tables['fx_bills'][ (int) $bill['id'] ]['payout_ref'], 'the provider reference is recorded' );

		$replay = $this->billing->resolve_payout( $bill, false );
		$this->assert_false( $replay['ok'], 'a replayed verdict is inert' );
		$this->assert_same( 'not_pending', $replay['error'], 'the replay says why' );
		$this->assert_same( 95.0, $this->wallet->balance( 7 )['balance_usd'], 'no double refund either' );
	}

	public function test_a_failed_pending_payout_refunds_and_returns_the_bill_to_due(): void {
		$this->boot();
		$this->adapter->pay_result = [ 'ok' => false, 'reference' => '', 'error' => 'timeout', 'state' => 'pending' ];
		$bill = $this->due_bill( 5 );
		$this->billing->settle_bill( $bill );

		$result = $this->billing->resolve_payout( $bill, false );

		$this->assert_same( FxBillingService::STATUS_DUE, $result['status'], 'the bill goes back to due' );
		$this->assert_same( 100.0, $this->wallet->balance( 7 )['balance_usd'], 'the debit is returned' );
	}

	public function test_reconcile_asks_the_adapter_and_applies_its_verdicts(): void {
		$this->boot();
		// Both bills were debited when they went pending: 100 - 4 - 6.
		$this->fxdb->tables['fx_wallets'][1]['balance_usd'] = 90.0;
		$settled = $this->due_bill( 4 );
		$failed  = $this->due_bill( 6 );
		$this->fxdb->tables['fx_bills'][ (int) $settled['id'] ]['status'] = FxBillingService::STATUS_PENDING;
		$this->fxdb->tables['fx_bills'][ (int) $failed['id'] ]['status']  = FxBillingService::STATUS_PENDING;

		$this->adapter->query_result = [ 'state' => 'settled', 'reference' => 'psp:700' ];
		$first = $this->billing->reconcile();
		$this->assert_same( 2, $first['scanned'], 'both pendings are visited' );
		$this->assert_same( 2, $first['resolved'], 'the verdicts apply' );
		$this->assert_same( 'paid', $this->fxdb->tables['fx_bills'][ (int) $settled['id'] ]['status'], 'the settled bill is paid' );
		$this->assert_same( 'psp:700', $this->fxdb->tables['fx_bills'][ (int) $settled['id'] ]['payout_ref'], 'the provider reference lands' );

		// The provider reverses itself on the second bill; reconcile refunds it.
		$this->fxdb->tables['fx_bills'][ (int) $failed['id'] ]['status'] = FxBillingService::STATUS_PENDING;
		$this->adapter->query_result = [ 'state' => 'failed', 'reference' => '' ];
		$second = $this->billing->reconcile();
		$this->assert_same( 1, $second['refunded'], 'the failed verdict refunds' );
		$this->assert_same( FxBillingService::STATUS_DUE, $this->fxdb->tables['fx_bills'][ (int) $failed['id'] ]['status'], 'the failed bill goes back to due' );
		$this->assert_same( 96.0, $this->wallet->balance( 7 )['balance_usd'], 'one bill stayed paid, one came back' );
	}

	public function test_reconcile_never_guesses_on_an_unknown_verdict(): void {
		$this->boot();
		// The debit already landed when the bill went pending: 100 - 5.
		$this->fxdb->tables['fx_wallets'][1]['balance_usd'] = 95.0;
		$bill = $this->due_bill( 5 );
		$this->fxdb->tables['fx_bills'][ (int) $bill['id'] ]['status'] = FxBillingService::STATUS_PENDING;
		$this->adapter->query_result = [ 'state' => 'unknown', 'reference' => '' ];

		$out = $this->billing->reconcile();

		$this->assert_same( 1, $out['scanned'], 'the pending is visited' );
		$this->assert_same( 1, $out['unresolved'], 'an unknown verdict changes nothing' );
		$this->assert_same( FxBillingService::STATUS_PENDING, $this->fxdb->tables['fx_bills'][ (int) $bill['id'] ]['status'], 'the bill stays pending' );
		$this->assert_same( 95.0, $this->wallet->balance( 7 )['balance_usd'], 'the debit is kept until proven otherwise' );
	}
}
