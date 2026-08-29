<?php
/**
 * Phase 41 — gamification: credits are idempotent per (user, reason, reference), the daily
 * cap blocks abuse, points expire, spending is a negative ledger row so one SUM reconciles
 * everything, redemptions are idempotent per key, the race counts earning only, and the
 * reconciliation report explains the ledger.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\MultiTenant\Gamification\GamificationService;
use IGBZ\Suite\Support\Db;

/** In-memory engine for the points ledger, rewards and redemptions. */
final class GameDb extends wpdb {

	/** @var array<string,array<int,array<string,mixed>>> */
	public array $tables = [
		'ig_points_ledger'      => [],
		'ig_point_rewards'      => [],
		'ig_reward_redemptions' => [],
	];

	private int $next_id = 1;

	/** @param array<string,mixed> $row */
	public function seed( string $table, array $row ): int {
		$id                          = $this->next_id++;
		$row['id']                   = $id;
		$this->tables[ $table ][ $id ] = $row;

		return $id;
	}

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'ig_points_ledger' ) ) {
			if ( preg_match( "/WHERE id = '?(\d+)'?/", $sql, $m ) ) {
				return $this->tables['ig_points_ledger'][ (int) $m[1] ] ?? null;
			}
			if ( preg_match( "/WHERE user_id = '?(\d+)'? AND reason = '([^']*)' AND reference = '([^']*)'/", $sql, $m ) ) {
				foreach ( $this->tables['ig_points_ledger'] as $row ) {
					if ( (string) $row['user_id'] === $m[1] && (string) $row['reason'] === $m[2] && (string) $row['reference'] === $m[3] ) {
						return $row;
					}
				}
				return null;
			}
		}

		if ( str_contains( $sql, 'ig_point_rewards' ) && preg_match( "/WHERE tenant_id = '?(\d+)'? AND slug = '([^']*)'/", $sql, $m ) ) {
			foreach ( $this->tables['ig_point_rewards'] as $row ) {
				if ( (string) $row['tenant_id'] === $m[1] && (string) $row['slug'] === $m[2] ) {
					return $row;
				}
			}
			return null;
		}

		if ( str_contains( $sql, 'ig_reward_redemptions' ) ) {
			if ( preg_match( "/WHERE id = '?(\d+)'?/", $sql, $m ) ) {
				return $this->tables['ig_reward_redemptions'][ (int) $m[1] ] ?? null;
			}
			if ( preg_match( "/WHERE user_id = '?(\d+)'? AND idempotency_key = '([^']*)'/", $sql, $m ) ) {
				foreach ( $this->tables['ig_reward_redemptions'] as $row ) {
					if ( (string) $row['user_id'] === $m[1] && (string) $row['idempotency_key'] === $m[2] ) {
						return $row;
					}
				}
				return null;
			}
		}

		return parent::get_row( $sql, $output );
	}

	public function get_var( string $sql ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'negative' ) ) {
			$totals = [];
			foreach ( $this->tables['ig_points_ledger'] as $row ) {
				if ( null !== $row['expires_at'] && strcmp( (string) $row['expires_at'], gmdate( 'Y-m-d H:i:s' ) ) <= 0 ) {
					continue;
				}
				$totals[ (int) $row['user_id'] ] = ( $totals[ (int) $row['user_id'] ] ?? 0 ) + (int) $row['points'];
			}
			return (string) count( array_filter( $totals, static fn ( $t ): bool => $t < 0 ) );
		}

		if ( str_contains( $sql, 'dupes' ) ) {
			$seen  = [];
			$dupes = 0;
			foreach ( $this->tables['ig_reward_redemptions'] as $row ) {
				if ( null === $row['idempotency_key'] ) {
					continue;
				}
				$key = $row['user_id'] . ':' . $row['idempotency_key'];
				if ( isset( $seen[ $key ] ) ) {
					++$dupes;
					continue;
				}
				$seen[ $key ] = true;
			}
			return (string) $dupes;
		}

		if ( str_contains( $sql, 'SUM( points )' ) && str_contains( $sql, 'ig_points_ledger' ) ) {
			$sum = 0;
			foreach ( $this->filtered_ledger( $sql ) as $row ) {
				$sum += (int) $row['points'];
			}
			return (string) $sum;
		}

		if ( str_contains( $sql, 'COUNT(*)' ) && str_contains( $sql, 'ig_points_ledger' ) && str_contains( $sql, 'expires_at IS NOT NULL' ) ) {
			$count = 0;
			foreach ( $this->tables['ig_points_ledger'] as $row ) {
				if ( null !== $row['expires_at'] && preg_match( "/expires_at <= '([^']*)'/", $sql, $m ) && strcmp( (string) $row['expires_at'], $m[1] ) <= 0 ) {
					++$count;
				}
			}
			return (string) $count;
		}

		return parent::get_var( $sql );
	}

	public function get_results( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'GROUP BY user_id ORDER BY points DESC' ) ) {
			$totals = [];
			foreach ( $this->filtered_ledger( $sql ) as $row ) {
				if ( (int) $row['points'] <= 0 ) {
					continue;
				}
				$totals[ (int) $row['user_id'] ] = ( $totals[ (int) $row['user_id'] ] ?? 0 ) + (int) $row['points'];
			}
			arsort( $totals );
			$out = [];
			foreach ( $totals as $user_id => $points ) {
				$out[] = [ 'user_id' => $user_id, 'points' => $points ];
			}
			if ( preg_match( '/LIMIT (\d+)/', $sql, $m ) ) {
				$out = array_slice( $out, 0, (int) $m[1] );
			}
			return $out;
		}

		return parent::get_results( $sql, $output );
	}

	public function insert( string $table, array $data, $format = null ): int|bool {
		$this->queries[] = 'INSERT INTO ' . $table;
		$this->last_write = [ 'table' => $table, 'data' => $data ];
		$this->writes[]   = $this->last_write;

		foreach ( [ 'ig_points_ledger', 'ig_point_rewards', 'ig_reward_redemptions' ] as $name ) {
			if ( str_contains( $table, $name ) ) {
				if ( 'ig_points_ledger' === $name ) {
					foreach ( $this->tables['ig_points_ledger'] as $row ) {
						if ( (string) $row['user_id'] === (string) $data['user_id'] && (string) $row['reason'] === (string) $data['reason'] && (string) $row['reference'] === (string) $data['reference'] ) {
							$this->insert_id = 0;
							return 0; // unique index
						}
					}
				}
				if ( 'ig_reward_redemptions' === $name ) {
					foreach ( $this->tables['ig_reward_redemptions'] as $row ) {
						if ( (string) $row['user_id'] === (string) $data['user_id'] && (string) $row['idempotency_key'] === (string) $data['idempotency_key'] ) {
							$this->insert_id = 0;
							return 0;
						}
					}
				}
				$this->insert_id = $this->seed( $name, $data );
				return 1;
			}
		}

		return parent::insert( $table, $data, $format );
	}

	/** @return array<int,array<string,mixed>> */
	private function filtered_ledger( string $sql ): array {
		$out = [];
		foreach ( $this->tables['ig_points_ledger'] as $row ) {
			if ( preg_match( "/tenant_id = '?(\d+)'? AND user_id = '?(\d+)'?/", $sql, $m ) ) {
				if ( (string) $row['tenant_id'] !== $m[1] || (string) $row['user_id'] !== $m[2] ) {
					continue;
				}
			} elseif ( preg_match( "/WHERE tenant_id = '?(\d+)'?/", $sql, $m ) && (string) $row['tenant_id'] !== $m[1] ) {
				continue;
			}
			if ( str_contains( $sql, 'expires_at IS NULL OR expires_at >' ) ) {
				if ( null !== $row['expires_at'] && strcmp( (string) $row['expires_at'], gmdate( 'Y-m-d H:i:s' ) ) <= 0 ) {
					continue;
				}
			}
			if ( str_contains( $sql, 'points > 0' ) && (int) $row['points'] <= 0 ) {
				continue;
			}
			if ( preg_match( "/created_at >= '([^']*)'/", $sql, $m ) && strcmp( (string) $row['created_at'], $m[1] ) < 0 ) {
				continue;
			}
			$out[] = $row;
		}
		return $out;
	}
}

