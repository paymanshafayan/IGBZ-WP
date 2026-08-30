<?php
/**
 * Phase 55 — growth intel: auditable giveaways, insights with provenance/retention,
 * manual competitor snapshots.
 *
 * The draw is the heart: commit–reveal with a documented, re-derivable winner function.
 * These tests re-derive every drawn winner from the published audit packet and the frozen
 * pool, refuse the classic frauds (duplicate entries, entries after the draw, a second
 * draw), and keep provider-fetched and manager-entered numbers apart by provenance.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\Instagram\Gateways\ZernioClient;
use IGBZ\Suite\Modules\Instagram\Growth\CompetitorService;
use IGBZ\Suite\Modules\Instagram\Growth\GiveawayDrawService;
use IGBZ\Suite\Modules\Instagram\Growth\InsightService;
use IGBZ\Suite\Modules\Instagram\Services\ZernioConnectionService;
use IGBZ\Suite\Modules\Instagram\Services\ZernioSocialService;
use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Http;

/** In-memory engine for the growth-intel tables + the Zernio profile. */
final class GrowthDb extends wpdb {

	/** @var array<string,array<int,array<string,mixed>>> */
	public array $tables = [
		'ig_giveaways'            => [],
		'ig_giveaway_entries'     => [],
		'ig_insights'             => [],
		'ig_competitors'          => [],
		'ig_competitor_snapshots' => [],
		'ig_accounts'             => [],
	];

	/** @var array<int,array<string,mixed>> tenant_id => zernio profile row */
	public array $profiles = [];

	/** When true, the next giveaway-entries insert fails like the UNIQUE key (the race). */
	public bool $entry_unique_race = false;

	private int $next_id = 1;

	public function seed( string $table, array $row ): int {
		$id = $this->next_id++;
		$row['id'] = $id;
		$this->tables[ $table ][ $id ] = $row;
		return $id;
	}

	public function insert( string $table, array $data, $format = null ): int|bool {
		$this->queries[] = 'INSERT INTO ' . $table;
		foreach ( $this->tables as $name => $rows ) {
			if ( ! str_contains( $table, $name ) ) {
				continue;
			}
			if ( 'ig_giveaway_entries' === $name ) {
				foreach ( $rows as $row ) {
					if ( (int) $row['giveaway_id'] === (int) $data['giveaway_id'] && (string) $row['subscriber'] === (string) $data['subscriber'] ) {
						if ( $this->entry_unique_race ) {
							return false;
						}
						return 1; // idempotent re-add of the same person
					}
				}
			}
			$id = $this->next_id++;
			$data['id'] = $id;
			$this->tables[ $name ][ $id ] = $data;
			$this->insert_id = $id;
			return 1;
		}
		return parent::insert( $table, $data, $format );
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		$this->queries[] = 'UPDATE ' . $table;
		foreach ( $this->tables as $name => $rows ) {
			if ( ! str_contains( $table, $name ) ) {
				continue;
			}
			$changed = 0;
			foreach ( $rows as $id => $row ) {
				if ( ! $this->where_matches( $row, $where ) ) {
					continue;
				}
				$present = array_intersect_key( $row, $data );
				if ( $present === $data && array_keys( $present ) === array_keys( $data ) ) {
					continue; // no-op write, 0 affected like the real engine
				}
				$this->tables[ $name ][ $id ] = array_merge( $row, $data );
				++$changed;
			}
			return $changed;
		}
		return parent::update( $table, $data, $where, $format, $where_format );
	}

	public function delete( string $table, array $where, $where_format = null ): int|bool {
		$this->queries[] = 'DELETE FROM ' . $table;
		foreach ( $this->tables as $name => $rows ) {
			if ( ! str_contains( $table, $name ) ) {
				continue;
			}
			$changed = 0;
			foreach ( $rows as $id => $row ) {
				if ( $this->where_matches( $row, $where ) ) {
					unset( $this->tables[ $name ][ $id ] );
					++$changed;
				}
			}
			return $changed;
		}
		return parent::delete( $table, $where, $where_format );
	}

