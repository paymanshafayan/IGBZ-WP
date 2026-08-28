<?php
namespace IGBZ\Suite\Modules\MultiTenant\Admin;

use IGBZ\Suite\Support\Admin\Menu;
use IGBZ\Suite\Support\Admin\View;
use IGBZ\Suite\Support\Capabilities;
use IGBZ\Suite\Support\Jobs\JobQueue;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 27 — the queue dashboard: backlog by status, the age of the oldest waiting job (a drain
 * that stopped keeping up shows here first) and the dead-letter backlog with a controlled
 * replay button. Replay keeps the job's idempotency key — replay IS the same logical operation.
 */
final class JobsPage {

	public const SLUG = 'igbz-jobs';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 17 );
	}

	public function add_page(): void {
		Menu::add( self::SLUG, __( 'Job queue', 'igbz-suite' ), [ $this, 'render' ], Capabilities::MANAGE_SUITE );
	}

	public function render(): void {
		$this->handle_post();

		$jobs = igbz()->get( 'jobs' );

		View::open(
			__( 'Job queue', 'igbz-suite' ),
			__( 'Backlog, age and dead letters of the durable queue.', 'igbz-suite' )
		);

		$stats = $jobs->stats();
		$age   = (int) $stats['oldest_pending_age_seconds'];

		echo '<table class="widefat striped" style="max-width:560px"><thead><tr><th>' . esc_html__( 'Status', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Jobs', 'igbz-suite' ) . '</th></tr></thead><tbody>';
		foreach ( [ 'pending', 'claimed', 'done', 'dead', 'cancelled' ] as $status ) {
			printf(
				'<tr><td>%s</td><td>%d</td></tr>',
				esc_html( $status ),
				(int) $stats[ $status ]
			);
		}
		printf(
			'<tr><td>%s</td><td>%s</td></tr>',
			esc_html__( 'Oldest waiting job', 'igbz-suite' ),
			esc_html( $age > 0 ? human_time_diff( time() - $age ) : '—' )
		);
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Dead letters', 'igbz-suite' ) . '</h2>';
		$dead = $jobs->dead_letters( 30 );
		if ( ! $dead ) {
			echo '<p>' . esc_html__( 'No dead-lettered jobs.', 'igbz-suite' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__( 'Type', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Tenant', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Attempts', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Reason', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Replay', 'igbz-suite' ) . '</th></tr></thead><tbody>';
			foreach ( $dead as $row ) {
				printf(
					'<tr><td>%1$d</td><td>%2$s</td><td>%3$d</td><td>%4$d</td><td>%5$s</td><td><form method="post">%6$s<input type="hidden" name="igbz_jobs_action" value="replay" /><input type="hidden" name="job_id" value="%1$d" />%7$s</form></td></tr>',
					(int) $row['id'],
					esc_html( (string) $row['job_type'] ),
					(int) $row['tenant_id'],
					(int) $row['attempts'],
					esc_html( (string) ( $row['last_error'] ?? '' ) ),
					wp_nonce_field( 'igbz_jobs_replay', '_wpnonce', true, false ),
					get_submit_button( __( 'Replay', 'igbz-suite' ), 'small', 'submit', false )
				);
			}
			echo '</tbody></table>';
		}

		View::close();
	}

	private function handle_post(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$action = isset( $_POST['igbz_jobs_action'] ) ? sanitize_key( (string) $_POST['igbz_jobs_action'] ) : '';
		if ( 'replay' !== $action ) {
			return;
		}
		View::check_nonce( 'igbz_jobs_replay' );

		$job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;
		$ok     = $job_id > 0 && igbz()->get( 'jobs' )->replay( $job_id );
		View::notice(
			$ok
				? sprintf( /* translators: %d: job id */ __( 'Job %d is queued again.', 'igbz-suite' ), $job_id )
				: __( 'Only dead-lettered jobs can be replayed.', 'igbz-suite' ),
			$ok ? 'success' : 'error'
		);
	}
}
