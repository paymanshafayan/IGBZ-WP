<?php
namespace IGBZ\Suite\Modules\MultiTenant\Gamification;

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Customer AI-studio credits: granted as a percentage of every purchase and
 * top-up-able through the payment pipeline (purpose = ai_credit_topup).
 * Balance is always SUM(delta) over the ledger — no stored-balance field.
 */
final class AiCreditsService {

	public const REASON_PURCHASE = 'purchase';
	public const REASON_TOPUP    = 'topup';
	public const REASON_SPEND    = 'spend';

	public function __construct(
		private Db $db,
		private Logger $logger
	) {}

	public function balance( int $user_id ): float {
		return (float) $this->db->scalar(
			'SELECT COALESCE(SUM(delta), 0) FROM ' . $this->db->table( 'ig_ai_credit_ledger' ) . ' WHERE user_id = %d AND tenant_id = %d',
			$user_id,
			igbz()->tenancy()->id()
		);
	}

	/** Grant credits from a paid order. Idempotent per order. */
	public function grant_from_order( int $order_id, int $user_id, float $order_total_irt ): bool {
		$percent = (float) igbz()->settings()->float( 'ai_credits.purchase_percent', 2.0 );
		if ( $percent <= 0 ) {
			return false;
		}

		$delta = round( $order_total_irt * $percent / 100, 4 );
		if ( $delta <= 0 ) {
			return false;
		}

		return $this->ledger( $user_id, $delta, self::REASON_PURCHASE, 'order:' . $order_id, [ 'order_total' => $order_total_irt ] );
	}

	/** @return array{ok:bool,balance:float,error:string} */
	public function spend( int $user_id, float $amount, string $reference ): array {
		$balance = $this->balance( $user_id );
		if ( $balance < $amount ) {
			return [ 'ok' => false, 'balance' => $balance, 'error' => 'insufficient' ];
		}

		$this->ledger( $user_id, -1 * $amount, self::REASON_SPEND, $reference );
		return [ 'ok' => true, 'balance' => $this->balance( $user_id ), 'error' => '' ];
	}

	public function ledger( int $user_id, float $delta, string $reason, string $reference, array $meta = [] ): bool {
		$existing = (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'ig_ai_credit_ledger' ) . ' WHERE user_id = %d AND reason = %s AND reference = %s AND tenant_id = %d',
			$user_id,
			$reason,
			$reference,
			igbz()->tenancy()->id()
		);
		if ( $existing > 0 ) {
			return false;
		}

		$this->db->insert(
			'ig_ai_credit_ledger',
			[
				'tenant_id'  => (int) igbz()->tenancy()->id(),
				'user_id'    => $user_id,
				'delta'      => $delta,
				'reason'     => $reason,
				'reference'  => $reference,
				'meta'       => wp_json_encode( $meta ),
				'created_at' => current_time( 'mysql', true ),
			]
		);
		$this->logger->info( 'ai_credits', 'Credit ledger entry', [ 'user_id' => $user_id, 'delta' => $delta, 'reason' => $reason ] );

		return true;
	}
}
