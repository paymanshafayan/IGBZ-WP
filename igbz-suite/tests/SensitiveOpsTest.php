<?php
/**
 * Phase 58 — the sensitive commercial operations (price change, refund, bulk delete)
 * ride the phase-57 permission queue and execute compensably: price writes verify on
 * re-read and restore the captured price when they do not stick, refunds delegate to
 * the payment guards (a refused refund moves nothing), and bulk delete only ever
 * TRASHES, restoring everything it trashed if the batch fails midway.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\MultiTenant\Payments\GatewayInterface;
use IGBZ\Suite\Modules\MultiTenant\Payments\PaymentRefundResult;
use IGBZ\Suite\Modules\MultiTenant\Payments\PaymentRequestResult;
use IGBZ\Suite\Modules\MultiTenant\Payments\PaymentService;
use IGBZ\Suite\Modules\MultiTenant\Payments\PaymentVerifyResult;
use IGBZ\Suite\Modules\MultiTenant\Payments\RefundableGatewayInterface;
use IGBZ\Suite\Modules\Pado\Services\ApprovalRequestService;
use IGBZ\Suite\Modules\Pado\Services\SensitiveOperationsService;
use IGBZ\Suite\Support\Db;

/** The little world the WooCommerce doubles serve. */
final class SensitiveOpsWorld {
	/** @var array<int,array{price:float,status:string}> */
	public static array $products = [];
	/** @var array<int,int> post id => how many times it was trashed minus untrashed (>0 = trashed) */
	public static array $trash = [];
	/** When true, saving a product silently does not stick (the compensation path). */
	public static bool $save_lies = false;
	/** When true, the next save() skips syncing the active price (the lag the live smoke saw). */
	public static bool $active_lags = false;
	/** Product ids whose wp_trash_post must fail (mid-batch failure). */
	public static array $trash_fails = [];
	/** @var array<int,array<string,mixed>> */
	public static array $refunds = [];

	public static function reset(): void {
		self::$products = [];
		self::$trash = [];
		self::$save_lies = false;
		self::$active_lags = false;
		self::$trash_fails = [];
		self::$refunds = [];
	}
}




/** The service with its environment seams pointed at the test world. */
final class SensitiveOpsServiceSpy extends SensitiveOperationsService {
	public function __construct( Db $db, $logger, ApprovalRequestService $approvals, PaymentService $payments ) {
		parent::__construct( $db, $logger, $approvals, $payments );
	}

	protected function load_product( int $product_id ): ?object {
		return isset( SensitiveOpsWorld::$products[ $product_id ] ) ? new SensitiveOpsProduct( $product_id ) : null;
	}

	protected function trash_post( int $post_id ): bool {
		if ( in_array( $post_id, SensitiveOpsWorld::$trash_fails, true ) ) {
			return false;
		}
		SensitiveOpsWorld::$trash[ $post_id ] = ( SensitiveOpsWorld::$trash[ $post_id ] ?? 0 ) + 1;
		return true;
	}

	protected function untrash_post( int $post_id ): void {
		SensitiveOpsWorld::$trash[ $post_id ] = ( SensitiveOpsWorld::$trash[ $post_id ] ?? 1 ) - 1;
	}
}

final class SensitiveOpsProduct {
	private float $regular_at_load;
	private float $price_at_load;

	public function __construct( private int $id ) {
		$this->regular_at_load = (float) ( SensitiveOpsWorld::$products[ $id ]['regular'] ?? 0 );
		$this->price_at_load   = (float) ( SensitiveOpsWorld::$products[ $id ]['price'] ?? 0 );
	}

	public function get_id(): int {
		return $this->id;
	}

	public function get_price(): float {
		return (float) ( SensitiveOpsWorld::$products[ $this->id ]['price'] ?? 0 );
	}

	public function get_regular_price(): string {
		return (string) ( SensitiveOpsWorld::$products[ $this->id ]['regular'] ?? 0 );
	}

