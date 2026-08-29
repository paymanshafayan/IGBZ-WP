<?php
namespace IGBZ\Suite\Modules\Instagram\Vip;

use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;
use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * VIP posts: create, publish, schedule, expire.
 *
 * The feed is Instagram-shaped on purpose — a locked post is still returned, with its caption and
 * counts intact and only the media stripped. Hiding locked posts entirely would remove the very
 * thing that sells a membership.
 */
final class VipPostService {

	public const STATUS_DRAFT     = 'draft';
	public const STATUS_SCHEDULED = 'scheduled';
	public const STATUS_PUBLISHED = 'published';
	public const STATUS_EXPIRED   = 'expired';
	public const STATUS_DELETED   = 'deleted';

	public const KIND_IMAGE    = 'image';
	public const KIND_CAROUSEL = 'carousel';
	public const KIND_VIDEO    = 'video';
	public const KIND_TEXT     = 'text';

	public const EXPIRY_HIDE   = 'hide';
	public const EXPIRY_DELETE = 'delete';

	public function __construct(
		private Db $db,
		private Settings $settings,
		private Logger $logger,
		private VipAccessService $access,
		private VipMediaService $media
	) {}

	// ----------------------------------------------------------------- create

	/**
	 * @param array<string,mixed> $data
	 */
	public function create( array $data, int $tenant_id = 0, int $author_id = 0 ): int {
		$now       = current_time( 'mysql', true );
		$tenant_id = $tenant_id > 0 ? $tenant_id : (int) ( $data['tenant_id'] ?? 0 );
		$author_id = $author_id > 0 ? $author_id : get_current_user_id();

		$media  = $this->normalise_media( $data['media'] ?? [] );
		$kind   = $this->derive_kind( $data['kind'] ?? '', $media );
		$access = $this->sanitise_access( (string) ( $data['access'] ?? VipAccessService::ACCESS_MEMBERS ) );
		$price  = VipAccessService::ACCESS_PURCHASE === $access ? max( 0.0, (float) ( $data['price'] ?? 0 ) ) : 0.0;

		$publish_at = $this->to_datetime( $data['publish_at'] ?? '' );
		$status     = $this->sanitise_status( (string) ( $data['status'] ?? self::STATUS_DRAFT ) );

		// A row asked to be scheduled with no date, or with a date in the past, is published now.
		// Leaving it in `scheduled` would strand it: the cron only picks up rows with publish_at.
		if ( self::STATUS_SCHEDULED === $status && ( null === $publish_at || $publish_at <= $now ) ) {
			$status     = self::STATUS_PUBLISHED;
			$publish_at = null;
		}

		$row = [
			'tenant_id'         => $tenant_id,
			'account_id'        => (int) ( $data['account_id'] ?? 0 ),
			'author_id'         => $author_id,
			'shortcode'         => $this->unique_shortcode(),
			'kind'              => $kind,
			'caption'           => (string) ( $data['caption'] ?? '' ),
			'media'             => wp_json_encode( $media ),
			'teaser_content_id' => (int) ( $data['teaser_content_id'] ?? 0 ),
			'product_id'        => (int) ( $data['product_id'] ?? 0 ),
			'access'            => $access,
			'price'             => $price,
			'status'            => $status,
			'comments_enabled'  => isset( $data['comments_enabled'] )
				? (int) (bool) $data['comments_enabled']
				: (int) $this->settings->bool( 'vip.comments_enabled', true ),
			'publish_at'        => $publish_at,
			'published_at'      => self::STATUS_PUBLISHED === $status ? $now : null,
			'expires_at'        => $this->resolve_expiry( $data, self::STATUS_PUBLISHED === $status ? $now : $publish_at ),
			'expiry_action'     => $this->sanitise_expiry_action( (string) ( $data['expiry_action'] ?? $this->settings->string( 'vip.default_expiry_action', self::EXPIRY_HIDE ) ) ),
			'created_at'        => $now,
			'updated_at'        => $now,
		];

		$id = $this->db->insert( 'vip_posts', $row );

		if ( $id > 0 ) {
			$this->logger->info( 'vip', 'VIP post created', [ 'post_id' => $id, 'status' => $status, 'access' => $access ] );
			if ( self::STATUS_PUBLISHED === $status ) {
				do_action( 'igbz_vip_post_published', $id );
			}
		}

		return $id;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function update( int $post_id, array $data ): bool {
		$post = $this->post( $post_id );
		if ( ! $post ) {
			return false;
		}

		$fields = [ 'updated_at' => current_time( 'mysql', true ) ];

		if ( array_key_exists( 'caption', $data ) ) {
			$fields['caption'] = (string) $data['caption'];
		}
		if ( array_key_exists( 'media', $data ) ) {
			$media           = $this->normalise_media( $data['media'] );
			$fields['media'] = wp_json_encode( $media );
			$fields['kind']  = $this->derive_kind( (string) ( $data['kind'] ?? $post['kind'] ), $media );
		}
		if ( array_key_exists( 'access', $data ) ) {
			$fields['access'] = $this->sanitise_access( (string) $data['access'] );
			if ( VipAccessService::ACCESS_PURCHASE !== $fields['access'] ) {
				$fields['price'] = 0.0;
			}
		}
		if ( array_key_exists( 'price', $data ) ) {
			$effective = $fields['access'] ?? (string) $post['access'];
			if ( VipAccessService::ACCESS_PURCHASE === $effective ) {
				$fields['price'] = max( 0.0, (float) $data['price'] );
			}
		}
		if ( array_key_exists( 'product_id', $data ) ) {
			$fields['product_id'] = (int) $data['product_id'];
		}
		if ( array_key_exists( 'teaser_content_id', $data ) ) {
			$fields['teaser_content_id'] = (int) $data['teaser_content_id'];
		}
		if ( array_key_exists( 'comments_enabled', $data ) ) {
			$fields['comments_enabled'] = (int) (bool) $data['comments_enabled'];
		}
		if ( array_key_exists( 'expires_at', $data ) ) {
			$fields['expires_at'] = $this->to_datetime( $data['expires_at'] );
		} elseif ( array_key_exists( 'expiry_days', $data ) ) {
			$base                 = (string) ( $post['published_at'] ?: current_time( 'mysql', true ) );
			$fields['expires_at'] = $this->resolve_expiry( $data, $base );
		}
		if ( array_key_exists( 'expiry_action', $data ) ) {
			$fields['expiry_action'] = $this->sanitise_expiry_action( (string) $data['expiry_action'] );
		}
		if ( array_key_exists( 'publish_at', $data ) ) {
			$fields['publish_at'] = $this->to_datetime( $data['publish_at'] );
		}

		return $this->db->update( 'vip_posts', $fields, [ 'id' => $post_id ] ) >= 0;
	}

	/**
	 * Phase 54: the publish transitions are a state machine, not a free-for-all. Only
	 * `draft` and `scheduled` may go live; an expired or deleted post answers false instead
	 * of silently resurrecting (its media may already be shredded), and the flip is
	 * conditional so a double beat produces one publish and one hook.
	 */
	public function publish( int $post_id ): bool {
		$post = $this->post( $post_id );
		if ( ! $post ) {
			return false;
		}
		if ( ! in_array( (string) $post['status'], [ self::STATUS_DRAFT, self::STATUS_SCHEDULED ], true ) ) {
			return false;
		}

		$now = current_time( 'mysql', true );

		// Recompute the expiry from the real publish moment. A post drafted a week ago with "expires
		// in 7 days" must expire seven days from now, not the instant it goes live.
		$expires = $post['expires_at'];
		if ( null === $expires ) {
			$days = $this->settings->int( 'vip.default_expiry_days', 0 );
			if ( $days > 0 ) {
				$expires = gmdate( 'Y-m-d H:i:s', strtotime( $now ) + ( $days * DAY_IN_SECONDS ) );
			}
		}

		$done = $this->db->query(
			'UPDATE ' . $this->db->table( 'vip_posts' ) . '
			 SET status = %s, published_at = %s, expires_at = %s, updated_at = %s
			 WHERE id = %d AND status IN (%s, %s)',
			self::STATUS_PUBLISHED,
			$now,
			$expires,
			$now,
			$post_id,
			self::STATUS_DRAFT,
			self::STATUS_SCHEDULED
		) > 0;

		if ( $done ) {
			do_action( 'igbz_vip_post_published', $post_id );
		}

		return $done;
	}

	public function delete( int $post_id, bool $purge_media = true ): bool {
		$post = $this->post( $post_id );
		if ( ! $post || self::STATUS_DELETED === (string) $post['status'] ) {
			return false;
		}

		$now    = current_time( 'mysql', true );
		$media  = $this->decode_media( $post );
		$fields = [
			'status'     => self::STATUS_DELETED,
			'updated_at' => $now,
		];

		// Phase 54: the media JSON is the purge ledger. It is cleared only when the files are
		// really gone; a partial purge keeps the list so `reconcile()` can retry the rest —
		// access is already dead (the status flip below is what denies it), so keeping the
		// row's bytes costs nothing and loses nothing.
		if ( $purge_media ) {
			$this->media->purge( $media );
			if ( $this->media->purge_complete( $media ) ) {
				$fields['media']          = wp_json_encode( [] );
				$fields['media_purged_at'] = $now;
			}
		}

		return $this->db->update( 'vip_posts', $fields, [ 'id' => $post_id ] ) > 0;
	}

	// ------------------------------------------------------------------- cron

	/**
	 * Publish anything whose scheduled time has arrived.
	 */
	public function publish_due(): int {
		$rows = $this->db->results(
			'SELECT id FROM ' . $this->db->table( 'vip_posts' ) . '
			 WHERE status = %s AND publish_at IS NOT NULL AND publish_at <= %s
			 LIMIT 50',
			self::STATUS_SCHEDULED,
			current_time( 'mysql', true )
		);

		$count = 0;
		foreach ( $rows as $row ) {
			if ( $this->publish( (int) $row['id'] ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Retire posts past their expiry.
	 *
	 * `hide` is the default rather than `delete` deliberately: dropping the row takes the comments
	 * and the view counts with it, and the shop owner loses any way to tell how that post performed.
	 * The media file is still purged either way, because that is what actually costs disk.
	 */
	public function expire_due(): int {
		$rows = $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'vip_posts' ) . '
			 WHERE status = %s AND expires_at IS NOT NULL AND expires_at <= %s
			 LIMIT 50',
			self::STATUS_PUBLISHED,
			current_time( 'mysql', true )
		);

		$now   = current_time( 'mysql', true );
		$count = 0;

		foreach ( $rows as $post ) {
			$post_id = (int) $post['id'];
			$action  = (string) $post['expiry_action'];
			$purge   = $this->settings->bool( 'vip.purge_media_on_expiry', true );

			$media  = $this->decode_media( $post );
			$fields = [
				'status'     => self::EXPIRY_DELETE === $action ? self::STATUS_DELETED : self::STATUS_EXPIRED,
				'expired_at' => $now,
				'updated_at' => $now,
			];

			if ( $purge ) {
				$this->media->purge( $media );
				if ( $this->media->purge_complete( $media ) ) {
					$fields['media']           = wp_json_encode( [] );
					$fields['media_purged_at'] = $now;
				}
			}

			// Phase 54: conditional flip — the sweep racing itself (or an admin unpublishing)
			// must not fire the expiry hook twice, and a row that changed underneath the
			// SELECT is left to the next round. Media columns are written only when they
			// actually changed, so a purge-disabled row keeps its ledger untouched.
			$sets   = [ 'status = %s', 'expired_at = %s', 'updated_at = %s' ];
			$args   = [ (string) $fields['status'], $now, $now ];
			if ( array_key_exists( 'media', $fields ) ) {
				$sets[] = 'media = %s';
				$args[] = (string) $fields['media'];
			}
			if ( array_key_exists( 'media_purged_at', $fields ) ) {
				$sets[] = 'media_purged_at = %s';
				$args[] = (string) $fields['media_purged_at'];
			}
			$args[] = $post_id;
			$args[] = self::STATUS_PUBLISHED;
			$args[] = $now;

			$won = $this->db->query(
				'UPDATE ' . $this->db->table( 'vip_posts' ) . '
				 SET ' . implode( ', ', $sets ) . '
				 WHERE id = %d AND status = %s AND expires_at IS NOT NULL AND expires_at <= %s',
				...$args
			) > 0;

			if ( ! $won ) {
				continue;
			}

			do_action( 'igbz_vip_post_expired', $post_id, $action );
			++$count;
		}

		if ( $count > 0 ) {
			$this->logger->info( 'vip', 'Expired VIP posts', [ 'count' => $count ] );
		}

		return $count;
	}

	/**
	 * Phase 54: the daily safety net for the channel.
	 *
	 * Two honest failures the sweeps cannot catch in the moment: a media purge that only
	 * partly succeeded (the file list survives in the row, so the retry knows exactly what
	 * is left), and denormalised counts drifting when moderation removes rows outside the
	 * toggle path. Both are bounded; a full batch means the caller continues the round.
	 *
	 * @return int rows acted upon — drives the queue's continuation contract.
	 */
	public function reconcile( int $limit = 50 ): int {
		$now    = current_time( 'mysql', true );
		$acted  = 0;

		// 1) Retry purges: retired rows whose media is not provably gone yet.
		$rows = $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'vip_posts' ) . '
			 WHERE status IN (%s, %s) AND media_purged_at IS NULL
			 ORDER BY expired_at ASC, id ASC
			 LIMIT %d',
			self::STATUS_EXPIRED,
			self::STATUS_DELETED,
			$limit
		);

		foreach ( $rows as $post ) {
			$media = $this->decode_media( $post );
			if ( [] === $media ) {
				// Nothing left to purge (a pre-phase-54 row already cleared its JSON): stamp
				// it so the sweep stops carrying the row.
				$this->db->update(
					'vip_posts',
					[ 'media_purged_at' => $now, 'updated_at' => $now ],
					[ 'id' => (int) $post['id'] ]
				);
				++$acted;
				continue;
			}

			$this->media->purge( $media );
			if ( $this->media->purge_complete( $media ) ) {
				$this->db->update(
					'vip_posts',
					[ 'media' => wp_json_encode( [] ), 'media_purged_at' => $now, 'updated_at' => $now ],
					[ 'id' => (int) $post['id'] ]
				);
				++$acted;
				$this->logger->info( 'vip', 'VIP media purge completed on retry', [ 'post_id' => (int) $post['id'] ] );
			}
		}

		// 2) Count drift: likes and visible comments are recounted from their tables so the
		// denormalised columns cannot lie forever after a moderation action.
		$acted += $this->recount_drift( $limit );

		return $acted;
	}

	/** Recount likes/comments for published posts whose stored counts drifted. Bounded. */
	private function recount_drift( int $limit ): int {
		$rows = $this->db->results(
			'SELECT id, likes_count, comments_count FROM ' . $this->db->table( 'vip_posts' ) . '
			 WHERE status = %s
			 ORDER BY id ASC
			 LIMIT %d',
			self::STATUS_PUBLISHED,
			$limit
		);

		$acted = 0;
		foreach ( $rows as $row ) {
			$post_id = (int) $row['id'];

			$likes = (int) $this->db->scalar(
				'SELECT COUNT(*) FROM ' . $this->db->table( 'vip_post_likes' ) . ' WHERE post_id = %d',
				$post_id
			);
			$comments = (int) $this->db->scalar(
				'SELECT COUNT(*) FROM ' . $this->db->table( 'vip_post_comments' ) . '
				 WHERE post_id = %d AND status = %s',
				$post_id,
				'visible'
			);

			if ( $likes !== (int) $row['likes_count'] || $comments !== (int) $row['comments_count'] ) {
				$this->db->update(
					'vip_posts',
					[
						'likes_count'    => $likes,
						'comments_count' => $comments,
						'updated_at'     => current_time( 'mysql', true ),
					],
					[ 'id' => $post_id ]
				);
				++$acted;
			}
		}

		return $acted;
	}

	// ------------------------------------------------------------------ reads

	/** @return array<string,mixed>|null */
	public function post( int $post_id ): ?array {
		return $this->db->row( 'SELECT * FROM ' . $this->db->table( 'vip_posts' ) . ' WHERE id = %d', $post_id );
	}

	/** @return array<string,mixed>|null */
	public function post_by_shortcode( string $shortcode ): ?array {
		$shortcode = sanitize_text_field( $shortcode );
		if ( '' === $shortcode ) {
			return null;
		}
		return $this->db->row( 'SELECT * FROM ' . $this->db->table( 'vip_posts' ) . ' WHERE shortcode = %s', $shortcode );
	}

	/**
	 * The member-facing feed.
	 *
	 * @param array<string,mixed> $args
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	public function feed( int $user_id, array $args = [] ): array {
		$tenant_id = (int) ( $args['tenant_id'] ?? 0 );
		$page      = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page  = min( 50, max( 1, (int) ( $args['per_page'] ?? $this->settings->int( 'vip.feed_page_size', 12 ) ) ) );
		$offset    = ( $page - 1 ) * $per_page;

		$where = 'status = %s AND (tenant_id = %d OR %d = 0)';
		$bind  = [ self::STATUS_PUBLISHED, $tenant_id, $tenant_id ];

		$total = (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'vip_posts' ) . " WHERE {$where}",
			...$bind
		);

		$rows = $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'vip_posts' ) . " WHERE {$where}
			 ORDER BY published_at DESC, id DESC
			 LIMIT %d OFFSET %d",
			...array_merge( $bind, [ $per_page, $offset ] )
		);

		$grants = $this->access->check_many( $user_id, $rows );
		$ids    = array_map( static fn( $r ) => (int) $r['id'], $rows );
		$liked  = $this->liked_map( $user_id, $ids );
		$saved  = $this->saved_map( $user_id, $ids );

		$items = [];
		foreach ( $rows as $row ) {
			$id      = (int) $row['id'];
			$items[] = $this->present(
				$row,
				$grants[ $id ] ?? VipAccess::deny( VipAccess::DENY_NO_MEMBER ),
				isset( $liked[ $id ] ),
				isset( $saved[ $id ] )
			);
		}

		return [ 'items' => $items, 'total' => $total ];
	}

	/**
	 * Shape one post for the app.
	 *
	 * A locked post keeps its caption, counts and a blurred cover; only the real media is withheld.
	 * That is what makes the feed feel like Instagram instead of a paywall.
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	public function present( array $row, VipAccess $access, bool $liked = false, bool $saved = false ): array {
		$media  = $this->decode_media( $row );
		$locked = ! $access->allowed;

		$out = [
			'id'               => (int) $row['id'],
			'shortcode'        => (string) $row['shortcode'],
			'kind'             => (string) $row['kind'],
			'caption'          => (string) ( $row['caption'] ?? '' ),
			'product_id'       => (int) $row['product_id'],
			'published_at'     => $row['published_at'],
			'expires_at'       => $row['expires_at'],
			'retention_days'   => $this->retention_days(),
			'expiry_notice'    => $this->expiry_notice( $row['expires_at'] ?? null ),
			'likes_count'      => (int) $row['likes_count'],
			'comments_count'   => (int) $row['comments_count'],
			'views_count'      => (int) $row['views_count'],
			'comments_enabled' => (bool) (int) $row['comments_enabled'],
			'liked'            => $liked,
			'saved'            => $saved,
			'media_count'      => count( $media ),
			'locked'           => $locked,
			'access'           => $access->to_array(),
		];

		if ( $locked ) {
			// Covers only: enough to show a blurred placeholder at the right aspect ratio, never a
			// URL that resolves to the real file.
			$out['media'] = array_map(
				static fn( array $m ): array => [
					'type'   => (string) ( $m['type'] ?? 'image' ),
					'width'  => (int) ( $m['width'] ?? 0 ),
					'height' => (int) ( $m['height'] ?? 0 ),
					'blur'   => (string) ( $m['blur'] ?? '' ),
				],
				$media
			);
			return $out;
		}

		$out['media'] = array_map(
			fn( array $m, int $i ): array => [
				'type'     => (string) ( $m['type'] ?? 'image' ),
				'url'      => $this->media->signed_url( (int) $row['id'], $i, get_current_user_id() ),
				'thumb'    => (string) ( $m['thumb'] ?? '' ),
				'width'    => (int) ( $m['width'] ?? 0 ),
				'height'   => (int) ( $m['height'] ?? 0 ),
				'duration' => (int) ( $m['duration'] ?? 0 ),
			],
			$media,
			array_keys( $media )
		);

		return $out;
	}

	/** @param array<string,mixed> $row */
	public function decode_media( array $row ): array {
		$media = json_decode( (string) ( $row['media'] ?? '[]' ), true );
		return is_array( $media ) ? array_values( $media ) : [];
	}

	// -------------------------------------------------------------- internals

	/**
	 * @param int[] $post_ids
	 * @return array<int,true>
	 */
	private function liked_map( int $user_id, array $post_ids ): array {
		return $this->flag_map( 'vip_post_likes', $user_id, $post_ids );
	}

	/**
	 * @param array<int,int> $post_ids
	 * @return array<int,bool>
	 */
	private function saved_map( int $user_id, array $post_ids ): array {
		return $this->flag_map( 'vip_post_saves', $user_id, $post_ids );
	}

	/**
	 * @param array<int,int> $post_ids
	 * @return array<int,bool>
	 */
	private function flag_map( string $table, int $user_id, array $post_ids ): array {
		$post_ids = array_values( array_filter( array_map( 'intval', $post_ids ) ) );
		if ( $user_id <= 0 || [] === $post_ids ) {
			return [];
		}

		$in   = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$rows = $this->db->column(
			'SELECT post_id FROM ' . $this->db->table( $table ) . " WHERE user_id = %d AND post_id IN ({$in})",
			...array_merge( [ $user_id ], $post_ids )
		);

		$out = [];
		foreach ( $rows as $id ) {
			$out[ (int) $id ] = true;
		}
		return $out;
	}

	/**
	 * How long a published post survives, in days.
	 *
	 * One accessor rather than a settings read at each call site, because the number appears in
	 * the app payload, on the store admin's screen and on the public share page, and those three
	 * disagreeing is exactly the sort of thing a customer notices after they have paid.
	 */
	public function retention_days(): int {
		return max( 0, $this->settings->int( 'vip.default_expiry_days', 7 ) );
	}

	/**
	 * The one sentence every surface shows about expiry.
	 *
	 * Built here so the app, the share page and the admin screen cannot drift apart. Empty when a
	 * post has no expiry at all — saying nothing is better than saying "expires never".
	 */
	public function expiry_notice( ?string $expires_at ): string {
		$expires_at = (string) $expires_at;
		if ( '' === $expires_at ) {
			return '';
		}

		$timestamp = strtotime( $expires_at . ' UTC' );
		if ( ! $timestamp ) {
			return '';
		}

		if ( $timestamp <= time() ) {
			return __( 'This post has expired and has been removed from the server.', 'igbz-suite' );
		}

		return sprintf(
			/* translators: %s: formatted date and time the post is removed. */
			__( 'Available until %s, then it is removed from the server. Tap the save icon in the app to keep your own copy.', 'igbz-suite' ),
			wp_date( (string) get_option( 'date_format', 'Y-m-d' ) . ' ' . (string) get_option( 'time_format', 'H:i' ), $timestamp )
		);
	}

	/**
	 * @param mixed $media
	 * @return array<int,array<string,mixed>>
	 */
	private function normalise_media( mixed $media ): array {
		if ( is_string( $media ) ) {
			$decoded = json_decode( $media, true );
			$media   = is_array( $decoded ) ? $decoded : [];
		}
		if ( ! is_array( $media ) ) {
			return [];
		}

		$out = [];
		foreach ( $media as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$url = esc_url_raw( (string) ( $item['url'] ?? '' ) );
			if ( '' === $url ) {
				continue;
			}
			$type  = in_array( (string) ( $item['type'] ?? '' ), [ 'image', 'video' ], true ) ? (string) $item['type'] : 'image';
			$out[] = [
				'type'     => $type,
				'url'      => $url,
				'path'     => sanitize_text_field( (string) ( $item['path'] ?? '' ) ),
				'thumb'    => esc_url_raw( (string) ( $item['thumb'] ?? '' ) ),
				'blur'     => sanitize_text_field( (string) ( $item['blur'] ?? '' ) ),
				'width'    => (int) ( $item['width'] ?? 0 ),
				'height'   => (int) ( $item['height'] ?? 0 ),
				'duration' => (int) ( $item['duration'] ?? 0 ),
			];
		}

		return $out;
	}

	/** @param array<int,array<string,mixed>> $media */
	private function derive_kind( string $requested, array $media ): string {
		$requested = (string) $requested;
		if ( in_array( $requested, [ self::KIND_IMAGE, self::KIND_CAROUSEL, self::KIND_VIDEO, self::KIND_TEXT ], true ) ) {
			// Trust an explicit carousel/video only if the media backs it up.
			if ( self::KIND_TEXT === $requested && [] === $media ) {
				return self::KIND_TEXT;
			}
		}

		if ( [] === $media ) {
			return self::KIND_TEXT;
		}
		if ( count( $media ) > 1 ) {
			return self::KIND_CAROUSEL;
		}
		return 'video' === ( $media[0]['type'] ?? 'image' ) ? self::KIND_VIDEO : self::KIND_IMAGE;
	}

	private function sanitise_access( string $access ): string {
		return in_array( $access, [ VipAccessService::ACCESS_FREE, VipAccessService::ACCESS_MEMBERS, VipAccessService::ACCESS_PURCHASE ], true )
			? $access
			: VipAccessService::ACCESS_MEMBERS;
	}

	private function sanitise_status( string $status ): string {
		return in_array( $status, [ self::STATUS_DRAFT, self::STATUS_SCHEDULED, self::STATUS_PUBLISHED ], true )
			? $status
			: self::STATUS_DRAFT;
	}

	private function sanitise_expiry_action( string $action ): string {
		return self::EXPIRY_DELETE === $action ? self::EXPIRY_DELETE : self::EXPIRY_HIDE;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function resolve_expiry( array $data, ?string $base ): ?string {
		if ( ! empty( $data['expires_at'] ) ) {
			return $this->to_datetime( $data['expires_at'] );
		}

		$days = array_key_exists( 'expiry_days', $data )
			? (int) $data['expiry_days']
			: $this->settings->int( 'vip.default_expiry_days', 0 );

		if ( $days <= 0 ) {
			return null;
		}

		$base ??= current_time( 'mysql', true );
		return gmdate( 'Y-m-d H:i:s', strtotime( $base ) + ( $days * DAY_IN_SECONDS ) );
	}

	private function to_datetime( mixed $value ): ?string {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		$ts = strtotime( $value );
		return false === $ts ? null : gmdate( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * A short, URL-safe public id, so a share link never exposes the row id.
	 */
	private function unique_shortcode(): string {
		for ( $i = 0; $i < 12; $i++ ) {
			$code   = substr( str_replace( [ '-', '_' ], '', Crypto::token( 12 ) ), 0, 11 );
			$exists = (int) $this->db->scalar(
				'SELECT COUNT(*) FROM ' . $this->db->table( 'vip_posts' ) . ' WHERE shortcode = %s',
				$code
			);
			if ( 0 === $exists ) {
				return $code;
			}
		}

		return substr( md5( uniqid( 'vip', true ) ), 0, 11 );
	}
}