final class GamificationTest extends TestCase {

	private Db $db;
	private GameDb $gdb;
	private GamificationService $service;

	private function boot(): void {
		igbz_test_reset_settings();

		$this->gdb         = new GameDb();
		$GLOBALS['wpdb']   = $this->gdb;

		$this->db = new Db();
		$ref = new ReflectionProperty( Db::class, 'is_sqlite' );
		$ref->setValue( $this->db, true );

		$this->service = new GamificationService( $this->db, igbz()->settings(), new IGBZ\Suite\Support\Logger( igbz()->settings() ) );

		$this->gdb->seed( 'ig_point_rewards', [
			'tenant_id' => 7, 'slug' => 'free-post', 'title' => 'Free post', 'cost_points' => 100, 'is_active' => 1, 'created_at' => gmdate( 'Y-m-d H:i:s' ),
		] );
	}

	public function run(): void {
		$this->test_credits_are_idempotent_and_capped();
		$this->test_expiry_stops_points_from_counting();
		$this->test_redemption_is_one_negative_row_and_idempotent();
		$this->test_the_race_counts_earning_not_spending();
		$this->test_reconciliation_explains_the_ledger();
	}

	public function test_credits_are_idempotent_and_capped(): void {
		$this->boot();

		$first = $this->service->credit( 7, 3, 40, 'login', 'd:1' );
		$this->assert_true( $first['ok'], 'the credit lands' );

		$replay = $this->service->credit( 7, 3, 40, 'login', 'd:1' );
		$this->assert_true( $replay['ok'], 'the replay succeeds' );
		$this->assert_same( (int) $first['row']['id'], (int) $replay['row']['id'], 'the replay returns the same row' );
		$this->assert_same( 40, $this->service->balance( 7, 3 ), 'a replayed event mints nothing' );

		igbz()->settings()->set( 'gamification.daily_cap', '50' );
		$blocked = $this->service->credit( 7, 3, 20, 'purchase', 'p:1' );
		$this->assert_false( $blocked['ok'], 'the cap blocks the excess' );
		$this->assert_same( 'daily_cap_reached', $blocked['error'], 'the refusal names the cap' );
		$this->assert_same( 40, $this->service->balance( 7, 3 ), 'blocked points never land' );

		$this->assert_false( $this->service->credit( 7, 3, 0, 'login', 'd:2' )['ok'], 'zero points are refused' );
	}

