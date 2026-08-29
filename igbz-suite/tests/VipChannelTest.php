<?php
/**
 * Phase 54 — the VIP channel hardened end to end.
 *
 * Money: a double-tapped subscribe reuses its pending row, payment rows are never shared
 * between shoppers (the phase-30 dedupe matched on tenant+purpose+amount and could hand one
 * shopper's payment to another buying the same price), membership activation and expiry are
 * conditional flips, and an entitlement grant that loses the UNIQUE race revives the row it
 * lost to — money paid must always leave an entitlement behind.
 *
 * Lifecycle: publish is a state machine (an expired post cannot be resurrected), expiry flips
 * conditionally, and the media purge is a shred-then-delete with a ledger — the JSON stays
 * until every file is provably gone, and the daily reconcile retries the rest and settles
 * drifted denormalised counts.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\Instagram\Vip\VipAccessService;
use IGBZ\Suite\Modules\Instagram\Vip\VipBillingService;
use IGBZ\Suite\Modules\Instagram\Vip\VipMediaService;
use IGBZ\Suite\Modules\Instagram\Vip\VipPostService;
use IGBZ\Suite\Modules\MultiTenant\Payments\GatewayInterface;
use IGBZ\Suite\Modules\MultiTenant\Payments\PaymentRequestResult;
use IGBZ\Suite\Modules\MultiTenant\Payments\PaymentService;
use IGBZ\Suite\Modules\MultiTenant\Payments\PaymentVerifyResult;
use IGBZ\Suite\Support\Db;

/** In-memory engine for the VIP tables + payments. */
final class VipChannelDb extends wpdb {

	/** @var array<string,array<int,array<string,mixed>>> */
	public array $tables = [
		'vip_plans'          => [],
		'vip_memberships'    => [],
		'vip_posts'          => [],
		'vip_entitlements'   => [],
		'vip_post_likes'     => [],
		'vip_post_comments'  => [],
		'payments'           => [],
	];

	private int $next_id = 1;

	/** When true, an entitlement insert for an already-held (user,post) fails like the UNIQUE key. */
	public bool $entitlement_unique_race = false;

	/** When true, the next entitlement pre-check select misses (the race window). */
	public bool $entitlement_first_select_misses = false;

	public function seed( string $table, array $row ): int {
		$id = $this->next_id++;
		$row['id'] = $id;
		$this->tables[ $table ][ $id ] = $row;
		return $id;
	}