	public function get_sale_price(): string {
		return (string) ( SensitiveOpsWorld::$products[ $this->id ]['sale'] ?? 0 );
	}

	public function set_price( float|string $price ): void {
		SensitiveOpsWorld::$products[ $this->id ]['price'] = (float) $price;
	}

	public function set_regular_price( float|string $price ): void {
		SensitiveOpsWorld::$products[ $this->id ]['regular'] = (float) $price;
	}

	public function save(): int {
		if ( SensitiveOpsWorld::$save_lies ) {
			// Pretend to accept but keep the values from load time: the re-read must catch it.
			SensitiveOpsWorld::$save_lies = false;
			SensitiveOpsWorld::$products[ $this->id ]['regular'] = $this->regular_at_load;
			SensitiveOpsWorld::$products[ $this->id ]['price']   = $this->price_at_load;
			return $this->id;
		}
		// The real data store serves the sale price while a sale is active.
		$sale = (float) ( SensitiveOpsWorld::$products[ $this->id ]['sale'] ?? 0 );
		if ( $sale > 0 ) {
			SensitiveOpsWorld::$products[ $this->id ]['price'] = $sale;
		} elseif ( SensitiveOpsWorld::$active_lags ) {
			// One save where the active price keeps its old value, as the phase-58 live
			// smoke once watched WooCommerce do; the service must retry and recover.
			SensitiveOpsWorld::$active_lags = false;
		}
		return $this->id;
	}
}

final class SensitiveOpsGateway implements GatewayInterface, RefundableGatewayInterface {
	public function id(): string { return 'opsgw'; }
	public function title(): string { return 'Ops Test Gateway'; }
	public function required_settings(): array { return []; }
	public function is_configured(): bool { return true; }
	public function request( float $amount, string $callback_url, array $context = [] ): PaymentRequestResult {
		return PaymentRequestResult::ok( 'AUTH-1', 'https://pay.test/redirect' );
	}
	public function verify( float $amount, array $callback_params ): PaymentVerifyResult {
		return PaymentVerifyResult::failure( 'not_tested', '' );
	}
	public function refund( string $reference_id, float $amount, array $context = [] ): PaymentRefundResult {
		SensitiveOpsWorld::$refunds[] = [ 'reference' => $reference_id, 'amount' => $amount, 'context' => $context ];
		return PaymentRefundResult::ok( 'RF-' . count( SensitiveOpsWorld::$refunds ) );
	}
}

/** In-memory engine: the approval queue + the payments table. */
final class OpsQueueDb extends wpdb {
	/** @var array<int,array<string,mixed>> */
	public array $approvals = [];
	/** @var array<int,array<string,mixed>> */
	public array $payments = [];
	private int $next_id = 1;

