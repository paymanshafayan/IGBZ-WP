<?php
namespace IGBZ\Suite\Support\Observability;

use IGBZ\Suite\Support\Backup\BackupService;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 71 — SLO evaluation over data the suite already keeps (jobs + logs).
 *
 * These are deliberately *outcome* indicators a shop owner can act on, not
 * vanity graphs: are background jobs succeeding, is the queue draining, and
 * has the error rate crossed the line where a human should look? Thresholds
 * are settings (`slo.*`) so operations can tune them without a deploy, and
 * every breach names the runbook section that tells the on-call what to do.
 */
final class Slo {

	public const DEFAULTS = [
		'slo.job_success_rate'  => 0.98,  // ≥ 98% of finished jobs succeed (24h)
		'slo.max_pending'       => 50,    // jobs waiting, all queues
		'slo.max_wait_minutes'  => 15,    // oldest pending job age
		'slo.max_errors_24h'    => 25,    // error-level log lines per day
		'slo.max_backup_hours'  => 26,    // RPO: hours since the last verified backup
	];

	public function __construct( private Db $db, private Settings $settings ) {}

	/**
	 * @return array{metrics:array<string,int|float>, breaches:array<int,array{slo:string,value:string,threshold:string,action:string}>, ok:bool}
	 */
	public function report(): array {
		$metrics = $this->metrics();
		$breaches = [];

		$finished = $metrics['jobs_done_24h'] + $metrics['jobs_failed_24h'] + $metrics['jobs_dead_24h'];
		$rate     = $finished > 0 ? $metrics['jobs_done_24h'] / $finished : 1.0;
		$floor    = (float) $this->settings->float( 'slo.job_success_rate', self::DEFAULTS['slo.job_success_rate'] );

		if ( $finished > 0 && $rate < $floor ) {
			$breaches[] = self::breach( 'slo.job_success_rate', round( $rate * 100, 1 ) . '%', round( $floor * 100, 1 ) . '%', 'jobs-failures' );
		}
		$max_pending = (int) $this->settings->int( 'slo.max_pending', self::DEFAULTS['slo.max_pending'] );
		if ( $metrics['jobs_pending'] > $max_pending ) {
			$breaches[] = self::breach( 'slo.max_pending', (string) $metrics['jobs_pending'], (string) $max_pending, 'queue-backlog' );
		}
		$max_wait = (int) $this->settings->int( 'slo.max_wait_minutes', self::DEFAULTS['slo.max_wait_minutes'] );
		if ( $metrics['oldest_pending_minutes'] > $max_wait ) {
			$breaches[] = self::breach( 'slo.max_wait_minutes', (string) $metrics['oldest_pending_minutes'], (string) $max_wait, 'queue-stalled' );
		}
		$max_errors = (int) $this->settings->int( 'slo.max_errors_24h', self::DEFAULTS['slo.max_errors_24h'] );
		if ( $metrics['log_errors_24h'] > $max_errors ) {
			$breaches[] = self::breach( 'slo.max_errors_24h', (string) $metrics['log_errors_24h'], (string) $max_errors, 'error-storm' );
		}

		// Phase 72 — the RPO indicator: a backup strategy that silently stops
		// backing up is a liability, not an asset, so staleness is a breach.
		$max_backup_age = (int) $this->settings->int( 'slo.max_backup_hours', self::DEFAULTS['slo.max_backup_hours'] ) * HOUR_IN_SECONDS;
		$age_minutes    = BackupService::last_backup_age_minutes();
		if ( null === $age_minutes ) {
			$breaches[] = self::breach( 'slo.max_backup_hours', 'never', (string) (int) ( $max_backup_age / HOUR_IN_SECONDS ) . 'h', 'backup-stale' );
		} elseif ( $age_minutes * MINUTE_IN_SECONDS > $max_backup_age ) {
			$breaches[] = self::breach( 'slo.max_backup_hours', (string) $age_minutes . ' min', (string) (int) ( $max_backup_age / HOUR_IN_SECONDS ) . 'h', 'backup-stale' );
		}

		return [ 'metrics' => $metrics, 'breaches' => $breaches, 'ok' => [] === $breaches ];
	}

	/**
	 * @return array<string,int|float|null>
	 */
	public function metrics(): array {
		$jobs  = $this->db->table( 'jobs' );
		$logs  = $this->db->table( 'logs' );
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$counts = $this->db->row(
			"SELECT
				COALESCE(SUM(status = 'done'), 0)    AS done_24h,
				COALESCE(SUM(status = 'failed'), 0)  AS failed_24h,
				COALESCE(SUM(status = 'dead'), 0)    AS dead_24h
			 FROM {$jobs} WHERE updated_at >= %s",
			$since
		) ?? [];

		$pending = (int) $this->db->scalar(
			"SELECT COUNT(*) FROM {$jobs} WHERE status = 'pending' AND available_at <= %s",
			gmdate( 'Y-m-d H:i:s' )
		);

		// A pending job whose availability is in the future is just delayed — the
		// queue isn't "behind" until a due job has been sitting there unheard.
		$oldest = $this->db->scalar(
			"SELECT MIN(available_at) FROM {$jobs} WHERE status = 'pending' AND available_at <= %s",
			gmdate( 'Y-m-d H:i:s' )
		);

		$errors = (int) $this->db->scalar(
			"SELECT COUNT(*) FROM {$logs} WHERE level = 'error' AND created_at >= %s",
			$since
		);

		return [
			'jobs_done_24h'          => (int) ( $counts['done_24h'] ?? 0 ),
			'jobs_failed_24h'        => (int) ( $counts['failed_24h'] ?? 0 ),
			'jobs_dead_24h'          => (int) ( $counts['dead_24h'] ?? 0 ),
			'jobs_pending'           => $pending,
			'oldest_pending_minutes' => $oldest ? (int) floor( ( time() - strtotime( (string) $oldest ) ) / MINUTE_IN_SECONDS ) : 0,
			'log_errors_24h'         => $errors,
			'backup_age_minutes'     => BackupService::last_backup_age_minutes(),
		];
	}

	/** @return array{slo:string,value:string,threshold:string,action:string} */
	private static function breach( string $slo, string $value, string $threshold, string $action ): array {
		return [ 'slo' => $slo, 'value' => $value, 'threshold' => $threshold, 'action' => $action ];
	}
}
