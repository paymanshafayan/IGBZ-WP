<?php
namespace IGBZ\Suite\Modules\RestApi\Controllers;

use IGBZ\Suite\Modules\Instagram\Vip\VipAccessService;
use IGBZ\Suite\Modules\Instagram\Vip\VipBillingService;
use IGBZ\Suite\Modules\Instagram\Vip\VipMessageService;
use IGBZ\Suite\Modules\Instagram\Vip\VipPostService;
use IGBZ\Suite\Modules\Instagram\Vip\VipSocialService;

defined( 'ABSPATH' ) || exit;

/**
 * The customer-facing VIP API — everything the mobile app calls.
 *
 * The shape mirrors an Instagram feed on purpose: a locked post is still returned with its caption
 * and counts, carrying an `access` block that tells the app which screen to show (sign in, buy the
 * membership, buy this post). Returning a 403 and nothing else would leave the app with no way to
 * sell anything.
 */
final class VipController extends BaseController {

	public function register_routes(): void {
		$ns = self::NAMESPACE;

		register_rest_route( $ns, '/vip/feed', $this->route( 'GET', [ $this, 'feed' ] ) );
		register_rest_route( $ns, '/vip/plans', $this->route( 'GET', [ $this, 'plans' ] ) );
		register_rest_route( $ns, '/vip/membership', $this->route( 'GET', [ $this, 'membership' ], [ $this, 'is_logged_in' ] ) );
		register_rest_route( $ns, '/vip/subscribe', $this->route( 'POST', [ $this, 'subscribe' ], [ $this, 'is_logged_in' ] ) );
		register_rest_route( $ns, '/vip/tip', $this->route( 'POST', [ $this, 'tip' ] ) );

		register_rest_route( $ns, '/vip/posts/(?P<id>[\d]+)', $this->route( 'GET', [ $this, 'post' ] ) );
		register_rest_route( $ns, '/vip/posts/(?P<id>[\d]+)/like', $this->route( 'POST', [ $this, 'like' ], [ $this, 'is_logged_in' ] ) );
		register_rest_route( $ns, '/vip/posts/(?P<id>[\d]+)/view', $this->route( 'POST', [ $this, 'view' ], [ $this, 'is_logged_in' ] ) );
		register_rest_route( $ns, '/vip/posts/(?P<id>[\d]+)/purchase', $this->route( 'POST', [ $this, 'purchase' ], [ $this, 'is_logged_in' ] ) );
		register_rest_route( $ns, '/vip/posts/(?P<id>[\d]+)/media', $this->route( 'GET', [ $this, 'media' ], [ $this, 'is_logged_in' ] ) );
		register_rest_route( $ns, '/vip/posts/(?P<id>[\d]+)/save', $this->route( 'POST', [ $this, 'save' ], [ $this, 'is_logged_in' ] ) );
		register_rest_route( $ns, '/vip/posts/(?P<id>[\d]+)/offline', $this->route( 'GET', [ $this, 'offline' ], [ $this, 'is_logged_in' ] ) );
		register_rest_route( $ns, '/vip/saved', $this->route( 'GET', [ $this, 'saved' ], [ $this, 'is_logged_in' ] ) );

		register_rest_route(
			$ns,
			'/vip/posts/(?P<id>[\d]+)/comments',
			[
				$this->route( 'GET', [ $this, 'list_comments' ] ),
				$this->route( 'POST', [ $this, 'add_comment' ], [ $this, 'is_logged_in' ] ),
			]
		);

		register_rest_route( $ns, '/vip/threads', $this->route( 'GET', [ $this, 'threads' ], [ $this, 'is_logged_in' ] ) );
		register_rest_route(
			$ns,
			'/vip/threads/(?P<id>[\d]+)/messages',
			[
				$this->route( 'GET', [ $this, 'thread_messages' ], [ $this, 'is_logged_in' ] ),
				$this->route( 'POST', [ $this, 'send_message' ], [ $this, 'is_logged_in' ] ),
			]
		);
		register_rest_route( $ns, '/vip/messages', $this->route( 'POST', [ $this, 'message_admin' ], [ $this, 'is_logged_in' ] ) );
	}