	public function query( string $sql ): int|bool {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'DELETE FROM' ) && str_contains( $sql, 'ig_insights' ) ) {
			$deleted = 0;
			foreach ( $this->tables['ig_insights'] as $id => $row ) {
				if ( $this->older_than_cutoff( $row, $sql ) ) {
					unset( $this->tables['ig_insights'][ $id ] );
					++$deleted;
				}
			}
			return $deleted;
		}

		if ( str_contains( $sql, 'DELETE FROM' ) && str_contains( $sql, 'ig_competitor_snapshots' ) ) {
			$deleted = 0;
			foreach ( $this->tables['ig_competitor_snapshots'] as $id => $row ) {
				if ( preg_match( "/competitor_id = '(\\d+)'/", $sql, $m ) && (int) $row['competitor_id'] === (int) $m[1] ) {
					unset( $this->tables['ig_competitor_snapshots'][ $id ] );
					++$deleted;
				}
			}
			return $deleted;
		}

		return parent::query( $sql );
	}

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'ig_zernio_profiles' ) ) {
			if ( preg_match( "/WHERE tenant_id = '(\\d+)'/", $sql, $m ) ) {
				return $this->profiles[ (int) $m[1] ] ?? null;
			}
			return null;
		}

		foreach ( $this->tables as $name => $rows ) {
			if ( ! str_contains( $sql, $name ) ) {
				continue;
			}
			if ( preg_match( '/WHERE tenant_id = \'?(\d+)\'? AND id = \'?(\d+)\'?/', $sql, $m ) ) {
				foreach ( $rows as $row ) {
					if ( (int) $row['tenant_id'] === (int) $m[1] && (int) $row['id'] === (int) $m[2] ) {
						return $row;
					}
				}
				return null;
			}
		}
		return parent::get_row( $sql, $output );
	}

	public function get_results( string $sql, $output = null ) {
		$this->queries[] = $sql;

		foreach ( $this->tables as $name => $rows ) {
			if ( ! str_contains( $sql, $name ) ) {
				continue;
			}
			$out = array_values( $rows );
			if ( str_contains( $sql, 'ORDER BY id ASC' ) ) {
				usort( $out, static fn ( $a, $b ): int => (int) $a['id'] <=> (int) $b['id'] );
			} elseif ( str_contains( $sql, 'ORDER BY id DESC' ) ) {
				usort( $out, static fn ( $a, $b ): int => (int) $b['id'] <=> (int) $a['id'] );
			}
			if ( str_contains( $sql, 'WHERE tenant_id' ) && preg_match( '/WHERE tenant_id = \'?(\d+)\'?/', $sql, $m ) ) {
				$tenant = (int) $m[1];
				$out = array_values( array_filter( $out, static fn ( array $r ): bool => (int) $r['tenant_id'] === $tenant ) );
			}
			if ( preg_match( '/AND giveaway_id = \'?(\d+)\'?/', $sql, $m ) ) {
				$gid = (int) $m[1];
				$out = array_values( array_filter( $out, static fn ( array $r ): bool => (int) $r['giveaway_id'] === $gid ) );
			}
			if ( str_contains( $sql, 'captured_for DESC' ) ) {
				usort( $out, static fn ( $a, $b ): int => strcmp( (string) $b['captured_for'], (string) $a['captured_for'] ) );
			}
			if ( str_contains( $sql, 'captured_for ASC' ) ) {
				usort( $out, static fn ( $a, $b ): int => strcmp( (string) $a['captured_for'], (string) $b['captured_for'] ) );
			}
			if ( preg_match( '/LIMIT (\d+)/', $sql, $m ) ) {
				$out = array_slice( $out, 0, (int) $m[1] );
			}
			return $out;
		}
		return parent::get_results( $sql, $output );
	}

	public function get_var( string $sql ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'ig_accounts' ) && str_contains( $sql, 'is_active = 1' ) ) {
			if ( preg_match( "/WHERE tenant_id = '(\\d+)'/", $sql, $m ) ) {
				$found = array_values( array_filter(
					$this->tables['ig_accounts'],
					static fn ( array $r ): bool => (int) $r['tenant_id'] === (int) $m[1] && 1 === (int) $r['is_active']
				) );
				return $found ? (string) ( (int) end( $found )['id'] ) : null;
			}
			return null;
		}

		foreach ( $this->tables as $name => $rows ) {
			if ( ! str_contains( $sql, $name ) ) {
				continue;
			}
			// SELECT id ... WHERE <unique-ish predicate>
			if ( 'ig_giveaway_entries' === $name && preg_match( "/giveaway_id = '(\\d+)'/", $sql, $m ) ) {
				foreach ( $rows as $row ) {
					if ( (int) $row['giveaway_id'] === (int) $m[1] && preg_match( "/subscriber = '([^']*)'/", $sql, $s ) && (string) $row['subscriber'] === $s[1] ) {
						return (string) $row['id'];
					}
				}
				return null;
			}
			if ( 'ig_insights' === $name && preg_match( "/metric = '([^']+)'/", $sql, $mm ) ) {
				foreach ( $rows as $row ) {
					if ( (string) $row['metric'] === $mm[1]
						&& preg_match( "/captured_for = '([^']+)'/", $sql, $d ) && (string) $row['captured_for'] === $d[1]
						&& ( ( ! preg_match( "/dimension = ''/", $sql ) && preg_match( "/dimension = '([^']*)'/", $sql, $dim ) && (string) $row['dimension'] === $dim[1] )
							|| ( preg_match( "/dimension = ''/", $sql ) && '' === (string) $row['dimension'] ) ) ) {
						return (string) $row['id'];
					}
				}
				return null;
			}
			if ( 'ig_competitors' === $name && preg_match( "/handle = '([^']+)'/", $sql, $h ) ) {
				foreach ( $rows as $row ) {
					if ( (string) $row['handle'] === $h[1] && preg_match( "/tenant_id = '(\d+)'/", $sql, $t ) && (int) $row['tenant_id'] === (int) $t[1] ) {
						return (string) $row['id'];
					}
				}
				return null;
			}
			if ( 'ig_competitor_snapshots' === $name && preg_match( "/competitor_id = '(\\d+)'/", $sql, $c ) ) {
				foreach ( $rows as $row ) {
					if ( (int) $row['competitor_id'] === (int) $c[1] && preg_match( "/captured_for = '([^']+)'/", $sql, $d ) && (string) $row['captured_for'] === $d[1] ) {
						return (string) $row['id'];
					}
				}
				return null;
			}
		}
		return parent::get_var( $sql );
	}

	private function where_matches( array $row, array $where ): bool {
		foreach ( $where as $column => $value ) {
			if ( (int) $row[ $column ] !== (int) $value && (string) $row[ $column ] !== (string) $value ) {
				return false;
			}
		}
		return true;
	}

	private function older_than_cutoff( array $row, string $sql ): bool {
		if ( ! preg_match( "/captured_for < '([^']+)'/", $sql, $m ) ) {
			return false;
		}
		return strcmp( (string) $row['captured_for'], $m[1] ) < 0;
	}
}

