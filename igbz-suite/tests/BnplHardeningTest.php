<?php
/**
 * Phase 33 — BNPL hardening: the paid flip is conditional (a replayed callback and the wallet
 * auto-collect can never double-settle one instalment), provider callbacks flow through the
 * phase-29 inbox contract, auto-collect retries are bounded, and reconciliation reports drift
 * instead of hiding it.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\MultiTenant\Bnpl\BnplService;
use IGBZ\Suite\Support\Db;

/** In-memory engine for contracts + instalments. */
final class BnplDb extends wpdb {

	/** @var array<string,array<int,array<string,mixed>>> */
	public array $tables = [
		'bnpl_contracts'    => [],
		'bnpl_installments' => [],
		'bnpl_credit'       => [],
	];

	private int $next_id = 1;

	public function seed( string $table, array $row ): int {
		$id = $this->next_id++;
		$row['id'] = $id;
		$this->tables[ $table ][ $id ] = $row;
		return $id;
	}

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'bnpl_installments' ) ) {
			if ( preg_match( "/WHERE id = '?(\d+)'?( AND tenant_id = '?(\d+)'?)?/", $sql, $m ) && ! str_contains( $sql, 'sequence' ) ) {
				return $this->tables['bnpl_installments'][ (int) $m[1] ] ?? null;
			}
			if ( preg_match( "/WHERE contract_id = '?(\d+)'? AND sequence = '?(\d+)'?/", $sql, $m ) ) {
				foreach ( $this->tables['bnpl_installments'] as $row ) {
					if ( (string) $row['contract_id'] === $m[1] && (string) $row['sequence'] === $m[2] ) {
						return $row;
					}
				}
				return null;
			}
		}
		if ( str_contains( $sql, 'bnpl_contracts' ) && preg_match( "/WHERE id = '?(\d+)'?/", $sql, $m ) ) {
			return $this->tables['bnpl_contracts'][ (int) $m[1] ] ?? null;
		}
		return parent::get_row( $sql, $output );
	}

	public function get_results( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'bnpl_contracts' ) && str_contains( $sql, 'status IN' ) ) {
			$out = [];
			foreach ( $this->tables['bnpl_contracts'] as $row ) {
				if ( in_array( (string) $row['status'], [ 'active', 'settled' ], true ) ) {
					$out[] = $row;
				}
			}
			usort( $out, static fn ( $a, $b ): int => (int) $a['id'] <=> (int) $b['id'] );
			return array_slice( $out, 0, 500 );
		}

		if ( str_contains( $sql, 'bnpl_installments' ) && str_contains( $sql, 'due_date' ) ) {
			$out = [];
			foreach ( $this->tables['bnpl_installments'] as $row ) {
				if ( in_array( (string) $row['status'], [ 'due', 'overdue' ], true ) && strcmp( (string) $row['due_date'], gmdate( 'Y-m-d' ) ) < 0 ) {
					$out[] = $row;
				}
			}
			return array_slice( $out, 0, 200 );
		}
		return parent::get_results( $sql, $output );
	}

	public function get_var( string $sql ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'COUNT(*)' ) && str_contains( $sql, 'bnpl_installments' ) && preg_match( "/contract_id = '?(\d+)'?/", $sql, $m ) ) {
			$count = 0;
			foreach ( $this->tables['bnpl_installments'] as $row ) {
				if ( (string) $row['contract_id'] === $m[1] && in_array( (string) $row['status'], [ 'due', 'overdue' ], true ) ) {
					++$count;
				}
			}
			return (string) $count;
		}

		if ( str_contains( $sql, 'SUM(amount + penalty)' ) && preg_match( "/contract_id = '?(\d+)'?/", $sql, $m ) ) {
			$wanted = str_contains( $sql, 'paid' ) ? [ 'paid' ] : [ 'due', 'overdue' ];
			$sum    = 0.0;
			foreach ( $this->tables['bnpl_installments'] as $row ) {
				if ( (string) $row['contract_id'] === $m[1] && in_array( (string) $row['status'], $wanted, true ) ) {
					$sum += (float) $row['amount'] + (float) ( $row['penalty'] ?? 0 );
				}
			}
			return (string) $sum;
		}
		return parent::get_var( $sql );
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

	public function query( string $query ): int|bool {
		$this->queries[] = $query;
		if ( str_contains( $query, 'bnpl_credit' ) ) {
			return 1; // used_credit arithmetic — irrelevant to these scenarios.
		}
		return parent::query( $query );
	}
}

final class BnplHardeningTest extends TestCase {

	private BnplDb $wpdb;
	private BnplService $bnpl;

	public function run(): void {
		$this->paid_flip_is_conditional_and_replays_are_no_ops();
		$this->provider_notifications_apply_through_one_path();
		$this->unknown_verdict_stays_honest();
		$this->auto_collect_retries_are_bounded();
		$this->reconcile_settles_and_reports_drift();
	}

	private function fresh(): void {
		igbz_test_reset_settings();
		$this->wpdb      = new BnplDb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$logger          = igbz()->get( 'logger' );
		$this->bnpl      = new BnplService(
			new Db(),
			new \IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService( new Db(), $logger ),
			$logger,
			new \IGBZ\Suite\Modules\MultiTenant\Bnpl\ProviderRegistry( [ new \IGBZ\Suite\Modules\MultiTenant\Bnpl\InternalBnplProvider() ] )
		);
	}

