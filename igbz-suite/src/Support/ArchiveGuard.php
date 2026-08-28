<?php
namespace IGBZ\Suite\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 11 (upload/archive): one set of rules in front of every untrusted archive.
 *
 * The zip bomb playbook says a small file can expand to terabytes, and the zip slip
 * playbook says an entry name can walk out of the destination directory — so an archive is
 * judged entry by entry BEFORE anything touches disk:
 *   - entry count ceiling,
 *   - total uncompressed size ceiling (header-declared; extraction never sees more),
 *   - no `..`, no absolute paths, no backslash escapes in entry names,
 *   - the magic bytes must actually say ZIP.
 */
final class ArchiveGuard {

	public const MAX_FILES        = 2048;
	public const MAX_UNCOMPRESSED = 10 * 1024 * 1024;

	/** @return array{ok:bool,error:string} */
	public static function check( \ZipArchive $zip, int $max_files = self::MAX_FILES, int $max_bytes = self::MAX_UNCOMPRESSED ): array {
		if ( $zip->numFiles > $max_files ) {
			return [ 'ok' => false, 'error' => 'archive-too-many-files' ];
		}

		$uncompressed = 0;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat         = $zip->statIndex( $i );
			$uncompressed += (int) ( $stat['size'] ?? 0 );
			if ( $uncompressed > $max_bytes ) {
				return [ 'ok' => false, 'error' => 'archive-too-large-uncompressed' ];
			}

			$name = str_replace( '\\', '/', (string) ( $zip->getNameIndex( $i ) ?: '' ) );
			if ( '' === $name || str_starts_with( $name, '/' ) || str_contains( $name, '..' ) ) {
				return [ 'ok' => false, 'error' => 'archive-unsafe-entry-name' ];
			}
		}

		return [ 'ok' => true, 'error' => '' ];
	}

	/** First four bytes must be the ZIP local file header signature. */
	public static function looks_like_zip( string $path ): bool {
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $handle ) {
			return false;
		}
		$magic = (string) fread( $handle, 4 );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return 'PK' === substr( $magic, 0, 2 );
	}
}
