<?php
namespace IGBZ\Suite\Modules\MultiTenant;

use IGBZ\Suite\Modules\MultiTenant\Affiliate\AffiliateService;
use IGBZ\Suite\Modules\MultiTenant\Bnpl\BnplGateway;
use IGBZ\Suite\Modules\MultiTenant\Bnpl\BnplService;
use IGBZ\Suite\Modules\MultiTenant\Bnpl\HttpBnplProvider;
use IGBZ\Suite\Modules\MultiTenant\Bnpl\ProviderRegistry;
use IGBZ\Suite\Modules\MultiTenant\Lms\LmsService;
use IGBZ\Suite\Modules\MultiTenant\Marketplace\MarketplaceService;
use IGBZ\Suite\Modules\MultiTenant\Otp\OtpService;
use IGBZ\Suite\Modules\MultiTenant\Payments\CallbackHandler;
use IGBZ\Suite\Modules\MultiTenant\Payments\PaymentService;
use IGBZ\Suite\Modules\MultiTenant\Payments\PspGateway;
use IGBZ\Suite\Modules\MultiTenant\Plans\PlanService;
use IGBZ\Suite\Modules\MultiTenant\Repository\TenantRepository;
use IGBZ\Suite\Modules\MultiTenant\Wallet\WalletGateway;
use IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService;
use IGBZ\Suite\Support\Capabilities;
use IGBZ\Suite\Support\Cron;
use IGBZ\Suite\Support\Jobs\JobContext;
use IGBZ\Suite\Support\Jobs\JobQueue;
use IGBZ\Suite\Support\ModuleInterface;
use IGBZ\Suite\Support\Modules;
use IGBZ\Suite\Support\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Port of the nopCommerce "IGBZ.MultiTenantStores" plugin.
 *
 * Owns tenants, wallet, plans/subscriptions, BNPL, affiliate marketing, the LMS, the PSP layer,
 * phone OTP and the marketplace feeds. Every service is registered in the container so the other
 * modules (and third-party code) can reuse them.
 */
final class MultiTenantModule implements ModuleInterface {

	/** Phase 25: batch sizes of the tenant sweeps (must match the service LIMITs). */
	private const SWEEP_BATCH_BNPL  = 200;
	private const SWEEP_BATCH_CARTS = 50;

	/** Phase 25: continuation rounds per tenant per hour — caps the worst-case loop. */
	private const MAX_SWEEP_ROUNDS = 10;

	/** Phase 26: batch sizes of the daily sweeps (must match the service LIMITs). */
	private const DAILY_BATCH_RENEWALS     = 100;
	private const DAILY_BATCH_COMMISSIONS  = 200;
	private const DAILY_BATCH_MASTER       = 100;

	/** Phase 29: webhook inbox batch per drain round. */
	private const WEBHOOK_BATCH = 20;

	public function id(): string {
		return Modules::MULTITENANT;
	}

	public function title(): string {
		return __( 'Multi-tenant stores', 'igbz-suite' );
	}

	public function description(): string {
		return __( 'Tenants, wallet, subscription plans, instalments (BNPL), affiliate marketing, courses, payment gateways, phone OTP and marketplace feeds.', 'igbz-suite' );
	}

