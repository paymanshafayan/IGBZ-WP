<?php
/**
 * Phase 72 — the backup loop: create → verify → tamper-check → restore →
 * retention, with the RPO stamp feeding the SLO panel.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Support\Backup\BackupService;
use IGBZ\Suite\Support\Backup\Bundle;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;
use IGBZ\Suite\Support\Observability\Slo;

final class BackupTest extends TestCase {

	private string $root;
	private string $uploads;

	public function run(): void {
		$this->root    = sys_get_temp_dir() . '/igbz-backup-test-' . substr( bin2hex( random_bytes( 4 ) ), 0, 8 );
		$this->uploads = $this->root . '/uploads';
		mkdir( $this->uploads . '/thumbs', 0777, true );
		mkdir( $this->uploads . '/igbz-backups', 0777, true ); // wp_mkdir_p is a stub in tests
		file_put_contents( $this->uploads . '/a.png', 'image-a' );
		file_put_contents( $this->uploads . '/thumbs/b.jpg', 'image-b' );

		$this->bundle_is_created_encrypted_and_stamped();
		$this->restore_round_trips_files_and_writes_sql_for_review();
		$this->tampered_bundle_is_refused();
		$this->retention_prunes_old_bundles();
		$this->stale_or_missing_backup_is_an_rpo_breach();

		foreach ( glob( $this->root . '/*' ) as $f ) {  // cleanup, best effort
			is_dir( $f ) ? $this->rrmdir( $f ) : unlink( $f );
		}
		@rmdir( $this->root ); // phpcs:ignore
	}

	private function service(): BackupService {
		$settings = igbz_test_reset_settings();
		return new BackupService( new Db(), $settings, new Logger( $settings ), $this->uploads );
	}

	private function bundle_is_created_encrypted_and_stamped(): void {
		$service = $this->service(); // one instance per scenario: reset_settings clears the option store
		$summary = $service->create( [
			'tables' => [ 'orders', 'wallets' ],
			'rows'   => [ 'orders' => [ [ 'id' => 1, 'total' => 12000, 'note' => "it's \"quoted\"\nnewline" ] ], 'wallets' => [] ],
		] );

		$this->assert_same( 2, $summary['tables'], 'both tables visited' );
		$this->assert_same( 1, $summary['rows'], 'empty table contributes zero rows' );
		$this->assert_same( 2, $summary['files'], 'both upload files captured' );

		$blob = (string) file_get_contents( $service->backup_dir() . '/' . $summary['file'] );
		$this->assert_contains( Bundle::MAGIC, substr( $blob, 0, 12 ), 'bundle carries the format magic' );
		$this->assert_not_contains( 'image-a', $blob, 'plaintext never rides in the bundle' );
		$this->assert_not_contains( 'quoted', $blob, 'row data is encrypted too' );

		$last = get_option( BackupService::LAST_OPTION, null );
		$this->assert_same( $summary['file'], (string) $last['file'], 'RPO stamp points at the newest bundle' );
		$this->assert_same( 0, (int) BackupService::last_backup_age_minutes(), 'age clock starts now' );
	}

	private function restore_round_trips_files_and_writes_sql_for_review(): void {
		$service  = $this->service();
		$summary  = $service->create( [
			'tables' => [ 'orders' ],
			'rows'   => [ 'orders' => [ [ 'id' => 7, 'total' => 500 ] ] ],
		] );

		// simulate losing the uploads
		unlink( $this->uploads . '/a.png' );
		unlink( $this->uploads . '/thumbs/b.jpg' );

		$report = $service->restore( $service->backup_dir() . '/' . $summary['file'] );

		$this->assert_same( 2, $report['files_restored'], 'both files back' );
		$this->assert_same( 'image-a', (string) file_get_contents( $this->uploads . '/a.png' ), 'content intact' );
		$this->assert_same( 'image-b', (string) file_get_contents( $this->uploads . '/thumbs/b.jpg' ), 'nested path intact' );
		$this->assert_same( 0, $report['applied'], 'dry run touches no database' );
		$this->assert_same( 1, $report['sql_statements'], 'one INSERT batch for review' );
		$this->assert_contains( 'INSERT INTO', (string) file_get_contents( $report['sql_file'] ), 'SQL written beside the bundle' );
	}

	private function tampered_bundle_is_refused(): void {
		$service = $this->service();
		$summary = $service->create( [ 'tables' => [], 'rows' => [] ] );

		// flip one byte inside the ciphertext
		$path   = $service->backup_dir() . '/' . $summary['file'];
		$blob   = (string) file_get_contents( $path );
		$blob[ strlen( $blob ) - 5 ] = $blob[ strlen( $blob ) - 5 ] === 'A' ? 'B' : 'A';
		file_put_contents( $path, $blob );

		$refused = false;
		try {
			$service->restore( $path );
		} catch ( \RuntimeException $e ) {
			$refused = str_contains( $e->getMessage(), 'decryptable' ) || str_contains( $e->getMessage(), 'integrity' );
		}
		$this->assert_same( true, $refused, 'a tampered bundle never reaches the filesystem or database' );
	}

	private function retention_prunes_old_bundles(): void {
		$settings = igbz_test_reset_settings();
		$settings->set( 'backup.retention', 2 );
		$service = new BackupService( new Db(), $settings, new Logger( $settings ), $this->uploads );

		// two hand-made stale bundles (same-second collisions between the earlier
		// scenarios can legally produce one shared filename, so the precondition
		// must not depend on them)
		$dir = $service->backup_dir();
		foreach ( [ '20000101-000000', '20000102-000000' ] as $stamp ) {
			file_put_contents( $dir . '/' . BackupService::FILE_PREFIX . $stamp . BackupService::FILE_SUFFIX, 'x' );
			touch( $dir . '/' . BackupService::FILE_PREFIX . $stamp . BackupService::FILE_SUFFIX, time() - 9999 );
		}

		$this->assert_same( true, count( $service->list_bundles() ) >= 3, 'precondition: at least three bundles' );
		$pruned = $service->prune();
		$this->assert_same( true, $pruned >= 1, 'oldest bundle removed' );
		$this->assert_same( true, count( $service->list_bundles() ) <= 2, 'retention=2 respected' );
	}

	private function stale_or_missing_backup_is_an_rpo_breach(): void {
		$GLOBALS['wpdb']->next_results = [ null, null, null, null ]; // quiet jobs/logs
		$slo = new Slo( new Db(), igbz_test_reset_settings() ); // one reset, then only the stamp changes

		// never backed up
		delete_option( BackupService::LAST_OPTION );
		$report = $slo->report();
		$names  = array_column( $report['breaches'], 'slo' );
		$this->assert_contains( 'slo.max_backup_hours', implode( ',', $names ), 'no backup at all is an RPO breach' );
		$this->assert_same( 'backup-stale', $report['breaches'][ count( $names ) - 1 ]['action'], 'breach names its runbook action' );

		// backed up 27h ago (threshold 26h)
		update_option( BackupService::LAST_OPTION, [ 't' => time() - 27 * HOUR_IN_SECONDS ] );
		$report = $slo->report();
		$this->assert_contains( 'slo.max_backup_hours', implode( ',', array_column( $report['breaches'], 'slo' ) ), '27h-old backup breaches' );

		// fresh backup: no backup breach
		update_option( BackupService::LAST_OPTION, [ 't' => time() - 60 ] );
		$report = $slo->report();
		$this->assert_not_contains( 'slo.max_backup_hours', implode( ',', array_column( $report['breaches'], 'slo' ) ), 'fresh backup is green' );
	}

	private function rrmdir( string $dir ): void {
		foreach ( glob( $dir . '/*' ) ?: [] as $f ) {
			is_dir( $f ) ? $this->rrmdir( $f ) : unlink( $f );
		}
		rmdir( $dir );
	}
}
