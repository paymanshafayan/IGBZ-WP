<?php
/**
 * Phase 21 — WooCommerce order flows must behave identically on legacy post-based storage and on
 * HPOS (custom order tables). The suite never reads orders from wp_posts itself; these tests run
 * the real flows against a WooCommerce double whose storage mode can be flipped, and assert that
 * (a) compatibility is declared, (b) every access goes through the order CRUD object, (c) the few
 * storage-aware helpers pick the correct table/link per mode.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Utilities {
	if ( ! class_exists( FeaturesUtil::class ) ) {
		class FeaturesUtil {
			/** @var array<string,array{file:string,compatible:bool}> */
			public static array $declarations = [];

			public static function declare_compatibility( string $feature, string $file, bool $compatible ): void {
				self::$declarations[ $feature ] = [
					'file'       => $file,
					'compatible' => $compatible,
				];
			}
		}
	}

	if ( ! class_exists( OrderUtil::class ) ) {
		class OrderUtil {
			public static bool $hpos = false;

			public static function custom_orders_table_usage_is_enabled(): bool {
				return self::$hpos;
			}

			public static function get_order_admin_edit_url( int $order_id ): string {
				return self::$hpos
					? 'http://example.test/wp-admin/admin.php?page=wc-orders&action=edit&id=' . $order_id
					: 'http://example.test/wp-admin/post.php?post=' . $order_id . '&action=edit';
			}
		}
	}
}

namespace Automattic\WooCommerce\Internal\DataStores\Orders {
	if ( ! class_exists( OrdersTableDataStore::class ) ) {
		class OrdersTableDataStore {
			public static function get_orders_table_name(): string {
				global $wpdb;
				return $wpdb->prefix . 'wc_orders';
			}
		}
	}
}

namespace {

	use IGBZ\Suite\Modules\Hub\Services\HubStats;
	use IGBZ\Suite\Modules\MultiTenant\Affiliate\AffiliateService;
	use IGBZ\Suite\Modules\MultiTenant\Lms\LmsService;
	use IGBZ\Suite\Modules\MultiTenant\MultiTenantModule;
	use IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService;
	use IGBZ\Suite\Support\Db;
	use IGBZ\Suite\Support\Plugin;
	use IGBZ\Suite\Support\WooCommerceCompat;

	if ( ! class_exists( 'WC_Order' ) ) {
		class WC_Order {
			/** @var array<string,mixed> */
			public array $meta = [];
			public int $saves  = 0;

			public function __construct(
				private int $id,
				private int $customer_id = 0,
				private float $total = 0.0,
				private float $subtotal = 0.0,
				private float $discount = 0.0,
				private string $status = 'completed',
				/** @var array<int,object> */
				private array $items = []
			) {}

			public function get_id(): int {
				return $this->id;
			}

			public function get_customer_id(): int {
				return $this->customer_id;
			}

			public function get_total(): float {
				return $this->total;
			}

			public function get_subtotal(): float {
				return $this->subtotal;
			}

			public function get_total_discount(): float {
				return $this->discount;
			}

			public function get_status(): string {
				return $this->status;
			}

			/** @return array<int,object> */
			public function get_items( string $type = 'line_item' ): array {
				return $this->items;
			}

			public function get_meta( string $key, bool $single = true ) {
				return $this->meta[ $key ] ?? '';
			}

			public function update_meta_data( string $key, $value ): void {
				$this->meta[ $key ] = $value;
			}

			public function save(): void {
				++$this->saves;
			}
		}
	}

	if ( ! class_exists( 'WC_Order_Item_Product' ) ) {
		class WC_Order_Item_Product {
			public function __construct( private int $product_id ) {}

			public function get_product_id(): int {
				return $this->product_id;
			}
		}
	}

	/** Shared state the WC doubles read from; tests reset it per scenario. */
	final class IgbzHposStub {
		public static bool $hpos = false;

		/** @var array<int,WC_Order> */
		public static array $orders = [];

		/** @var array<int,array<string,mixed>> Arguments wc_get_orders() was called with. */
		public static array $order_queries = [];

		/** @var mixed What wc_get_orders() hands back. */
		public static $orders_result = null;

		/** @var array<int,array{0:int,1:string,2:mixed}> Direct postmeta writes — must stay empty for orders. */
		public static array $postmeta_writes = [];

		public static function reset(): void {
			self::$hpos            = false;
			self::$orders          = [];
			self::$order_queries   = [];
			self::$orders_result   = null;
			self::$postmeta_writes = [];
			\Automattic\WooCommerce\Utilities\FeaturesUtil::$declarations = [];
		}
	}

	if ( ! function_exists( 'wc_get_order' ) ) {
		function wc_get_order( $order_id ) {
			return IgbzHposStub::$orders[ (int) $order_id ] ?? false;
		}
	}