	public function insert( string $table, array $data, $format = null ): int|bool {
		foreach ( $this->tables as $name => $rows ) {
			if ( str_contains( $table, $name ) ) {
				if ( 'vip_entitlements' === $name && $this->entitlement_unique_race ) {
					foreach ( $rows as $row ) {
						if ( (int) $row['user_id'] === (int) $data['user_id'] && (int) $row['post_id'] === (int) $data['post_id'] ) {
							return false;
						}
					}
				}
				$id = $this->next_id++;
				$data['id'] = $id;
				$this->tables[ $name ][ $id ] = $data;
				$this->insert_id = $id;
				return 1;
			}
		}
		return parent::insert( $table, $data, $format );
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		foreach ( $this->tables as $name => $rows ) {
			if ( str_contains( $table, $name ) ) {
				$changed = 0;
				foreach ( $rows as $id => $row ) {
					$hit = true;
					foreach ( $where as $column => $value ) {
						if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) {
							$hit = false;
							break;
						}
					}
					if ( $hit ) {
						$this->tables[ $name ][ $id ] = array_merge( $row, $data );
						++$changed;
					}
				}
				return $changed;
			}
		}
		return parent::update( $table, $data, $where, $format, $where_format );
	}

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;

		foreach ( $this->tables as $name => $rows ) {
			if ( ! str_contains( $sql, $name ) ) {
				continue;
			}

			if ( preg_match( '/WHERE id = \'?(\d+)\'?/', $sql, $m ) && ! str_contains( $sql, 'ORDER BY' ) ) {
				return $this->tables[ $name ][ (int) $m[1] ] ?? null;
			}

			if ( 'vip_entitlements' === $name && preg_match( '/WHERE user_id = \'?(\d+)\'? AND post_id = \'?(\d+)\'?/', $sql, $m ) ) {
				if ( $this->entitlement_first_select_misses ) {
					$this->entitlement_first_select_misses = false;
					return null;
				}
				foreach ( $rows as $row ) {
					if ( (int) $row['user_id'] === (int) $m[1] && (int) $row['post_id'] === (int) $m[2] ) {
						return $row;
					}
				}
				return null;
			}

			if ( 'payments' === $name && str_contains( $sql, 'ORDER BY id DESC' ) ) {
				return $this->match_payment( $sql );
			}

			if ( 'vip_memberships' === $name && str_contains( $sql, 'status = \'pending\'' ) ) {
				$found = array_values( array_filter(
					$rows,
					static fn ( array $row ): bool => 'pending' === (string) $row['status']
				) );
				usort( $found, static fn ( $a, $b ): int => (int) $b['id'] <=> (int) $a['id'] );
				return $found[0] ?? null;
			}
		}
		return parent::get_row( $sql, $output );
	}

	public function get_var( string $sql, $x = null, $y = null ) {
		$this->queries[] = $sql;

		foreach ( $this->tables as $name => $rows ) {
			if ( ! str_contains( $sql, $name ) ) {
				continue;
			}

			if ( str_contains( $sql, 'MAX(ends_at)' ) ) {
				$best = null;
				foreach ( $rows as $row ) {
					if ( 'active' !== (string) $row['status'] || empty( $row['ends_at'] ) ) {
						continue;
					}
					if ( null === $best || strcmp( (string) $row['ends_at'], (string) $best ) > 0 ) {
						$best = (string) $row['ends_at'];
					}
				}
				return $best;
			}

			if ( preg_match( '/^SELECT COUNT\(\*\) FROM/', $sql ) ) {
				$count = 0;
				foreach ( $rows as $row ) {
					if ( $this->count_matches( $name, $row, $sql ) ) {
						++$count;
					}
				}
				return (string) $count;
			}

			if ( preg_match( '/WHERE id = \'?(\d+)\'?/', $sql, $m ) ) {
				$row = $this->tables[ $name ][ (int) $m[1] ] ?? null;
				if ( preg_match( '/^SELECT (\w+) FROM/', $sql, $c ) ) {
					return null === $row ? null : (string) ( $row[ $c[1] ] ?? '' );
				}
				return null === $row ? null : '1';
			}

			if ( 'vip_memberships' === $name && preg_match( "/status = '([^']*)'/", $sql, $st ) ) {
				$found = array_values( array_filter(
					$rows,
					static fn ( array $row ): bool => $st[1] === (string) $row['status']
				) );
				if ( preg_match( "/ends_at IS NULL OR ends_at > '([^']*)'/", $sql, $gt ) ) {
					$found = array_values( array_filter(
						$found,
						static fn ( array $row ): bool => empty( $row['ends_at'] ) || strcmp( (string) $row['ends_at'], $gt[1] ) > 0
					) );
				}
				usort( $found, static fn ( $a, $b ): int => (int) $b['id'] <=> (int) $a['id'] );
				return $found ? (string) $found[0]['id'] : null;
			}
		}
		return parent::get_var( $sql, $x, $y );
	}

	public function get_results( string $sql, $output = null ) {
		$this->queries[] = $sql;

		foreach ( $this->tables as $name => $rows ) {
			if ( ! str_contains( $sql, $name ) ) {
				continue;
			}

			$out = [];
			foreach ( $rows as $row ) {
				if ( $this->result_matches( $name, $row, $sql ) ) {
					$out[] = $row;
				}
			}
			usort( $out, static fn ( $a, $b ): int => (int) $a['id'] <=> (int) $b['id'] );
			if ( preg_match( '/LIMIT \'?(\d+)\'?/', $sql, $l ) ) {
				$out = array_slice( $out, 0, (int) $l[1] );
			}
			return $out;
		}
		return parent::get_results( $sql, $output );
	}

	public function get_col( string $sql, $x = 0 ) {
		$results = $this->get_results( $sql );
		$column  = preg_match( '/SELECT (\w+) FROM/', $sql, $m ) ? $m[1] : 'id';
		return array_map( static fn ( array $row ): string => (string) $row[ $column ], $results );
	}

	public function query( string $query ): int|bool {
		$this->queries[] = $query;

		foreach ( $this->tables as $name => $rows ) {
			if ( ! str_contains( $query, $name ) || ! preg_match( '/^UPDATE/', $query ) ) {
				continue;
			}

			if ( ! preg_match( '/WHERE id = \'?(\d+)\'?( AND (.*))?$/', $query, $w ) ) {
				continue;
			}

			$id  = (int) $w[1];
			$row = $this->tables[ $name ][ $id ] ?? null;
			if ( ! $row || ! $this->update_guard_holds( $query, $row ) ) {
				return 0;
			}

			$set_clause = trim( substr( $query, (int) strpos( $query, 'SET' ) + 3, (int) strpos( $query, ' WHERE ' ) - (int) strpos( $query, 'SET' ) - 3 ) );
			preg_match_all( '/(\w+) = \'([^\']*)\'/', $set_clause, $sets, PREG_SET_ORDER );
			foreach ( $sets as $set ) {
				$this->tables[ $name ][ $id ][ $set[1] ] = $set[2];
			}

			return 1;
		}
		return parent::query( $query );
	}

	/** @param array<string,mixed> $row */
	private function update_guard_holds( string $query, array $row ): bool {
		if ( preg_match( "/AND status = '([^']*)'/", $query, $m ) && (string) $row['status'] !== $m[1] ) {
			return false;
		}
		if ( preg_match( '/AND status IN \(\'([^\']*)\', ?\'([^\']*)\'\)/', $query, $m ) ) {
			$allowed = [ $m[1], $m[2] ];
			if ( ! in_array( (string) $row['status'], $allowed, true ) ) {
				return false;
			}
		}
		if ( preg_match( "/AND ends_at IS NOT NULL AND ends_at <= '([^']*)'/", $query, $m ) ) {
			if ( empty( $row['ends_at'] ) || strcmp( (string) $row['ends_at'], $m[1] ) > 0 ) {
				return false;
			}
		}
		if ( preg_match( "/AND expires_at IS NOT NULL AND expires_at <= '([^']*)'/", $query, $m ) ) {
			if ( empty( $row['expires_at'] ) || strcmp( (string) $row['expires_at'], $m[1] ) > 0 ) {
				return false;
			}
		}
		return true;
	}

	/** @param array<string,mixed> $row */
	private function count_matches( string $name, array $row, string $sql ): bool {
		if ( 'vip_post_likes' === $name ) {
			return preg_match( "/WHERE post_id = '?(\d+)'?/", $sql, $m ) && (int) $row['post_id'] === (int) $m[1];
		}
		if ( 'vip_post_comments' === $name ) {
			return preg_match( "/WHERE post_id = '?(\d+)'?/", $sql, $m )
				&& (int) $row['post_id'] === (int) $m[1]
				&& ( ! str_contains( $sql, "status = 'visible'" ) || 'visible' === (string) $row['status'] );
		}
		return true;
	}

	/** @param array<string,mixed> $row */
	private function result_matches( string $name, array $row, string $sql ): bool {
		if ( 'vip_posts' === $name ) {
			if ( preg_match( "/status IN \('([^']*)', ?'([^']*)'\)/", $sql, $m ) && ! in_array( (string) $row['status'], [ $m[1], $m[2] ], true ) ) {
				return false;
			}
			if ( preg_match( "/(?:AND|WHERE) status = '([^']*)'/", $sql, $m ) && (string) $row['status'] !== $m[1] ) {
				return false;
			}
			if ( str_contains( $sql, 'media_purged_at IS NULL' ) && ! empty( $row['media_purged_at'] ) ) {
				return false;
			}
			if ( preg_match( "/expires_at IS NOT NULL AND expires_at <= '([^']*)'/", $sql, $m ) && ( empty( $row['expires_at'] ) || strcmp( (string) $row['expires_at'], $m[1] ) > 0 ) ) {
				return false;
			}
			return true;
		}

		if ( 'vip_memberships' === $name ) {
			if ( preg_match( "/(?:AND|WHERE) status = '([^']*)'/", $sql, $m ) && (string) $row['status'] !== $m[1] ) {
				return false;
			}
			if ( preg_match( "/ends_at IS NOT NULL AND ends_at <= '([^']*)'/", $sql, $m ) && ( empty( $row['ends_at'] ) || strcmp( (string) $row['ends_at'], $m[1] ) > 0 ) ) {
				return false;
			}
			return true;
		}

		return true;
	}

	/** @return array<string,mixed>|null */
	private function match_payment( string $sql ) {
		$want = [];
		foreach ( [ 'tenant_id', 'user_id', 'purpose', 'idempotency_key', 'order_id', 'gateway', 'status' ] as $column ) {
			if ( preg_match( "/\b{$column} = '([^']*)'/", $sql, $m ) ) {
				$want[ $column ] = $m[1];
			}
		}
		if ( preg_match( "/amount = '([^']*)'/", $sql, $m ) ) {
			$want['amount'] = (float) $m[1];
		}

		$found = [];
		foreach ( $this->tables['payments'] as $row ) {
			$hit = true;
			foreach ( $want as $column => $value ) {
				$actual = 'amount' === $column ? (float) $row['amount'] : (string) ( $row[ $column ] ?? '' );
				if ( $actual !== $value && abs( (float) $actual - (float) $value ) > 0.0001 ) {
					$hit = false;
					break;
				}
			}
			if ( $hit ) {
				$found[] = $row;
			}
		}
		usort( $found, static fn ( $a, $b ): int => (int) $b['id'] <=> (int) $a['id'] );
		return $found[0] ?? null;
	}
}

