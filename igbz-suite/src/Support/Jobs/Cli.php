<?php
/**
 * Phase 27 — operator tooling for the durable queue, available under WP-CLI only.
 *
 *   wp igbz jobs stats            backlog by status + oldest waiting job
 *   wp igbz jobs dead             the dead-letter backlog
 *   wp igbz jobs replay <id>      re-queue one dead-lettered job (same idempotency key)
 *   wp igbz jobs drain [--budget] drain due jobs now (default budget 100)
 *
 * @package IGBZ\Suite\Support\Jobs
 */

declare( strict_types = 1 );

namespace IGBZ\Suite\Support\Jobs;

defined( 'ABSPATH' ) || exit;

final class Cli {

	/** No-op outside WP-CLI; registers the `igbz jobs` command otherwise. */
	public static function maybe_register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( '\WP_CLI' ) ) {
			return;
		}
		\WP_CLI::add_command( 'igbz jobs', new self() );
	}

	/** @param array<int,mixed> $args @param array<string,mixed> $assoc */
	public function stats( array $args = [], array $assoc = [] ): void {
		unset( $args, $assoc );
		$stats = igbz()->get( 'jobs' )->stats();
		foreach ( $stats as $key => $value ) {
			\WP_CLI::log( sprintf( '%s: %d', $key, (int) $value ) );
		}
	}

	/** @param array<int,mixed> $args @param array<string,mixed> $assoc */
	public function dead( array $args = [], array $assoc = [] ): void {
		unset( $args, $assoc );
		$rows = igbz()->get( 'jobs' )->dead_letters( 50 );
		if ( ! $rows ) {
			\WP_CLI::success( 'No dead-lettered jobs.' );
			return;
		}
		foreach ( $rows as $row ) {
			\WP_CLI::log(
				sprintf(
					'#%d %s tenant=%d attempts=%d — %s',
					(int) $row['id'],
					(string) $row['job_type'],
					(int) $row['tenant_id'],
					(int) $row['attempts'],
					(string) ( $row['last_error'] ?? '' )
				)
			);
		}
	}

	/** @param array<int,mixed> $args @param array<string,mixed> $assoc */
	public function replay( array $args = [], array $assoc = [] ): void {
		unset( $assoc );
		$job_id = (int) ( $args[0] ?? 0 );
		if ( $job_id <= 0 ) {
			\WP_CLI::error( 'Usage: wp igbz jobs replay <job-id>' );
			return;
		}
		if ( igbz()->get( 'jobs' )->replay( $job_id ) ) {
			\WP_CLI::success( sprintf( 'Job %d is queued again.', $job_id ) );
		} else {
			\WP_CLI::error( sprintf( 'Job %d is not dead-lettered.', $job_id ) );
		}
	}

	/** @param array<int,mixed> $args @param array<string,mixed> $assoc */
	public function drain( array $args = [], array $assoc = [] ): void {
		unset( $args );
		$budget = max( 1, (int) ( $assoc['budget'] ?? QueueRunner::DEFAULT_JOB_BUDGET ) );
		$totals = igbz()->get( 'jobs.runner' )->run( $budget );
		\WP_CLI::success(
			sprintf( 'done=%d failed=%d dead=%d rounds=%d', $totals['done'], $totals['failed'], $totals['dead'], $totals['rounds'] )
		);
	}
}
