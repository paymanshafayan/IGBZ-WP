<?php
namespace IGBZ\Suite\Support\Backup;

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;
use IGBZ\Suite\Support\Schema;
use IGBZ\Suite\Support\Settings;
use IGBZ\Suite\Support\Trace;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 72 — encrypted, restorable backups of the product's own data.
 *
 * What goes in: a logical dump (INSERTs) of the suite tables, the settings
 * document, and uploads files under a size cap — DB first, files second
 * (the order the WP admin handbook recommends), all inside one AES-256-GCM
 * envelope. What stays out: WordPress core, other plugins' data, the raw
 * database volume and every secret in plaintext — those belong to the host
 * snapshot / off-site copy in RUNBOOK-BACKUP-RESTORE.md.
 *
 * The last successful run stamps `igbz_last_backup`, which the SLO panel
 * turns into an RPO breach when a day passes without a backup.
 */
final class BackupService {

	public const FILE_PREFIX = 'igbz-backup-';
	public const FILE_SUFFIX = '.igbzbk';
	public const LAST_OPTION = 'igbz_last_backup';

	private const DEFAULT_RETENTION = 7;    // bundles kept
	private const DEFAULT_MAX_FILE_MB = 5;  // per-file cap for the uploads payload

	public function __construct(
		private Db $db,
		private Settings $settings,
		private Logger $logger,
		private string $base_dir = ''
	) {
		if ( '' === $this->base_dir ) {
			$this->base_dir = (string) ( wp_get_upload_dir()['basedir'] ?? '' );
		}
	}

	public function backup_dir(): string {
		return rtrim( $this->base_dir, '/' ) . '/igbz-backups';
	}

	/**
	 * Create one bundle. Test seams: $tables (names, unprefixed) and
	 * $rows (table => row list) override the live database.
	 *
	 * @param array{tables?:array<int,string>,rows?:array<string,array<int,array<string,mixed>>>,files_root?:string} $opts
	 * @return array{file:string,bytes:int,tables:int,rows:int,files:int,skipped:int,pruned:int}
	 */
	public function create( array $opts = [] ): array {
		$dir = $this->backup_dir();
		wp_mkdir_p( $dir );

		$tables = $opts['tables'] ?? Schema::tables();
		$files_root = $opts['files_root'] ?? $this->base_dir;

		// 1) logical DB dump
		$sql  = "-- IGBZ Suite logical dump v" . Bundle::VERSION . ' ' . gmdate( 'c' ) . "\n";
		$rows_total = 0;
		foreach ( $tables as $table ) {
			$rows = $opts['rows'][ $table ] ?? $this->db->results( 'SELECT * FROM ' . $this->db->table( $table ) );
			$sql .= $this->dump_rows( (string) $table, $rows );
			$rows_total += count( $rows );
		}

		// 2) settings document (travels encrypted; restore writes it beside the SQL, never applies it silently)
		$settings_json = (string) wp_json_encode( $this->settings->all(), JSON_UNESCAPED_UNICODE );

		// 3) uploads payload
		[ $files, $skipped ] = $this->collect_files( (string) $files_root );

		$manifest = [
			'created_at'      => current_time( 'mysql', true ),
			'generator'       => 'igbz-suite/' . ( defined( 'IGBZ_VERSION' ) ? IGBZ_VERSION : '1.0.0' ),
			'db'              => [
				'tables'     => count( (array) $tables ),
				'rows'       => $rows_total,
				'sql_sha256' => hash( 'sha256', $sql ),
			],
			'settings_sha256' => hash( 'sha256', $settings_json ),
			'files'           => $files,
			'skipped'         => $skipped,
		];

		$files_payload = array_map( static fn ( string $raw ): string => base64_encode( $raw ), $this->raw_files );

		$blob   = Bundle::encode( [
			'v'        => Bundle::VERSION,
			'manifest' => $manifest,
			'sql'      => $sql,
			'settings' => $settings_json,
			'files'    => $files_payload,
		] );
		$name   = self::FILE_PREFIX . gmdate( 'Ymd-His' ) . self::FILE_SUFFIX;
		$target = $dir . '/' . $name;
		$bytes  = file_put_contents( $target, $blob ); // phpcs:ignore
		if ( false === $bytes ) {
			$this->logger->error( 'backup', 'bundle not writable', [ 'target' => $target ] );
			throw new \RuntimeException( 'IGBZ Suite: backup directory is not writable.' );
		}

		update_option( self::LAST_OPTION, [
			't'     => time(),
			'file'  => $name,
			'sha256' => hash( 'sha256', $blob ),
			'bytes' => (int) $bytes,
		], false );

		$pruned = $this->prune();

		$this->logger->info( 'backup', 'bundle created', [
			'file' => $name, 'bytes' => (int) $bytes, 'tables' => count( (array) $tables ),
			'rows' => $rows_total, 'files' => count( $files ), 'skipped' => count( $skipped ), 'pruned' => $pruned,
		] );

		return [
			'file' => $name, 'bytes' => (int) $bytes, 'tables' => count( (array) $tables ),
			'rows' => $rows_total, 'files' => count( $files ), 'skipped' => count( $skipped ), 'pruned' => $pruned,
		];
	}

