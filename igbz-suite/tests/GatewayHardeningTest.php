<?php
/**
 * Phase 30 — hardened rial gateway adapters.
 *
 * One shared timeout, one shared error classification, creation idempotency, refund as a
 * capability with an over-refund guard and mandatory idempotency key. No provider sandbox is
 * exercised here — this phase hardens the contract, not each PSP.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\MultiTenant\Payments\GatewayErrors;
use IGBZ\Suite\Modules\MultiTenant\Payments\GatewayInterface;
use IGBZ\Suite\Modules\MultiTenant\Payments\HttpPspGateway;
use IGBZ\Suite\Modules\MultiTenant\Payments\PaymentRequestResult;
use IGBZ\Suite\Modules\MultiTenant\Payments\PaymentRefundResult;
use IGBZ\Suite\Modules\MultiTenant\Payments\PaymentService;
use IGBZ\Suite\Modules\MultiTenant\Payments\PaymentVerifyResult;
use IGBZ\Suite\Modules\MultiTenant\Payments\PspHttp;
use IGBZ\Suite\Modules\MultiTenant\Payments\RefundableGatewayInterface;
use IGBZ\Suite\Support\Db;

/** In-memory payments store for the service-level scenarios. */
final class PaymentsHarnessDb extends wpdb {

	/** @var array<int,array<string,mixed>> */
	public array $payments = [];

	private int $next_id = 1;

	public function seed( array $row ): int {
		$id = $this->next_id++;
		$row['id'] = $id;
		$this->payments[ $id ] = $row;
		return $id;
	}

	public function insert( string $table, array $data, $format = null ): int|bool {
		if ( ! str_contains( $table, 'payments' ) ) {
			return parent::insert( $table, $data, $format );
		}
		$id = $this->next_id++;
		$data['id'] = $id;
		$this->payments[ $id ] = $data;
		$this->insert_id = $id;
		return 1;
	}

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( preg_match( "/WHERE id = '?(\d+)'?( AND tenant_id = '?(\d+)'?)?/", $sql, $m ) ) {
			$row = $this->payments[ (int) $m[1] ] ?? null;
			if ( $row && isset( $m[3] ) && (string) $row['tenant_id'] !== $m[3] ) {
				return null;
			}
			return $row;
		}

		if ( str_contains( $sql, 'ORDER BY id DESC' ) ) {
			$found = [];
			foreach ( $this->payments as $row ) {
				if (
					(string) $row['tenant_id'] === $this->scalar( $sql, 'tenant_id' )
					&& (string) $row['order_id'] === $this->scalar( $sql, 'order_id' )
					&& (string) $row['purpose'] === $this->scalar( $sql, 'purpose' )
					&& (string) $row['gateway'] === $this->scalar( $sql, 'gateway' )
					&& abs( (float) $row['amount'] - (float) $this->scalar( $sql, 'amount' ) ) < 0.0001
					&& (string) $row['status'] === $this->scalar( $sql, 'status' )
				) {
					$found[] = $row;
				}
			}
			usort( $found, static fn ( $a, $b ): int => (int) $b['id'] <=> (int) $a['id'] );
			return $found[0] ?? null;
		}
		return parent::get_row( $sql, $output );
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		if ( ! str_contains( $table, 'payments' ) ) {
			return parent::update( $table, $data, $where, $format, $where_format );
		}
		$changed = 0;
		foreach ( $this->payments as $id => $row ) {
			$hit = true;
			foreach ( $where as $column => $value ) {
				if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) {
					$hit = false;
					break;
				}
			}
			if ( $hit ) {
				$this->payments[ $id ] = array_merge( $row, $data );
				++$changed;
			}
		}
		return $changed;
	}

	private function scalar( string $sql, string $column ): string {
		return preg_match( "/\b{$column} = '?([^' ]*)'?/", $sql, $m ) ? $m[1] : '';
	}
}

/** Scripted gateway — never touches the network. */
class TestStubGateway implements GatewayInterface {
	/** @var array<int,array<string,mixed>> */
	public array $requests = [];

	public function id(): string {
		return 'testgw';
	}

	public function title(): string {
		return 'Test Gateway';
	}

	public function required_settings(): array {
		return [];
	}

	public function is_configured(): bool {
		return true;
	}

	public function request( float $amount, string $callback_url, array $context = [] ): PaymentRequestResult {
		$this->requests[] = [ 'amount' => $amount, 'context' => $context ];
		return PaymentRequestResult::ok( 'AUTH-1', 'https://pay.test/redirect' );
	}

	public function verify( float $amount, array $callback_params ): PaymentVerifyResult {
		return PaymentVerifyResult::failure( 'not_tested', '' );
	}
}

/** Refund-capable stub for the refund scenarios. */
final class TestRefundStubGateway extends TestStubGateway implements RefundableGatewayInterface {
	/** @var array<int,array<string,mixed>> */
	public array $refunds = [];