	/** @return int */
	private function seed_contract_with( array $installments ): int {
		$contract = $this->wpdb->seed(
			'bnpl_contracts',
			[
				'tenant_id'     => 0,
				'user_id'       => 5,
				'provider'      => 'internal',
				'provider_ref'  => 'internal:1',
				'principal'     => 100.0,
				'total_payable' => 100.0,
				'status'        => 'active',
			]
		);
		foreach ( $installments as $n => $status ) {
			$this->wpdb->seed(
				'bnpl_installments',
				[
					'contract_id'         => $contract,
					'tenant_id'           => 0,
					'user_id'             => 5,
					'sequence'            => $n + 1,
					'amount'              => 50.0,
					'penalty'             => 0.0,
					'status'              => $status,
					'due_date'            => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ),
					'collection_attempts' => 0,
				]
			);
		}
		return $contract;
	}

	private function paid_flip_is_conditional_and_replays_are_no_ops(): void {
		$this->fresh();
		$contract = $this->seed_contract_with( [ 'due', 'paid' ] );
		$due_id   = array_keys( $this->wpdb->tables['bnpl_installments'] )[0];

		$this->assert_true( $this->bnpl->mark_installment_paid( $due_id, 'cb-1' ), 'the first mark lands' );
		$this->assert_same( 'paid', (string) $this->wpdb->tables['bnpl_installments'][ $due_id ]['status'], 'the instalment is paid' );

		$this->assert_true( ! $this->bnpl->mark_installment_paid( $due_id, 'cb-2' ), 'a replay is a no-op' );
		$this->assert_same( 'cb-1', (string) $this->wpdb->tables['bnpl_installments'][ $due_id ]['payment_ref'], 'the original reference survives' );

		$this->assert_same( 'settled', (string) $this->wpdb->tables['bnpl_contracts'][ $contract ]['status'], 'the last instalment settles the contract' );
	}

	private function provider_notifications_apply_through_one_path(): void {
		$this->fresh();
		$contract = $this->seed_contract_with( [ 'due', 'due' ] );
		[ $first, $second ] = array_keys( $this->wpdb->tables['bnpl_installments'] );

		$by_id = $this->bnpl->apply_provider_notification( [ 'installment_id' => $first, 'verdict' => 'paid', 'payment_ref' => 'EXT-1' ] );
		$this->assert_same( 'done', $by_id, 'a by-id notification is applied' );
		$this->assert_same( 'paid', (string) $this->wpdb->tables['bnpl_installments'][ $first ]['status'], 'the instalment moved' );

		$by_seq = $this->bnpl->apply_provider_notification( [ 'contract_id' => $contract, 'sequence' => 2, 'verdict' => 'paid', 'payment_ref' => 'EXT-2' ] );
		$this->assert_same( 'done', $by_seq, 'a contract+sequence notification resolves the row' );
		$this->assert_same( 'paid', (string) $this->wpdb->tables['bnpl_installments'][ $second ]['status'], 'and moves it' );

		$malformed = $this->bnpl->apply_provider_notification( [ 'verdict' => 'paid' ] );
		$this->assert_same( 'done', $malformed, 'malformed payloads are acknowledged, not retried forever' );
	}

	private function unknown_verdict_stays_honest(): void {
		$this->fresh();
		$this->seed_contract_with( [ 'due' ] );
		$id = array_keys( $this->wpdb->tables['bnpl_installments'] )[0];

		$this->assert_same( 'unknown', $this->bnpl->apply_provider_notification( [ 'installment_id' => $id, 'verdict' => 'unknown' ] ), 'unknown goes back to the inbox for retry' );
		$this->assert_same( 'unknown', $this->bnpl->apply_provider_notification( [ 'installment_id' => $id ] ), 'a missing verdict is unknown too' );
		$this->assert_same( 'due', (string) $this->wpdb->tables['bnpl_installments'][ $id ]['status'], 'nothing moved while unknown' );
	}

	private function auto_collect_retries_are_bounded(): void {
		$this->fresh();
		igbz()->settings()->set( 'bnpl.max_collect_attempts', '3' );

		$this->assert_same( 3, $this->bnpl->max_collect_attempts(), 'the cap is read from settings' );

		$this->seed_contract_with( [ 'overdue' ] );
		$id = array_keys( $this->wpdb->tables['bnpl_installments'] )[0];
		$this->wpdb->tables['bnpl_installments'][ $id ]['collection_attempts'] = 3;

		$before_queries = count( $this->wpdb->queries );
		$this->bnpl->process_overdue();
		$wallet_touches = array_filter(
			array_slice( $this->wpdb->queries, $before_queries ),
			static fn ( $q ) => str_contains( $q, 'wallet_' )
		);

		$this->assert_same( 3, (int) $this->wpdb->tables['bnpl_installments'][ $id ]['collection_attempts'], 'at the cap, no further attempts are counted' );
		$this->assert_same( 0, count( $wallet_touches ), 'and the wallet is never touched again' );
	}

	private function reconcile_settles_and_reports_drift(): void {
		$this->fresh();

		$clean   = $this->seed_contract_with( [ 'paid', 'paid' ] );
		$drifted = $this->seed_contract_with( [ 'paid', 'due' ] );
		$this->wpdb->tables['bnpl_contracts'][ $drifted ]['total_payable'] = 130.0; // paid 50 + outstanding 50 != 130

		$report = $this->bnpl->reconcile();

		$this->assert_same( 2, (int) $report['scanned'], 'both live contracts scan' );
		$this->assert_same( 1, (int) $report['settled'], 'the all-paid contract settles through the normal path' );
		$this->assert_same( 'settled', (string) $this->wpdb->tables['bnpl_contracts'][ $clean ]['status'], 'with its status flipped' );
		$this->assert_same( 1, (int) $report['mismatches'], 'the tampered total is reported...' );
		$this->assert_same( 'active', (string) $this->wpdb->tables['bnpl_contracts'][ $drifted ]['status'], '...and never silently fixed' );
	}
}