	public function register( Plugin $plugin ): void {
		$this->bind_services( $plugin );

		// Phase 13: deleting a tenant sweeps every tenant-scoped table with it, audited.
		add_action(
			'igbz_tenant_deleted',
			static function ( int $tenant_id ): void {
				igbz()->get( 'tenant.offboarding' )->purge( $tenant_id );
			}
		);

		// --- storefront / account plumbing -----------------------------------
		add_action( 'init', [ $this, 'capture_referral' ], 5 );
		add_action( 'init', [ $this, 'maybe_render_feed' ], 6 );
		( new CallbackHandler() )->register();

		// --- WooCommerce integration -----------------------------------------
		add_filter( 'woocommerce_payment_gateways', [ $this, 'register_gateways' ] );
		add_filter( 'woocommerce_product_query_meta_query', [ $this, 'scope_product_query' ], 10, 2 );
		add_filter( 'woocommerce_order_query_args', [ $this, 'scope_order_query' ] );
		add_action( 'pre_get_posts', [ $this, 'scope_admin_queries' ], 20 );
		add_action( 'woocommerce_new_product', [ $this, 'stamp_new_product' ], 10, 2 );
		add_action( 'woocommerce_order_status_completed', [ $this, 'on_order_completed' ] );
		add_action( 'woocommerce_order_status_processing', [ $this, 'on_order_completed' ] );
		add_action( 'woocommerce_order_status_refunded', [ $this, 'on_order_reversed' ] );
		add_action( 'woocommerce_order_status_cancelled', [ $this, 'on_order_reversed' ] );
		add_action( 'woocommerce_order_status_failed', [ $this, 'on_order_reversed' ] );
		// A partial refund never changes the order status, so the status hooks above never fire
		// for one. Refunding a course line item out of a mixed order has to revoke that course.
		add_action( 'woocommerce_order_refunded', [ $this, 'on_order_partially_refunded' ], 10, 2 );
		add_action( 'woocommerce_checkout_order_created', [ $this, 'stamp_tenant_on_order' ] );
		add_action( 'user_register', [ $this, 'on_user_register' ] );

		// --- scheduled work ----------------------------------------------------
		add_action( Cron::HOOK_HOURLY, [ $this, 'run_hourly' ] );
		add_action( Cron::HOOK_DAILY, [ $this, 'run_daily' ] );

		// --- admin -------------------------------------------------------------
		if ( is_admin() ) {
			( new Admin\StoreDashboardPage() )->register();
			( new Admin\TenantsPage() )->register();
			( new Admin\WalletPage() )->register();
			( new Admin\PlansPage() )->register();
			( new Admin\BnplPage() )->register();
			( new Admin\AffiliatePage() )->register();
			( new Admin\LmsPage() )->register();
			( new Admin\PaymentsPage() )->register();
			( new Admin\LogisticsPage() )->register();
			( new Admin\MarketplacePage() )->register();
			( new Admin\SeoPage() )->register();
			( new Admin\GamificationPage() )->register();
			( new Admin\TranslatorPage() )->register();
			( new Admin\MasterPaymentPage() )->register();
			( new Admin\DomainPage() )->register();
			( new Admin\JobsPage() )->register();

		add_action( 'woocommerce_product_saved', [ $this, 'on_product_saved' ], 10, 2 );
		add_action( Cron::HOOK_FIVE_MINUTES, [ $this, 'marketplace_tick' ] );
		add_action( Cron::HOOK_FIVE_MINUTES, [ $this, 'webhook_tick' ] );

		// Phase 24: marketplace sync runs as a queued job — leased and retried like everything
		// else, instead of blocking the shared five-minute cron request.
		$this->register_queue_handlers( $plugin->get( 'jobs' ) );

		// Phase 29: provider payment notifications arrive in the durable inbox and are applied
		// through the shared state machine — never directly, never in the request path.
		$plugin->get( 'webhooks.inbox' )->register_source( 'bnpl', static function ( array $payload ): string {
			return igbz()->get( 'bnpl' )->apply_provider_notification( $payload );
		} );
		$plugin->get( 'webhooks.inbox' )->register_source( 'psp', static function ( array $payload ): string {
			$payment_id = (int) ( $payload['payment_id'] ?? 0 );
			$verdict    = (string) ( $payload['status'] ?? '' );
			if ( $payment_id <= 0 || '' === $verdict ) {
				return 'done'; // Malformed — acknowledge so the provider stops re-delivering.
			}
			$outcome = igbz()->get( 'payments' )->apply_notification( $payment_id, $verdict, $payload );
			if ( 'unknown' === $verdict && ! empty( $outcome['ok'] ) ) {
				return 'unknown'; // The provider could not decide yet; the inbox retries later.
			}
			return 'done';
		} );
		add_action( 'woocommerce_add_to_cart', [ $this, 'watch_cart' ], 10, 6 );
		add_action( Cron::HOOK_HOURLY, [ $this, 'abandoned_cart_tick' ] );
		add_action( Cron::HOOK_DAILY, [ $this, 'master_payment_tick' ] );
		add_action( 'woocommerce_order_status_completed', [ $this, 'hold_master_payment' ], 20, 2 );
		add_action( 'igbz_tenant_created', [ $this, 'provision_legal_pages' ], 10, 1 );
		add_action( 'woocommerce_order_status_processing', [ $this, 'hold_master_payment' ], 20, 2 );
		add_filter( 'woocommerce_coupons_enable_coupons', [ $this, 'enable_cash_discount' ] );
		add_action( 'woocommerce_order_status_completed', [ $this, 'grant_ai_credits' ], 10, 2 );
		add_action( 'woocommerce_order_status_processing', [ $this, 'grant_ai_credits' ], 10, 2 );
		}

		( new Frontend\AccountEndpoints() )->register();
		( new Frontend\ShortCodes() )->register();
		( new Frontend\TenantThemeRouter( $plugin->db() ) )->register();

		( new Lms\CertificatePage( $plugin->get( 'lms' ), $plugin->settings() ) )->register();
	}