	/** @var array<string,string> rel path => raw contents, filled by collect_files() */
	private array $raw_files = [];

	/**
	 * @param array<int,string> $rows
	 * @return string INSERT statements (none when the table is empty — empty is a fact, not an error)
	 */
	private function dump_rows( string $table, array $rows ): string {
		if ( [] === $rows ) {
			return '';
		}
		$sql = '';
		foreach ( array_chunk( $rows, 50 ) as $batch ) {
			$values = [];
			foreach ( $batch as $row ) {
				$value = '';
				foreach ( $row as $column ) {
					$value .= ( '' === $value ? '' : ',' ) . Bundle::sql_value( $column );
				}
				$values[] = '(' . $value . ')';
			}
			$sql .= 'INSERT INTO ' . $this->db->table( $table ) . ' VALUES ' . implode( ',', $values ) . ";\n";
		}
		return $sql;
	}

	/**
	 * Walk the uploads tree (the backup dir itself is excluded — a backup must
	 * never swallow earlier backups). Oversized files are recorded, not guessed at.
	 *
	 * @return array{0:array<int,array<string,int|string>>,1:array<int,array<string,string>>}
	 */
	private function collect_files( string $root ): array {
		$cap     = $this->settings->int( 'backup.max_file_mb', self::DEFAULT_MAX_FILE_MB ) * 1024 * 1024;
		$entries = [];
		$skipped = [];
		$this->raw_files = [];

		if ( ! is_dir( $root ) ) {
			return [ $entries, $skipped ];
		}

		$backup_dir = $this->backup_dir();
		$iterator   = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			$path = (string) $file;
			if ( str_starts_with( $path, $backup_dir ) ) {
				continue;
			}
			$rel = ltrim( substr( $path, strlen( rtrim( $root, '/' ) ) ), '/' );
			if ( '' === $rel ) {
				continue;
			}
			$size = (int) $file->getSize();
			if ( $size > $cap ) {
				$skipped[] = [ 'path' => $rel, 'reason' => 'over-cap' ];
				continue;
			}
			$raw = file_get_contents( $path ); // phpcs:ignore
			if ( false === $raw ) {
				$skipped[] = [ 'path' => $rel, 'reason' => 'unreadable' ];
				continue;
			}
			$this->raw_files[ $rel ] = (string) $raw;
			$entries[] = Bundle::file_entry( $rel, $raw );
		}

