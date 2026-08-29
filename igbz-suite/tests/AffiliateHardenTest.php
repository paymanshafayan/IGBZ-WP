<?php
/**
 * Phase 40 — affiliate hardening: a user can never be their own parent, refunds reverse
 * pending AND approved commissions but report the paid ones they cannot undo, and the fraud
 * report surfaces self-referrals, shared-IP conversion clusters, refund-heavy affiliates and
 * paid commissions on refunded orders — report only, never automatic punishment.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\MultiTenant\Affiliate\AffiliateService;
use IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService;
use IGBZ\Suite\Support\Db;

/** In-memory engine for affiliates, commissions and clicks. */
final class AffiliateDb extends wpdb {

	/** @var array<string,array<int,array<string,mixed>>> */
	public array $tables = [
		'affiliates'            => [],
		'affiliate_commissions' => [],
		'referral_clicks'       => [],
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

		if ( str_contains( $sql, 'affiliates' ) && ! str_contains( $sql, 'commissions' ) ) {
			if ( preg_match( "/WHERE id = '?(\d+)'? AND tenant_id = '?(\d+)'?/", $sql, $m ) ) {
				$row = $this->tables['affiliates'][ (int) $m[1] ] ?? null;
				return null !== $row && (string) $row['tenant_id'] === $m[2] ? $row : null;
			}
			if ( preg_match( "/WHERE code = '([^']*)'/", $sql, $m ) ) {
				foreach ( $this->tables['affiliates'] as $row ) {
					if ( (string) $row['code'] === $m[1] ) {
						return $row;
					}
				}
				return null;
			}
			if ( preg_match( "/WHERE user_id = '?(\d+)'? AND tenant_id = '?(\d+)'?/", $sql, $m ) ) {
				foreach ( $this->tables['affiliates'] as $row ) {
					if ( (string) $row['user_id'] === $m[1] && (string) $row['tenant_id'] === $m[2] ) {
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

		if ( str_contains( $sql, 'SELECT id FROM' ) && str_contains( $sql, "code = " ) ) {
			return null; // Every generated code is free in this harness.
		}

		return parent::get_var( $sql );
	}

	public function get_results( string $sql, $output = null ) {
		$this->queries[] = $sql;

		$table = str_contains( $sql, 'affiliate_commissions' )
			? 'affiliate_commissions'
			: ( str_contains( $sql, 'referral_clicks' ) ? 'referral_clicks' : ( str_contains( $sql, 'affiliates' ) ? 'affiliates' : '' ) );
		if ( '' === $table ) {
			return parent::get_results( $sql, $output );
		}

		$tenant = null;
		if ( preg_match( "/WHERE tenant_id = '?(\d+)'?/", $sql, $m ) ) {
			$tenant = $m[1];
		}

		$out = [];
		foreach ( $this->tables[ $table ] as $row ) {
			if ( null !== $tenant && (string) $row['tenant_id'] !== $tenant ) {
				continue;
			}
			if ( 'referral_clicks' === $table && (int) $row['converted_user_id'] <= 0 ) {
				continue;
			}
			$out[] = $row;
		}
		usort( $out, static fn ( $a, $b ): int => (int) $a['id'] <=> (int) $b['id'] );
		return array_slice( $out, 0, 5000 );
	}

	public function insert( string $table, array $data, $format = null ): int|bool {
		$this->queries[] = 'INSERT INTO ' . $table;
		$this->last_write = [ 'table' => $table, 'data' => $data ];
		$this->writes[]   = $this->last_write;

		foreach ( [ 'affiliates', 'affiliate_commissions', 'referral_clicks' ] as $name ) {
			if ( str_contains( $table, $name ) ) {
				$this->insert_id = $this->seed( $name, $data );
				return 1;
			}
		}

		return parent::insert( $table, $data, $format );
	}

	public function query( string $sql ): int|bool {
		$this->queries[] = $sql;

		// The void path: UPDATE ... SET status = 'rejected' WHERE order_id = N AND status IN ('pending','approved').
		if ( str_contains( $sql, 'affiliate_commissions' ) && str_contains( $sql, 'SET status' ) ) {
			if ( ! preg_match( "/order_id = '?(\d+)'?/", $sql, $m ) ) {
				return parent::query( $sql );
			}
			$order_id = $m[1];
			$allowed  = [];
			if ( preg_match( "/status IN \( '([^']+)', '([^']+)' \)/", $sql, $mm ) ) {
				$allowed = [ $mm[1], $mm[2] ];
			}
			$changed = 0;
			foreach ( $this->tables['affiliate_commissions'] as $id => $row ) {
				if ( (string) $row['order_id'] === $order_id && in_array( (string) $row['status'], $allowed, true ) ) {
					$this->tables['affiliate_commissions'][ $id ]['status'] = 'rejected';
					++$changed;
				}
			}
			return $changed;
		}

		return parent::query( $sql );
	}
}

final class AffiliateHardenTest extends TestCase {

	private Db $db;
	private AffiliateDb $adb;
	private AffiliateService $service;

	private function boot(): void {
		igbz_test_reset_settings();
		$GLOBALS['igbz_test_http'] = [];

		$this->adb         = new AffiliateDb();
		$GLOBALS['wpdb']   = $this->adb;

		$this->db = new Db();
		$ref = new ReflectionProperty( Db::class, 'is_sqlite' );
		$ref->setValue( $this->db, true );

		$settings      = igbz()->settings();
		$this->service = new AffiliateService( $this->db, new WalletService( $this->db, new IGBZ\Suite\Support\Logger( $settings ) ), new IGBZ\Suite\Support\Logger( $settings ) );
	}

	private function affiliate( int $user_id, array $extra = [] ): array {
		$id = $this->adb->seed( 'affiliates', array_merge( [
			'tenant_id'       => 0,
			'user_id'         => $user_id,
			'code'            => 'C' . $user_id,
			'parent_id'       => 0,
			'tier'            => 1,
			'commission_rate' => 10,
			'total_earned'    => 0,
			'total_paid'      => 0,
			'clicks'          => 0,
			'signups'         => 0,
			'status'          => 'active',
			'created_at'      => gmdate( 'Y-m-d H:i:s' ),
		], $extra ) );

		return $this->adb->tables['affiliates'][ $id ];
	}

	private function commission( int $affiliate_id, int $referred_user_id, string $status, array $extra = [] ): array {
		$id = $this->adb->seed( 'affiliate_commissions', array_merge( [
			'tenant_id'        => 0,
			'affiliate_id'     => $affiliate_id,
			'order_id'         => 100 + $this->adb->insert_id,
			'referred_user_id' => $referred_user_id,
			'tier'             => 1,
			'base_amount'      => 100.0,
			'rate'             => 10,
			'amount'           => 10.0,
			'status'           => $status,
			'approved_at'      => null,
			'paid_at'          => null,
			'created_at'       => gmdate( 'Y-m-d H:i:s' ),
		], $extra ) );

		return $this->adb->tables['affiliate_commissions'][ $id ];
	}

	public function run(): void {
		$this->test_enroll_drops_a_self_parent();
		$this->test_void_covers_pending_and_approved_but_never_touches_paid();
		$this->test_the_fraud_report_surfaces_every_signal_without_punishing();
	}

	public function test_enroll_drops_a_self_parent(): void {
		$this->boot();
		$mine = $this->affiliate( 5 );

		$enrolled = $this->service->enroll( 5, 1, (int) $mine['id'] );

		$this->assert_same( 0, (int) $enrolled['parent_id'], 'a user cannot be their own parent' );
		$this->assert_same( 1, (int) $enrolled['tier'], 'the dropped parent resets the tier' );
	}

	public function test_void_covers_pending_and_approved_but_never_touches_paid(): void {
		$this->boot();
		$aff      = $this->affiliate( 9 );
		$pending  = $this->commission( (int) $aff['id'], 3, AffiliateService::STATUS_PENDING, [ 'order_id' => 500 ] );
		$approved = $this->commission( (int) $aff['id'], 3, AffiliateService::STATUS_APPROVED, [ 'order_id' => 500 ] );
		$paid     = $this->commission( (int) $aff['id'], 3, AffiliateService::STATUS_PAID, [ 'order_id' => 500 ] );

		$this->service->void_order_commission( 500 );

		$this->assert_same( 'rejected', $this->adb->tables['affiliate_commissions'][ (int) $pending['id'] ]['status'], 'the pending row is reversed' );
		$this->assert_same( 'rejected', $this->adb->tables['affiliate_commissions'][ (int) $approved['id'] ]['status'], 'the approved-but-unpaid row is reversed' );
		$this->assert_same( 'paid', $this->adb->tables['affiliate_commissions'][ (int) $paid['id'] ]['status'], 'paid money is never silently un-paid' );
	}

	public function test_the_fraud_report_surfaces_every_signal_without_punishing(): void {
		$this->boot();
		$aff = $this->affiliate( 9 );

		// Signal 1 — a self-referral commission slipped through somewhere.
		$this->commission( (int) $aff['id'], 9, AffiliateService::STATUS_PENDING );

		// Signal 3 — a refund-heavy affiliate: 5 commissions, 3 rejected (> half).
		$this->commission( (int) $aff['id'], 3, AffiliateService::STATUS_REJECTED );
		$this->commission( (int) $aff['id'], 4, AffiliateService::STATUS_REJECTED );
		$this->commission( (int) $aff['id'], 7, AffiliateService::STATUS_REJECTED );

		// Signal 4 — a paid commission on an order the shop later refunded.
		$paid_row = $this->commission( (int) $aff['id'], 6, AffiliateService::STATUS_PAID, [ 'order_id' => 900 ] );
		if ( class_exists( 'IgbzHposStub' ) && class_exists( 'WC_Order' ) ) {
			IgbzHposStub::$orders[900] = new WC_Order( 900, 6, 100.0, 100.0, 0.0, 'refunded' );
		}

		// Signal 2 — one IP converting two different users.
		$this->adb->seed( 'referral_clicks', [ 'tenant_id' => 0, 'affiliate_id' => (int) $aff['id'], 'source' => '', 'landing_url' => '', 'ip_hash' => hash( 'sha256', '1.2.3.4' ), 'user_agent' => '', 'converted_user_id' => 11, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ] );
		$this->adb->seed( 'referral_clicks', [ 'tenant_id' => 0, 'affiliate_id' => (int) $aff['id'], 'source' => '', 'landing_url' => '', 'ip_hash' => hash( 'sha256', '1.2.3.4' ), 'user_agent' => '', 'converted_user_id' => 12, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ] );

		$report = $this->service->fraud_report();

		$this->assert_same( 1, $report['self_referrals'], 'the self-referral is counted' );
		$this->assert_same( 1, $report['shared_ip_groups'], 'the shared-IP cluster is counted' );
		$this->assert_same( [ (int) $aff['id'] ], $report['high_refund_affiliates'], 'the refund-heavy affiliate is flagged' );
		if ( class_exists( 'IgbzHposStub' ) ) {
			$this->assert_same( 1, $report['paid_on_refunded_orders'], 'the un-undoable paid commission is surfaced' );
		}
		$this->assert_same( 'paid', $this->adb->tables['affiliate_commissions'][ (int) $paid_row['id'] ]['status'], 'the report changes nothing' );
	}
}
