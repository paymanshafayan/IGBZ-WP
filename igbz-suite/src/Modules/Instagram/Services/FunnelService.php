<?php
namespace IGBZ\Suite\Modules\Instagram\Services;

use IGBZ\Suite\Modules\Instagram\Gateways\ManyChatClient;
use IGBZ\Suite\Modules\Instagram\Services\AccountCredentials;
use IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService;
use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * The "comment the word X and I'll DM you the link" engine.
 *
 * A funnel matches a keyword on an Instagram comment (optionally scoped to a single post) and
 * responds through ManyChat: trigger a Flow, tag the subscriber, deliver a link / coupon /
 * product page, and optionally credit the customer's wallet.
 *
 * Every hit is recorded in ig_funnel_hits with a UNIQUE (funnel_id, comment_id) key, so ManyChat
 * retries can never double-deliver.
 */
final class FunnelService {

	public const MATCH_EXACT    = 'exact';
	public const MATCH_CONTAINS = 'contains';
	public const MATCH_STARTS   = 'starts';
	public const MATCH_REGEX    = 'regex';

	public const TARGET_URL     = 'url';
	public const TARGET_PRODUCT = 'product';
	public const TARGET_COUPON  = 'coupon';
	public const TARGET_FLOW    = 'flow';

	/**
	 * States a hit can be in, stored in ig_funnel_hits.delivery_error.
	 *
	 * The fast path answers ManyChat inside its ~10 second window and only *then* talks to the
	 * API, so "the webhook ran" and "the subscriber got the DM" are two different facts and the
	 * row has to be able to say which one it is:
	 *
	 *   delivered = 0, error = DELIVERY_PENDING  the hit is recorded, the DM is not settled yet
	 *   delivered = 0, error = DELIVERY_PENDING_INLINE
	 *                                            same, but the reply was already handed back in
	 *                                            the webhook response for ManyChat to render, so
	 *                                            the follow-up must not send the text a second time
	 *   delivered = 0, error = DELIVERY_BLOCKED  the subscriber is over the per-user cap
	 *   delivered = 0, error = <message>         the send really failed; retried hourly
	 *   delivered = 1, error = ''                confirmed by a ManyChat API call that succeeded
	 *   delivered = 1, error = DELIVERY_UNCONFIRMED
	 *                                            the reply was handed back in the webhook response
	 *                                            for ManyChat to render, and no API call was
	 *                                            available to prove it arrived
	 *
	 * Keeping the in-flight state in this column rather than in a new one is deliberate: it needs
	 * no migration, and every reader already treats delivery_error as "why this row is not a
	 * delivery" rather than as free text.
	 *
	 * A conversion is counted only on the transition into delivered = 1, so the dashboard can no
	 * longer report a 100% conversion rate for a funnel whose DMs are all failing.
	 */
	public const DELIVERY_PENDING        = 'pending';
	public const DELIVERY_PENDING_INLINE = 'pending_inline';
	public const DELIVERY_BLOCKED        = 'per_user_limit';
	public const DELIVERY_UNCONFIRMED    = 'unconfirmed';

	/** Both in-flight spellings, for the queries that must treat them alike. */
	public const PENDING_STATES = [ self::DELIVERY_PENDING, self::DELIVERY_PENDING_INLINE ];

	/** Grace period before the hourly retry takes over a hit the scheduled follow-up never settled. */
	public const FOLLOWUP_GRACE = 300;

	public function __construct(
		private Db $db,
		private ManyChatClient $client,
		private SubscriberService $subscribers,
		private WalletService $wallet,
		private Logger $logger,
		private AccountCredentials $credentials
	) {}

	/**
	 * The ManyChat client for the account that owns a funnel.
	 *
	 * ManyChat keys are page-scoped, so the key must follow the funnel's account. A funnel with
	 * account_id = 0 applies to every account of its tenant; those fall back to the tenant's first
	 * active account, which is the only page we could sensibly message.
	 *
	 * @param array<string,mixed> $funnel
	 */
	private function client_for( array $funnel ): ManyChatClient {
		$account = $this->account_for( $funnel );
		$key     = $account ? $this->credentials->key( $account, AccountCredentials::SERVICE_MANYCHAT ) : '';

		return $this->client->for_key( $key );
	}