/** Scripted gateway — never touches the network. */
final class VipStubGateway implements GatewayInterface {

	/** @var array<int,array<string,mixed>> */
	public array $requests = [];

	public function id(): string {
		return 'vipgw';
	}

	public function title(): string {
		return 'VIP Test Gateway';
	}

	public function required_settings(): array {
		return [];
	}

	public function is_configured(): bool {
		return true;
	}

	public function request( float $amount, string $callback_url, array $context = [] ): PaymentRequestResult {
		$this->requests[] = [ 'amount' => $amount, 'context' => $context ];
		return PaymentRequestResult::ok( 'AUTH-' . count( $this->requests ), 'https://pay.test/redirect' );
	}

	public function verify( float $amount, array $callback_params ): PaymentVerifyResult {
		return PaymentVerifyResult::failure( 'not_tested', '' );
	}
}

final class VipChannelTest extends TestCase {

	private VipChannelDb $wpdb;
	private VipBillingService $billing;
	private VipPostService $posts;
	private VipMediaService $media;
	private PaymentService $payments;
	private VipStubGateway $gateway;

	/** @var array<int,string> */
	private array $events = [];



	public function run(): void {
		$this->subscribe_reuses_its_pending_row_on_a_double_tap();
		$this->free_plan_double_tap_returns_the_live_membership();
		$this->payment_rows_are_never_shared_between_shoppers();
		$this->payment_dedupe_key_reuses_the_same_row();
		$this->membership_activation_is_a_conditional_flip();
		$this->membership_expiry_flips_only_lapsed_rows_once();
		$this->entitlement_grant_survives_a_unique_race();
		$this->expired_posts_cannot_be_republished();
		$this->expiry_shreds_keeps_a_ledger_and_reconcile_completes();
		$this->reconcile_settles_drifted_counts();
	}