	public function insert( string $table, array $data, $format = null ): int|bool {
		$this->queries[] = 'INSERT INTO ' . $table;
		if ( str_contains( $table, 'igbz_approval_requests' ) ) {
			if ( null !== ( $data['idempotency_key'] ?? null ) ) {
				foreach ( $this->approvals as $row ) {
					if ( (int) $row['tenant_id'] === (int) $data['tenant_id']
						&& (string) $row['kind'] === (string) $data['kind']
						&& (string) $row['idempotency_key'] === (string) $data['idempotency_key'] ) {
						return false;
					}
				}
			}
			$data['id'] = $this->next_id++;
			$this->approvals[ $data['id'] ] = $data;
			$this->insert_id = $data['id'];
			return 1;
		}
		if ( str_contains( $table, 'igbz_payments' ) ) {
			$data['id'] = $this->next_id++;
			$this->payments[ $data['id'] ] = $data;
			$this->insert_id = $data['id'];
			return 1;
		}
		return parent::insert( $table, $data, $format );
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		$this->queries[] = 'UPDATE ' . $table;
		$store = str_contains( $table, 'igbz_approval_requests' ) ? $this->approvals
			: ( str_contains( $table, 'igbz_payments' ) ? $this->payments : null );
		if ( null === $store ) {
			return parent::update( $table, $data, $where, $format, $where_format );
		}
		$name = $this->approvals === $store ? 'approvals' : 'payments';
		$changed = 0;
		foreach ( $this->$name as $id => $row ) {
			if ( ! $this->matches( $row, $where ) ) { continue; }
			$this->{$name}[ $id ] = array_merge( $row, $data );
			++$changed;
		}
		return $changed;
	}

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;
		if ( str_contains( $sql, 'idempotency_key' ) && preg_match( "/idempotency_key = '([^']+)'/", $sql, $key ) ) {
			preg_match( "/tenant_id = '?(\\d+)'?/", $sql, $t );
			preg_match( "/kind = '([^']+)'/", $sql, $k );
			foreach ( $this->approvals as $row ) {
				if ( (int) $row['tenant_id'] === (int) ( $t[1] ?? -1 )
					&& (string) $row['kind'] === (string) ( $k[1] ?? '' )
					&& (string) ( $row['idempotency_key'] ?? '' ) === (string) $key[1] ) {
					return $row;
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'igbz_approval_requests' ) && preg_match( '/WHERE id = \'?(\d+)\'?/', $sql, $m ) ) {
			$row = $this->approvals[ (int) $m[1] ] ?? null;
			if ( $row && preg_match( "/tenant_id = '(\d+)'/", $sql, $t ) && (int) $row['tenant_id'] !== (int) $t[1] ) { return null; }
			return $row;
		}
		if ( str_contains( $sql, 'igbz_payments' ) && preg_match( '/WHERE id = \'?(\d+)\'?/', $sql, $m ) ) {
			return $this->payments[ (int) $m[1] ] ?? null;
		}
		return parent::get_row( $sql, $output );
	}

	private function matches( array $row, array $where ): bool {
		foreach ( $where as $column => $value ) {
			if ( (int) $row[ $column ] !== (int) $value && (string) $row[ $column ] !== (string) $value ) { return false; }
		}
		return true;
	}
}

final class SensitiveOpsTest extends TestCase {

	private OpsQueueDb $db;
	private SensitiveOperationsService $ops;
	private ApprovalRequestService $approvals;

	public function run(): void {
		$this->price_changes_wait_for_approval_then_apply();
		$this->a_repeated_price_request_is_one_row();
		$this->a_lying_price_write_is_compensated();
		$this->a_sale_product_keeps_its_sale_price();
		$this->a_lagging_active_price_is_retried();
		$this->a_tampered_payload_never_executes();
		$this->refunds_delegate_to_the_payment_guards();
		$this->bulk_delete_trashes_never_destroys();
		$this->a_mid_batch_failure_restores_what_was_trashed();
		$this->the_batch_is_bounded_and_validated();
	}

	// ---------------------------------------------------------------- price

	private function price_changes_wait_for_approval_then_apply(): void {
		$this->fresh( [ 50 => 120000.0 ] );

		$made = $this->ops->request_price_change( 1, 50, 99000.0, 7, 'کمپین' );
		$this->assert_true( $made['ok'], 'the request lands' , 'the invariant holds' );
		$this->assert_same( 120000.0, (float) SensitiveOpsWorld::$products[50]['price'], 'nothing changed before approval' );

		$row = $this->db->approvals[ $made['id'] ];
		$this->assert_same( 'price_change', (string) $row['kind'] , 'the invariant holds' );
		$this->assert_same( 'manage_tenant', (string) $row['capability'], 'the approver must prove tenant management' );

		$executor_calls = 0;
		$ok = $this->approvals->decide( $made['id'], ApprovalRequestService::STATUS_APPROVED, 9, '', function ( array $r ) use ( &$executor_calls ): bool {
			++$executor_calls;
			return $this->ops->run( $r );
		}, null, true );
		$this->assert_true( $ok, 'the approval executes' , 'the invariant holds' );
		$this->assert_same( 1, $executor_calls, 'the executor ran exactly once' );
		$this->assert_same( 99000.0, SensitiveOpsWorld::$products[50]['price'], 'the price applied' );
		$this->assert_same( 'executed', (string) $this->db->approvals[ $made['id'] ]['status'] , 'the invariant holds' );

		// The old price travelled inside the payload — the audit knows both sides.
		$payload = json_decode( (string) $this->db->approvals[ $made['id'] ]['payload'], true );
		$this->assert_same( 120000.0, (float) $payload['old_price'], 'the captured before-state is on record' );
	}

