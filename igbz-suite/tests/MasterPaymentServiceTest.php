<?php
/**
 * Phase 31 — escrow hardening: partial refunds with an over-refund guard and optimistic lock,
 * conditional release (no double fulfilment), dispute resolution, reconciliation that repairs
 * missing wallet credits, and idempotent withdrawals.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\MultiTenant\MasterPayment\MasterPaymentService;
use IGBZ\Suite\Support\Db;

/** In-memory engine for the escrow tables. */
final class EscrowDb extends wpdb {

	/** @var array<string,array<int,array<string,mixed>>> */
	public array $tables = [
		'ig_master_payments'    => [],
		'ig_master_disputes'    => [],
		'ig_master_withdrawals' => [],
		'tenant_members'        => [],
		'wallet_ledger'         => [],
	];

	private int $next_id = 1;

	/** When true, status flips conditioned on `held` report zero rows (a forced race). */
	public bool $force_flip_lost = false;

	/** When true, refunded_amount optimistic writes report zero rows. */
	public bool $force_refund_lost = false;

	public function seed( string $table, array $row ): int {
		$id = $this->next_id++;
		$row['id'] = $id;
		$this->tables[ $table ][ $id ] = $row + [ 'refunded_amount' => 0.0 ];
		return $id;
	}

	public function insert( string $table, array $data, $format = null ): int|bool {
		foreach ( $this->tables as $name => $rows ) {
			if ( str_contains( $table, $name ) ) {
				if ( 'ig_master_withdrawals' === $name && isset( $data['idempotency_key'] ) && null !== $data['idempotency_key'] ) {
					foreach ( $rows as $row ) {
						if ( (string) $row['tenant_id'] === (string) $data['tenant_id']
							&& (string) $row['idempotency_key'] === (string) $data['idempotency_key'] ) {
							$this->last_error = 'Duplicate entry';
							return 0;
						}
					}
				}
				$id = $this->next_id++;
				$data['id'] = $id;
				$this->tables[ $name ][ $id ] = $data + [ 'refunded_amount' => 0.0 ];
				$this->insert_id = $id;
				return 1;
			}
		}
		return parent::insert( $table, $data, $format );
	}

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'ig_master_payments' ) ) {
			if ( preg_match( "/WHERE id = '?(\d+)'? AND tenant_id = '?(\d+)'?/", $sql, $m ) ) {
				$row = $this->tables['ig_master_payments'][ (int) $m[1] ] ?? null;
				return ( $row && (string) $row['tenant_id'] === $m[2] ) ? $row : null;
			}
			if ( preg_match( "/WHERE order_id = '?(\d+)'? AND phase = '([^']*)' AND tenant_id = '?(\d+)'?/", $sql, $m ) ) {
				foreach ( $this->tables['ig_master_payments'] as $row ) {
					if ( (string) $row['order_id'] === $m[1] && (string) $row['phase'] === $m[2] && (string) $row['tenant_id'] === $m[3] ) {
						return $row;
					}
				}
				return null;
			}
		}
		if ( str_contains( $sql, 'ig_master_disputes' ) && preg_match( "/WHERE id = '?(\d+)'? AND tenant_id = '?(\d+)'?/", $sql, $m ) ) {
			$row = $this->tables['ig_master_disputes'][ (int) $m[1] ] ?? null;
			return ( $row && (string) $row['tenant_id'] === $m[2] ) ? $row : null;
		}
		if ( str_contains( $sql, 'ig_master_withdrawals' ) && preg_match( "/WHERE tenant_id = '?(\d+)'? AND idempotency_key = '([^']*)'/", $sql, $m ) ) {
			foreach ( $this->tables['ig_master_withdrawals'] as $row ) {
				if ( (string) $row['tenant_id'] === $m[1] && (string) ( $row['idempotency_key'] ?? '' ) === $m[2] ) {
					return $row;
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'tenant_members' ) && preg_match( "/WHERE tenant_id = '?(\d+)'?/", $sql, $m ) ) {
			foreach ( $this->tables['tenant_members'] as $row ) {
				if ( (string) $row['tenant_id'] === $m[1] ) {
					return $row;
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'wallet_ledger' ) && preg_match( "/WHERE reason = '([^']*)' AND reference_code = '([^']*)'/", $sql, $m ) ) {
			foreach ( $this->tables['wallet_ledger'] as $row ) {
				if ( (string) $row['reason'] === $m[1] && (string) $row['reference_code'] === $m[2] ) {
					return $row;
				}
			}
			return null;
		}
		return parent::get_row( $sql, $output );
	}

	public function get_results( string $sql, $output = null ) {
		$this->queries[] = $sql;

		$name = '';
		foreach ( $this->tables as $candidate => $rows ) {
			if ( str_contains( $sql, $candidate ) ) {
				$name = $candidate;
				break;
			}
		}
		if ( '' === $name ) {
			return parent::get_results( $sql, $output );
		}

		$out = [];
		foreach ( $this->tables[ $name ] as $row ) {
			if ( $this->matches( $sql, $row ) ) {
				$out[] = $row;
			}
		}
		usort( $out, static fn ( $a, $b ): int => (int) $a['id'] <=> (int) $b['id'] );
		if ( str_contains( $sql, 'ORDER BY id DESC' ) ) {
			usort( $out, static fn ( $a, $b ): int => (int) $b['id'] <=> (int) $a['id'] );
		}
		if ( preg_match( "/LIMIT '?(\d+)'?/", $sql, $l ) ) {
			$out = array_slice( $out, 0, (int) $l[1] );
		}
		return $out;
	}

	public function get_var( string $sql ) {
		$this->queries[] = $sql;
		if ( str_contains( $sql, 'COUNT(*)' ) && str_contains( $sql, 'ig_master_disputes' ) ) {
			$count = 0;
			foreach ( $this->tables['ig_master_disputes'] as $row ) {
				if ( 'open' !== (string) $row['status'] ) {
					continue;
				}
				if ( preg_match( "/payment_id = '?(\d+)'?/", $sql, $m ) && (string) $row['payment_id'] !== $m[1] ) {
					continue;
				}
				if ( preg_match( "/created_at <= '([^']*)'/", $sql, $m ) && strcmp( (string) $row['created_at'], $m[1] ) > 0 ) {
					continue;
				}
				++$count;
			}
			return (string) $count;
		}
		return parent::get_var( $sql );
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		foreach ( $this->tables as $name => $rows ) {
			if ( str_contains( $table, $name ) ) {
				if ( 'ig_master_payments' === $name && $this->force_flip_lost && isset( $where['status'] ) && 'held' === $where['status'] ) {
					return 0;
				}
				if ( 'ig_master_payments' === $name && $this->force_refund_lost && isset( $where['refunded_amount'] ) ) {
					return 0;
				}
				$changed = 0;
				foreach ( $rows as $id => $row ) {
					$hit = true;
					foreach ( $where as $column => $value ) {
						$current = $row[ $column ] ?? null;
						if ( is_numeric( $current ) && is_numeric( $value ) ) {
							if ( abs( (float) $current - (float) $value ) > 0.0001 ) {
								$hit = false;
								break;
							}
						} elseif ( (string) $current !== (string) $value ) {
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

	/** @param array<string,mixed> $row */
	private function matches( string $sql, array $row ): bool {
		if ( preg_match_all( "/\b(status|phase) = '([^']*)'/", $sql, $pairs, PREG_SET_ORDER ) ) {
			foreach ( $pairs as $p ) {
				if ( (string) ( $row[ $p[1] ] ?? '' ) !== $p[2] ) {
					return false;
				}
			}
		}
		if ( preg_match_all( "/\b(tenant_id) = '?(\d+)'?/", $sql, $pairs, PREG_SET_ORDER ) ) {
			foreach ( $pairs as $p ) {
				if ( (string) ( $row[ $p[1] ] ?? '' ) !== $p[2] ) {
					return false;
				}
			}
		}
		if ( preg_match_all( "/\bhold_until <= '([^']*)'/", $sql, $pairs, PREG_SET_ORDER ) ) {
			foreach ( $pairs as $p ) {
				if ( strcmp( (string) ( $row['hold_until'] ?? '9999' ), $p[1] ) > 0 ) {
					return false;
				}
			}
		}
		return true;
	}
}

/** Scripted wallet — no ledger arithmetic needed at this layer. */
final class EscrowWalletSpy {
	/** @var array<int,array<string,mixed>> */
	public array $credits = [];
	/** @var array<int,array<string,mixed>> */
	public array $debits = [];
	public bool $credit_ok = true;

	public function credit( int $user_id, float $amount, string $reason, string $reference_code, array $context = [], int $tenant_id = 0 ): bool {
		$this->credits[] = [ 'user_id' => $user_id, 'amount' => $amount, 'reason' => $reason, 'reference_code' => $reference_code, 'context' => $context ];
		return $this->credit_ok;
	}

	public function debit( int $user_id, float $amount, string $reason, string $reference_code, array $context = [], int $tenant_id = 0 ): bool {
		$this->debits[] = [ 'user_id' => $user_id, 'amount' => $amount, 'reason' => $reason, 'reference_code' => $reference_code ];
		return true;
	}

	public function balance( int $user_id ): array {
		return [ 'balance' => 1000.0 ];
	}
}

final class MasterPaymentServiceTest extends TestCase {

	private EscrowDb $wpdb;
	private MasterPaymentService $service;
	private EscrowWalletSpy $wallet;

	public function run(): void {
		$this->hold_dedupes_per_order_and_phase();
		$this->release_due_only_releases_due_and_undisputed();
		$this->partial_refunds_run_to_the_guard();
		$this->refund_refuses_double_fulfilment();
		$this->release_credits_the_unrefunded_remainder();
		$this->dispute_resolution_release_and_refund();
		$this->a_racing_release_loses_cleanly();
		$this->reconcile_repairs_missing_credits();
		$this->withdrawals_are_idempotent_by_key();
	}

	private function fresh(): void {
		igbz_test_reset_settings();
		$this->wpdb      = new EscrowDb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->wallet    = new EscrowWalletSpy();
		igbz()->bind( 'wallet', fn () => $this->wallet );
		$this->wpdb->seed( 'tenant_members', [ 'tenant_id' => 7, 'user_id' => 42 ] );
		$this->service = new MasterPaymentService( new Db(), igbz()->get( 'logger' ) );
	}

	private function hold( float $amount = 100.0, int $order = 1 ): array {
		return $this->service->hold( 7, $order, $amount, 'IRT', 'GW-' . $order );
	}

	private function hold_dedupes_per_order_and_phase(): void {
		$this->fresh();

		$first  = $this->hold();
		$second = $this->hold();
		$this->assert_true( (bool) $first['ok'], 'the first hold lands' );
		$this->assert_same( 'already_held', (string) $second['error'], 'the replay reports the original' );
		$this->assert_same( (int) $first['payment_id'], (int) $second['payment_id'], 'with its id' );
		$this->assert_same( 1, count( $this->wpdb->tables['ig_master_payments'] ), 'one row only' );
	}

	private function release_due_only_releases_due_and_undisputed(): void {
		$this->fresh();

		$past   = gmdate( 'Y-m-d H:i:s', time() - 3600 );
		$future = gmdate( 'Y-m-d H:i:s', time() + 3600 );

		$due      = $this->wpdb->seed( 'ig_master_payments', [ 'tenant_id' => 7, 'order_id' => 1, 'status' => 'held', 'amount' => 100.0, 'hold_until' => $past ] );
		$not_yet  = $this->wpdb->seed( 'ig_master_payments', [ 'tenant_id' => 7, 'order_id' => 2, 'status' => 'held', 'amount' => 50.0, 'hold_until' => $future ] );
		$disputed = $this->wpdb->seed( 'ig_master_payments', [ 'tenant_id' => 7, 'order_id' => 3, 'status' => 'held', 'amount' => 60.0, 'hold_until' => $past ] );
		$this->wpdb->seed( 'ig_master_disputes', [ 'tenant_id' => 7, 'payment_id' => $disputed, 'status' => 'open' ] );

		$released = $this->service->release_due();

		$this->assert_same( 1, $released, 'only the due, undisputed payment releases' );
		$this->assert_same( 'released', (string) $this->wpdb->tables['ig_master_payments'][ $due ]['status'], 'it is released' );
		$this->assert_same( 'held', (string) $this->wpdb->tables['ig_master_payments'][ $not_yet ]['status'], 'the future one waits' );
		$this->assert_same( 'held', (string) $this->wpdb->tables['ig_master_payments'][ $disputed ]['status'], 'the disputed one stays frozen' );
		$this->assert_same( 100.0, (float) $this->wallet->credits[0]['amount'], 'the full amount goes to the owner wallet' );
		$this->assert_same( 'master:' . $due, (string) $this->wallet->credits[0]['reference_code'], 'keyed for idempotent replays' );
	}

	private function partial_refunds_run_to_the_guard(): void {
		$this->fresh();
		$held = $this->hold();
		$id   = (int) $held['payment_id'];

		$one = $this->service->refund( $id, 7, 30.0 );
		$this->assert_true( (bool) $one['ok'], 'a partial refund lands' );
		$this->assert_same( 30.0, (float) $one['refunded'], 'with the running total' );
		$this->assert_same( 'held', (string) $this->wpdb->tables['ig_master_payments'][ $id ]['status'], 'the escrow stays held' );

		$too_much = $this->service->refund( $id, 7, 80.0 );
		$this->assert_same( 'over_refund', (string) $too_much['error'], 'the guard blocks an overshoot before any write' );
		$this->assert_same( 30.0, (float) $this->wpdb->tables['ig_master_payments'][ $id ]['refunded_amount'], 'and nothing moved' );

		$rest = $this->service->refund( $id, 7 );
		$this->assert_true( (bool) $rest['ok'], 'a null amount refunds the remainder' );
		$this->assert_same( 100.0, (float) $rest['refunded'], 'exactly the held amount in total' );
		$this->assert_same( 'refunded', (string) $this->wpdb->tables['ig_master_payments'][ $id ]['status'], 'and the payment closes' );

		$this->wpdb->force_refund_lost = true;
		$other = (int) $this->hold( 50.0, 2 )['payment_id'];
		$raced = $this->service->refund( $other, 7, 10.0 );
		$this->assert_same( 'lost_race', (string) $raced['error'], 'a racing writer reports honestly' );
		$this->wpdb->force_refund_lost = false;
	}

	private function refund_refuses_double_fulfilment(): void {
		$this->fresh();

		$released = $this->wpdb->seed( 'ig_master_payments', [ 'tenant_id' => 7, 'order_id' => 9, 'status' => 'released', 'amount' => 100.0 ] );
		$out      = $this->service->refund( $released, 7, 10.0 );
		$this->assert_same( 'already_released', (string) $out['error'], 'money already paid out cannot be refunded from escrow' );

		$refunded = $this->wpdb->seed( 'ig_master_payments', [ 'tenant_id' => 7, 'order_id' => 10, 'status' => 'refunded', 'amount' => 100.0, 'refunded_amount' => 100.0 ] );
		$out      = $this->service->refund( $refunded, 7, 10.0 );
		$this->assert_same( 'already_refunded', (string) $out['error'], 'a closed refund stays closed' );

		$foreign = $this->wpdb->seed( 'ig_master_payments', [ 'tenant_id' => 8, 'order_id' => 11, 'status' => 'held', 'amount' => 100.0 ] );
		$out     = $this->service->refund( $foreign, 7, 10.0 );
		$this->assert_same( 'payment_not_found', (string) $out['error'], 'tenant scoping holds' );
	}

	private function release_credits_the_unrefunded_remainder(): void {
		$this->fresh();

		$id = (int) $this->hold()['payment_id'];
		$this->service->refund( $id, 7, 25.0 );
		$this->wpdb->tables['ig_master_payments'][ $id ]['hold_until'] = gmdate( 'Y-m-d H:i:s', time() - 1 );

		$this->service->release_due();

		$this->assert_same( 'released', (string) $this->wpdb->tables['ig_master_payments'][ $id ]['status'], 'the remainder releases' );
		$this->assert_same( 75.0, (float) $this->wallet->credits[0]['amount'], 'the owner gets exactly the un-refunded share' );
	}

	private function dispute_resolution_release_and_refund(): void {
		$this->fresh();

		$pay_a = (int) $this->hold( 100.0, 21 )['payment_id'];
		$d_a   = (int) $this->service->open_dispute( $pay_a, 'app', 'broken item', 7 )['dispute_id'];
		$this->assert_same( 'disputed', (string) $this->wpdb->tables['ig_master_payments'][ $pay_a ]['status'], 'a dispute freezes the payment' );

		$bad = $this->service->resolve_dispute( $d_a, 7, 'bananas' );
		$this->assert_same( 'invalid_verdict', (string) $bad['error'], 'only release/refund are verdicts' );

		$out = $this->service->resolve_dispute( $d_a, 7, 'release', 'evidence ok' );
		$this->assert_true( (bool) $out['ok'], 'release resolves' );
		$this->assert_same( 'held', (string) $this->wpdb->tables['ig_master_payments'][ $pay_a ]['status'], 'back on the release track' );
		$this->assert_same( 'resolved', (string) $this->wpdb->tables['ig_master_disputes'][ $d_a ]['status'], 'the dispute closes' );

		$again = $this->service->resolve_dispute( $d_a, 7, 'release' );
		$this->assert_same( 'already_resolved', (string) $again['error'], 'a closed dispute stays closed' );

		$pay_b = (int) $this->hold( 80.0, 22 )['payment_id'];
		$d_b   = (int) $this->service->open_dispute( $pay_b, 'support', 'never delivered', 7 )['dispute_id'];
		$out   = $this->service->resolve_dispute( $d_b, 7, 'refund', 'customer right' );
		$this->assert_true( (bool) $out['ok'], 'refund resolves' );
		$this->assert_same( 'refunded', (string) $this->wpdb->tables['ig_master_payments'][ $pay_b ]['status'], 'with a full refund' );
	}

	private function a_racing_release_loses_cleanly(): void {
		$this->fresh();

		$id = (int) $this->hold()['payment_id'];
		$this->wpdb->tables['ig_master_payments'][ $id ]['hold_until'] = gmdate( 'Y-m-d H:i:s', time() - 1 );
		$this->wpdb->force_flip_lost = true;

		$released = $this->service->release_due();

		$this->assert_same( 0, $released, 'the racer reports zero releases' );
		$this->assert_same( 'held', (string) $this->wpdb->tables['ig_master_payments'][ $id ]['status'], 'no double fulfilment' );
		$this->wpdb->force_flip_lost = false;
	}

	private function reconcile_repairs_missing_credits(): void {
		$this->fresh();

		$with    = $this->wpdb->seed( 'ig_master_payments', [ 'tenant_id' => 7, 'order_id' => 31, 'status' => 'released', 'amount' => 100.0 ] );
		$without = $this->wpdb->seed( 'ig_master_payments', [ 'tenant_id' => 7, 'order_id' => 32, 'status' => 'released', 'amount' => 40.0 ] );
		$this->wpdb->seed( 'wallet_ledger', [ 'reason' => 'topup', 'reference_code' => 'master:' . $with ] );
		$this->wpdb->seed( 'ig_master_disputes', [ 'tenant_id' => 7, 'payment_id' => $with, 'status' => 'open', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ] );

		$report = $this->service->reconcile();

		$this->assert_same( 1, (int) $report['missing_credit'], 'the gap is detected' );
		$this->assert_same( 1, (int) $report['repaired'], 'and repaired' );
		$this->assert_same( 40.0, (float) $this->wallet->credits[0]['amount'], 'the repair credits the exact amount' );
		$this->assert_same( 1, (int) $report['stale_disputes'], 'an old open dispute is surfaced' );
	}

	private function withdrawals_are_idempotent_by_key(): void {
		$this->fresh();

		$first  = $this->service->request_withdrawal( 7, 42, 120.0, 'card', '6037…', 'W-1' );
		$second = $this->service->request_withdrawal( 7, 42, 120.0, 'card', '6037…', 'W-1' );

		$this->assert_true( (bool) $first['ok'], 'the first withdrawal debits' );
		$this->assert_true( (bool) ( $second['duplicate'] ?? false ), 'the replay is a duplicate...' );
		$this->assert_same( (int) $first['withdrawal_id'], (int) $second['withdrawal_id'], '...pointing at the original' );
		$this->assert_same( 1, count( $this->wallet->debits ), 'the wallet is debited exactly once' );
		$this->assert_same( 1, count( $this->wpdb->tables['ig_master_withdrawals'] ), 'one withdrawal row' );

		$third = $this->service->request_withdrawal( 7, 42, 10.0, 'card', '', '' );
		$this->assert_true( (bool) $third['ok'], 'keyless withdrawals still work' );
		$this->assert_same( 2, count( $this->wpdb->tables['ig_master_withdrawals'] ), 'each gets its own row' );
	}
}
