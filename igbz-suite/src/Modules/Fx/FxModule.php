<?php
namespace IGBZ\Suite\Modules\Fx;

use IGBZ\Suite\Modules\Fx\Admin\FxPage;
use IGBZ\Suite\Modules\Fx\Providers\PstNetPayoutAdapter;
use IGBZ\Suite\Modules\Fx\Providers\RedotPayPayoutAdapter;
use IGBZ\Suite\Support\Cron;
use IGBZ\Suite\Support\Jobs\JobContext;
use IGBZ\Suite\Support\Jobs\JobQueue;
use IGBZ\Suite\Support\ModuleInterface;
use IGBZ\Suite\Support\Modules;
use IGBZ\Suite\Support\Plugin;

/**
 * FX payment gateway — the foreign-currency intermediary.
 *
 * Store admins without a foreign card top up a USD credit wallet with Rials
 * (existing Iranian gateways, +fx.fee_percent on top of the USD amount, per
 * the client's rule). The actual Manus/ManyChat bills are paid by the
 * operator's payout adapter. The module never queues a task: it only gates a
 * tenant's own credit at dispatch time.
 *
 * Off by default; `multitenant` must be enabled for the Rial top-ups, and
 * `instagram` for the Manus meter.
 */

defined( 'ABSPATH' ) || exit;

/**
 * FX payment gateway — the foreign-currency intermediary.
 *
 * Store admins without a foreign card top up a USD credit wallet with Rials
 * (existing Iranian gateways, +fx.fee_percent on top of the USD amount, per
 * the client's rule). The actual Manus/ManyChat bills are paid by the
 * operator's payout adapter. The module never queues a task: it only gates a
 * tenant's own credit at dispatch time.
 *
 * Off by default; `multitenant` must be enabled for the Rial top-ups, and
 * `instagram` for the Manus meter.
 */
final class FxModule implements ModuleInterface {

	/** Phase 26: settlement batch (must match FxBillingService::due_bills' default limit). */
	private const SETTLE_BATCH = 50;

	/** Phase 26: continuation rounds per day — caps the worst-case loop. */
	private const MAX_SETTLE_ROUNDS = 10;

	public function id(): string {
		return Modules::FX;
	}

	public function title(): string {
		return __( 'FX payments', 'igbz-suite' );
	}

	public function description(): string {
		return __( 'Foreign-currency intermediary: Rial top-ups for a USD credit wallet, automatic payout adapter, and per-task credit gating for Manus.', 'igbz-suite' );
	}

	public function register( Plugin $plugin ): void {
		$this->bind_services( $plugin );

		$topup = $plugin->get( 'fx.topup' );
		add_action( 'igbz_payment_verified', [ $topup, 'on_payment_verified' ], 10, 2 );

		// Phase 26: the daily FX sweep runs as queued jobs (billed, settled and funded by the
		// same daily beat's drain) instead of blocking the shared daily cron request.
		add_action( Cron::HOOK_DAILY, [ $this, 'run_daily' ] );
		$this->register_queue_handlers( $plugin->get( 'jobs' ) );

		( new FxPage() )->register();
	}

	public function run_daily(): void {
		$jobs = igbz()->get( 'jobs' );
		$slot = JobQueue::slot( DAY_IN_SECONDS );
		foreach ( [ 'fx.billing.bills', 'fx.billing.settle', 'fx.ramp.fund' ] as $job_type ) {
			$jobs->enqueue( $job_type, [], [ 'idempotency_key' => $slot ] );
		}
	}

