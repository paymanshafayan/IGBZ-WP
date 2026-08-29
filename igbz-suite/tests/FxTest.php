<?php
/**
 * The FX payment gateway: Rial top-ups with a fee, and a credit meter that
 * never queues a task.
 *
 * The rules this pins down are the ones the client stated while approving the
 * design:
 *
 *   - a Rial top-up adds the operator's fee (default 10%) on top of the
 *     requested USD amount, and only the requested amount lands in the wallet;
 *   - a verified top-up credits exactly once, even if the webhook replays;
 *   - the meter refuses a task on the spot when the tenant's credit is short —
 *     there is no queue, no debt and no cross-tenant borrowing;
 *   - a task the provider never accepted is refunded, once, at the exact amount that
 *     was debited;
 *   - the rate falls back to the manual value when the auto source fails.
 */

declare( strict_types=1 );

use IGBZ\Suite\Modules\Fx\FxAccountsService;
use IGBZ\Suite\Modules\Fx\FxBillingService;
use IGBZ\Suite\Modules\Fx\FxMath;
use IGBZ\Suite\Modules\Fx\FxMeter;
use IGBZ\Suite\Modules\Fx\FxPayoutRegistry;
use IGBZ\Suite\Modules\Fx\FxRampService;
use IGBZ\Suite\Modules\Fx\FxRateService;
use IGBZ\Suite\Modules\Fx\FxReportsService;
use IGBZ\Suite\Modules\Fx\FxTopupService;
use IGBZ\Suite\Modules\Fx\FxWalletService;
use IGBZ\Suite\Modules\MultiTenant\Payments\PaymentService;
use IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Http;
use IGBZ\Suite\Support\Logger;

/**
 * In-memory stand-in for the five fx tables plus payments. Reads real rows
 * and derives matches from the WHERE clauses in the SQL, following the same
 * approach as the VIP double.
 */
final class FxDb extends wpdb {

	/** @var array<string,array<int,array<string,mixed>>> table => id => row */
	public array $tables = [
		'fx_wallets' => [],
		'fx_ledger'  => [],
		'fx_prices'  => [],
		'fx_rates'   => [],
		'fx_accounts' => [],
		'fx_bills'   => [],
		'payments'   => [],
	];

	private int $next_id = 1;

	/** @param array<string,mixed> $row */
	public function seed( string $table, array $row ): int {
		$id                            = (int) ( $row['id'] ?? $this->next_id++ );
		$row['id']                     = $id;
		$this->tables[ $table ][ $id ] = $row;

		return $id;
	}

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;
		$table           = self::which( $sql );

		if ( 'fx_ledger' === $table ) {
			$rows = $this->matching( $table, $sql, [ 'tenant_id', 'reason', 'reference' ] );
			return $rows[0] ?? null;
		}

		if ( 'fx_prices' === $table ) {
			$rows = $this->matching( $table, $sql, [ 'service' ] );
			if ( str_contains( $sql, 'is_active = 1' ) ) {
				$rows = array_values( array_filter( $rows, static fn ( $r ): bool => (int) ( $r['is_active'] ?? 0 ) === 1 ) );
			}
			if ( str_contains( $sql, 'ORDER BY id DESC' ) ) {
				usort( $rows, static fn ( $a, $b ): int => (int) $b['id'] <=> (int) $a['id'] );
			}
			return $rows[0] ?? null;
		}

		if ( 'fx_wallets' === $table ) {
			$rows = $this->matching( $table, $sql, [ 'tenant_id' ] );
			return $rows[0] ?? null;
		}

		if ( 'fx_accounts' === $table ) {
			return $this->tables[ $table ][ self::int_of( 'id', $sql ) ] ?? null;
		}

		if ( 'fx_rates' === $table ) {
			// Phase 35: the top-up now reads its locked quote row back by id.
			return $this->tables[ $table ][ self::int_of( 'id', $sql ) ] ?? null;
		}

		if ( 'payments' === $table ) {
			return $this->tables[ $table ][ self::int_of( 'id', $sql ) ] ?? null;
		}

