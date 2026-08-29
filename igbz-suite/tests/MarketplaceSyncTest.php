<?php
/**
 * Phase 46 — durable marketplace sync: publishing is idempotent by payload
 * hash, a conflict is terminal and never overwritten, throttling and
 * provider breakage defer the row instead of burning retries, and the queue
 * itself never double-enqueues.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\MultiTenant\Marketplace\MarketplaceSyncService;
use IGBZ\Suite\Support\Db;

/** In-memory engine for the sync queue and the link book. */
final class MarketDb extends wpdb {

	/** @var array<string,array<int,array<string,mixed>>> */
	public array $tables = [ 'ig_marketplace_sync' => [], 'marketplace_links' => [], 'ig_category_mapping' => [] ];

	private int $next_id = 1;

	/** @param array<string,mixed> $row */
	public function seed( string $table, array $row ): int {
		$id                            = $this->next_id++;
		$row['id']                     = $id;
		$this->tables[ $table ][ $id ] = $row;

		return $id;
	}

	/** @return array<string,mixed>|null */
	public function first_row( string $table ): ?array {
		$rows = array_values( $this->tables[ $table ] );
		return $rows[0] ?? null;
	}

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'marketplace_links' ) && preg_match( "/product_id = '(\d+)'.*channel = '([^']*)'/s", $sql, $m ) ) {
			foreach ( $this->tables['marketplace_links'] as $row ) {
				if ( (string) $row['product_id'] === $m[1] && (string) $row['channel'] === $m[2] ) {
					return $row;
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'ig_category_mapping' ) ) {
			return null;
		}

		return parent::get_row( $sql, $output );
	}

	public function get_var( string $sql, $column = 0, $row = 0 ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'COUNT(*)' ) && str_contains( $sql, 'ig_marketplace_sync' ) ) {
			preg_match( "/product_id = '(\d+)'/", $sql, $pid );
			preg_match( "/marketplace = '([^']*)'/", $sql, $mk );
			preg_match( "/status = '([^']*)'/", $sql, $st );
			$count = 0;
			foreach ( $this->tables['ig_marketplace_sync'] as $row ) {
				if ( (string) $row['product_id'] === $pid[1]
					&& (string) $row['marketplace'] === $mk[1]
					&& (string) $row['status'] === $st[1] ) {
					++$count;
				}
			}
			return $count;
		}

		return parent::get_var( $sql, $column, $row );
	}

	public function get_results( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'ig_marketplace_sync' ) && str_contains( $sql, 'ORDER BY id DESC' ) ) {
			return array_values( $this->tables['ig_marketplace_sync'] );
		}

		return parent::get_results( $sql, $output );
	}

	/** The bare logical name, whatever prefix the engine used. */
	private function logical( string $table ): string {
		foreach ( [ 'ig_marketplace_sync', 'marketplace_links', 'ig_category_mapping' ] as $known ) {
			if ( str_ends_with( $table, $known ) ) {
				return $known;
			}
		}
		return $table;
	}

	public function insert( string $table, array $data, $format = null ): int|bool {
		$this->queries[] = 'INSERT ' . $table;
		$table = $this->logical( $table );

		if ( isset( $this->tables[ $table ] ) ) {
			$id = $this->next_id++;
			$data['id'] = $id;
			$this->tables[ $table ][ $id ] = $data;
			$this->insert_id = $id;
			return 1;
		}

		return parent::insert( $table, $data, $format );
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		$this->queries[] = 'UPDATE ' . $table;
		$table = $this->logical( $table );

		if ( isset( $this->tables[ $table ] ) ) {
			$changed = 0;
			foreach ( $this->tables[ $table ] as $id => $row ) {
				$hit = true;
				foreach ( $where as $column => $value ) {
					if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) {
						$hit = false;
						break;
					}
				}
				if ( $hit ) {
					$this->tables[ $table ][ $id ] = array_merge( $row, $data );
					++$changed;
				}
			}
			return $changed;
		}

		return parent::update( $table, $data, $where, $format, $where_format );
	}
}

final class MarketplaceSyncTest extends TestCase {

	private MarketDb $mdb;
	private MarketplaceSyncService $sync;

	private function boot(): void {
		igbz_test_reset_settings();

		$this->mdb       = new MarketDb();
		$GLOBALS['wpdb'] = $this->mdb;

		$db  = new Db();
		$ref = new ReflectionProperty( Db::class, 'is_sqlite' );
		$ref->setValue( $db, true );

		$this->sync = new MarketplaceSyncService( $db, new IGBZ\Suite\Support\Logger( igbz()->settings() ) );
	}

