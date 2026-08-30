<?php
/**
 * Phase 73 — release tooling, WP-CLI only.
 *
 *   wp igbz release verify [--url=] [--tries=6] [--sleep=5]
 *
 *     Polls the product health endpoint and exits non-zero on red — the
 *     signal a deploy pipeline (or a canary cron) uses to roll back.
 *
 * @package IGBZ\Suite\Support\Release
 */

declare( strict_types = 1 );

namespace IGBZ\Suite\Support\Release;

defined( 'ABSPATH' ) || exit;

final class Cli {

	/** No-op outside WP-CLI; registers the `igbz release` command otherwise. */
	public static function maybe_register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( '\\WP_CLI' ) ) {
			return;
		}
		\WP_CLI::add_command( 'igbz release', new self() );
	}

	/** @param array<int,mixed> $args @param array<string,mixed> $assoc */
	public function verify( array $args = [], array $assoc = [] ): void {
		unset( $args );
		$url   = (string) ( $assoc['url'] ?? home_url( '/?igbz_health=1' ) );
		$tries = max( 1, (int) ( $assoc['tries'] ?? 6 ) );
		$sleep = max( 0, (int) ( $assoc['sleep'] ?? 5 ) );

		$fetch = static function ( string $probe ): array {
			$response = wp_remote_get( $probe, [ 'timeout' => 10, 'sslverify' => true ] ); // phpcs:ignore
			if ( is_wp_error( $response ) ) { // phpcs:ignore
				return [ 'code' => 0, 'body' => '' ];
			}
			return [
				'code' => (int) wp_remote_retrieve_response_code( $response ), // phpcs:ignore
				'body' => (string) wp_remote_retrieve_body( $response ), // phpcs:ignore
			];
		};

		$verdict = ( new ReleaseGate() )->verify( $fetch, $url, [ 'tries' => $tries, 'sleep' => $sleep ] );

		\WP_CLI::log( sprintf(
			'release gate: %s (code %d, attempt %d/%d)',
			$verdict['state'], $verdict['last_code'], $verdict['attempts'], $tries
		) );

		if ( 'green' === $verdict['state'] ) {
			\WP_CLI::success( 'Release verified — announce it.' );
			return;
		}
		if ( 'degraded' === $verdict['state'] ) {
			\WP_CLI::warning( 'Serving, but schema drift is visible — open RUNBOOK-RELEASE-ROLLBACK.md §drift.' );
			return;
		}
		\WP_CLI::error( 'RED — do not announce; roll back (RUNBOOK-RELEASE-ROLLBACK.md §auto).' );
	}
}
