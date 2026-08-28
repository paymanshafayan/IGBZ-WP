<?php
namespace IGBZ\Suite\Modules\MultiTenant\Bnpl;

use IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Buy-now-pay-later: credit assessment, contract creation, instalment schedule generation,
 * repayment (wallet or gateway), late penalties and reminders.
 *
 * Port note: the nopCommerce version shipped a dead ISnappPayBnplGateway/SnappPayBnplGateway pair
 * that was registered but never called. Here external providers go through one explicit
 * BnplProviderInterface and the built-in "internal" provider is the default, so nothing is dead code.
 */
final class BnplService {

	public const STATUS_PENDING   = 'pending';
	public const STATUS_ACTIVE    = 'active';
	public const STATUS_SETTLED   = 'settled';
	public const STATUS_DEFAULTED = 'defaulted';
	public const STATUS_CANCELLED = 'cancelled';

	public const INSTALLMENT_DUE     = 'due';
	public const INSTALLMENT_PAID    = 'paid';
	public const INSTALLMENT_OVERDUE = 'overdue';
	public const INSTALLMENT_WAIVED  = 'waived';

	private ProviderRegistry $providers;

	public function __construct( private Db $db, private WalletService $wallet, private Logger $logger, ?ProviderRegistry $providers = null ) {
		$this->providers = $providers ?? new ProviderRegistry();
	}

	public function providers(): ProviderRegistry {
		return $this->providers;
	}

	/** Resolve the provider that underwrites a given contract row. */
	public function provider_for( string $id ): BnplProviderInterface {
		return $this->providers->get( '' !== $id ? $id : 'internal' );
	}

	// ------------------------------------------------------------ eligibility

