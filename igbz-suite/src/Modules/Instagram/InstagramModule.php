<?php
namespace IGBZ\Suite\Modules\Instagram;

use IGBZ\Suite\Modules\Instagram\Gateways\ZernioAdapterInterface;
use IGBZ\Suite\Modules\Instagram\Gateways\ZernioClient;
use IGBZ\Suite\Modules\Instagram\Services\InboxService;
use IGBZ\Suite\Modules\Instagram\Services\SocialMigrationService;
use IGBZ\Suite\Modules\Instagram\Services\TranslationBridge;
use IGBZ\Suite\Modules\Instagram\Services\SkuGenerator;
use IGBZ\Suite\Modules\Instagram\Services\ZernioConnectionService;
use IGBZ\Suite\Modules\Instagram\Services\ZernioSocialService;
use IGBZ\Suite\Modules\Instagram\Vip\VipAccessService;
use IGBZ\Suite\Modules\Instagram\Vip\VipBillingService;
use IGBZ\Suite\Modules\Instagram\Vip\VipLandingPage;
use IGBZ\Suite\Modules\Instagram\Vip\VipMediaService;
use IGBZ\Suite\Modules\Instagram\Vip\VipMessageService;
use IGBZ\Suite\Modules\Instagram\Vip\VipPostService;
use IGBZ\Suite\Modules\Instagram\Vip\VipSocialService;
use IGBZ\Suite\Support\Cron;
use IGBZ\Suite\Support\Jobs\JobContext;
use IGBZ\Suite\Support\Jobs\JobQueue;
use IGBZ\Suite\Support\ModuleInterface;
use IGBZ\Suite\Support\Modules;
use IGBZ\Suite\Support\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Instagram commerce on the single social provider (phase 50, ADR-0004).
 *
 * The historical third-party social stack is gone: the only social
 * gateway is Zernio, reached exclusively through ZernioSocialService (guard →
 * backend mapping → profile-scoped key). What this module binds now:
 *
 *   - the Zernio adapter + connection service (profile, keys, OAuth, webhook
 *     identity) and the tenant-facing social facade;
 *   - the controlled legacy→Zernio migration (journal + hourly round);
 *   - the building blocks the rebuilt flows land on (phase 51 inbox/DM,
 *     52 product registration, 53 publishing/voice, 55 giveaway/insights);
 *   - the VIP channel (phase 54) and the config-driven AI media studio.
 */
final class InstagramModule implements ModuleInterface {

	/** Phase 50: migration round budget (continuation contract, phase 25 pattern). */
	private const MIGRATION_ROUND_LIMIT = SocialMigrationService::ROUND_LIMIT;

	public function id(): string {
		return Modules::INSTAGRAM;
	}

	public function title(): string {
		return __( 'Instagram commerce', 'igbz-suite' );
	}

	public function description(): string {
		return __( 'Single social provider (Zernio): profile per store, official OAuth, publishing, inbox and analytics — plus the VIP channel and AI media studio.', 'igbz-suite' );
	}

	public function register( Plugin $plugin ): void {
		$this->bind_services( $plugin );

		// The VIP channel: private media delivery, the public share page and payment settlement.
		// All three are request-time listeners, so they are registered whether or not the VIP
		// feature is switched on — each checks `vip.enabled` itself, and a payment that was
		// already started must still settle if the channel is turned off mid-checkout.
		/** @var VipMediaService $vip_media */
		$vip_media = $plugin->get( 'vip.media' );
		add_action( 'template_redirect', [ $vip_media, 'handle_request' ], 5 );

		( new VipLandingPage(
			$plugin->get( 'vip.posts' ),
			$plugin->get( 'vip.access' ),
			$plugin->get( 'vip.billing' ),
			$plugin->settings()
		) )->register();

		/** @var VipBillingService $vip_billing */
		$vip_billing = $plugin->get( 'vip.billing' );
		$vip_billing->register();

		add_action( Cron::HOOK_FIVE_MINUTES, [ $this, 'run_five_minutes' ] );
		add_action( Cron::HOOK_HOURLY, [ $this, 'run_hourly' ] );

		// Phase 24/25: the sweeps run as independent queued jobs — leased, retried
		// with backoff, dead-lettered when broken — instead of one long blocking cron request.
		$this->register_queue_handlers( $plugin->get( 'jobs' ) );

		if ( is_admin() ) {
			( new Admin\AccountsPage() )->register();
			( new Admin\VipPage() )->register();
			( new Admin\AiStudioPage() )->register();
		}
	}

