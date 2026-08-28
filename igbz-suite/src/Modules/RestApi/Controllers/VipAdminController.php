<?php
namespace IGBZ\Suite\Modules\RestApi\Controllers;

use IGBZ\Suite\Modules\Instagram\Vip\VipAccess;
use IGBZ\Suite\Modules\Instagram\Vip\VipAccessService;
use IGBZ\Suite\Modules\Instagram\Vip\VipBillingService;
use IGBZ\Suite\Modules\Instagram\Vip\VipMessageService;
use IGBZ\Suite\Modules\Instagram\Vip\VipPostService;
use IGBZ\Suite\Modules\Instagram\Vip\VipSocialService;

defined( 'ABSPATH' ) || exit;

/**
 * The VIP dashboard API for the admin app.
 *
 * The shop owner runs this channel the way they run an Instagram page: publish, schedule, read
 * every like and comment, answer direct messages, and see who is paying. Every route here is
 * behind can_manage_tenant().
 */
final class VipAdminController extends BaseController {

	public function register_routes(): void {
		$ns   = self::NAMESPACE;
		$perm = [ $this, 'can_manage_tenant' ];

		register_rest_route(
			$ns,
			'/vip/admin/posts',
			[
				$this->route( 'GET', [ $this, 'list_posts' ], $perm ),
				$this->route( 'POST', [ $this, 'create_post' ], $perm ),
			]
		);
		register_rest_route(
			$ns,
			'/vip/admin/posts/(?P<id>[\d]+)',
			[
				$this->route( 'GET', [ $this, 'get_post' ], $perm ),
				$this->route( 'PUT, PATCH', [ $this, 'update_post' ], $perm ),
				$this->route( 'DELETE', [ $this, 'delete_post' ], $perm ),
			]
		);
		register_rest_route( $ns, '/vip/admin/posts/(?P<id>[\d]+)/publish', $this->route( 'POST', [ $this, 'publish_post' ], $perm ) );
		register_rest_route( $ns, '/vip/admin/posts/(?P<id>[\d]+)/insights', $this->route( 'GET', [ $this, 'insights' ], $perm ) );

		register_rest_route( $ns, '/vip/admin/comments', $this->route( 'GET', [ $this, 'list_comments' ], $perm ) );
		register_rest_route( $ns, '/vip/admin/comments/(?P<id>[\d]+)/reply', $this->route( 'POST', [ $this, 'reply_comment' ], $perm ) );
		register_rest_route( $ns, '/vip/admin/comments/(?P<id>[\d]+)/pin', $this->route( 'POST', [ $this, 'pin_comment' ], $perm ) );
		register_rest_route( $ns, '/vip/admin/comments/(?P<id>[\d]+)/hide', $this->route( 'POST', [ $this, 'hide_comment' ], $perm ) );
		register_rest_route( $ns, '/vip/admin/comments/(?P<id>[\d]+)', $this->route( 'DELETE', [ $this, 'delete_comment' ], $perm ) );

		register_rest_route( $ns, '/vip/admin/threads', $this->route( 'GET', [ $this, 'threads' ], $perm ) );
		register_rest_route(
			$ns,
			'/vip/admin/threads/(?P<id>[\d]+)/messages',
			[
				$this->route( 'GET', [ $this, 'thread_messages' ], $perm ),
				$this->route( 'POST', [ $this, 'reply_thread' ], $perm ),
			]
		);

		register_rest_route( $ns, '/vip/admin/members', $this->route( 'GET', [ $this, 'members' ], $perm ) );
		register_rest_route( $ns, '/vip/admin/stats', $this->route( 'GET', [ $this, 'stats' ], $perm ) );
		register_rest_route(
			$ns,
			'/vip/admin/plans',
			[
				$this->route( 'GET', [ $this, 'list_plans' ], $perm ),
				$this->route( 'POST', [ $this, 'save_plan' ], $perm ),
			]
		);
		register_rest_route( $ns, '/vip/admin/plans/(?P<id>[\d]+)', $this->route( 'PUT, PATCH, DELETE', [ $this, 'update_plan' ], $perm ) );
	}

	// ------------------------------------------------------------------ posts

