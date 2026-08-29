<?php
namespace IGBZ\Suite\Modules\MultiTenant\Seo;

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Advertising campaigns with approval and cost control.
 *
 * Every campaign is born pending_approval; only an approved campaign may
 * spend, spending is checked against the remaining budget before the money
 * leaves, and a launch is refused the moment the cap cannot cover it.
 */
final class AdCampaignService {

	public const STATUS_PENDING  = 'pending_approval';
	public const STATUS_APPROVED = 'approved';
	public const STATUS_REJECTED = 'rejected';
	public const STATUS_COMPLETED = 'completed';

	public function __construct(
		private Db $db,
		private Logger $logger,
		private AdvertorialPublisherInterface $network
	) {}

	/** @return array<string,mixed> the created campaign row */
	public function create( string $title, string $channel, int $budget_irt, int $tenant_id = 0 ): array {
		$now = current_time( 'mysql', true );
		$id  = $this->db->insert(
			'ig_ad_campaigns',
			[
				'tenant_id'   => $tenant_id > 0 ? $tenant_id : (int) igbz()->tenancy()->id(),
				'title'       => mb_substr( $title, 0, 191 ),
				'channel'     => $channel,
				'budget_irt'  => max( 0, $budget_irt ),
				'spent_irt'   => 0,
				'status'      => self::STATUS_PENDING,
				'created_at'  => $now,
				'updated_at'  => $now,
			]
		);

		return (array) $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'ig_ad_campaigns' ) . ' WHERE id = %d',
			(int) $id
		);
	}

	/** @return array<string,mixed>|null */
	public function campaign( int $id ): ?array {
		$row = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'ig_ad_campaigns' ) . ' WHERE id = %d',
			$id
		);

		return $row ?: null;
	}

	/** Approval is a one-way gate; only a pending campaign can pass it. */
	public function approve( int $campaign_id, int $approver_id ): array {
		$campaign = $this->campaign( $campaign_id );
		if ( null === $campaign ) {
			return [ 'ok' => false, 'error' => 'not_found' ];
		}
		if ( self::STATUS_PENDING !== (string) $campaign['status'] ) {
			return [ 'ok' => false, 'error' => 'bad_state' ];
		}

		$this->db->update(
			'ig_ad_campaigns',
			[
				'status'      => self::STATUS_APPROVED,
				'approver_id' => $approver_id,
				'approved_at' => current_time( 'mysql', true ),
				'updated_at'  => current_time( 'mysql', true ),
			],
			[ 'id' => $campaign_id ]
		);
		$this->logger->info( 'seo', 'Campaign approved', [ 'campaign' => $campaign_id, 'approver' => $approver_id ] );

		return [ 'ok' => true, 'error' => '' ];
	}

	/** Rejection is terminal and must say why. */
	public function reject( int $campaign_id, string $reason ): array {
		$campaign = $this->campaign( $campaign_id );
		if ( null === $campaign ) {
			return [ 'ok' => false, 'error' => 'not_found' ];
		}
		if ( self::STATUS_PENDING !== (string) $campaign['status'] ) {
			return [ 'ok' => false, 'error' => 'bad_state' ];
		}

		$this->db->update(
			'ig_ad_campaigns',
			[
				'status'        => self::STATUS_REJECTED,
				'reject_reason' => mb_substr( $reason, 0, 255 ),
				'updated_at'    => current_time( 'mysql', true ),
			],
			[ 'id' => $campaign_id ]
		);

		return [ 'ok' => true, 'error' => '' ];
	}

	/** The money that is still allowed to leave this campaign. */
	public function remaining( array $campaign ): int {
		return max( 0, (int) $campaign['budget_irt'] - (int) $campaign['spent_irt'] );
	}

	/**
	 * Publish one advertorial through the configured network, paid for by an
	 * approved campaign that still has the headroom. Nothing is sent before
	 * the budget says yes.
	 *
	 * @return array{ok:bool,error:string,reference:string}
	 */
	public function launch_advertorial( int $campaign_id, string $title, string $body_html, int $cost_irt, array $target_media = [] ): array {
		$campaign = $this->campaign( $campaign_id );
		if ( null === $campaign ) {
			return [ 'ok' => false, 'error' => 'not_found', 'reference' => '' ];
		}
		if ( self::STATUS_APPROVED !== (string) $campaign['status'] ) {
			return [ 'ok' => false, 'error' => 'bad_state', 'reference' => '' ];
		}
		if ( $cost_irt <= 0 || $cost_irt > $this->remaining( $campaign ) ) {
			return [ 'ok' => false, 'error' => 'over_budget', 'reference' => '' ];
		}
		if ( ! $this->network->is_configured() ) {
			return [ 'ok' => false, 'error' => 'not_configured', 'reference' => '' ];
		}

		$result = $this->network->publish_advertorial( $title, $body_html, $target_media );
		if ( ! $result['ok'] ) {
			return [ 'ok' => false, 'error' => 'provider_failed', 'reference' => '' ];
		}

		// The spend is recorded only after the provider acknowledged the order.
		$this->db->update(
			'ig_ad_campaigns',
			[
				'spent_irt'  => (int) $campaign['spent_irt'] + $cost_irt,
				'updated_at' => current_time( 'mysql', true ),
			],
			[ 'id' => $campaign_id ]
		);
		$this->logger->info( 'seo', 'Advertorial launched', [ 'campaign' => $campaign_id, 'cost' => $cost_irt, 'reference' => (string) $result['reference'] ] );

		return [ 'ok' => true, 'error' => '', 'reference' => (string) $result['reference'] ];
	}
}
