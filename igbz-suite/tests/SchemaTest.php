<?php
declare( strict_types=1 );

use IGBZ\Suite\Support\Schema;

/**
 * The nopCommerce original drifted between its migrations and its entity list; this keeps the
 * table catalogue and the DDL in lockstep so Status page health checks stay meaningful.
 */
final class SchemaTest extends TestCase {

	public function run(): void {
		$tables     = Schema::tables();
		$statements = Schema::statements();

		$this->assert_same( count( $tables ), count( array_unique( $tables ) ), 'tables() has no duplicates' );
		$this->assert_same( count( $tables ), count( $statements ), 'every catalogued table has exactly one CREATE statement' );

		$declared = [];
		foreach ( $statements as $sql ) {
			$this->assert_contains( 'CREATE TABLE', $sql, 'statement is a CREATE TABLE' );
			$this->assert_contains( 'PRIMARY KEY', $sql, 'statement declares a primary key' );
			$this->assert_contains( 'utf8mb4', $sql, 'statement carries the charset collate' );

			if ( preg_match( '/CREATE TABLE\s+(\S+)\s*\(/', $sql, $m ) ) {
				$declared[] = $m[1];
			}
		}

		$this->assert_same( count( $statements ), count( $declared ), 'every statement names a table' );

		$expected = array_map( static fn ( string $t ): string => 'wp_igbz_' . $t, $tables );
		sort( $expected );
		sort( $declared );
		$this->assert_same( $expected, $declared, 'the DDL creates exactly the catalogued tables' );

		// dbDelta is whitespace sensitive: it needs two spaces after PRIMARY KEY and lowercase "key".
		foreach ( $statements as $sql ) {
			if ( str_contains( $sql, 'PRIMARY KEY' ) ) {
				$this->assert_contains( 'PRIMARY KEY  (', $sql, 'dbDelta requires two spaces after PRIMARY KEY' );
			}
		}

		foreach ( [ 'tenants', 'wallet_ledger', 'api_tokens', 'devices', 'ig_content' ] as $table ) {
			$this->assert_true( in_array( $table, $tables, true ), "core table {$table} is catalogued" );
		}

		$this->assert_same( 'wp_igbz_tenants', Schema::table( 'tenants' ), 'table() prefixes correctly' );

		$this->assert_no_unsafe_core_column_names( $statements );

		// Tenant scoping is the backbone of the suite: nearly every table must carry the column.
		// lesson_progress inherits its tenant through enrollment_id, so it deliberately has none;
		// vip_post_likes, vip_post_saves and vip_post_views are the same shape — a pure (post, user)
		// join row whose tenant is whatever the post's is. Copying the column onto them would create
		// a second place for it to be wrong.
		$unscoped = [
			'plans',
			'logs',
			'tenant_domains',
			'tenant_members',
			'tenants',
			'lesson_progress',
			'vip_post_likes',
			'vip_post_saves',
			'vip_post_views',
			'fx_rates',
			'fx_prices',
			'ig_label_group_items',
			'ig_courier_tracking',
			'ig_courier_chat',
		];
		foreach ( $statements as $sql ) {
			preg_match( '/CREATE TABLE\s+wp_igbz_(\S+)\s*\(/', $sql, $m );
			$name = $m[1] ?? '';
			if ( '' === $name || in_array( $name, $unscoped, true ) ) {
				continue;
			}
			$this->assert_contains( 'tenant_id', $sql, "{$name} carries a tenant_id column" );
		}
	}

	/**
	 * Guard against column names that wpdb silently casts.
	 *
	 * wpdb::$field_types maps a set of core column *names* to formats and applies them to any
	 * table when insert()/update() are called without an explicit format list. `post_id` is mapped
	 * to %d there, so a VARCHAR column of that name in a plugin table had every value cast to an
	 * integer: ig_funnels.post_id stored 0 instead of an Instagram media id and funnel matching
	 * broke silently on both MySQL and SQLite.
	 *
	 * Db::insert()/update()/delete() now always pass explicit formats, which neutralises the map.
	 * This test is the second line of defence: if a new non-integer column reuses one of these
	 * names, it flags the collision so the risk is a deliberate choice rather than an accident.
	 *
	 * @param string[] $statements
	 */
	private function assert_no_unsafe_core_column_names( array $statements ): void {
		// The subset of wpdb::$field_types entries plausible in this schema, all forced to %d.
		$numeric_in_core = [ 'post_id', 'user_id', 'parent', 'count', 'active', 'public', 'deleted', 'object_id', 'term_id' ];

		$found = [];

		foreach ( $statements as $sql ) {
			preg_match( '/CREATE TABLE\s+wp_igbz_(\S+)\s*\(/', $sql, $m );
			$table = $m[1] ?? '';

			foreach ( explode( "\n", $sql ) as $line ) {
				$line = trim( $line );

				if ( ! preg_match( '/^([a-z_]+)\s+(VARCHAR|TEXT|LONGTEXT|CHAR|DATETIME|DATE|DECIMAL|FLOAT|DOUBLE)/i', $line, $col ) ) {
					continue;
				}

				if ( in_array( $col[1], $numeric_in_core, true ) ) {
					$found[] = $table . '.' . $col[1] . ' (' . strtoupper( $col[2] ) . ')';
				}
			}
		}

		// ig_zernio_inbox.post_id is intentional: it holds the Instagram shortcode of the post
		// the captured event belongs to (phase 51) — the same VARCHAR-of-a-shortcode shape.
		// ig_funnels.post_id and ig_funnel_hits.post_id are intentional: they hold Instagram media
		// ids, which are opaque strings. They are safe only because Db always sends formats.
		$known = [ 'ig_funnels.post_id (VARCHAR)', 'ig_funnel_hits.post_id (VARCHAR)', 'ig_zernio_inbox.post_id (VARCHAR)' ];
		$new   = array_values( array_diff( $found, $known ) );

		$this->assert_same(
			[],
			$new,
			'no new non-integer column reuses a name that wpdb::$field_types casts to %d'
		);
	}
}