	if ( ! function_exists( 'wc_get_orders' ) ) {
		function wc_get_orders( array $args = [] ) {
			IgbzHposStub::$order_queries[] = $args;
			return IgbzHposStub::$orders_result;
		}
	}

	if ( ! function_exists( 'admin_url' ) ) {
		function admin_url( string $path = '' ): string {
			return 'http://example.test/wp-admin/' . ltrim( $path, '/' );
		}
	}

	if ( ! function_exists( 'update_post_meta' ) ) {
		function update_post_meta( int $post_id, string $meta_key, $meta_value ): bool {
			IgbzHposStub::$postmeta_writes[] = [ $post_id, $meta_key, $meta_value ];
			return true;
		}
	}

	final class HposOrderFlowTest extends TestCase {

		public function run(): void {
			$this->compat_is_declared_for_order_tables_and_blocks();
			$this->tenant_stamp_goes_through_crud_in_both_storages();
			$this->order_edit_url_follows_the_active_storage();
			$this->hub_revenue_reads_the_active_store();
			$this->completed_order_flow_is_storage_independent();
		}

		/** @return array<string,bool> */
		private function both_storages(): array {
			return [ 'legacy' => false, 'hpos' => true ];
		}

		private function set_storage( bool $hpos ): void {
			IgbzHposStub::$hpos                                  = $hpos;
			\Automattic\WooCommerce\Utilities\OrderUtil::$hpos   = $hpos;
		}

		/**
		 * Snapshot/restore the container so the test's fake services can never leak into later
		 * cases.
		 */
		private function with_clean_container( callable $fn ): void {
			$factories_ref = new ReflectionProperty( Plugin::class, 'factories' );
			$resolved_ref  = new ReflectionProperty( Plugin::class, 'resolved' );
			$factories     = $factories_ref->getValue( igbz() );
			$cache         = $resolved_ref->getValue( igbz() );

			try {
				$fn();
			} finally {
				$factories_ref->setValue( igbz(), $factories );
				$resolved_ref->setValue( igbz(), $cache );
			}
		}

		private function compat_is_declared_for_order_tables_and_blocks(): void {
			IgbzHposStub::reset();

			WooCommerceCompat::declare_compatibility();

			$declared = \Automattic\WooCommerce\Utilities\FeaturesUtil::$declarations;
			$this->assert_true(
				isset( $declared['custom_order_tables'] ) && true === $declared['custom_order_tables']['compatible'],
				'HPOS (custom order tables) compatibility is declared, not just implemented'
			);
			$this->assert_true(
				isset( $declared['cart_checkout_blocks'] ) && true === $declared['cart_checkout_blocks']['compatible'],
				'block cart/checkout compatibility is declared (the checkout hook fires in both)'
			);
			$this->assert_not_same(
				'',
				(string) ( $declared['custom_order_tables']['file'] ?? '' ),
				'the declaration names the plugin file'
			);
		}

		private function tenant_stamp_goes_through_crud_in_both_storages(): void {
			foreach ( $this->both_storages() as $mode => $hpos ) {
				IgbzHposStub::reset();
				$this->set_storage( $hpos );

				$this->with_clean_container( function () use ( $mode ): void {
					$GLOBALS['wpdb'] = new class() extends wpdb {
						public function get_row( string $sql, $output = null ) {
							$this->queries[] = $sql;
							if ( str_contains( $sql, "id = '7'" ) ) {
								return [ 'id' => 7, 'slug' => 't7', 'name' => 'T7', 'owner_user_id' => 7, 'status' => 'active', 'plan_id' => 0, 'currency' => 'IRT', 'locale' => 'fa' ];
							}
							return null;
						}
					};
					igbz()->bind( 'tenancy', static fn () => new \IGBZ\Suite\Modules\MultiTenant\Repository\TenantContext( new Db() ) );
					igbz()->tenancy()->force( 7 );
					igbz()->bind( 'affiliate', static fn () => new class() {
						public function cookie_code(): string {
							return '';
						}
					} );

					$order  = new WC_Order( 101, 5, 1000.0 );
					$module = new MultiTenantModule();
					$module->stamp_tenant_on_order( $order );

					$this->assert_same(
						7,
						(int) $order->get_meta( '_igbz_tenant_id' ),
						"[$mode] the tenant stamp lands on the order via CRUD meta"
					);
					$this->assert_same( 1, $order->saves, "[$mode] the stamp persists through \$order->save()" );
					$this->assert_same(
						[],
						IgbzHposStub::$postmeta_writes,
						"[$mode] no direct postmeta write — that storage does not exist under HPOS"
					);
				} );
			}
		}

		private function order_edit_url_follows_the_active_storage(): void {
			IgbzHposStub::reset();

			$this->set_storage( false );
			$this->assert_contains(
				'post.php?post=55',
				WooCommerceCompat::order_edit_url( 55 ),
				'legacy stores edit orders on the post screen'
			);

			$this->set_storage( true );
			$this->assert_same(
				'http://example.test/wp-admin/admin.php?page=wc-orders&action=edit&id=55',
				WooCommerceCompat::order_edit_url( 55 ),
				'HPOS stores edit orders on the wc-orders screen, not post.php'
			);
		}