	public bool $refuse_next = false;

	public function id(): string {
		return 'testrefund';
	}

	public function refund( string $reference_id, float $amount, array $context = [] ): PaymentRefundResult {
		$this->refunds[] = [ 'reference_id' => $reference_id, 'amount' => $amount, 'context' => $context ];
		if ( $this->refuse_next ) {
			$this->refuse_next = false;
			return PaymentRefundResult::failure( 'psp_said_no', 'Nope' );
		}
		return PaymentRefundResult::ok( 'REF-' . count( $this->refunds ) );
	}
}

final class GatewayHardeningTest extends TestCase {

	private PaymentsHarnessDb $wpdb;
	private PaymentService $service;
	private TestStubGateway $gateway;
	private TestRefundStubGateway $refund_gateway;

	public function run(): void {
		$this->timeout_is_one_shared_clamped_value();
		$this->error_classifier_contract();
		$this->creation_reuses_a_fresh_created_row();
		$this->creation_inserts_when_nothing_matches();
		$this->every_request_carries_an_idempotency_key();
		$this->refund_guards_and_sequence();
		$this->httppsp_refund_needs_a_configured_endpoint();
	}

	private function fresh(): void {
		igbz_test_reset_settings();
		$this->wpdb      = new PaymentsHarnessDb();
		$GLOBALS['wpdb'] = $this->wpdb;

		$this->gateway        = new TestStubGateway();
		$this->refund_gateway = new TestRefundStubGateway();

		$logger        = igbz()->get( 'logger' );
		$this->service = new PaymentService( new Db(), igbz()->get( 'http' ), new \IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService( new Db(), $logger ), $logger );
		$this->service->register( $this->gateway );
		$this->service->register( $this->refund_gateway );

		igbz()->settings()->set( 'payments.testgw.enabled', 'yes' );
		igbz()->settings()->set( 'payments.testrefund.enabled', 'yes' );
	}

	private function timeout_is_one_shared_clamped_value(): void {
		igbz_test_reset_settings();

		$this->assert_same( 30, PspHttp::timeout(), 'the default is 30 seconds' );

		igbz()->settings()->set( 'payments.timeout', '12' );
		$this->assert_same( 12, PspHttp::timeout(), 'a configured value wins' );

		igbz()->settings()->set( 'payments.timeout', '1' );
		$this->assert_same( 5, PspHttp::timeout(), 'never below 5 seconds' );

		igbz()->settings()->set( 'payments.timeout', '9000' );
		$this->assert_same( 60, PspHttp::timeout(), 'never above 60 seconds' );
	}

	private function error_classifier_contract(): void {
		[ $code ] = GatewayErrors::classify( false, 'curl: timeout after 30s', [ 'x' => 1 ], '403', 'denied', 'fallback', 'Fallback' );
		$this->assert_same( 'network_timeout', $code, 'a failed round-trip is transient by definition' );

		[ $code ] = GatewayErrors::classify( true, '', [], '', '', 'fallback', 'Fallback' );
		$this->assert_same( 'invalid_response', $code, 'an empty body reads as unreadable, not declined' );

		[ $code, $message ] = GatewayErrors::classify( true, '', [ 'e' => 1 ], '403', 'merchant denied', 'fallback', 'Fallback' );
		$this->assert_same( '403', $code, 'the provider code passes through untouched' );
		$this->assert_same( 'merchant denied', $message, 'the provider message survives' );

		[ $code ] = GatewayErrors::classify( true, '', [ 'e' => 1 ], '', '', 'fallback', 'Fallback' );
		$this->assert_same( 'fallback', $code, 'with nothing from the provider the fallback applies' );
	}

	private function creation_reuses_a_fresh_created_row(): void {
		$this->fresh();

		$seeded = $this->wpdb->seed(
			[
				'tenant_id' => 0,
				'order_id'  => 55,
				'purpose'   => 'order',
				'gateway'   => 'testgw',
				'amount'    => 100.0,
				'status'    => 'created',
				'meta'      => '{}',
			]
		);

		$out = $this->service->start( 100.0, 'order', [ 'order_id' => 55, 'tenant_id' => 0 ], 'testgw' );

		$this->assert_true( (bool) $out['ok'], 'the start succeeds' );
		$this->assert_same( $seeded, (int) $out['payment_id'], 'the fresh created row is reused, not duplicated' );
		$this->assert_same( 1, count( $this->wpdb->payments ), 'no extra payment row was inserted' );
		$this->assert_same( 'pending', (string) $this->wpdb->payments[ $seeded ]['status'], 'and it moved on to pending' );
	}