	/** Phase 26: handler wiring for the queued daily FX jobs. */
	public function register_queue_handlers( JobQueue $jobs ): void {
		$jobs->register( 'fx.billing.bills', static function (): void {
			igbz()->get( 'fx.billing' )->bill_accounts();
		} );
		$jobs->register( 'fx.billing.settle', function ( array $payload, JobContext $ctx ) use ( $jobs ): void {
			$processed = igbz()->get( 'fx.billing' )->settle_due( self::SETTLE_BATCH );
			$jobs->continue_round( $ctx, $payload, 'fx.billing.settle', $processed, self::SETTLE_BATCH, self::MAX_SETTLE_ROUNDS );
		} );
		// After the billing sweep, keep the payout card funded so the next
		// sweep can spend it (Rial -> USDT -> card, via the exchange ramp).
		$jobs->register( 'fx.ramp.fund', static function (): void {
			igbz()->get( 'fx.ramp' )->ensure_card_funded();
		} );
	}

	private function bind_services( Plugin $plugin ): void {
		$plugin->bind( 'fx.wallet', static fn ( Plugin $c ) => new FxWalletService( $c->get( 'db' ) ) );
		$plugin->bind( 'fx.rates', static fn ( Plugin $c ) => new FxRateService( $c->get( 'db' ), $c->settings(), $c->get( 'http' ) ) );
		$plugin->bind( 'fx.meter', static fn ( Plugin $c ) => new FxMeter( $c->get( 'db' ), $c->get( 'fx.wallet' ), $c->logger() ) );
		$plugin->bind(
			'fx.topup',
			static fn ( Plugin $c ) => new FxTopupService(
				$c->get( 'db' ),
				$c->settings(),
				$c->get( 'payments' ),
				$c->get( 'fx.wallet' ),
				$c->get( 'fx.rates' ),
				$c->logger()
			)
		);
		$plugin->bind( 'fx.accounts', static fn ( Plugin $c ) => new FxAccountsService( $c->get( 'db' ) ) );

		$registry = new FxPayoutRegistry();
		$registry->register( new PstNetPayoutAdapter( $plugin->settings(), $plugin->get( 'http' ), $plugin->logger() ) );
		$registry->register( new RedotPayPayoutAdapter( $plugin->settings(), $plugin->get( 'http' ), $plugin->logger() ) );
		do_action( 'igbz_register_fx_payout_providers', $registry );
		$plugin->bind( 'fx.payouts', static fn () => $registry );

		$plugin->bind(
			'fx.billing',
			static fn ( Plugin $c ) => new FxBillingService(
				$c->get( 'db' ),
				$c->settings(),
				$c->get( 'fx.wallet' ),
				$c->get( 'fx.meter' ),
				$c->get( 'fx.payouts' ),
				$c->get( 'fx.accounts' ),
				$c->logger()
			)
		);
		$plugin->bind( 'fx.ramp', static fn ( Plugin $c ) => new FxRampService( $c->get( 'db' ), $c->settings(), $c->get( 'fx.payouts' ), $c->logger() ) );
		$plugin->bind( 'fx.reports', static fn ( Plugin $c ) => new FxReportsService( $c->get( 'db' ) ) );
	}

	/** @return array<int,array{label:string,status:string,detail:string}> */
	public function health(): array {
		$rows   = [];
		$rates  = igbz()->get( 'fx.rates' );
		$wallet = igbz()->get( 'fx.wallet' );

		$rate = $rates->current();
		if ( $rate > 0 ) {
			$rows[] = [ 'label' => 'FX rate', 'status' => 'ok', 'detail' => sprintf( '%s IRT/USD', number_format( $rate, 0 ) ) ];
		} else {
			$rows[] = [ 'label' => 'FX rate', 'status' => 'warn', 'detail' => __( 'No rate configured — top-ups are refused.', 'igbz-suite' ) ];
		}

		$payouts = igbz()->get( 'fx.payouts' );
		$active  = $payouts->active();
		if ( $active && $active->is_configured() ) {
			$rows[] = [ 'label' => 'FX payout', 'status' => 'ok', 'detail' => $active->title() ];
		} else {
			$rows[] = [ 'label' => 'FX payout', 'status' => 'warn', 'detail' => __( 'No payout adapter configured — bills cannot be paid automatically yet.', 'igbz-suite' ) ];
		}

		return $rows;
	}
}