		return [ $entries, $skipped ];
	}

	/** Keep the newest `backup.retention` bundles, delete the rest. */
	public function prune(): int {
		$keep  = max( 1, $this->settings->int( 'backup.retention', self::DEFAULT_RETENTION ) );
		$all   = $this->list_bundles();
		if ( count( $all ) <= $keep ) {
			return 0;
		}
		$removed = 0;
		foreach ( array_slice( $all, $keep ) as $stale ) {
			if ( unlink( $stale['path'] ) ) { // phpcs:ignore
				++$removed;
			}
		}
		return $removed;
	}

	/** @return array<int,array{name:string,path:string,bytes:int,mtime:int}> newest first */
	public function list_bundles(): array {
		$dir = $this->backup_dir();
		if ( ! is_dir( $dir ) ) {
			return [];
		}
		$out = [];
		foreach ( scandir( $dir ) ?: [] as $name ) {
			if ( ! str_starts_with( $name, self::FILE_PREFIX ) || ! str_ends_with( $name, self::FILE_SUFFIX ) ) {
				continue;
			}
			$path = $dir . '/' . $name;
			$out[] = [ 'name' => $name, 'path' => $path, 'bytes' => (int) filesize( $path ), 'mtime' => (int) filemtime( $path ) ];
		}
		usort( $out, static fn ( array $a, array $b ): int => $b['mtime'] <=> $a['mtime'] );
		return $out;
	}

	/**
	 * Verify + unpack one bundle. Files are restored in place; the SQL and the
	 * settings document are written beside the bundle for the operator to
	 * review — nothing hits the database unless $apply_sql is explicitly true.
	 *
	 * @return array{file:string,integrity:array<int,string>,files_restored:int,sql_file:string,sql_statements:int,applied:int}
	 */
	public function restore( string $path, bool $apply_sql = false ): array {
		$blob = (string) file_get_contents( $path ); // phpcs:ignore
		$payload = Bundle::decode( $blob );
		if ( null === $payload ) {
			$this->logger->error( 'backup', 'bundle not decryptable — wrong key or corrupted file', [ 'path' => basename( $path ) ] );
			throw new \RuntimeException( 'IGBZ Suite: bundle is not decryptable (key mismatch or corruption).' );
		}

		$errors = Bundle::integrity_errors( $payload );
		if ( [] !== $errors ) {
			$this->logger->error( 'backup', 'bundle failed integrity check', [ 'path' => basename( $path ), 'errors' => implode( '; ', $errors ) ] );
			throw new \RuntimeException( 'IGBZ Suite: bundle failed integrity: ' . implode( '; ', $errors ) );
		}

		// Files first, database second (WordPress recommended restore order).
		$restored = 0;
		foreach ( ( $payload['manifest']['files'] ?? [] ) as $entry ) {
			$rel = (string) ( $entry['path'] ?? '' );
			if ( '' === $rel || str_contains( $rel, '..' ) ) {
				continue;
			}
			$dest = rtrim( $this->base_dir, '/' ) . '/' . $rel;
			wp_mkdir_p( dirname( $dest ) );
			if ( false !== file_put_contents( $dest, (string) base64_decode( (string) ( $payload['files'][ $rel ] ?? '' ), true ) ) ) { // phpcs:ignore
				++$restored;
			}
		}

		$dir = $this->backup_dir();
		wp_mkdir_p( $dir );
		$sql_path = $dir . '/restore-' . gmdate( 'Ymd-His' ) . '.sql';
		file_put_contents( $sql_path, $payload['sql'] ); // phpcs:ignore
		file_put_contents( $dir . '/restore-settings-' . gmdate( 'Ymd-His' ) . '.json', $payload['settings'] ); // phpcs:ignore

		$statements = $this->sql_statements( $payload['sql'] );
		$applied    = 0;
		if ( $apply_sql ) {
			foreach ( $statements as $statement ) {
				if ( '' === trim( $statement ) ) {
					continue;
				}
				if ( false !== $this->db->query( $statement ) ) {
					++$applied;
				}
			}
		}

		$this->logger->info( 'backup', 'bundle restored', [
			'path' => basename( $path ), 'files' => $restored, 'statements' => count( $statements ), 'applied' => $applied,
		] );

		return [
			'file' => basename( $path ), 'integrity' => $errors, 'files_restored' => $restored,
			'sql_file' => $sql_path, 'sql_statements' => count( $statements ), 'applied' => $applied,
		];
	}

	/** @return array<int,string> */
	private function sql_statements( string $sql ): array {
		$clean = preg_replace( '/^--.*$/m', '', $sql ) ?? $sql;
		return array_filter( array_map( 'trim', explode( ";\n", $clean ) ) );
	}

	/** Age of the last successful backup in minutes (null = never backed up). */
	public static function last_backup_age_minutes(): ?int {
		$last = get_option( self::LAST_OPTION, null );
		if ( ! is_array( $last ) || ! isset( $last['t'] ) ) {
			return null;
		}
		return (int) floor( ( time() - (int) $last['t'] ) / MINUTE_IN_SECONDS );
	}
}