	/**
	 * @param array<string,mixed> $funnel
	 * @return array<string,mixed>|null
	 */
	private function account_for( array $funnel ): ?array {
		$account_id = (int) ( $funnel['account_id'] ?? 0 );
		if ( $account_id > 0 ) {
			return $this->db->row(
				'SELECT * FROM ' . $this->db->table( 'ig_accounts' ) . ' WHERE id = %d',
				$account_id
			);
		}

		return $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'ig_accounts' ) . '
			 WHERE tenant_id = %d AND is_active = 1 ORDER BY id LIMIT 1',
			(int) ( $funnel['tenant_id'] ?? 0 )
		);
	}

	// --------------------------------------------------------------- CRUD

	/** @return array<string,mixed>|null */
	public function get( int $id ): ?array {
		return $this->db->row( 'SELECT * FROM ' . $this->db->table( 'ig_funnels' ) . ' WHERE id = %d AND tenant_id = %d', $id, igbz()->tenancy()->id() );
	}

	/**
	 * @param array{tenant_id?:int,account_id?:int,active_only?:bool} $args
	 * @return array<int,array<string,mixed>>
	 */
	public function all( array $args = [] ): array {
		$where  = [ '1=1' ];
		$params = [];
		foreach ( [ 'tenant_id', 'account_id' ] as $column ) {
			if ( isset( $args[ $column ] ) ) {
				$where[]  = $column . ' = %d';
				$params[] = (int) $args[ $column ];
			}
		}
		if ( ! empty( $args['active_only'] ) ) {
			$where[] = 'is_active = 1';
		}

		// Phase 20: a tenant's funnel list is bounded; the cap documents it.
		$sql = 'SELECT * FROM ' . $this->db->table( 'ig_funnels' ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT 500';

		return $params ? $this->db->results( $sql, ...$params ) : $this->db->results( $sql );
	}

	/** @param array<string,mixed> $data */
	public function save( array $data, int $id = 0 ): int {
		$now     = current_time( 'mysql', true );
		$payload = [
			'tenant_id'           => (int) ( $data['tenant_id'] ?? 0 ),
			'account_id'          => (int) ( $data['account_id'] ?? 0 ),
			'name'                => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'keyword'             => $this->canonical( (string) ( $data['keyword'] ?? '' ) ),
			'match_mode'          => in_array( $data['match_mode'] ?? '', [ self::MATCH_EXACT, self::MATCH_CONTAINS, self::MATCH_STARTS, self::MATCH_REGEX ], true )
				? (string) $data['match_mode']
				: self::MATCH_CONTAINS,
			'post_id'             => sanitize_text_field( (string) ( $data['post_id'] ?? '' ) ),
			'reply_text'          => (string) ( $data['reply_text'] ?? '' ),
			'target_type'         => in_array( $data['target_type'] ?? '', [ self::TARGET_URL, self::TARGET_PRODUCT, self::TARGET_COUPON, self::TARGET_FLOW ], true )
				? (string) $data['target_type']
				: self::TARGET_URL,
			'target_url'          => esc_url_raw( (string) ( $data['target_url'] ?? '' ) ),
			'product_id'          => (int) ( $data['product_id'] ?? 0 ),
			'coupon_code'         => sanitize_text_field( (string) ( $data['coupon_code'] ?? '' ) ),
			'manychat_flow_ns'    => sanitize_text_field( (string) ( $data['manychat_flow_ns'] ?? '' ) ),
			'manychat_tag'        => sanitize_text_field( (string) ( $data['manychat_tag'] ?? '' ) ),
			'grant_wallet_credit' => (float) ( $data['grant_wallet_credit'] ?? 0 ),
			'per_user_limit'      => (int) ( $data['per_user_limit'] ?? 1 ),
			'total_limit'         => (int) ( $data['total_limit'] ?? 0 ),
			'starts_at'           => ! empty( $data['starts_at'] ) ? (string) $data['starts_at'] : null,
			'ends_at'             => ! empty( $data['ends_at'] ) ? (string) $data['ends_at'] : null,
			'is_active'           => empty( $data['is_active'] ) ? 0 : 1,
			'updated_at'          => $now,
		];

		if ( $id > 0 ) {
			$this->db->update( 'ig_funnels', $payload, [ 'id' => $id ] );
			return $id;
		}
		$payload['created_at'] = $now;
		return $this->db->insert( 'ig_funnels', $payload );
	}

	public function delete( int $id ): bool {
		return $this->db->delete( 'ig_funnels', [ 'id' => $id ] ) > 0;
	}

	// ------------------------------------------------------------ matching

	private function canonical( string $value ): string {
		$value = preg_replace( '/[\x{200C}\x{200F}\x{200E}]/u', '', $value ) ?? $value;
		$value = str_replace(
			[ 'ي', 'ك', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩', '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' ],
			[ 'ی', 'ک', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ],
			$value
		);
		return trim( mb_strtolower( $value ) );
	}

	/**
	 * Find the funnel a comment triggers. Post-scoped funnels win over global ones.
	 *
	 * @return array<string,mixed>|null
	 */
	public function match( string $comment, string $post_id = '', int $tenant_id = 0, int $account_id = 0 ): ?array {
		$needle = $this->canonical( $comment );
		if ( '' === $needle ) {
			return null;
		}

		$now = current_time( 'mysql', true );

		// A post can be referred to in more than one way and the two ends of this comparison are
		// configured by different parties. The funnel's post_id is whatever the operator picked or
		// pasted -- since the content picker landed, usually the shortcode of one of our published
		// posts, but older funnels still hold the raw id that ManyChat sends, and a pasted post URL
		// is a reasonable thing to type. The incoming value is whatever ManyChat put in the comment
		// event. Match on every spelling that denotes the same post rather than on string equality,
		// so a funnel does not silently stop firing because the two sides disagree about format.
		$candidates = array_values(
			array_unique(
				array_filter(
					[ $post_id, PostIdentity::from_permalink( $post_id ) ],
					static fn ( string $value ): bool => '' !== $value
				)
			)
		);

		// The empty string is the "any post" scope and is always in play. Kept separate from the
		// candidates so that an unparseable incoming id narrows to global funnels instead of
		// matching everything.
		$placeholders = implode( ', ', array_fill( 0, count( $candidates ) + 1, '%s' ) );

		$rows = $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_funnels' ) . '
			 WHERE is_active = 1
			   AND (starts_at IS NULL OR starts_at <= %s)
			   AND (ends_at IS NULL OR ends_at >= %s)
			   AND post_id IN (' . $placeholders . ')
			   AND (tenant_id = %d OR %d = 0)
			   AND (account_id = %d OR account_id = 0)
			 -- A funnel pinned to this post beats a catch-all funnel that happens to share the
			 -- keyword: (post_id = "") is 0 for the pinned row and 1 for the catch-all, so ASC
			 -- puts the pinned one first. Ordering on the negation instead reverses the two and
			 -- lets one catch-all shadow every per-post funnel on the account.
			 ORDER BY (post_id = %s) ASC, id DESC',
			$now,
			$now,
			...array_merge( $candidates, [ '' ], [ $tenant_id, $tenant_id, $account_id, '' ] )
		);

		foreach ( $rows as $row ) {
			if ( $this->matches( $needle, $row ) && ! $this->exhausted( $row ) ) {
				return $row;
			}
		}

		return null;
	}

	/** @param array<string,mixed> $funnel */
	private function matches( string $needle, array $funnel ): bool {
		$keyword = $this->canonical( (string) $funnel['keyword'] );
		if ( '' === $keyword ) {
			return false;
		}

		return match ( (string) $funnel['match_mode'] ) {
			self::MATCH_EXACT  => $needle === $keyword,
			self::MATCH_STARTS => str_starts_with( $needle, $keyword ),
			self::MATCH_REGEX  => (bool) @preg_match( '/' . str_replace( '/', '\/', $keyword ) . '/iu', $needle ),
			default            => str_contains( $needle, $keyword ),
		};
	}

	/** @param array<string,mixed> $funnel */
	private function exhausted( array $funnel ): bool {
		$total = (int) $funnel['total_limit'];
		return $total > 0 && (int) $funnel['conversions'] >= $total;
	}

	// ------------------------------------------------------------ delivery

	/**
	 * Handle one inbound comment event. Idempotent on (funnel_id, comment_id).
	 *
	 * @param array{comment_text?:string,comment_id?:string,post_id?:string,subscriber_id?:string,ig_username?:string,ig_user_id?:string,first_name?:string,last_name?:string,timestamp?:int,event?:string,tenant_id?:int,account_id?:int} $event
	 * @return array{matched:bool,duplicate:bool,funnel_id:int,hit_id:int,message:string,payload:array<string,mixed>}
	 */
	public function handle_event( array $event ): array {
		$comment    = (string) ( $event['comment_text'] ?? '' );
		$comment_id = (string) ( $event['comment_id'] ?? '' );
		$post_id    = (string) ( $event['post_id'] ?? '' );
		$tenant_id  = (int) ( $event['tenant_id'] ?? 0 );
		$account_id = (int) ( $event['account_id'] ?? 0 );

		$funnel = $this->match( $comment, $post_id, $tenant_id, $account_id );
		if ( ! $funnel ) {
			return $this->result( false, false, 0, 0, __( 'No funnel matched this comment.', 'igbz-suite' ) );
		}

		$subscriber_id = $this->subscribers->upsert(
			[
				'manychat_subscriber_id' => (string) ( $event['subscriber_id'] ?? '' ),
				'ig_username'            => (string) ( $event['ig_username'] ?? '' ),
				'ig_user_id'             => (string) ( $event['ig_user_id'] ?? '' ),
				'first_name'             => (string) ( $event['first_name'] ?? '' ),
				'last_name'              => (string) ( $event['last_name'] ?? '' ),
			],
			(int) $funnel['tenant_id']
		);

		$hit_id = $this->record_hit( $funnel, $event, $subscriber_id );

		if ( 0 === $hit_id ) {
			return $this->result( true, true, (int) $funnel['id'], 0, __( 'This comment has already been processed.', 'igbz-suite' ) );
		}

		$this->db->query(
			'UPDATE ' . $this->db->table( 'ig_funnels' ) . ' SET hits = hits + 1 WHERE id = %d',
			(int) $funnel['id']
		);

		if ( $this->over_user_limit( $funnel, (string) ( $event['subscriber_id'] ?? '' ), $hit_id ) ) {
			$this->db->update(
				'ig_funnel_hits',
				[ 'delivered' => 0, 'delivery_error' => self::DELIVERY_BLOCKED ],
				[ 'id' => $hit_id ]
			);
			return $this->result( true, false, (int) $funnel['id'], $hit_id, __( 'This subscriber has already claimed this offer.', 'igbz-suite' ) );
		}

		$payload = $this->deliver( $funnel, $hit_id, (string) ( $event['subscriber_id'] ?? '' ), $subscriber_id );

		do_action( 'igbz_ig_funnel_hit', (int) $funnel['id'], $hit_id, $event );

		return $this->result( true, false, (int) $funnel['id'], $hit_id, __( 'Delivered.', 'igbz-suite' ), $payload );
	}

	/**
	 * Insert the dedupe row for one inbound event. Returns 0 when the comment was already handled
	 * (UNIQUE funnel_id + comment_id).
	 *
	 * @param array<string,mixed> $funnel
	 * @param array<string,mixed> $event
	 */
	public function record_hit( array $funnel, array $event, int $subscriber_row_id = 0 ): int {
		$comment    = (string) ( $event['comment_text'] ?? '' );
		$post_id    = (string) ( $event['post_id'] ?? '' );
		$comment_id = (string) ( $event['comment_id'] ?? '' );

		if ( '' === $comment_id ) {
			$comment_id = 'synthetic:' . md5( (string) ( $event['subscriber_id'] ?? '' ) . '|' . $post_id . '|' . $comment );
		}

		return $this->db->insert(
			'ig_funnel_hits',
			[
				'tenant_id'              => (int) $funnel['tenant_id'],
				'funnel_id'              => (int) $funnel['id'],
				'subscriber_id'          => $subscriber_row_id,
				'manychat_subscriber_id' => (string) ( $event['subscriber_id'] ?? '' ),
				'ig_username'            => ltrim( (string) ( $event['ig_username'] ?? '' ), '@' ),
				'comment_id'             => $comment_id,
				'comment_text'           => mb_substr( $comment, 0, 2000 ),
				'post_id'                => $post_id,
				'event'                  => (string) ( $event['event'] ?? 'comment' ),
				// Recorded as explicitly not-yet-settled rather than as an empty string, so an
				// in-flight hit is distinguishable from one nobody ever tried to deliver.
				'delivered'              => 0,
				'delivery_error'         => self::DELIVERY_PENDING,
				'occurred_at'            => ! empty( $event['timestamp'] )
					? gmdate( 'Y-m-d H:i:s', (int) $event['timestamp'] )
					: current_time( 'mysql', true ),
				'created_at'             => current_time( 'mysql', true ),
			]
		);
	}

	/**
	 * Fast path for the ManyChat External Request action, which times out after roughly ten
	 * seconds. Everything that needs an outbound HTTP call (sendFlow, tagging, profile sync,
	 * wallet credit) is pushed to a background event; the response only contains locally computed
	 * data so ManyChat can render the DM itself.
	 *
	 * What this method must NOT do is claim the delivery happened. Answering the webhook proves
	 * only that we computed a reply — whether the subscriber received anything is decided later,
	 * in followup(), which is the single writer of `delivered` and of the conversion counter.
	 * Marking the hit delivered here made a funnel with a broken ManyChat key report a 100%
	 * conversion rate while sending nothing at all, and hid the row from the hourly retry.
	 *
	 * @param array<string,mixed> $event
	 * @return array{matched:bool,duplicate:bool,blocked:bool,funnel:array<string,mixed>|null,hit_id:int,link:string,coupon:string,text:string}
	 */
	public function handle_event_async( array $event ): array {
		$funnel = $this->match(
			(string) ( $event['comment_text'] ?? '' ),
			(string) ( $event['post_id'] ?? '' ),
			(int) ( $event['tenant_id'] ?? 0 ),
			(int) ( $event['account_id'] ?? 0 )
		);

		if ( ! $funnel ) {
			return [
				'matched'   => false,
				'duplicate' => false,
				'blocked'   => false,
				'funnel'    => null,
				'hit_id'    => 0,
				'link'      => '',
				'coupon'    => '',
				'text'      => '',
			];
		}

		$hit_id = $this->record_hit( $funnel, $event );
		if ( 0 === $hit_id ) {
			// A ManyChat retry of a comment we already have. Re-serving the link is the friendly
			// answer; no counter moves and no coupon is minted.
			return [
				'matched'   => true,
				'duplicate' => true,
				'blocked'   => false,
				'funnel'    => $funnel,
				'hit_id'    => 0,
				'link'      => $this->resolve_link( $funnel ),
				'coupon'    => '',
				'text'      => '',
			];
		}

		// Every recorded hit is an attempt, so the hits counter moves here and only here — before
		// any branch can return. Previously the capped branch returned without incrementing, so
		// the conversion rate was computed against a denominator that skipped exactly the events
		// that did not convert.
		$this->db->query(
			'UPDATE ' . $this->db->table( 'ig_funnels' ) . ' SET hits = hits + 1 WHERE id = %d',
			(int) $funnel['id']
		);

		// The per-subscriber cap has to be enforced here too, not only in handle_event(). This is
		// the path the ManyChat webhook actually uses, so without it a funnel configured "one per
		// user" handed out an unlimited number of links — and, for coupon funnels, an unlimited
		// number of discount codes — to the same person simply by commenting again.
		if ( $this->over_user_limit( $funnel, (string) ( $event['subscriber_id'] ?? '' ), $hit_id ) ) {
			$this->db->update(
				'ig_funnel_hits',
				[ 'delivered' => 0, 'delivery_error' => self::DELIVERY_BLOCKED ],
				[ 'id' => $hit_id ]
			);

			// No link goes out with a blocked hit. Returning one made the cap decorative: the
			// caller put it straight into the DM, so the person who had used up their allowance
			// still got the target URL.
			return [
				'matched'   => true,
				'duplicate' => false,
				'blocked'   => true,
				'funnel'    => $funnel,
				'hit_id'    => $hit_id,
				'link'      => '',
				'coupon'    => '',
				'text'      => '',
			];
		}

		$link   = $this->resolve_link( $funnel );
		$coupon = self::TARGET_COUPON === (string) $funnel['target_type']
			? $this->issue_coupon( $funnel, (string) ( $event['subscriber_id'] ?? '' ) )
			: '';

		if ( '' !== $coupon ) {
			$link = add_query_arg( 'coupon', rawurlencode( $coupon ), $link );
		}

		$text = strtr(
			(string) $funnel['reply_text'],
			[ '{link}' => $link, '{coupon}' => $coupon, '{keyword}' => (string) $funnel['keyword'] ]
		);
		if ( '' === trim( $text ) ) {
			$text = sprintf( /* translators: %s: link */ __( 'Here you go: %s', 'igbz-suite' ), $link );
		}

		// The coupon is stored now because it has really been minted; delivery has not happened.
		$this->db->update(
			'ig_funnel_hits',
			[ 'coupon_issued' => $coupon, 'delivered' => 0, 'delivery_error' => self::DELIVERY_PENDING_INLINE ],
			[ 'id' => $hit_id ]
		);

		if ( ! wp_next_scheduled( 'igbz_ig_funnel_followup', [ $hit_id ] ) ) {
			wp_schedule_single_event( time() + 5, 'igbz_ig_funnel_followup', [ $hit_id ] );
		}

		do_action( 'igbz_ig_funnel_hit', (int) $funnel['id'], $hit_id, $event );

		return [
			'matched'   => true,
			'duplicate' => false,
			'blocked'   => false,
			'funnel'    => $funnel,
			'hit_id'    => $hit_id,
			'link'      => $link,
			'coupon'    => $coupon,
			'text'      => $text,
		];
	}

	/**
	 * Background half of handle_event_async(): profile sync, tagging, delivery and wallet credit.
	 *
	 * This is where the hit is settled. Whatever ManyChat's API answers here is what the row
	 * records, so an operator looking at the Delivery column is reading the result of an actual
	 * send rather than the fact that a webhook once fired.
	 */
	public function followup( int $hit_id ): void {
		$hit = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'ig_funnel_hits' ) . ' WHERE id = %d', $hit_id );
		if ( ! $hit ) {
			return;
		}
		$funnel = $this->get( (int) $hit['funnel_id'] );
		if ( ! $funnel ) {
			return;
		}

		// Blocked and already-settled hits have nothing to do here. Re-running a settled hit
		// would be harmless — settle() is conditional — but the API calls would not be.
		if ( (int) $hit['delivered'] === 1 || self::DELIVERY_BLOCKED === (string) $hit['delivery_error'] ) {
			return;
		}

		$manychat_id = (string) $hit['manychat_subscriber_id'];
		if ( '' === $manychat_id ) {
			// Nothing addressable to send to. Recorded rather than dropped silently: this is a
			// wiring mistake in the ManyChat flow (the subscriber id was not mapped into the
			// External Request body) and the operator has to see it to fix it.
			$this->settle( $funnel, $hit_id, false, 'missing_subscriber_id' );
			return;
		}

		$subscriber = $this->subscribers->sync_from_api( $manychat_id, (int) $funnel['tenant_id'], (int) $funnel['account_id'] );
		$row_id     = (int) ( $subscriber['id'] ?? 0 );

		if ( 0 === $row_id ) {
			// The profile sync is best effort; a failure there must not cost the subscriber their
			// DM. Fall back to whatever the webhook already stored.
			$row_id = (int) $hit['subscriber_id'];
		} elseif ( (int) $hit['subscriber_id'] !== $row_id ) {
			$this->db->update( 'ig_funnel_hits', [ 'subscriber_id' => $row_id ], [ 'id' => $hit_id ] );
		}

		$client = $this->client_for( $funnel );

		if ( ! $client->is_configured() ) {
			// The single most common cause of a silent funnel: the account has no ManyChat key,
			// so every send would fail with the same message. Say it once, plainly.
			$this->settle( $funnel, $hit_id, false, 'manychat_key_missing', $row_id );
			return;
		}

		$link = $this->resolve_link( $funnel );
		if ( '' !== (string) $hit['coupon_issued'] ) {
			$link = add_query_arg( 'coupon', rawurlencode( (string) $hit['coupon_issued'] ), $link );
		}

		$text = strtr(
			(string) $funnel['reply_text'],
			[
				'{link}'    => $link,
				'{coupon}'  => (string) $hit['coupon_issued'],
				'{keyword}' => (string) $funnel['keyword'],
			]
		);
		if ( '' === trim( $text ) ) {
			$text = sprintf( /* translators: %s: link */ __( 'Here you go: %s', 'igbz-suite' ), $link );
		}

		$fields = [
			'igbz_link'    => $link,
			'igbz_coupon'  => (string) $hit['coupon_issued'],
			'igbz_message' => $text,
			'igbz_funnel'  => (string) $funnel['name'],
		];

		$client->set_custom_fields( $manychat_id, $fields );

		if ( '' !== (string) $funnel['manychat_tag'] ) {
			$client->add_tag_by_name( $manychat_id, (string) $funnel['manychat_tag'] );
		}

		if ( '' !== (string) $funnel['manychat_flow_ns'] ) {
			// A flow is the authoritative delivery: ManyChat renders the DM from the custom
			// fields we just wrote, and sendFlow tells us whether it started.
			$sent = $client->send_flow( $manychat_id, (string) $funnel['manychat_flow_ns'] );
			$this->settle( $funnel, $hit_id, (bool) $sent['ok'], (bool) $sent['ok'] ? '' : (string) $sent['error'], $row_id, $fields );
		} elseif ( self::DELIVERY_PENDING_INLINE === (string) $hit['delivery_error'] ) {
			// No flow configured, and the reply was already returned inline in the webhook
			// response for ManyChat to render. Sending the same text again over the API would
			// double-DM the subscriber, so the hit is settled as delivered-but-unconfirmed.
			$this->settle( $funnel, $hit_id, true, self::DELIVERY_UNCONFIRMED, $row_id, $fields );
		} else {
			// Reached from retry_failed(), where nothing was ever handed to ManyChat: send it.
			$sent = $client->send_text(
				$manychat_id,
				$text,
				igbz()->settings()->string( 'manychat.button_label', __( 'Open the link', 'igbz-suite' ) ),
				$link
			);
			$this->settle( $funnel, $hit_id, (bool) $sent['ok'], (bool) $sent['ok'] ? '' : (string) $sent['error'], $row_id, $fields );
		}

		do_action( 'igbz_ig_funnel_followup_done', (int) $funnel['id'], $hit_id );
	}

	/**
	 * Has this subscriber already used up the funnel's per-person allowance?
	 *
	 * Counts settled deliveries *and* hits still in flight. Counting only delivered = 1 left a
	 * window the width of the follow-up delay (five seconds) in which the same person could
	 * comment twice and be handed two links — or two single-use coupons — because neither hit had
	 * settled yet. $exclude_hit_id keeps the row we just inserted from counting against itself.
	 *
	 * A hit that *failed* deliberately does not count. The allowance is "how many times this
	 * person may receive the offer", and somebody whose DM never arrived has received nothing, so
	 * commenting again is the obvious thing for them to do and it must work.
	 *
	 * @param array<string,mixed> $funnel
	 */
	private function over_user_limit( array $funnel, string $subscriber_id, int $exclude_hit_id = 0 ): bool {
		$limit = (int) $funnel['per_user_limit'];
		if ( $limit <= 0 || '' === $subscriber_id ) {
			return false;
		}
		$count = (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'ig_funnel_hits' ) . '
			 WHERE funnel_id = %d AND manychat_subscriber_id = %s AND id <> %d
			   AND (delivered = 1 OR delivery_error = %s OR delivery_error = %s)',
			(int) $funnel['id'],
			$subscriber_id,
			$exclude_hit_id,
			self::DELIVERY_PENDING,
			self::DELIVERY_PENDING_INLINE
		);
		return $count >= $limit;
	}

	/**
	 * Write the outcome of one delivery attempt, exactly once.
	 *
	 * The UPDATE is conditional on the row still being unsettled, and the conversion counter,
	 * the wallet credit and the `igbz_ig_funnel_delivered` action all hang off whether that
	 * UPDATE actually changed a row. Two writers can therefore race — the scheduled follow-up and
	 * the hourly retry, say — without the funnel ever counting the same conversion twice or
	 * paying the same reward twice.
	 *
	 * @param array<string,mixed> $funnel
	 * @param array<string,mixed> $fields Custom-field payload handed to the delivered action.
	 */
	private function settle(
		array $funnel,
		int $hit_id,
		bool $delivered,
		string $error,
		int $subscriber_row_id = 0,
		array $fields = []
	): void {
		$table = $this->db->table( 'ig_funnel_hits' );

		if ( ! $delivered ) {
			$this->db->query(
				'UPDATE ' . $table . ' SET delivered = 0, delivery_error = %s WHERE id = %d AND delivered = 0',
				mb_substr( $error, 0, 255 ),
				$hit_id
			);
			$this->logger->warning(
				'manychat',
				'Funnel delivery failed',
				[ 'funnel_id' => (int) $funnel['id'], 'hit_id' => $hit_id, 'error' => $error ]
			);
			return;
		}

		// `delivered` always changes value here (0 -> 1), so a zero row count means somebody else
		// settled this hit first rather than "the write was a no-op".
		$claimed = $this->db->query(
			'UPDATE ' . $table . ' SET delivered = 1, delivery_error = %s WHERE id = %d AND delivered = 0',
			mb_substr( $error, 0, 255 ),
			$hit_id
		);

		if ( $claimed < 1 ) {
			return;
		}

		$this->db->query(
			'UPDATE ' . $this->db->table( 'ig_funnels' ) . ' SET conversions = conversions + 1 WHERE id = %d',
			(int) $funnel['id']
		);

		$this->grant_wallet_credit( $funnel, $subscriber_row_id, $hit_id );

		// FX meter for delivered DMs: charge the funnel's tenant only when the FX module is
		// enabled, and only on a confirmed settlement (the single writer of `delivered`). A
		// short wallet refuses here too — same "no queue" rule as Manus tasks.
		if ( igbz()->has( 'fx.meter' ) ) {
			igbz()->get( 'fx.meter' )->charge_delivery(
				(int) ( $funnel['tenant_id'] ?? 0 ),
				'funnel-hit:' . (int) $hit_id
			);
		}

		if ( self::DELIVERY_UNCONFIRMED === $error ) {
			// Same honesty rule as an unverified publish: the reply went back to ManyChat in the
			// webhook response and almost certainly reached the subscriber, but no API call
			// proved it, so say so instead of reporting a clean delivery.
			$this->logger->warning(
				'manychat',
				'Funnel reply handed to ManyChat but not confirmed by an API call',
				[ 'funnel_id' => (int) $funnel['id'], 'hit_id' => $hit_id ]
			);
			do_action( 'igbz_ig_funnel_delivered_unconfirmed', (int) $funnel['id'], $hit_id );
		}

		do_action( 'igbz_ig_funnel_delivered', (int) $funnel['id'], $hit_id, $fields );
	}

	/**
	 * Resolve the funnel target and push it to the subscriber through ManyChat.
	 *
	 * @param array<string,mixed> $funnel
	 * @return array<string,mixed> fields that can be mapped back into ManyChat custom fields
	 */
	public function deliver( array $funnel, int $hit_id, string $manychat_subscriber_id, int $subscriber_row_id = 0 ): array {
		$link   = $this->resolve_link( $funnel );
		$coupon = '';

		if ( self::TARGET_COUPON === (string) $funnel['target_type'] ) {
			$coupon = $this->issue_coupon( $funnel, $manychat_subscriber_id );
			if ( '' !== $coupon ) {
				$link = add_query_arg( 'coupon', rawurlencode( $coupon ), $link ?: wc_get_cart_url() );
			}
		}

		$text = strtr(
			(string) $funnel['reply_text'],
			[
				'{link}'    => $link,
				'{coupon}'  => $coupon,
				'{keyword}' => (string) $funnel['keyword'],
			]
		);
		if ( '' === trim( $text ) ) {
			$text = sprintf( /* translators: %s: link */ __( 'Here you go: %s', 'igbz-suite' ), $link );
		}

		$fields = [
			'igbz_link'    => $link,
			'igbz_coupon'  => $coupon,
			'igbz_message' => $text,
			'igbz_funnel'  => (string) $funnel['name'],
		];

		$delivered = false;
		$error     = '';

		if ( '' === $manychat_subscriber_id ) {
			$error = 'missing_subscriber_id';
		} else {
			$client = $this->client_for( $funnel );

			if ( ! $client->is_configured() ) {
				$error = 'manychat_key_missing';
			} else {
				$client->set_custom_fields( $manychat_subscriber_id, $fields );

				if ( '' !== (string) $funnel['manychat_tag'] ) {
					$client->add_tag_by_name( $manychat_subscriber_id, (string) $funnel['manychat_tag'] );
				}

				if ( '' !== (string) $funnel['manychat_flow_ns'] ) {
					$sent      = $client->send_flow( $manychat_subscriber_id, (string) $funnel['manychat_flow_ns'] );
					$delivered = (bool) $sent['ok'];
					$error     = (string) $sent['error'];
				} else {
					$sent      = $client->send_text(
						$manychat_subscriber_id,
						$text,
						igbz()->settings()->string( 'manychat.button_label', __( 'Open the link', 'igbz-suite' ) ),
						$link
					);
					$delivered = (bool) $sent['ok'];
					$error     = (string) $sent['error'];
				}
			}
		}

		if ( '' !== $coupon ) {
			$this->db->update( 'ig_funnel_hits', [ 'coupon_issued' => $coupon ], [ 'id' => $hit_id ] );
		}

		// settle() owns `delivered`, the conversion counter, the wallet credit and the action, so
		// this path and followup() can never disagree about what a delivery is.
		$this->settle( $funnel, $hit_id, $delivered, $delivered ? '' : $error, $subscriber_row_id, $fields );

		return $fields;
	}

	/** @param array<string,mixed> $funnel */
	public function resolve_link( array $funnel ): string {
		if ( self::TARGET_PRODUCT === (string) $funnel['target_type'] && (int) $funnel['product_id'] > 0 ) {
			$permalink = get_permalink( (int) $funnel['product_id'] );
			if ( $permalink ) {
				return add_query_arg( [ 'utm_source' => 'instagram', 'utm_medium' => 'dm', 'utm_campaign' => rawurlencode( (string) $funnel['keyword'] ) ], $permalink );
			}
		}

		$url = (string) $funnel['target_url'];
		if ( '' === $url ) {
			return home_url( '/' );
		}

		return add_query_arg( [ 'utm_source' => 'instagram', 'utm_medium' => 'dm', 'utm_campaign' => rawurlencode( (string) $funnel['keyword'] ) ], $url );
	}

	/**
	 * Static code if the funnel names one, otherwise a single-use WooCommerce coupon cloned from
	 * the template coupon.
	 *
	 * @param array<string,mixed> $funnel
	 */
	public function issue_coupon( array $funnel, string $manychat_subscriber_id ): string {
		$template = (string) $funnel['coupon_code'];
		if ( '' === $template || ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
			return $template;
		}

		if ( ! igbz()->settings()->bool( 'instagram.unique_coupons', true ) ) {
			return $template;
		}

		$template_id = wc_get_coupon_id_by_code( $template );
		if ( ! $template_id ) {
			return $template;
		}

		$source = new \WC_Coupon( $template_id );
		$code   = strtolower( $template . '-' . substr( Crypto::token( 4 ), 0, 6 ) );

		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( $source->get_discount_type() );
		$coupon->set_amount( $source->get_amount() );
		$coupon->set_individual_use( $source->get_individual_use() );
		$coupon->set_product_ids( $source->get_product_ids() );
		$coupon->set_excluded_product_ids( $source->get_excluded_product_ids() );
		$coupon->set_product_categories( $source->get_product_categories() );
		$coupon->set_minimum_amount( $source->get_minimum_amount() );
		$coupon->set_maximum_amount( $source->get_maximum_amount() );
		$coupon->set_free_shipping( $source->get_free_shipping() );
		$coupon->set_usage_limit( 1 );
		$coupon->set_date_expires( time() + igbz()->settings()->int( 'instagram.coupon_ttl_days', 7 ) * DAY_IN_SECONDS );
		$coupon->set_description( sprintf( 'IGBZ funnel %s / %s', (string) $funnel['name'], $manychat_subscriber_id ) );
		$coupon->save();

		return $code;
	}

	/**
	 * Credit the funnel reward.
	 *
	 * The reason is REASON_IG_REWARD, not REASON_COMMISSION. They are different kinds of money and
	 * the customer reads the difference: an affiliate commission is earned by referring a sale and
	 * is owed to a registered affiliate, while this is a promotional reward for commenting on a
	 * post. Filing the second under the first put "Affiliate commission" on the statement of
	 * shoppers who had never joined the affiliate programme, and made the two indistinguishable to
	 * anyone totalling the ledger by reason.
	 *
	 * Changing the reason cannot double-pay an existing reward. The ledger's idempotency key is
	 * (tenant, user, reason, reference_code), so in principle a row already written as
	 * `affiliate_commission` + `ig_funnel:<hit>` would no longer collide with the new reason — but
	 * this method is only ever reached from settle(), which claims the hit with a conditional
	 * `UPDATE ... WHERE delivered = 0` and returns early when that claim fails. A hit that was
	 * already paid is never re-settled, so the credit is never re-attempted.
	 *
	 * @param array<string,mixed> $funnel
	 */
	private function grant_wallet_credit( array $funnel, int $subscriber_row_id, int $hit_id ): void {
		$amount = (float) $funnel['grant_wallet_credit'];
		if ( $amount <= 0 || $subscriber_row_id <= 0 ) {
			return;
		}

		$user_id = $this->subscribers->maybe_link_user( $subscriber_row_id );
		if ( $user_id <= 0 ) {
			return;
		}

		$this->wallet->credit(
			$user_id,
			$amount,
			WalletService::REASON_IG_REWARD,
			'ig_funnel:' . $hit_id,
			[ 'funnel_id' => (int) $funnel['id'] ],
			(int) $funnel['tenant_id'],
			0,
			sprintf( /* translators: %s: funnel name */ __( 'Instagram funnel reward: %s', 'igbz-suite' ), (string) $funnel['name'] )
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{matched:bool,duplicate:bool,funnel_id:int,hit_id:int,message:string,payload:array<string,mixed>}
	 */
	private function result( bool $matched, bool $duplicate, int $funnel_id, int $hit_id, string $message, array $payload = [] ): array {
		return [
			'matched'   => $matched,
			'duplicate' => $duplicate,
			'funnel_id' => $funnel_id,
			'hit_id'    => $hit_id,
			'message'   => $message,
			'payload'   => $payload,
		];
	}

	// --------------------------------------------------------------- stats

	/** @return array{hits:int,conversions:int,subscribers:int,rate:float} */
	public function stats( int $funnel_id ): array {
		$row = $this->db->row(
			'SELECT hits, conversions FROM ' . $this->db->table( 'ig_funnels' ) . ' WHERE id = %d',
			$funnel_id
		);
		$hits        = (int) ( $row['hits'] ?? 0 );
		$conversions = (int) ( $row['conversions'] ?? 0 );

		return [
			'hits'        => $hits,
			'conversions' => $conversions,
			'subscribers' => (int) $this->db->scalar(
				'SELECT COUNT(DISTINCT manychat_subscriber_id) FROM ' . $this->db->table( 'ig_funnel_hits' ) . ' WHERE funnel_id = %d',
				$funnel_id
			),
			'rate'        => $hits > 0 ? round( $conversions / $hits * 100, 2 ) : 0.0,
		];
	}

	/** @return array<int,array<string,mixed>> */
	public function hits( int $funnel_id, int $limit = 50, int $offset = 0 ): array {
		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_funnel_hits' ) . ' WHERE funnel_id = %d ORDER BY id DESC LIMIT %d OFFSET %d',
			$funnel_id,
			$limit,
			$offset
		);
	}

	/**
	 * Re-attempt undelivered hits, from cron or from the button on the funnel screen.
	 *
	 * Two kinds of row qualify. A row with a real error message failed a send and is retried
	 * outright. A row still marked pending is one whose scheduled follow-up never ran — WP-Cron
	 * only fires on traffic, so on a quiet site the +5s event can simply be missed — and is
	 * picked up once it is older than the grace period, which keeps the retry from racing a
	 * follow-up that is merely a few seconds late.
	 *
	 * Excluded: rows blocked by the per-user cap (retrying is exactly what the cap forbids) and
	 * rows carrying the unconfirmed marker, which are already delivered.
	 */
	public function retry_failed( int $limit = 20 ): int {
		$now  = time();
		$rows = $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_funnel_hits' ) . '
			 WHERE delivered = 0
			   AND delivery_error <> %s
			   AND delivery_error <> %s
			   AND created_at >= %s
			   AND ( delivery_error NOT IN ( %s, %s ) OR created_at <= %s )
			 ORDER BY id DESC LIMIT %d',
			'',
			self::DELIVERY_BLOCKED,
			gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS ),
			self::DELIVERY_PENDING,
			self::DELIVERY_PENDING_INLINE,
			gmdate( 'Y-m-d H:i:s', $now - self::FOLLOWUP_GRACE ),
			$limit
		);

		$done = 0;
		foreach ( $rows as $hit ) {
			$funnel = $this->get( (int) $hit['funnel_id'] );
			if ( ! $funnel ) {
				continue;
			}
			// followup() rather than deliver(): it knows about the inline-reply case, so a hit
			// whose text ManyChat already rendered is settled instead of being DMed twice.
			$this->followup( (int) $hit['id'] );
			$done++;
		}

		return $done;
	}

	/**
	 * Undelivered hits, split by why. Feeds the admin health card.
	 *
	 * @return array{pending:int,failed:int,blocked:int,unconfirmed:int}
	 */
	public function delivery_backlog( int $since_seconds = DAY_IN_SECONDS ): array {
		$table = $this->db->table( 'ig_funnel_hits' );
		$since = gmdate( 'Y-m-d H:i:s', time() - $since_seconds );

		$rows = $this->db->results(
			'SELECT delivered, delivery_error, COUNT(*) AS total FROM ' . $table . '
			 WHERE created_at >= %s GROUP BY delivered, delivery_error',
			$since
		);

		$out = [ 'pending' => 0, 'failed' => 0, 'blocked' => 0, 'unconfirmed' => 0 ];

		foreach ( $rows as $row ) {
			$total = (int) $row['total'];
			$error = (string) $row['delivery_error'];

			if ( 1 === (int) $row['delivered'] ) {
				if ( self::DELIVERY_UNCONFIRMED === $error ) {
					$out['unconfirmed'] += $total;
				}
				continue;
			}

			if ( self::DELIVERY_BLOCKED === $error ) {
				$out['blocked'] += $total;
			} elseif ( in_array( $error, self::PENDING_STATES, true ) ) {
				$out['pending'] += $total;
			} else {
				$out['failed'] += $total;
			}
		}

		return $out;
	}
}