	private function fresh(): void {
		igbz_test_reset_settings();
		$this->events = [];
		$this->wpdb      = new VipChannelDb();
		$GLOBALS['wpdb'] = $this->wpdb;

		$db     = new Db();
		$logger = igbz()->get( 'logger' );

		$access = new VipAccessService( $db );
		$this->media = new VipMediaService( $db, igbz()->settings(), $logger );
		$this->posts = new VipPostService( $db, igbz()->settings(), $logger, $access, $this->media );

		$wallet = new IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService( $db, $logger );
		$this->payments = new PaymentService( $db, igbz()->get( 'http' ), $wallet, $logger );
		$this->gateway  = new VipStubGateway();
		$this->payments->register( $this->gateway );
		igbz()->settings()->set( 'payments.vipgw.enabled', 'yes' );

		$this->billing = new VipBillingService( $db, igbz()->settings(), $logger, $access );

		$this->track( 'igbz_vip_membership_activated' );
		$this->track( 'igbz_vip_membership_expired' );
		$this->track( 'igbz_vip_post_published' );
		$this->track( 'igbz_vip_entitlement_granted' );
	}

	/** Runs after igbz_test_reset_settings(), which also drops registered actions. */
	private function track( string $hook ): void {
		add_action( $hook, function ( ...$args ) use ( $hook ): void {
			$this->events[] = $hook;
		} );
	}