final class GrowthIntelTest extends TestCase {

	private GrowthDb $db;
	private GiveawayDrawService $giveaways;
	private InsightService $insights;
	private CompetitorService $competitors;

	public function run(): void {
		$this->creation_publishes_only_the_seed_commitment();
		$this->duplicate_entries_are_refused_even_under_the_race();
		$this->entries_respect_the_window_and_the_closed_giveaway();
		$this->the_draw_is_re_derivable_from_the_audit_packet();
		$this->a_second_draw_loses_honestly();
		$this->an_empty_pool_refuses_to_draw();
		$this->cancel_only_works_while_open();
		$this->manual_rows_carry_provenance_and_correct_by_day();
		$this->provider_ingest_flows_through_the_official_path();
		$this->an_unconnected_store_ingests_nothing();
		$this->retention_prunes_only_past_the_window();
		$this->competitors_and_snapshots_stay_tenant_scoped();
	}

	// ------------------------------------------------------------ giveaways

	private function creation_publishes_only_the_seed_commitment(): void {
		$this->fresh();
		$result = $this->giveaways->create( [ 'title' => 'Norooz', 'account_id' => 5 ], 1 );
		$this->assert_true( $result['ok'], 'the giveaway is created' );
		$this->assert_same( 64, strlen( (string) $result['commitment'] ), 'the commitment is a sha256 hex' );

		$row = $this->db->tables['ig_giveaways'][ $result['id'] ];
		$this->assert_true( str_starts_with( (string) $row['server_seed'], 'igbz1:' ), 'the seed is encrypted at rest' );
		$this->assert_same( (string) $result['commitment'], (string) $row['server_seed_hash'], 'the stored hash is the published commitment' );

		$view = $this->giveaways->get( 1, $result['id'] );
		$this->assert_false( array_key_exists( 'server_seed', $view ), 'the public view never exposes the seed' );
		$this->assert_same( (string) $result['commitment'], (string) $view['commitment'], 'the commitment is visible from day one' );

		$bad = $this->giveaways->create( [ 'starts_at' => '2030-01-02 00:00:00', 'ends_at' => '2030-01-01 00:00:00' ], 1 );
		$this->assert_false( $bad['ok'], 'an inverted window is refused' );
		$this->assert_same( 'bad_window', $bad['error'], 'the refusal says why' );
	}

