<?php
/**
 * Phase 32 — the subscription lifecycle beyond renew: manual suspension and reactivation that
 * keep the remaining time intact, and a grace sweep that expires past-due subscriptions only
 * after the configured window.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\MultiTenant\Plans\PlanService;
use IGBZ\Suite\Support\Db;

/** In-memory engine for subscriptions + tenants. */
final class PlansDb extends wpdb {

	/** @var array<string,array<int,array<string,mixed>>> */
	public array $tables = [
		'subscriptions' => [],
		'tenants'       => [],
	];

	private int $next_id = 1;

	public function seed( string $table, array $row ): int {
		$id = $this->next_id++;
		$row['id'] = $id;
		$this->tables[ $table ][ $id ] = $row;
		return $id;
	}

	public function insert( string $table, array $data, $format = null ): int|bool {
		foreach ( $this->tables as $name => $rows ) {
			if ( str_contains( $table, $name ) ) {
				$id = $this->next_id++;
				$data['id'] = $id;
				$this->tables[ $name ][ $id ] = $data;
				$this->insert_id = $id;
				return 1;
			}
		}
		return parent::insert( $table, $data, $format );
	}

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'subscriptions' ) ) {
			if ( preg_match( "/WHERE id = '?(\d+)'?( AND tenant_id = '?(\d+)'?)?/", $sql, $m ) && ! str_contains( $sql, 'status IN' ) ) {
				return $this->tables['subscriptions'][ (int) $m[1] ] ?? null;
			}
			if ( str_contains( $sql, 'status IN' ) && preg_match( "/WHERE tenant_id = '?(\d+)'? AND status IN \(([^)]*)\)/", $sql, $m ) ) {
				$statuses = array_map( static fn ( $s ) => trim( $s, " '" ), explode( ',', $m[2] ) );
				$found    = [];
				foreach ( $this->tables['subscriptions'] as $row ) {
					if ( (string) $row['tenant_id'] === $m[1] && in_array( (string) $row['status'], $statuses, true ) ) {
						$found[] = $row;
					}
				}
				usort( $found, static fn ( $a, $b ): int => (int) $b['id'] <=> (int) $a['id'] );
				return $found[0] ?? null;
			}
		}
		if ( str_contains( $sql, 'tenants' ) && preg_match( "/WHERE id = '?(\d+)'?/", $sql, $m ) ) {
			return $this->tables['tenants'][ (int) $m[1] ] ?? null;
		}
		return parent::get_row( $sql, $output );
	}

	public function get_results( string $sql, $output = null ) {
		$this->queries[] = $sql;
		if ( str_contains( $sql, 'subscriptions' ) && str_contains( $sql, 'past_due' ) ) {
			$out = [];
			foreach ( $this->tables['subscriptions'] as $row ) {
				if ( 'past_due' !== (string) $row['status'] ) {
					continue;
				}
				if ( preg_match( "/ends_at <= '([^']*)'/", $sql, $m ) && strcmp( (string) $row['ends_at'], $m[1] ) > 0 ) {
					continue;
				}
				$out[] = $row;
			}
			usort( $out, static fn ( $a, $b ): int => (int) $a['id'] <=> (int) $b['id'] );
			if ( preg_match( "/LIMIT '?(\d+)'?/", $sql, $l ) ) {
				$out = array_slice( $out, 0, (int) $l[1] );
			}
			return $out;
		}
		return parent::get_results( $sql, $output );
	}

	public function query( string $query ): int|bool {
		$this->queries[] = $query;
		if ( str_contains( $query, 'subscriptions' ) && preg_match( "/SET status = '([^']*)'/", $query, $set ) ) {
			$new = $set[1];
			if ( preg_match( "/WHERE id = '?(\d+)'? AND status IN \(([^)]*)\)/", $query, $m ) ) {
				$from = array_map( static fn ( $s ) => trim( $s, " '" ), explode( ',', $m[2] ) );
				$row  = $this->tables['subscriptions'][ (int) $m[1] ] ?? null;
				if ( $row && in_array( (string) $row['status'], $from, true ) ) {
					$this->tables['subscriptions'][ (int) $m[1] ]['status'] = $new;
					return 1;
				}
				return 0;
			}
			if ( preg_match( "/WHERE id = '?(\d+)'? AND status = '([^']*)'/", $query, $m ) ) {
				$row = $this->tables['subscriptions'][ (int) $m[1] ] ?? null;
				if ( $row && (string) $row['status'] === $m[2] ) {
					$this->tables['subscriptions'][ (int) $m[1] ]['status'] = $new;
					return 1;
				}
				return 0;
			}
		}
		return parent::query( $query );
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
}

final class PlansLifecycleTest extends TestCase {

	private PlansDb $wpdb;
	private PlanService $plans;