	private function hook_count( string $hook ): int {
		return count( array_keys( $this->events, $hook, true ) );
	}

	private function seed_free_plan(): int {
		return $this->wpdb->seed(
			'vip_plans',
			[
				'tenant_id'     => 1,
				'slug'          => 'free',
				'name'          => 'Free',
				'price'         => 0.0,
				'currency'      => 'IRT',
				'duration_days' => 30,
				'is_active'     => 1,
			]
		);
	}

	// ------------------------------------------------------------------ money

	private function subscribe_reuses_its_pending_row_on_a_double_tap(): void {
		$this->fresh();
		$plan_id = $this->wpdb->seed(
			'vip_plans',
			[
				'tenant_id'     => 1,
				'slug'          => 'gold',
				'name'          => 'Gold',
				'price'         => 100000.0,
				'currency'      => 'IRT',
				'duration_days' => 30,
				'is_active'     => 1,
			]
		);

		// No gateway enabled: both taps stop at the payment step, so the pending row —
		// the thing the reuse guard protects — is exactly what survives.
		igbz()->settings()->set( 'payments.vipgw.enabled', 'no' );

		$first  = $this->billing->subscribe( 7, $plan_id );
		$second = $this->billing->subscribe( 7, $plan_id );

		$this->assert_false( $first['ok'], 'the first tap cannot pay with no gateway' );
		$this->assert_same( $first['membership_id'], $second['membership_id'], 'a double tap reuses the pending row instead of stacking a second one' );
		$this->assert_same( 1, count( $this->wpdb->tables['vip_memberships'] ), 'exactly one membership row exists' );
		$this->assert_same( 'pending', (string) reset( $this->wpdb->tables['vip_memberships'] )['status'], 'the row stays pending' );
	}

	private function free_plan_double_tap_returns_the_live_membership(): void {
		$this->fresh();
		$plan_id = $this->seed_free_plan();

		$first  = $this->billing->subscribe( 7, $plan_id );
		$second = $this->billing->subscribe( 7, $plan_id );

		$this->assert_true( $first['ok'] && $second['ok'], 'both taps succeed' );
		$this->assert_same( $first['membership_id'], $second['membership_id'], 'a live free plan is returned as-is, not stacked' );
		$this->assert_same( 1, count( $this->wpdb->tables['vip_memberships'] ), 'no second free term is minted' );
		$this->assert_same( 'active', (string) reset( $this->wpdb->tables['vip_memberships'] )['status'], 'the membership is active' );
	}

	private function payment_rows_are_never_shared_between_shoppers(): void {
		$this->fresh();

		$a = $this->payments->start( 50000.0, VipBillingService::PURPOSE_POST, [ 'tenant_id' => 1, 'user_id' => 7, 'post_id' => 9 ], 'vipgw' );
		$b = $this->payments->start( 50000.0, VipBillingService::PURPOSE_POST, [ 'tenant_id' => 1, 'user_id' => 8, 'post_id' => 9 ], 'vipgw' );

		$this->assert_true( $a['ok'] && $b['ok'], 'both shoppers start a payment' );
		$this->assert_not_same( $a['payment_id'], $b['payment_id'], 'two shoppers buying the same price never share one payment row' );
		$this->assert_same( 2, count( $this->wpdb->tables['payments'] ), 'two payment rows exist' );
	}