		private function hub_revenue_reads_the_active_store(): void {
			foreach ( $this->both_storages() as $mode => $hpos ) {
				IgbzHposStub::reset();
				$this->set_storage( $hpos );

				$GLOBALS['wpdb']               = new wpdb();
				$GLOBALS['wpdb']->next_results  = [ '2500.50' ];
				IgbzHposStub::$orders_result   = (object) [ 'total' => 3 ];

				$stats  = new HubStats( new Db() );
				$method = new ReflectionMethod( HubStats::class, 'order_totals' );
				[ $count, $revenue ] = $method->invoke( $stats );

				$this->assert_same( 3, $count, "[$mode] paid order count comes from wc_get_orders" );
				$this->assert_same( 2500.5, $revenue, "[$mode] revenue sum is returned" );

				$last = (string) end( $GLOBALS['wpdb']->queries );
				if ( $hpos ) {
					$this->assert_contains( 'wp_wc_orders', $last, "[$mode] HPOS revenue reads the custom orders table" );
					$this->assert_not_contains( 'postmeta', $last, "[$mode] HPOS revenue never touches postmeta" );
				} else {
					$this->assert_contains( '_order_total', $last, "[$mode] legacy revenue reads order postmeta" );
					$this->assert_contains( "post_type = 'shop_order'", $last, "[$mode] legacy revenue filters shop_order posts" );
				}
			}
		}

		private function completed_order_flow_is_storage_independent(): void {
			foreach ( $this->both_storages() as $mode => $hpos ) {
				IgbzHposStub::reset();
				$this->set_storage( $hpos );
				igbz_test_reset_settings();

				$this->with_clean_container( function () use ( $mode ): void {
					// Equality-only WHERE double, same contract as TenantScopeTest.
					$rows   = [
						[ 'id' => 1, 'slug' => 't1', 'name' => 'T1', 'owner_user_id' => 1, 'status' => 'active', 'plan_id' => 0, 'currency' => 'IRT', 'locale' => 'fa' ],
						[ 'id' => 9, 'tenant_id' => 1, 'user_id' => 9, 'commission_rate' => 10, 'parent_id' => 0 ],
					];
					$wpdb   = new class( $rows ) extends wpdb {
						/** @param array<int,array<string,mixed>> $affiliates */
						public function __construct( public array $affiliates ) {}

						public function get_row( string $sql, $output = null ) {
							$this->queries[] = $sql;
							foreach ( $this->affiliates as $row ) {
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

						public function get_results( string $sql, $output = null ) {
							$this->queries[] = $sql;
							$row             = $this->get_row( $sql );
							return $row ? [ $row ] : [];
						}
					};
					$GLOBALS['wpdb'] = $wpdb;

					igbz()->bind( 'tenancy', static fn () => new \IGBZ\Suite\Modules\MultiTenant\Repository\TenantContext( new Db() ) );
					igbz()->tenancy()->force( 1 );

					$db     = new Db();
					$logger = igbz()->get( 'logger' );
					igbz()->bind( 'wallet', static fn () => new WalletService( $db, $logger ) );
					igbz()->bind( 'affiliate', static fn () => new AffiliateService( $db, igbz()->get( 'wallet' ), $logger ) );
					igbz()->bind( 'lms', static fn () => new LmsService( $db ) );

					$order = new WC_Order(
						77,
						5,
						1000.0,
						1000.0,
						0.0,
						'completed',
						[ new WC_Order_Item_Product( 555 ) ]
					);
					$order->update_meta_data( '_igbz_tenant_id', 1 );
					$order->update_meta_data( '_igbz_affiliate_id', 9 );
					IgbzHposStub::$orders[77] = $order;

					( new MultiTenantModule() )->on_order_completed( 77 );

					$commission_writes = array_filter(
						$wpdb->writes,
						static fn ( array $w ): bool => str_contains( (string) $w['table'], 'affiliate_commissions' )
					);
					$this->assert_same(
						1,
						count( $commission_writes ),
						"[$mode] a completed order records exactly one tier-1 commission"
					);
					$write = array_values( $commission_writes )[0] ?? [ 'data' => [] ];
					$this->assert_same( 1, (int) ( $write['data']['tenant_id'] ?? 0 ), "[$mode] commission carries the tenant from order meta" );
					$this->assert_same( 100.0, (float) ( $write['data']['amount'] ?? 0 ), "[$mode] 10% of the 1000-rial base" );
					$this->assert_same(
						[],
						IgbzHposStub::$postmeta_writes,
						"[$mode] the completed-order flow never bypasses the order CRUD"
					);
				} );
			}
		}
	}
}