	// ------------------------------------------------------------------ feed

	public function feed( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->enabled() ) {
			return $this->fail( 'igbz_vip_disabled', __( 'The VIP channel is not enabled.', 'igbz-suite' ), 404 );
		}

		[ $page, $per_page ] = $this->page_args( $request, igbz()->settings()->int( 'vip.feed_page_size', 12 ) );

		$result = $this->posts()->feed(
			get_current_user_id(),
			[
				'tenant_id' => (int) $request->get_param( 'tenant_id' ),
				'page'      => $page,
				'per_page'  => $per_page,
			]
		);

		return $this->paged( $result['items'], $result['total'], $page, $per_page );
	}

	public function post( \WP_REST_Request $request ): \WP_REST_Response {
		$post = $this->posts()->post( (int) $request['id'] );
		if ( ! $post ) {
			return $this->fail( 'igbz_vip_not_found', __( 'Post not found.', 'igbz-suite' ), 404 );
		}

		$user_id = get_current_user_id();
		$access  = $this->access()->check_row( $user_id, $post );

		// A deleted or purged post is a 404 even for a member: there is nothing left to show, and
		// pretending otherwise gets the app stuck on a spinner.
		if ( ! $access->allowed && in_array( $access->reason, [ \IGBZ\Suite\Modules\Instagram\Vip\VipAccess::DENY_MISSING, \IGBZ\Suite\Modules\Instagram\Vip\VipAccess::DENY_UNPUBLISHED ], true ) ) {
			return $this->fail( 'igbz_vip_not_found', __( 'Post not found.', 'igbz-suite' ), 404 );
		}

		$payload = $this->posts()->present( $post, $access, $this->social()->has_liked( (int) $post['id'], $user_id ) );

		if ( $access->allowed ) {
			$payload['views_count'] = $this->social()->record_view( (int) $post['id'], $user_id );
		}

		$payload['share_url'] = $this->share_url( (string) $post['shortcode'] );

		return $this->ok( $payload );
	}

	/**
	 * Fresh signed media links for a post the caller already owns.
	 *
	 * The app calls this when a link minted for the feed has aged out mid-scroll, rather than
	 * reloading the whole feed.
	 */
	public function media( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request['id'];
		$post    = $this->posts()->post( $post_id );
		if ( ! $post ) {
			return $this->fail( 'igbz_vip_not_found', __( 'Post not found.', 'igbz-suite' ), 404 );
		}

		$user_id = get_current_user_id();
		$access  = $this->access()->check_row( $user_id, $post );
		if ( ! $access->allowed ) {
			return $this->fail( 'igbz_vip_locked', __( 'You do not have access to this post.', 'igbz-suite' ), 403 );
		}

		$presented = $this->posts()->present( $post, $access, false );

		return $this->ok(
			[
				'post_id'    => $post_id,
				'media'      => $presented['media'],
				'expires_in' => igbz()->settings()->int( 'vip.media_link_ttl', 900 ),
			]
		);
	}

	// ----------------------------------------------------------------- social

	/**
	 * Save (or unsave) a post.
	 *
	 * The bookmark half of the promise printed on the purchase page. The app is expected to follow
	 * a save with a call to /offline: a bookmark alone does not survive the weekly purge, and a
	 * customer who tapped save and lost the post anyway is a customer who was misled.
	 */
	public function save( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$result = $this->social()->toggle_save( (int) $request['id'], get_current_user_id() );
		} catch ( \RuntimeException $e ) {
			return 'igbz_vip_not_found' === $e->getMessage()
				? $this->fail( 'igbz_vip_not_found', __( 'Post not found.', 'igbz-suite' ), 404 )
				: $this->fail( 'igbz_vip_locked', __( 'You do not have access to this post.', 'igbz-suite' ), 403 );
		}

		return $this->ok( $result );
	}

	/**
	 * The posts this member has saved.
	 *
	 * A saved post whose media has already been purged is still returned, locked and with its
	 * expiry notice, so the app can say "this one is gone from the server — your copy is the only
	 * one left" instead of quietly dropping it from the list.
	 */
	public function saved( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id             = get_current_user_id();
		[ $page, $per_page ] = $this->page_args( $request, igbz()->settings()->int( 'vip.feed_page_size', 12 ) );

		$ids   = $this->social()->saved_post_ids( $user_id, $per_page, ( $page - 1 ) * $per_page );
		$items = [];

		foreach ( $ids as $id ) {
			$post = $this->posts()->post( $id );
			if ( ! $post ) {
				continue;
			}
			$items[] = $this->posts()->present(
				$post,
				$this->access()->check_row( $user_id, $post ),
				$this->social()->has_liked( $id, $user_id ),
				true
			);
		}

		return $this->paged( $items, $this->social()->saved_count( $user_id ), $page, $per_page );
	}

	/**
	 * Hand the member the bytes of a post they own, so the app can keep a copy.
	 *
	 * This is the one place the VIP channel deliberately lets content leave the server for good,
	 * and it is allowed because the post is going to be deleted anyway: the alternative is selling
	 * something that evaporates in a week with no way to keep it. The links are longer-lived than
	 * feed links (a download is not a scroll) but they are still signed, still bound to this
	 * viewer, and still refused once the post has expired — after that there is nothing to copy.
	 *
	 * The heavy-security tier is untouched: LMS video has no offline path and must not gain one.
	 */
	public function offline( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request['id'];
		$post    = $this->posts()->post( $post_id );
		if ( ! $post ) {
			return $this->fail( 'igbz_vip_not_found', __( 'Post not found.', 'igbz-suite' ), 404 );
		}

		$user_id = get_current_user_id();
		$access  = $this->access()->check_row( $user_id, $post );
		if ( ! $access->allowed ) {
			return $this->fail( 'igbz_vip_locked', __( 'You do not have access to this post.', 'igbz-suite' ), 403 );
		}

		$ttl   = igbz()->settings()->int( 'vip.offline_link_ttl', 3600 );
		$media = [];

		foreach ( $this->posts()->decode_media( $post ) as $index => $item ) {
			$media[] = [
				'type'     => (string) ( $item['type'] ?? 'image' ),
				'url'      => igbz()->get( 'vip.media' )->signed_url( $post_id, (int) $index, $user_id, $ttl ),
				'width'    => (int) ( $item['width'] ?? 0 ),
				'height'   => (int) ( $item['height'] ?? 0 ),
				'duration' => (int) ( $item['duration'] ?? 0 ),
			];
		}

		if ( [] === $media ) {
			return $this->fail( 'igbz_vip_no_media', __( 'This post has no media left to download.', 'igbz-suite' ), 410 );
		}

		$this->social()->mark_offline( $post_id, $user_id );

		return $this->ok(
			[
				'post_id'    => $post_id,
				'caption'    => (string) ( $post['caption'] ?? '' ),
				'media'      => $media,
				'expires_in' => $ttl,
				'notice'     => $this->posts()->expiry_notice( $post['expires_at'] ?? null ),
			]
		);
	}

	public function like( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request['id'];
		$user_id = get_current_user_id();

		$post = $this->posts()->post( $post_id );
		if ( ! $post ) {
			return $this->fail( 'igbz_vip_not_found', __( 'Post not found.', 'igbz-suite' ), 404 );
		}
		if ( ! $this->access()->check_row( $user_id, $post )->allowed ) {
			return $this->fail( 'igbz_vip_locked', __( 'You do not have access to this post.', 'igbz-suite' ), 403 );
		}

		return $this->ok( $this->social()->toggle_like( $post_id, $user_id ) );
	}

	public function view( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request['id'];
		$user_id = get_current_user_id();

		$post = $this->posts()->post( $post_id );
		if ( ! $post || ! $this->access()->check_row( $user_id, $post )->allowed ) {
			return $this->fail( 'igbz_vip_locked', __( 'You do not have access to this post.', 'igbz-suite' ), 403 );
		}

		$count = $this->social()->record_view( $post_id, $user_id, (int) $request->get_param( 'seconds' ) );

		return $this->ok( [ 'ok' => true, 'views_count' => $count ] );
	}

	public function list_comments( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request['id'];
		$post    = $this->posts()->post( $post_id );
		if ( ! $post ) {
			return $this->fail( 'igbz_vip_not_found', __( 'Post not found.', 'igbz-suite' ), 404 );
		}

		if ( ! $this->access()->check_row( get_current_user_id(), $post )->allowed ) {
			return $this->fail( 'igbz_vip_locked', __( 'You do not have access to this post.', 'igbz-suite' ), 403 );
		}

		[ $page, $per_page ] = $this->page_args( $request, 20 );
		$result              = $this->social()->comments( $post_id, $page, $per_page );

		return $this->paged( $result['items'], $result['total'], $page, $per_page );
	}

	public function add_comment( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$id = $this->social()->add_comment(
				(int) $request['id'],
				get_current_user_id(),
				(string) $request->get_param( 'body' ),
				(int) $request->get_param( 'parent_id' )
			);
		} catch ( \RuntimeException $e ) {
			return $this->fail( 'igbz_vip_comment_failed', $e->getMessage(), 400 );
		}

		return $this->ok( [ 'ok' => true, 'comment_id' => $id ], 201 );
	}

	// ---------------------------------------------------------------- billing

	public function plans( \WP_REST_Request $request ): \WP_REST_Response {
		$tenant_id = (int) $request->get_param( 'tenant_id' );

		return $this->ok(
			[
				'plans'        => $this->access()->plans( $tenant_id ),
				'tips_enabled' => igbz()->settings()->bool( 'vip.tips_enabled', true ),
				'tip_presets'  => $this->billing()->tip_presets(),
				'tip_min'      => igbz()->settings()->int( 'vip.tip_min', 10000 ),
				'currency'     => igbz()->settings()->string( 'general.default_currency', 'IRT' ),
			]
		);
	}

	public function membership( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id    = get_current_user_id();
		$tenant_id  = (int) $request->get_param( 'tenant_id' );
		$membership = $this->access()->active_membership( $user_id, $tenant_id );

		return $this->ok(
			[
				'is_member'  => null !== $membership,
				'membership' => $membership
					? [
						'id'         => (int) $membership['id'],
						'plan_id'    => (int) $membership['plan_id'],
						'status'     => (string) $membership['status'],
						'starts_at'  => $membership['starts_at'],
						'ends_at'    => $membership['ends_at'],
						'auto_renew' => (bool) (int) $membership['auto_renew'],
						'cancelled'  => null !== $membership['cancelled_at'],
					]
					: null,
			]
		);
	}

	public function subscribe( \WP_REST_Request $request ): \WP_REST_Response {
		$result = $this->billing()->subscribe(
			get_current_user_id(),
			(int) $request->get_param( 'plan_id' ),
			(string) $request->get_param( 'gateway' ),
			(bool) $request->get_param( 'use_wallet' )
		);

		if ( ! $result['ok'] ) {
			return $this->fail( 'igbz_vip_subscribe_failed', (string) $result['error'], 400 );
		}

		return $this->ok( $result );
	}

	public function purchase( \WP_REST_Request $request ): \WP_REST_Response {
		$result = $this->billing()->purchase_post(
			get_current_user_id(),
			(int) $request['id'],
			(string) $request->get_param( 'gateway' ),
			(bool) $request->get_param( 'use_wallet' )
		);

		if ( ! $result['ok'] ) {
			return $this->fail( 'igbz_vip_purchase_failed', (string) $result['error'], 400 );
		}

		return $this->ok( $result );
	}

	/**
	 * Tips are open to guests: the share page is public, and asking a passer-by to register before
	 * they can support the shop is how the tip never happens.
	 */
	public function tip( \WP_REST_Request $request ): \WP_REST_Response {
		$result = $this->billing()->tip(
			get_current_user_id(),
			(float) $request->get_param( 'amount' ),
			(int) $request->get_param( 'post_id' ),
			(string) $request->get_param( 'message' ),
			(string) $request->get_param( 'gateway' )
		);

		if ( ! $result['ok'] ) {
			return $this->fail( 'igbz_vip_tip_failed', (string) $result['error'], 400 );
		}

		return $this->ok( $result );
	}

	// --------------------------------------------------------------- messages

	public function threads( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->ok( [ 'items' => $this->messages()->threads_for_user( get_current_user_id() ) ] );
	}

	public function thread_messages( \WP_REST_Request $request ): \WP_REST_Response {
		$thread_id = (int) $request['id'];
		$thread    = $this->messages()->thread( $thread_id );

		if ( ! $thread || (int) $thread['user_id'] !== get_current_user_id() ) {
			return $this->fail( 'igbz_vip_not_found', __( 'Conversation not found.', 'igbz-suite' ), 404 );
		}

		[ $page, $per_page ] = $this->page_args( $request, 30 );
		$result              = $this->messages()->messages( $thread_id, $page, $per_page );

		$this->messages()->mark_read( $thread_id, VipMessageService::SENDER_USER );

		return $this->paged( $result['items'], $result['total'], $page, $per_page );
	}

	public function send_message( \WP_REST_Request $request ): \WP_REST_Response {
		$thread_id = (int) $request['id'];
		$thread    = $this->messages()->thread( $thread_id );

		if ( ! $thread || (int) $thread['user_id'] !== get_current_user_id() ) {
			return $this->fail( 'igbz_vip_not_found', __( 'Conversation not found.', 'igbz-suite' ), 404 );
		}

		try {
			$id = $this->messages()->send(
				$thread_id,
				get_current_user_id(),
				(string) $request->get_param( 'body' ),
				VipMessageService::SENDER_USER,
				(int) $request->get_param( 'post_id' )
			);
		} catch ( \RuntimeException $e ) {
			return $this->fail( 'igbz_vip_message_failed', $e->getMessage(), 400 );
		}

		return $this->ok( [ 'ok' => true, 'message_id' => $id, 'thread_id' => $thread_id ], 201 );
	}

	/** "Message the admin" straight from a post, without the app knowing a thread id. */
	public function message_admin( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		// Phase 14: the tenant a message lands in is derived from the post (or the resolved
		// tenancy), never from the client — a client-controlled tenant id would let anyone
		// open an admin thread in a store they have no relationship with.
		$tenant_id = (int) igbz()->tenancy()->id();
		if ( $post_id > 0 ) {
			$post = $this->posts()->post( $post_id );
			if ( $post ) {
				$tenant_id = (int) ( $post['tenant_id'] ?? 0 );
			}
		}

		try {
			$id = $this->messages()->send_from_user(
				get_current_user_id(),
				(string) $request->get_param( 'body' ),
				$post_id,
				$tenant_id
			);
		} catch ( \RuntimeException $e ) {
			return $this->fail( 'igbz_vip_message_failed', $e->getMessage(), 400 );
		}

		return $this->ok( [ 'ok' => true, 'message_id' => $id ], 201 );
	}

	// -------------------------------------------------------------- internals

	private function enabled(): bool {
		return igbz()->settings()->bool( 'vip.enabled', true );
	}

	private function share_url( string $shortcode ): string {
		$slug = igbz()->settings()->string( 'vip.landing_slug', 'vip' );
		return home_url( '/' . trim( $slug, '/' ) . '/p/' . rawurlencode( $shortcode ) );
	}

	private function posts(): VipPostService {
		return igbz()->get( 'vip.posts' );
	}

	private function access(): VipAccessService {
		return igbz()->get( 'vip.access' );
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
