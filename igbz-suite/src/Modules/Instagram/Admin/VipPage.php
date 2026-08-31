<?php
namespace IGBZ\Suite\Modules\Instagram\Admin;

use IGBZ\Suite\Modules\Instagram\Vip\VipAccessService;
use IGBZ\Suite\Modules\Instagram\Vip\VipBillingService;
use IGBZ\Suite\Modules\Instagram\Vip\VipMessageService;
use IGBZ\Suite\Modules\Instagram\Vip\VipPostService;
use IGBZ\Suite\Modules\Instagram\Vip\VipSocialService;
use IGBZ\Suite\Support\Admin\Menu;
use IGBZ\Suite\Support\Admin\View;
use IGBZ\Suite\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * The VIP channel, run like an Instagram page from the WordPress admin.
 *
 * The mobile admin app talks to the same services over `/vip/admin/*`; this screen exists because
 * a shop owner sitting at a desk should not have to reach for a phone to answer a comment or pull
 * a post that went out wrong. Everything here is a thin shell over the same five services the API
 * uses — no second copy of the rules.
 *
 * Five tabs, in the order the work actually happens: posts, then the feedback the posts generate
 * (comments, inbox), then who is paying for it (members, plans).
 */
final class VipPage {

	public const SLUG = 'igbz-vip';

	private const NONCE = 'igbz_vip_admin';