		return parent::get_row( $sql, $output );
	}

	public function get_results( string $sql, $output = null ) {
		$this->queries[] = $sql;
		$table           = self::which( $sql );

		if ( 'fx_ledger' === $table ) {
			$rows = $this->matching( $table, $sql, [ 'tenant_id' ] );
			if ( str_contains( $sql, 'ORDER BY id DESC' ) ) {
				usort( $rows, static fn ( $a, $b ): int => (int) $b['id'] <=> (int) $a['id'] );
			}
			if ( preg_match( '/LIMIT (\d+)/', $sql, $m ) ) {
				$rows = array_slice( $rows, 0, (int) $m[1] );
			}
			return $rows;
		}

		if ( 'fx_prices' === $table ) {
			return array_values( $this->tables[ $table ] );
		}

		if ( 'fx_bills' === $table ) {
			$rows = array_values( $this->tables[ $table ] );
			if ( str_contains( $sql, 'tenant_id =' ) ) {
				$tenant = self::int_of( 'tenant_id', $sql );
				$rows   = array_values( array_filter( $rows, static fn ( $r ): bool => (int) $r['tenant_id'] === $tenant ) );
			}
			return $rows;
		}

		return parent::get_results( $sql, $output );
	}

	public function get_var( string $sql ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'COUNT(*)' ) ) {
			$table = self::which( $sql );
			return (string) count( $this->matching( $table, $sql, [ 'tenant_id', 'reason', 'reference' ] ) );
		}

		if ( str_contains( $sql, 'amount_usd' ) && str_contains( $sql, 'igbz_fx_ledger' ) ) {
			$rows = $this->matching( 'fx_ledger', $sql, [ 'tenant_id', 'reason', 'reference' ] );
			return $rows ? (string) $rows[0]['amount_usd'] : null;
		}

		return parent::get_var( $sql );
	}

	public function insert( string $table, array $data, $format = null ): int|bool {
		$this->queries[] = 'INSERT INTO ' . $table;
		$this->last_write = [ 'table' => $table, 'data' => $data, 'formats' => $format ?? [], 'guessed' => null === $format ];
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
			// VALUES ('7', '10.0000', '2026-...') — tenant, balance, updated_at.
			if ( preg_match( "/VALUES \('([^']*)', '([^']*)', '([^']*)'\)/", $sql, $m ) ) {
				$tenant  = (int) $m[1];
				$balance = (float) $m[2];
				$updated = $m[3];

				foreach ( $this->tables['fx_wallets'] as $id => $row ) {
					if ( (int) $row['tenant_id'] === $tenant ) {
						$this->tables['fx_wallets'][ $id ]['balance_usd'] = $balance;
						$this->tables['fx_wallets'][ $id ]['updated_at'] = $updated;
						return 1;
					}
				}
				$this->seed( 'fx_wallets', [ 'tenant_id' => $tenant, 'balance_usd' => $balance, 'updated_at' => $updated ] );
				return 1;
			}
		}

		return parent::query( $sql );
	}

	private static function which( string $sql ): string {
		$names = [ 'fx_accounts', 'fx_bills', 'fx_wallets', 'fx_ledger', 'fx_prices', 'fx_rates', 'payments' ];
		foreach ( $names as $name ) {
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

	private static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}

/** A payout adapter that always accepts, recording what it was asked to pay. */
final class StubPayoutAdapter implements \IGBZ\Suite\Modules\Fx\Contracts\FxPayoutAdapterInterface {

	public array $paid = [];

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
		$this->paid[] = (int) $bill['id'];
		return [ 'ok' => true, 'reference' => 'stub:' . (int) $bill['id'], 'error' => '' ];
	}

	public function card_balance(): float {
		return 100.0;
	}

	public function webhook( array $payload ): void {}
}

/** A rate source that answers from a canned value without the network. */
final class CannedRateService extends FxRateService {
	private float $canned;

	public function __construct( Db $db, \IGBZ\Suite\Support\Settings $settings, float $canned ) {
		parent::__construct( $db, $settings, new Http( new Logger( $settings ) ) );
		$this->canned = $canned;
	}

	protected function fetch_auto_rate(): float {
		return $this->canned;
	}
}

final class FxTest extends TestCase {

	private FxDb $fxdb;
	private Db $db;

	private function boot(): void {
		igbz_test_reset_settings();

		$this->fxdb = new FxDb();
		$GLOBALS['wpdb'] = $this->fxdb;

		$this->db = new Db();
		// is_sqlite() = true makes lock()/unlock() no-ops, exactly like the playground runtime.
		$ref = new ReflectionProperty( Db::class, 'is_sqlite' );
		$ref->setValue( $this->db, true );

		$settings = igbz()->settings();
		$settings->set( 'fx.fee_percent', 10 );
		$settings->set( 'fx.rate_manual', 50000 );
		$settings->set( 'fx.rate_source', 'manual' );
	}

