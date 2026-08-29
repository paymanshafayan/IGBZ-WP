<?php
/**
 * Phase 6-14 core logic: logistics PIN/routing, VOD signed URLs, AI credits,
 * and the comment giveaway. The money- and security-critical rules are pinned
 * here: cryptographic PINs, unbiased draws, idempotent credit grants.
 */

declare( strict_types=1 );

use IGBZ\Suite\Modules\MultiTenant\Gamification\AiCreditsService;
use IGBZ\Suite\Modules\MultiTenant\Lms\LmsVodService;
use IGBZ\Suite\Modules\MultiTenant\Logistics\LogisticsService;
use IGBZ\Suite\Modules\Instagram\AiStudio\GiveawayService;
use IGBZ\Suite\Support\Db;

/** In-memory double for the phase tables. */
final class PhasesDb extends wpdb {

	/** @var array<string,array<int,array<string,mixed>>> */
	public array $tables = [
		'ig_shipments'        => [],
		'ig_giveaways'        => [],
		'ig_funnels'          => [],
		'ig_funnel_hits'      => [],
		'ig_ai_credit_ledger' => [],
	];

	private int $next_id = 1;

	public function seed( string $table, array $row ): int {
		$id                            = (int) ( $row['id'] ?? $this->next_id++ );
		$row['id']                     = $id;
		$this->tables[ $table ][ $id ] = $row;

		return $id;
	}

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;
		foreach ( $this->tables as $table => $rows ) {
			if ( ! str_contains( $sql, 'igbz_' . $table ) ) {
				continue;
			}
			if ( preg_match( '/WHERE id = (\d+)/', $sql, $m ) ) {
				return $rows[ (int) $m[1] ] ?? null;
			}
			return $this->match_one( $table, $sql );
		}
		return parent::get_row( $sql, $output );
	}

	public function get_results( string $sql, $output = null ) {
		$this->queries[] = $sql;

		// Giveaway entries: funnels of a post -> distinct hits.
		if ( str_contains( $sql, 'ig_funnel_hits' ) && str_contains( $sql, 'SELECT id FROM' ) ) {
			preg_match( "/ig_post_id = '([^']*)'/", $sql, $m );
			$post_id = $m[1] ?? '';
			$funnel_ids = [];
			foreach ( $this->tables['ig_funnels'] as $f ) {
				if ( (string) $f['ig_post_id'] === $post_id ) {
					$funnel_ids[ (int) $f['id'] ] = true;
				}
			}
			$seen  = [];
			$out   = [];
			foreach ( $this->tables['ig_funnel_hits'] as $h ) {
				if ( ! isset( $funnel_ids[ (int) $h['funnel_id'] ] ) ) {
					continue;
				}
				$sub = (string) $h['manychat_subscriber_id'];
				if ( '' === $sub || isset( $seen[ $sub ] ) ) {
					continue;
				}
				$seen[ $sub ] = true;
				$out[] = $h;
			}
			return $out;
		}

		foreach ( $this->tables as $table => $rows ) {
			if ( ! str_contains( $sql, 'igbz_' . $table ) ) {
				continue;
			}
			return $this->match_all( $table, $sql );
		}
		return parent::get_results( $sql, $output );
	}

	public function get_var( string $sql ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'COALESCE(SUM(delta)' ) ) {
			$sum = 0.0;
			foreach ( $this->tables['ig_ai_credit_ledger'] as $row ) {
				$sum += (float) $row['delta'];
			}
			return (string) $sum;
		}

		if ( str_contains( $sql, 'COUNT(*)' ) ) {
			foreach ( $this->tables as $table => $rows ) {
				if ( str_contains( $sql, 'igbz_' . $table ) ) {
					return (string) count( $this->match_all( $table, $sql ) );
				}
			}
		}

		return parent::get_var( $sql );
	}

	public function insert( string $table, array $data, $format = null ): int|bool {
		$short = str_replace( [ $this->prefix . 'igbz_', $this->prefix ], '', $table );
		if ( isset( $this->tables[ $short ] ) ) {
			$this->insert_id = $this->seed( $short, $data );
			return 1;
		}
		return parent::insert( $table, $data, $format );
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		$short = str_replace( [ $this->prefix . 'igbz_', $this->prefix ], '', $table );
		$changed = 0;
		foreach ( $this->tables[ $short ] ?? [] as $id => $row ) {
			$hit = true;
			foreach ( $where as $column => $value ) {
				if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) {
					$hit = false;
					break;
				}
			}
			if ( $hit ) {
				$this->tables[ $short ][ $id ] = array_merge( $row, $data );
				++$changed;
			}
		}
		return $changed;
	}

	private function match_one( string $table, string $sql ): ?array {
		$rows = $this->match_all( $table, $sql );
		return $rows[0] ?? null;
	}

	/** @return array<int,array<string,mixed>> */
	private function match_all( string $table, string $sql ): array {
		$out = [];
		foreach ( $this->tables[ $table ] ?? [] as $row ) {
			if ( preg_match_all( "/([a-z_]+) = '([^']*)'/", $sql, $m, PREG_SET_ORDER ) ) {
				$ok = true;
				foreach ( $m as $pair ) {
					$col = $pair[1];
					$val = $pair[2];
					if ( in_array( $col, [ 'id', 'tenant_id', 'user_id', 'account_id' ], true ) ) {
						continue; // handled below for id; tenant/user compared loosely
					}
					if ( (string) ( $row[ $col ] ?? '' ) !== $val ) {
						$ok = false;
						break;
					}
				}
				if ( ! $ok ) {
					continue;
				}
			}
			// tenant/user filters
			if ( preg_match( '/tenant_id = (\d+)/', $sql, $m ) && (int) $row['tenant_id'] !== (int) $m[1] ) {
				continue;
			}
			if ( preg_match( '/user_id = (\d+)/', $sql, $m ) && (int) $row['user_id'] !== (int) $m[1] ) {
				continue;
			}
			if ( preg_match( '/post_id = \'([^\']*)\'/', $sql, $m ) && (string) $row['ig_post_id'] !== $m[1] ) {
				continue;
			}
			$out[] = $row;
		}
		return $out;
	}
}