	private function duplicate_entries_are_refused_even_under_the_race(): void {
		$this->fresh();
		$id = $this->open_giveaway();

		$first = $this->giveaways->add_entry( 1, $id, [ 'subscriber' => '@Sara_Joon', 'source' => 'comment' ] );
		$this->assert_true( $first['ok'], 'the first entry lands' );

		$again = $this->giveaways->add_entry( 1, $id, [ 'subscriber' => 'sara_joon' ] );
		$this->assert_false( $again['ok'], 'a second entry by the same person (case-insensitive) is refused' );
		$this->assert_same( 'duplicate_entry', $again['error'], 'the refusal says duplicate' );
		$this->assert_same( $first['id'], $again['id'], 'the refusal points at the original entry' );

		// The pre-check race: the UNIQUE key is the backstop.
		$this->db->entry_unique_race = true;
		$raced = $this->giveaways->add_entry( 1, $id, [ 'subscriber' => 'sara_joon' ] );
		$this->assert_false( $raced['ok'], 'the race loser gets no second row' );
		$this->assert_same( 'duplicate_entry', $raced['error'], 'the race loser is refused the same way' );

		$pool = $this->giveaways->entries( 1, $id );
		$this->assert_same( 1, count( $pool ), 'exactly one row exists for that person' );

		$anon = $this->giveaways->add_entry( 1, $id, [ 'subscriber' => '' ] );
		$this->assert_false( $anon['ok'], 'an entry without a subscriber is refused' );
	}

	private function entries_respect_the_window_and_the_closed_giveaway(): void {
		$this->fresh();

		$future = $this->giveaways->create( [ 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ], 1 )['id'];
		$this->assert_same( 'entry_window', $this->giveaways->add_entry( 1, $future, [ 'subscriber' => 'early' ] )['error'], 'entries before the window are refused' );

		// A giveaway cannot be *created* with a past end (create refuses it), so the
		// closed-window row is seeded the way a long-running giveaway actually looks.
		$past = $this->db->seed( 'ig_giveaways', [
			'tenant_id'        => 1,
			'account_id'       => 0,
			'ig_post_id'       => '',
			'title'            => 'closed',
			'status'           => 'open',
			'entries_count'    => 0,
			'starts_at'        => gmdate( 'Y-m-d H:i:s', time() - 7200 ),
			'ends_at'          => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
			'server_seed'      => '',
			'server_seed_hash' => '',
			'created_at'       => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'       => gmdate( 'Y-m-d H:i:s' ),
		] );
		$this->assert_same( 'entry_window', $this->giveaways->add_entry( 1, $past, [ 'subscriber' => 'late' ] )['error'], 'entries after the window are refused' );

		$drawn = $this->open_giveaway();
		$this->giveaways->add_entry( 1, $drawn, [ 'subscriber' => 'a' ] );
		$this->giveaways->draw( 1, $drawn );
		$this->assert_same( 'giveaway_closed', $this->giveaways->add_entry( 1, $drawn, [ 'subscriber' => 'b' ] )['error'], 'no entries after the draw' );

		$this->assert_same( 'not_found', $this->giveaways->add_entry( 2, $drawn, [ 'subscriber' => 'c' ] )['error'], 'a foreign tenant sees no giveaway' );
	}

