<?php
use IGBZ\Suite\Support\ArchiveGuard;

/**
 * Phase 11 (archive gate): real archives built inside the sandbox exercise the pre-extraction
 * rules — entry-name safety, count ceilings and the ZIP magic bytes.
 */
final class ArchiveGuardTest extends TestCase {

	private string $dir;

	public function run(): void {
		$this->dir = rtrim( sys_get_temp_dir(), '/' ) . '/igbz-agt-' . uniqid( '', true );
		@mkdir( $this->dir, 0777, true );
		try {
			$this->clean_archive_passes();
			$this->traversal_entry_is_refused();
			$this->file_count_ceiling_is_refused();
			$this->magic_bytes_must_match();
		} finally {
			$this->remove_tree( $this->dir );
		}
	}

	private function make_zip( string $name, array $entries ): string {
		$path = $this->dir . '/' . $name;
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		foreach ( $entries as $entry => $body ) {
			$zip->addFromString( (string) $entry, (string) $body );
		}
		$zip->close();
		return $path;
	}

	private function open( string $path ): ZipArchive {
		$zip = new ZipArchive();
		$zip->open( $path );
		return $zip;
	}

	private function clean_archive_passes(): void {
		$path = $this->make_zip( 'clean.zip', [ 'style.css' => 'body{}', 'functions.php' => '<?php // theme' ] );
		$this->assert_true( ArchiveGuard::looks_like_zip( $path ), 'magic bytes accepted' );
		$result = ArchiveGuard::check( $this->open( $path ) );
		$this->assert_true( $result['ok'], 'clean archive passes the gate' );
	}

	private function traversal_entry_is_refused(): void {
		$path = $this->make_zip( 'evil.zip', [ '../../evil.txt' => 'pwn' ] );
		$result = ArchiveGuard::check( $this->open( $path ) );
		$this->assert_false( $result['ok'], 'zip-slip entry refused' );
		$this->assert_same( 'archive-unsafe-entry-name', $result['error'], 'zip-slip reason recorded' );
	}

	private function file_count_ceiling_is_refused(): void {
		$path = $this->make_zip( 'many.zip', [ 'a.txt' => '1', 'b.txt' => '2', 'c.txt' => '3' ] );
		$result = ArchiveGuard::check( $this->open( $path ), 2, ArchiveGuard::MAX_UNCOMPRESSED );
		$this->assert_false( $result['ok'], 'entry count ceiling enforced' );
		$this->assert_same( 'archive-too-many-files', $result['error'], 'count reason recorded' );
	}

	private function magic_bytes_must_match(): void {
		$path = $this->dir . '/fake.zip';
		file_put_contents( $path, '<?php echo "not a zip";' );
		$this->assert_false( ArchiveGuard::looks_like_zip( $path ), 'PHP payload wearing a .zip name refused' );
	}

	private function remove_tree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) ?: [] as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			@unlink( $dir . '/' . $name );
		}
		@rmdir( $dir );
	}
}
