<?php
namespace IGBZ\Suite\Modules\Instagram;

use IGBZ\Suite\Modules\Instagram\Gateways\ManyChatClient;
use IGBZ\Suite\Modules\Instagram\Gateways\ChatPlaceClient;
use IGBZ\Suite\Modules\Instagram\Services\AccountCredentials;
use IGBZ\Suite\Modules\Instagram\Services\ContentScheduler;
use IGBZ\Suite\Modules\Instagram\Services\FunnelService;
use IGBZ\Suite\Modules\Instagram\Services\InsightsService;
use IGBZ\Suite\Modules\Instagram\Services\IntakeWorker;
use IGBZ\Suite\Modules\Instagram\Services\ManusClient;
use IGBZ\Suite\Modules\Instagram\Services\ManusService;
use IGBZ\Suite\Modules\Instagram\Services\ManyChatBridge;
use IGBZ\Suite\Modules\Instagram\Services\ProductIntakeService;
use IGBZ\Suite\Modules\Instagram\Services\ProductPublisher;
use IGBZ\Suite\Modules\Instagram\Services\PromptBuilder;
use IGBZ\Suite\Modules\Instagram\Services\SkuGenerator;
use IGBZ\Suite\Modules\Instagram\Services\SubscriberService;
use IGBZ\Suite\Modules\Instagram\Services\TranslationBridge;
use IGBZ\Suite\Modules\Instagram\Messaging\ConfigurableDmGateway;
use IGBZ\Suite\Modules\Instagram\Messaging\DirectMessenger;
use IGBZ\Suite\Modules\Instagram\Messaging\ManyChatGateway;
use IGBZ\Suite\Modules\Instagram\Speech\HttpSpeechToText;
use IGBZ\Suite\Modules\Instagram\Speech\ManusSpeechToText;
use IGBZ\Suite\Modules\Instagram\Speech\SpeechToText;
use IGBZ\Suite\Modules\Instagram\Vip\VipAccessService;
use IGBZ\Suite\Modules\Instagram\Vip\VipBillingService;
use IGBZ\Suite\Modules\Instagram\Vip\VipLandingPage;
use IGBZ\Suite\Modules\Instagram\Vip\VipMediaService;
use IGBZ\Suite\Modules\Instagram\Vip\VipMessageService;
use IGBZ\Suite\Modules\Instagram\Vip\VipPostService;
use IGBZ\Suite\Modules\Instagram\Vip\VipSocialService;
use IGBZ\Suite\Modules\Instagram\Webhooks\ManusWebhook;
use IGBZ\Suite\Modules\Instagram\Webhooks\ManyChatWebhook;
use IGBZ\Suite\Support\Cron;
use IGBZ\Suite\Support\Jobs\JobContext;
use IGBZ\Suite\Support\Jobs\JobQueue;
use IGBZ\Suite\Support\ModuleInterface;
use IGBZ\Suite\Support\Modules;
use IGBZ\Suite\Support\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Port of the nopCommerce "IGBZ.InstagramCommerce" plugin.
 *
 * The single functional difference from the original: the Instagram Graph API is gone. Content
 * creation, scheduling and publishing run through Manus, and comment-to-DM funnels run through
 * ManyChat. Both sit behind PublisherInterface / ContentGeneratorInterface so a Graph adapter can
 * be dropped back in later without touching the rest of the module.
 */
final class InstagramModule implements ModuleInterface {

	/** Phase 25: funnel retry batch (must match FunnelService::retry_failed's default limit). */
	private const FUNNEL_RETRY_BATCH = 20;

	/** Phase 25: continuation rounds per hour — caps the worst-case loop. */
	private const MAX_SWEEP_ROUNDS = 10;

	public function id(): string {
		return Modules::INSTAGRAM;
	}

	public function title(): string {
		return __( 'Instagram commerce', 'igbz-suite' );
	}

	public function description(): string {
		return __( 'Manus content studio (research, graphics, reels, captions, auto-publishing at peak hours) plus ManyChat comment-to-DM funnels.', 'igbz-suite' );
	}