	private function the_draw_is_re_derivable_from_the_audit_packet(): void {
		$this->fresh();
		$id = $this->open_giveaway();
		foreach ( [ 'nima', 'sara', 'kosar', 'arash', 'yasi' ] as $who ) {
			$this->giveaways->add_entry( 1, $id, [ 'subscriber' => $who ] );
		}

		$result = $this->giveaways->draw( 1, $id );
		$this->assert_true( $result['ok'], 'the draw runs' );
		$audit = $result['audit'];
		$this->assert_same( 64, strlen( (string) $audit['server_seed'] ), 'the draw reveals the seed' );
		$this->assert_true( (int) $audit['winner_no'] >= 1 && (int) $audit['winner_no'] <= 5, 'the winner number addresses the pool' );

		// The auditor's path: pool + packet → the same winner, and a tampered packet fails.
		$pool  = $this->giveaways->entries( 1, $id );
		$check = GiveawayDrawService::verify_audit( $audit, $pool );
		$this->assert_true( $check['ok'], 'the audit packet re-derives the winner' );
		$this->assert_same( (int) $audit['winner_entry_id'], $check['winner_entry_id'], 'the re-derived entry is the recorded one' );

		$tampered = $audit;
		$tampered['winner_no'] = ( 5 === (int) $audit['winner_no'] ) ? 1 : ( (int) $audit['winner_no'] + 1 );
		$this->assert_false( GiveawayDrawService::verify_audit( $tampered, $pool )['ok'], 'a re-rolled winner number does not verify' );

		$wrong_pool = array_slice( $pool, 0, 4 );
		$this->assert_false( GiveawayDrawService::verify_audit( $audit, $wrong_pool )['ok'], 'a pool with a removed entry does not verify' );

		// The stored row agrees with the packet.
		$row = $this->db->tables['ig_giveaways'][ $id ];
		$this->assert_same( 'drawn', (string) $row['status'], 'the row is drawn' );
		$this->assert_same( 5, (int) $row['entries_count'], 'the pool size is recorded on the row' );
		$this->assert_same( (string) $row['pool_hash'], (string) $audit['pool_hash'], 'the stored pool hash matches the packet' );

		$view = $this->giveaways->get( 1, $id );
		$this->assert_same( (int) $audit['winner_no'], (int) $view['audit']['winner_no'], 'the public view carries the audit packet' );
	}

	private function a_second_draw_loses_honestly(): void {
		$this->fresh();
		$id = $this->open_giveaway();
		$this->giveaways->add_entry( 1, $id, [ 'subscriber' => 'only' ] );

		$first = $this->giveaways->draw( 1, $id );
		$again = $this->giveaways->draw( 1, $id );
		$this->assert_false( $again['ok'], 'the second draw is refused' );
		$this->assert_same( 'already_drawn', $again['error'], 'the refusal says why' );

		$row = $this->db->tables['ig_giveaways'][ $id ];
		$this->assert_same( (string) $row['winner_subscriber'], $first['winner'], 'the first winner stands' );
		$this->assert_same( $first['audit']['server_seed'], json_decode( (string) $row['audit'], true )['server_seed'], 'the audit was not rewritten' );
	}

	private function an_empty_pool_refuses_to_draw(): void {
		$this->fresh();
		$id = $this->open_giveaway();
		$this->assert_same( 'no_entries', $this->giveaways->draw( 1, $id )['error'], 'a draw with no entries is refused' );
	}

	private function cancel_only_works_while_open(): void {
		$this->fresh();
		$id = $this->open_giveaway();
		$this->assert_true( $this->giveaways->cancel( 1, $id )['ok'], 'an open giveaway is cancelled' );
		$this->assert_false( $this->giveaways->cancel( 1, $id )['ok'], 'a cancelled giveaway cannot be cancelled again' );
		$this->assert_same( 'giveaway_cancelled', $this->giveaways->draw( 1, $id )['error'], 'a cancelled giveaway cannot be drawn' );
	}

	// ------------------------------------------------------------- insights