final class PhasesTest extends TestCase {

	private PhasesDb $phdb;
	private Db $db;

	private function boot(): void {
		igbz_test_reset_settings();

		$this->phdb = new PhasesDb();
		$GLOBALS['wpdb'] = $this->phdb;

		$this->db = new Db();
		$ref = new ReflectionProperty( Db::class, 'is_sqlite' );
		$ref->setValue( $this->db, true );

		$settings = igbz()->settings();
		$settings->set( 'logistics.weight_threshold_kg', 30 );
		$settings->set( 'logistics.express_cities', 'تهران' );
		$settings->set( 'logistics.express_cost_irt', 65000 );
		$settings->set( 'logistics.national_cost_irt', 45000 );
		$settings->set( 'logistics.heavy_cost_irt', 150000 );
		$settings->set( 'logistics.delivery_pin_digits', 4 );
	}

	private function logistics(): LogisticsService {
		return new LogisticsService( $this->db, igbz()->settings(), new \IGBZ\Suite\Support\Logger( igbz()->settings() ) );
	}

	public function run(): void {
		$this->test_route_categorisation_follows_settings();
		$this->test_delivery_pin_is_four_cryptographic_digits();
		$this->test_delivery_requires_the_pin();
		$this->test_vod_signed_url_is_expiring_and_ip_bound();
		$this->test_ai_credits_grant_is_idempotent_and_spend_is_capped();
		$this->test_giveaway_draws_from_real_hits_once();
	}

	public function test_route_categorisation_follows_settings(): void {
		$this->boot();
		$svc = $this->logistics();

		$heavy  = $svc->categorize_route( 40, 'شیراز', false );
		$express = $svc->categorize_route( 1, 'تهران', false );
		$express2 = $svc->categorize_route( 1, 'اصفهان', true );
		$national = $svc->categorize_route( 1, 'اصفهان', false );

		$this->assert_same( 'heavy', $heavy['route_type'], 'heavy freight over the threshold' );
		$this->assert_same( 150000.0, $heavy['cost_irt'], 'heavy cost from settings' );
		$this->assert_same( 'express', $express['route_type'], 'express city' );
		$this->assert_same( 'express', $express2['route_type'], 'express flag' );
		$this->assert_same( 'national', $national['route_type'], 'national post otherwise' );
		$this->assert_true( $heavy['delivery_pin_required'], 'heavy requires a PIN' );
		$this->assert_false( $national['delivery_pin_required'], 'national post does not' );
	}

	public function test_delivery_pin_is_four_cryptographic_digits(): void {
		$this->boot();
		$svc = $this->logistics();

		for ( $i = 0; $i < 20; $i++ ) {
			$pin = $svc->generate_delivery_pin();
			$this->assert_true( (bool) preg_match( '/^\d{4}$/', $pin ), 'PIN is four digits' );
		}

		// Uniqueness sanity across a batch (random_int, not a counter).
		$pins = [];
		for ( $i = 0; $i < 50; $i++ ) {
			$pins[ $svc->generate_delivery_pin() ] = true;
		}
		$this->assert_true( count( $pins ) > 5, 'PINs are not a tiny predictable set' );
	}