	public function register( Plugin $plugin ): void {
		$this->bind_services( $plugin );

		( new ManyChatWebhook(
			$plugin->get( 'ig.funnels' ),
			$plugin->get( 'ig.subscribers' ),
			$plugin->logger(),
			$plugin->get( 'ig.credentials' )
		) )->register();

		( new ManusWebhook(
			$plugin->db(),
			$plugin->get( 'ig.manus' ),
			$plugin->logger(),
			$plugin->get( 'ig.credentials' ),
			$plugin->get( 'ig.intake' ),
			$plugin->get( 'ig.intake_worker' )
		) )->register();

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
		add_action( Cron::HOOK_DAILY, [ $this, 'run_daily' ] );

		// Phase 24: the five-minute sweeps run as independent queued jobs — leased, retried
		// with backoff, dead-lettered when broken — instead of one long blocking cron request.
		$this->register_queue_handlers( $plugin->get( 'jobs' ) );

		// Products deleted in WooCommerce must not leave funnels pointing at a 404.
		add_action( 'before_delete_post', [ $this, 'detach_deleted_product' ] );

		// A registration is only finished once its post is actually live.
		add_action( 'igbz_ig_content_published', [ $this, 'close_intake_for_content' ], 10, 2 );

		if ( is_admin() ) {
			( new Admin\AccountsPage() )->register();
			( new Admin\IntakePage() )->register();
			( new Admin\ContentPage() )->register();
			( new Admin\FunnelsPage() )->register();
			( new Admin\VipPage() )->register();
			( new Admin\SubscribersPage() )->register();
			( new Admin\InsightsPage() )->register();
			( new Admin\AiStudioPage() )->register();
			( new Admin\GiveawayPage() )->register();

		add_action( 'igbz_ig_content_published', [ $this, 'basalam_publish' ], 10, 2 );
		}
	}