	public function list_posts( \WP_REST_Request $request ): \WP_REST_Response {
		[ $page, $per_page, $offset ] = $this->page_args( $request, 20 );

		$db        = igbz()->db();
		$table     = $db->table( 'vip_posts' );
		$tenant_id = $this->scoped_tenant_id( $request );
		$status    = (string) $request->get_param( 'status' );

		$where = '(tenant_id = %d OR %d = 0) AND status <> %s';
		$bind  = [ $tenant_id, $tenant_id, VipPostService::STATUS_DELETED ];

		if ( '' !== $status ) {
			$where .= ' AND status = %s';
			$bind[] = $status;
		}

		$total = (int) $db->scalar( "SELECT COUNT(*) FROM {$table} WHERE {$where}", ...$bind );
		$rows  = $db->results(
			"SELECT * FROM {$table} WHERE {$where} ORDER BY COALESCE(published_at, publish_at, created_at) DESC, id DESC LIMIT %d OFFSET %d",
			...array_merge( $bind, [ $per_page, $offset ] )
		);

		return $this->paged( array_map( [ $this, 'present_admin_post' ], $rows ), $total, $page, $per_page );
	}

	public function get_post( \WP_REST_Request $request ): \WP_REST_Response {
		$post = $this->posts()->post( (int) $request['id'] );
		if ( ! $post ) {
			return $this->fail( 'igbz_vip_not_found', __( 'Post not found.', 'igbz-suite' ), 404 );
		}

		return $this->ok( $this->present_admin_post( $post ) );
	}

	public function create_post( \WP_REST_Request $request ): \WP_REST_Response {
		$data = $this->post_payload( $request );

		$id = $this->posts()->create( $data, $this->scoped_tenant_id( $request ), get_current_user_id() );
		if ( $id <= 0 ) {
			return $this->fail( 'igbz_vip_create_failed', __( 'Could not create the post.', 'igbz-suite' ), 500 );
		}

		return $this->ok( $this->present_admin_post( (array) $this->posts()->post( $id ) ), 201 );
	}

	public function update_post( \WP_REST_Request $request ): \WP_REST_Response {
		$id = (int) $request['id'];
		if ( ! $this->posts()->post( $id ) ) {
			return $this->fail( 'igbz_vip_not_found', __( 'Post not found.', 'igbz-suite' ), 404 );
		}

		$this->posts()->update( $id, $this->post_payload( $request, true ) );

		return $this->ok( $this->present_admin_post( (array) $this->posts()->post( $id ) ) );
	}

	public function publish_post( \WP_REST_Request $request ): \WP_REST_Response {
		$id = (int) $request['id'];
		if ( ! $this->posts()->publish( $id ) ) {
			return $this->fail( 'igbz_vip_publish_failed', __( 'That post could not be published.', 'igbz-suite' ), 400 );
		}

		return $this->ok( $this->present_admin_post( (array) $this->posts()->post( $id ) ) );
	}

	public function delete_post( \WP_REST_Request $request ): \WP_REST_Response {
		$purge = null === $request->get_param( 'purge_media' ) || (bool) $request->get_param( 'purge_media' );

		if ( ! $this->posts()->delete( (int) $request['id'], $purge ) ) {
			return $this->fail( 'igbz_vip_not_found', __( 'Post not found.', 'igbz-suite' ), 404 );
		}

		return $this->ok( [ 'ok' => true ] );
	}

	/**
	 * Per-post feedback: who liked it, who watched it, how long for.
	 */
	public function insights( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request['id'];
		$post    = $this->posts()->post( $post_id );
		if ( ! $post ) {
			return $this->fail( 'igbz_vip_not_found', __( 'Post not found.', 'igbz-suite' ), 404 );
		}

		$db = igbz()->db();

		$likes = $db->results(
			'SELECT l.user_id, l.created_at FROM ' . $db->table( 'vip_post_likes' ) . ' l
			 WHERE l.post_id = %d ORDER BY l.id DESC LIMIT 100',
			$post_id
		);

		$views = $db->results(
			'SELECT user_id, seconds_watched, view_count, first_viewed_at, viewed_at
			 FROM ' . $db->table( 'vip_post_views' ) . '
			 WHERE post_id = %d ORDER BY viewed_at DESC LIMIT 100',
			$post_id
		);

		$purchases = $db->row(
			'SELECT COUNT(*) AS n, COALESCE(SUM(price_paid),0) AS total
			 FROM ' . $db->table( 'vip_entitlements' ) . '
			 WHERE post_id = %d AND revoked_at IS NULL',
			$post_id
		);

		return $this->ok(
			[
				'post_id'        => $post_id,
				'likes_count'    => (int) $post['likes_count'],
				'comments_count' => (int) $post['comments_count'],
				'views_count'    => (int) $post['views_count'],
				'purchases'      => [
					'count'   => (int) ( $purchases['n'] ?? 0 ),
					'revenue' => (float) ( $purchases['total'] ?? 0 ),
				],
				'likes'          => array_map( [ $this, 'with_user' ], $likes ),
				'views'          => array_map( [ $this, 'with_user' ], $views ),
			]
		);
	}