	private function bind_services( Plugin $plugin ): void {
		$plugin->bind( 'tenants', static fn ( Plugin $c ) => new TenantRepository( $c->db() ) );
		$plugin->bind( 'tenant.offboarding', static fn ( Plugin $c ) => new \IGBZ\Suite\Support\TenantOffboarding( $c->db(), $c->logger() ) );
		$plugin->bind( 'wallet', static fn ( Plugin $c ) => new WalletService( $c->db(), $c->logger() ) );
		$plugin->bind( 'plans', static fn ( Plugin $c ) => new PlanService( $c->db(), $c->get( 'wallet' ), $c->logger() ) );
				$plugin->bind( 'logistics.courier', static fn ( Plugin $c ) => new \IGBZ\Suite\Modules\MultiTenant\Logistics\CourierService( $c->db(), $c->logger() ) );
		$plugin->bind( 'domain', static fn ( Plugin $c ) => new \IGBZ\Suite\Modules\MultiTenant\Domain\DomainService( $c->db(), $c->get( 'http' ), $c->logger() ) );
		$plugin->bind( 'legal.nid', static fn ( Plugin $c ) => new \IGBZ\Suite\Modules\MultiTenant\Otp\NationalIdVerifier( $c->db(), $c->get( 'http' ) ) );
		$plugin->bind( 'legal.waiver', static fn ( Plugin $c ) => new \IGBZ\Suite\Modules\MultiTenant\Payments\LegalWaiverService( $c->db(), $c->logger() ) );
		$plugin->bind( 'logistics.labels', static fn ( Plugin $c ) => new \IGBZ\Suite\Modules\MultiTenant\Logistics\LabelPrintingService( $c->db() ) );
		$plugin->bind( 'i18n', static fn ( Plugin $c ) => new \IGBZ\Suite\Modules\MultiTenant\Translation\I18nService( $c->db() ) );
		$plugin->bind( 'marketplace.basalam', static fn ( Plugin $c ) => new \IGBZ\Suite\Modules\MultiTenant\Marketplace\BasalamAdapter( $c->get( 'http' ) ) );
		$plugin->bind( 'webpresence', static fn ( Plugin $c ) => new \IGBZ\Suite\Modules\MultiTenant\Domain\WebPresenceService( $c->db(), $c->get( 'http' ), $c->logger() ) );
		$plugin->bind( 'master.payment', static fn ( Plugin $c ) => new \IGBZ\Suite\Modules\MultiTenant\MasterPayment\MasterPaymentService( $c->db(), $c->logger() ) );
$plugin->bind( 'logistics', static fn ( Plugin $c ) => new \IGBZ\Suite\Modules\MultiTenant\Logistics\LogisticsService( $c->db(), $c->settings(), $c->logger() ) );
		$plugin->bind( 'marketplace.sync', static fn ( Plugin $c ) => new \IGBZ\Suite\Modules\MultiTenant\Marketplace\MarketplaceSyncService( $c->db(), $c->logger() ) );
		$plugin->bind( 'marketplace.mappings', static fn ( Plugin $c ) => new \IGBZ\Suite\Modules\MultiTenant\Marketplace\CategoryMappingService( $c->db() ) );
		$plugin->bind( 'gamification', static fn ( Plugin $c ) => new \IGBZ\Suite\Modules\MultiTenant\Gamification\GamificationService( $c->db(), $c->logger() ) );
		$plugin->bind( 'gamification.carts', static fn ( Plugin $c ) => new \IGBZ\Suite\Modules\MultiTenant\Gamification\AbandonedCartService( $c->db(), $c->logger() ) );
		$plugin->bind( 'translation.adapter', static fn ( Plugin $c ) => new \IGBZ\Suite\Modules\MultiTenant\Translation\HttpTranslationAdapter( $c->get( 'http' ) ) );
		$plugin->bind( 'translation', static fn ( Plugin $c ) => new \IGBZ\Suite\Modules\MultiTenant\Translation\TranslationService( $c->get( 'translation.adapter' ), $c->logger() ) );
		$plugin->bind( 'ai.credits', static fn ( Plugin $c ) => new \IGBZ\Suite\Modules\MultiTenant\Gamification\AiCreditsService( $c->db(), $c->logger() ) );
		$plugin->bind( 'lms.vod', static fn () => new \IGBZ\Suite\Modules\MultiTenant\Lms\LmsVodService() );
		$plugin->bind(
			'bnpl.providers',
			static function ( Plugin $c ) {
				$registry = new ProviderRegistry();
				$registry->add( new HttpBnplProvider( 'snapppay', __( 'SnappPay', 'igbz-suite' ), 'bnpl.snapppay', $c->get( 'http' ) ) );
				$registry->add( new HttpBnplProvider( 'tara', __( 'Tara', 'igbz-suite' ), 'bnpl.tara', $c->get( 'http' ) ) );
				$registry->add( new HttpBnplProvider( 'digipay', __( 'Digipay', 'igbz-suite' ), 'bnpl.digipay', $c->get( 'http' ) ) );
				return $registry;
			}
		);
		$plugin->bind(
			'bnpl',
			static fn ( Plugin $c ) => new BnplService( $c->db(), $c->get( 'wallet' ), $c->logger(), $c->get( 'bnpl.providers' ) )
		);
		$plugin->bind( 'affiliate', static fn ( Plugin $c ) => new AffiliateService( $c->db(), $c->get( 'wallet' ), $c->logger() ) );
		$plugin->bind( 'lms', static fn ( Plugin $c ) => new LmsService( $c->db() ) );
		$plugin->bind(
			'payments',
			static fn ( Plugin $c ) => new PaymentService( $c->db(), $c->http(), $c->get( 'wallet' ), $c->logger() )
		);
		$plugin->bind( 'otp', static fn ( Plugin $c ) => new OtpService( $c->db(), $c->http(), $c->logger() ) );
		$plugin->bind( 'marketplace', static fn ( Plugin $c ) => new MarketplaceService( $c->db(), $c->logger() ) );
	}

	// ------------------------------------------------------------------ hooks

	public function capture_referral(): void {
		if ( is_admin() || wp_doing_cron() ) {
			return;
		}
		if ( ! igbz()->settings()->bool( 'affiliate.enabled', true ) ) {
			return;
		}
		igbz()->get( 'affiliate' )->capture_click();
	}

