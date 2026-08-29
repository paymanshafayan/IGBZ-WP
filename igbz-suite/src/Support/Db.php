<?php
namespace IGBZ\Suite\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Thin $wpdb helper: prefixed table names, typed fetch helpers and a real transaction wrapper
 * (the nopCommerce original summed wallet rows without a lock, which allowed overdrafts).
 */
final class Db {

	private ?bool $is_sqlite = null;

	public function wpdb(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	public function table( string $name ): string {
		return $this->wpdb()->prefix . 'igbz_' . ltrim( $name, '_' );
	}

	public function prepare( string $sql, mixed ...$args ): string {
		return $args ? $this->wpdb()->prepare( $sql, ...$args ) : $sql; // phpcs:ignore
	}

	/** @return array<string,mixed>|null */
	public function row( string $sql, mixed ...$args ): ?array {
		$row = $this->wpdb()->get_row( $this->prepare( $sql, ...$args ), ARRAY_A ); // phpcs:ignore
		return is_array( $row ) ? $row : null;
	}

	/** @return array<int,array<string,mixed>> */
	public function results( string $sql, mixed ...$args ): array {
		$rows = $this->wpdb()->get_results( $this->prepare( $sql, ...$args ), ARRAY_A ); // phpcs:ignore
		return is_array( $rows ) ? $rows : [];
	}

	public function scalar( string $sql, mixed ...$args ): mixed {
		return $this->wpdb()->get_var( $this->prepare( $sql, ...$args ) ); // phpcs:ignore
	}

	/** @return array<int,mixed> */
	public function column( string $sql, mixed ...$args ): array {
		$col = $this->wpdb()->get_col( $this->prepare( $sql, ...$args ) ); // phpcs:ignore
		return is_array( $col ) ? $col : [];
	}

	public function query( string $sql, mixed ...$args ): int {
		return (int) $this->wpdb()->query( $this->prepare( $sql, ...$args ) ); // phpcs:ignore
	}

	/**
	 * @param array<string,mixed> $data
	 * @return int Inserted id, 0 on failure.
	 */
	public function insert( string $table, array $data ): int {
		$ok = $this->wpdb()->insert( $this->table( $table ), $data, $this->formats( $data ) ); // phpcs:ignore
		return $ok ? (int) $this->wpdb()->insert_id : 0;
	}

	/**
	 * Derive an explicit placeholder format for every column from its PHP value.
	 *
	 * This must never be omitted. When $wpdb is not given formats it guesses them from the *column
	 * name* using its internal `$field_types` map, which is hard-coded for WordPress core tables and
	 * applied to any table. Several of those names are generic enough to collide with ours — most
	 * importantly `post_id`, which core forces to `%d`.
	 *
	 * `ig_funnels.post_id` and `ig_funnel_hits.post_id` are VARCHARs holding Instagram media ids
	 * such as "17912345678901234" or "POST-123", so the guess silently cast every value to an
	 * integer and stored 0. The funnel row then matched no incoming comment and the entire
	 * "comment a keyword and I'll DM you the link" feature failed with no error anywhere. This is
	 * core behaviour, not an SQLite quirk, so it broke on MySQL identically.
	 *
	 * @param array<string,mixed> $data
	 * @return string[]
	 */
	private function formats( array $data ): array {
		$formats = [];

		foreach ( $data as $value ) {
			if ( is_int( $value ) || is_bool( $value ) ) {
				$formats[] = '%d';
			} elseif ( is_float( $value ) ) {
				$formats[] = '%f';
			} else {
				// Strings and nulls; $wpdb replaces a null value with a literal NULL regardless.
				$formats[] = '%s';
			}
		}

		return $formats;
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $where
	 */
	public function update( string $table, array $data, array $where ): int {
		// Explicit formats for the same reason as insert() — see formats().
		return (int) $this->wpdb()->update( $this->table( $table ), $data, $where, $this->formats( $data ), $this->formats( $where ) ); // phpcs:ignore
	}

	/** @param array<string,mixed> $where */
	public function delete( string $table, array $where ): int {
		return (int) $this->wpdb()->delete( $this->table( $table ), $where, $this->formats( $where ) ); // phpcs:ignore
	}

	/**
	 * Phase 20: a bounded, resumable mass delete.
	 *
	 * One unbounded DELETE can hold locks for a long time, balloon the binary log, and die in
	 * PHP's time limit halfway through — so housekeeping and offboarding trim in deterministic
	 * id-ordered batches instead. The loop stops when a batch removes nothing or less than a
	 * full batch; the safety cap bounds a single run and whatever is left carries over to the
	 * next housekeeping pass.
	 *
	 * @param string $where_sql WHERE clause with placeholders; must not carry ORDER/LIMIT.
	 * @param array<int,mixed> $args
	 */
	public function delete_batches( string $table, string $where_sql, array $args = [], int $batch = 500, int $max_batches = 200 ): int {
		$batch   = max( 1, min( 5000, $batch ) );
		$deleted = 0;

		for ( $i = 0; $i < max( 1, $max_batches ); ++$i ) {
			$affected = $this->query(
				'DELETE FROM ' . $this->table( $table ) . ' WHERE ' . $where_sql . ' ORDER BY id LIMIT %d',
				...array_merge( $args, [ $batch ] )
			);
			if ( $affected <= 0 ) {
				break;
			}
			$deleted += $affected;
			if ( $affected < $batch ) {
				break;
			}
		}

		return $deleted;
	}

	public function last_error(): string {
		return (string) $this->wpdb()->last_error;
	}

	/**
	 * Run a closure inside a real SQL transaction. InnoDB is required for SELECT ... FOR UPDATE
	 * to be meaningful; on MyISAM the callback still runs but without isolation.
	 *
	 * @template T
	 * @param callable():T $callback
	 * @return T
	 */
	public function transaction( callable $callback ): mixed {
		$wpdb = $this->wpdb();
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore
		try {
			$result = $callback( $this );
			$wpdb->query( 'COMMIT' ); // phpcs:ignore
			return $result;
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore
			throw $e;
		}
	}

	/**
	 * True when the site runs on something other than MySQL/MariaDB — in practice SQLite, which is
	 * what WordPress Playground and the `sqlite-database-integration` plugin use.
	 *
	 * Note that this does NOT mean "write SQLite SQL". The drop-in translates MySQL into SQLite, so
	 * ordinary MySQL statements — including ON DUPLICATE KEY UPDATE — must still be emitted. Only
	 * constructs the translator has no equivalent for need branching here: `SELECT ... FOR UPDATE`
	 * row locks and the `GET_LOCK`/`RELEASE_LOCK` advisory-lock functions.
	 *
	 * The result is cached for the request.
	 */
	public function is_sqlite(): bool {
		if ( null === $this->is_sqlite ) {
			$this->is_sqlite = defined( 'DB_ENGINE' ) && 'sqlite' === constant( 'DB_ENGINE' )
				|| class_exists( '\WP_SQLite_DB' )
				|| class_exists( '\WP_SQLite_Translator' );
		}
		return $this->is_sqlite;
	}

	/**
	 * Portable "insert or update" for tables with a UNIQUE key.
	 *
	 * The statement is always written in MySQL dialect — `INSERT ... ON DUPLICATE KEY UPDATE` with
	 * `VALUES(col)` and `GREATEST()`. That is correct on both supported engines because $wpdb only
	 * ever speaks MySQL: the `sqlite-database-integration` drop-in used by WordPress Playground is a
	 * MySQL *translator*, not a raw SQLite connection, so it parses MySQL and rewrites it.
	 *
	 * Emitting native SQLite spelling here (`ON CONFLICT ... DO UPDATE SET excluded.col`) is a bug:
	 * the translator cannot parse it and fails with "Failed to parse the MySQL query." Worse, that
	 * failure aborts the surrounding transaction while $wpdb still reports success, so callers such
	 * as WalletService::post() committed a ledger entry that silently never landed. Hence the
	 * explicit failure check below — a broken upsert must never look like a successful write.
	 *
	 * @param array<string,mixed>  $data          Column => value for the INSERT.
	 * @param array<string,string> $update        Column => strategy applied on conflict. Strategies:
	 *                                            `value` overwrite, `greatest` keep the larger,
	 *                                            `coalesce` keep the existing non-null.
	 * @param string[]             $conflict_keys Columns forming the UNIQUE key. Not emitted into the
	 *                                            SQL (MySQL infers the target from the index) but kept
	 *                                            so callers document which index they rely on.
	 *
	 * @throws \RuntimeException When the database rejects the statement.
	 */
	public function upsert( string $table, array $data, array $update, array $conflict_keys = [] ): int {
		$full         = $this->table( $table );
		$columns      = array_keys( $data );
		$placeholders = [];
		$values       = [];

		foreach ( $data as $value ) {
			if ( null === $value ) {
				$placeholders[] = 'NULL';
				continue;
			}
			$placeholders[] = is_int( $value ) ? '%d' : ( is_float( $value ) ? '%f' : '%s' );
			$values[]       = $value;
		}

		unset( $conflict_keys );

		$sets = [];

		foreach ( $update as $column => $strategy ) {
			$incoming = 'VALUES(' . $column . ')';
			switch ( $strategy ) {
				case 'greatest':
					$sets[] = "{$column} = GREATEST({$column}, {$incoming})";
					break;
				case 'coalesce':
					$sets[] = "{$column} = COALESCE({$column}, {$incoming})";
					break;
				default:
					$sets[] = "{$column} = {$incoming}";
			}
		}

		$sql = 'INSERT INTO ' . $full . ' (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $placeholders ) . ')';

		if ( $sets ) {
			$sql .= ' ON DUPLICATE KEY UPDATE ' . implode( ', ', $sets );
		}

		$wpdb   = $this->wpdb();
		$result = $wpdb->query( $this->prepare( $sql, ...$values ) ); // phpcs:ignore

		if ( false === $result ) {
			throw new \RuntimeException( 'Upsert into ' . $full . ' failed: ' . $this->last_error() );
		}

		return (int) $result;
	}

	/**
	 * Acquire a named advisory lock (used to serialise wallet debits per customer).
	 *
	 * GET_LOCK is MySQL-only. On SQLite the whole database is single-writer anyway, so there is
	 * nothing to serialise and we report success rather than failing every wallet operation.
	 */
	public function lock( string $name, int $timeout = 5 ): bool {
		if ( $this->is_sqlite() ) {
			return true;
		}
		return '1' === (string) $this->scalar( 'SELECT GET_LOCK(%s, %d)', 'igbz_' . $name, $timeout );
	}

	public function unlock( string $name ): void {
		if ( $this->is_sqlite() ) {
			return;
		}
		$this->scalar( 'SELECT RELEASE_LOCK(%s)', 'igbz_' . $name );
	}
}