	private function manual_rows_carry_provenance_and_correct_by_day(): void {
		$this->fresh();
		$this->db->seed( 'ig_accounts', [ 'tenant_id' => 1, 'username' => 'shop', 'is_active' => 1 ] );

		$ok = $this->insights->record( 1, [ 'account_id' => 7, 'metric' => 'Followers', 'value' => 1200 ] );
		$this->assert_true( $ok['ok'], 'a manual row lands' );

		$row = array_values( $this->db->tables['ig_insights'] )[0];
		$this->assert_same( 'followers', (string) $row['metric'], 'metrics are normalised' );
		$this->assert_same( 'manual', (string) $row['source'], 'the source is recorded' );
		$this->assert_same( gmdate( 'Y-m-d' ), (string) $row['captured_for'], 'the day defaults to today' );

		// Re-submitting the same day corrects the value instead of stacking rows.
		$this->insights->record( 1, [ 'account_id' => 7, 'metric' => 'followers', 'value' => 1250 ] );
		$this->assert_same( 1, count( $this->db->tables['ig_insights'] ), 'one row per account+metric+day' );
		$this->assert_same( 1250.0, (float) array_values( $this->db->tables['ig_insights'] )[0]['value'], 'the value was corrected' );

		$bad = $this->insights->record( 1, [ 'account_id' => 7, 'metric' => 'followers', 'captured_for' => 'not-a-date' ] );
		$this->assert_false( $bad['ok'], 'a bad date is refused' );

		$this->insights->record( 2, [ 'account_id' => 7, 'metric' => 'followers', 'value' => 9 ] );
		$this->assert_same( 1, count( $this->insights->list( 1 ) ), 'the other tenant\'s row is invisible' );
	}

	private function provider_ingest_flows_through_the_official_path(): void {
		$this->fresh();
		$this->db->seed( 'ig_accounts', [ 'tenant_id' => 1, 'username' => 'shop', 'is_active' => 1 ] );
		$account_row_id = (int) max( array_keys( $this->db->tables['ig_accounts'] ) );

		$now = current_time( 'mysql', true );
		$this->db->profiles[1] = [
			'id'         => 11,
			'tenant_id'  => 1,
			'profile_id' => 'prof-1',
			'status'     => ZernioConnectionService::STATUS_CONNECTED,
			'key_enc'    => Crypto::encrypt( 'sk-store' ),
			'account_id' => 'acc-1',
			'connected_at' => $now,
		];

		igbz_test_queue_http( [
			'match'  => '/analytics',
			'status' => 200,
			'body'   => wp_json_encode( [ 'metrics' => [ 'followers' => 4321, 'impressions' => '987', 'nested' => [ 'x' => 1 ] ] ] ),
		] );

		$result = $this->insights->ingest( 1 );
		$this->assert_true( $result['ok'], 'the ingest runs' );
		$this->assert_same( 2, $result['stored'], 'the two numeric metrics land' );
		$this->assert_same( 1, $result['skipped'], 'the non-numeric metric is skipped honestly' );

		$row = array_values( $this->db->tables['ig_insights'] )[0];
		$this->assert_same( 'zernio', (string) $row['source'], 'provider rows carry their provenance' );
		$this->assert_same( 'acc-1', (string) $row['provider_ref'], 'the provider account string is kept' );
		$this->assert_same( $account_row_id, (int) $row['account_id'], 'the row is bound to the store account entity' );
	}

	private function an_unconnected_store_ingests_nothing(): void {
		$this->fresh();
		$this->db->seed( 'ig_accounts', [ 'tenant_id' => 1, 'username' => 'shop', 'is_active' => 1 ] );
		$result = $this->insights->ingest( 1 );
		$this->assert_false( $result['ok'], 'the ingest refuses' );
		$this->assert_same( 'not_connected', $result['error'], 'no profile means an honest refusal' );
		$this->assert_same( 0, count( $this->db->tables['ig_insights'] ), 'nothing was invented' );
	}