	private const PER_PAGE = 20;

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 24 );
	}

	public function add_page(): void {
		Menu::add( self::SLUG, __( 'VIP channel', 'igbz-suite' ), [ $this, 'render' ], Capabilities::MANAGE_INSTAGRAM );
	}

	// ------------------------------------------------------------- services

	private function posts(): VipPostService {
		return igbz()->get( 'vip.posts' );
	}

	private function social(): VipSocialService {
		return igbz()->get( 'vip.social' );
	}

	private function messages(): VipMessageService {
		return igbz()->get( 'vip.messages' );
	}

	private function billing(): VipBillingService {
		return igbz()->get( 'vip.billing' );
	}

	private function tenant_id(): int {
		return igbz()->tenancy()->id();
	}

	// ---------------------------------------------------------------- shell

	public function render(): void {
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$this->handle_post();
		}
		$this->handle_get_actions();

		$tabs = [
			'posts'    => __( 'Posts', 'igbz-suite' ),
			'comments' => __( 'Comments', 'igbz-suite' ),
			'inbox'    => __( 'Inbox', 'igbz-suite' ),
			'members'  => __( 'Members', 'igbz-suite' ),
			'plans'    => __( 'Plans', 'igbz-suite' ),
		];

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'posts';
		$tab = isset( $tabs[ $tab ] ) ? $tab : 'posts';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		View::open(
			__( 'VIP channel', 'igbz-suite' ),
			__( 'A private feed inside your app. The public Instagram post stays a teaser; the real thing is served here, only to people who paid for it.', 'igbz-suite' )
		);

		if ( ! igbz()->settings()->bool( 'vip.enabled', true ) ) {
			View::notice(
				__( 'The VIP channel is switched off, so the app and the share pages will not show any of this. Turn it on under Settings → VIP channel.', 'igbz-suite' ),
				'warning'
			);
		}

		$unread = $this->messages()->unread_count_for_admin( $this->tenant_id() );
		if ( $unread > 0 && 'inbox' !== $tab ) {
			View::notice(
				sprintf(
					/* translators: %s: number of unread messages. */
					_n( '%s unread message from a member is waiting in the inbox.', '%s unread messages from members are waiting in the inbox.', $unread, 'igbz-suite' ),
					number_format_i18n( $unread )
				),
				'info'
			);
		}

		$this->retention_notice();

		View::tabs( $tabs, $tab, self::SLUG );

		match ( $tab ) {
			'comments' => $this->render_comments(),
			'inbox'    => $this->render_inbox(),
			'members'  => $this->render_members(),
			'plans'    => $this->render_plans(),
			default    => $this->render_posts(),
		};

		View::close();
	}

	// ----------------------------------------------------------- posts tab

	/**
	 * The retention banner.
	 *
	 * The store admin does not own this number — the IGBZ senior admin sets it for the whole
	 * platform — so the screen states it rather than offering a field. The Close Friends line is
	 * the honest way out for an admin who wants to keep a post: it is advice to a human, not an
	 * integration. Nothing here touches the Instagram API.
	 */
	private function retention_notice(): void {
		$days = $this->posts()->retention_days();

		if ( $days <= 0 ) {
			return;
		}

		View::notice(
			sprintf(
				/* translators: %s: number of days a VIP post survives. */
				_n(
					'VIP posts do not last. Each post is removed from the server %s day after it is published, and the file cannot be recovered. The IGBZ administrator sets this window. If you want to keep the content, post it to your own Instagram Close Friends before it expires — your copy stays yours there.',
					'VIP posts do not last. Each post is removed from the server %s days after it is published, and the file cannot be recovered. The IGBZ administrator sets this window. If you want to keep the content, post it to your own Instagram Close Friends before it expires — your copy stays yours there.',
					$days,
					'igbz-suite'
				),
				number_format_i18n( $days )
			),
			'info'
		);
	}

	private function render_posts(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$edit   = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : '';
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' !== $edit ) {
			$this->render_post_form( 'new' === $edit ? 0 : (int) $edit );
			return;
		}

		$this->render_stats();

		printf(
			'<p><a class="button button-primary" href="%1$s">%2$s</a></p>',
			esc_url( Menu::url( self::SLUG, [ 'tab' => 'posts', 'edit' => 'new' ] ) ),
			esc_html__( 'New VIP post', 'igbz-suite' )
		);

		echo '<form method="get" class="igbz-filters">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::SLUG ) );
		echo '<input type="hidden" name="tab" value="posts" />';
		echo '<select name="status">';
		printf( '<option value="">%s</option>', esc_html__( 'Any status', 'igbz-suite' ) );
		foreach ( $this->statuses() as $key => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $key ), selected( $key, $status, false ), esc_html( $label ) );
		}
		echo '</select> ';
		submit_button( __( 'Filter', 'igbz-suite' ), 'secondary', '', false );
		printf(
			' <a class="button" href="%1$s">%2$s</a>',
			esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'tab' => 'posts', 'run' => 'tick' ] ), self::NONCE ) ),
			esc_html__( 'Run scheduling and expiry now', 'igbz-suite' )
		);
		echo '</form>';

		$db        = igbz()->db();
		$table     = $db->table( 'vip_posts' );
		$tenant_id = $this->tenant_id();

		$where = '(tenant_id = %d OR %d = 0) AND status <> %s';
		$bind  = [ $tenant_id, $tenant_id, VipPostService::STATUS_DELETED ];
		if ( '' !== $status ) {
			$where .= ' AND status = %s';
			$bind[] = $status;
		}

		$total = (int) $db->scalar( "SELECT COUNT(*) FROM {$table} WHERE {$where}", ...$bind );
		$rows  = $db->results(
			"SELECT * FROM {$table} WHERE {$where}
			 ORDER BY COALESCE(published_at, publish_at, created_at) DESC, id DESC
			 LIMIT %d OFFSET %d",
			...array_merge( $bind, [ self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE ] )
		);

		$display = [];
		foreach ( $rows as $row ) {
			$display[] = [
				'post'     => $this->post_cell( $row ),
				'access'   => esc_html( $this->access_label( (string) $row['access'], (float) $row['price'] ) ),
				'status'   => View::status_pill( $this->status_tone( (string) $row['status'] ) ) . ' '
					. esc_html( $this->statuses()[ (string) $row['status'] ] ?? (string) $row['status'] ),
				'social'   => $this->social_cell( $row ),
				'expires'  => esc_html( $this->local_time( $row['expires_at'] ?? null ) ),
				'actions'  => $this->post_actions( $row ),
			];
		}

		View::table(
			[
				'post'    => __( 'Post', 'igbz-suite' ),
				'access'  => __( 'Access', 'igbz-suite' ),
				'status'  => __( 'Status', 'igbz-suite' ),
				'social'  => __( 'Engagement', 'igbz-suite' ),
				'expires' => __( 'Expires', 'igbz-suite' ),
				'actions' => __( 'Actions', 'igbz-suite' ),
			],
			$display,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'No VIP posts yet. Publish the teaser on Instagram, then put the real thing here.', 'igbz-suite' )
		);

		View::pagination( $total, self::PER_PAGE, $paged, self::SLUG, [ 'tab' => 'posts', 'status' => $status ] );
	}

	private function render_stats(): void {
		$stats = $this->billing()->stats( $this->tenant_id(), 30 );

		$cards = [
			[ number_format_i18n( (int) $stats['active_members'] ), __( 'Active members', 'igbz-suite' ) ],
			[ View::money( (float) $stats['memberships']['total'] ), __( 'Memberships, 30 days', 'igbz-suite' ) ],
			[ View::money( (float) $stats['post_purchases']['total'] ), __( 'Single posts, 30 days', 'igbz-suite' ) ],
			[ View::money( (float) $stats['tips']['total'] ), __( 'Tips, 30 days', 'igbz-suite' ) ],
			[ number_format_i18n( (int) $stats['cancelling_members'] ), __( 'Cancelling', 'igbz-suite' ) ],
		];

		echo '<div class="igbz-cards">';
		foreach ( $cards as $card ) {
			printf( '<div class="igbz-card"><strong>%1$s</strong><span>%2$s</span></div>', esc_html( $card[0] ), esc_html( $card[1] ) );
		}
		echo '</div>';
	}

	/** @param array<string,mixed> $row */
	private function post_cell( array $row ): string {
		$caption = (string) $row['caption'];
		$title   = '' !== trim( $caption ) ? wp_trim_words( $caption, 12, '…' ) : sprintf( '#%d', (int) $row['id'] );

		return sprintf(
			'<a href="%1$s"><strong>%2$s</strong></a><br /><span class="description">%3$s · %4$s</span>',
			esc_url( Menu::url( self::SLUG, [ 'tab' => 'posts', 'edit' => (int) $row['id'] ] ) ),
			esc_html( $title ),
			esc_html( $this->kind_label( (string) $row['kind'] ) ),
			esc_html( (string) $row['shortcode'] )
		);
	}

	/** @param array<string,mixed> $row */
	private function social_cell( array $row ): string {
		return sprintf(
			/* translators: 1: likes, 2: comments, 3: views. */
			esc_html__( '%1$s likes · %2$s comments · %3$s views', 'igbz-suite' ),
			esc_html( number_format_i18n( (int) $row['likes_count'] ) ),
			esc_html( number_format_i18n( (int) $row['comments_count'] ) ),
			esc_html( number_format_i18n( (int) $row['views_count'] ) )
		);
	}

	/** @param array<string,mixed> $row */
	private function post_actions( array $row ): string {
		$id   = (int) $row['id'];
		$html = sprintf(
			'<a class="button button-small" href="%1$s">%2$s</a> ',
			esc_url( Menu::url( self::SLUG, [ 'tab' => 'posts', 'edit' => $id ] ) ),
			esc_html__( 'Edit', 'igbz-suite' )
		);

		if ( VipPostService::STATUS_PUBLISHED !== (string) $row['status'] ) {
			$html .= sprintf(
				'<a class="button button-small" href="%1$s">%2$s</a> ',
				esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'tab' => 'posts', 'publish' => $id ] ), self::NONCE ) ),
				esc_html__( 'Publish now', 'igbz-suite' )
			);
		}

		$html .= sprintf(
			'<a class="button button-small" href="%1$s" target="_blank" rel="noopener">%2$s</a> ',
			esc_url( $this->share_url( (string) $row['shortcode'] ) ),
			esc_html__( 'Share page', 'igbz-suite' )
		);

		$html .= sprintf(
			'<a class="button button-small" href="%1$s" onclick="return confirm(\'%2$s\')">%3$s</a>',
			esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'tab' => 'posts', 'delete' => $id ] ), self::NONCE ) ),
			esc_js( __( 'Remove this post and its media file?', 'igbz-suite' ) ),
			esc_html__( 'Delete', 'igbz-suite' )
		);

		return $html;
	}

	private function render_post_form( int $post_id ): void {
		$post = $post_id > 0 ? $this->posts()->post( $post_id ) : null;
		if ( $post_id > 0 && ! $post ) {
			View::notice( __( 'Post not found.', 'igbz-suite' ), 'error' );
			return;
		}

		$media = $post ? $this->posts()->decode_media( $post ) : [];

		printf(
			'<p><a href="%1$s">&larr; %2$s</a></p>',
			esc_url( Menu::url( self::SLUG, [ 'tab' => 'posts' ] ) ),
			esc_html__( 'Back to the posts', 'igbz-suite' )
		);

		echo '<form method="post">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="igbz_vip_form" value="post" />';
		printf( '<input type="hidden" name="post_id" value="%d" />', $post_id );

		echo '<table class="form-table" role="presentation"><tbody>';

		$this->row(
			__( 'Caption', 'igbz-suite' ),
			sprintf(
				'<textarea name="caption" rows="5" class="large-text">%s</textarea><p class="description">%s</p>',
				esc_textarea( (string) ( $post['caption'] ?? '' ) ),
				esc_html__( 'The same caption a member would read under an Instagram post. Shown in full on the share page even when the media is locked — it is what sells the post.', 'igbz-suite' )
			)
		);

		$this->row(
			__( 'Media URLs', 'igbz-suite' ),
			sprintf(
				'<textarea name="media" rows="4" class="large-text code" placeholder="https://example.com/wp-content/uploads/2026/08/clip.mp4">%s</textarea><p class="description">%s</p>',
				esc_textarea( implode( "\n", array_map( static fn ( array $m ): string => (string) ( $m['url'] ?? '' ), $media ) ) ),
				esc_html__( 'One per line, in order. Images and video are told apart by their extension. These URLs are never handed to the app directly: every request is re-signed for one member and expires.', 'igbz-suite' )
			)
		);

		$this->row(
			__( 'Blurred placeholders', 'igbz-suite' ),
			sprintf(
				'<textarea name="blurs" rows="4" class="large-text code">%s</textarea><p class="description">%s</p>',
				esc_textarea( implode( "\n", array_map( static fn ( array $m ): string => (string) ( $m['blur'] ?? '' ), $media ) ) ),
				esc_html__( 'One per line, matching the order above. This is the only image a locked post ever shows, so it must be a genuinely low-resolution copy — a CSS blur over the real file is not privacy.', 'igbz-suite' )
			)
		);

		$access = (string) ( $post['access'] ?? VipAccessService::ACCESS_MEMBERS );
		$this->row(
			__( 'Who can open it', 'igbz-suite' ),
			sprintf(
				'<select name="access">%s</select>',
				$this->options(
					[
						VipAccessService::ACCESS_MEMBERS  => __( 'Members only', 'igbz-suite' ),
						VipAccessService::ACCESS_PURCHASE => __( 'Members, or anyone who buys this post', 'igbz-suite' ),
						VipAccessService::ACCESS_FREE     => __( 'Everyone — a public post', 'igbz-suite' ),
					],
					$access
				)
			)
		);

		$this->row(
			__( 'Price for a single purchase', 'igbz-suite' ),
			sprintf(
				'<input type="number" name="price" value="%s" class="regular-text" min="0" step="1000" /><p class="description">%s</p>',
				esc_attr( (string) ( $post['price'] ?? 0 ) ),
				esc_html__( 'Only used by the middle option. A member never pays it twice — a subscription already unlocks the post.', 'igbz-suite' )
			)
		);

		$this->row(
			__( 'Comments', 'igbz-suite' ),
			sprintf(
				'<label><input type="hidden" name="comments_enabled" value="0" /><input type="checkbox" name="comments_enabled" value="1" %s /> %s</label>',
				checked( (bool) (int) ( $post['comments_enabled'] ?? igbz()->settings()->bool( 'vip.comments_enabled', true ) ), true, false ),
				esc_html__( 'Let members comment and reply on this post', 'igbz-suite' )
			)
		);

		$this->row(
			__( 'Publish at', 'igbz-suite' ),
			sprintf(
				'<input type="datetime-local" name="publish_at" value="%s" /><p class="description">%s</p>',
				esc_attr( $this->local_input( $post['publish_at'] ?? null ) ),
				esc_html__( 'Leave empty to keep it a draft, or use "Publish now" from the list. A time in the past publishes immediately.', 'igbz-suite' )
			)
		);

		$this->row(
			__( 'Expires', 'igbz-suite' ),
			$this->expiry_summary( $post )
		);

		$this->row(
			__( 'Linked product', 'igbz-suite' ),
			sprintf(
				'<input type="number" name="product_id" value="%s" class="small-text" min="0" /><p class="description">%s</p>',
				esc_attr( (string) ( $post['product_id'] ?? 0 ) ),
				esc_html__( 'Optional WooCommerce product ID, so the app can show a buy button under the post.', 'igbz-suite' )
			)
		);

		if ( $post ) {
			$this->row(
				__( 'Share link', 'igbz-suite' ),
				sprintf(
					'<input type="text" readonly onfocus="this.select()" class="large-text code" value="%s" /><p class="description">%s</p>',
					esc_attr( $this->share_url( (string) $post['shortcode'] ) ),
					esc_html__( 'This is what the share sheet opens: a teaser, the price, and the buttons to subscribe, buy the post, or install the app.', 'igbz-suite' )
				)
			);
		}

		echo '</tbody></table>';
		submit_button( $post ? __( 'Save post', 'igbz-suite' ) : __( 'Create post', 'igbz-suite' ) );
		echo '</form>';

		if ( $post ) {
			$this->render_post_comments( (int) $post['id'] );
		}
	}

	private function render_post_comments( int $post_id ): void {
		$comments = $this->social()->comments( $post_id, 1, 20 );
		if ( [] === $comments['items'] ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Comments on this post', 'igbz-suite' ) . '</h2>';
		$this->comment_list( $comments['items'] );
	}

	// -------------------------------------------------------- comments tab

	private function render_comments(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

		$db        = igbz()->db();
		$table     = $db->table( 'vip_post_comments' );
		$tenant_id = $this->tenant_id();

		$where = '(tenant_id = %d OR %d = 0) AND status <> %s AND is_admin = 0';
		$bind  = [ $tenant_id, $tenant_id, VipSocialService::STATUS_DELETED ];

		$total = (int) $db->scalar( "SELECT COUNT(*) FROM {$table} WHERE {$where}", ...$bind );
		$rows  = $db->results(
			"SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
			...array_merge( $bind, [ self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE ] )
		);

		echo '<p class="description">' . esc_html__( 'Everything members have said, newest first. Your own replies are hidden here so the list stays a to-do list.', 'igbz-suite' ) . '</p>';

		$this->comment_list( array_map( [ $this->social(), 'present_comment' ], $rows ), true );

		View::pagination( $total, self::PER_PAGE, $paged, self::SLUG, [ 'tab' => 'comments' ] );
	}

	/**
	 * @param array<int,array<string,mixed>> $comments
	 */
	private function comment_list( array $comments, bool $show_post = false ): void {
		if ( [] === $comments ) {
			echo '<p>' . esc_html__( 'No comments yet.', 'igbz-suite' ) . '</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped"><tbody>';

		foreach ( $comments as $comment ) {
			$id = (int) $comment['id'];

			echo '<tr><td>';
			printf(
				'<strong>%1$s</strong> <span class="description">%2$s</span>%3$s',
				esc_html( (string) $comment['author'] ),
				esc_html( $this->local_time( $comment['created_at'] ?? null ) ),
				! empty( $comment['is_pinned'] ) ? ' <em>' . esc_html__( '(pinned)', 'igbz-suite' ) . '</em>' : ''
			);

			if ( $show_post ) {
				printf(
					' — <a href="%1$s">%2$s</a>',
					esc_url( Menu::url( self::SLUG, [ 'tab' => 'posts', 'edit' => (int) $comment['post_id'] ] ) ),
					esc_html( sprintf( /* translators: %d: post id. */ __( 'post #%d', 'igbz-suite' ), (int) $comment['post_id'] ) )
				);
			}

			echo '<p>' . esc_html( (string) $comment['body'] ) . '</p>';

			foreach ( (array) ( $comment['replies'] ?? [] ) as $reply ) {
				printf(
					'<p style="margin-inline-start:24px"><strong>%1$s</strong>: %2$s</p>',
					esc_html( (string) $reply['author'] ),
					esc_html( (string) $reply['body'] )
				);
			}

			echo '<form method="post" style="margin-top:6px">';
			wp_nonce_field( self::NONCE );
			echo '<input type="hidden" name="igbz_vip_form" value="reply" />';
			printf( '<input type="hidden" name="comment_id" value="%d" />', $id );
			printf(
				'<input type="text" name="body" class="regular-text" placeholder="%s" /> ',
				esc_attr__( 'Reply as the page…', 'igbz-suite' )
			);
			submit_button( __( 'Reply', 'igbz-suite' ), 'secondary', '', false );
			printf(
				' <a class="button button-small" href="%1$s">%2$s</a>',
				esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'tab' => 'comments', 'pin' => $id ] ), self::NONCE ) ),
				empty( $comment['is_pinned'] ) ? esc_html__( 'Pin', 'igbz-suite' ) : esc_html__( 'Unpin', 'igbz-suite' )
			);
			printf(
				' <a class="button button-small" href="%1$s">%2$s</a>',
				esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'tab' => 'comments', 'hide' => $id ] ), self::NONCE ) ),
				esc_html__( 'Hide', 'igbz-suite' )
			);
			printf(
				' <a class="button button-small" href="%1$s" onclick="return confirm(\'%2$s\')">%3$s</a>',
				esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'tab' => 'comments', 'remove' => $id ] ), self::NONCE ) ),
				esc_js( __( 'Delete this comment?', 'igbz-suite' ) ),
				esc_html__( 'Delete', 'igbz-suite' )
			);
			echo '</form>';

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	// ----------------------------------------------------------- inbox tab

	private function render_inbox(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$thread_id = isset( $_GET['thread'] ) ? (int) $_GET['thread'] : 0;
		$paged     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $thread_id > 0 ) {
			$this->render_thread( $thread_id );
			return;
		}

		$inbox = $this->messages()->inbox(
			[
				'tenant_id' => $this->tenant_id(),
				'page'      => $paged,
				'per_page'  => self::PER_PAGE,
			]
		);

		$display = [];
		foreach ( $inbox['items'] as $thread ) {
			$display[] = [
				'who'     => sprintf(
					'<a href="%1$s"><strong>%2$s</strong></a>%3$s',
					esc_url( Menu::url( self::SLUG, [ 'tab' => 'inbox', 'thread' => (int) $thread['id'] ] ) ),
					esc_html( (string) $thread['user_name'] ),
					$thread['unread_admin'] > 0 ? ' <span class="description">' . esc_html__( '(unread)', 'igbz-suite' ) . '</span>' : ''
				),
				'preview' => esc_html( (string) $thread['preview'] ),
				'last'    => esc_html( $this->local_time( $thread['last_at'] ?? null ) ),
				'status'  => esc_html__( (string) $thread['status'], 'igbz-suite' ),
			];
		}

		View::table(
			[
				'who'     => __( 'Member', 'igbz-suite' ),
				'preview' => __( 'Last message', 'igbz-suite' ),
				'last'    => __( 'When', 'igbz-suite' ),
				'status'  => __( 'Status', 'igbz-suite' ),
			],
			$display,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'Nobody has written yet. Members message you from inside a post, the way they would send an Instagram DM.', 'igbz-suite' )
		);

		View::pagination( $inbox['total'], self::PER_PAGE, $paged, self::SLUG, [ 'tab' => 'inbox' ] );
	}

	private function render_thread( int $thread_id ): void {
		$thread = $this->messages()->thread( $thread_id );
		if ( ! $thread ) {
			View::notice( __( 'Conversation not found.', 'igbz-suite' ), 'error' );
			return;
		}

		$this->messages()->mark_read( $thread_id, VipMessageService::SENDER_ADMIN );

		$user = get_userdata( (int) $thread['user_id'] );

		printf(
			'<p><a href="%1$s">&larr; %2$s</a></p>',
			esc_url( Menu::url( self::SLUG, [ 'tab' => 'inbox' ] ) ),
			esc_html__( 'Back to the inbox', 'igbz-suite' )
		);

		printf(
			'<h2>%s</h2>',
			esc_html( $user ? $user->display_name : __( 'Deleted user', 'igbz-suite' ) )
		);

		$messages = $this->messages()->messages( $thread_id, 1, 50 );

		echo '<table class="wp-list-table widefat striped"><tbody>';
		foreach ( $messages['items'] as $message ) {
			$is_admin = VipMessageService::SENDER_ADMIN === (string) $message['sender_type'];
			printf(
				'<tr><td style="width:120px"><strong>%1$s</strong><br /><span class="description">%2$s</span></td><td>%3$s%4$s</td></tr>',
				esc_html( $is_admin ? __( 'You', 'igbz-suite' ) : ( $user ? $user->display_name : __( 'Member', 'igbz-suite' ) ) ),
				esc_html( $this->local_time( $message['created_at'] ?? null ) ),
				esc_html( (string) $message['body'] ),
				( (int) $message['post_id'] ) > 0
					? sprintf(
						'<br /><a href="%1$s" class="description">%2$s</a>',
						esc_url( Menu::url( self::SLUG, [ 'tab' => 'posts', 'edit' => (int) $message['post_id'] ] ) ),
						esc_html( sprintf( /* translators: %d: post id. */ __( 'about post #%d', 'igbz-suite' ), (int) $message['post_id'] ) )
					)
					: ''
			);
		}
		echo '</tbody></table>';

		echo '<form method="post" style="margin-top:12px">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="igbz_vip_form" value="message" />';
		printf( '<input type="hidden" name="thread_id" value="%d" />', $thread_id );
		printf(
			'<textarea name="body" rows="3" class="large-text" placeholder="%s"></textarea>',
			esc_attr__( 'Write a reply…', 'igbz-suite' )
		);
		submit_button( __( 'Send', 'igbz-suite' ) );
		echo '</form>';
	}

	// --------------------------------------------------------- members tab

	private function render_members(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

		$db        = igbz()->db();
		$table     = $db->table( 'vip_memberships' );
		$tenant_id = $this->tenant_id();

		$where = '(tenant_id = %d OR %d = 0) AND status <> %s';
		$bind  = [ $tenant_id, $tenant_id, VipAccessService::STATUS_PENDING ];

		$total = (int) $db->scalar( "SELECT COUNT(*) FROM {$table} WHERE {$where}", ...$bind );
		$rows  = $db->results(
			"SELECT * FROM {$table} WHERE {$where} ORDER BY ends_at DESC, id DESC LIMIT %d OFFSET %d",
			...array_merge( $bind, [ self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE ] )
		);

		$display = [];
		foreach ( $rows as $row ) {
			$user      = get_userdata( (int) $row['user_id'] );
			$display[] = [
				'member' => esc_html( $user ? $user->display_name : __( 'Deleted user', 'igbz-suite' ) ),
				'email'  => esc_html( $user ? $user->user_email : '' ),
				'status' => esc_html__( (string) $row['status'], 'igbz-suite' ) . ( null !== $row['cancelled_at'] ? ' <em>' . esc_html__( '(cancelling)', 'igbz-suite' ) . '</em>' : '' ),
				'until'  => esc_html( $this->local_time( $row['ends_at'] ?? null ) ),
				'paid'   => esc_html( View::money( (float) $row['price_paid'] ) ),
			];
		}

		View::table(
			[
				'member' => __( 'Member', 'igbz-suite' ),
				'email'  => __( 'Email', 'igbz-suite' ),
				'status' => __( 'Status', 'igbz-suite' ),
				'until'  => __( 'Access until', 'igbz-suite' ),
				'paid'   => __( 'Paid', 'igbz-suite' ),
			],
			$display,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'No memberships yet. Price a plan first — the paywall has nothing to sell until then.', 'igbz-suite' )
		);

		View::pagination( $total, self::PER_PAGE, $paged, self::SLUG, [ 'tab' => 'members' ] );
	}

	// ----------------------------------------------------------- plans tab

	private function render_plans(): void {
		$db   = igbz()->db();
		$rows = $db->results(
			'SELECT * FROM ' . $db->table( 'vip_plans' ) . ' WHERE tenant_id = %d OR tenant_id = 0 ORDER BY sort_order ASC, price ASC LIMIT 100', // Phase 20: bounded plan catalog.
			$this->tenant_id()
		);

		echo '<p class="description">' . esc_html__( 'A plan priced at zero is never buyable, which is why the sample plan ships switched off. Give it a price and activate it.', 'igbz-suite' ) . '</p>';

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		foreach (
			[
				__( 'Name', 'igbz-suite' ),
				__( 'Price', 'igbz-suite' ),
				__( 'Length (days)', 'igbz-suite' ),
				__( 'Active', 'igbz-suite' ),
				__( 'Order', 'igbz-suite' ),
				'',
			] as $heading
		) {
			echo '<th>' . esc_html( (string) $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr><td colspan="6">';
			echo '<form method="post" class="igbz-filters">';
			wp_nonce_field( self::NONCE );
			echo '<input type="hidden" name="igbz_vip_form" value="plan" />';
			printf( '<input type="hidden" name="plan_id" value="%d" />', (int) $row['id'] );
			printf( '<input type="text" name="name" value="%s" class="regular-text" /> ', esc_attr( (string) $row['name'] ) );
			printf( '<input type="number" name="price" value="%s" min="0" step="1000" /> ', esc_attr( (string) $row['price'] ) );
			printf( '<input type="number" name="duration_days" value="%s" min="1" max="3650" class="small-text" /> ', esc_attr( (string) $row['duration_days'] ) );
			printf(
				'<label><input type="hidden" name="is_active" value="0" /><input type="checkbox" name="is_active" value="1" %s /> %s</label> ',
				checked( (bool) (int) $row['is_active'], true, false ),
				esc_html__( 'Active', 'igbz-suite' )
			);
			printf( '<input type="number" name="sort_order" value="%s" class="small-text" /> ', esc_attr( (string) $row['sort_order'] ) );
			submit_button( __( 'Save', 'igbz-suite' ), 'secondary', '', false );
			echo '</form>';
			echo '</td></tr>';
		}

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Add a plan', 'igbz-suite' ) . '</h2>';
		echo '<form method="post" class="igbz-filters">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="igbz_vip_form" value="plan" />';
		echo '<input type="hidden" name="plan_id" value="0" />';
		printf( '<input type="text" name="name" placeholder="%s" class="regular-text" /> ', esc_attr__( 'Monthly', 'igbz-suite' ) );
		printf( '<input type="number" name="price" placeholder="%s" min="0" step="1000" /> ', esc_attr__( 'Price', 'igbz-suite' ) );
		printf( '<input type="number" name="duration_days" value="30" min="1" max="3650" class="small-text" /> ' );
		printf(
			'<label><input type="hidden" name="is_active" value="0" /><input type="checkbox" name="is_active" value="1" checked /> %s</label> ',
			esc_html__( 'Active', 'igbz-suite' )
		);
		submit_button( __( 'Add plan', 'igbz-suite' ), 'primary', '', false );
		echo '</form>';
	}

	// -------------------------------------------------------------- writes

	private function handle_post(): void {
		Capabilities::require( Capabilities::MANAGE_INSTAGRAM );
		View::check_nonce( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$form = isset( $_POST['igbz_vip_form'] ) ? sanitize_key( wp_unslash( $_POST['igbz_vip_form'] ) ) : '';

		match ( $form ) {
			'post'    => $this->save_post_form(),
			'reply'   => $this->save_reply(),
			'message' => $this->save_message(),
			'plan'    => $this->save_plan(),
			default   => null,
		};
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	private function save_post_form(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked by handle_post().
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;

		$urls  = $this->lines( isset( $_POST['media'] ) ? sanitize_textarea_field( wp_unslash( $_POST['media'] ) ) : '' );
		$blurs = $this->lines( isset( $_POST['blurs'] ) ? sanitize_textarea_field( wp_unslash( $_POST['blurs'] ) ) : '' );

		$media = [];
		foreach ( $urls as $i => $url ) {
			$media[] = [
				'type' => $this->guess_type( $url ),
				'url'  => $url,
				'blur' => $blurs[ $i ] ?? '',
			];
		}

		$data = [
			'caption'          => isset( $_POST['caption'] ) ? sanitize_textarea_field( wp_unslash( $_POST['caption'] ) ) : '',
			'media'            => $media,
			'access'           => isset( $_POST['access'] ) ? sanitize_key( wp_unslash( $_POST['access'] ) ) : VipAccessService::ACCESS_MEMBERS,
			'price'            => isset( $_POST['price'] ) ? (float) $_POST['price'] : 0.0,
			'comments_enabled' => ! empty( $_POST['comments_enabled'] ),
			'product_id'       => isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0,
			'publish_at'       => $this->from_input( isset( $_POST['publish_at'] ) ? sanitize_text_field( wp_unslash( $_POST['publish_at'] ) ) : '' ),
		];
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Expiry is deliberately not in that list. The window and what happens at the end of it
		// belong to the IGBZ senior admin (Settings → VIP channel), and the service derives both
		// from the settings; accepting them from this form would let one store quietly opt out of
		// a platform policy the customer was promised on the purchase page.

		if ( '' !== (string) $data['publish_at'] ) {
			$data['status'] = VipPostService::STATUS_SCHEDULED;
		}

		if ( $post_id > 0 ) {
			$this->posts()->update( $post_id, $data );
			View::notice( __( 'Post saved.', 'igbz-suite' ) );
			return;
		}

		$new_id = $this->posts()->create( $data, $this->tenant_id(), get_current_user_id() );
		if ( $new_id <= 0 ) {
			View::notice( __( 'Could not create the post.', 'igbz-suite' ), 'error' );
			return;
		}

		View::notice( __( 'Post created. Publish it when the teaser goes out on Instagram.', 'igbz-suite' ) );
		// Keep the editor on screen for the row that was just created rather than bouncing back
		// to a list where it is one line among many.
		$_GET['edit'] = (string) $new_id;
	}

	private function save_reply(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked by handle_post().
		$comment_id = isset( $_POST['comment_id'] ) ? (int) $_POST['comment_id'] : 0;
		$body       = isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $comment_id <= 0 || '' === trim( $body ) ) {
			return;
		}

		$db      = igbz()->db();
		$comment = $db->row( 'SELECT post_id FROM ' . $db->table( 'vip_post_comments' ) . ' WHERE id = %d AND tenant_id = %d', $comment_id, igbz()->tenancy()->id() );
		if ( ! $comment ) {
			View::notice( __( 'Comment not found.', 'igbz-suite' ), 'error' );
			return;
		}

		try {
			$this->social()->add_comment( (int) $comment['post_id'], get_current_user_id(), $body, $comment_id, true );
			View::notice( __( 'Reply posted.', 'igbz-suite' ) );
		} catch ( \RuntimeException $e ) {
			View::notice( $e->getMessage(), 'error' );
		}
	}

	private function save_message(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked by handle_post().
		$thread_id = isset( $_POST['thread_id'] ) ? (int) $_POST['thread_id'] : 0;
		$body      = isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $thread_id <= 0 || '' === trim( $body ) ) {
			return;
		}

		try {
			$this->messages()->send( $thread_id, get_current_user_id(), $body, VipMessageService::SENDER_ADMIN );
			View::notice( __( 'Message sent.', 'igbz-suite' ) );
		} catch ( \RuntimeException $e ) {
			View::notice( $e->getMessage(), 'error' );
		}
	}

	private function save_plan(): void {
		$db  = igbz()->db();
		$now = current_time( 'mysql', true );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked by handle_post().
		$plan_id = isset( $_POST['plan_id'] ) ? (int) $_POST['plan_id'] : 0;
		$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$fields  = [
			'name'          => $name,
			'price'         => isset( $_POST['price'] ) ? max( 0.0, (float) $_POST['price'] ) : 0.0,
			'duration_days' => isset( $_POST['duration_days'] ) ? max( 1, (int) $_POST['duration_days'] ) : 30,
			'is_active'     => empty( $_POST['is_active'] ) ? 0 : 1,
			'sort_order'    => isset( $_POST['sort_order'] ) ? (int) $_POST['sort_order'] : 0,
			'updated_at'    => $now,
		];
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === trim( $name ) ) {
			View::notice( __( 'The plan needs a name.', 'igbz-suite' ), 'error' );
			return;
		}

		// A plan with no price cannot be sold, so activating one is a mistake worth catching here
		// rather than letting a member hit a checkout that charges nothing.
		if ( $fields['is_active'] && $fields['price'] <= 0 ) {
			$fields['is_active'] = 0;
			View::notice( __( 'A plan priced at zero cannot be active. Set a price, then activate it.', 'igbz-suite' ), 'warning' );
		}

		if ( $plan_id > 0 ) {
			$db->update( 'vip_plans', $fields, [ 'id' => $plan_id ] );
			View::notice( __( 'Plan saved.', 'igbz-suite' ) );
			return;
		}

		$fields['tenant_id']  = $this->tenant_id();
		$fields['slug']       = $this->unique_plan_slug( $name );
		$fields['currency']   = igbz()->settings()->string( 'general.default_currency', 'IRT' );
		$fields['created_at'] = $now;

		if ( $db->insert( 'vip_plans', $fields ) <= 0 ) {
			View::notice( __( 'Could not save the plan.', 'igbz-suite' ), 'error' );
			return;
		}

		View::notice( __( 'Plan added.', 'igbz-suite' ) );
	}

	private function unique_plan_slug( string $name ): string {
		$db   = igbz()->db();
		$base = sanitize_title( $name ) ?: 'plan';
		$slug = $base;

		// The unique key is (tenant_id, slug), so a second shop may reuse a name freely; only a
		// collision inside this tenant needs a suffix.
		for ( $i = 2; $i < 50; $i++ ) {
			$taken = (int) $db->scalar(
				'SELECT COUNT(*) FROM ' . $db->table( 'vip_plans' ) . ' WHERE tenant_id = %d AND slug = %s',
				$this->tenant_id(),
				$slug
			);
			if ( 0 === $taken ) {
				return $slug;
			}
			$slug = $base . '-' . $i;
		}

		return $base . '-' . wp_rand( 100, 999 );
	}

	private function handle_get_actions(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		foreach ( [ 'publish', 'delete', 'pin', 'hide', 'remove' ] as $action ) {
			if ( ! isset( $_GET[ $action ] ) ) {
				continue;
			}
			check_admin_referer( self::NONCE );
			Capabilities::require( Capabilities::MANAGE_INSTAGRAM );
			$this->run_action( $action, (int) $_GET[ $action ] );
			return;
		}

		if ( isset( $_GET['run'] ) && 'tick' === sanitize_key( wp_unslash( $_GET['run'] ) ) ) {
			check_admin_referer( self::NONCE );
			Capabilities::require( Capabilities::MANAGE_INSTAGRAM );
			$published = $this->posts()->publish_due();
			$expired   = $this->posts()->expire_due();
			View::notice(
				sprintf(
					/* translators: 1: number published, 2: number expired. */
					__( 'Done: %1$s published, %2$s expired.', 'igbz-suite' ),
					number_format_i18n( $published ),
					number_format_i18n( $expired )
				)
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	private function run_action( string $action, int $id ): void {
		switch ( $action ) {
			case 'publish':
				$published = $this->posts()->publish( $id );
				View::notice(
					$published
						? __( 'Post published.', 'igbz-suite' )
						: __( 'That post could not be published — it may already be live.', 'igbz-suite' ),
					$published ? 'success' : 'error'
				);
				break;

			case 'delete':
				$this->posts()->delete( $id );
				View::notice( __( 'Post removed.', 'igbz-suite' ) );
				break;

			case 'pin':
				$db      = igbz()->db();
				$pinned  = (int) $db->scalar(
					'SELECT is_pinned FROM ' . $db->table( 'vip_post_comments' ) . ' WHERE id = %d AND tenant_id = %d',
					$id,
					igbz()->tenancy()->id()
				);
				$this->social()->pin_comment( $id, 1 !== $pinned );
				View::notice( __( 'Comment updated.', 'igbz-suite' ) );
				break;

			case 'hide':
				$this->social()->set_comment_status( $id, VipSocialService::STATUS_HIDDEN );
				View::notice( __( 'Comment hidden.', 'igbz-suite' ) );
				break;

			case 'remove':
				$this->social()->set_comment_status( $id, VipSocialService::STATUS_DELETED );
				View::notice( __( 'Comment deleted.', 'igbz-suite' ) );
				break;
		}
	}

	// -------------------------------------------------------------- helpers

	/**
	 * One row of the post editor.
	 *
	 * $html is echoed as built, not run through wp_kses_post(). That is deliberate and it is the
	 * only safe reading: kses strips form controls — select, option, input, textarea — so filtering
	 * here silently deletes every field on this screen and leaves the labels behind. Each caller
	 * assembles its own markup with esc_attr()/esc_html() on the values, which is where escaping
	 * belongs.
	 */
	private function row( string $label, string $html ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller; see above.
		echo '</td></tr>';
	}

	/** @param array<string,string> $options */
	private function options( array $options, string $current ): string {
		$html = '';
		foreach ( $options as $value => $label ) {
			$html .= sprintf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $value, $current, false ),
				esc_html( $label )
			);
		}
		return $html;
	}

	/** @return array<int,string> */
	private function lines( string $raw ): array {
		$out = [];
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) ?: [] as $line ) {
			$line = trim( (string) $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	private function guess_type( string $url ): string {
		$extension = strtolower( (string) pathinfo( wp_parse_url( $url, PHP_URL_PATH ) ?: '', PATHINFO_EXTENSION ) );
		return in_array( $extension, [ 'mp4', 'mov', 'webm', 'm4v' ], true ) ? 'video' : 'image';
	}

	/** @return array<string,string> */
	private function statuses(): array {
		return [
			VipPostService::STATUS_DRAFT     => __( 'Draft', 'igbz-suite' ),
			VipPostService::STATUS_SCHEDULED => __( 'Scheduled', 'igbz-suite' ),
			VipPostService::STATUS_PUBLISHED => __( 'Published', 'igbz-suite' ),
			VipPostService::STATUS_EXPIRED   => __( 'Expired', 'igbz-suite' ),
		];
	}

	private function status_tone( string $status ): string {
		return match ( $status ) {
			VipPostService::STATUS_PUBLISHED => 'ok',
			VipPostService::STATUS_EXPIRED   => 'error',
			default                          => 'warn',
		};
	}

	private function kind_label( string $kind ): string {
		return match ( $kind ) {
			VipPostService::KIND_CAROUSEL => __( 'Carousel', 'igbz-suite' ),
			VipPostService::KIND_VIDEO    => __( 'Video', 'igbz-suite' ),
			VipPostService::KIND_TEXT     => __( 'Text', 'igbz-suite' ),
			default                       => __( 'Photo', 'igbz-suite' ),
		};
	}

	private function access_label( string $access, float $price ): string {
		return match ( $access ) {
			VipAccessService::ACCESS_FREE     => __( 'Public', 'igbz-suite' ),
			VipAccessService::ACCESS_PURCHASE => sprintf(
				/* translators: %s: formatted price. */
				__( 'Members or %s', 'igbz-suite' ),
				View::money( $price )
			),
			default                           => __( 'Members only', 'igbz-suite' ),
		};
	}

	private function share_url( string $shortcode ): string {
		$slug = trim( igbz()->settings()->string( 'vip.landing_slug', 'vip' ), '/' );
		return home_url( '/' . $slug . '/p/' . rawurlencode( $shortcode ) );
	}

	private function local_time( ?string $mysql_utc ): string {
		if ( ! $mysql_utc || '0000-00-00 00:00:00' === $mysql_utc ) {
			return '—';
		}
		return wp_date( 'Y-m-d H:i', (int) strtotime( $mysql_utc . ' UTC' ) ) ?: '—';
	}

	/** Value for a datetime-local input, in site time. */
	/**
	 * The read-only expiry line in the post editor.
	 *
	 * A statement, not a control: the window is platform policy. A draft has no expiry yet, so it
	 * says what will happen at publish time rather than showing an empty date the admin might
	 * think they have to fill in.
	 */
	private function expiry_summary( ?array $post ): string {
		$days = $this->posts()->retention_days();

		if ( $days <= 0 ) {
			return '<p class="description">' . esc_html__( 'The IGBZ administrator has switched expiry off, so posts stay until you delete them.', 'igbz-suite' ) . '</p>';
		}

		$when = (string) ( $post['expires_at'] ?? '' );
		$line = '' === $when
			? sprintf(
				/* translators: %s: number of days. */
				_n(
					'%s day after it is published, this post is removed from the server.',
					'%s days after it is published, this post is removed from the server.',
					$days,
					'igbz-suite'
				),
				number_format_i18n( $days )
			)
			: sprintf(
				/* translators: %s: local date and time. */
				__( 'Removed from the server on %s.', 'igbz-suite' ),
				$this->local_time( $when )
			);

		return sprintf(
			'<p><strong>%s</strong></p><p class="description">%s</p>',
			esc_html( $line ),
			esc_html__( 'The IGBZ administrator sets this window; it cannot be changed per post. The media file is deleted for good — to keep the content, post it to your own Instagram Close Friends before then.', 'igbz-suite' )
		);
	}

	private function local_input( ?string $mysql_utc ): string {
		if ( ! $mysql_utc || '0000-00-00 00:00:00' === $mysql_utc ) {
			return '';
		}
		return (string) get_date_from_gmt( $mysql_utc, 'Y-m-d\TH:i' );
	}

	/** Turn a datetime-local value in site time back into UTC. */
	private function from_input( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		return (string) get_gmt_from_date( str_replace( 'T', ' ', $value ), 'Y-m-d H:i:s' );
	}
}
