<?php
/**
 * Phase 72 — operator tooling for backups, WP-CLI only.
 *
 *   wp igbz backup create           one encrypted bundle now
 *   wp igbz backup list             bundles on disk (newest first) + last-run stamp
 *   wp igbz backup verify <file>    decrypt + integrity check without restoring
 *   wp igbz backup restore <file>   unpack files, write SQL for review (--apply to execute)
 *
 * @package IGBZ\Suite\Support\Backup
 */

declare( strict_types = 1 );

namespace IGBZ\Suite\Support\Backup;

defined( 'ABSPATH' ) || exit;

final class Cli {

	/** No-op outside WP-CLI; registers the `igbz backup` command otherwise. */
	public static function maybe_register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( '\\WP_CLI' ) ) {
			return;
		}
		\WP_CLI::add_command( 'igbz backup', new self() );
	}

	public static function service(): BackupService {
		return igbz()->get( 'backup' );
	}

	/** @param array<int,mixed> $args @param array<string,mixed> $assoc */
	public function create( array $args = [], array $assoc = [] ): void {
		unset( $args, $assoc );
		$summary = self::service()->create();
		\WP_CLI::success( sprintf(
			'%s (%d bytes) — tables=%d rows=%d files=%d skipped=%d pruned=%d',
			$summary['file'], $summary['bytes'], $summary['tables'], $summary['rows'], $summary['files'], $summary['skipped'], $summary['pruned']
		) );
	}

	/** @param array<int,mixed> $args @param array<string,mixed> $assoc */
	public function list( array $args = [], array $assoc = [] ): void {
		unset( $args, $assoc );
		$bundles = self::service()->list_bundles();
		if ( ! $bundles ) {
			\WP_CLI::log( 'No bundles yet.' );
		}
		foreach ( $bundles as $bundle ) {
			\WP_CLI::log( sprintf( '%s  %d bytes  %s', $bundle['name'], $bundle['bytes'], gmdate( 'Y-m-d H:i:s', $bundle['mtime'] ) ) );
		}
		$age = BackupService::last_backup_age_minutes();
		\WP_CLI::log( 'Last successful backup: ' . ( null === $age ? 'never' : $age . ' minutes ago' ) );
	}

	/** @param array<int,mixed> $args @param array<string,mixed> $assoc */
	public function verify( array $args = [], array $assoc = [] ): void {
		unset( $assoc );
		$file = (string) ( $args[0] ?? '' );
		if ( '' === $file ) {
			\WP_CLI::error( 'Usage: wp igbz backup verify <file>' );
			return;
		}
		$payload = Bundle::decode( (string) file_get_contents( $file ) ); // phpcs:ignore
		if ( null === $payload ) {
			\WP_CLI::error( 'Not a decryptable IGBZ bundle (wrong key or corrupted).' );
			return;
		}
		$errors = Bundle::integrity_errors( $payload );
		if ( $errors ) {
			\WP_CLI::error( 'Integrity errors: ' . implode( '; ', $errors ) );
			return;
		}
		\WP_CLI::success( sprintf( 'Intact — %d files, %d table rows, created %s',
			count( $payload['manifest']['files'] ?? [] ),
			(int) ( $payload['manifest']['db']['rows'] ?? 0 ),
			(string) ( $payload['manifest']['created_at'] ?? '?' ) ) );
	}

	/** @param array<int,mixed> $args @param array<string,mixed> $assoc */
	public function restore( array $args = [], array $assoc = [] ): void {
		$file = (string) ( $args[0] ?? '' );
		if ( '' === $file ) {
			\WP_CLI::error( 'Usage: wp igbz backup restore <file> [--apply]' );
			return;
		}
		$apply = ! empty( $assoc['apply'] );
		if ( ! $apply ) {
			\WP_CLI::log( 'DRY RUN — files are restored, SQL is written for review only. Add --apply to execute it.' );
		}
		$report = self::service()->restore( $file, $apply );
		\WP_CLI::success( sprintf(
			'restored %d files · SQL: %s (%d statements, %d applied)',
			$report['files_restored'], $report['sql_file'], $report['sql_statements'], $report['applied']
		) );
	}
}