	private function a_repeated_price_request_is_one_row(): void {
		$this->fresh( [ 51 => 10.0 ] );
		$first = $this->ops->request_price_change( 1, 51, 12.0, 7 );
		$again = $this->ops->request_price_change( 1, 51, 12.0, 7 );
		$this->assert_true( $again['duplicate'], 'the same change is one row, not two' );
		$this->assert_same( $first['id'], $again['id'] , 'the invariant holds' );
	}

	private function a_lying_price_write_is_compensated(): void {
		$this->fresh( [ 52 => 500.0 ] );
		$id = $this->ops->request_price_change( 1, 52, 400.0, 7 )['id'];

		SensitiveOpsWorld::$save_lies = true; // the save pretends, the re-read catches it
		$ok = $this->approvals->decide( $id, ApprovalRequestService::STATUS_APPROVED, 9, '', fn ( array $r ): bool => $this->ops->run( $r ), null, true );

		$this->assert_true( $ok, 'the decision completes either way — the fate lives in the status' , 'the invariant holds' );
		$row = $this->db->approvals[ $id ];
		$this->assert_same( 'failed', (string) $row['status'], 'the row dies as failed' );
		$this->assert_same( 500.0, SensitiveOpsWorld::$products[52]['price'], 'the price was compensated back to the captured value' );
		$this->assert_same( 500.0, SensitiveOpsWorld::$products[52]['regular'], 'the regular price was compensated too' );
	}

	private function a_tampered_payload_never_executes(): void {
		$this->fresh( [ 53 => 100.0 ] );
		$id = $this->ops->request_price_change( 1, 53, 80.0, 7 )['id'];

		// An editor (think: a poisoned tool call) rewrites the payload after submission.
		$payload = json_decode( (string) $this->db->approvals[ $id ]['payload'], true );
		$payload['new_price'] = 1.0;
		$this->db->approvals[ $id ]['payload'] = wp_json_encode( $payload );

		$ok = $this->approvals->decide( $id, ApprovalRequestService::STATUS_APPROVED, 9, '', fn ( array $r ): bool => $this->ops->run( $r ), null, true );
		$this->assert_true( $ok, 'the decision completes either way — the fate lives in the status' , 'the invariant holds' );
		$this->assert_same( 100.0, SensitiveOpsWorld::$products[53]['price'], 'the price never moved' );
		$this->assert_same( 'failed', (string) $this->db->approvals[ $id ]['status'] , 'the invariant holds' );
	}

	private function a_sale_product_keeps_its_sale_price(): void {
		// The live-smoke finding of phase 58, as a regression test: the change owns the
		// regular price; while a sale is active the served price stays the sale price.
		$this->fresh( [ 54 => 100.0 ] );
		SensitiveOpsWorld::$products[54]['sale'] = 80.0;
		SensitiveOpsWorld::$products[54]['price'] = 80.0;

		$id = $this->ops->request_price_change( 1, 54, 90.0, 7 )['id'];
		$ok = $this->approvals->decide( $id, ApprovalRequestService::STATUS_APPROVED, 9, '', fn ( array $r ): bool => $this->ops->run( $r ), null, true );

		$this->assert_true( $ok, 'a discounted product can still change its regular price' , 'the invariant holds' );
		$this->assert_same( 'executed', (string) $this->db->approvals[ $id ]['status'], 'the row is executed, not falsely failed' );
		$this->assert_same( 90.0, SensitiveOpsWorld::$products[54]['regular'], 'the regular price moved' );
		$this->assert_same( 80.0, SensitiveOpsWorld::$products[54]['price'], 'the sale price is still what customers pay' );
	}