	private function creation_inserts_when_nothing_matches(): void {
		$this->fresh();

		$this->wpdb->seed(
			[
				'tenant_id' => 0,
				'order_id'  => 55,
				'purpose'   => 'order',
				'gateway'   => 'testgw',
				'amount'    => 100.0,
				'status'    => 'created',
				'meta'      => '{}',
			]
		);

		$out = $this->service->start( 200.0, 'order', [ 'order_id' => 55, 'tenant_id' => 0 ], 'testgw' );
		$this->assert_same( 2, (int) $out['payment_id'], 'a different amount is a fresh payment' );
		$this->assert_same( 2, count( $this->wpdb->payments ), 'the original row is untouched' );
	}

	private function every_request_carries_an_idempotency_key(): void {
		$this->fresh();

		$out = $this->service->start( 100.0, 'order', [ 'order_id' => 9, 'tenant_id' => 0 ], 'testgw' );
		$context = (array) $this->gateway->requests[0]['context'];

		$this->assert_same( 'pay-' . (int) $out['payment_id'], (string) $context['idempotency_key'], 'a stable key rides along by default' );

		$out2 = $this->service->start( 100.0, 'order', [ 'order_id' => 10, 'tenant_id' => 0, 'idempotency_key' => 'caller-key' ], 'testgw' );
		$this->assert_true( (bool) $out2['ok'], 'a caller-supplied key is respected' );
		$this->assert_same( 'caller-key', (string) $this->gateway->requests[1]['context']['idempotency_key'], 'unchanged, never overwritten' );
	}

	private function refund_guards_and_sequence(): void {
		$this->fresh();

		$this->assert_true( ! $this->service->supports_refund( 'testgw' ), 'a plain adapter is not refund-capable' );
		$this->assert_true( $this->service->supports_refund( 'testrefund' ), 'a refundable adapter is detected' );

		$unpaid = $this->wpdb->seed(
			[ 'tenant_id' => 0, 'gateway' => 'testrefund', 'status' => 'created', 'amount' => 100.0, 'reference_id' => 'R1', 'meta' => '{}' ]
		);
		$out = $this->service->refund_payment( $unpaid, 50.0 );
		$this->assert_same( 'not_paid', (string) $out['reason'], 'only a paid payment refunds' );

		$paid = $this->wpdb->seed(
			[ 'tenant_id' => 0, 'gateway' => 'testrefund', 'status' => 'paid', 'amount' => 100.0, 'reference_id' => 'R2', 'meta' => '{}' ]
		);

		$out = $this->service->refund_payment( $paid, 150.0 );
		$this->assert_same( 'over_refund', (string) $out['reason'], 'a refund above the paid amount is refused before the PSP is asked' );

		$out = $this->service->refund_payment( $paid, 60.0, 'damaged goods' );
		$this->assert_true( (bool) $out['ok'], 'a legal refund goes through' );
		$this->assert_same( 'REF-1', (string) $out['reference'], 'with the PSP reference' );
		$this->assert_same( 60.0, (float) $out['refunded'], 'and the running total' );
		$this->assert_same( 'refund:' . $paid . ':1', (string) $this->refund_gateway->refunds[0]['context']['idempotency_key'], 'the idempotency key is mandatory and sequenced' );

		$out = $this->service->refund_payment( $paid, 50.0 );
		$this->assert_same( 'over_refund', (string) $out['reason'], 'the running total blocks the follow-up overshoot' );

		$out = $this->service->refund_payment( $paid, 40.0 );
		$this->assert_true( (bool) $out['ok'], 'the remainder still refunds' );
		$this->assert_same( 100.0, (float) $out['refunded'], 'exactly the paid amount in total' );
		$this->assert_same( 'refund:' . $paid . ':2', (string) $this->refund_gateway->refunds[1]['context']['idempotency_key'], 'the sequence advances' );

		$meta = json_decode( (string) $this->wpdb->payments[ $paid ]['meta'], true );
		$this->assert_same( 2, count( (array) $meta['refunds'] ), 'refunds are recorded on the payment itself' );

		// A PSP refusal keeps the payment untouched.
		$this->refund_gateway->refuse_next = true;
		$other = $this->wpdb->seed(
			[ 'tenant_id' => 0, 'gateway' => 'testrefund', 'status' => 'paid', 'amount' => 80.0, 'reference_id' => 'R3', 'meta' => '{}' ]
		);
		$out = $this->service->refund_payment( $other, 20.0 );
		$this->assert_same( 'psp_said_no', (string) $out['reason'], 'a refused refund surfaces the provider code' );
		$meta = json_decode( (string) $this->wpdb->payments[ $other ]['meta'], true );
		$this->assert_true( empty( $meta['refunds'] ), 'and nothing is recorded' );
	}

	private function httppsp_refund_needs_a_configured_endpoint(): void {
		$this->fresh();

		$config_psp = new HttpPspGateway( igbz()->get( 'http' ) );

		$out = $config_psp->refund( 'ANY-REF', 10.0, [ 'idempotency_key' => 'k' ] );
		$this->assert_same( 'not_configured', $out->error_code, 'without a refund URL nothing is attempted' );
	}
}
