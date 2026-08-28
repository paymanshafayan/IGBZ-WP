<?php
namespace IGBZ\Suite\Modules\Instagram\AiStudio;

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Comment giveaway drawn from real funnel hits — no Instagram API involved.
 *
 * Entries are the ManyChat comments already stored in ig_funnel_hits for a
 * post; the winner is picked with random_int (cryptographic, unbiased).
 */
final class GiveawayService {

	public const STATUS_OPEN   = 'open';
	public const STATUS_DRAWN  = 'drawn';

	public function __construct(
		private Db $db,
		private Logger $logger
	) {}

	/**
	 * @return array{ok:bool,message:string,giveaway_id:int}
	 */
	public function create( int $tenant_id, int $account_id, string $post_id, string $title ): array {
		$now = current_time( 'mysql', true );
		$id  = (int) $this->db->insert(
			'ig_giveaways',
			[
				'tenant_id'    => $tenant_id,
				'account_id'   => $account_id,
				'ig_post_id'      => $post_id,
				'title'        => $title,
				'status'       => self::STATUS_OPEN,
				'created_at'   => $now,
				'updated_at'   => $now,
			]
		);
		$this->logger->info( 'giveaway', 'Giveaway created', [ 'id' => $id ] );

		return [ 'ok' => true, 'message' => '', 'giveaway_id' => $id ];
	}

	/**
	 * Draw a winner from the funnel hits of the giveaway's post.
	 *
	 * @return array{ok:bool,message:string,winner_subscriber:string}
	 */
	public function draw( int $giveaway_id ): array {
		$giveaway = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'ig_giveaways' ) . ' WHERE id = %d AND tenant_id = %d', $giveaway_id, igbz()->tenancy()->id() );
		if ( ! $giveaway ) {
			return [ 'ok' => false, 'message' => __( 'Giveaway not found.', 'igbz-suite' ), 'winner_subscriber' => '' ];
		}
		if ( self::STATUS_DRAWN === $giveaway['status'] ) {
			return [ 'ok' => false, 'message' => __( 'Already drawn.', 'igbz-suite' ), 'winner_subscriber' => (string) $giveaway['winner_subscriber'] ];
		}

		$hits = $this->db->results(
			'SELECT manychat_subscriber_id, ig_username FROM ' . $this->db->table( 'ig_funnel_hits' ) . '
			 WHERE funnel_id IN (SELECT id FROM ' . $this->db->table( 'ig_funnels' ) . ' WHERE ig_post_id = %s)
			   AND manychat_subscriber_id <> %s
			 GROUP BY manychat_subscriber_id',
			(string) $giveaway['ig_post_id'],
			''
		);

		if ( ! $hits ) {
			return [ 'ok' => false, 'message' => __( 'No entries for this post.', 'igbz-suite' ), 'winner_subscriber' => '' ];
		}

		$winner = $hits[ random_int( 0, count( $hits ) - 1 ) ];
		$this->db->update(
			'ig_giveaways',
			[
				'status'           => self::STATUS_DRAWN,
				'winner_subscriber' => (string) ( $winner['manychat_subscriber_id'] ?? '' ),
				'entries_count'    => count( $hits ),
				'updated_at'       => current_time( 'mysql', true ),
			],
			[ 'id' => $giveaway_id ]
		);
		$this->logger->info( 'giveaway', 'Giveaway drawn', [ 'id' => $giveaway_id, 'entries' => count( $hits ) ] );

		return [
			'ok'               => true,
			'message'          => '',
			'winner_subscriber' => (string) ( $winner['ig_username'] ?? $winner['manychat_subscriber_id'] ?? '' ),
		];
	}

	/** @return array<int,array<string,mixed>> */
	public function all( int $limit = 50 ): array {
		return $this->db->results( 'SELECT * FROM ' . $this->db->table( 'ig_giveaways' ) . ' ORDER BY id DESC LIMIT %d', $limit );
	}
}
