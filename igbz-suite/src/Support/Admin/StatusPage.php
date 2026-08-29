<?php
namespace IGBZ\Suite\Support\Admin;

use IGBZ\Suite\Support\Activator;
use IGBZ\Suite\Support\Capabilities;
use IGBZ\Suite\Support\Cron;
use IGBZ\Suite\Support\Modules;
use IGBZ\Suite\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard / health screen. Every module contributes rows through ModuleInterface::health(),
 * so an unconfigured integration is visible instead of failing silently at runtime.
 */
final class StatusPage {

	public const SLUG = 'igbz';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 8 );
		add_action( 'admin_head', [ $this, 'styles' ] );
	}

	public function add_page(): void {
		Menu::ensure_parent();
		// Same slug as the parent menu: WordPress maps both the top-level entry and
		// this submenu onto the SAME page hook (toplevel_page_igbz). Registering a
		// callback here as well made the page render TWICE (found by the 1406/06/02
		// visual test). Pass an empty callback: the parent's render_static() —
		// registered by Menu::ensure_parent() — remains the single renderer.
		add_submenu_page(
			Menu::SLUG,
			__( 'Status', 'igbz-suite' ),
			__( 'Status', 'igbz-suite' ),
			Capabilities::MANAGE_SUITE,
			self::SLUG,
			''
		);
	}

	public function styles(): void {
		if ( ! Menu::is_igbz_screen() ) {
			return;
		}
		echo '<style>
			.igbz-wrap .igbz-cards{display:flex;flex-wrap:wrap;gap:16px;margin:16px 0}
			.igbz-wrap .igbz-card{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:12px 16px;min-width:170px}
			.igbz-wrap .igbz-card strong{display:block;font-size:22px;line-height:1.4}
			.igbz-wrap .igbz-card span{color:#646970}
			.igbz-wrap table.widefat td{vertical-align:middle}
			.igbz-wrap .igbz-filters{margin:12px 0;display:flex;flex-wrap:wrap;gap:8px;align-items:center}
			.igbz-wrap .igbz-media-grid{display:flex;flex-wrap:wrap;gap:10px;margin:10px 0}
			.igbz-wrap .igbz-media{width:150px;border:1px solid #c3c4c7;border-radius:4px;padding:6px;background:#fff;word-break:break-all;font-size:11px}
			.igbz-wrap .igbz-media img,.igbz-wrap .igbz-media video{width:100%;height:auto;display:block;border-radius:2px;margin-bottom:4px}
			.igbz-wrap .igbz-pre{background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:10px;max-height:320px;overflow:auto;white-space:pre-wrap;word-break:break-word}
			.igbz-wrap .igbz-bars{display:flex;align-items:flex-end;gap:4px;height:150px;margin:12px 0;padding:8px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;overflow-x:auto}
			.igbz-wrap .igbz-bar{flex:1 0 22px;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;height:100%}
			.igbz-wrap .igbz-bar span{display:block;width:100%;background:#2271b1;border-radius:2px 2px 0 0}
			.igbz-wrap .igbz-bar em{font-size:10px;font-style:normal;color:#646970;margin-top:4px}
		</style>';
	}

	/** Fallback callback for the top level menu entry. */
	public static function render_static(): void {
		( new self() )->render();
	}

	public function render(): void {
		Capabilities::require( Capabilities::MANAGE_SUITE );

		$this->maybe_run_cron();

		View::open(
			__( 'IGBZ Suite status', 'igbz-suite' ),
			__( 'Health of every enabled module, its integrations and background jobs.', 'igbz-suite' )
		);

		$this->render_cards();
		$this->render_modules();
		$this->render_cron();
		$this->render_environment();
		$this->render_logs();

		View::close();
	}

	private function maybe_run_cron(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['igbz_run'] ) ) {
			return;
		}
		check_admin_referer( 'igbz_run_cron' );

		$hook   = sanitize_key( wp_unslash( $_GET['igbz_run'] ) );
		$events = Cron::events();
		if ( ! isset( $events[ $hook ] ) ) {
			View::notice( __( 'Unknown job.', 'igbz-suite' ), 'error' );
			return;
		}

		do_action( $hook );
		/* translators: %s: cron hook name. */
		View::notice( sprintf( __( 'Job %s executed.', 'igbz-suite' ), $hook ) );
	}

	private function render_cards(): void {
		$db     = igbz()->db();
		$counts = [];

		foreach (
			[
				'tenants'      => __( 'Tenants', 'igbz-suite' ),
				'subscriptions' => __( 'Subscriptions', 'igbz-suite' ),
				'affiliates'   => __( 'Affiliates', 'igbz-suite' ),
				'enrollments'  => __( 'Enrolments', 'igbz-suite' ),
				'ig_content'   => __( 'IG content', 'igbz-suite' ),
				'ig_subscribers' => __( 'DM subscribers', 'igbz-suite' ),
			] as $table => $label
		) {
			$counts[ $label ] = (int) $db->scalar( 'SELECT COUNT(*) FROM ' . $db->table( $table ) );
		}

		$balance = (float) $db->scalar( 'SELECT COALESCE(SUM(balance),0) FROM ' . $db->table( 'wallet_balances' ) );

		echo '<div class="igbz-cards">';
		foreach ( $counts as $label => $value ) {
			printf(
				'<div class="igbz-card"><strong>%1$s</strong><span>%2$s</span></div>',
				esc_html( number_format_i18n( $value ) ),
				esc_html( $label )
			);
		}
		printf(
			'<div class="igbz-card"><strong>%1$s</strong><span>%2$s</span></div>',
			esc_html( View::money( $balance ) ),
			esc_html__( 'Wallet liability', 'igbz-suite' )
		);
		echo '</div>';
	}

	private function render_modules(): void {
		echo '<h2>' . esc_html__( 'Modules', 'igbz-suite' ) . '</h2>';

		$modules = igbz()->modules();

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th style="width:22%">' . esc_html__( 'Module', 'igbz-suite' ) . '</th>';
		echo '<th style="width:10%">' . esc_html__( 'State', 'igbz-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Checks', 'igbz-suite' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $modules as $id => $module ) {
			$enabled = Modules::enabled( $id );

			echo '<tr><td><strong>' . esc_html( $module->title() ) . '</strong><p class="description">'
				. esc_html( $module->description() ) . '</p></td>';

			echo '<td>' . wp_kses_post( View::status_pill( $enabled ? 'ok' : 'warn' ) ) . '<br />'
				. esc_html( $enabled ? __( 'enabled', 'igbz-suite' ) : __( 'disabled', 'igbz-suite' ) ) . '</td>';

			echo '<td>';
			if ( ! $enabled ) {
				echo '<em>' . esc_html__( 'Not running.', 'igbz-suite' ) . '</em>';
			} else {
				$rows = [];
				try {
					$rows = $module->health();
				} catch ( \Throwable $e ) {
					$rows = [
						[
							'label'  => __( 'Health check failed', 'igbz-suite' ),
							'status' => 'error',
							'detail' => $e->getMessage(),
						],
					];
				}

				echo '<table class="widefat striped" style="margin:0"><tbody>';
				foreach ( $rows as $row ) {
					printf(
						'<tr><td style="width:30%%">%1$s</td><td style="width:80px">%2$s</td><td>%3$s</td></tr>',
						esc_html( (string) ( $row['label'] ?? '' ) ),
						wp_kses_post( View::status_pill( (string) ( $row['status'] ?? 'warn' ) ) ),
						esc_html( (string) ( $row['detail'] ?? '' ) )
					);
				}
				if ( ! $rows ) {
					echo '<tr><td>' . esc_html__( 'No checks reported.', 'igbz-suite' ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	private function render_cron(): void {
		echo '<h2>' . esc_html__( 'Background jobs', 'igbz-suite' ) . '</h2>';

		$rows = [];
		foreach ( Cron::events() as $hook => $recurrence ) {
			$next    = wp_next_scheduled( $hook );
			$rows[] = [
				'hook'       => $hook,
				'recurrence' => $recurrence,
				'next'       => $next
					? sprintf(
						/* translators: %s: human readable time difference. */
						__( 'in %s', 'igbz-suite' ),
						human_time_diff( time(), (int) $next )
					)
					: __( 'not scheduled', 'igbz-suite' ),
				'action'     => sprintf(
					'<a class="button button-small" href="%1$s">%2$s</a>',
					esc_url(
						wp_nonce_url(
							Menu::url( self::SLUG, [ 'igbz_run' => $hook ] ),
							'igbz_run_cron'
						)
					),
					esc_html__( 'Run now', 'igbz-suite' )
				),
			];
		}

		View::table(
			[
				'hook'       => __( 'Hook', 'igbz-suite' ),
				'recurrence' => __( 'Recurrence', 'igbz-suite' ),
				'next'       => __( 'Next run', 'igbz-suite' ),
				'action'     => __( 'Action', 'igbz-suite' ),
			],
			$rows,
			static fn ( array $row, string $key ): string => 'action' === $key
				? (string) $row['action']
				: esc_html( (string) $row[ $key ] )
		);

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			View::notice( __( 'DISABLE_WP_CRON is set. Make sure a real system cron calls wp-cron.php, otherwise scheduling and reminders will not run.', 'igbz-suite' ), 'warning' );
		}
	}

	private function render_environment(): void {
		$db      = igbz()->db();
		$missing = [];
		foreach ( Schema::tables() as $table ) {
			$name = $db->table( $table );
			if ( $name !== (string) $db->scalar( 'SHOW TABLES LIKE %s', $name ) ) {
				$missing[] = $table;
			}
		}

		$rows = [
			[
				'label'  => __( 'Database schema', 'igbz-suite' ),
				'status' => $missing ? 'error' : 'ok',
				'detail' => $missing
					? sprintf(
						/* translators: %s: comma separated table names. */
						__( 'Missing tables: %s. Deactivate and reactivate the plugin.', 'igbz-suite' ),
						implode( ', ', $missing )
					)
					: sprintf(
						/* translators: %d: table count. */
						__( 'All %d tables present.', 'igbz-suite' ),
						count( Schema::tables() )
					),
			],
			[
				'label'  => __( 'Schema version', 'igbz-suite' ),
				'status' => (int) get_option( Activator::VERSION_OPTION, 0 ) === IGBZ_DB_VERSION ? 'ok' : 'warn',
				'detail' => sprintf( '%s / %s', get_option( Activator::VERSION_OPTION, '0' ), IGBZ_DB_VERSION ),
			],
			[
				'label'  => __( 'WooCommerce', 'igbz-suite' ),
				'status' => class_exists( 'WooCommerce' ) ? 'ok' : 'error',
				'detail' => defined( 'WC_VERSION' ) ? (string) WC_VERSION : __( 'not active', 'igbz-suite' ),
			],
			[
				'label'  => __( 'PHP', 'igbz-suite' ),
				'status' => version_compare( PHP_VERSION, '8.1', '>=' ) ? 'ok' : 'error',
				'detail' => PHP_VERSION,
			],
			[
				'label'  => __( 'Encryption', 'igbz-suite' ),
				'status' => function_exists( 'openssl_encrypt' ) ? 'ok' : 'error',
				'detail' => function_exists( 'openssl_encrypt' )
					? __( 'openssl available, secrets are stored as AES-256-GCM.', 'igbz-suite' )
					: __( 'ext-openssl missing: API keys cannot be encrypted at rest.', 'igbz-suite' ),
			],
			[
				'label'  => __( 'Secret salts', 'igbz-suite' ),
				'status' => ( defined( 'AUTH_KEY' ) && '' !== AUTH_KEY && 'put your unique phrase here' !== AUTH_KEY ) ? 'ok' : 'error',
				'detail' => __( 'AUTH_KEY / AUTH_SALT are used to derive the encryption key.', 'igbz-suite' ),
			],
			[
				'label'  => __( 'Permalinks', 'igbz-suite' ),
				'status' => '' !== get_option( 'permalink_structure', '' ) ? 'ok' : 'warn',
				'detail' => '' !== get_option( 'permalink_structure', '' )
					? __( 'Pretty permalinks are on, REST webhook URLs are clean.', 'igbz-suite' )
					: __( 'Plain permalinks: webhook URLs fall back to ?rest_route=.', 'igbz-suite' ),
			],
			[
				'label'  => __( 'HTTPS', 'igbz-suite' ),
				'status' => is_ssl() || str_starts_with( (string) home_url(), 'https://' ) ? 'ok' : 'warn',
				'detail' => __( 'Provider webhooks only call HTTPS callbacks, so the public site must serve over HTTPS.', 'igbz-suite' ),
			],
		];

		echo '<h2>' . esc_html__( 'Environment', 'igbz-suite' ) . '</h2>';
		View::table(
			[
				'label'  => __( 'Check', 'igbz-suite' ),
				'status' => __( 'Status', 'igbz-suite' ),
				'detail' => __( 'Detail', 'igbz-suite' ),
			],
			$rows,
			static fn ( array $row, string $key ): string => 'status' === $key
				? View::status_pill( (string) $row['status'] )
				: esc_html( (string) $row[ $key ] )
		);
	}

	private function render_logs(): void {
		$db   = igbz()->db();
		$rows = $db->results(
			'SELECT created_at, level, channel, message FROM ' . $db->table( 'logs' )
			. " WHERE level IN ('warning','error') ORDER BY id DESC LIMIT 20"
		);

		echo '<h2>' . esc_html__( 'Recent warnings and errors', 'igbz-suite' ) . '</h2>';
		View::table(
			[
				'created_at' => __( 'Time', 'igbz-suite' ),
				'level'      => __( 'Level', 'igbz-suite' ),
				'channel'    => __( 'Channel', 'igbz-suite' ),
				'message'    => __( 'Message', 'igbz-suite' ),
			],
			$rows,
			null,
			__( 'Nothing logged. That is the good outcome.', 'igbz-suite' )
		);
	}
}
