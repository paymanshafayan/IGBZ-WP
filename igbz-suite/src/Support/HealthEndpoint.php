<?php
namespace IGBZ\Suite\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 70 — the product's own health/readiness endpoint.
 *
 * GET /?igbz_health=1 answers one cheap JSON document any orchestrator
 * (Railway healthcheck, uptime probe, deploy script) can gate on:
 *
 *   200 { ok: true,  ... } — the store is serving: the database answers and
 *                            the suite booted with its full schema.
 *   503 { ok: false, ... } — anything else; the container must not receive
 *                            traffic and the deploy must not pass.
 *
 * `degraded: true` (with 200) means "serving, but something is worth an
 * alarm": a schema/migration drift or a missing module — readiness and
 * drift are deliberately different questions.
 *
 * The endpoint is intentionally unauthenticated (it leaks no secrets — only
 * versions and counts), cache-disabled and O(1) apart from the per-table
 * SHOW TABLES inventory, which runs only when everything else is already
 * healthy.
 */
final class HealthEndpoint {

	public const QUERY_VAR = 'igbz_health';

	public function __construct(
		private Db $db,
		/** @var callable():int */
		private $table_count_provider,
	) {}

	public static function register(): void {
		add_action( 'init', [ new self( igbz()->db(), static fn (): int => self::count_tables() ), 'handle' ], 99 );
	}

	/**
	 * Answer the request when (and only when) it is the health probe.
	 * Registered late so an early fatal never gets a green answer from us.
	 */
	public function handle(): void {
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}

		$snapshot = $this->snapshot();

		status_header( $snapshot['status'] );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		echo wp_json_encode( $snapshot['data'], JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * One health document. Everything a deploy decision needs, nothing a
	 * stranger could use: versions, counts, and the pass/fail flags.
	 *
	 * @return array{ok:bool,status:int,data:array<string,mixed>}
	 */
	public function snapshot(): array {
		$db_ok = null !== $this->db->scalar( 'SELECT 1' );

		$data = [
			'wp'          => get_bloginfo( 'version' ),
			'php'         => PHP_VERSION,
			'db'          => $db_ok,
			'igbz_loaded' => defined( 'IGBZ_VERSION' ),
			'igbz_dbv'    => (int) get_option( 'igbz_db_version', 0 ),
			'wc_active'   => class_exists( 'WooCommerce' ),
			'wc_version'  => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			'active'      => (array) get_option( 'active_plugins', [] ),
			'modules'     => get_option( 'igbz_enabled_modules' ),
		];

		if ( class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) ) {
			$data['hpos'] = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}

		$ok   = $db_ok && $data['igbz_loaded'];
		$data['ok'] = $ok;

		if ( $ok ) {
			$expected        = count( Schema::tables() );
			$found           = ( $this->table_count_provider )();
			$data['igbz_tables'] = $found . '/' . $expected;
			$data['schema_expected_dbv'] = IGBZ_DB_VERSION;

			// Serving with drift is still serving — but it must be visible.
			$data['degraded'] = ( $found !== $expected ) || ( $data['igbz_dbv'] !== (int) IGBZ_DB_VERSION );
		} else {
			$data['degraded'] = true;
		}

		return [
			'ok'     => $ok,
			'status' => $ok ? 200 : 503,
			'data'   => $data,
		];
	}

	/** Real table inventory: how many of the suite's tables exist right now. */
	private static function count_tables(): int {
		global $wpdb;
		$count = 0;
		foreach ( Schema::tables() as $name ) {
			$full = $wpdb->prefix . 'igbz_' . $name;
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $found ) {
				++$count;
			}
		}

		return $count;
	}
}
