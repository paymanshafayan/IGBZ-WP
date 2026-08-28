<?php
namespace IGBZ\Suite\Modules\Hub;

use IGBZ\Suite\Modules\Hub\Rest\Cors;
use IGBZ\Suite\Modules\Hub\Rest\HubController;
use IGBZ\Suite\Modules\Hub\Services\ContentBlockService;
use IGBZ\Suite\Modules\Hub\Services\DirectoryService;
use IGBZ\Suite\Modules\Hub\Services\DomainVerifier;
use IGBZ\Suite\Modules\Hub\Services\HubStats;
use IGBZ\Suite\Modules\Hub\Services\SignupService;
use IGBZ\Suite\Modules\Hub\Services\VipLinkService;
use IGBZ\Suite\Modules\MultiTenant\Payments\PaymentService;
use IGBZ\Suite\Modules\MultiTenant\Plans\PlanService;
use IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService;
use IGBZ\Suite\Support\Cron;
use IGBZ\Suite\Support\Jobs\JobQueue;
use IGBZ\Suite\Support\ModuleInterface;
use IGBZ\Suite\Support\Modules;
use IGBZ\Suite\Support\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Port of the nopCommerce "Misc.MasterSiteHub" plugin.
 *
 * The mother site is deliberately a separate front end (the original project split it off so it
 * could be hosted independently). This module is everything that front end needs: real platform
 * aggregates, a store directory, editable marketing blocks, self-service provisioning, custom
 * domain verification and signed VIP hand-off links.
 *
 * Fixes carried over from the audit:
 *  - no invented metrics; MRR joins subscriptions to plans and order totals come from WooCommerce;
 *  - signup actually creates the owner user and consumes the chosen plan;
 *  - domain SSL/CNAME verification performs a real DNS lookup;
 *  - CORS is restricted to the configured mother origin (the original used AllowAnyOrigin);
 *  - the hub reads tenant data through the MultiTenant repositories instead of touching tables
 *    that belong to another plugin.
 */
final class HubModule implements ModuleInterface {

	public function id(): string {
		return Modules::HUB;
	}

	public function title(): string {
		return __( 'Master site hub', 'igbz-suite' );
	}

	public function description(): string {
		return __( 'Cross-store aggregates, the public store directory, landing blocks, self-service store creation, custom domain verification and signed VIP links.', 'igbz-suite' );
	}

	public function register( Plugin $plugin ): void {
		$this->bind_services( $plugin );

		( new Cors() )->register();

		( new HubController(
			$plugin->get( 'hub.stats' ),
			$plugin->get( 'hub.directory' ),
			$plugin->get( 'hub.signup' ),
			$plugin->get( 'hub.vip' ),
			$plugin->get( 'hub.domains' ),
			$plugin->get( 'hub.blocks' ),
			$plugin->tenancy()->repository(),
			$this->plans( $plugin )
		) )->register();

		// VIP hand-off links land on the storefront.
		add_action( 'init', [ $this, 'handle_vip_link' ], 4 );

		// Aggregates and DNS re-checks.
		add_action( Cron::HOOK_HOURLY, [ $this, 'run_hourly' ] );

		// Phase 25: the hourly tick runs as a queued job — leased and retried like everything
		// else, instead of blocking the shared hourly cron request.
		$this->register_queue_handlers( $plugin->get( 'jobs' ) );

		// Anything that changes the directory invalidates its cache.
		foreach ( [ 'igbz_tenant_created', 'igbz_tenant_updated', 'igbz_tenant_deleted', 'igbz_subscription_started' ] as $hook ) {
			add_action( $hook, [ $this, 'flush_caches' ] );
		}

		( new Frontend\HubShortCodes() )->register();

		if ( is_admin() ) {
			( new Admin\HubPage() )->register();
		}
	}

	private function bind_services( Plugin $plugin ): void {
		$plugin->bind( 'hub.stats', static fn ( Plugin $c ) => new HubStats( $c->db() ) );
		$plugin->bind(
			'hub.directory',
			static fn ( Plugin $c ) => new DirectoryService( $c->db(), $c->tenancy()->repository() )
		);
		$plugin->bind( 'hub.vip', static fn ( Plugin $c ) => new VipLinkService( $c->db(), $c->logger() ) );
		$plugin->bind(
			'hub.domains',
			static fn ( Plugin $c ) => new DomainVerifier( $c->db(), $c->tenancy()->repository(), $c->logger() )
		);
		$plugin->bind( 'hub.blocks', static fn () => new ContentBlockService() );
		$plugin->bind(
			'hub.signup',
			fn ( Plugin $c ) => new SignupService(
				$c->tenancy()->repository(),
				$this->plans( $c ),
				$this->payments( $c ),
				$c->logger()
			)
		);
	}