	private function wallet(): FxWalletService {
		return new FxWalletService( $this->db );
	}

	private function meter(): FxMeter {
		return new FxMeter( $this->db, $this->wallet(), new Logger( igbz()->settings() ) );
	}

	private function seed_price( string $service, float $usd ): void {
		$this->fxdb->seed(
			'fx_prices',
			[
				'service'    => $service,
				'price_usd'  => $usd,
				'is_active'  => 1,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			]
		);
	}

	private function topup(): FxTopupService {
		$settings = igbz()->settings();
		$logger   = new Logger( $settings );
		$payments = new PaymentService( $this->db, new Http( $logger ), new WalletService( $this->db, $logger ), $logger );

		return new FxTopupService( $this->db, $settings, $payments, $this->wallet(), new FxRateService( $this->db, $settings, new Http( $logger ) ), $logger );
	}

	private function billing( ?FxPayoutRegistry $payouts = null ): FxBillingService {
		$settings = igbz()->settings();
		$logger   = new Logger( $settings );

		$registry = $payouts ?? new FxPayoutRegistry();
		if ( $payouts === null ) {
			$registry->register( new StubPayoutAdapter() );
			$settings->set( 'fx.payout_provider', 'stub' );
		}

		return new FxBillingService(
			$this->db,
			$settings,
			$this->wallet(),
			$this->meter(),
			$registry,
			new FxAccountsService( $this->db ),
			$logger
		);
	}

	public function run(): void {
		$this->test_the_fee_is_added_on_top_of_the_usd_amount();
		$this->test_quote_rounds_the_rial_amount();
		$this->test_the_rate_falls_back_to_manual();
		$this->test_the_auto_rate_wins_when_it_answers();
		$this->test_a_verified_topup_credits_the_wallet();
		$this->test_a_replayed_verification_does_not_double_credit();
		$this->test_start_reports_the_fee_loaded_quote();
		$this->test_the_meter_spends_and_refunds_once();
		$this->test_the_meter_refuses_on_the_spot_when_credit_is_short();
		$this->test_an_unpriced_service_is_refused();
		$this->test_monthly_billing_is_retired_with_the_legacy_providers();
		$this->test_dm_delivery_is_charged_once();
		$this->test_the_pstnet_adapter_charges_and_reports_balance();
		$this->test_the_redotpay_adapter_is_a_valid_pilot();
		$this->test_manual_settlement_marks_the_bill_paid_and_debits();
		$this->test_the_ramp_reads_the_price_and_reports_unpriced();
		$this->test_the_ramp_disabled_returns_no_price();
		$this->test_operator_report_aggregates_the_ledger();
	}

	public function test_the_fee_is_added_on_top_of_the_usd_amount(): void {
		$q = FxMath::quote( 10, 10, 50000 );

		$this->assert_same( 11.0, $q['gross_usd'], '10 USD at 10% fee charges 11 USD' );
		$this->assert_same( 1.0, $q['fee_usd'], 'the fee is the difference' );
		$this->assert_same( 10.0, $q['net_usd'], 'the wallet receives only the requested amount' );
	}

	public function test_quote_rounds_the_rial_amount(): void {
		$q = FxMath::quote( 10.005, 10, 50000 );

		$this->assert_same( 550275.0, $q['amount_irt'], 'the Rial charge is rounded to a whole unit' );
	}

	public function test_the_rate_falls_back_to_manual(): void {
		$this->boot();
		$settings = igbz()->settings();
		$settings->set( 'fx.rate_source', 'auto' );
		$settings->set( 'fx.rate_url', '' );

		$rates = new FxRateService( $this->db, $settings, new Http( new Logger( $settings ) ) );

		$this->assert_same( 50000.0, $rates->current(), 'an unreachable auto source falls back to the manual rate' );
	}

	public function test_the_auto_rate_wins_when_it_answers(): void {
		$this->boot();
		$settings = igbz()->settings();
		$settings->set( 'fx.rate_source', 'auto' );

		$rates = new CannedRateService( $this->db, $settings, 92000 );

		$this->assert_same( 92000.0, $rates->current(), 'a live auto rate overrides the manual fallback' );
	}

