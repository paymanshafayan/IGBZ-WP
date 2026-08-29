<?php
namespace IGBZ\Suite\Modules\RestApi;

use IGBZ\Suite\Modules\MultiTenant\Otp\OtpService;
use IGBZ\Suite\Modules\RestApi\Admin\DevicesPage;
use IGBZ\Suite\Modules\RestApi\Auth\Authenticator;
use IGBZ\Suite\Modules\RestApi\Auth\TokenService;
use IGBZ\Suite\Modules\RestApi\Controllers\AccountController;
use IGBZ\Suite\Modules\RestApi\Controllers\AuthController;
use IGBZ\Suite\Modules\RestApi\Controllers\SocialMigrationController;
use IGBZ\Suite\Modules\RestApi\Controllers\BaseController;
use IGBZ\Suite\Modules\RestApi\Controllers\CatalogController;
use IGBZ\Suite\Modules\RestApi\Controllers\DeviceController;
use IGBZ\Suite\Modules\RestApi\Controllers\FxController;

use IGBZ\Suite\Modules\RestApi\Controllers\StoreAdminController;
use IGBZ\Suite\Modules\RestApi\Controllers\WebhookController;
use IGBZ\Suite\Modules\RestApi\Controllers\VipAdminController;
use IGBZ\Suite\Modules\RestApi\Controllers\VipController;
use IGBZ\Suite\Modules\RestApi\Push\DeviceRepository;
use IGBZ\Suite\Modules\RestApi\Push\FcmService;
use IGBZ\Suite\Modules\RestApi\Push\GoogleAuth;
use IGBZ\Suite\Modules\RestApi\Push\NotificationService;
use IGBZ\Suite\Support\Cron;
use IGBZ\Suite\Support\Jobs\JobQueue;
use IGBZ\Suite\Support\ModuleInterface;
use IGBZ\Suite\Support\Modules;
use IGBZ\Suite\Support\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Port of the nopCommerce "Nop.Plugin.Api" plugin: the mobile app back end.
 *
 * Everything the Flutter client needs lives under the `igbz/v1` namespace - OTP and password
 * login, catalogue browsing, the customer account (orders, wallet, instalments, courses,
 * affiliate), device registration and push.
 *
 * Fixes carried over from the audit of the original:
 *  - JWTs were 30-day, single-use-forever tokens with no refresh and no way to sign out; here a
 *    short-lived access token is paired with a rotating refresh token, every issue is recorded in
 *    `api_tokens` and can be revoked per device or per account;
 *  - five admin controllers inherited a base class that did not exist, so the plugin did not
 *    compile; the base class here is real and every privileged route declares a permission;
 *  - device tokens lived on the customer record with no way to remove one and no delivery
 *    feedback; FCM v1 responses now invalidate dead tokens automatically;
 *  - deep-link and store-config endpoints returned hard-coded `*.local` placeholders; they are
 *    driven by settings.
 */
final class RestApiModule implements ModuleInterface {

	public function id(): string {
		return Modules::REST_API;
	}

	public function title(): string {
		return __( 'Mobile API', 'igbz-suite' );
	}

	public function description(): string {
		return __( 'JWT authentication with refresh tokens, catalogue and account endpoints for the mobile app, device registration and Firebase push notifications.', 'igbz-suite' );
	}

	public function register( Plugin $plugin ): void {
		$this->bind_services( $plugin );

		/** @var Authenticator $authenticator */
		$authenticator = $plugin->get( 'api.auth' );
		$authenticator->register();

		foreach ( $this->controllers( $plugin ) as $controller ) {
			$controller->register();
		}

		/** @var NotificationService $notifications */
		$notifications = $plugin->get( 'api.notifications' );
		$notifications->register();

		add_action( Cron::HOOK_DAILY, [ $this, 'run_daily' ] );

		// Phase 26: the daily prune runs as a queued job — leased and retried like everything
		// else, instead of blocking the shared daily cron request.
		$this->register_queue_handlers( $plugin->get( 'jobs' ) );

		// A password change must not leave a stolen phone signed in.
		add_action( 'after_password_reset', [ $this, 'on_password_reset' ], 10, 1 );
		add_action( 'profile_update', [ $this, 'on_profile_update' ], 10, 2 );

		if ( is_admin() ) {
			( new DevicesPage() )->register();
		}
	}

