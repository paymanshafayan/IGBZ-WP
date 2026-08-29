<?php
use IGBZ\Suite\Modules\MultiTenant\Affiliate\AffiliateService;
use IGBZ\Suite\Modules\MultiTenant\Bnpl\BnplService;
use IGBZ\Suite\Modules\MultiTenant\Gamification\AiCreditsService;
use IGBZ\Suite\Modules\MultiTenant\Lms\LmsService;
use IGBZ\Suite\Modules\Fx\FxAccountsService;
use IGBZ\Suite\Modules\MultiTenant\Logistics\CourierService;
use IGBZ\Suite\Modules\MultiTenant\Logistics\LogisticsService;
use IGBZ\Suite\Modules\MultiTenant\Marketplace\MarketplaceService;
use IGBZ\Suite\Modules\MultiTenant\MasterPayment\MasterPaymentService;
use IGBZ\Suite\Modules\MultiTenant\Payments\PaymentService;
use IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Http;
use IGBZ\Suite\Support\Logger;

require_once __DIR__ . '/LmsTest.php';

/**
 * Phase 07 (OWASP API1, negative access tests): an object id alone must never cross a tenant
 * boundary. Every domain getter must answer "not found" for another tenant's row, and serve
 * the row only inside its own tenant.
 */
final class TenantScopeTest extends TestCase {

	/** Minimal tenant rows so TenantContext::force() can resolve through the doubles. */
	private const TENANTS = [
		1 => [ 'id' => 1, 'slug' => 't1', 'name' => 'T1', 'owner_user_id' => 1, 'status' => 'active', 'plan_id' => 0, 'currency' => 'IRT', 'locale' => 'fa' ],
		2 => [ 'id' => 2, 'slug' => 't2', 'name' => 'T2', 'owner_user_id' => 2, 'status' => 'active', 'plan_id' => 0, 'currency' => 'IRT', 'locale' => 'fa' ],
	];

	/**
	 * Equality-only WHERE double: every `column = 'value'` pair in the SQL must hold on the
	 * row, which is exactly how the real engine answers these statements.
	 */
	private function scoped_db( array $tables ): Db {
		$wpdb          = new class( $tables ) extends wpdb {
			/** @param array<string,array<int,array<string,mixed>>> $tables */
			public function __construct( public array $tables ) {}

			public function get_row( string $sql, $output = null ) {
				$this->queries[] = $sql;
				return $this->first( $sql );
			}

			public function get_results( string $sql, $output = null ) {
				$this->queries[] = $sql;
				$row             = $this->first( $sql );
				return $row ? [ $row ] : [];
			}

			private function first( string $sql ): ?array {
				if ( ! preg_match( '/igbz_(\w+)/', $sql, $m ) ) {
					return null;
				}
				foreach ( $this->tables[ $m[1] ] ?? [] as $row ) {
					$ok = true;
					if ( preg_match_all( "/\b([a-z_]+) = '([^']*)'/", $sql, $pairs, PREG_SET_ORDER ) ) {
						foreach ( $pairs as $p ) {
							if ( (string) ( $row[ $p[1] ] ?? '' ) !== $p[2] ) {
								$ok = false;
								break;
							}
						}
					}
					if ( $ok ) {
						return $row;
					}
				}
				return null;
			}
		};
		$GLOBALS['wpdb'] = $wpdb;
		return new Db();
	}

	private function in_tenant( int $tenant_id, callable $fn ): mixed {
		igbz()->tenancy()->force( $tenant_id );
		try {
			return $fn();
		} finally {
			igbz()->tenancy()->force( null );
		}
	}

	public function run(): void {
		$this->lms_objects_stay_inside_their_tenant();
		$this->payment_objects_stay_inside_their_tenant();
		$this->affiliate_objects_stay_inside_their_tenant();
		$this->bnpl_objects_stay_inside_their_tenant();
		$this->logistics_objects_stay_inside_their_tenant();
		$this->marketplace_links_stay_inside_their_tenant();
		$this->master_payment_hold_is_per_tenant();
		$this->courier_chat_needs_an_owned_shipment();
		$this->fx_objects_stay_inside_their_tenant();
	}