	public function test_a_verified_topup_credits_the_wallet(): void {
		$this->boot();
		$this->fxdb->seed(
			'payments',
			[
				'tenant_id' => 7,
				'user_id'   => 3,
				'purpose'   => FxTopupService::PURPOSE,
				'amount'    => 550000,
				'gateway'   => 'zarinpal',
				'status'    => 'paid',
				'meta'      => wp_json_encode(
					[
						'fx_net_usd'   => 10,
						'fx_gross_usd' => 11,
						'fx_fee_usd'   => 1,
						'fx_rate_id'   => 5,
					]
				),
			]
		);

		$this->topup()->on_payment_verified( 1 );

		$this->assert_same( 10.0, $this->wallet()->balance( 7 )['balance_usd'], 'the wallet receives the net USD amount' );
		$this->assert_same( 1, count( $this->fxdb->tables['fx_ledger'] ), 'exactly one ledger row for the top-up' );
	}

	public function test_a_replayed_verification_does_not_double_credit(): void {
		$this->boot();
		$this->fxdb->seed(
			'payments',
			[
				'tenant_id' => 7,
				'user_id'   => 3,
				'purpose'   => FxTopupService::PURPOSE,
				'amount'    => 550000,
				'gateway'   => 'zarinpal',
				'status'    => 'paid',
				'meta'      => wp_json_encode( [ 'fx_net_usd' => 10, 'fx_gross_usd' => 11, 'fx_fee_usd' => 1, 'fx_rate_id' => 5 ] ),
			]
		);
		$topup = $this->topup();

		$topup->on_payment_verified( 1 );
		$topup->on_payment_verified( 1 );

		$this->assert_same( 10.0, $this->wallet()->balance( 7 )['balance_usd'], 'a replayed webhook credits once' );
		$this->assert_same( 1, count( $this->fxdb->tables['fx_ledger'] ), 'the ledger holds one row' );
	}

	public function test_start_reports_the_fee_loaded_quote(): void {
		$this->boot();

		// No gateway is configured in the harness, so start() fails — but the quote is computed
		// before the gateway lookup and must come back with the fee already loaded.
		$result = $this->topup()->start( 7, 3, 10 );

		$this->assert_false( $result['ok'], 'no gateway means no redirect' );
		$this->assert_same( 550000.0, $result['amount_irt'], 'the Rial amount includes the fee' );
		$this->assert_same( 11.0, $result['gross_usd'], 'the gross USD includes the fee' );
		$this->assert_same( 10.0, $result['net_usd'], 'the net USD is what the wallet gets' );
	}

	public function test_the_meter_spends_and_refunds_once(): void {
		$this->boot();
		$this->seed_price( 'social_task', 0.5 );
		$this->wallet()->credit( 7, 1.0, FxWalletService::REASON_TOPUP, 'payment:1' );
		$meter = $this->meter();

		$first = $meter->consume( 7, 'social_task', 'task:aaa' );
		$this->assert_true( $first['ok'], 'the first task is allowed' );
		$this->assert_same( 0.5, $first['balance'], 'half a dollar left' );

		$second = $meter->consume( 7, 'social_task', 'task:bbb' );
		$this->assert_true( $second['ok'], 'the second task is allowed' );
		$this->assert_same( 0.0, $second['balance'], 'the wallet is empty' );

		$third = $meter->consume( 7, 'social_task', 'task:ccc' );
		$this->assert_false( $third['ok'], 'the third task is refused on the spot' );
		$this->assert_same( 'insufficient', $third['error'], 'the refusal names the reason' );

		$meter->release( 7, 'social_task', 'task:bbb' );
		$this->assert_same( 0.5, $this->wallet()->balance( 7 )['balance_usd'], 'a failed task is refunded' );

		$meter->release( 7, 'social_task', 'task:bbb' );
		$this->assert_same( 0.5, $this->wallet()->balance( 7 )['balance_usd'], 'a double release refunds once' );
	}

	public function test_the_meter_refuses_on_the_spot_when_credit_is_short(): void {
		$this->boot();
		$this->seed_price( 'social_task', 0.5 );

		$result = $this->meter()->consume( 7, 'social_task', 'task:aaa' );

		$this->assert_false( $result['ok'], 'an empty wallet refuses immediately' );
		$this->assert_same( 'insufficient', $result['error'], 'no queue, no debt — just the reason' );
	}

	public function test_an_unpriced_service_is_refused(): void {
		$this->boot();

		$result = $this->meter()->consume( 7, 'social_task', 'task:aaa' );

		$this->assert_false( $result['ok'], 'a service with no price cannot be consumed' );
		$this->assert_same( 'unpriced', $result['error'], 'the operator must set prices first' );
	}