	// --------------------------------------------------------------- comments

	public function list_comments( \WP_REST_Request $request ): \WP_REST_Response {
		[ $page, $per_page, $offset ] = $this->page_args( $request, 30 );

		$db        = igbz()->db();
		$table     = $db->table( 'vip_post_comments' );
		$tenant_id = $this->scoped_tenant_id( $request );
		$post_id   = (int) $request->get_param( 'post_id' );

		$where = '(tenant_id = %d OR %d = 0) AND status <> %s';
		$bind  = [ $tenant_id, $tenant_id, VipSocialService::STATUS_DELETED ];

		if ( $post_id > 0 ) {
			$where .= ' AND post_id = %d';
			$bind[] = $post_id;
		}
		// The default view is the unanswered stream: comments from members, newest first, which is
		// what the shop owner actually opens the screen to deal with.
		if ( ! $request->get_param( 'include_admin' ) ) {
			$where .= ' AND is_admin = 0';
		}

		$total = (int) $db->scalar( "SELECT COUNT(*) FROM {$table} WHERE {$where}", ...$bind );
		$rows  = $db->results(
			"SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
			...array_merge( $bind, [ $per_page, $offset ] )
		);

		$items = array_map(
			function ( array $row ): array {
				$out            = $this->social()->present_comment( $row );
				$out['status']  = (string) $row['status'];
				$out['post']    = $this->post_stub( (int) $row['post_id'] );
				return $out;
			},
			$rows
		);

		return $this->paged( $items, $total, $page, $per_page );
	}

	public function reply_comment( \WP_REST_Request $request ): \WP_REST_Response {
		$db      = igbz()->db();
		$comment = $db->row(
			'SELECT post_id FROM ' . $db->table( 'vip_post_comments' ) . ' WHERE id = %d AND tenant_id = %d',
			(int) $request['id'],
			$this->scoped_tenant_id( $request )
		);
		if ( ! $comment ) {
			return $this->fail( 'igbz_vip_not_found', __( 'Comment not found.', 'igbz-suite' ), 404 );
		}

		try {
			$id = $this->social()->add_comment(
				(int) $comment['post_id'],
				get_current_user_id(),
				(string) $request->get_param( 'body' ),
				(int) $request['id'],
				true
			);
		} catch ( \RuntimeException $e ) {
			return $this->fail( 'igbz_vip_comment_failed', $e->getMessage(), 400 );
		}

		return $this->ok( [ 'ok' => true, 'comment_id' => $id ], 201 );
	}

	public function pin_comment( \WP_REST_Request $request ): \WP_REST_Response {
		$pinned = null === $request->get_param( 'pinned' ) || (bool) $request->get_param( 'pinned' );
		$this->social()->pin_comment( (int) $request['id'], $pinned );

		return $this->ok( [ 'ok' => true, 'pinned' => $pinned ] );
	}

	public function hide_comment( \WP_REST_Request $request ): \WP_REST_Response {
		$hidden = null === $request->get_param( 'hidden' ) || (bool) $request->get_param( 'hidden' );
		$status = $hidden ? VipSocialService::STATUS_HIDDEN : VipSocialService::STATUS_VISIBLE;

		if ( ! $this->social()->set_comment_status( (int) $request['id'], $status ) ) {
			return $this->fail( 'igbz_vip_not_found', __( 'Comment not found.', 'igbz-suite' ), 404 );
		}

		return $this->ok( [ 'ok' => true, 'status' => $status ] );
	}