	/** @return array<string,mixed> */
	private function queue_row( int $attempts = 0 ): array {
		$id = $this->mdb->seed( 'ig_marketplace_sync', [
			'tenant_id' => 7, 'product_id' => 11, 'marketplace' => 'digikala', 'action' => 'upsert',
			'status' => 'pending', 'attempts' => $attempts, 'last_error' => '', 'not_before' => null,
			'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		] );

		return $this->mdb->tables['ig_marketplace_sync'][ $id ];
	}

	public function run(): void {
		$this->test_payload_hash_ignores_key_order();
		$this->test_an_already_published_payload_is_not_pushed_again();
		$this->test_outcomes_are_classified_honestly();
		$this->test_throttling_defers_without_burning_retries();
		$this->test_provider_breakage_backs_off_until_the_cap();
		$this->test_success_writes_the_link_book();
		$this->test_the_queue_never_double_enqueues();
	}

	public function test_payload_hash_ignores_key_order(): void {
		$this->boot();

		$one = $this->sync->payload_hash( [ 'name' => 'کفش', 'price_irt' => 500000, 'stock' => 3 ] );
		$two = $this->sync->payload_hash( [ 'stock' => 3, 'name' => 'کفش', 'price_irt' => 500000 ] );
		$this->assert_same( $one, $two, 'the same payload hashes the same' );

		$three = $this->sync->payload_hash( [ 'name' => 'کفش', 'price_irt' => 500001, 'stock' => 3 ] );
		$this->assert_false( $one === $three, 'a changed price changes the hash' );
	}

	public function test_an_already_published_payload_is_not_pushed_again(): void {
		$this->boot();
		$hash = $this->sync->payload_hash( [ 'name' => 'کفش' ] );

		$this->mdb->seed( 'marketplace_links', [
			'tenant_id' => 7, 'product_id' => 11, 'channel' => 'digikala', 'external_id' => 'dk-1',
			'payload_hash' => $hash, 'remote_rev' => '', 'last_synced_at' => gmdate( 'Y-m-d H:i:s' ),
			'sync_status' => 'synced', 'sync_message' => '',
		] );

		$this->assert_true( $this->sync->already_published( 11, 'digikala', 7, $hash ), 'the exact payload is remembered' );

		$other = $this->sync->payload_hash( [ 'name' => 'کفش', 'price_irt' => 1 ] );
		$this->assert_false( $this->sync->already_published( 11, 'digikala', 7, $other ), 'a changed payload must be pushed' );
	}

	public function test_outcomes_are_classified_honestly(): void {
		$this->boot();
		$row = $this->queue_row();

		$ok = $this->sync->classify_result( $row, [ 'ok' => true, 'remote_id' => 'dk-1' ] );
		$this->assert_same( 'done', $ok['status'], 'a clean push is done' );
		$this->assert_same( 'synced', $ok['link_status'], 'the link book says synced' );

		$conflict = $this->sync->classify_result( $row, [ 'ok' => false, 'http_status' => 409, 'message' => 'changed' ] );
		$this->assert_same( 'failed', $conflict['status'], 'a conflict stops the row' );
		$this->assert_same( 'conflict', $conflict['last_error'], 'the refusal is named' );
		$this->assert_same( 'conflict', $conflict['link_status'], 'the link book shows the conflict' );

		$bad = $this->sync->classify_result( $row, [ 'ok' => false, 'http_status' => 400, 'message' => 'bad payload' ] );
		$this->assert_same( 'failed', $bad['status'], 'a broken payload does not retry forever' );
	}

	public function test_throttling_defers_without_burning_retries(): void {
		$this->boot();
		$row     = $this->queue_row( 1 );
		$verdict = $this->sync->classify_result( $row, [ 'ok' => false, 'http_status' => 429, 'retry_after' => 30, 'message' => 'slow down' ] );

		$this->assert_same( 'pending', $verdict['status'], 'a throttled row waits' );
		$this->assert_same( 1, $verdict['attempts'], 'the provider saying "wait" is not our failure' );
		$this->assert_true( null !== $verdict['not_before'] && $verdict['not_before'] >= gmdate( 'Y-m-d H:i:s', time() + 25 ), 'the row sleeps for the asked time' );
	}

	public function test_provider_breakage_backs_off_until_the_cap(): void {
		$this->boot();

		$first = $this->sync->classify_result( $this->queue_row( 0 ), [ 'ok' => false, 'http_status' => 503, 'message' => 'down' ] );
		$this->assert_same( 'pending', $first['status'], 'the first breakage defers' );
		$this->assert_same( 1, $first['attempts'], 'one attempt spent' );

		$last = $this->sync->classify_result( $this->queue_row( 2 ), [ 'ok' => false, 'http_status' => 503, 'message' => 'down' ] );
		$this->assert_same( 'failed', $last['status'], 'the retry cap ends the row' );
		$this->assert_same( 3, $last['attempts'], 'the cap counts honestly' );
	}

	public function test_success_writes_the_link_book(): void {
		$this->boot();
		$row  = $this->queue_row();
		$hash = $this->sync->payload_hash( [ 'name' => 'کفش' ] );

		$this->sync->record_outcome( $row, [ 'ok' => true, 'remote_id' => 'dk-1', 'remote_rev' => 'rev-9' ], $hash );

		$queue = $this->mdb->tables['ig_marketplace_sync'][ (int) $row['id'] ];
		$this->assert_same( 'done', $queue['status'], 'the queue row is done' );

		$link = $this->mdb->first_row( 'marketplace_links' );
		$this->assert_true( null !== $link, 'the link book has a row' );
		$this->assert_same( 'dk-1', (string) $link['external_id'], 'the remote id is remembered' );
		$this->assert_same( $hash, (string) $link['payload_hash'], 'the published hash is remembered' );
		$this->assert_same( 'rev-9', (string) $link['remote_rev'], 'the remote revision is remembered' );
	}

	public function test_the_queue_never_double_enqueues(): void {
		$this->boot();
		$this->queue_row();

		$this->sync->enqueue( 11, 'digikala', 'upsert', 7 );
		$this->assert_same( 1, count( $this->mdb->tables['ig_marketplace_sync'] ), 'a pending row is not duplicated' );

		$this->sync->enqueue( 11, 'divar', 'upsert', 7 );
		$this->assert_same( 2, count( $this->mdb->tables['ig_marketplace_sync'] ), 'another marketplace gets its own row' );
	}
}