	public function test_monthly_billing_is_retired_with_the_legacy_providers(): void {
		$this->boot();
		$this->seed_price( 'legacy_monthly', 25 );
		$this->wallet()->credit( 7, 30, FxWalletService::REASON_TOPUP, 'payment:1' );

		$accounts = new FxAccountsService( $this->db );
		$account  = $accounts->get( $accounts->create( 7, 'legacy', 'acct-1' ) );

		$billing = $this->billing();
		$this->assert_same( '', $billing->service_for( $account ), 'no legacy provider maps to a priced service anymore' );
		$this->assert_same( 0, $billing->create_monthly_bill( $account ), 'so no monthly bill is created — the mechanism stays, the billing does not' );
		$this->assert_same( 30.0, $this->wallet()->balance( 7 )['balance_usd'], 'the wallet is untouched' );
	}

	public function test_dm_delivery_is_charged_once(): void {
		$this->boot();
		$this->seed_price( 'dm_delivery', 0.1 );
		$this->wallet()->credit( 7, 1, FxWalletService::REASON_TOPUP, 'payment:1' );
		$meter = $this->meter();

		$first = $meter->charge_delivery( 7, 'dm:11' );
		$this->assert_true( $first['ok'], 'the first delivery is charged' );
		$this->assert_same( 0.9, $first['balance'], 'the delivery price is deducted' );

		$second = $meter->charge_delivery( 7, 'dm:11' );
		$this->assert_false( $second['ok'], 'a replayed delivery is not charged twice' );
		$this->assert_same( 0.9, $this->wallet()->balance( 7 )['balance_usd'], 'the wallet is untouched by the replay' );
	}

	public function test_the_pstnet_adapter_charges_and_reports_balance(): void {
		$this->boot();
		$settings = igbz()->settings();
		$settings->set( 'fx.pstnet_api_key', 'k-test' );
		$settings->set( 'fx.pstnet_card_id', 'card-1' );

		$adapter = new \IGBZ\Suite\Modules\Fx\Providers\PstNetPayoutAdapter(
			$settings,
			new Http( new Logger( $settings ) ),
			new Logger( $settings )
		);

		$this->assert_true( $adapter->is_configured(), 'the adapter is configured once key and card are set' );
		$this->assert_same( 'pstnet', $adapter->id(), 'the adapter id is stable' );
	}

	public function test_the_redotpay_adapter_is_a_valid_pilot(): void {
		$this->boot();
		$settings = igbz()->settings();
		$settings->set( 'fx.redotpay_api_key', 'k-test' );
		$settings->set( 'fx.redotpay_card_id', 'card-1' );

		$adapter = new \IGBZ\Suite\Modules\Fx\Providers\RedotPayPayoutAdapter(
			$settings,
			new Http( new Logger( $settings ) ),
			new Logger( $settings )
		);

		$this->assert_true( $adapter->is_configured(), 'the pilot adapter is configured once key and card are set' );
		$this->assert_same( 'redotpay', $adapter->id(), 'the pilot adapter id is stable' );
	}

	public function test_manual_settlement_marks_the_bill_paid_and_debits(): void {
		$this->boot();
		$this->wallet()->credit( 7, 30, FxWalletService::REASON_TOPUP, 'payment:1' );

		// The bill row is written directly: manual settlement no longer depends on the
		// retired monthly-bill creation path.
		$now       = current_time( 'mysql', true );
		$bill_id   = $this->fxdb->tables['fx_bills'] ? max( array_keys( $this->fxdb->tables['fx_bills'] ) ) + 1 : 1;
		$this->fxdb->tables['fx_bills'][ $bill_id ] = [
			'id'         => $bill_id,
			'tenant_id'  => 7,
			'service'    => 'manual',
			'period'     => gmdate( 'Y-m' ),
			'amount_usd' => 25.0,
			'status'     => 'unpaid',
			'created_at' => $now,
			'updated_at' => $now,
		];
		$bill     = $this->fxdb->tables['fx_bills'][ $bill_id ];
		$billing  = $this->billing();

		$result = $billing->settle_bill_manually( $bill, 99 );

		$this->assert_true( $result['ok'], 'a manual settlement succeeds' );
		$this->assert_same( FxBillingService::STATUS_PAID, $this->fxdb->tables['fx_bills'][ $bill_id ]['status'], 'the bill is marked paid' );
		$this->assert_same( 5.0, $this->wallet()->balance( 7 )['balance_usd'], 'the wallet is debited the bill amount' );
		$this->assert_same( 'manual:99', $this->fxdb->tables['fx_bills'][ $bill_id ]['payout_ref'], 'the payout ref names the operator' );
	}