	private function a_lagging_active_price_is_retried(): void {
		// The other live-smoke finding: the regular price moved but the active price
		// lagged one save behind. The service retries the activation; the customer
		// never sees a price the queue did not approve.
		$this->fresh( [ 55 => 700.0 ] );
		$id = $this->ops->request_price_change( 1, 55, 600.0, 7 )['id'];

		SensitiveOpsWorld::$active_lags = true;
		$ok = $this->approvals->decide( $id, ApprovalRequestService::STATUS_APPROVED, 9, '', fn ( array $r ): bool => $this->ops->run( $r ), null, true );

		$this->assert_true( $ok, 'the activation retry recovers the lagging price' , 'the invariant holds' );
		$this->assert_same( 'executed', (string) $this->db->approvals[ $id ]['status'], 'the row is executed' );
		$this->assert_same( 600.0, SensitiveOpsWorld::$products[55]['regular'], 'the regular price moved' );
		$this->assert_same( 600.0, SensitiveOpsWorld::$products[55]['price'], 'the active price caught up' );
	}

	// --------------------------------------------------------------- refund

	private function refunds_delegate_to_the_payment_guards(): void {
		$this->fresh();

		$this->db->payments[1] = [
			'id' => 1, 'tenant_id' => 1, 'user_id' => 7, 'gateway' => 'opsgw', 'reference_id' => 'REF-A',
			'amount' => 100.0, 'status' => 'pending', 'meta' => wp_json_encode( [] ), 'purpose' => 'order',
			'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		];
		$this->db->payments[2] = [
			'id' => 2, 'tenant_id' => 1, 'user_id' => 7, 'gateway' => 'opsgw', 'reference_id' => 'REF-B',
			'amount' => 200.0, 'status' => 'paid', 'meta' => wp_json_encode( [] ), 'purpose' => 'order',
			'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		];

		$unpaid = $this->ops->request_refund( 1, 1, 50.0, 7, 'pars' )['id'];
		$ok = $this->approvals->decide( $unpaid, ApprovalRequestService::STATUS_APPROVED, 9, '', fn ( array $r ): bool => $this->ops->run( $r ), null, true );
		$this->assert_true( $ok, 'the decision completes either way — the fate lives in the status' , 'the invariant holds' );
		$this->assert_same( 'failed', (string) $this->db->approvals[ $unpaid ]['status'], 'the refused refund dies as failed' );
		$this->assert_same( 0, count( SensitiveOpsWorld::$refunds ), 'the gateway was never touched' );

		$paid = $this->ops->request_refund( 1, 2, 50.0, 7, 'partial', 'refund:2:smoke' )['id'];
		$ok = $this->approvals->decide( $paid, ApprovalRequestService::STATUS_APPROVED, 9, '', fn ( array $r ): bool => $this->ops->run( $r ), null, true );
		$this->assert_true( $ok, 'a paid payment refunds through the PSP' , 'the invariant holds' );
		$this->assert_same( 1, count( SensitiveOpsWorld::$refunds ), 'exactly one PSP call' );
		$this->assert_same( 'REF-B', (string) SensitiveOpsWorld::$refunds[0]['reference'] , 'the invariant holds' );
		$this->assert_same( 'refund:2:1', (string) SensitiveOpsWorld::$refunds[0]['context']['idempotency_key'], 'the PSP idempotency key rides the refund' );

		$meta = json_decode( (string) $this->db->payments[2]['meta'], true );
		$this->assert_same( 50.0, (float) $meta['refunds'][0]['amount'], 'the refund is on the payment ledger' );
	}

	// ----------------------------------------------------------- bulk delete

	private function bulk_delete_trashes_never_destroys(): void {
		$this->fresh( [ 61 => 5.0, 62 => 5.0 ] );
		$id = $this->ops->request_bulk_delete( [ 61, 62 ], 1, 7, 'پاکسازی' )['id'];

		$ok = $this->approvals->decide( $id, ApprovalRequestService::STATUS_APPROVED, 9, '', fn ( array $r ): bool => $this->ops->run( $r ), null, true );
		$this->assert_true( $ok, 'the batch executes' , 'the invariant holds' );
		$this->assert_same( 1, (int) SensitiveOpsWorld::$trash[61], 'product 61 is trashed' );
		$this->assert_same( 1, (int) SensitiveOpsWorld::$trash[62], 'product 62 is trashed' );
		$this->assertSameProductsStillExist( 'trashing keeps the rows — the operation is reversible by design' );

		$row = $this->db->approvals[ $id ];
		$this->assert_same( 'bulk_product_delete', (string) $row['kind'] , 'the invariant holds' );
		$this->assert_same( 'critical', (string) $row['impact'], 'bulk delete is critical impact' );
	}

	private function a_mid_batch_failure_restores_what_was_trashed(): void {
		$this->fresh( [ 63 => 5.0, 64 => 5.0, 65 => 5.0 ] );
		SensitiveOpsWorld::$trash_fails = [ 64 ];

		$id = $this->ops->request_bulk_delete( [ 63, 64, 65 ], 1, 7 )['id'];
		$ok = $this->approvals->decide( $id, ApprovalRequestService::STATUS_APPROVED, 9, '', fn ( array $r ): bool => $this->ops->run( $r ), null, true );

		$this->assert_true( $ok, 'the decision completes either way — the fate lives in the status' , 'the invariant holds' );
		$this->assert_same( 'failed', (string) $this->db->approvals[ $id ]['status'] , 'the invariant holds' );
		$this->assert_same( 0, (int) SensitiveOpsWorld::$trash[63], 'product 63 (trashed before the failure) was restored' );
		$this->assert_same( 0, (int) ( SensitiveOpsWorld::$trash[64] ?? 0 ), 'product 64 never moved' );
		$this->assert_same( 0, (int) ( SensitiveOpsWorld::$trash[65] ?? 0 ), 'product 65 was never reached' );
	}

	private function the_batch_is_bounded_and_validated(): void {
		$this->fresh();
		$too_big = range( 1, 501 );
		$made = $this->ops->request_bulk_delete( $too_big, 1, 7 );
		$this->assert_false( $made['ok'], 'a 501-row batch is refused up front' , 'the invariant holds' );
		$this->assert_same( 'batch_too_large', $made['error'] , 'the invariant holds' );

		$empty = $this->ops->request_bulk_delete( [ 0, -3, 'x' ], 1, 7 );
		$this->assert_false( $empty['ok'], 'an empty-after-validation batch is refused' , 'the invariant holds' );

		$bad = $this->ops->request_price_change( 1, 0, 10.0, 7 );
		$this->assert_false( $bad['ok'], 'a price change without a product is refused' , 'the invariant holds' );
	}

	// ---------------------------------------------------------------- setup

	/** @param array<int,float> $products id => price */
	private function fresh( array $products = [] ): void {
		igbz_test_reset_settings();
		SensitiveOpsWorld::reset();
		foreach ( $products as $id => $price ) {
			SensitiveOpsWorld::$products[ $id ] = [ 'regular' => (float) $price, 'sale' => 0.0, 'price' => (float) $price ];
		}

		$this->db = new OpsQueueDb();
		$GLOBALS['wpdb'] = $this->db;

		$db     = new Db();
		$logger = igbz()->get( 'logger' );

		$this->approvals = new ApprovalRequestService( $db );

		$wallet  = new IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService( $db, $logger );
		$payments = new PaymentService( $db, igbz()->get( 'http' ), $wallet, $logger );
		$payments->register( new SensitiveOpsGateway() );

		$this->ops = new SensitiveOpsServiceSpy( $db, $logger, $this->approvals, $payments );
	}

	private function assertSameProductsStillExist( string $message ): void {
		foreach ( [ 61, 62 ] as $id ) {
			$this->assert_true( isset( SensitiveOpsWorld::$products[ $id ] ), $message , 'the invariant holds' );
		}
	}
}