	private function bind_services( Plugin $plugin ): void {
		$plugin->bind( 'ig.prompts', static fn () => new PromptBuilder() );
		$plugin->bind( 'ig.credentials', static fn ( Plugin $c ) => new AccountCredentials( $c->db() ) );
		$plugin->bind( 'ig.zernio_client', static fn ( Plugin $c ) => new \IGBZ\Suite\Modules\Instagram\Gateways\ZernioClient( $c->http(), $c->logger() ) );
		$plugin->bind( 'ig.zernio', static fn ( Plugin $c ) => new \IGBZ\Suite\Modules\Instagram\Services\ZernioConnectionService( $c->db(), $c->logger(), $c->get( 'ig.zernio_client' ) ) );
		$plugin->bind( 'ig.manus_client', static fn ( Plugin $c ) => new ManusClient( $c->http(), $c->logger() ) );
		$plugin->bind(
			'ig.manus',
			static fn ( Plugin $c ) => new ManusService(
				$c->db(),
				$c->get( 'ig.manus_client' ),
				$c->get( 'ig.prompts' ),
				$c->logger(),
				$c->get( 'ig.credentials' )
			)
		);
		$plugin->bind(
			'ig.scheduler',
			static fn ( Plugin $c ) => new ContentScheduler(
				$c->db(),
				$c->get( 'ig.manus' ),
				$c->logger(),
				$c->get( 'ig.credentials' )
			)
		);
		$plugin->bind(
			'ig.insights',
			static fn ( Plugin $c ) => new InsightsService( $c->db(), $c->get( 'ig.manus' ), $c->get( 'ig.prompts' ), $c->logger() )
		);
		$plugin->bind(
			'ig.manychat',
			static function ( Plugin $c ) {
				$provider = $c->settings()->string( 'dm.provider', 'manychat' );
				if ( 'chatplace' === $provider ) {
					return new ChatPlaceClient( $c->http(), $c->logger() );
				}
				return new ManyChatClient( $c->http(), $c->logger() );
			}
		);

		// Direct messaging is routed per capability rather than per vendor: no single provider
		// covers text, video and native post sharing, and the paid-post feature needs all three.
		$plugin->bind(
			'ig.dm_manychat',
			static fn ( Plugin $c ) => new ManyChatGateway( $c->get( 'ig.manychat' ), $c->get( 'ig.credentials' ), $c->logger() )
		);
		$plugin->bind(
			'ig.dm_custom',
			static fn ( Plugin $c ) => new ConfigurableDmGateway( $c->http(), $c->settings(), $c->logger() )
		);
		$plugin->bind(
			'ig.dm',
			static fn ( Plugin $c ) => new DirectMessenger(
				$c->settings(),
				$c->logger(),
				$c->get( 'ig.dm_manychat' ),
				$c->get( 'ig.dm_custom' )
			)
		);
		$plugin->bind(
			'ig.subscribers',
			static fn ( Plugin $c ) => new SubscriberService(
				$c->db(),
				$c->get( 'ig.manychat' ),
				$c->logger(),
				$c->get( 'ig.credentials' )
			)
		);
		$plugin->bind(
			'ig.funnels',
			static fn ( Plugin $c ) => new FunnelService(
				$c->db(),
				$c->get( 'ig.manychat' ),
				$c->get( 'ig.subscribers' ),
				$c->has( 'wallet' )
					? $c->get( 'wallet' )
					: new \IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService( $c->db(), $c->logger() ),
				$c->logger(),
				$c->get( 'ig.credentials' )
			)
		);

		// ------------------------------------------- product registration

		$plugin->bind( 'ig.skus', static fn ( Plugin $c ) => new SkuGenerator( $c->db() ) );
		$plugin->bind( 'ig.translations', static fn ( Plugin $c ) => new TranslationBridge( $c->logger() ) );
		$plugin->bind(
			'ig.manychat_bridge',
			static fn ( Plugin $c ) => new ManyChatBridge( $c->get( 'ig.manychat' ), $c->get( 'ig.credentials' ), $c->logger() )
		);
		$plugin->bind(
			'ig.intake',
			static fn ( Plugin $c ) => new ProductIntakeService(
				$c->db(),
				$c->get( 'ig.manus' ),
				$c->get( 'ig.skus' ),
				$c->logger()
			)
		);
		$plugin->bind(
			'ig.publisher',
			static fn ( Plugin $c ) => new ProductPublisher(
				$c->get( 'ig.intake' ),
				$c->get( 'ig.funnels' ),
				$c->get( 'ig.manus' ),
				$c->get( 'ig.translations' ),
				$c->get( 'ig.manychat_bridge' ),
				$c->get( 'ig.skus' ),
				$c->logger()
			)
		);
		$plugin->bind(
			'ig.intake_worker',
			static fn ( Plugin $c ) => new IntakeWorker(
				$c->get( 'ig.intake' ),
				$c->get( 'ig.publisher' ),
				$c->get( 'ig.manus' ),
				$c->logger()
			)
		);

		// ------------------------------------------------------ VIP channel

		$plugin->bind( 'vip.access', static fn ( Plugin $c ) => new VipAccessService( $c->db() ) );
		$plugin->bind( 'giveaways', static fn ( Plugin $c ) => new \IGBZ\Suite\Modules\Instagram\AiStudio\GiveawayService( $c->get( 'db' ), $c->logger() ) );
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

		// Speech to text: a pluggable engine with Manus as the always-available fallback.
		$plugin->bind( 'ig.stt_http', static fn ( Plugin $c ) => new HttpSpeechToText( $c->settings(), $c->logger() ) );
		$plugin->bind( 'ig.stt_manus', static fn ( Plugin $c ) => new ManusSpeechToText( $c->get( 'ig.manus' ), $c->logger() ) );
		$plugin->bind(
			'ig.stt',
			static fn ( Plugin $c ) => new SpeechToText(
				$c->settings(),
				$c->logger(),
				$c->get( 'ig.stt_http' ),
				$c->get( 'ig.stt_manus' )
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
		foreach ( [ 'ig.content.tick', 'ig.intake.tick', 'ig.vip.publish_due', 'ig.vip.expire_due' ] as $job_type ) {
			$jobs->enqueue( $job_type, [], [ 'idempotency_key' => $slot ] );
		}
	}

	/** Phase 24: handler wiring for the queued five-minute sweeps. */
	public function register_queue_handlers( JobQueue $jobs ): void {
		$jobs->register( 'ig.content.tick', static function (): void {
			igbz()->get( 'ig.scheduler' )->tick();
		} );
		$jobs->register( 'ig.intake.tick', static function (): void {
			igbz()->get( 'ig.intake_worker' )->tick();
		} );
		$jobs->register( 'ig.vip.publish_due', static function (): void {
			igbz()->get( 'vip.posts' )->publish_due();
		} );
		$jobs->register( 'ig.vip.expire_due', static function (): void {
			igbz()->get( 'vip.posts' )->expire_due();
		} );

		// Phase 25 — the hourly IG jobs (continuation via the queue's canonical contract).
		$jobs->register( 'ig.funnels.retry', function ( array $payload, JobContext $ctx ) use ( $jobs ): void {
			$done = igbz()->get( 'ig.funnels' )->retry_failed();
			$jobs->continue_round( $ctx, $payload, 'ig.funnels.retry', $done, self::FUNNEL_RETRY_BATCH, self::MAX_SWEEP_ROUNDS );
		} );
		$jobs->register( 'ig.insights.reconcile', static function (): void {
			if ( igbz()->settings()->bool( 'manus.collect_insights', true ) ) {
				igbz()->get( 'ig.insights' )->reconcile();
			}
		} );

		// Phase 26 — the daily insights collection (bounded keyset walk inside the service).
		$jobs->register( 'ig.insights.collect', static function (): void {
			if ( igbz()->settings()->bool( 'manus.collect_insights', true ) ) {
				igbz()->get( 'ig.insights' )->collect_all();
			}
		} );
	}

	public function run_hourly(): void {
		// Phase 25: queued jobs with the hourly slot key absorbing duplicate beats. The funnel
		// retry applies the continuation contract (capped batch, re-queue while full); the
		// insights reconciler walks accounts itself, so it stays a single control-plane job.
		$jobs = igbz()->get( 'jobs' );
		$slot = JobQueue::slot( HOUR_IN_SECONDS );
		$jobs->enqueue( 'ig.funnels.retry', [], [ 'idempotency_key' => $slot ] );
		$jobs->enqueue( 'ig.insights.reconcile', [], [ 'idempotency_key' => $slot ] );
	}

	public function run_daily(): void {
		// Phase 26: the insights collector runs as a queued job; the enabled-check stays at
		// run time inside the handler. The daily slot key absorbs duplicate beats.
		igbz()->get( 'jobs' )->enqueue( 'ig.insights.collect', [], [ 'idempotency_key' => JobQueue::slot( DAY_IN_SECONDS ) ] );
	}

	/**
	 * Close the registration whose post has just gone live.
	 *
	 * The registration and the content row are two different things with two different
	 * lifecycles: the content row is published by the scheduler, and the registration only counts
	 * as finished when that happens. Listening to the event rather than assuming means a post
	 * published by hand, by cron or by the webhook all close the loop identically.
	 *
	 * @param int    $content_id
	 * @param string $permalink
	 */
	public function close_intake_for_content( $content_id, $permalink = '' ): void {
		unset( $permalink );

		$db  = igbz()->db();
		$row = $db->row(
			'SELECT id FROM ' . $db->table( 'ig_intake' ) . ' WHERE content_id = %d ORDER BY id DESC LIMIT 1',
			(int) $content_id
		);

		if ( $row ) {
			igbz()->get( 'ig.intake' )->mark_published( (int) $row['id'] );
		}
	}

	/** @param int $post_id */
	public function detach_deleted_product( $post_id ): void {
		$post_id = (int) $post_id;
		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}
		$db = igbz()->db();
		$db->update( 'ig_funnels', [ 'product_id' => 0 ], [ 'product_id' => $post_id ] );
		$db->update( 'ig_content', [ 'product_id' => 0 ], [ 'product_id' => $post_id ] );
	}

	// ---------------------------------------------------------------- health

	/** @return array<int,array{label:string,status:string,detail:string}> */
	public function health(): array {
		$settings = igbz()->settings();
		$db       = igbz()->db();
		$rows     = [];

		/** @var ManusService $manus */
		$manus = igbz()->get( 'ig.manus' );
		/** @var AccountCredentials $credentials */
		$credentials = igbz()->get( 'ig.credentials' );

		// Credentials are per account now, so health is a tally over the active accounts rather
		// than one look at a global option.
		$active     = $manus->all_accounts( true );
		$ready      = [ AccountCredentials::SERVICE_MANUS => 0, AccountCredentials::SERVICE_MANYCHAT => 0 ];
		$on_trial   = 0;
		$trial_dead = 0;
		foreach ( $active as $account ) {
			foreach ( array_keys( $ready ) as $service ) {
				if ( $credentials->has_key( $account, $service ) ) {
					++$ready[ $service ];
				}
			}
			if ( AccountCredentials::MODE_TRIAL === $credentials->mode( $account ) ) {
				++$on_trial;
				if ( ! $credentials->trial_is_open( $account ) ) {
					++$trial_dead;
				}
			}
		}
		$total = count( $active );

		foreach (
			[
				AccountCredentials::SERVICE_MANUS    => [
					__( 'Manus API', 'igbz-suite' ),
					sprintf(
						/* translators: %s: agent profile */
						__( 'Agent profile: %s', 'igbz-suite' ),
						$settings->string( 'manus.agent_profile', 'manus-1.6' )
					),
					__( 'No active account has a usable Manus key; content generation and publishing are disabled.', 'igbz-suite' ),
				],
				AccountCredentials::SERVICE_MANYCHAT => [
					__( 'ManyChat API', 'igbz-suite' ),
					__( 'A ManyChat Pro plan is required per page.', 'igbz-suite' ),
					__( 'No active account has a usable ManyChat key; subscriber lookups and flow sending are disabled.', 'igbz-suite' ),
				],
			] as $service => $labels
		) {
			[ $label, $note, $empty ] = $labels;
			$count                    = $ready[ $service ];

			$rows[] = [
				'label'  => $label,
				'status' => $count > 0 ? ( $count === $total ? 'ok' : 'warn' ) : 'error',
				'detail' => $count > 0
					? sprintf(
						/* translators: 1: accounts with a key, 2: total active accounts, 3: extra note */
						__( '%1$d of %2$d active accounts have a key. %3$s', 'igbz-suite' ),
						$count,
						$total,
						$note
					)
					: $empty,
			];
		}

		if ( $on_trial > 0 ) {
			$rows[] = [
				'label'  => __( 'Free trial accounts', 'igbz-suite' ),
				'status' => $trial_dead > 0 ? 'warn' : 'ok',
				'detail' => sprintf(
					/* translators: 1: accounts on trial, 2: accounts whose trial ended */
					__( '%1$d account(s) run on the shared trial key; %2$d have used it up or expired.', 'igbz-suite' ),
					$on_trial,
					$trial_dead
				),
			];
		}

		$untokened = 0;
		foreach ( $active as $account ) {
			if ( '' === (string) ( $account['manychat_webhook_token'] ?? '' ) ) {
				++$untokened;
			}
		}
		$rows[] = [
			'label'  => __( 'ManyChat webhook', 'igbz-suite' ),
			'status' => 0 === $untokened ? 'ok' : 'error',
			'detail' => 0 === $untokened
				? sprintf(
					/* translators: %s: webhook URL */
					__( 'Every account has its own External Request URL, e.g. %s', 'igbz-suite' ),
					esc_url_raw( add_query_arg( 'token', '***', rest_url( ManyChatWebhook::NAMESPACE . '/manychat/comment' ) ) )
				)
				: sprintf(
					/* translators: %d: number of accounts */
					__( '%d active account(s) have no webhook token; their incoming ManyChat requests are rejected. Re-save the account to mint one.', 'igbz-suite' ),
					$untokened
				),
		];

		$accounts = (int) $db->scalar( 'SELECT COUNT(*) FROM ' . $db->table( 'ig_accounts' ) . ' WHERE is_active = 1' );
		$rows[]   = [
			'label'  => __( 'Instagram accounts', 'igbz-suite' ),
			'status' => $accounts > 0 ? 'ok' : 'warn',
			'detail' => sprintf( /* translators: %d: count */ _n( '%d active account', '%d active accounts', $accounts, 'igbz-suite' ), $accounts ),
		];

		$funnels = (int) $db->scalar( 'SELECT COUNT(*) FROM ' . $db->table( 'ig_funnels' ) . ' WHERE is_active = 1' );

		// "Undelivered" used to lump four very different situations together. A hit blocked by
		// the per-user cap is the system working; a hit waiting for its follow-up is normal for a
		// few seconds; only a failed send is a problem worth a warning.
		$backlog = igbz()->get( 'ig.funnels' )->delivery_backlog();

		$detail = sprintf(
			/* translators: 1: active funnels, 2: failed hits */
			__( '%1$d active funnel(s); %2$d failed delivery(ies) in the last 24h.', 'igbz-suite' ),
			$funnels,
			$backlog['failed']
		);

		if ( $backlog['pending'] > 0 ) {
			$detail .= ' ' . sprintf(
				/* translators: %d: count */
				__( '%d hit(s) still waiting to send.', 'igbz-suite' ),
				$backlog['pending']
			);
		}

		if ( $backlog['unconfirmed'] > 0 ) {
			$detail .= ' ' . sprintf(
				/* translators: %d: count */
				__( '%d reply(ies) were handed to ManyChat without a confirmation.', 'igbz-suite' ),
				$backlog['unconfirmed']
			);
		}

		$rows[] = [
			'label'  => __( 'Comment funnels', 'igbz-suite' ),
			'status' => ( $backlog['failed'] > 0 || $backlog['unconfirmed'] > 0 ) ? 'warn' : 'ok',
			'detail' => $detail,
		];

		$failed = (int) $db->scalar(
			'SELECT COUNT(*) FROM ' . $db->table( 'ig_content' ) . ' WHERE status = %s',
			ManusService::STATUS_FAILED
		);
		// Publishing goes through a Manus task rather than a Graph API call that returns a media id,
		// so "finished" and "confirmed" are not the same thing. Count the rows we could not verify.
		$unverified = (int) $db->scalar(
			'SELECT COUNT(*) FROM ' . $db->table( 'ig_content' ) . " WHERE status = %s AND permalink = ''",
			ManusService::STATUS_PUBLISHED
		);

		$detail = sprintf( /* translators: %d: count */ __( '%d item(s) in the failed state.', 'igbz-suite' ), $failed );
		if ( $unverified > 0 ) {
			$detail .= ' ' . sprintf(
				/* translators: %d: count */
				_n(
					'%d published item returned no post link, so it could not be confirmed on Instagram.',
					'%d published items returned no post link, so they could not be confirmed on Instagram.',
					$unverified,
					'igbz-suite'
				),
				$unverified
			);
		}

		$rows[] = [
			'label'  => __( 'Content pipeline', 'igbz-suite' ),
			'status' => ( $failed > 0 || $unverified > 0 ) ? 'warn' : 'ok',
			'detail' => $detail,
		];

		foreach ( $this->intake_health() as $row ) {
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Health of the phone-to-Instagram registration flow.
	 *
	 * @return array<int,array{label:string,status:string,detail:string}>
	 */
	private function intake_health(): array {
		$rows   = [];
		$intake = igbz()->get( 'ig.intake' );
		$counts = $intake->counts_by_status();

		$stuck = (int) ( $counts[ ProductIntakeService::STATUS_FAILED ] ?? 0 );
		$open  = array_sum( $counts ) - $stuck
			- (int) ( $counts[ ProductIntakeService::STATUS_PUBLISHED ] ?? 0 )
			- (int) ( $counts[ ProductIntakeService::STATUS_SCHEDULED ] ?? 0 );

		$rows[] = [
			'label'  => __( 'Product registration', 'igbz-suite' ),
			'status' => $stuck > 0 ? 'warn' : 'ok',
			'detail' => sprintf(
				/* translators: 1: registrations in progress, 2: failed registrations */
				__( '%1$d registration(s) in progress, %2$d failed. Products are created from the app; nobody has to open the WooCommerce editor.', 'igbz-suite' ),
				max( 0, $open ),
				$stuck
			),
		];

		// The one dependency the flow cannot work around: an intake needs an account to borrow a
		// Manus key from, and without one every registration fails at the first step.
		$accounts = (int) igbz()->db()->scalar(
			'SELECT COUNT(*) FROM ' . igbz()->db()->table( 'ig_accounts' ) . ' WHERE is_active = 1'
		);

		if ( 0 === $accounts ) {
			$rows[] = [
				'label'  => __( 'Registration prerequisites', 'igbz-suite' ),
				'status' => 'error',
				'detail' => __( 'No active Instagram account, so the assistant has no API key to work with and registrations will fail at the photo check. Add one under IGBZ → IG Accounts.', 'igbz-suite' ),
			];
		}

		/** @var \IGBZ\Suite\Modules\Instagram\Speech\SpeechToText $speech */
		$speech    = igbz()->get( 'ig.stt' );
		$preferred = $speech->preferred();
		$voice_on  = igbz()->settings()->bool( 'stt.enabled', true );

		$rows[] = [
			'label'  => __( 'Voice input', 'igbz-suite' ),
			'status' => ! $voice_on ? 'warn' : 'ok',
			'detail' => ! $voice_on
				? __( 'Switched off. Shopkeepers must type the product description.', 'igbz-suite' )
				: ( $preferred->is_configured()
					? sprintf( /* translators: %s: engine name */ __( 'Using %s.', 'igbz-suite' ), $preferred->title() )
					: sprintf(
						/* translators: %s: engine name */
						__( '%s is selected but not configured, so voice notes fall back to Manus, which takes minutes rather than seconds.', 'igbz-suite' ),
						$preferred->title()
					) ),
		];

		/** @var \IGBZ\Suite\Modules\Instagram\Services\TranslationBridge $translations */
		$translations = igbz()->get( 'ig.translations' );
		$engine       = $translations->engine();
		$targets      = $translations->target_languages();

		$rows[] = [
			'label'  => __( 'Listing translations', 'igbz-suite' ),
			'status' => 'ok',
			'detail' => match ( $engine ) {
				\IGBZ\Suite\Modules\Instagram\Services\TranslationBridge::ENGINE_POLYLANG => sprintf(
					/* translators: %s: comma separated language codes */
					__( 'Polylang detected. Real translated products are created and linked for: %s', 'igbz-suite' ),
					$targets ? implode( ', ', $targets ) : __( 'no extra languages configured', 'igbz-suite' )
				),
				\IGBZ\Suite\Modules\Instagram\Services\TranslationBridge::ENGINE_WPML     => sprintf(
					/* translators: %s: comma separated language codes */
					__( 'WPML detected. Real translated products are created and linked for: %s', 'igbz-suite' ),
					$targets ? implode( ', ', $targets ) : __( 'no extra languages configured', 'igbz-suite' )
				),
				default                                                                   => $targets
					? sprintf(
						/* translators: %s: comma separated language codes */
						__( 'No multilingual plugin, so translations into %s are stored on each product and can be turned into real products later.', 'igbz-suite' ),
						implode( ', ', $targets )
					)
					: __( 'Single language store; listings are written once.', 'igbz-suite' ),
			},
		];

		return $rows;
	}

	/** Auto-publish Instagram-made content to Basalam when enabled. */
	public function basalam_publish( int $content_id, $content = null ): void {
		if ( ! igbz()->settings()->bool( 'basalam.enabled', false ) || ! igbz()->has( 'marketplace.basalam' ) ) {
			return;
		}
		if ( null === $content ) {
			$content = igbz()->get( 'ig.manus' )->content( $content_id );
		}
		if ( ! $content ) {
			return;
		}
		$media = json_decode( (string) ( $content['media'] ?? '{}' ), true );
		igbz()->get( 'marketplace.basalam' )->publish_content(
			[
				'kind'       => (string) ( $content['kind'] ?? 'post' ),
				'caption'    => (string) ( $content['caption'] ?? '' ),
				'media_url'  => (string) ( $media['url'] ?? $media[0]['url'] ?? '' ),
			]
		);
	}
}