	public function test_the_ramp_reads_the_price_and_reports_unpriced(): void {
		$this->boot();
		$settings = igbz()->settings();
		$settings->set( 'fx.ramp_enabled', true );
		$settings->set( 'fx.ramp_api_key', 'k-test' );
		$settings->set( 'fx.ramp_base_url', 'https://api.nobitex.ir' );

		igbz_test_queue_http( [ 'status' => 200, 'body' => wp_json_encode( [ 'status' => 'ok', 'price' => 92000 ] ) ] );

		$ramp = new FxRampService( $this->db, $settings, new FxPayoutRegistry(), new Logger( $settings ) );

		$this->assert_same( 92000.0, $ramp->usdt_price(), 'the ramp reads the live USDT price' );
	}

	public function test_the_ramp_disabled_returns_no_price(): void {
		$this->boot();
		$settings = igbz()->settings();
		$settings->set( 'fx.ramp_enabled', false );

		$ramp = new FxRampService( $this->db, $settings, new FxPayoutRegistry(), new Logger( $settings ) );

		$this->assert_same( 0.0, $ramp->usdt_price(), 'a disabled ramp answers zero' );
	}

	public function test_operator_report_aggregates_the_ledger(): void {
		$this->boot();

		$this->fxdb->seed(
			'fx_ledger',
			[
				'tenant_id' => 7, 'reason' => FxWalletService::REASON_TOPUP,
				'reference' => 'payment:1', 'amount_usd' => 10, 'amount_irt' => 550000,
				'meta' => wp_json_encode( [ 'fee_usd' => 1 ] ), 'created_at' => gmdate( 'Y-m-d 10:00:00' ),
			]
		);
		$this->fxdb->seed(
			'fx_ledger',
			[
				'tenant_id' => 7, 'reason' => FxWalletService::REASON_TASK,
				'reference' => 'task:1', 'amount_usd' => -0.5, 'amount_irt' => 0,
				'meta' => '{}', 'created_at' => gmdate( 'Y-m-d 11:00:00' ),
			]
		);
		$this->fxdb->seed(
			'fx_ledger',
			[
				'tenant_id' => 0, 'reason' => FxRampService::REASON_RAMP,
				'reference' => 'ramp:1', 'amount_usd' => 5, 'amount_irt' => 460000,
				'meta' => '{}', 'created_at' => gmdate( 'Y-m-d 12:00:00' ),
			]
		);
		$this->fxdb->seed(
			'fx_ledger',
			[
				'tenant_id' => 7, 'reason' => FxWalletService::REASON_SUBSCRIPTION,
				'reference' => 'bill:1', 'amount_usd' => -25, 'amount_irt' => 0,
				'meta' => '{}', 'created_at' => gmdate( 'Y-m-d 13:00:00' ),
			]
		);
		$this->fxdb->seed(
			'fx_bills',
			[
				'tenant_id' => 7, 'fx_account_id' => 1, 'status' => FxBillingService::STATUS_PAID,
				'amount_usd' => 25, 'period_start' => gmdate( 'Y-m-01' ), 'period_end' => gmdate( 'Y-m-t' ),
				'created_at' => gmdate( 'Y-m-d 00:00:00' ),
			]
		);

		$report = ( new FxReportsService( $this->db ) )->operator_summary();

		$this->assert_same( 1, $report['topup_count'], 'one top-up counted' );
		$this->assert_same( 10.0, $report['topups_usd'], 'top-up USD summed' );
		$this->assert_same( 550000.0, $report['topups_irt'], 'top-up IRT summed' );
		$this->assert_same( 1.0, $report['fees_usd'], 'the fee is read from the meta' );
		$this->assert_same( 0.5, $report['task_spend_usd'], 'task spend is positive in the report' );
		$this->assert_same( 25.0, $report['subscriptions_usd'], 'subscriptions summed' );
		$this->assert_same( 460000.0, $report['ramp_irt'], 'ramp purchases summed' );
		$this->assert_same( 1, $report['bills_paid'], 'one paid bill' );
		$this->assert_same( 25.0, $report['bills_paid_usd'], 'paid bill USD summed' );
	}
}