	private function fx_objects_stay_inside_their_tenant(): void {
		$db = $this->scoped_db(
			[
				'tenants'     => self::TENANTS,
				'fx_accounts' => [ 8 => [ 'id' => 8, 'tenant_id' => 1, 'provider' => 'zernio', 'status' => 'active' ] ],
			]
		);
		$fx = new FxAccountsService( $db );

		$this->assert_true( null !== $this->in_tenant( 1, fn () => $fx->get( 8 ) ), 'fx account readable in own tenant' );
		$this->assert_same( null, $this->in_tenant( 2, fn () => $fx->get( 8 ) ), 'fx account id from another tenant resolves to nothing' );
	}

	private function lms_objects_stay_inside_their_tenant(): void {
		$wpdb          = new LmsDb();
		$GLOBALS['wpdb'] = $wpdb;
		foreach ( self::TENANTS as $row ) {
			$wpdb->seed( 'tenants', $row );
		}
		$course        = $wpdb->seed_course( [ 'tenant_id' => 1, 'title' => 'Tenant one course' ] );
		$enrollment    = $wpdb->seed_enrollment( [ 'course_id' => $course, 'user_id' => 5, 'tenant_id' => 1 ] );
		$lesson        = $wpdb->seed( 'lessons', [ 'course_id' => $course, 'tenant_id' => 1, 'title' => 'L', 'sort_order' => 1 ] );
		$quiz          = $wpdb->seed( 'quizzes', [ 'course_id' => $course, 'tenant_id' => 1, 'title' => 'Q' ] );

		$lms = new LmsService( new Db() );

		$this->assert_true(
			null !== $this->in_tenant( 1, fn () => $lms->course( $course ) ),
			'course is readable inside its own tenant'
		);
		$this->assert_same(
			null,
			$this->in_tenant( 2, fn () => $lms->course( $course ) ),
			'course id from another tenant resolves to nothing (OWASP API1 negative case)'
		);
		$this->assert_same(
			null,
			$this->in_tenant( 2, fn () => $lms->enrollment( $course, 5 ) ),
			'enrollment lookup is tenant-bound'
		);
		$this->assert_true(
			null !== $this->in_tenant( 1, fn () => $lms->enrollment( $course, 5 ) ),
			'enrollment readable inside its own tenant'
		);
		$this->assert_same(
			null,
			$this->in_tenant( 2, fn () => $lms->course_by_product( (int) ( $wpdb->get( 'courses', $course )['product_id'] ?? 0 ) ) ),
			'course_by_product is tenant-bound'
		);
		$this->assert_same( null, $this->in_tenant( 2, fn () => $lms->lesson( $lesson ) ), 'lesson id from another tenant resolves to nothing' );
		$this->assert_same( null, $this->in_tenant( 2, fn () => $lms->quiz( $quiz ) ), 'quiz id from another tenant resolves to nothing' );
	}

	private function payment_objects_stay_inside_their_tenant(): void {
		$db = $this->scoped_db(
			[
				'tenants'  => self::TENANTS,
				'payments' => [ 9 => [ 'id' => 9, 'tenant_id' => 1, 'user_id' => 5, 'authority' => 'auth-9', 'status' => 'paid', 'amount' => 1 ] ],
			]
		);
		$logger  = new Logger( igbz()->settings() );
		$service = new PaymentService( $db, new Http( $logger ), new WalletService( $db, $logger ), $logger );

		$this->assert_true( null !== $this->in_tenant( 1, fn () => $service->payment( 9 ) ), 'payment readable in own tenant' );
		$this->assert_same( null, $this->in_tenant( 2, fn () => $service->payment( 9 ) ), 'payment id from another tenant resolves to nothing' );
		$this->assert_same( [], $this->in_tenant( 2, fn () => $service->payments_for_user( 5 ) ), 'per-user payment list is tenant-bound' );
	}

	private function affiliate_objects_stay_inside_their_tenant(): void {
		$db = $this->scoped_db(
			[
				'tenants'    => self::TENANTS,
				'affiliates' => [ 3 => [ 'id' => 3, 'tenant_id' => 1, 'user_id' => 5, 'code' => 'AFF3' ] ],
			]
		);
		$logger  = new Logger( igbz()->settings() );
		$service = new AffiliateService( $db, new WalletService( $db, $logger ), $logger );

		$this->assert_true( null !== $this->in_tenant( 1, fn () => $service->find( 3 ) ), 'affiliate readable in own tenant' );
		$this->assert_same( null, $this->in_tenant( 2, fn () => $service->find( 3 ) ), 'affiliate id from another tenant resolves to nothing' );
	}