	private function bind_services( Plugin $plugin ): void {
		// ---------------------------------------------- the social provider

		$plugin->bind( 'ig.zernio_client', static fn ( Plugin $c ) => new ZernioClient( $c->http(), $c->logger() ) );
		$plugin->bind(
			'ig.zernio',
			static fn ( Plugin $c ) => new ZernioConnectionService( $c->db(), $c->logger(), $c->get( 'ig.zernio_client' ) )
		);
		$plugin->bind(
			'ig.zernio_social',
			static fn ( Plugin $c ) => new ZernioSocialService(
				$c->db(),
				$c->logger(),
				$c->get( 'ig.zernio' ),
				$c->get( 'ig.zernio_client' )
			)
		);
		$plugin->bind(
			'ig.social_migration',
			static fn ( Plugin $c ) => new SocialMigrationService( $c->db(), $c->logger(), $c->get( 'ig.zernio' ) )
		);
		$plugin->bind(
			'ig.inbox',
			static fn ( Plugin $c ) => new InboxService(
				$c->db(),
				$c->logger(),
				$c->get( 'ig.zernio' ),
				$c->get( 'ig.zernio_client' ),
				$c->settings()
			)
		);

		// --------------------------- building blocks for the rebuilt flows

		// Product registration (phase 52) and listing translation keep their
		// provider-neutral machinery; the flow itself is rebuilt on Zernio.
		$plugin->bind( 'ig.skus', static fn ( Plugin $c ) => new SkuGenerator( $c->db() ) );
		$plugin->bind( 'ig.translations', static fn ( Plugin $c ) => new TranslationBridge( $c->logger() ) );

		// ------------------------------------------------------ VIP channel

		$plugin->bind( 'vip.access', static fn ( Plugin $c ) => new VipAccessService( $c->db() ) );
		$plugin->bind( 'ai.studio', static fn ( Plugin $c ) => new \IGBZ\Suite\Modules\Instagram\AiStudio\AiStudioService( new \IGBZ\Suite\Modules\Instagram\AiStudio\HttpAiStudioProvider( $c->get( 'http' ) ), $c->logger() ) );
		$plugin->bind(
			'vip.media',
			static fn ( Plugin $c ) => new VipMediaService( $c->db(), $c->settings(), $c->logger() )
		);
		$plugin->bind(
			'vip.posts',
			static fn ( Plugin $c ) => new VipPostService(
				$c->db(),
				$c->settings(),
				$c->logger(),
				$c->get( 'vip.access' ),
				$c->get( 'vip.media' )
			)
		);
		$plugin->bind(
			'vip.social',
			static fn ( Plugin $c ) => new VipSocialService( $c->db(), $c->settings(), $c->get( 'vip.access' ) )
		);
		$plugin->bind( 'vip.messages', static fn ( Plugin $c ) => new VipMessageService( $c->db(), $c->settings() ) );
		$plugin->bind(
			'vip.billing',
			static fn ( Plugin $c ) => new VipBillingService(
				$c->db(),
				$c->settings(),
				$c->logger(),
				$c->get( 'vip.access' )
			)
		);
	}

	// ------------------------------------------------------------------ cron

	public function run_five_minutes(): void {
		// Phase 24: this beat only enqueues; the queue runner drains in the same beat with
		// leases, retries and dead letters. The slot key absorbs WP-Cron's duplicate beats —
		// the second delivery of the same five-minute window is a no-op.
		$jobs = igbz()->get( 'jobs' );
		$slot = JobQueue::slot();
		foreach ( [ 'ig.vip.publish_due', 'ig.vip.expire_due' ] as $job_type ) {
			$jobs->enqueue( $job_type, [], [ 'idempotency_key' => $slot ] );
		}
	}