	/** The Hub can run with the MultiTenant module switched off, so build a fallback instance. */
	private function plans( Plugin $plugin ): PlanService {
		if ( $plugin->has( 'plans' ) ) {
			return $plugin->get( 'plans' );
		}
		return new PlanService( $plugin->db(), new WalletService( $plugin->db(), $plugin->logger() ), $plugin->logger() );
	}

	private function payments( Plugin $plugin ): PaymentService {
		if ( $plugin->has( 'payments' ) ) {
			return $plugin->get( 'payments' );
		}
		return new PaymentService(
			$plugin->db(),
			$plugin->http(),
			new WalletService( $plugin->db(), $plugin->logger() ),
			$plugin->logger()
		);
	}

	// ------------------------------------------------------------- runtime

	public function handle_vip_link(): void {
		/** @var VipLinkService $vip */
		$vip = igbz()->get( 'hub.vip' );
		$vip->handle_request();
	}

	public function run_hourly(): void {
		// Phase 25: the beat only enqueues; the interval gate and the enabled-check stay at run
		// time inside the handler. The hourly slot key absorbs duplicate beats.
		igbz()->get( 'jobs' )->enqueue( 'hub.tick', [], [ 'idempotency_key' => JobQueue::slot( HOUR_IN_SECONDS ) ] );
	}

	/** Phase 25: handler wiring for the queued hub tick. */
	public function register_queue_handlers( JobQueue $jobs ): void {
		$jobs->register( 'hub.tick', static function (): void {
			$settings = igbz()->settings();
			if ( ! $settings->bool( 'hub.enabled', true ) ) {
				return;
			}

			$interval = max( 300, $settings->int( 'hub.sync_interval', 3600 ) );
			$last     = (int) get_option( 'igbz_hub_last_sync', 0 );

			if ( time() - $last >= $interval ) {
				igbz()->get( 'hub.stats' )->summary( true );
				igbz()->get( 'hub.directory' )->featured( 0, true );
				update_option( 'igbz_hub_last_sync', time(), false );
			}

			igbz()->get( 'hub.domains' )->recheck_pending();
		} );
	}

	public function flush_caches(): void {
		igbz()->get( 'hub.stats' )->flush();
		igbz()->get( 'hub.directory' )->flush();
	}

	// -------------------------------------------------------------- health

	/** @return array<int,array{label:string,status:string,detail:string}> */
	public function health(): array {
		$settings = igbz()->settings();
		$rows     = [];

		$rows[] = [
			'label'  => __( 'Hub API', 'igbz-suite' ),
			'status' => $settings->bool( 'hub.enabled', true ) ? 'ok' : 'warn',
			'detail' => sprintf(
				/* translators: %s: REST base URL */
				__( 'Base URL: %s', 'igbz-suite' ),
				rest_url( HubController::NAMESPACE )
			),
		];

		$origins = Cors::allowed_origins();
		$rows[]  = [
			'label'  => __( 'CORS origins', 'igbz-suite' ),
			'status' => $origins ? 'ok' : 'warn',
			'detail' => $origins
				? implode( ', ', $origins )
				: __( 'No mother origin configured: the hub answers same-origin requests only. A wildcard is never issued.', 'igbz-suite' ),
		];

		$secret = $settings->string( 'hub.vip_link_secret', '' );
		$rows[] = [
			'label'  => __( 'VIP link signing', 'igbz-suite' ),
			'status' => '' !== $secret ? 'ok' : 'error',
			'detail' => '' !== $secret
				? sprintf(
					/* translators: %d: seconds */
					__( 'Secret present. Tickets expire after %d seconds and are single use.', 'igbz-suite' ),
					igbz()->get( 'hub.vip' )->ttl()
				)
				: __( 'hub.vip_link_secret is empty; hand-off links cannot be signed.', 'igbz-suite' ),
		];

		$db      = igbz()->db();
		$pending = (int) $db->scalar( 'SELECT COUNT(*) FROM ' . $db->table( 'tenant_domains' ) . ' WHERE verified_at IS NULL' );
		$rows[]  = [
			'label'  => __( 'Custom domains', 'igbz-suite' ),
			'status' => $pending > 0 ? 'warn' : 'ok',
			'detail' => sprintf(
				/* translators: 1: pending count, 2: expected CNAME target */
				__( '%1$d domain(s) waiting on DNS. Expected CNAME target: %2$s', 'igbz-suite' ),
				$pending,
				igbz()->get( 'hub.domains' )->expected_cname()
			),
		];

		$rows[] = [
			'label'  => __( 'Self sign-up', 'igbz-suite' ),
			'status' => $settings->bool( 'general.allow_self_signup', false ) ? 'ok' : 'warn',
			'detail' => $settings->bool( 'general.allow_self_signup', false )
				? __( 'POST /signup creates the owner user, the store and the subscription.', 'igbz-suite' )
				: __( 'Disabled: POST /signup answers 403. Turn it on under Settings → General.', 'igbz-suite' ),
		];

		return $rows;
	}
}