	private function bind_services( Plugin $plugin ): void {
		$plugin->bind( 'api.tokens', static fn ( Plugin $c ) => new TokenService( $c->db(), $c->logger() ) );
		$plugin->bind( 'api.auth', static fn ( Plugin $c ) => new Authenticator( $c->get( 'api.tokens' ), $c->logger() ) );
		$plugin->bind( 'api.devices', static fn ( Plugin $c ) => new DeviceRepository( $c->db() ) );
		$plugin->bind(
			'api.biometric',
			static fn ( Plugin $c ) => new \IGBZ\Suite\Support\BiometricGate( $c->get( 'api.devices' ), $c->logger() )
		);
		$plugin->bind( 'api.google_auth', static fn ( Plugin $c ) => new GoogleAuth( $c->http(), $c->logger() ) );
		$plugin->bind(
			'api.push',
			static fn ( Plugin $c ) => new FcmService(
				$c->http(),
				$c->get( 'api.google_auth' ),
				$c->get( 'api.devices' ),
				$c->logger()
			)
		);
		$plugin->bind(
			'api.notifications',
			static fn ( Plugin $c ) => new NotificationService(
				$c->get( 'api.push' ),
				$c->get( 'api.devices' ),
				$c->logger()
			)
		);
	}

	/** @return BaseController[] */
	private function controllers( Plugin $plugin ): array {
		$controllers = [
			new AuthController( $plugin->get( 'api.tokens' ), $this->otp( $plugin ), $plugin->logger() ),
			new CatalogController(),
			new AccountController(),
			new DeviceController( $plugin->get( 'api.devices' ), $plugin->get( 'api.notifications' ) ),
			new StoreAdminController(),
			new WebhookController( $plugin->get( 'webhooks.inbox' ) ),
		];

		// Phase 50: the legacy product-intake endpoints went with the legacy
		// assistant/funnel stack they existed for. The 13-step product
		// registration (phase 52) re-lands its own endpoints on the single
		// social provider. What survives from that area is the migration
		// surface — store owners read their state and run their own round.
		if ( \IGBZ\Suite\Support\Modules::enabled( \IGBZ\Suite\Support\Modules::INSTAGRAM ) && $plugin->has( 'ig.social_migration' ) ) {
			$controllers[] = new SocialMigrationController();
		}

		// Same story for the VIP channel: the posts, the paywall and the member inbox are owned
		// by the Instagram module, and the app talks to them over this namespace.
		if ( \IGBZ\Suite\Support\Modules::enabled( \IGBZ\Suite\Support\Modules::INSTAGRAM ) && $plugin->has( 'vip.posts' ) ) {
			$controllers[] = new VipController();
			$controllers[] = new VipAdminController();
		}

		// The FX wallet endpoints exist whenever the FX module is enabled (they need the wallet,
		// the rates and the top-up service, which only the FX module binds).
		if ( \IGBZ\Suite\Support\Modules::enabled( \IGBZ\Suite\Support\Modules::FX ) && $plugin->has( 'fx.wallet' ) ) {
			$controllers[] = new FxController();
		if ( \IGBZ\Suite\Support\Modules::enabled( \IGBZ\Suite\Support\Modules::INSTAGRAM ) && $plugin->has( 'ai.studio' ) ) {
			$controllers[] = new \IGBZ\Suite\Modules\RestApi\Controllers\AiStudioController();
		if ( \IGBZ\Suite\Support\Modules::enabled( \IGBZ\Suite\Support\Modules::MULTITENANT ) && $plugin->has( 'logistics.courier' ) ) {
			$controllers[] = new \IGBZ\Suite\Modules\RestApi\Controllers\CourierController();
		if ( \IGBZ\Suite\Support\Modules::enabled( \IGBZ\Suite\Support\Modules::MULTITENANT ) && $plugin->has( 'domain' ) ) {
			$controllers[] = new \IGBZ\Suite\Modules\RestApi\Controllers\DomainController();
		}
		}
		}
		}

		return $controllers;
	}

	/** The API can run with the MultiTenant module switched off, so build a fallback instance. */
	private function otp( Plugin $plugin ): OtpService {
		if ( $plugin->has( 'otp' ) ) {
			return $plugin->get( 'otp' );
		}
		return new OtpService( $plugin->db(), $plugin->http(), $plugin->logger() );
	}

	// ------------------------------------------------------------- runtime

	public function run_daily(): void {
		// Phase 26: the prune runs as a queued job; the daily slot key absorbs duplicate beats.
		igbz()->get( 'jobs' )->enqueue( 'api.prune', [], [ 'idempotency_key' => JobQueue::slot( DAY_IN_SECONDS ) ] );
	}

	/** Phase 26: handler wiring for the queued API prune. */
	public function register_queue_handlers( JobQueue $jobs ): void {
		$jobs->register( 'api.prune', static function (): void {
			$plugin = igbz();

			/** @var TokenService $tokens */
			$tokens  = $plugin->get( 'api.tokens' );
			$deleted = $tokens->prune_expired();

			/** @var DeviceRepository $devices */
			$devices = $plugin->get( 'api.devices' );
			$stale   = $devices->prune_stale( max( 30, $plugin->settings()->int( 'api.device_retention_days', 180 ) ) );

			if ( $deleted > 0 || $stale > 0 ) {
				$plugin->logger()->info(
					'api',
					'Daily API housekeeping',
					[ 'tokens_pruned' => $deleted, 'devices_pruned' => $stale ]
				);
			}
		} );
	}