	private function payment_dedupe_key_reuses_the_same_row(): void {
		$this->fresh();

		$ctx = [ 'tenant_id' => 1, 'user_id' => 7, 'membership_id' => 5, 'dedupe_key' => 'vip_membership:5' ];
		$a   = $this->payments->start( 100000.0, VipBillingService::PURPOSE_MEMBERSHIP, $ctx, 'vipgw' );
		$b   = $this->payments->start( 100000.0, VipBillingService::PURPOSE_MEMBERSHIP, $ctx, 'vipgw' );

		$this->assert_true( $a['ok'] && $b['ok'], 'both attempts succeed' );
		$this->assert_same( $a['payment_id'], $b['payment_id'], 'the same dedupe key reuses the created row' );
		$this->assert_same( 1, count( $this->wpdb->tables['payments'] ), 'no duplicate row is stacked' );
	}

	private function membership_activation_is_a_conditional_flip(): void {
		$this->fresh();
		$plan_id = $this->seed_free_plan();

		$id = $this->wpdb->seed(
			'vip_memberships',
			[
				'tenant_id'  => 1,
				'user_id'    => 7,
				'plan_id'    => $plan_id,
				'status'     => 'pending',
				'price_paid' => 0.0,
				'payment_id' => 0,
			]
		);

		$this->assert_true( $this->billing->activate_membership( $id ), 'the winner activates' );
		$this->assert_true( ! $this->billing->activate_membership( $id ), 'the racing delivery cannot activate twice' );
		$this->assert_same( 'active', (string) $this->wpdb->tables['vip_memberships'][ $id ]['status'], 'the row is active' );
		$this->assert_same( 1, $this->hook_count( 'igbz_vip_membership_activated' ), 'the activation hook fires exactly once' );
	}