	/** @return array<string,mixed>|null */
	public function credit_profile( int $user_id, int $tenant_id = 0 ): ?array {
		return $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'bnpl_credit' ) . ' WHERE user_id = %d AND tenant_id = %d',
			$user_id,
			$tenant_id
		);
	}

	public function ensure_credit_profile( int $user_id, int $tenant_id = 0 ): array {
		$profile = $this->credit_profile( $user_id, $tenant_id );
		if ( $profile ) {
			return $profile;
		}
		$this->db->insert(
			'bnpl_credit',
			[
				'tenant_id'    => $tenant_id,
				'user_id'      => $user_id,
				'credit_limit' => (float) igbz()->settings()->get( 'bnpl.default_credit_limit', 0 ),
				'used_credit'  => 0,
				'score'        => $this->score( $user_id, $tenant_id ),
				'status'       => 'active',
				'updated_at'   => current_time( 'mysql', true ),
			]
		);
		return $this->credit_profile( $user_id, $tenant_id ) ?? [];
	}

	/**
	 * Simple, transparent scoring: order history, wallet health and repayment record.
	 * Deliberately deterministic so the merchant can explain a decision to a customer.
	 */
	public function score( int $user_id, int $tenant_id = 0 ): int {
		$score = 300;

		$paid_orders = wc_get_orders(
			[
				'customer_id' => $user_id,
				'status'      => [ 'wc-completed', 'wc-processing' ],
				'limit'       => 50,
				'return'      => 'ids',
			]
		);
		$score += min( 200, count( (array) $paid_orders ) * 20 );

		$balance = $this->wallet->balance( $user_id, $tenant_id );
		if ( $balance > 0 ) {
			$score += 50;
		}

		$late = (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'bnpl_installments' ) . '
			 WHERE user_id = %d AND status = %s',
			$user_id,
			self::INSTALLMENT_OVERDUE
		);
		$score -= $late * 80;

		$settled = (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'bnpl_contracts' ) . ' WHERE user_id = %d AND status = %s',
			$user_id,
			self::STATUS_SETTLED
		);
		$score += min( 250, $settled * 50 );

		$defaults = (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'bnpl_contracts' ) . ' WHERE user_id = %d AND status = %s',
			$user_id,
			self::STATUS_DEFAULTED
		);
		$score -= $defaults * 300;

		return (int) max( 0, min( 1000, apply_filters( 'igbz_bnpl_score', $score, $user_id, $tenant_id ) ) );
	}

	/** @return array{eligible:bool,reason:string,available:float,limit:float,score:int} */
	public function eligibility( int $user_id, float $amount, int $tenant_id = 0 ): array {
		$settings = igbz()->settings();

		if ( ! $settings->bool( 'bnpl.enabled', true ) ) {
			return $this->ineligible( __( 'Instalment payments are disabled.', 'igbz-suite' ) );
		}
		if ( $user_id <= 0 ) {
			return $this->ineligible( __( 'You must be signed in to use instalments.', 'igbz-suite' ) );
		}

		$min = (float) $settings->get( 'bnpl.min_order_total', 0 );
		if ( $amount < $min ) {
			return $this->ineligible(
				sprintf(
					/* translators: %s: minimum amount */
					__( 'Instalments require a minimum order of %s.', 'igbz-suite' ),
					wp_strip_all_tags( wc_price( $min ) )
				)
			);
		}

		$profile = $this->ensure_credit_profile( $user_id, $tenant_id );
		if ( 'active' !== ( $profile['status'] ?? '' ) ) {
			return $this->ineligible( __( 'Your instalment account is not active.', 'igbz-suite' ) );
		}

		$overdue = (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'bnpl_installments' ) . ' WHERE user_id = %d AND status = %s',
			$user_id,
			self::INSTALLMENT_OVERDUE
		);
		if ( $overdue > 0 ) {
			return $this->ineligible( __( 'You have overdue instalments. Settle them before opening a new plan.', 'igbz-suite' ) );
		}

		$limit     = (float) $profile['credit_limit'];
		$used      = (float) $profile['used_credit'];
		$available = round( $limit - $used, 2 );

		if ( $amount > $available ) {
			return [
				'eligible'  => false,
				'reason'    => sprintf(
					/* translators: %s: available credit */
					__( 'Your available instalment credit is %s.', 'igbz-suite' ),
					wp_strip_all_tags( wc_price( max( 0, $available ) ) )
				),
				'available' => max( 0, $available ),
				'limit'     => $limit,
				'score'     => (int) $profile['score'],
			];
		}

		return [
			'eligible'  => true,
			'reason'    => '',
			'available' => $available,
			'limit'     => $limit,
			'score'     => (int) $profile['score'],
		];
	}

	/** @return array{eligible:bool,reason:string,available:float,limit:float,score:int} */
	private function ineligible( string $reason ): array {
		return [ 'eligible' => false, 'reason' => $reason, 'available' => 0.0, 'limit' => 0.0, 'score' => 0 ];
	}

	// ------------------------------------------------------------- quotation

	/**
	 * Preview a schedule without persisting anything (used by the checkout widget).
	 *
	 * @return array{principal:float,down_payment:float,fee:float,total:float,installments:array<int,array{sequence:int,amount:float,due_date:string}>}
	 */
	public function quote( float $amount, ?int $count = null, ?float $down_payment = null ): array {
		$settings = igbz()->settings();
		$count    = max( 1, $count ?? $settings->int( 'bnpl.default_installments', 4 ) );
		$interval = max( 1, $settings->int( 'bnpl.interval_days', 30 ) );
		$fee_pct  = (float) $settings->get( 'bnpl.fee_percent', 0 );

		$down    = null === $down_payment ? round( $amount / $count, 2 ) : round( min( $amount, max( 0, $down_payment ) ), 2 );
		$rest    = round( $amount - $down, 2 );
		$fee     = round( $rest * $fee_pct / 100, 2 );
		$total   = round( $amount + $fee, 2 );
		$remain  = $count > 1 ? $count - 1 : 0;
		$payable = round( $rest + $fee, 2 );

		$schedule = [];
		if ( $down > 0 ) {
			$schedule[] = [ 'sequence' => 0, 'amount' => $down, 'due_date' => gmdate( 'Y-m-d' ) ];
		}
		if ( $remain > 0 ) {
			$each      = floor( ( $payable / $remain ) * 100 ) / 100;
			$allocated = 0.0;
			for ( $i = 1; $i <= $remain; $i++ ) {
				$value      = ( $i === $remain ) ? round( $payable - $allocated, 2 ) : $each;
				$allocated += $value;
				$schedule[] = [
					'sequence'  => $i,
					'amount'    => $value,
					'due_date'  => gmdate( 'Y-m-d', time() + $i * $interval * DAY_IN_SECONDS ),
				];
			}
		} elseif ( 0.0 === $down && $payable > 0 ) {
			$schedule[] = [ 'sequence' => 1, 'amount' => $payable, 'due_date' => gmdate( 'Y-m-d', time() + $interval * DAY_IN_SECONDS ) ];
		}

		return [
			'principal'    => round( $amount, 2 ),
			'down_payment' => $down,
			'fee'          => $fee,
			'total'        => $total,
			'installments' => $schedule,
		];
	}

	// -------------------------------------------------------------- contracts

	/**
	 * Create a contract plus its instalment rows in one transaction and reserve the credit.
	 */
	public function create_contract( int $user_id, float $amount, int $order_id = 0, ?int $count = null, ?float $down_payment = null, int $tenant_id = 0, string $provider = '' ): int {
		$provider = '' !== $provider ? $provider : $this->providers->default()->id();
		$eligibility = $this->eligibility( $user_id, $amount, $tenant_id );
		if ( ! $eligibility['eligible'] ) {
			throw new \RuntimeException( $eligibility['reason'] );
		}

		$quote = $this->quote( $amount, $count, $down_payment );
		$now   = current_time( 'mysql', true );

		return $this->db->transaction(
			function () use ( $user_id, $tenant_id, $order_id, $provider, $quote, $now ) {
				$contract_id = $this->db->insert(
					'bnpl_contracts',
					[
						'tenant_id'         => $tenant_id,
						'user_id'           => $user_id,
						'order_id'          => $order_id,
						'provider'          => $provider,
						'principal'         => $quote['principal'],
						'down_payment'      => $quote['down_payment'],
						'fee_amount'        => $quote['fee'],
						'total_payable'     => $quote['total'],
						'installment_count' => count( $quote['installments'] ),
						'interval_days'     => igbz()->settings()->int( 'bnpl.interval_days', 30 ),
						'status'            => self::STATUS_PENDING,
						'created_at'        => $now,
						'updated_at'        => $now,
					]
				);

				if ( 0 === $contract_id ) {
					throw new \RuntimeException( 'Could not create the instalment contract.' );
				}

				foreach ( $quote['installments'] as $installment ) {
					$this->db->insert(
						'bnpl_installments',
						[
							'contract_id' => $contract_id,
							'tenant_id'   => $tenant_id,
							'user_id'     => $user_id,
							'sequence'    => (int) $installment['sequence'],
							'amount'      => (float) $installment['amount'],
							'due_date'    => (string) $installment['due_date'],
							'status'      => self::INSTALLMENT_DUE,
						]
					);
				}

				$this->db->query(
					'UPDATE ' . $this->db->table( 'bnpl_credit' ) . ' SET used_credit = used_credit + %f, updated_at = %s WHERE user_id = %d AND tenant_id = %d',
					$quote['principal'],
					$now,
					$user_id,
					$tenant_id
				);

				$this->logger->info( 'bnpl', 'Contract created', [ 'contract_id' => $contract_id, 'user_id' => $user_id, 'total' => $quote['total'] ] );
				do_action( 'igbz_bnpl_contract_created', $contract_id, $user_id, $order_id );

				return $contract_id;
			}
		);
	}

	/**
	 * Activate a contract. The credit provider underwrites it first; a refusal leaves the
	 * contract pending and releases nothing, so the caller can retry or cancel.
	 */
	public function activate_contract( int $contract_id ): bool {
		$contract = $this->contract( $contract_id );
		if ( ! $contract ) {
			return false;
		}
		if ( self::STATUS_ACTIVE === $contract['status'] ) {
			return true;
		}

		$provider = $this->provider_for( (string) $contract['provider'] );
		$decision = $provider->underwrite( $contract + [ 'installments' => $this->installments( $contract_id ) ] );

		if ( empty( $decision['approved'] ) ) {
			$this->db->update(
				'bnpl_contracts',
				[ 'updated_at' => current_time( 'mysql', true ) ],
				[ 'id' => $contract_id ]
			);
			$this->logger->warning(
				'bnpl',
				'Provider declined the contract',
				[ 'contract_id' => $contract_id, 'provider' => $provider->id(), 'message' => (string) ( $decision['message'] ?? '' ) ]
			);
			do_action( 'igbz_bnpl_contract_declined', $contract_id, $decision );
			return false;
		}

		$now = current_time( 'mysql', true );
		$ok  = $this->db->update(
			'bnpl_contracts',
			[
				'status'       => self::STATUS_ACTIVE,
				'provider_ref' => (string) ( $decision['reference'] ?? '' ),
				'signed_at'    => $now,
				'updated_at'   => $now,
			],
			[ 'id' => $contract_id ]
		) >= 0;
		do_action( 'igbz_bnpl_contract_activated', $contract_id );
		return $ok;
	}

	public function cancel_contract( int $contract_id, string $reason = '' ): bool {
		$contract = $this->contract( $contract_id );
		if ( ! $contract || self::STATUS_SETTLED === $contract['status'] ) {
			return false;
		}
		$now = current_time( 'mysql', true );
		$this->db->update( 'bnpl_contracts', [ 'status' => self::STATUS_CANCELLED, 'updated_at' => $now ], [ 'id' => $contract_id ] );
		$this->db->query(
			'UPDATE ' . $this->db->table( 'bnpl_installments' ) . ' SET status = %s WHERE contract_id = %d AND status != %s',
			self::INSTALLMENT_WAIVED,
			$contract_id,
			self::INSTALLMENT_PAID
		);
		$this->release_credit( $contract );

		$reference = (string) ( $contract['provider_ref'] ?? '' );
		if ( '' !== $reference ) {
			$this->provider_for( (string) $contract['provider'] )->cancel( $reference );
		}

		$this->logger->info( 'bnpl', 'Contract cancelled', [ 'contract_id' => $contract_id, 'reason' => $reason ] );
		do_action( 'igbz_bnpl_contract_cancelled', $contract_id, $reason );
		return true;
	}

	/** @return array<string,mixed>|null */
	public function contract( int $contract_id ): ?array {
		return $this->db->row( 'SELECT * FROM ' . $this->db->table( 'bnpl_contracts' ) . ' WHERE id = %d AND tenant_id = %d', $contract_id, igbz()->tenancy()->id() );
	}

	/** @return array<int,array<string,mixed>> */
	public function installments( int $contract_id ): array {
		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'bnpl_installments' ) . ' WHERE contract_id = %d AND tenant_id = %d ORDER BY sequence',
			$contract_id,
			igbz()->tenancy()->id()
		);
	}

	/** @return array<int,array<string,mixed>> */
	public function contracts_for_user( int $user_id, int $tenant_id = 0 ): array {
		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'bnpl_contracts' ) . ' WHERE user_id = %d AND tenant_id = %d ORDER BY id DESC',
			$user_id,
			$tenant_id
		);
	}

	public function outstanding( int $contract_id ): float {
		return (float) $this->db->scalar(
			'SELECT COALESCE(SUM(amount + penalty),0) FROM ' . $this->db->table( 'bnpl_installments' ) . '
			 WHERE contract_id = %d AND status IN (%s,%s)',
			$contract_id,
			self::INSTALLMENT_DUE,
			self::INSTALLMENT_OVERDUE
		);
	}

	// -------------------------------------------------------------- repayment

	/**
	 * Pay one instalment from the wallet. Idempotent through the ledger reference code.
	 */
	public function pay_installment_from_wallet( int $installment_id ): bool {
		$installment = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'bnpl_installments' ) . ' WHERE id = %d AND tenant_id = %d', $installment_id, igbz()->tenancy()->id() );
		if ( ! $installment || self::INSTALLMENT_PAID === $installment['status'] ) {
			return false;
		}

		$amount = (float) $installment['amount'] + (float) $installment['penalty'];
		$result = $this->wallet->debit(
			(int) $installment['user_id'],
			$amount,
			WalletService::REASON_BNPL_PAY,
			'installment:' . $installment_id,
			[ 'contract_id' => (int) $installment['contract_id'] ],
			(int) $installment['tenant_id'],
			0,
			sprintf( __( 'Instalment %d payment', 'igbz-suite' ), (int) $installment['sequence'] )
		);

		if ( ! $result->success ) {
			$this->logger->warning( 'bnpl', 'Instalment payment failed', [ 'installment_id' => $installment_id, 'error' => $result->error_code ] );
			return false;
		}

		return $this->mark_installment_paid( $installment_id, 'wallet:' . $result->entry_id );
	}

	public function mark_installment_paid( int $installment_id, string $payment_ref = '' ): bool {
		$installment = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'bnpl_installments' ) . ' WHERE id = %d AND tenant_id = %d', $installment_id, igbz()->tenancy()->id() );
		if ( ! $installment ) {
			return false;
		}

		$this->db->update(
			'bnpl_installments',
			[
				'status'      => self::INSTALLMENT_PAID,
				'paid_at'     => current_time( 'mysql', true ),
				'payment_ref' => $payment_ref,
			],
			[ 'id' => $installment_id ]
		);

		$contract = $this->contract( (int) $installment['contract_id'] );
		if ( $contract ) {
			$this->provider_for( (string) $contract['provider'] )->report_payment(
				$installment + [
					'payment_ref'  => $payment_ref,
					'provider_ref' => (string) $contract['provider_ref'],
				]
			);
		}

		do_action( 'igbz_bnpl_installment_paid', $installment_id, (int) $installment['contract_id'] );
		$this->maybe_settle_contract( (int) $installment['contract_id'] );
		return true;
	}

	public function maybe_settle_contract( int $contract_id ): bool {
		$remaining = (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'bnpl_installments' ) . ' WHERE contract_id = %d AND status IN (%s,%s)',
			$contract_id,
			self::INSTALLMENT_DUE,
			self::INSTALLMENT_OVERDUE
		);
		if ( $remaining > 0 ) {
			return false;
		}

		$contract = $this->contract( $contract_id );
		if ( ! $contract ) {
			return false;
		}

		$now = current_time( 'mysql', true );
		$this->db->update(
			'bnpl_contracts',
			[ 'status' => self::STATUS_SETTLED, 'settled_at' => $now, 'updated_at' => $now ],
			[ 'id' => $contract_id ]
		);
		$this->release_credit( $contract );

		$this->logger->info( 'bnpl', 'Contract settled', [ 'contract_id' => $contract_id ] );
		do_action( 'igbz_bnpl_contract_settled', $contract_id, (int) $contract['user_id'] );
		return true;
	}

	/** @param array<string,mixed> $contract */
	private function release_credit( array $contract ): void {
		$this->db->query(
			'UPDATE ' . $this->db->table( 'bnpl_credit' ) . '
			 SET used_credit = GREATEST(0, used_credit - %f), updated_at = %s
			 WHERE user_id = %d AND tenant_id = %d',
			(float) $contract['principal'],
			current_time( 'mysql', true ),
			(int) $contract['user_id'],
			(int) $contract['tenant_id']
		);
	}

	// ------------------------------------------------------ cron: dunning

	/** Flag overdue instalments, accrue penalties and try auto-collection from the wallet. */
	public function process_overdue(): int {
		$today = gmdate( 'Y-m-d' );
		$rows  = $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'bnpl_installments' ) . '
			 WHERE status IN (%s,%s) AND due_date < %s LIMIT 200',
			self::INSTALLMENT_DUE,
			self::INSTALLMENT_OVERDUE,
			$today
		);

		$penalty_rate = (float) igbz()->settings()->get( 'bnpl.penalty_percent_per_day', 0 );
		$processed    = 0;

		foreach ( $rows as $row ) {
			$installment_id = (int) $row['id'];
			$days_late      = max( 1, (int) floor( ( time() - strtotime( (string) $row['due_date'] ) ) / DAY_IN_SECONDS ) );
			$penalty        = round( (float) $row['amount'] * $penalty_rate / 100 * $days_late, 2 );

			$this->db->update(
				'bnpl_installments',
				[ 'status' => self::INSTALLMENT_OVERDUE, 'penalty' => $penalty ],
				[ 'id' => $installment_id ]
			);

			// Auto-collect when the wallet can cover it.
			if ( igbz()->settings()->bool( 'bnpl.auto_collect', true ) ) {
				$this->pay_installment_from_wallet( $installment_id );
			}

			if ( $days_late >= (int) igbz()->settings()->get( 'bnpl.default_after_days', 60 ) ) {
				$this->db->update(
					'bnpl_contracts',
					[ 'status' => self::STATUS_DEFAULTED, 'updated_at' => current_time( 'mysql', true ) ],
					[ 'id' => (int) $row['contract_id'] ]
				);
				do_action( 'igbz_bnpl_contract_defaulted', (int) $row['contract_id'], (int) $row['user_id'] );
			}

			$processed++;
		}

		return $processed;
	}

	/** Send reminders a configurable number of days before the due date. */
	public function send_reminders(): int {
		$lead   = (int) igbz()->settings()->get( 'bnpl.reminder_days_before', 3 );
		$target = gmdate( 'Y-m-d', time() + $lead * DAY_IN_SECONDS );

		$rows = $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'bnpl_installments' ) . '
			 WHERE status = %s AND due_date = %s AND reminder_sent_at IS NULL LIMIT 200',
			self::INSTALLMENT_DUE,
			$target
		);

		foreach ( $rows as $row ) {
			do_action( 'igbz_bnpl_reminder_due', (int) $row['id'], (int) $row['user_id'], (float) $row['amount'], (string) $row['due_date'] );
			$this->db->update( 'bnpl_installments', [ 'reminder_sent_at' => current_time( 'mysql', true ) ], [ 'id' => (int) $row['id'] ] );
		}

		return count( $rows );
	}

	// -------------------------------------------------------------- admin

	public function set_credit_limit( int $user_id, float $limit, int $tenant_id = 0 ): bool {
		$this->ensure_credit_profile( $user_id, $tenant_id );
		return $this->db->update(
			'bnpl_credit',
			[ 'credit_limit' => $limit, 'updated_at' => current_time( 'mysql', true ) ],
			[ 'user_id' => $user_id, 'tenant_id' => $tenant_id ]
		) >= 0;
	}

	/** @return array{contracts:int,active:int,defaulted:int,outstanding:float} */
	public function stats( int $tenant_id = 0 ): array {
		$row = $this->db->row(
			'SELECT
				COUNT(*) AS contracts,
				SUM(status = %s) AS active,
				SUM(status = %s) AS defaulted
			 FROM ' . $this->db->table( 'bnpl_contracts' ) . ' WHERE tenant_id = %d',
			self::STATUS_ACTIVE,
			self::STATUS_DEFAULTED,
			$tenant_id
		);
		$outstanding = (float) $this->db->scalar(
			'SELECT COALESCE(SUM(amount + penalty),0) FROM ' . $this->db->table( 'bnpl_installments' ) . '
			 WHERE tenant_id = %d AND status IN (%s,%s)',
			$tenant_id,
			self::INSTALLMENT_DUE,
			self::INSTALLMENT_OVERDUE
		);
		return [
			'contracts'   => (int) ( $row['contracts'] ?? 0 ),
			'active'      => (int) ( $row['active'] ?? 0 ),
			'defaulted'   => (int) ( $row['defaulted'] ?? 0 ),
			'outstanding' => $outstanding,
		];
	}
}