	private function retention_prunes_only_past_the_window(): void {
		$this->fresh();
		$old = gmdate( 'Y-m-d', strtotime( '-800 days' ) );
		$this->db->seed( 'ig_insights', [ 'tenant_id' => 1, 'account_id' => 7, 'metric' => 'followers', 'dimension' => '', 'value' => 1.0, 'captured_for' => $old, 'source' => 'manual', 'provider_ref' => '', 'created_at' => $old ] );
		$this->db->seed( 'ig_insights', [ 'tenant_id' => 1, 'account_id' => 7, 'metric' => 'reach', 'dimension' => '', 'value' => 2.0, 'captured_for' => gmdate( 'Y-m-d' ), 'source' => 'manual', 'provider_ref' => '', 'created_at' => $old ] );

		igbz()->settings()->set( 'ig.insights_retention_days', '30' ); // below the floor
		$deleted = $this->insights->prune();
		$this->assert_same( 1, $deleted, 'only the out-of-window row goes' );
		$this->assert_same( 1, count( $this->db->tables['ig_insights'] ), 'the fresh row stays' );
	}

	// ---------------------------------------------------------- competitors

	private function competitors_and_snapshots_stay_tenant_scoped(): void {
		$this->fresh();

		$made = $this->competitors->save_competitor( 1, [ 'handle' => '@Rival_Shop', 'notes' => 'sector: skincare' ] );
		$this->assert_true( $made['ok'], 'the competitor lands' );
		$row = $this->db->tables['ig_competitors'][ $made['id'] ];
		$this->assert_same( 'rival_shop', (string) $row['handle'], 'handles are normalised (@ and case)' );

		$again = $this->competitors->save_competitor( 1, [ 'handle' => 'rival_shop', 'display_name' => 'Rival' ] );
		$this->assert_same( $made['id'], $again['id'], 'the same handle updates, never duplicates' );
		$this->assert_same( 'Rival', (string) $this->db->tables['ig_competitors'][ $made['id'] ]['display_name'], 'the update applied' );

		$foreign = $this->competitors->save_competitor( 2, [ 'handle' => 'rival_shop' ] );
		$this->assert_true( $foreign['ok'], 'another tenant may track the same handle' );
		$this->assert_true( $foreign['id'] !== $made['id'], 'as its own row' );

		$snap = $this->competitors->record_snapshot( 1, $made['id'], [ 'followers' => 5400, 'evidence_url' => 'https://example.com/proof', 'note' => 'screenshot' ] );
		$this->assert_true( $snap['ok'], 'a snapshot lands' );
		$this->competitors->record_snapshot( 1, $made['id'], [ 'followers' => 5600, 'captured_for' => gmdate( 'Y-m-d' ) ] );
		$this->assert_same( 1, count( $this->db->tables['ig_competitor_snapshots'] ), 'one snapshot per day corrects, not stacks' );
		$this->assert_same( 5600, (int) array_values( $this->db->tables['ig_competitor_snapshots'] )[0]['followers'], 'the day was corrected' );

		$this->assert_same( 'not_found', $this->competitors->record_snapshot( 2, $made['id'], [ 'followers' => 1 ] )['error'], 'a foreign tenant cannot snapshot another store\'s competitor' );

		$deleted = $this->competitors->delete( 1, $made['id'] );
		$this->assert_true( $deleted['ok'], 'the competitor is gone' );
		$this->assert_same( 0, count( $this->db->tables['ig_competitor_snapshots'] ), 'the evidence goes with the competitor' );
		$this->assert_same( 1, count( $this->db->tables['ig_competitors'] ), 'only the other tenant\'s row remains' );
	}

	// --------------------------------------------------------------- setup

	private function fresh(): void {
		igbz_test_reset_settings();
		$this->db = new GrowthDb();
		$GLOBALS['wpdb'] = $this->db;

		$db     = new Db();
		$logger = igbz()->get( 'logger' );

		$this->giveaways   = new GiveawayDrawService( $db, $logger );
		$this->competitors = new CompetitorService( $db, $logger );

		$client     = new ZernioClient( new Http( $logger ), $logger );
		$connection = new ZernioConnectionService( $db, $logger, $client );
		$social     = new ZernioSocialService( $db, $logger, $connection, $client );
		$this->insights = new InsightService( $db, $logger, igbz()->settings(), $social );
	}

	private function open_giveaway(): int {
		return $this->giveaways->create( [ 'title' => 'test' ], 1 )['id'];
	}
}
