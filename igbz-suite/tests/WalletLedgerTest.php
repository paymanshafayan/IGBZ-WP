<?php
/**
 * Phase 28 — the wallet ledger contract.
 *
 * The balance invariant (cached balance == ledger sum) must survive the whole reservation
 * lifecycle: reserve moves funds out, release brings them back exactly once, settle consumes
 * them with a permanent zero-amount mark, refunds can never exceed the original debit,
 * concurrent debits can never overdraw, and reconciliation finds and repairs any drift.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

/** In-memory engine for the two wallet tables. */
final class WalletDb extends wpdb {

	/** @var array<string,array<int,array<string,mixed>>> */
	public array $tables = [
		'wallet_ledger'   => [],
		'wallet_balances' => [],
	];

	private int $next_id = 1;

	public function get_var( string $sql ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'GET_LOCK' ) || str_contains( $sql, 'RELEASE_LOCK' ) ) {
			return '1';
		}

		if ( str_contains( $sql, 'SUM(amount)' ) ) {
			$sum = 0.0;
			foreach ( $this->ledger_where( $sql ) as $row ) {
				$sum += (float) $row['amount'];
			}
			return (string) $sum;
		}

		if ( str_contains( $sql, 'SELECT balance FROM' ) ) {
			foreach ( $this->tables['wallet_balances'] as $row ) {
				if ( $this->matches_where( $sql, $row ) ) {
					return (string) $row['balance'];
				}
			}
			return null;
		}
		return parent::get_var( $sql );
	}

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;
		$rows = $this->ledger_where( $sql );
		if ( str_contains( $sql, 'amount < 0' ) ) {
			usort( $rows, static fn ( $a, $b ): int => (int) $a['id'] <=> (int) $b['id'] );
		}
		return $rows[0] ?? null;
	}

	public function get_results( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'wallet_balances' ) && preg_match( "/WHERE id > '?(\d+)'?/", $sql, $m ) ) {
			$out = [];
			foreach ( $this->tables['wallet_balances'] as $id => $row ) {
				if ( $id > (int) $m[1] ) {
					$out[] = $row;
				}
			}
			usort( $out, static fn ( $a, $b ): int => (int) $a['id'] <=> (int) $b['id'] );
			if ( preg_match( "/LIMIT '?(\d+)'?/", $sql, $l ) ) {
				$out = array_slice( $out, 0, (int) $l[1] );
			}
			return $out;
		}
		return $this->ledger_where( $sql );
	}

	public function insert( string $table, array $data, $format = null ): int|bool {
		if ( str_contains( $table, 'wallet_' ) ) {
			$short                  = str_contains( $table, 'ledger' ) ? 'wallet_ledger' : 'wallet_balances';
			$id                     = $this->next_id++;
			$data['id']             = $id;
			$this->tables[ $short ][ $id ] = $data;
			$this->insert_id        = $id;
			return 1;
		}
		return parent::insert( $table, $data, $format );
	}

	public function query( string $sql ): int|bool {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'START TRANSACTION' ) || str_contains( $sql, 'COMMIT' ) || str_contains( $sql, 'ROLLBACK' ) ) {
			return 0;
		}

		// The wallet balance upsert: INSERT ... ON DUPLICATE KEY UPDATE keyed on (tenant, user).
		if ( str_contains( $sql, 'wallet_balances' ) && str_contains( $sql, 'ON DUPLICATE KEY' )
			&& preg_match( '/INSERT INTO \S+ \(([^)]+)\) VALUES \(([^)]*)\)/', $sql, $m ) ) {
			$columns = array_map( 'trim', explode( ',', $m[1] ) );
			$values  = str_getcsv( $m[2], ',', "'", '\\' );
			$data    = array_combine( $columns, $values );

			foreach ( $this->tables['wallet_balances'] as $id => $row ) {
				if ( (string) $row['tenant_id'] === (string) $data['tenant_id']
					&& (string) $row['user_id'] === (string) $data['user_id'] ) {
					$this->tables['wallet_balances'][ $id ] = array_merge( $row, $data );
					return 2;
				}
			}
			$new_id = $this->next_id++;
			$data['id'] = $new_id;
			$this->tables['wallet_balances'][ $new_id ] = $data;
			return 1;
		}
		return 0;
	}

	/** @return array<int,array<string,mixed>> */
	private function ledger_where( string $sql ): array {
		$out = [];
		foreach ( $this->tables['wallet_ledger'] as $row ) {
			if ( $this->matches_where( $sql, $row ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/** @param array<string,mixed> $row */
	private function matches_where( string $sql, array $row ): bool {
		if ( preg_match_all( "/\b(user_id|tenant_id|reason|reference_code) = '([^']*)'/", $sql, $pairs, PREG_SET_ORDER ) ) {
			foreach ( $pairs as $p ) {
				if ( (string) ( $row[ $p[1] ] ?? '' ) !== $p[2] ) {
					return false;
				}
			}
		}
		if ( preg_match( "/reference_code LIKE '([^']*)'/", $sql, $m ) ) {
			$prefix = str_replace( '%', '', $m[1] );
			if ( ! str_starts_with( (string) ( $row['reference_code'] ?? '' ), $prefix ) ) {
				return false;
			}
		}
		if ( str_contains( $sql, 'amount < 0' ) && (float) ( $row['amount'] ?? 0 ) >= 0 ) {
			return false;
		}
		return true;
	}
}

final class WalletLedgerTest extends TestCase {

	private WalletDb $wpdb;
	private WalletService $wallet;

	public function run(): void {
		$this->reserve_moves_funds_and_is_idempotent();
		$this->release_returns_funds_exactly_once();
		$this->settle_consumes_without_moving_funds();
		$this->refund_cannot_exceed_the_original_debit();
		$this->concurrent_debits_cannot_overdraw();
		$this->reconciliation_repairs_drift();
		$this->invariant_holds_through_the_lifecycle();
	}

	private function fresh(): void {
		igbz_test_reset_settings();
		$this->wpdb      = new WalletDb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->wallet    = new WalletService( new Db(), new Logger( igbz()->settings() ) );
	}

	/** @return array<int,array<string,mixed>> */
	private function ledger(): array {
		return array_values( $this->wpdb->tables['wallet_ledger'] );
	}

	private function balance_of( int $user ): float {
		return $this->wallet->balance( $user, 1 );
	}

	private function reserve_moves_funds_and_is_idempotent(): void {
		$this->fresh();

		$topup = $this->wallet->credit( 3, 1000.0, WalletService::REASON_TOPUP, 'T1', [], 1 );
		$this->assert_true( $topup->success, 'the top-up lands' );
		$this->assert_same( 1000.0, $this->balance_of( 3 ), 'the balance reflects the top-up' );

		$reserve = $this->wallet->reserve( 3, 300.0, 'R1', 1 );
		$this->assert_true( $reserve->success, 'a reservation posts' );
		$this->assert_same( 700.0, $this->balance_of( 3 ), 'reserved funds leave the available balance' );

		$again = $this->wallet->reserve( 3, 300.0, 'R1', 1 );
		$this->assert_true( $again->duplicate, 'the same reference code is absorbed...' );
		$this->assert_same( 700.0, $this->balance_of( 3 ), '...and never reserves twice' );
		$this->assert_same( 2, count( $this->ledger() ), 'exactly two entries exist' );
	}

	private function release_returns_funds_exactly_once(): void {
		$this->fresh();

		$this->wallet->credit( 4, 500.0, WalletService::REASON_TOPUP, 'T', [], 1 );
		$this->wallet->reserve( 4, 200.0, 'R2', 1 );

		$release = $this->wallet->release_reserve( 4, 'R2', 1 );
		$this->assert_true( $release->success, 'a release posts' );
		$this->assert_same( 500.0, $this->balance_of( 4 ), 'the reserved funds come back' );

		$again = $this->wallet->release_reserve( 4, 'R2', 1 );
		$this->assert_true( $again->duplicate, 'releasing twice is absorbed by the ledger key' );
		$this->assert_same( 500.0, $this->balance_of( 4 ), 'the balance is untouched by the replay' );

		$unknown = $this->wallet->release_reserve( 4, 'NOPE', 1 );
		$this->assert_true( ! $unknown->success, 'releasing an unknown reservation fails' );
		$this->assert_same( 'unknown_reservation', $unknown->error_code, 'with a precise error code' );
	}

	private function settle_consumes_without_moving_funds(): void {
		$this->fresh();

		$this->wallet->credit( 5, 900.0, WalletService::REASON_TOPUP, 'T', [], 1 );
		$this->wallet->reserve( 5, 400.0, 'R3', 1 );
		$this->assert_same( 500.0, $this->balance_of( 5 ), 'the reservation moved the funds out' );

		$settle = $this->wallet->settle_reserve( 5, 'R3', 1 );
		$this->assert_true( $settle->success, 'a settlement posts' );
		$this->assert_same( 500.0, $this->balance_of( 5 ), 'settlement consumes — no extra money moves' );

		$marks = array_values( array_filter(
			$this->ledger(),
			static fn ( array $r ) => WalletService::REASON_RESERVE_SETTLE === $r['reason']
		) );
		$this->assert_same( 1, count( $marks ), 'the settlement is recorded' );
		$this->assert_same( WalletService::DIRECTION_MARK, (string) $marks[0]['direction'], 'as a zero-amount mark entry' );
		$this->assert_same( 0.0, round( (float) $marks[0]['amount'], 4 ), 'the mark moves nothing' );

		$again = $this->wallet->settle_reserve( 5, 'R3', 1 );
		$this->assert_true( $again->duplicate, 'settling twice is absorbed' );

		$too_late = $this->wallet->release_reserve( 5, 'R3', 1 );
		$this->assert_true( ! $too_late->success, 'a settled reservation cannot be released' );
		$this->assert_same( 'already_settled', $too_late->error_code, 'and says why' );
		$this->assert_same( 500.0, $this->balance_of( 5 ), 'the refusal moves nothing' );

		// The opposite order: released funds cannot be settled.
		$this->wallet->reserve( 5, 100.0, 'R4', 1 );
		$this->wallet->release_reserve( 5, 'R4', 1 );
		$released = $this->wallet->settle_reserve( 5, 'R4', 1 );
		$this->assert_true( ! $released->success, 'a released reservation cannot be settled' );
		$this->assert_same( 'already_released', $released->error_code, 'and says why' );
	}

	private function refund_cannot_exceed_the_original_debit(): void {
		$this->fresh();

		$this->wallet->credit( 6, 1000.0, WalletService::REASON_TOPUP, 'T', [], 1 );
		$this->wallet->debit( 6, 500.0, WalletService::REASON_ORDER_PAY, 'PAY-9', [], 1, 9 );
		$this->assert_same( 500.0, $this->balance_of( 6 ), 'the order payment debited' );

		$first = $this->wallet->refund( 6, 'PAY-9', 200.0, 'A', 1, 9 );
		$this->assert_true( $first->success, 'a partial refund posts' );
		$this->assert_same( 700.0, $this->balance_of( 6 ), 'the refund credits back' );

		$too_much = $this->wallet->refund( 6, 'PAY-9', 400.0, 'B', 1, 9 );
		$this->assert_true( ! $too_much->success, 'a refund exceeding the remainder is refused' );
		$this->assert_same( 'over_refund', $too_much->error_code, 'with a precise error code' );
		$this->assert_same( 700.0, $this->balance_of( 6 ), 'the refusal posts nothing' );

		$rest = $this->wallet->refund( 6, 'PAY-9', 300.0, 'C', 1, 9 );
		$this->assert_true( $rest->success, 'the exact remainder posts' );
		$this->assert_same( 1000.0, $this->balance_of( 6 ), 'the debit is now fully refunded' );

		$one_too_many = $this->wallet->refund( 6, 'PAY-9', 0.5, 'D', 1, 9 );
		$this->assert_same( 'over_refund', $one_too_many->error_code, 'nothing beyond the original can ever be refunded' );

		$unknown = $this->wallet->refund( 6, 'GHOST', 10.0, 'E', 1 );
		$this->assert_same( 'unknown_original', $unknown->error_code, 'refunding against a missing debit fails' );

		$replay = $this->wallet->refund( 6, 'PAY-9', 200.0, 'A', 1, 9 );
		$this->assert_true( $replay->duplicate, 'a replayed refund is absorbed by the ledger key' );
		$this->assert_same( 1000.0, $this->balance_of( 6 ), 'the replay credits nothing' );
	}

	private function concurrent_debits_cannot_overdraw(): void {
		$this->fresh();

		$this->wallet->credit( 7, 100.0, WalletService::REASON_TOPUP, 'T', [], 1 );

		// Two payment flows race for the same 100; the advisory lock serialises them.
		$first  = $this->wallet->try_debit( 7, 80.0, WalletService::REASON_ORDER_PAY, 'ORDER-A', 1 );
		$second = $this->wallet->try_debit( 7, 80.0, WalletService::REASON_ORDER_PAY, 'ORDER-B', 1 );

		$this->assert_true( $first, 'the first debit wins' );
		$this->assert_true( ! $second, 'the second debit is refused — no overdraw' );
		$this->assert_same( 20.0, $this->balance_of( 7 ), 'the wallet keeps exactly what is left' );
		$this->assert_true( $this->wallet->check_invariant( 7, 1 ), 'the invariant survives the race' );
	}

	private function reconciliation_repairs_drift(): void {
		$this->fresh();

		$this->wallet->credit( 8, 250.0, WalletService::REASON_TOPUP, 'T8', [], 1 );
		$this->wallet->credit( 9, 40.0, WalletService::REASON_TOPUP, 'T9', [], 1 );

		// Corrupt one cached balance directly, as a bug or a crashed write might.
		foreach ( $this->wpdb->tables['wallet_balances'] as $id => $row ) {
			if ( 9 === (int) $row['user_id'] ) {
				$this->wpdb->tables['wallet_balances'][ $id ]['balance'] = '999.0000';
			}
		}

		$this->assert_true( ! $this->wallet->check_invariant( 9, 1 ), 'the drift is visible to the invariant check' );

		$result = $this->wallet->reconcile_all();
		$this->assert_same( 2, (int) $result['checked'], 'every cached balance is checked' );
		$this->assert_same( 1, (int) $result['repaired'], 'only the drifted wallet is repaired' );
		$this->assert_same( 40.0, $this->balance_of( 9 ), 'the repaired balance matches the ledger' );
		$this->assert_true( $this->wallet->check_invariant( 9, 1 ), 'the invariant holds again' );
		$this->assert_true( $this->wallet->check_invariant( 8, 1 ), 'the healthy wallet was never touched' );
	}

	private function invariant_holds_through_the_lifecycle(): void {
		$this->fresh();

		$this->wallet->credit( 10, 1000.0, WalletService::REASON_TOPUP, 'T', [], 1 );
		$this->wallet->debit( 10, 150.0, WalletService::REASON_ORDER_PAY, 'P1', [], 1, 1 );
		$this->wallet->reserve( 10, 300.0, 'R10', 1 );
		$this->wallet->release_reserve( 10, 'R10', 1 );
		$this->wallet->reserve( 10, 100.0, 'R11', 1 );
		$this->wallet->settle_reserve( 10, 'R11', 1 );
		$this->wallet->refund( 10, 'P1', 50.0, 'RA', 1, 1 );

		// 1000 - 150 (order) - 300 (R10) + 300 (R10 released) - 100 (R11, settled: stays consumed) + 50 (refund) = 800.
		$this->assert_same( 800.0, $this->balance_of( 10 ), 'the final balance is exact' );
		$this->assert_true( $this->wallet->check_invariant( 10, 1 ), 'cached balance equals the ledger sum' );
		$this->assert_same( 800.0, $this->wallet->recalculate( 10, 1 ), 'a full recalculation agrees' );
	}
}