	/** Phase 24/50: handler wiring for the queued sweeps. */
	public function register_queue_handlers( JobQueue $jobs ): void {
		$jobs->register( 'ig.vip.publish_due', static function (): void {
			igbz()->get( 'vip.posts' )->publish_due();
		} );
		$jobs->register( 'ig.vip.expire_due', static function (): void {
			igbz()->get( 'vip.posts' )->expire_due();
		} );

		// Phase 50 — the controlled legacy→Zernio migration, one bounded round per
		// beat with the canonical continuation contract: a full round queues the
		// next one (capped), a short round ends the wave.
		$jobs->register( 'ig.social.migrate', function ( array $payload, JobContext $ctx ) use ( $jobs ): void {
			$done = igbz()->get( 'ig.social_migration' )->run_distributed_round( self::MIGRATION_ROUND_LIMIT );
			$jobs->continue_round( $ctx, $payload, 'ig.social.migrate', $done, self::MIGRATION_ROUND_LIMIT, 10 );
		} );
	}

	public function run_hourly(): void {
		// Phase 50: the migration round rides the hourly beat. The hourly slot key
		// absorbs duplicate beats; the round itself is idempotent per tenant.
		$jobs = igbz()->get( 'jobs' );
		$slot = JobQueue::slot( HOUR_IN_SECONDS );
		$jobs->enqueue( 'ig.social.migrate', [], [ 'idempotency_key' => $slot ] );
	}

	// ---------------------------------------------------------------- health

	/** @return array<int,array{label:string,status:string,detail:string}> */
	public function health(): array {
		$settings = igbz()->settings();
		$db       = igbz()->db();
		$tenant   = (int) igbz()->tenancy()->id();
		$rows     = [];

		/** @var ZernioAdapterInterface $client */
		$client = igbz()->get( 'ig.zernio_client' );
		/** @var ZernioConnectionService $zernio */
		$zernio = igbz()->get( 'ig.zernio' );

		// The central account is the platform's; a store without it configured has
		// no social plane at all, so this is an error, not a warning.
		$rows[] = [
			'label'  => __( 'Zernio (central account)', 'igbz-suite' ),
			'status' => $client->is_configured() ? 'ok' : 'error',
			'detail' => $client->is_configured()
				? __( 'Central key configured; profiles are provisioned from it. Store profiles never see this key.', 'igbz-suite' )
				: __( 'No central Zernio key configured; no store can connect yet. Set it under IGBZ settings → Zernio.', 'igbz-suite' ),
		];

		$profile = $tenant > 0 ? $zernio->profile( $tenant ) : null;
		if ( null === $profile ) {
			$rows[] = [
				'label'  => __( 'Store profile', 'igbz-suite' ),
				'status' => $client->is_configured() ? 'warn' : 'ok',
				'detail' => __( 'Not provisioned yet; the migration round creates the store profile once the central key is live.', 'igbz-suite' ),
			];
		} else {
			$connected = ZernioConnectionService::STATUS_CONNECTED === (string) $profile['status'];
			$rows[]    = [
				'label'  => __( 'Store profile', 'igbz-suite' ),
				'status' => $connected ? 'ok' : 'warn',
				'detail' => $connected
					? sprintf( /* translators: %s: status */ __( 'Connected via official OAuth (profile %s).', 'igbz-suite' ), mb_substr( (string) $profile['profile_id'], 0, 12 ) )
					: sprintf( /* translators: %s: status */ __( 'Status: %s — finish the OAuth connect (IGBZ → IG Accounts).', 'igbz-suite' ), (string) $profile['status'] ),
			];
		}

		$accounts = 0;
		$deprecated = 0;
		if ( $tenant > 0 ) {
			$accounts   = (int) $db->scalar( 'SELECT COUNT(*) FROM ' . $db->table( 'ig_accounts' ) . ' WHERE tenant_id = %d AND is_active = 1', $tenant );
			$deprecated = (int) $db->scalar( 'SELECT COUNT(*) FROM ' . $db->table( 'ig_accounts' ) . ' WHERE tenant_id = %d AND legacy_deprecated_at IS NOT NULL', $tenant );
		}
		$rows[] = [
			'label'  => __( 'Legacy credentials', 'igbz-suite' ),
			'status' => $accounts > 0 && $deprecated < $accounts ? 'warn' : 'ok',
			'detail' => $accounts > 0
				? sprintf( /* translators: 1: deprecated, 2: total */ __( '%1$d of %2$d legacy account row(s) deprecated; encrypted keys stay until offboarding.', 'igbz-suite' ), $deprecated, $accounts )
				: __( 'No legacy account rows in this store.', 'igbz-suite' ),
		];

		// Publishing/inbox/registration flows come back with their Zernio rebuild
		// (phases 51–53); until then there is no pipeline health to report.
		return $rows;
	}
}