	/**
	 * @param mixed $user
	 */
	public function on_password_reset( $user = null ): void {
		$user_id = $user instanceof \WP_User ? (int) $user->ID : 0;
		if ( $user_id > 0 ) {
			igbz()->get( 'api.tokens' )->revoke_all_for_user( $user_id );
		}
	}

	/**
	 * @param mixed $old_user_data
	 */
	public function on_profile_update( int $user_id, $old_user_data = null ): void {
		if ( ! $old_user_data instanceof \WP_User ) {
			return;
		}

		$new = get_userdata( $user_id );
		if ( $new && $new->user_pass !== $old_user_data->user_pass ) {
			igbz()->get( 'api.tokens' )->revoke_all_for_user( $user_id );
		}
	}

	// -------------------------------------------------------------- health

	/** @return array<int,array{label:string,status:string,detail:string}> */
	public function health(): array {
		$plugin   = igbz();
		$settings = $plugin->settings();
		$rows     = [];

		/** @var TokenService $tokens */
		$tokens = $plugin->get( 'api.tokens' );

		$rows[] = [
			'label'  => __( 'API base URL', 'igbz-suite' ),
			'status' => 'ok',
			'detail' => rest_url( BaseController::NAMESPACE ),
		];

		$secret = $settings->string( 'api.jwt_secret', '' );
		$rows[] = [
			'label'  => __( 'JWT signing secret', 'igbz-suite' ),
			'status' => strlen( $secret ) >= 32 ? 'ok' : 'error',
			'detail' => strlen( $secret ) >= 32
				? __( 'A secret of adequate length is stored encrypted.', 'igbz-suite' )
				: __( 'Missing or too short. Generate one under Settings → Mobile API; tokens cannot be trusted until then.', 'igbz-suite' ),
		];

		$access  = $tokens->access_ttl();
		$refresh = $tokens->refresh_ttl();
		$rows[]  = [
			'label'  => __( 'Token lifetimes', 'igbz-suite' ),
			'status' => $access <= 86400 ? 'ok' : 'warn',
			'detail' => sprintf(
				/* translators: 1: access token lifetime, 2: refresh token lifetime */
				__( 'Access token %1$s, refresh token %2$s. Refresh tokens rotate on use and a replayed one revokes the device.', 'igbz-suite' ),
				human_time_diff( 0, $access ),
				human_time_diff( 0, $refresh )
			),
		];

		$rows[] = [
			'label'  => __( 'Active sessions', 'igbz-suite' ),
			'status' => 'ok',
			'detail' => sprintf(
				/* translators: %d: number of sessions */
				__( '%d unexpired session(s) across all devices.', 'igbz-suite' ),
				$tokens->active_session_count()
			),
		];

		$rows[] = [
			'label'  => __( 'Rate limiting', 'igbz-suite' ),
			'status' => 'ok',
			'detail' => sprintf(
				/* translators: %d: requests per minute */
				__( '%d requests per minute per client on authentication routes.', 'igbz-suite' ),
				$settings->int( 'api.rate_limit_per_minute', 120 )
			),
		];

		/** @var DeviceRepository $devices */
		$devices     = $plugin->get( 'api.devices' );
		$total       = $devices->count();
		$with_token  = $devices->count( [ 'with_token' => true ] );

		$rows[] = [
			'label'  => __( 'Registered devices', 'igbz-suite' ),
			'status' => 'ok',
			'detail' => sprintf(
				/* translators: 1: total devices, 2: devices with a push token */
				__( '%1$d device(s) registered, %2$d with a usable push token.', 'igbz-suite' ),
				$total,
				$with_token
			),
		];

		/** @var GoogleAuth $google */
		$google      = $plugin->get( 'api.google_auth' );
		$push_on     = $settings->bool( 'api.push_enabled', false );
		$configured  = $google->is_configured();

		$rows[] = [
			'label'  => __( 'Firebase push', 'igbz-suite' ),
			'status' => ! $push_on ? 'warn' : ( $configured ? 'ok' : 'error' ),
			'detail' => ! $push_on
				? __( 'Disabled. Devices still register, but nothing is delivered.', 'igbz-suite' )
				: ( $configured
					? sprintf(
						/* translators: %s: Firebase project id */
						__( 'FCM HTTP v1 for project %s. Tokens rejected by Google are cleared automatically.', 'igbz-suite' ),
						$google->project_id()
					)
					: __( 'Enabled but the service account JSON is missing or invalid. Paste it under Settings → Mobile API.', 'igbz-suite' ) ),
		];

		return $rows;
	}
}