	/**
	 * Public marketplace feed: /?igbz_feed=torob[&tenant=12].
	 *
	 * Port note: in the nop original this lived in a controller that the compatibility document
	 * claimed did not exist; here it is a single early-init responder with no theme overhead.
	 */
	public function maybe_render_feed(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public read-only feed.
		if ( empty( $_GET['igbz_feed'] ) ) {
			return;
		}
		$channel = sanitize_key( wp_unslash( $_GET['igbz_feed'] ) );
		$tenant  = isset( $_GET['tenant'] ) ? absint( wp_unslash( $_GET['tenant'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		/** @var MarketplaceService $marketplace */
		$marketplace = igbz()->get( 'marketplace' );

		if ( ! in_array( $channel, array_keys( $marketplace->channels() ), true ) || ! $marketplace->is_channel_enabled( $channel ) ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}

		if ( $tenant > 0 ) {
			igbz()->tenancy()->force( $tenant );
		}

		$body = $marketplace->render_feed( $channel, $tenant );

		status_header( 200 );
		header( 'Content-Type: ' . $marketplace->feed_content_type( $channel ) );
		header( 'X-Robots-Tag: noindex' );
		header( 'Cache-Control: public, max-age=' . igbz()->settings()->int( 'marketplace.cache_ttl', 900 ) );
		echo $body; // phpcs:ignore WordPress.Security.EscapingOutput -- feed body is built and escaped by MarketplaceService.
		exit;
	}

	/**
	 * @param array<int,string> $gateways
	 * @return array<int,string|\WC_Payment_Gateway>
	 */
	public function register_gateways( $gateways ): array {
		$gateways   = is_array( $gateways ) ? $gateways : [];
		$settings   = igbz()->settings();

		if ( $settings->bool( 'wallet.enabled', true ) ) {
			$gateways[] = new WalletGateway();
		}
		if ( $settings->bool( 'bnpl.enabled', true ) ) {
			$gateways[] = new BnplGateway();
		}

		/**
		 * Every adapter is registered, not just the enabled ones, so each keeps its own row in
		 * WooCommerce > Settings > Payments. PspGateway::is_available() is what hides a gateway
		 * that is switched off or missing its credentials from the actual checkout.
		 */
		/** @var PaymentService $payments */
		$payments = igbz()->get( 'payments' );
		foreach ( $payments->gateways() as $adapter ) {
			$gateways[] = new PspGateway( $adapter );
		}

		return $gateways;
	}

	/** @param int $order_id */
	public function on_order_completed( $order_id ): void {
		$order_id = (int) $order_id;

		if ( igbz()->settings()->bool( 'affiliate.enabled', true ) ) {
			igbz()->get( 'affiliate' )->record_order_commission( $order_id );
		}
		if ( igbz()->settings()->bool( 'lms.enabled', true ) ) {
			igbz()->get( 'lms' )->enroll_from_order( $order_id );
		}

		$this->maybe_cashback( $order_id );
	}

	/**
	 * A refunded, cancelled or failed order: take back everything it granted.
	 *
	 * The commission was always voided here. Course access was not, so a customer could buy a
	 * course, watch it, ask for a refund and keep it — the enrollment row outlived the order that
	 * paid for it and nothing ever looked at it again.
	 *
	 * @param int $order_id
	 */
	public function on_order_reversed( $order_id ): void {
		$order_id = (int) $order_id;

		igbz()->get( 'affiliate' )->void_order_commission( $order_id );

		if ( igbz()->settings()->bool( 'lms.enabled', true ) && igbz()->settings()->bool( 'lms.revoke_on_refund', true ) ) {
			$revoked = igbz()->get( 'lms' )->revoke_from_order( $order_id );
			if ( $revoked > 0 ) {
				igbz()->logger()->info(
					'lms',
					sprintf( 'revoked %d enrollment(s) for reversed order %d', $revoked, $order_id ),
					[ 'order_id' => $order_id, 'count' => $revoked ]
				);
			}
		}
	}

	/**
	 * A partial refund: revoke only the courses whose line items were actually refunded.
	 *
	 * WooCommerce records a refund as a child order holding negative quantities, so "was this
	 * line refunded?" is `get_qty_refunded_for_item() < 0`. Refunding the shipping on an order
	 * that also contains a course must not cost the customer the course.
	 *
	 * @param int $order_id
	 * @param int $refund_id
	 */
	public function on_order_partially_refunded( $order_id, $refund_id ): void {
		$order_id = (int) $order_id;

		if ( ! igbz()->settings()->bool( 'lms.enabled', true ) || ! igbz()->settings()->bool( 'lms.revoke_on_refund', true ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// A full refund flips the status too, and that path already revokes everything; letting
		// both run would double-log the same revocation.
		if ( $order->has_status( [ 'refunded', 'cancelled', 'failed' ] ) ) {
			return;
		}

		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return;
		}

		/** @var LmsService $lms */
		$lms = igbz()->get( 'lms' );

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			if ( (float) $order->get_qty_refunded_for_item( (int) $item_id ) >= 0 ) {
				continue;
			}

			$course = $lms->course_by_product( $item->get_product_id() );
			if ( ! $course ) {
				continue;
			}

			$enrollment = $lms->enrollment( (int) $course['id'], $user_id );
			// Only the access this order granted; a second purchase or a manual enrollment stands.
			if ( ! $enrollment || (int) $enrollment['order_id'] !== $order_id ) {
				continue;
			}

			$lms->unenroll( (int) $course['id'], $user_id );
			igbz()->logger()->info(
				'lms',
				sprintf( 'revoked course %d for user %d after a partial refund on order %d', (int) $course['id'], $user_id, $order_id ),
				[ 'order_id' => $order_id, 'refund_id' => (int) $refund_id, 'course_id' => (int) $course['id'], 'user_id' => $user_id ]
			);
		}
	}

	private function maybe_cashback( int $order_id ): void {
		$percent = (float) igbz()->settings()->get( 'wallet.order_cashback_percent', 0 );
		if ( $percent <= 0 ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || (int) $order->get_customer_id() <= 0 ) {
			return;
		}

		$amount = round( (float) $order->get_total() * $percent / 100, 2 );
		if ( $amount <= 0 ) {
			return;
		}

		igbz()->get( 'wallet' )->credit(
			(int) $order->get_customer_id(),
			$amount,
			WalletService::REASON_CASHBACK,
			'cashback:' . $order_id,
			[ 'percent' => $percent ],
			(int) $order->get_meta( '_igbz_tenant_id' ),
			$order_id,
			__( 'Purchase cashback', 'igbz-suite' )
		);
	}

	/**
	 * Scope WooCommerce catalog queries before SQL is generated. A product with no tenant marker
	 * is never exposed inside a tenant store; this prevents the shared platform catalogue leaking
	 * into a merchant's storefront.
	 *
	 * @param array<int,array<string,mixed>> $meta_query
	 * @param mixed $query
	 * @return array<int,array<string,mixed>>
	 */
	public function scope_product_query( array $meta_query, $query = null ): array {
		$tenant_id = (int) igbz()->tenancy()->id();
		if ( $tenant_id > 0 ) {
			$meta_query[] = [ 'key' => '_igbz_tenant_id', 'value' => $tenant_id, 'compare' => '=' ];
		}
		return $meta_query;
	}

	/** Scope WC_Order_Query for store owners, including HPOS-compatible queries. */
	public function scope_order_query( array $args ): array {
		$tenant_id = (int) igbz()->tenancy()->id();
		if ( $tenant_id > 0 && ! current_user_can( Capabilities::MANAGE_TENANTS ) ) {
			$args['meta_query'] = array_merge( (array) ( $args['meta_query'] ?? [] ), [ [ 'key' => '_igbz_tenant_id', 'value' => $tenant_id, 'compare' => '=' ] ] );
		}
		return $args;
	}

	/** Scope legacy WordPress admin product/order lists before SQL is built. */
	public function scope_admin_queries( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || current_user_can( Capabilities::MANAGE_TENANTS ) ) { return; }
		if ( ! current_user_can( Capabilities::MANAGE_OWN_TENANT ) ) { return; }
		$tenant_id = (int) igbz()->tenancy()->id();
		$post_type = $query->get( 'post_type' );
		if ( $tenant_id <= 0 || ! in_array( $post_type, [ 'product', 'shop_order' ], true ) ) { return; }
		$meta_query = (array) $query->get( 'meta_query' );
		$meta_query[] = [ 'key' => '_igbz_tenant_id', 'value' => $tenant_id, 'compare' => '=' ];
		$query->set( 'meta_query', $meta_query );
	}

	/** Stamp products created by a tenant owner at the data boundary. */
	public function stamp_new_product( int $product_id, $product = null ): void {
		$tenant_id = (int) igbz()->tenancy()->id();
		if ( $tenant_id > 0 && $product_id > 0 ) {
			update_post_meta( $product_id, '_igbz_tenant_id', $tenant_id );
		}
	}

	/** @param \WC_Order $order */
	public function stamp_tenant_on_order( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$tenant_id = igbz()->tenancy()->id();
		if ( $tenant_id > 0 ) {
			$order->update_meta_data( '_igbz_tenant_id', $tenant_id );
		}
		$code = igbz()->get( 'affiliate' )->cookie_code();
		if ( '' !== $code ) {
			$order->update_meta_data( '_igbz_ref_code', $code );
		}
		$order->save();
	}

	/** @param int $user_id */
	public function on_user_register( $user_id ): void {
		igbz()->get( 'affiliate' )->attach_referral_to_user( (int) $user_id );
	}

	public function run_hourly(): void {
		$this->fan_out_hourly_sweeps();
	}

	/**
	 * Phase 25: the hourly sweeps run as one job per active tenant instead of one global scan
	 * with a LIMIT. The hourly slot key absorbs duplicate beats, `group_key` gives each tenant
	 * a fair round-robin share of the claim budget, and every handler re-queues a next round
	 * while its batch comes back full — capped, so a pathological backlog cannot loop forever.
	 */
	private function fan_out_hourly_sweeps(): void {
		$ids = $this->active_tenant_ids();
		if ( ! $ids ) {
			return;
		}
		$jobs = igbz()->get( 'jobs' );
		foreach ( [ 'bnpl.overdue', 'bnpl.reminders', 'carts.sweep' ] as $job_type ) {
			$jobs->fan_out_tenants( $job_type, $ids, [ 'slot' => JobQueue::slot( HOUR_IN_SECONDS ) ] );
		}
	}

	/** Active tenants (active status or a trial that has not ended). @return array<int,int> */
	private function active_tenant_ids(): array {
		$tenants = igbz()->get( 'tenants' )->all( [ 'limit' => 500 ] );
		$ids     = [];
		foreach ( $tenants as $tenant ) {
			if ( $tenant->is_active() ) {
				$ids[] = $tenant->id;
			}
		}
		return $ids;
	}

	public function run_daily(): void {
		// Phase 26: the daily set runs as independent queued jobs; the daily slot key absorbs
		// duplicate beats. Bounded services carry the continuation contract inside the handler.
		$jobs = igbz()->get( 'jobs' );
		$slot = JobQueue::slot( DAY_IN_SECONDS );
		foreach ( [ 'plans.renewals', 'affiliate.commissions', 'marketplace.flush', 'master.release', 'wallet.reconcile', 'master.reconcile', 'bnpl.reconcile' ] as $job_type ) {
			$jobs->enqueue( $job_type, [], [ 'idempotency_key' => $slot ] );
		}
	}

	// ----------------------------------------------------------------- health

	/** @return array<int,array{label:string,status:string,detail:string}> */
	public function health(): array {
		$settings = igbz()->settings();
		$rows     = [];

		$rows[] = [
			'label'  => __( 'WooCommerce', 'igbz-suite' ),
			'status' => igbz()->woocommerce_active() ? 'ok' : 'error',
			'detail' => igbz()->woocommerce_active()
				? sprintf( /* translators: %s: version */ __( 'Active (%s)', 'igbz-suite' ), defined( 'WC_VERSION' ) ? WC_VERSION : '?' )
				: __( 'WooCommerce is not active.', 'igbz-suite' ),
		];

		$tenants = igbz()->get( 'tenants' )->count();
		$rows[]  = [
			'label'  => __( 'Tenants', 'igbz-suite' ),
			'status' => $tenants > 0 ? 'ok' : 'warn',
			'detail' => sprintf( /* translators: %d: count */ _n( '%d tenant configured', '%d tenants configured', $tenants, 'igbz-suite' ), $tenants ),
		];

		/** @var PaymentService $payments */
		$payments  = igbz()->get( 'payments' );
		$ready     = $payments->enabled_gateways();
		$rows[]    = [
			'label'  => __( 'Payment gateways', 'igbz-suite' ),
			'status' => $ready ? 'ok' : 'warn',
			'detail' => $ready
				? implode( ', ', array_map( static fn ( $g ) => $g->title(), $ready ) )
				: __( 'No PSP credentials configured yet.', 'igbz-suite' ),
		];

		$secret = $settings->string( 'lms.video_hmac_secret', '' );
		$rows[] = [
			'label'  => __( 'Signed video links', 'igbz-suite' ),
			'status' => '' !== $secret ? 'ok' : 'error',
			'detail' => '' !== $secret
				? __( 'HMAC secret present.', 'igbz-suite' )
				: __( 'lms.video_hmac_secret is empty; video URLs cannot be signed.', 'igbz-suite' ),
		];

		$rows[] = [
			'label'  => __( 'SMS provider', 'igbz-suite' ),
			'status' => 'log' === $settings->string( 'otp.sms_provider', 'log' ) ? 'warn' : 'ok',
			'detail' => sprintf(
				/* translators: %s: provider id */
				__( 'Current provider: %s', 'igbz-suite' ),
				$settings->string( 'otp.sms_provider', 'log' )
			),
		];

		return $rows;
	}

	/** Enqueue marketplace sync rows when a product is saved. */
	public function on_product_saved( int $product_id, $product = null ): void {
		if ( ! igbz()->settings()->bool( 'marketplace.enabled', true ) ) {
			return;
		}
		$sync = igbz()->get( 'marketplace.sync' );
		$sync->enqueue( $product_id, 'digikala' );
		$sync->enqueue( $product_id, 'divar' );
	}

	/** Phase 29: drain the webhook inbox on the five-minute cron. */
	public function webhook_tick(): void {
		igbz()->get( 'jobs' )->enqueue( 'webhooks.drain', [], [ 'idempotency_key' => JobQueue::slot() ] );
	}

	/** Drain the marketplace queue on the five-minute cron. */
	public function marketplace_tick(): void {
		// Phase 24: the beat only enqueues; the enabled-check happens at run time inside the
		// handler so a late settings change is still respected. Slot key absorbs duplicate beats.
		igbz()->get( 'jobs' )->enqueue( 'marketplace.sync', [], [ 'idempotency_key' => JobQueue::slot() ] );
	}

	/** Phase 24/25: handler wiring for the queued jobs owned by this module. */
	public function register_queue_handlers( JobQueue $jobs ): void {
		$jobs->register( 'marketplace.sync', static function (): void {
			if ( ! igbz()->settings()->bool( 'marketplace.enabled', true ) ) {
				return;
			}
			igbz()->get( 'marketplace.sync' )->process_pending();
		} );

		// Phase 25 — the tenant-scoped hourly sweeps. Each handler stays within its capped
		// batch and applies the continuation contract: a full batch means more rows may wait,
		// so the next round re-queues itself under a derived idempotency key.
		$jobs->register( 'bnpl.overdue', function ( array $payload, JobContext $ctx ) use ( $jobs ): void {
			$processed = igbz()->get( 'bnpl' )->process_overdue( $ctx->tenant_id );
			$this->continue_sweep( $jobs, 'bnpl.overdue', $ctx, $payload, $processed, self::SWEEP_BATCH_BNPL );
		} );
		$jobs->register( 'bnpl.reminders', function ( array $payload, JobContext $ctx ) use ( $jobs ): void {
			$processed = igbz()->get( 'bnpl' )->send_reminders( $ctx->tenant_id );
			$this->continue_sweep( $jobs, 'bnpl.reminders', $ctx, $payload, $processed, self::SWEEP_BATCH_BNPL );
		} );
		$jobs->register( 'carts.sweep', function ( array $payload, JobContext $ctx ) use ( $jobs ): void {
			// Settings are consulted at run time (phase 24 pattern), not at enqueue time.
			if ( ! igbz()->settings()->bool( 'abandoned_cart.enabled', true ) ) {
				return;
			}
			$processed = $this->carts()->sweep( $ctx->tenant_id );
			$this->continue_sweep( $jobs, 'carts.sweep', $ctx, $payload, $processed, self::SWEEP_BATCH_CARTS );
		} );

		// Phase 26 — the daily set. Bounded sweeps continue via the queue's canonical contract.
		$jobs->register( 'plans.renewals', function ( array $payload, JobContext $ctx ) use ( $jobs ): void {
			$plans     = igbz()->get( 'plans' );
			$processed = $plans->process_due_renewals();
			// Phase 32: the grace sweep rides the same daily job; a full batch on either side
			// continues the round so nothing waits another day.
			$processed += $plans->expire_past_grace( self::DAILY_BATCH_RENEWALS );
			$jobs->continue_round( $ctx, $payload, 'plans.renewals', $processed, self::DAILY_BATCH_RENEWALS, self::MAX_SWEEP_ROUNDS );
		} );
		$jobs->register( 'affiliate.commissions', function ( array $payload, JobContext $ctx ) use ( $jobs ): void {
			$processed = igbz()->get( 'affiliate' )->process_pending_commissions();
			$jobs->continue_round( $ctx, $payload, 'affiliate.commissions', $processed, self::DAILY_BATCH_COMMISSIONS, self::MAX_SWEEP_ROUNDS );
		} );
		$jobs->register( 'marketplace.flush', static function (): void {
			igbz()->get( 'marketplace' )->flush_cache();
		} );
		$jobs->register( 'master.release', function ( array $payload, JobContext $ctx ) use ( $jobs ): void {
			if ( ! igbz()->settings()->bool( 'master_payment.enabled', true ) || ! igbz()->has( 'master.payment' ) ) {
				return;
			}
			$processed = igbz()->get( 'master.payment' )->release_due();
			$jobs->continue_round( $ctx, $payload, 'master.release', $processed, self::DAILY_BATCH_MASTER, self::MAX_SWEEP_ROUNDS );
		} );
		$jobs->register( 'wallet.reconcile', static function (): void {
			// Phase 28: the ledger is the source of truth; any cached-balance drift is repaired.
			igbz()->get( 'wallet' )->reconcile_all();
		} );
		$jobs->register( 'master.reconcile', static function (): void {
			// Phase 31: released escrow must have its wallet credit; gaps are repaired and reported.
			igbz()->get( 'master.payment' )->reconcile();
		} );
		$jobs->register( 'bnpl.reconcile', static function (): void {
			// Phase 33: instalments must add up against their contracts; drift is reported, not hidden.
			igbz()->get( 'bnpl' )->reconcile();
		} );
		$jobs->register( 'webhooks.drain', function ( array $payload, JobContext $ctx ) use ( $jobs ): void {
			// Phase 29: one batch per round; a full batch re-queues the next round.
			$inbox     = igbz()->get( 'webhooks.inbox' );
			$totals    = $inbox->process_batch( self::WEBHOOK_BATCH );
			$processed = $totals['done'] + $totals['unknown'] + $totals['failed'] + $totals['dead'];
			$jobs->continue_round( $ctx, $payload, 'webhooks.drain', $processed, self::WEBHOOK_BATCH, self::MAX_SWEEP_ROUNDS );
		} );
	}

	/**
	 * Phase 25 continuation contract for capped sweeps — delegates to the queue's canonical
	 * contract (phase 26): a full batch enqueues the next round under a derived key, bounded
	 * by the round cap (batch × rounds rows per tenant per window, worst case).
	 *
	 * @param array<string,mixed> $payload
	 */
	private function continue_sweep( JobQueue $jobs, string $job_type, JobContext $ctx, array $payload, int $processed, int $batch ): void {
		$jobs->continue_round( $ctx, $payload, $job_type, $processed, $batch, self::MAX_SWEEP_ROUNDS );
	}

	/** Track a cart for abandoned-cart recovery. */
	public function watch_cart( $cart_item_key = '', $product_id = 0, $quantity = 1, $variation_id = 0, $variation = null, $cart_item_data = null ): void {
		if ( ! igbz()->settings()->bool( 'abandoned_cart.enabled', true ) || ! function_exists( 'WC' ) || null === WC()->session ) {
			return;
		}
		$total = (float) WC()->cart->get_total( 'edit' );
		$this->carts()->watch( get_current_user_id(), (string) WC()->session->get_customer_id(), $total );
	}

	/**
	 * Sweep abandoned carts hourly. Phase 25: one `carts.sweep` job per active tenant — the
	 * enabled-check moved to run time inside the handler. Duplicate fan-outs (run_hourly hooks
	 * this sweep too) are absorbed by the shared hourly slot key.
	 */
	public function abandoned_cart_tick(): void {
		$ids = $this->active_tenant_ids();
		if ( $ids ) {
			igbz()->get( 'jobs' )->fan_out_tenants( 'carts.sweep', $ids, [ 'slot' => JobQueue::slot( HOUR_IN_SECONDS ) ] );
		}
	}

	private function carts(): \IGBZ\Suite\Modules\MultiTenant\Gamification\AbandonedCartService {
		return igbz()->get( 'gamification.carts' );
	}

	/** Grant AI-studio credits to the buyer when an order is paid. */
	public function grant_ai_credits( int $order_id, $order = null ): void {
		if ( ! igbz()->settings()->bool( 'ai_credits.enabled', true ) || ! igbz()->has( 'ai.credits' ) ) {
			return;
		}
		if ( null === $order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}
		$user_id = (int) $order->get_user_id();
		if ( $user_id <= 0 ) {
			return;
		}
		igbz()->get( 'ai.credits' )->grant_from_order( $order_id, $user_id, (float) $order->get_total() );
	}

	/** Release due master payments daily. */
	public function master_payment_tick(): void {
		// Phase 26: same daily slot key as run_daily() — whichever fires first enqueues, the
		// other is a no-op. The enabled-check moved to run time inside the handler.
		igbz()->get( 'jobs' )->enqueue( 'master.release', [], [ 'idempotency_key' => JobQueue::slot( DAY_IN_SECONDS ) ] );
	}

	/** Hold a paid order's funds in the master gateway (when enabled + agreed). */
	public function hold_master_payment( int $order_id, $order = null ): void {
		if ( ! igbz()->settings()->bool( 'master_payment.enabled', true ) || ! igbz()->has( 'master.payment' ) ) {
			return;
		}
		if ( null === $order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}
		$tenant = (int) igbz()->tenancy()->id();
		$master = igbz()->get( 'master.payment' );
		if ( ! $master->has_agreement( $tenant ) ) {
			return; // no agreement -> no escrow
		}
		$master->hold( $tenant, $order_id, (float) $order->get_total(), 'IRT', (string) $order->get_transaction_id(), 'rial' );
	}

	/**
	 * Cash discount (legal pricing rule, phase 6): when BNPL is enabled and
	 * the buyer pays without instalments, they get an n% cash discount off
	 * the base price (installment price stays the base price).
	 */
	public function apply_cash_discount( $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		$percent = (float) igbz()->settings()->float( 'bnpl.cash_discount_percent', 0 );
		if ( $percent <= 0 ) {
			return;
		}
		$chosen = WC()->session ? (string) WC()->session->get( 'chosen_payment_method' ) : '';
		if ( 'igbz_bnpl' === $chosen ) {
			return; // instalments: no discount
		}
		$subtotal = (float) WC()->cart->get_subtotal();
		WC()->cart->add_fee( __( 'Cash discount', 'igbz-suite' ), -1 * ( $subtotal * $percent / 100 ) );
	}

	/** Default pages (terms, conditions, privacy) for every new store. */
	public function provision_legal_pages( int $tenant_id ): void {
		$pages = [
			'terms'    => __( 'Terms & conditions', 'igbz-suite' ),
			'conditions' => __( 'Conditions of use', 'igbz-suite' ),
			'privacy'  => __( 'Privacy policy', 'igbz-suite' ),
		];
		foreach ( $pages as $slug => $title ) {
			$existing = get_page_by_path( $slug );
			if ( $existing ) {
				continue;
			}
			wp_insert_post(
				[
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_name'    => $slug,
					'post_content' => '<h1>' . esc_html( $title ) . '</h1><p>' . esc_html__( 'This store is operated under the IGBZ platform. Replace this default text with your own terms before going live.', 'igbz-suite' ) . '</p>',
				]
			);
		}
	}

	/** Save real SEO meta onto the product (fixes the nop gap). */
	public function save_product_seo_meta( int $product_id, string $title, string $description ): void {
		update_post_meta( $product_id, 'igbz_seo_title', sanitize_text_field( $title ) );
		update_post_meta( $product_id, 'igbz_seo_description', sanitize_textarea_field( $description ) );
	}
}
