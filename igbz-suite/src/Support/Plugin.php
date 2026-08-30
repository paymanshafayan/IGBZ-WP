<?php
namespace IGBZ\Suite\Support;

use IGBZ\Suite\Modules\Hub\HubModule;
use IGBZ\Suite\Modules\Pado\PadoModule;
use IGBZ\Suite\Modules\Instagram\InstagramModule;
use IGBZ\Suite\Modules\MultiTenant\MultiTenantModule;
use IGBZ\Suite\Modules\RestApi\RestApiModule;
use IGBZ\Suite\Modules\Fx\FxModule;

defined( 'ABSPATH' ) || exit;

/**
 * Service container + module registry.
 *
 * The suite ships as ONE plugin with six independently toggleable modules, preserving the original
 * IGBZ boundaries while adding the Pado and FX modules.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	/** @var array<string,callable> */
	private array $factories = [];

	/** @var array<string,mixed> */
	private array $resolved = [];

	/** @var array<string,ModuleInterface> */
	private array $modules = [];

	private bool $booted = false;

	private function __construct() {}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$this->register_core_services();

		WooCommerceCompat::register();

		// Phase 68: the Persian storefront layer (toman currency, checkout country
		// defaults, Persian front digits) — see FaStorefront.
		FaStorefront::register();

		// Phase 70: the product's own health/readiness probe (GET /?igbz_health=1).
		HealthEndpoint::register();

		add_action( 'plugins_loaded', [ $this, 'on_plugins_loaded' ], 5 );
		add_action( 'init', [ $this, 'load_textdomain' ], 1 );
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'igbz-suite', false, dirname( IGBZ_BASENAME ) . '/languages' );
	}

	public function on_plugins_loaded(): void {
		if ( ! $this->woocommerce_active() ) {
			add_action( 'admin_notices', [ $this, 'render_woocommerce_notice' ] );
			return;
		}

		Activator::maybe_upgrade();

		foreach ( $this->module_map() as $id => $class ) {
			/** @var ModuleInterface $module */
			$module                = new $class();
			$this->modules[ $id ] = $module;
			if ( Modules::enabled( $id ) ) {
				$module->register( $this );
			}
		}

		( new \IGBZ\Suite\Support\Admin\SettingsPage() )->register();
		( new \IGBZ\Suite\Support\Admin\StatusPage() )->register();
		( new \IGBZ\Suite\Support\Cron() )->register();

		// Registered unconditionally: this guards core and WooCommerce routes, which exist
		// whether or not any of our modules are switched on.
		( new \IGBZ\Suite\Support\CoreSurfaceGuard( $this->logger() ) )->register();

		do_action( 'igbz_booted', $this );
	}

	/** @return array<string,class-string<ModuleInterface>> */
	public function module_map(): array {
		return [
			Modules::MULTITENANT => MultiTenantModule::class,
			Modules::INSTAGRAM   => InstagramModule::class,
			Modules::HUB         => HubModule::class,
			// FX binds fx.wallet/fx.rates/fx.topup before REST_API asks for them, so the mobile
			// API can register the /fx/* routes when the FX module is on.
			Modules::FX          => FxModule::class,
			Modules::REST_API    => RestApiModule::class,
			// Pado (AI assistant) is enabled by default and adds the "مرکز پادو"
			// admin page plus the unified approval-request queue that other modules
			// (price changes, refunds, instagram publish, bulk delete, …) post into.
			Modules::PADO    => PadoModule::class,
		];
	}

	/** @return array<string,ModuleInterface> */
	public function modules(): array {
		return $this->modules;
	}

	public function woocommerce_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	public function render_woocommerce_notice(): void {
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'IGBZ Suite requires WooCommerce to be installed and active.', 'igbz-suite' )
			. '</p></div>';
	}

	// ---------------------------------------------------------------- container

	public function bind( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->resolved[ $id ] );
	}

	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] ) || isset( $this->resolved[ $id ] );
	}

	public function get( string $id ): mixed {
		if ( array_key_exists( $id, $this->resolved ) ) {
			return $this->resolved[ $id ];
		}
		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new \RuntimeException( sprintf( 'IGBZ Suite: service "%s" is not registered.', $id ) );
		}
		$this->resolved[ $id ] = ( $this->factories[ $id ] )( $this );
		return $this->resolved[ $id ];
	}

	private function register_core_services(): void {
		$this->bind( 'settings', static fn () => new Settings() );
		$this->bind( 'logger', static fn ( Plugin $c ) => new Logger( $c->get( 'settings' ) ) );
		$this->bind( 'slo', static fn ( Plugin $c ) => new \IGBZ\Suite\Support\Observability\Slo( $c->get( 'db' ), $c->get( 'settings' ) ) );
		$this->bind( 'db', static fn () => new Db() );
		$this->bind( 'http', static fn ( Plugin $c ) => new Http( $c->get( 'logger' ) ) );
		$this->bind( 'tenancy', static fn ( Plugin $c ) => new \IGBZ\Suite\Modules\MultiTenant\Repository\TenantContext( $c->get( 'db' ) ) );
		$this->bind( 'jobs', static fn ( Plugin $c ) => new \IGBZ\Suite\Support\Jobs\JobQueue( $c->get( 'db' ), $c->get( 'logger' ) ) );
		$this->bind( 'jobs.runner', static fn ( Plugin $c ) => new \IGBZ\Suite\Support\Jobs\QueueRunner( $c->get( 'jobs' ), $c->get( 'logger' ) ) );
		// Phase 29: the durable webhook inbox and the shared payment state machine.
		$this->bind( 'webhooks.inbox', static fn ( Plugin $c ) => new \IGBZ\Suite\Support\Webhooks\WebhookInbox( $c->get( 'db' ), $c->get( 'settings' ), $c->get( 'logger' ) ) );
	}

	public function settings(): Settings {
		return $this->get( 'settings' );
	}

	public function logger(): Logger {
		return $this->get( 'logger' );
	}

	public function db(): Db {
		return $this->get( 'db' );
	}

	public function slo(): \IGBZ\Suite\Support\Observability\Slo {
		return $this->get( 'slo' );
	}

	public function http(): Http {
		return $this->get( 'http' );
	}

	public function tenancy(): \IGBZ\Suite\Modules\MultiTenant\Repository\TenantContext {
		return $this->get( 'tenancy' );
	}
}
