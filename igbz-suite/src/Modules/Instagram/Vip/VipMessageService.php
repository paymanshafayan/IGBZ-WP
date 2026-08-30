<?php
namespace IGBZ\Suite\Modules\Instagram\Vip;

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * In-app direct messages between a member and the shop.
 *
 * This is the "message the admin about this post" button from the Instagram post UI. Because the
 * transport is our own app rather than Instagram, none of Meta's messaging rules apply here — in
 * particular there is no 24-hour window, so the shop can answer a question three days later or
 * open a conversation itself.
 */
class VipMessageService { // not final: phase-59 tests subclass the environment seam

	public const STATUS_OPEN     = 'open';
	public const STATUS_CLOSED   = 'closed';
	public const STATUS_ARCHIVED = 'archived';

	public const SENDER_USER  = 'user';
	public const SENDER_ADMIN = 'admin';

	public function __construct( private Db $db, private Settings $settings ) {}

	/**
	 * Find or create the member's thread.
	 *
	 * One thread per member, like an Instagram inbox conversation, rather than one per post. A
	 * shopper who asks about three posts should not have to remember which of three threads holds
	 * the answer; the post is attached to the individual message instead.
	 */
	public function thread_for_user( int $user_id, int $tenant_id = 0, string $subject = '' ): int {
		$existing = $this->db->row(
			'SELECT id FROM ' . $this->db->table( 'vip_threads' ) . '
			 WHERE user_id = %d AND tenant_id = %d AND status <> %s
			 ORDER BY id DESC LIMIT 1',
			$user_id,
			$tenant_id,
			self::STATUS_ARCHIVED
		);

		if ( $existing ) {
			return (int) $existing['id'];
		}

		$now = current_time( 'mysql', true );

		return $this->db->insert(
			'vip_threads',
			[
				'tenant_id'  => $tenant_id,
				'user_id'    => $user_id,
				'subject'    => mb_substr( sanitize_text_field( $subject ), 0, 191 ),
				'status'     => self::STATUS_OPEN,
				'created_at' => $now,
				'updated_at' => $now,
			]
		);
	}

	/**
	 * @throws \RuntimeException
	 */
	public function send( int $thread_id, int $sender_id, string $body, string $sender_type = self::SENDER_USER, int $post_id = 0 ): int {
		if ( ! $this->settings->bool( 'vip.messages_enabled', true ) ) {
			throw new \RuntimeException( __( 'Messaging is turned off.', 'igbz-suite' ) );
		}

		$thread = $this->thread( $thread_id );
		if ( ! $thread ) {
			throw new \RuntimeException( __( 'Conversation not found.', 'igbz-suite' ) );
		}

		$body = trim( wp_strip_all_tags( $body ) );
		if ( '' === $body ) {
			throw new \RuntimeException( __( 'Write something first.', 'igbz-suite' ) );
		}
		$body = mb_substr( $body, 0, 4000 );

		$is_admin = self::SENDER_ADMIN === $sender_type;
		$now      = current_time( 'mysql', true );

		$id = $this->db->insert(
			'vip_messages',
			[
				// Copied down from the thread rather than passed in: the caller that knows the
				// tenant is the one that opened the conversation, and a message whose tenant does
				// not match its thread would be invisible in one shop's inbox and visible in
				// another's.
				'tenant_id'   => (int) $thread['tenant_id'],
				'thread_id'   => $thread_id,
				'sender_type' => $is_admin ? self::SENDER_ADMIN : self::SENDER_USER,
				'sender_id'   => $sender_id,
				'post_id'     => $post_id,
				'body'        => $body,
				'read_at'     => null,
				'created_at'  => $now,
			]
		);

		if ( $id <= 0 ) {
			throw new \RuntimeException( __( 'Could not send the message.', 'igbz-suite' ) );
		}

		// The unread counter belongs to the side that did NOT send. Bumping both is the classic
		// inbox bug where the admin's own reply marks the thread unread for the admin.
		$this->db->query(
			'UPDATE ' . $this->db->table( 'vip_threads' ) . '
			 SET last_message_at = %s,
			     last_message_preview = %s,
			     unread_admin = unread_admin + %d,
			     unread_user = unread_user + %d,
			     status = %s,
			     updated_at = %s
			 WHERE id = %d',
			$now,
			mb_substr( $body, 0, 255 ),
			$is_admin ? 0 : 1,
			$is_admin ? 1 : 0,
			// Any new message re-opens a closed thread: a reply to a resolved question is still a
			// question the shop needs to see.
			self::STATUS_OPEN,
			$now,
			$thread_id
		);

		do_action( 'igbz_vip_message_sent', $id, $thread_id, $sender_id, $is_admin );

		return $id;
	}

	/** Convenience path for the app's "message the admin" button on a post. */
	public function send_from_user( int $user_id, string $body, int $post_id = 0, int $tenant_id = 0 ): int {
		$thread_id = $this->thread_for_user( $user_id, $tenant_id );
		return $this->send( $thread_id, $user_id, $body, self::SENDER_USER, $post_id );
	}

	/** @return array<string,mixed>|null */
	public function thread( int $thread_id ): ?array {
		return $this->db->row( 'SELECT * FROM ' . $this->db->table( 'vip_threads' ) . ' WHERE id = %d', $thread_id );
	}

