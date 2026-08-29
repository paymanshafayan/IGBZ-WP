<?php
namespace IGBZ\Suite\Support;

use IGBZ\Suite\Support\Jobs\JobQueue;

defined( 'ABSPATH' ) || exit;

/**
 * Central scheduler. Individual modules attach their handlers to these hooks so a disabled
 * module simply has nothing listening.
 */
final class Cron {

	public const HOOK_FIVE_MINUTES = 'igbz_cron_five_minutes';
	public const HOOK_HOURLY       = 'igbz_cron_hourly';
	public const HOOK_DAILY        = 'igbz_cron_daily';

	/** @return array<string,string> hook => recurrence */
	public static function events(): array {
		return [
			self::HOOK_FIVE_MINUTES => 'igbz_five_minutes',
			self::HOOK_HOURLY       => 'hourly',
			self::HOOK_DAILY        => 'daily',
		];
	}

	public function register(): void {
		self::register_schedules();
		add_action( self::HOOK_DAILY, [ $this, 'housekeeping' ] );

		// Phase 24: background work lives in the durable queue; the five-minute beat drains it.
		igbz()->get( 'jobs.runner' )->register();

		// Phase 26: housekeeping itself is a queued job, drained by the same daily beat.
		igbz()->get( 'jobs' )->register( 'cron.housekeeping', [ $this, 'run_housekeeping' ] );

		// Phase 27: operator tooling — a no-op outside WP-CLI.
		Jobs\Cli::maybe_register();
	}

	/**
	 * Register the custom recurrences on the `cron_schedules` filter.
	 *
	 * This is deliberately static and separate from register() so it can run at plugin
	 * load time, before `plugins_loaded`. Activation happens on a request where the
	 * plugin file is included *after* `plugins_loaded` has already fired, so anything
	 * that only hooks itself inside `plugins_loaded` is absent when
	 * Activator::schedule_events() runs — and wp_schedule_event() silently returns
	 * false for an unknown recurrence, leaving the five-minute event unscheduled.
	 *
	 * add_filter() de-duplicates identical callbacks at the same priority, so calling
	 * this more than once is harmless.
	 */
	public static function register_schedules(): void {
		add_filter( 'cron_schedules', [ self::class, 'add_schedules' ] ); // phpcs:ignore WordPress.WP.CronInterval
	}

	/**
	 * @param array<string,array{interval:int,display:string}> $schedules
	 * @return array<string,array{interval:int,display:string}>
	 */
	public static function add_schedules( array $schedules ): array {
		// `cron_schedules` is not an `init`-or-later filter: anything that calls
		// wp_get_schedules() / wp_schedule_event() during `plugins_loaded` fires it early
		// (Jetpack's Nonce_Handler does exactly this, and so does our own activation path).
		// Calling __() there forces a just-in-time textdomain load, which WordPress 6.7+
		// reports as a `_load_textdomain_just_in_time` doing-it-wrong notice. Falling back
		// to the English label before `init` costs nothing: the only consumer of `display`
		// is the cron admin UI, which always runs later.
		$translate = did_action( 'init' ) > 0;

		$schedules['igbz_five_minutes'] = [
			'interval' => 300,
			'display'  => $translate
				? __( 'Every five minutes (IGBZ)', 'igbz-suite' )
				: 'Every five minutes (IGBZ)',
		];
		$schedules['igbz_fifteen_minutes'] = [
			'interval' => 900,
			'display'  => $translate
				? __( 'Every fifteen minutes (IGBZ)', 'igbz-suite' )
				: 'Every fifteen minutes (IGBZ)',
		];
		return $schedules;
	}

	public function housekeeping(): void {
		// Phase 26: the beat only enqueues; the daily slot key absorbs duplicate beats.
		igbz()->get( 'jobs' )->enqueue( 'cron.housekeeping', [], [ 'idempotency_key' => JobQueue::slot( DAY_IN_SECONDS ) ] );
	}

	/** Phase 26: the actual housekeeping body, executed as a leased, retriable queued job. */
	public function run_housekeeping(): void {
		$settings = igbz()->settings();
		igbz()->logger()->prune( $settings->int( 'log.retention_days', 30 ) );

		// Phase 57: pending approval requests whose decision window passed expire honestly.
		if ( igbz()->has( 'pado.approvals' ) ) {
			igbz()->get( 'pado.approvals' )->expire_due();
		}

		// Phase 20: bounded batches — a grown-out table must not lock the site during
		// housekeeping; whatever is left carries over to tomorrow's run.
		$db = igbz()->db();
		$db->delete_batches( 'otp_codes', 'expires_at < %s', [ gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ] );
		$db->delete_batches( 'api_tokens', 'expires_at < %s AND ( refresh_expires_at IS NULL OR refresh_expires_at < %s )', [ gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s' ) ] );
	}
}