	public function delete_comment( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->social()->set_comment_status( (int) $request['id'], VipSocialService::STATUS_DELETED ) ) {
			return $this->fail( 'igbz_vip_not_found', __( 'Comment not found.', 'igbz-suite' ), 404 );
		}

		return $this->ok( [ 'ok' => true ] );
	}

	// ---------------------------------------------------------------- inbox

	public function threads( \WP_REST_Request $request ): \WP_REST_Response {
		[ $page, $per_page ] = $this->page_args( $request, 20 );

		$result = $this->messages()->inbox(
			[
				'tenant_id' => $this->scoped_tenant_id( $request ),
				'page'      => $page,
				'per_page'  => $per_page,
				'unread'    => (bool) $request->get_param( 'unread' ),
			]
		);

		$response = $this->paged( $result['items'], $result['total'], $page, $per_page );
		$response->header( 'X-IGBZ-Unread', (string) $this->messages()->unread_count_for_admin( $this->scoped_tenant_id( $request ) ) );

		return $response;
	}

	public function thread_messages( \WP_REST_Request $request ): \WP_REST_Response {
		$thread_id = (int) $request['id'];
		if ( ! $this->messages()->thread( $thread_id ) ) {
			return $this->fail( 'igbz_vip_not_found', __( 'Conversation not found.', 'igbz-suite' ), 404 );
		}

		[ $page, $per_page ] = $this->page_args( $request, 30 );
		$result              = $this->messages()->messages( $thread_id, $page, $per_page );

		$this->messages()->mark_read( $thread_id, VipMessageService::SENDER_ADMIN );

		return $this->paged( $result['items'], $result['total'], $page, $per_page );
	}

	public function reply_thread( \WP_REST_Request $request ): \WP_REST_Response {
		$thread_id = (int) $request['id'];
		if ( ! $this->messages()->thread( $thread_id ) ) {
			return $this->fail( 'igbz_vip_not_found', __( 'Conversation not found.', 'igbz-suite' ), 404 );
		}

		try {
			$id = $this->messages()->send(
				$thread_id,
				get_current_user_id(),
				(string) $request->get_param( 'body' ),
				VipMessageService::SENDER_ADMIN,
				(int) $request->get_param( 'post_id' )
			);
		} catch ( \RuntimeException $e ) {
			return $this->fail( 'igbz_vip_message_failed', $e->getMessage(), 400 );
		}

		return $this->ok( [ 'ok' => true, 'message_id' => $id ], 201 );
	}

	// -------------------------------------------------------- members & money

	public function members( \WP_REST_Request $request ): \WP_REST_Response {
		[ $page, $per_page, $offset ] = $this->page_args( $request, 30 );

		$db        = igbz()->db();
		$table     = $db->table( 'vip_memberships' );
		$tenant_id = $this->scoped_tenant_id( $request );
		$status    = (string) ( $request->get_param( 'status' ) ?: VipAccessService::STATUS_ACTIVE );

		$where = '(tenant_id = %d OR %d = 0) AND status = %s';
		$bind  = [ $tenant_id, $tenant_id, $status ];

		$total = (int) $db->scalar( "SELECT COUNT(*) FROM {$table} WHERE {$where}", ...$bind );
		$rows  = $db->results(
			"SELECT * FROM {$table} WHERE {$where} ORDER BY ends_at DESC, id DESC LIMIT %d OFFSET %d",
			...array_merge( $bind, [ $per_page, $offset ] )
		);

		$items = array_map(
			static function ( array $row ): array {
				$user = get_userdata( (int) $row['user_id'] );
				return [
					'id'         => (int) $row['id'],
					'user_id'    => (int) $row['user_id'],
					'user_name'  => $user ? $user->display_name : __( 'Deleted user', 'igbz-suite' ),
					'email'      => $user ? $user->user_email : '',
					'avatar'     => get_avatar_url( (int) $row['user_id'], [ 'size' => 96 ] ),
					'plan_id'    => (int) $row['plan_id'],
					'status'     => (string) $row['status'],
					'starts_at'  => $row['starts_at'],
					'ends_at'    => $row['ends_at'],
					'auto_renew' => (bool) (int) $row['auto_renew'],
					'cancelled'  => null !== $row['cancelled_at'],
					'price_paid' => (float) $row['price_paid'],
				];
			},
			$rows
		);

		return $this->paged( $items, $total, $page, $per_page );
	}

	public function stats( \WP_REST_Request $request ): \WP_REST_Response {
		$tenant_id = $this->scoped_tenant_id( $request );
		$days      = max( 1, (int) ( $request->get_param( 'days' ) ?: 30 ) );

		$db    = igbz()->db();
		$posts = $db->table( 'vip_posts' );

		$stats            = $this->billing()->stats( $tenant_id, $days );
		$stats['posts']   = [
			'published' => (int) $db->scalar(
				"SELECT COUNT(*) FROM {$posts} WHERE status = %s AND (tenant_id = %d OR %d = 0)",
				VipPostService::STATUS_PUBLISHED,
				$tenant_id,
				$tenant_id
			),
			'scheduled' => (int) $db->scalar(
				"SELECT COUNT(*) FROM {$posts} WHERE status = %s AND (tenant_id = %d OR %d = 0)",
				VipPostService::STATUS_SCHEDULED,
				$tenant_id,
				$tenant_id
			),
			'expired'   => (int) $db->scalar(
				"SELECT COUNT(*) FROM {$posts} WHERE status = %s AND (tenant_id = %d OR %d = 0)",
				VipPostService::STATUS_EXPIRED,
				$tenant_id,
				$tenant_id
			),
		];
		$stats['unread_messages'] = $this->messages()->unread_count_for_admin( $tenant_id );

		return $this->ok( $stats );
	}

	public function list_plans( \WP_REST_Request $request ): \WP_REST_Response {
		$db        = igbz()->db();
		$tenant_id = $this->scoped_tenant_id( $request );

		$rows = $db->results(
			'SELECT * FROM ' . $db->table( 'vip_plans' ) . '
			 WHERE tenant_id = %d OR tenant_id = 0
			 ORDER BY sort_order ASC, price ASC',
			$tenant_id
		);

		return $this->ok( [ 'items' => $rows ] );
	}

	public function save_plan( \WP_REST_Request $request ): \WP_REST_Response {
		$db  = igbz()->db();
		$now = current_time( 'mysql', true );

		$slug = sanitize_title( (string) ( $request->get_param( 'slug' ) ?: $request->get_param( 'name' ) ) );
		if ( '' === $slug ) {
			return $this->fail( 'igbz_vip_plan_invalid', __( 'The plan needs a name.', 'igbz-suite' ), 400 );
		}

		$id = $db->insert(
			'vip_plans',
			[
				'tenant_id'     => $this->scoped_tenant_id( $request ),
				'slug'          => $slug,
				'name'          => sanitize_text_field( (string) $request->get_param( 'name' ) ),
				'description'   => sanitize_textarea_field( (string) $request->get_param( 'description' ) ),
				'price'         => max( 0.0, (float) $request->get_param( 'price' ) ),
				'currency'      => igbz()->settings()->string( 'general.default_currency', 'IRT' ),
				'duration_days' => max( 0, (int) ( $request->get_param( 'duration_days' ) ?: 30 ) ),
				'is_active'     => (int) (bool) $request->get_param( 'is_active' ),
				'sort_order'    => (int) $request->get_param( 'sort_order' ),
				'created_at'    => $now,
				'updated_at'    => $now,
			]
		);

		if ( $id <= 0 ) {
			return $this->fail( 'igbz_vip_plan_failed', __( 'Could not save the plan. The slug may already be in use.', 'igbz-suite' ), 400 );
		}

		return $this->ok( [ 'ok' => true, 'plan_id' => $id ], 201 );
	}

	public function update_plan( \WP_REST_Request $request ): \WP_REST_Response {
		$db = igbz()->db();
		$id = (int) $request['id'];

		if ( 'DELETE' === $request->get_method() ) {
			// Soft delete: an active membership points at this plan, and removing the row would
			// leave the renewal with no duration to renew for.
			$db->update( 'vip_plans', [ 'is_active' => 0, 'updated_at' => current_time( 'mysql', true ) ], [ 'id' => $id ] );
			return $this->ok( [ 'ok' => true ] );
		}

		$fields = [ 'updated_at' => current_time( 'mysql', true ) ];
		foreach ( [ 'name', 'description' ] as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$fields[ $key ] = sanitize_text_field( (string) $request->get_param( $key ) );
			}
		}
		if ( null !== $request->get_param( 'price' ) ) {
			$fields['price'] = max( 0.0, (float) $request->get_param( 'price' ) );
		}
		if ( null !== $request->get_param( 'duration_days' ) ) {
			$fields['duration_days'] = max( 0, (int) $request->get_param( 'duration_days' ) );
		}
		if ( null !== $request->get_param( 'is_active' ) ) {
			$fields['is_active'] = (int) (bool) $request->get_param( 'is_active' );
		}
		if ( null !== $request->get_param( 'sort_order' ) ) {
			$fields['sort_order'] = (int) $request->get_param( 'sort_order' );
		}

		$db->update( 'vip_plans', $fields, [ 'id' => $id ] );

		return $this->ok( [ 'ok' => true ] );
	}

	// -------------------------------------------------------------- internals

	/**
	 * @return array<string,mixed>
	 */
	private function post_payload( \WP_REST_Request $request, bool $partial = false ): array {
		$keys = [
			'caption',
			'media',
			'kind',
			'access',
			'price',
			'status',
			'product_id',
			'teaser_content_id',
			'account_id',
			'comments_enabled',
			'publish_at',
			'expires_at',
			'expiry_days',
			'expiry_action',
		];

		$data = [];
		foreach ( $keys as $key ) {
			$value = $request->get_param( $key );
			// On a partial update only the keys the client actually sent may be touched; treating a
			// missing key as an empty value would wipe a caption every time the app toggles a price.
			if ( null === $value && $partial ) {
				continue;
			}
			if ( null !== $value ) {
				$data[ $key ] = $value;
			}
		}

		return $data;
	}

	/**
	 * @param array<string,mixed> $post
	 * @return array<string,mixed>
	 */
	private function present_admin_post( array $post ): array {
		// The admin always sees the real media, so present() is called with an author grant.
		$out = $this->posts()->present( $post, VipAccess::allow( VipAccess::ALLOW_AUTHOR ), false );

		$out['status']        = (string) $post['status'];
		$out['access_mode']   = (string) $post['access'];
		$out['price']         = (float) $post['price'];
		$out['publish_at']    = $post['publish_at'];
		$out['expiry_action'] = (string) $post['expiry_action'];
		$out['expired_at']    = $post['expired_at'];
		$out['author_id']     = (int) $post['author_id'];
		$out['share_url']     = home_url( '/' . trim( igbz()->settings()->string( 'vip.landing_slug', 'vip' ), '/' ) . '/p/' . rawurlencode( (string) $post['shortcode'] ) );

		return $out;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function post_stub( int $post_id ): array {
		$db  = igbz()->db();
		$row = $db->row(
			'SELECT id, shortcode, caption, kind FROM ' . $db->table( 'vip_posts' ) . ' WHERE id = %d AND tenant_id = %d',
			$post_id,
			$this->scoped_tenant_id()
		);

		if ( ! $row ) {
			return [ 'id' => $post_id ];
		}

		return [
			'id'        => (int) $row['id'],
			'shortcode' => (string) $row['shortcode'],
			'excerpt'   => mb_substr( wp_strip_all_tags( (string) $row['caption'] ), 0, 80 ),
			'kind'      => (string) $row['kind'],
		];
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function with_user( array $row ): array {
		$user_id     = (int) ( $row['user_id'] ?? 0 );
		$user        = $user_id > 0 ? get_userdata( $user_id ) : null;
		$row['name'] = $user ? $user->display_name : __( 'Deleted user', 'igbz-suite' );
		$row['avatar'] = $user_id > 0 ? get_avatar_url( $user_id, [ 'size' => 96 ] ) : '';

		return $row;
	}

	private function posts(): VipPostService {
		return igbz()->get( 'vip.posts' );
	}

	private function social(): VipSocialService {
		return igbz()->get( 'vip.social' );
	}

	private function billing(): VipBillingService {
		return igbz()->get( 'vip.billing' );
	}

	private function messages(): VipMessageService {
		return igbz()->get( 'vip.messages' );
	}
}