	/**
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	public function messages( int $thread_id, int $page = 1, int $per_page = 30 ): array {
		$page     = max( 1, $page );
		$per_page = min( 100, max( 1, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;
		$table    = $this->db->table( 'vip_messages' );

		$total = (int) $this->db->scalar( "SELECT COUNT(*) FROM {$table} WHERE thread_id = %d", $thread_id );

		// Newest first for paging, then flipped so the app renders oldest-to-newest without having
		// to know how the query was ordered.
		$rows = $this->db->results(
			"SELECT * FROM {$table} WHERE thread_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
			$thread_id,
			$per_page,
			$offset
		);

		$items = array_map(
			static fn( array $r ): array => [
				'id'          => (int) $r['id'],
				'thread_id'   => (int) $r['thread_id'],
				'sender_type' => (string) $r['sender_type'],
				'sender_id'   => (int) $r['sender_id'],
				'post_id'     => (int) $r['post_id'],
				'body'        => (string) $r['body'],
				'read_at'     => $r['read_at'],
				'created_at'  => $r['created_at'],
			],
			array_reverse( $rows )
		);

		return [ 'items' => $items, 'total' => $total ];
	}

	/**
	 * Mark a thread read for one side.
	 */
	public function mark_read( int $thread_id, string $reader = self::SENDER_USER ): void {
		$now   = current_time( 'mysql', true );
		$other = self::SENDER_ADMIN === $reader ? self::SENDER_USER : self::SENDER_ADMIN;

		$this->db->query(
			'UPDATE ' . $this->db->table( 'vip_messages' ) . '
			 SET read_at = %s
			 WHERE thread_id = %d AND sender_type = %s AND read_at IS NULL',
			$now,
			$thread_id,
			$other
		);

		$this->db->update(
			'vip_threads',
			self::SENDER_ADMIN === $reader ? [ 'unread_admin' => 0 ] : [ 'unread_user' => 0 ],
			[ 'id' => $thread_id ]
		);
	}

	/**
	 * The member's own thread list.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function threads_for_user( int $user_id ): array {
		$rows = $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'vip_threads' ) . '
			 WHERE user_id = %d AND status <> %s
			 ORDER BY last_message_at DESC, id DESC
			 LIMIT 50',
			$user_id,
			self::STATUS_ARCHIVED
		);

		return array_map( [ $this, 'present_thread' ], $rows );
	}

	/**
	 * The admin inbox.
	 *
	 * @param array<string,mixed> $args
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	public function inbox( array $args = [] ): array {
		$tenant_id  = (int) ( $args['tenant_id'] ?? 0 );
		$page       = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page   = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset     = ( $page - 1 ) * $per_page;
		$unread_only = ! empty( $args['unread'] );
		$table      = $this->db->table( 'vip_threads' );

		$where = 'status <> %s AND (tenant_id = %d OR %d = 0)';
		$bind  = [ self::STATUS_ARCHIVED, $tenant_id, $tenant_id ];

		if ( $unread_only ) {
			$where .= ' AND unread_admin > 0';
		}

		$total = (int) $this->db->scalar( "SELECT COUNT(*) FROM {$table} WHERE {$where}", ...$bind );

		$rows = $this->db->results(
			"SELECT * FROM {$table} WHERE {$where}
			 ORDER BY unread_admin DESC, last_message_at DESC, id DESC
			 LIMIT %d OFFSET %d",
			...array_merge( $bind, [ $per_page, $offset ] )
		);

		return [
			'items' => array_map( [ $this, 'present_thread' ], $rows ),
			'total' => $total,
		];
	}

	public function set_status( int $thread_id, string $status ): bool {
		if ( ! in_array( $status, [ self::STATUS_OPEN, self::STATUS_CLOSED, self::STATUS_ARCHIVED ], true ) ) {
			return false;
		}

		return $this->db->update(
			'vip_threads',
			[
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			],
			[ 'id' => $thread_id ]
		) > 0;
	}

	public function unread_count_for_admin( int $tenant_id = 0 ): int {
		return (int) $this->db->scalar(
			'SELECT COALESCE(SUM(unread_admin),0) FROM ' . $this->db->table( 'vip_threads' ) . '
			 WHERE status <> %s AND (tenant_id = %d OR %d = 0)',
			self::STATUS_ARCHIVED,
			$tenant_id,
			$tenant_id
		);
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function present_thread( array $row ): array {
		$user_id = (int) $row['user_id'];
		$user    = $user_id > 0 ? get_userdata( $user_id ) : null;

		return [
			'id'           => (int) $row['id'],
			'user_id'      => $user_id,
			'user_name'    => $user ? $user->display_name : __( 'Deleted user', 'igbz-suite' ),
			'avatar'       => $user_id > 0 ? get_avatar_url( $user_id, [ 'size' => 96 ] ) : '',
			'subject'      => (string) $row['subject'],
			'status'       => (string) $row['status'],
			'preview'      => (string) $row['last_message_preview'],
			'last_at'      => $row['last_message_at'],
			'unread_admin' => (int) $row['unread_admin'],
			'unread_user'  => (int) $row['unread_user'],
		];
	}
}