	private function membership_expiry_flips_only_lapsed_rows_once(): void {
		$this->fresh();
		$plan_id = $this->seed_free_plan();

		$past = $this->wpdb->seed( 'vip_memberships', [ 'tenant_id' => 1, 'user_id' => 7, 'plan_id' => $plan_id, 'status' => 'active', 'payment_id' => 0, 'ends_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ] );
		$this->wpdb->seed( 'vip_memberships', [ 'tenant_id' => 1, 'user_id' => 7, 'plan_id' => $plan_id, 'status' => 'active', 'payment_id' => 0, 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ] );

		$this->assert_same( 1, $this->billing->expire_memberships(), 'only the lapsed row is expired' );
		$this->assert_same( 'expired', (string) $this->wpdb->tables['vip_memberships'][ $past ]['status'], 'the lapsed row flips' );
		$this->assert_same( 1, $this->hook_count( 'igbz_vip_membership_expired' ), 'one expiry hook' );

		$this->assert_same( 0, $this->billing->expire_memberships(), 'a second sweep is a no-op' );
		$this->assert_same( 1, $this->hook_count( 'igbz_vip_membership_expired' ), 'still one expiry hook' );
	}

	private function entitlement_grant_survives_a_unique_race(): void {
		$this->fresh();
		$post_id = $this->wpdb->seed( 'vip_posts', [ 'tenant_id' => 1, 'status' => 'published', 'access' => 'purchase', 'price' => 50000.0, 'expires_at' => null ] );
		$this->wpdb->seed( 'vip_entitlements', [ 'tenant_id' => 1, 'user_id' => 7, 'post_id' => $post_id, 'source' => 'purchase', 'revoked_at' => gmdate( 'Y-m-d H:i:s' ) ] );

		// The pre-check saw nothing (the other grant had not committed yet), the insert hits
		// the UNIQUE key: the race the fallback exists for.
		$this->wpdb->entitlement_unique_race    = true;
		$this->wpdb->entitlement_first_select_misses = true;

		$granted = $this->billing->grant_entitlement( 7, $post_id, VipBillingService::SOURCE_PURCHASE, 42, 50000.0 );

		$this->assert_true( $granted > 0, 'the losing grant still returns the live entitlement id' );
		$row = null;
		foreach ( $this->wpdb->tables['vip_entitlements'] as $candidate ) {
			if ( (int) $candidate['post_id'] === $post_id ) {
				$row = $candidate;
			}
		}
		$this->assert_not_same( null, $row, 'the entitlement row exists' );
		$this->assert_same( 42, (int) $row['payment_id'], 'the paid payment is attached' );
		$this->assert_same( 1, $this->hook_count( 'igbz_vip_entitlement_granted' ), 'the grant hook fired once' );
	}

	// -------------------------------------------------------------- lifecycle

	private function expired_posts_cannot_be_republished(): void {
		$this->fresh();

		$expired = $this->wpdb->seed( 'vip_posts', [ 'tenant_id' => 1, 'status' => 'expired', 'media' => '[]', 'expiry_action' => 'hide', 'expires_at' => null, 'expired_at' => gmdate( 'Y-m-d H:i:s' ) ] );
		$draft   = $this->wpdb->seed( 'vip_posts', [ 'tenant_id' => 1, 'status' => 'draft', 'media' => '[]', 'expiry_action' => 'hide', 'expires_at' => null ] );

		$this->assert_true( ! $this->posts->publish( $expired ), 'an expired post cannot be resurrected' );
		$this->assert_true( $this->posts->publish( $draft ), 'a draft publishes' );
		$this->assert_true( ! $this->posts->publish( $draft ), 'the racing beat cannot publish twice' );
		$this->assert_same( 1, $this->hook_count( 'igbz_vip_post_published' ), 'one publish hook' );
	}

	private function expiry_shreds_keeps_a_ledger_and_reconcile_completes(): void {
		$this->fresh();

		$dir  = '/tmp/igbz-uploads/vip-phase54';
		@mkdir( $dir, 0777, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- test fixture
		$file = $dir . '/paid.mp4';
		$bytes = 'top-secret-paid-content-' . random_int( 1000, 9999 );
		file_put_contents( $file, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$post_id = $this->wpdb->seed(
			'vip_posts',
			[
				'tenant_id'    => 1,
				'status'       => 'published',
				'access'       => 'purchase',
				'media'        => wp_json_encode( [ [ 'type' => 'video', 'path' => 'vip-phase54/paid.mp4' ] ] ),
				'expiry_action' => 'hide',
				'published_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
				'expires_at'   => gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ),
			]
		);

		$this->assert_same( 1, $this->posts->expire_due(), 'the post expires' );

		$row = $this->wpdb->tables['vip_posts'][ $post_id ];
		$this->assert_same( 'expired', (string) $row['status'], 'the row retires' );
		$this->assert_true( file_exists( $file ), 'the harness stub does not unlink; the ledger must reflect that' );
		$this->assert_true( file_get_contents( $file ) !== $bytes && strlen( (string) file_get_contents( $file ) ) === strlen( $bytes ), 'the file was shredded in place before its delete' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assert_true( empty( $row['media_purged_at'] ?? '' ), 'no purge stamp while a file survives' );
		$this->assert_same( 1, count( json_decode( (string) $row['media'], true ) ), 'the media ledger keeps the outstanding file' );

		// The storage release that failed in the moment now succeeds; the retry completes.
		unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- simulating the successful delete
		$this->assert_same( 1, $this->posts->reconcile(), 'the retry acts on the row' );

		$row = $this->wpdb->tables['vip_posts'][ $post_id ];
		$this->assert_same( '[]', (string) $row['media'], 'the ledger clears once every file is provably gone' );
		$this->assert_true( ! empty( $row['media_purged_at'] ), 'the purge is stamped complete' );

		$this->assert_same( 0, $this->posts->reconcile(), 'a stamped row leaves the sweep' );
	}

	private function reconcile_settles_drifted_counts(): void {
		$this->fresh();

		$post_id = $this->wpdb->seed(
			'vip_posts',
			[
				'tenant_id'      => 1,
				'status'         => 'published',
				'media'          => '[]',
				'likes_count'    => 5,
				'comments_count' => 2,
			]
		);
		$this->wpdb->seed( 'vip_post_likes', [ 'post_id' => $post_id, 'user_id' => 7 ] );
		$this->wpdb->seed( 'vip_post_comments', [ 'tenant_id' => 1, 'post_id' => $post_id, 'user_id' => 7, 'status' => 'hidden' ] );

		$this->assert_same( 1, $this->posts->reconcile(), 'the drifted row is settled' );

		$row = $this->wpdb->tables['vip_posts'][ $post_id ];
		$this->assert_same( 1, (int) $row['likes_count'], 'likes recount from the table' );
		$this->assert_same( 0, (int) $row['comments_count'], 'hidden comments do not count' );
	}
}