	public function test_expiry_stops_points_from_counting(): void {
		$this->boot();

		$this->gdb->seed( 'ig_points_ledger', [
			'tenant_id' => 7, 'user_id' => 3, 'reason' => 'event', 'reference' => 'e:1',
			'points' => 60, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ), 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
		] );
		$this->service->credit( 7, 3, 25, 'login', 'd:1' );

		$this->assert_same( 25, $this->service->balance( 7, 3 ), 'expired points stop counting' );
	}

	public function test_redemption_is_one_negative_row_and_idempotent(): void {
		$this->boot();
		$this->service->credit( 7, 3, 150, 'purchase', 'p:1' );

		$poor = $this->service->redeem( 7, 4, 'free-post', 'k:9' );
		$this->assert_false( $poor['ok'], 'no balance, no reward' );
		$this->assert_same( 'insufficient_points', $poor['error'], 'the refusal names the gap' );

		$first = $this->service->redeem( 7, 3, 'free-post', 'k:1' );
		$this->assert_true( $first['ok'], 'the reward is issued' );
		$this->assert_same( 50, $this->service->balance( 7, 3 ), 'the spend is one negative ledger row' );

		$replay = $this->service->redeem( 7, 3, 'free-post', 'k:1' );
		$this->assert_true( $replay['ok'], 'the replay succeeds' );
		$this->assert_same( (int) $first['redemption']['id'], (int) $replay['redemption']['id'], 'the replay returns the same redemption' );
		$this->assert_same( 50, $this->service->balance( 7, 3 ), 'a replayed redemption spends nothing' );

		$this->assert_false( $this->service->redeem( 7, 3, 'free-post', '' )['ok'], 'no key, no redemption' );
		$this->assert_false( $this->service->redeem( 7, 3, 'nope', 'k:2' )['ok'], 'an unknown reward is refused' );
	}

	public function test_the_race_counts_earning_not_spending(): void {
		$this->boot();
		$this->service->credit( 7, 3, 120, 'purchase', 'p:1' );
		$this->service->credit( 7, 4, 80, 'purchase', 'p:2' );
		$this->service->redeem( 7, 3, 'free-post', 'k:1' ); // user 3 spends 100

		$race = $this->service->race( 7, 30, 10 );

		$this->assert_same( 2, count( $race ), 'both earners are ranked' );
		$this->assert_same( 3, $race[0]['user_id'], 'spending does not shrink the race score' );
		$this->assert_same( 120, $race[0]['points'], 'the race counts earning only' );
	}

	public function test_reconciliation_explains_the_ledger(): void {
		$this->boot();
		$this->service->credit( 7, 3, 50, 'login', 'd:1' );
		$this->gdb->seed( 'ig_points_ledger', [
			'tenant_id' => 7, 'user_id' => 3, 'reason' => 'event', 'reference' => 'e:old',
			'points' => 30, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ), 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
		] );

		$out = $this->service->reconcile();

		$this->assert_same( 1, $out['expired_now'], 'expired rows are counted' );
		$this->assert_same( 0, $out['negative_balances'], 'no user sits below zero' );
		$this->assert_same( 0, $out['duplicate_keys'], 'no redemption key repeats' );
	}
}
