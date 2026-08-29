<?php
use IGBZ\Suite\Support\Db;

/**
 * DELETE double whose affected-row counts are scripted per call, so the batch loop's stopping
 * rules can be proven exactly.
 */
final class BatchDb extends wpdb {

	/** @var int[] affected rows returned by each successive DELETE */
	public array $scripted = [];

	public int $delete_calls = 0;

	public function query( string $sql ): int|bool {
		$this->queries[] = $sql;
		if ( str_starts_with( ltrim( $sql ), 'DELETE' ) ) {
			return $this->scripted[ $this->delete_calls++ ] ?? 0;
		}
		return 1;
	}
}

/**
 * Phase 20: mass deletes run in bounded, id-ordered batches and stop honestly — an unbounded
 * DELETE on a grown-out table is exactly how a housekeeping run locks a site.
 */
final class BatchTest extends TestCase {

	public function run(): void {
		igbz_test_reset_settings();

		$this->loops_until_a_partial_batch_and_sums();
		$this->honours_the_safety_cap();
		$this->reports_zero_without_touching_anything();
		$this->every_batch_is_ordered_and_bounded();
	}

	private function db( array $scripted ): BatchDb {
		$db                    = new BatchDb();
		$db->scripted          = $scripted;
		$GLOBALS['wpdb']       = $db;
		return $db;
	}

	private function loops_until_a_partial_batch_and_sums(): void {
		$db = $this->db( [ 500, 500, 123 ] );

		$total = ( new Db() )->delete_batches( 'logs', 'created_at < %s', [ '2026-01-01 00:00:00' ], 500 );

		$this->assert_same( 1123, $total, 'full batches plus the partial tail are summed' );
		$this->assert_same( 3, $db->delete_calls, 'the loop stops at the first partial batch' );
	}

	private function honours_the_safety_cap(): void {
		$db = $this->db( [ 500, 500, 500, 500, 500, 500 ] );

		$total = ( new Db() )->delete_batches( 'logs', 'created_at < %s', [ '2026-01-01 00:00:00' ], 500, 4 );

		$this->assert_same( 4, $db->delete_calls, 'the safety cap ends a single run' );
		$this->assert_same( 2000, $total, 'whatever is left carries over to the next run' );
	}

	private function reports_zero_without_touching_anything(): void {
		$db = $this->db( [ 0 ] );

		$total = ( new Db() )->delete_batches( 'logs', 'created_at < %s', [ '2026-01-01 00:00:00' ], 500 );

		$this->assert_same( 0, $total, 'nothing to delete answers zero' );
		$this->assert_same( 1, $db->delete_calls, 'one probe batch is enough to know the table is clean' );
	}

	private function every_batch_is_ordered_and_bounded(): void {
		$db = $this->db( [ 500, 0 ] );

		( new Db() )->delete_batches( 'logs', 'created_at < %s', [ '2026-01-01 00:00:00' ], 500 );

		foreach ( $db->queries as $sql ) {
			$this->assert_true( str_contains( $sql, 'created_at' ), 'the caller condition survives into every batch' );
			$this->assert_true( str_contains( $sql, 'ORDER BY id LIMIT' ), 'every batch is deterministic and bounded' );
		}
	}
}