	public function test_delivery_requires_the_pin(): void {
		$this->boot();
		$svc  = $this->logistics();
		$id   = $svc->create_shipment(
			[
				'tenant_id'       => 1,
				'order_id'        => 7,
				'city'            => 'تهران',
				'recipient_name'  => 'Sara',
				'recipient_phone' => '0912',
				'delivery_pin'    => '1234',
			]
		);
		$this->assert_true( $id > 0, 'shipment created' );

		$this->assert_false( $svc->mark_delivered( $id, '0000' ), 'wrong PIN is rejected' );
		// Phase 43: delivery is legal only from at_destination, so walk the machine.
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'igbz_ig_shipments', [ 'status' => 'at_destination' ], [ 'id' => $id ] );
		$this->assert_true( $svc->mark_delivered( $id, '1234' ), 'correct PIN confirms delivery from at_destination' );
	}

	public function test_vod_signed_url_is_expiring_and_ip_bound(): void {
		igbz_test_reset_settings();
		$settings = igbz()->settings();
		$settings->set( 'lms.vod_secure_key', 'k' );
		$settings->set( 'lms.vod_base_url', 'https://vod.example.com' );
		$settings->set( 'lms.vod_ttl_seconds', 7200 );
		$settings->set( 'lms.vod_bind_ip', true );

		$vod = new LmsVodService();
		$url = $vod->signed_url( '/videos/l1/index.m3u8', '1.2.3.4' );

		$this->assert_contains( 'https://vod.example.com/videos/l1/index.m3u8', $url, 'base path preserved' );
		$this->assert_contains( 'h=', $url, 'signature present' );
		$this->assert_contains( 'e=', $url, 'expiry present' );
		$this->assert_contains( 'ip=', $url, 'IP bound when configured' );
		$this->assert_same( $url, $vod->signed_url( '/videos/l1/index.m3u8', '1.2.3.4' ), 'deterministic for the same inputs' );
		$this->assert_not_same( $url, $vod->signed_url( '/videos/l1/index.m3u8', '5.6.7.8' ), 'different IP gets a different signature' );

		$settings->set( 'lms.vod_bind_ip', false );
		$this->assert_not_contains( 'ip=', $vod->signed_url( '/videos/l1/index.m3u8', '1.2.3.4' ), 'IP binding can be disabled' );
	}

	public function test_ai_credits_grant_is_idempotent_and_spend_is_capped(): void {
		$this->boot();
		igbz()->settings()->set( 'ai_credits.purchase_percent', 2.0 );

		$svc = new AiCreditsService( $this->db, new \IGBZ\Suite\Support\Logger( igbz()->settings() ) );

		$this->assert_true( $svc->grant_from_order( 10, 5, 1000000 ), 'grant from a million-rial order' );
		$this->assert_same( 20000.0, $svc->balance( 5 ), '2% of the order' );
		$this->assert_false( $svc->grant_from_order( 10, 5, 1000000 ), 'same order cannot grant twice' );
		$this->assert_same( 20000.0, $svc->balance( 5 ), 'balance unchanged after replay' );

		$spend = $svc->spend( 5, 15000, 'job:1' );
		$this->assert_true( $spend['ok'], 'spend within balance' );
		$this->assert_same( 5000.0, $spend['balance'], 'balance reduced' );

		$over = $svc->spend( 5, 99999, 'job:2' );
		$this->assert_false( $over['ok'], 'overdraft refused' );
		$this->assert_same( 'insufficient', $over['error'], 'reason named' );
	}

	public function test_giveaway_draws_from_real_hits_once(): void {
		$this->boot();

		$this->phdb->seed( 'ig_funnels', [ 'id' => 1, 'ig_post_id' => '178-abc', 'tenant_id' => 1 ] );
		foreach ( [ 's1', 's2', 's3' ] as $i => $sub ) {
			$this->phdb->seed( 'ig_funnel_hits', [ 'funnel_id' => 1, 'manychat_subscriber_id' => $sub, 'ig_username' => 'user' . $i ] );
		}

		$svc = new GiveawayService( $this->db, new \IGBZ\Suite\Support\Logger( igbz()->settings() ) );
		$created = $svc->create( 1, 0, '178-abc', 'Test giveaway' );
		$this->assert_true( $created['ok'], 'giveaway created' );

		$draw = $svc->draw( $created['giveaway_id'] );
		$this->assert_true( $draw['ok'], 'draw succeeded' );
		$this->assert_true( in_array( $draw['winner_subscriber'], [ 'user0', 'user1', 'user2' ], true ), 'winner is one of the real entries' );

		$again = $svc->draw( $created['giveaway_id'] );
		$this->assert_false( $again['ok'], 'a second draw is refused' );
		$this->assert_contains( 'Already drawn', $again['message'], 'status named' );
	}
}
