<?php
namespace IGBZ\Suite\Modules\MultiTenant\Payments;

use IGBZ\Suite\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 29 — the shared payment state machine.
 *
 * Every surface that can change a payment's status (browser return callbacks, provider
 * webhooks, admin tools, reconciliation) must go through this machine. Two rules protect the
 * money path:
 *
 *  1. Only legal transitions are applied — a failed payment can never jump to paid, and a paid
 *     payment is terminal (refunds are a separate ledger operation, not a status hop).
 *  2. The write is a conditional UPDATE pinned on the current status, so two racing callbacks
 *     for the same payment cannot both win: the loser sees zero affected rows and reports the
 *     race instead of corrupting the state.
 *
 * `unknown` is a first-class resting place for the "provider did not answer / answered
 * ambiguously" case — the payment is neither paid nor failed, and a later re-verify may move it
 * to a terminal state. Dropping that case silently is exactly how money goes missing.
 */
final class PaymentStateMachine {

	public const STATUS_UNKNOWN = 'unknown';

	/** @var array<string,array<int,string>> from => allowed to-states */
	private const TRANSITIONS = [
		PaymentService::STATUS_CREATED   => [ PaymentService::STATUS_PENDING, PaymentService::STATUS_CANCELLED ],
		PaymentService::STATUS_PENDING   => [ PaymentService::STATUS_PAID, PaymentService::STATUS_FAILED, PaymentService::STATUS_CANCELLED, self::STATUS_UNKNOWN ],
		self::STATUS_UNKNOWN             => [ PaymentService::STATUS_PAID, PaymentService::STATUS_FAILED, PaymentService::STATUS_CANCELLED ],
		PaymentService::STATUS_PAID      => [],
		PaymentService::STATUS_FAILED    => [],
		PaymentService::STATUS_CANCELLED => [],
	];

	private function __construct( private Db $db ) {}

	public static function make( Db $db ): self {
		return new self( $db );
	}

	/** Is a transition legal? Unknown states are illegal origins and destinations alike. */
	public static function can( string $from, string $to ): bool {
		return in_array( $to, self::TRANSITIONS[ $from ] ?? [], true );
	}

	/** @return array<int,string> */
	public static function states(): array {
		return array_keys( self::TRANSITIONS );
	}

	/**
	 * Apply one transition atomically.
	 *
	 * @param array<string,mixed> $extra Extra columns written alongside the status
	 *                                   (reference_id, error_code, verified_at, ...).
	 * @return array{ok:bool,from:string,reason?:string}
	 */
	public function advance( int $payment_id, string $to, array $extra = [] ): array {
		$row = $this->db->row(
			'SELECT status FROM ' . $this->db->table( 'payments' ) . ' WHERE id = %d',
			$payment_id
		);
		if ( ! $row ) {
			return [ 'ok' => false, 'from' => '', 'reason' => 'not_found' ];
		}
		$from = (string) $row['status'];

		if ( ! self::can( $from, $to ) ) {
			return [ 'ok' => false, 'from' => $from, 'reason' => 'illegal_transition' ];
		}

		$data                 = array_merge( $extra, [ 'status' => $to, 'updated_at' => current_time( 'mysql', true ) ] );
		$data['status']       = $to;
		$changed = $this->db->update(
			'payments',
			$data,
			[
				'id'     => $payment_id,
				'status' => $from,
			]
		);
		if ( 0 === $changed ) {
			// Someone else won the race between our read and write. Honest report, no corruption.
			return [ 'ok' => false, 'from' => $from, 'reason' => 'lost_race' ];
		}

		/**
		 * Fires after a payment transition is safely persisted.
		 *
		 * @param int    $payment_id
		 * @param string $from
		 * @param string $to
		 */
		do_action( 'igbz_payment_transition', $payment_id, $from, $to );
		return [ 'ok' => true, 'from' => $from ];
	}
}