	private function logistics_objects_stay_inside_their_tenant(): void {
		$db = $this->scoped_db(
			[
				'tenants'      => self::TENANTS,
				'ig_shipments' => [ 7 => [ 'id' => 7, 'tenant_id' => 1, 'order_id' => 41, 'status' => 'draft', 'tracking_code' => 'TRK-7', 'delivery_pin' => '1234' ] ],
			]
		);
		$logger  = new Logger( igbz()->settings() );
		$service = new LogisticsService( $db, igbz()->settings(), $logger );

		$this->assert_true( null !== $this->in_tenant( 1, fn () => $service->get( 7 ) ), 'shipment readable in own tenant' );
		$this->assert_same( null, $this->in_tenant( 2, fn () => $service->get( 7 ) ), 'shipment id from another tenant resolves to nothing' );
	}

	private function marketplace_links_stay_inside_their_tenant(): void {
		$db = $this->scoped_db(
			[
				'tenants'           => self::TENANTS,
				'marketplace_links' => [ 2 => [ 'id' => 2, 'tenant_id' => 1, 'product_id' => 55, 'channel' => 'basalam', 'external_id' => 'x' ] ],
			]
		);
		$service = new MarketplaceService( $db, new Logger( igbz()->settings() ) );

		$this->assert_true( null !== $service->link( 55, 'basalam', 1 ), 'marketplace link readable in own tenant' );
		$this->assert_same( null, $service->link( 55, 'basalam', 2 ), 'same product id in another tenant has no link' );
	}

	private function master_payment_hold_is_per_tenant(): void {
		$db = $this->scoped_db(
			[
				'tenants'            => self::TENANTS,
				'ig_master_payments' => [ 3 => [ 'id' => 3, 'tenant_id' => 1, 'order_id' => 77, 'phase' => 'rial', 'status' => 'held' ] ],
			]
		);
		$service = new MasterPaymentService( $db, new Logger( igbz()->settings() ) );

		$own = $service->hold( 1, 77, 1000.0 );
		$this->assert_same( 'already_held', $own['error'], 'duplicate hold inside the owning tenant is refused' );
		$other = $service->hold( 2, 77, 1000.0 );
		$this->assert_true( $other['ok'], 'another tenant holding the same global order id is a fresh hold' );
	}

	private function courier_chat_needs_an_owned_shipment(): void {
		$db = $this->scoped_db(
			[
				'tenants'      => self::TENANTS,
				'ig_shipments' => [ 7 => [ 'id' => 7, 'tenant_id' => 1, 'courier_id' => 9, 'status' => 'assigned', 'tracking_code' => 'TRK-7', 'delivery_pin' => '1234' ] ],
			]
		);
		$service = new CourierService( $db, new Logger( igbz()->settings() ) );

		$this->assert_same( [], $service->chat( 7, 999 ), 'chat stays empty for a courier that does not own the shipment' );
		$this->assert_same( 0, $service->send_chat( 7, 'courier', 'hi', 1, 999 ), 'chat message cannot land on an unowned shipment' );
	}

	private function bnpl_objects_stay_inside_their_tenant(): void {
		$db = $this->scoped_db(
			[
				'tenants'           => self::TENANTS,
				'bnpl_contracts'    => [ 4 => [ 'id' => 4, 'tenant_id' => 1, 'user_id' => 5, 'status' => 'active' ] ],
				'bnpl_installments' => [ 6 => [ 'id' => 6, 'contract_id' => 4, 'tenant_id' => 1, 'status' => 'due', 'sequence' => 1 ] ],
			]
		);
		$logger  = new Logger( igbz()->settings() );
		$service = new BnplService( $db, new WalletService( $db, $logger ), $logger );

		$this->assert_true( null !== $this->in_tenant( 1, fn () => $service->contract( 4 ) ), 'contract readable in own tenant' );
		$this->assert_same( null, $this->in_tenant( 2, fn () => $service->contract( 4 ) ), 'contract id from another tenant resolves to nothing' );
	}
}