	public function run(): void {
		$this->suspension_freezes_and_reactivation_restores();
		$this->suspension_is_conditional();
		$this->reactivating_a_lapsed_subscription_returns_past_due();
		$this->suspended_subscription_is_not_active();
		$this->grace_sweep_expires_only_beyond_the_window();
		$this->grace_window_is_configurable();
	}

	private function fresh(): void {
		igbz_test_reset_settings();
		$this->wpdb      = new PlansDb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->wpdb->seed( 'tenants', [ 'status' => 'active', 'plan_id' => 1 ] );
		$logger      = igbz()->get( 'logger' );
		$this->plans = new PlanService( new Db(), new \IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService( new Db(), $logger ), $logger );
	}

	/** @return array<int,string> */
	private function seed_sub( string $status, string $ends_at ): array {
		$id = $this->wpdb->seed(
			'subscriptions',
			[
				'tenant_id'        => 1,
				'plan_id'          => 1,
				'status'           => $status,
				'ends_at'          => $ends_at,
				'auto_renew'       => 1,
				'renewal_failures' => 0,
			]
		);
		return [ $id, $status ];
	}

	private function suspension_freezes_and_reactivation_restores(): void {
		$this->fresh();
		[ $id ] = $this->seed_sub( 'active', gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ) );

		$this->assert_true( $this->plans->suspend( $id, 'fraud review' ), 'a live subscription suspends' );
		$this->assert_same( 'suspended', (string) $this->wpdb->tables['subscriptions'][ $id ]['status'], 'the row freezes' );
		$this->assert_same( 'suspended', (string) $this->wpdb->tables['tenants'][1]['status'], 'the tenant freezes with it' );

		$this->assert_true( $this->plans->reactivate( $id ), 'reactivation succeeds' );
		$this->assert_same( 'active', (string) $this->wpdb->tables['subscriptions'][ $id ]['status'], 'straight back to active...' );
		$this->assert_same( 'active', (string) $this->wpdb->tables['tenants'][1]['status'], '...and so does the tenant' );
	}

	private function suspension_is_conditional(): void {
		$this->fresh();
		[ $id ] = $this->seed_sub( 'active', gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) );

		$this->plans->suspend( $id );
		$this->assert_true( ! $this->plans->suspend( $id ), 'a second suspension cannot win' );

		[ $expired_id ] = $this->seed_sub( 'expired', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );
		$this->assert_true( ! $this->plans->suspend( $expired_id ), 'a dead subscription cannot be suspended' );
	}

	private function reactivating_a_lapsed_subscription_returns_past_due(): void {
		$this->fresh();
		[ $id ] = $this->seed_sub( 'suspended', gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) );

		$this->assert_true( $this->plans->reactivate( $id ), 'reactivation still succeeds' );
		$this->assert_same( 'past_due', (string) $this->wpdb->tables['subscriptions'][ $id ]['status'], 'the lapsed period lands in past_due for the sweep' );

		[ $active_id ] = $this->seed_sub( 'active', gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) );
		$this->assert_true( ! $this->plans->reactivate( $active_id ), 'only suspended rows reactivate' );
	}

	private function suspended_subscription_is_not_active(): void {
		$this->fresh();
		[ $id ] = $this->seed_sub( 'active', gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) );

		$this->plans->suspend( $id );
		$this->assert_same( null, $this->plans->active_subscription( 1 ), 'a suspended tenant has no active subscription' );
	}

	private function grace_sweep_expires_only_beyond_the_window(): void {
		$this->fresh();
		igbz()->settings()->set( 'plans.grace_days', '7' );

		[ $old ]  = $this->seed_sub( 'past_due', gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) );
		[ $new ]  = $this->seed_sub( 'past_due', gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) );
		[ $fine ] = $this->seed_sub( 'active', gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) );

		$expired = $this->plans->expire_past_grace();

		$this->assert_same( 1, $expired, 'only the over-grace subscription expires' );
		$this->assert_same( 'expired', (string) $this->wpdb->tables['subscriptions'][ $old ]['status'], 'it is expired' );
		$this->assert_same( 'past_due', (string) $this->wpdb->tables['subscriptions'][ $new ]['status'], 'the one inside grace keeps serving' );
		$this->assert_same( 'active', (string) $this->wpdb->tables['subscriptions'][ $fine ]['status'], 'active rows are untouched by the grace sweep' );
		$this->assert_same( 'suspended', (string) $this->wpdb->tables['tenants'][1]['status'], 'the expired tenant suspends' );
	}

	private function grace_window_is_configurable(): void {
		$this->fresh();

		$this->assert_same( 7, $this->plans->grace_days(), 'the default window is 7 days' );

		igbz()->settings()->set( 'plans.grace_days', '14' );
		$this->assert_same( 14, $this->plans->grace_days(), 'configured wins' );

		igbz()->settings()->set( 'plans.grace_days', '-3' );
		$this->assert_same( 0, $this->plans->grace_days(), 'never negative' );
	}
}
