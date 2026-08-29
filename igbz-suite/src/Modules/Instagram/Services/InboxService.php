<?php
namespace IGBZ\Suite\Modules\Instagram\Services;

use IGBZ\Suite\Modules\Instagram\Gateways\ZernioClient;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;
use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 51 — the Zernio inbox and the comment-to-DM pipeline (ADR-0004 §6).
 *
 * Zernio webhooks are team-level, so one install receives events for every
 * managed account. Ownership is decided server-side: the event's account id is
 * mapped through `ig_zernio_profiles` to a profile and a tenant, and an event
 * for an account we do not manage is refused before anything is stored. The
 * signature (HMAC over payload+timestamp with the profile's own secret, inside
 * the 300s replay window) is checked against that tenant — a signature that
 * belongs to another store never passes.
 *
 * The decision path is entirely ours, in this database: capture -> dedupe ->
 * opt-out check -> backend rule -> rate limit -> approval -> delivery. No
 * external automation is given business authority, and every step that can be
 * retried is idempotent: the delivery ledger carries a stable
 * `idempotency_key` (`inbox:<id>`) so the provider can never receive the same
 * answer twice, and the capture table's unique event id makes webhook retries
 * no-ops.
 *
 * Instagram's 24-hour window: Zernio will not open a cold DM, so a comment
 * counts as the user's engagement and the DM goes to the user of our own
 * account only. The live semantics of the send endpoints stay with
 * PV-ZERNIO-* — the paths are settings-driven like the rest of the adapter.
 */
final class InboxService {

	public const SOURCE_COMMENT = 'comment';
	public const SOURCE_DM      = 'dm';
	public const SOURCE_MENTION = 'mention';
	public const SOURCE_ACCOUNT = 'account';
	public const SOURCE_OTHER   = 'other';

	public const STATUS_RECEIVED         = 'received';
	public const STATUS_PROCESSED        = 'processed';
	public const STATUS_IGNORED          = 'ignored';
	public const STATUS_SKIPPED_OPTOUT   = 'skipped_optout';
	public const STATUS_SKIPPED_RLIMIT   = 'skipped_rate_limit';
	public const STATUS_PENDING_APPROVAL = 'pending_approval';
	public const STATUS_QUEUED           = 'queued';
	public const STATUS_SENT             = 'sent';
	public const STATUS_FAILED           = 'failed';

	public const ACTION_IGNORE = 'ignore';
	public const ACTION_DM     = 'dm';
	public const ACTION_REPLY  = 'reply';

	public const STATE_QUEUED            = 'queued';
	public const STATE_PENDING_APPROVAL  = 'pending_approval';
	public const STATE_SENT              = 'sent';
	public const STATE_FAILED            = 'failed';
	public const STATE_REJECTED          = 'rejected';

	public function __construct(
		private Db $db,
		private Logger $logger,
		private ZernioConnectionService $zernio,
		private ZernioClient $client,
		private Settings $settings
	) {}

	// ------------------------------------------------------------- capture

	/**
	 * The webhook entry point.
	 *
	 * @param array<string,string> $headers X-Zernio-Signature, X-Zernio-Timestamp
	 * @return array{ok:bool,status:string,error?:string,id?:int}
	 *         status: received | duplicate | bad_payload | unknown_account |
	 *                 invalid_signature
	 */
	public function handle_webhook( string $raw, array $headers ): array {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return [ 'ok' => false, 'status' => 'bad_payload' ];
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return [ 'ok' => false, 'status' => 'bad_payload' ];
		}

		// 1) ownership — server-side account->profile->tenant mapping.
		$account_id = $this->first_string( $decoded, [
			[ 'data', 'accountId' ],
			[ 'account', 'accountId' ],
			[ 'accountId' ],
		] );
		if ( '' === $account_id ) {
			return [ 'ok' => false, 'status' => 'bad_payload' ];
		}

		$profile = $this->db->row(
			"SELECT p.* FROM " . $this->db->table( 'ig_zernio_profiles' ) . ' p WHERE p.account_id = %s LIMIT 1',
			$account_id
		);
		if ( null === $profile ) {
			// Not our account: refuse without saying which accounts exist.
			$this->logger->warning( 'ig.inbox', 'Inbox event for an unmapped account refused', [ 'account' => $account_id ] );
			return [ 'ok' => false, 'status' => 'unknown_account' ];
		}

		$tenant_id = (int) $profile['tenant_id'];

		// 2) identity — the profile's own secret, inside the replay window.
		$signature = trim( (string) ( $headers['X-Zernio-Signature'] ?? $headers['x-zernio-signature'] ?? '' ) );
		$timestamp = (int) ( $headers['X-Zernio-Timestamp'] ?? $headers['x-zernio-timestamp'] ?? 0 );
		if ( ! $this->zernio->verify_webhook( $tenant_id, $raw, $timestamp, $signature ) ) {
			$this->logger->warning( 'ig.inbox', 'Inbox event failed signature verification', [ 'tenant' => $tenant_id ] );
			return [ 'ok' => false, 'status' => 'invalid_signature' ];
		}

		// 3) capture — the unique (profile, event) pair makes retries no-ops.
		$event_id = (string) (
			$decoded['event_id'] ?? $decoded['eventId']
			?? $decoded['data']['id'] ?? $decoded['id']
			?? ''
		);
		if ( '' === $event_id ) {
			$event_id = hash( 'sha256', $raw );
		}

		$existing = $this->db->row(
			"SELECT id, status FROM " . $this->db->table( 'ig_zernio_inbox' ) . " WHERE profile_id = %d AND event_id = %s LIMIT 1",
			(int) $profile['id'],
			$event_id
		);
		if ( null !== $existing ) {
			return [ 'ok' => true, 'status' => 'duplicate', 'id' => (int) $existing['id'] ];
		}

		$now      = current_time( 'mysql', true );
		$inbox_id = $this->db->insert(
			'ig_zernio_inbox',
			[
				'tenant_id'       => $tenant_id,
				'profile_id'      => (int) $profile['id'],
				'event_id'        => substr( $event_id, 0, 64 ),
				'event'           => substr( (string) ( $decoded['event'] ?? $decoded['type'] ?? '' ), 0, 48 ),
				'source'          => $this->normalize_source( (string) ( $decoded['event'] ?? $decoded['type'] ?? '' ) ),
				'post_id'         => substr( $this->first_string( $decoded, [ [ 'data', 'postId' ], [ 'postId' ], [ 'post', 'id' ] ] ), 0, 64 ),
				'sender_id'       => substr( $this->first_string( $decoded, [ [ 'data', 'sender', 'id' ], [ 'data', 'senderId' ], [ 'senderId' ] ] ), 0, 64 ),
				'sender_username' => substr( $this->first_string( $decoded, [ [ 'data', 'sender', 'username' ], [ 'senderUsername' ], [ 'username' ] ] ), 0, 128 ),
				'text'            => $this->first_string( $decoded, [ [ 'data', 'text' ], [ 'data', 'content' ], [ 'text' ], [ 'content' ] ] ),
				'occurred_at'     => $this->to_mysql_datetime( (string) ( $decoded['data']['timestamp'] ?? $decoded['timestamp'] ?? '' ) ),
				'received_at'     => $now,
				'status'          => self::STATUS_RECEIVED,
			]
		);

		// 4) decide + deliver. Local work only, except the final provider call.
		$this->process_event( (int) $inbox_id, $tenant_id, $profile );

		return [ 'ok' => true, 'status' => 'received', 'id' => (int) $inbox_id ];
	}

	/**
	 * The decision pipeline for one captured event.
	 *
	 * @param array<string,mixed> $profile the owning ig_zernio_profiles row
	 */
	private function process_event( int $inbox_id, int $tenant_id, array $profile ): void {
		$row = $this->db->row(
			"SELECT * FROM " . $this->db->table( 'ig_zernio_inbox' ) . ' WHERE id = %d LIMIT 1',
			$inbox_id
		);
		if ( null === $row ) {
			return;
		}

		$source = (string) $row['source'];
		if ( in_array( $source, [ self::SOURCE_ACCOUNT, self::SOURCE_OTHER ], true ) ) {
			// Account lifecycle and unknown events are captured, never answered.
			$this->set_status( $inbox_id, self::STATUS_IGNORED );
			return;
		}

		// 1) opt-out — a user who asked to stop is never messaged again.
		$text = (string) $row['text'];
		$sender_id = (string) $row['sender_id'];

		if ( self::SOURCE_DM === $source && $this->looks_like_opt_out( $text ) ) {
			$this->add_opt_out( $tenant_id, $sender_id, (string) $row['sender_username'], 'detected from the user' );
			$this->set_status( $inbox_id, self::STATUS_SKIPPED_OPTOUT );
			return;
		}
		if ( '' !== $sender_id && $this->is_opted_out( $tenant_id, $sender_id ) ) {
			$this->set_status( $inbox_id, self::STATUS_SKIPPED_OPTOUT );
			return;
		}

		// 2) rules — the first active rule that matches (priority order).
		$rule = $this->match_rule( $tenant_id, $source, $text );
		if ( null === $rule || self::ACTION_IGNORE === $rule['action'] ) {
			$this->set_status( $inbox_id, self::STATUS_IGNORED );
			return;
		}

		$kind = (string) $rule['action'];
		$template = (string) $rule['template'];
		if ( '' === $template ) {
			$this->set_status( $inbox_id, self::STATUS_IGNORED );
			return;
		}
		$reply = str_replace( [ '{username}', '{post_id}' ], [ (string) $row['sender_username'], (string) $row['post_id'] ], $template );
		$reply = trim( $reply );
		if ( '' === $reply ) {
			$this->set_status( $inbox_id, self::STATUS_IGNORED );
			return;
		}

		// 3) rate limit — per sender and per tenant, rolling hour.
		$since = gmdate( 'Y-m-d H:i:s', time() - 3600 );

		$tenant_cap   = max( 0, (int) $this->settings->int( 'igbz.inbox_rate_limit_tenant', 20 ) );
		$sender_cap   = max( 0, (int) $this->settings->int( 'igbz.inbox_rate_limit_sender', 3 ) );
		$tenant_count = (int) $this->db->scalar(
			"SELECT COUNT(*) FROM " . $this->db->table( 'ig_inbox_actions' ) . " WHERE tenant_id = %d AND state IN ('" . self::STATE_SENT . "','" . self::STATE_QUEUED . "','" . self::STATE_PENDING_APPROVAL . "') AND created_at >= %s",
			$tenant_id,
			$since
		);
		if ( $tenant_count >= $tenant_cap ) {
			$this->logger->warning( 'ig.inbox', 'Inbox reply skipped: tenant rate limit', [ 'tenant' => $tenant_id ] );
			$this->set_status( $inbox_id, self::STATUS_SKIPPED_RLIMIT );
			return;
		}
		if ( '' !== $sender_id && $sender_cap > 0 ) {
			$sender_count = (int) $this->db->scalar(
				"SELECT COUNT(*) FROM " . $this->db->table( 'ig_inbox_actions' ) . ' a
					JOIN ' . $this->db->table( 'ig_zernio_inbox' ) . ' i ON i.id = a.inbox_id
					WHERE a.tenant_id = %d AND a.state IN (%s) AND a.created_at >= %s AND i.sender_id = %s',
				$tenant_id,
				$this->quoted_states(),
				$since,
				$sender_id
			);
			if ( $sender_count >= $sender_cap ) {
				$this->logger->warning( 'ig.inbox', 'Inbox reply skipped: sender rate limit', [ 'tenant' => $tenant_id ] );
				$this->set_status( $inbox_id, self::STATUS_SKIPPED_RLIMIT );
				return;
			}
		}

		// 4) the delivery row — one per decision, stable idempotency key.
		$target = self::ACTION_DM === $kind ? $sender_id : (string) $row['event_id'];
		$action_id = $this->db->insert(
			'ig_inbox_actions',
			[
				'tenant_id'       => $tenant_id,
				'inbox_id'        => $inbox_id,
				'rule_id'         => (int) $rule['id'],
				'kind'            => $kind,
				'target'          => substr( $target, 0, 128 ),
				'text'            => $reply,
				'idempotency_key' => 'inbox:' . $inbox_id,
				'state'           => self::STATE_QUEUED,
				'created_at'      => current_time( 'mysql', true ),
			]
		);

		// 5) approval — off by default: a human approves before anything is sent.
		$auto_approve = 1 === (int) $this->settings->int( 'igbz.inbox_auto_approve', 0 );
		if ( ! $auto_approve ) {
			$this->db->update( 'ig_inbox_actions', [ 'state' => self::STATE_PENDING_APPROVAL ], [ 'id' => (int) $action_id ] );
			$this->set_status( $inbox_id, self::STATUS_PENDING_APPROVAL );
			$this->logger->info( 'ig.inbox', 'Inbox reply awaiting approval', [ 'tenant' => $tenant_id, 'action' => (int) $action_id ] );
			return;
		}

		$this->deliver( (int) $action_id, $tenant_id, $profile );
	}

	// ------------------------------------------------------------- approval

	/**
	 * Human approval: deliver the pending action (same idempotency key as any
	 * retry — the provider can never see the answer twice).
	 */
	public function approve( int $tenant_id, int $action_id ): array {
		$action = $this->db->row(
			"SELECT * FROM " . $this->db->table( 'ig_inbox_actions' ) . ' WHERE id = %d AND tenant_id = %d LIMIT 1',
			$action_id,
			$tenant_id
		);
		if ( null === $action || self::STATE_PENDING_APPROVAL !== (string) $action['state'] ) {
			return [ 'ok' => false, 'error' => 'not_pending' ];
		}

		$profile = $this->db->row(
			"SELECT p.* FROM " . $this->db->table( 'ig_zernio_inbox' ) . ' i
				JOIN ' . $this->db->table( 'ig_zernio_profiles' ) . ' p ON p.id = i.profile_id
				WHERE i.id = %d AND p.tenant_id = %d LIMIT 1',
			(int) $action['inbox_id'],
			$tenant_id
		);
		if ( null === $profile ) {
			return [ 'ok' => false, 'error' => 'no_profile' ];
		}

		$this->deliver( $action_id, $tenant_id, $profile );

		return [ 'ok' => true, 'action_id' => $action_id ];
	}

	/** Human rejection: the decision is final and auditable, never delivered. */
	public function reject( int $tenant_id, int $action_id ): array {
		$action = $this->db->row(
			"SELECT state FROM " . $this->db->table( 'ig_inbox_actions' ) . ' WHERE id = %d AND tenant_id = %d LIMIT 1',
			$action_id,
			$tenant_id
		);
		if ( null === $action || self::STATE_PENDING_APPROVAL !== (string) $action['state'] ) {
			return [ 'ok' => false, 'error' => 'not_pending' ];
		}

		$this->db->update( 'ig_inbox_actions', [ 'state' => self::STATE_REJECTED ], [ 'id' => $action_id ] );
		$this->logger->info( 'ig.inbox', 'Inbox reply rejected by the operator', [ 'tenant' => $tenant_id, 'action' => $action_id ] );

		return [ 'ok' => true, 'action_id' => $action_id ];
	}

	/**
	 * The provider call. Never throws: failure lands in the ledger with its
	 * error and stays retryable by the operator via approve() — the idempotency
	 * key makes a redelivery safe.
	 */
	private function deliver( int $action_id, int $tenant_id, array $profile ): void {
		$action = $this->db->row(
			"SELECT * FROM " . $this->db->table( 'ig_inbox_actions' ) . ' WHERE id = %d LIMIT 1',
			$action_id
		);
		if ( null === $action ) {
			return;
		}

		$key = $this->zernio->key_for( $tenant_id );
		$result = self::ACTION_DM === (string) $action['kind']
			? $this->client->send_direct_message(
					$key,
					(string) $profile['account_id'],
					(string) $action['target'],
					[ 'content' => (string) $action['text'], 'idempotency_key' => (string) $action['idempotency_key'] ]
				)
			: $this->client->reply_to_comment(
					$key,
					(string) $profile['account_id'],
					(string) $action['target'],
					(string) $action['text'],
					(string) $action['idempotency_key']
				);

		if ( ! empty( $result['ok'] ) ) {
			$this->db->update(
				'ig_inbox_actions',
				[
					'state'        => self::STATE_SENT,
					'provider_ref' => substr( (string) ( $result['message_id'] ?? $result['comment_id'] ?? '' ), 0, 64 ),
					'error'        => '',
					'delivered_at' => current_time( 'mysql', true ),
				],
				[ 'id' => $action_id ]
			);
			$this->set_status( (int) $action['inbox_id'], self::STATUS_SENT );
			$this->logger->info( 'ig.inbox', 'Inbox reply delivered', [ 'tenant' => $tenant_id, 'action' => $action_id, 'kind' => (string) $action['kind'] ] );
		} else {
			$error = substr( (string) ( $result['error'] ?? 'provider_error' ), 0, 255 );
			$this->db->update(
				'ig_inbox_actions',
				[ 'state' => self::STATE_FAILED, 'error' => $error ],
				[ 'id' => $action_id ]
			);
			$this->set_status( (int) $action['inbox_id'], self::STATUS_FAILED );
			$this->logger->error( 'ig.inbox', 'Inbox reply delivery failed', [ 'tenant' => $tenant_id, 'action' => $action_id, 'error' => $error ] );
		}
	}

	// ------------------------------------------------------------- opt-out

	/** Register an opt-out (idempotent per tenant+sender). */
	public function add_opt_out( int $tenant_id, string $sender_id, string $username, string $note = '' ): void {
		if ( '' === $sender_id ) {
			return;
		}
		$existing = $this->db->row(
			"SELECT id FROM " . $this->db->table( 'ig_inbox_optouts' ) . ' WHERE tenant_id = %d AND sender_id = %s LIMIT 1',
			$tenant_id,
			$sender_id
		);
		if ( null !== $existing ) {
			return;
		}
		$this->db->insert(
			'ig_inbox_optouts',
			[
				'tenant_id'       => $tenant_id,
				'sender_id'       => $sender_id,
				'sender_username' => substr( $username, 0, 128 ),
				'note'            => substr( $note, 0, 255 ),
				'created_at'      => current_time( 'mysql', true ),
			]
		);
		$this->logger->info( 'ig.inbox', 'Inbox opt-out registered', [ 'tenant' => $tenant_id ] );
	}

	public function is_opted_out( int $tenant_id, string $sender_id ): bool {
		if ( '' === $sender_id ) {
			return false;
		}
		return (bool) $this->db->scalar(
			"SELECT id FROM " . $this->db->table( 'ig_inbox_optouts' ) . ' WHERE tenant_id = %d AND sender_id = %s LIMIT 1',
			$tenant_id,
			$sender_id
		);
	}

	/**
	 * A user saying stop, exactly. Normalized exact match against the
	 * configured phrases — fuzzy matching would silence real questions.
	 */
	private function looks_like_opt_out( string $text ): bool {
		$normalized = mb_strtolower( trim( $text ) );
		if ( '' === $normalized ) {
			return false;
		}
		$phrases = array_map( 'trim', explode( ',', $this->settings->string( 'igbz.inbox_optout_phrases', 'نه,نه,خیر,نه دیگه پیام ندهید,stop,no' ) ) );
		foreach ( $phrases as $phrase ) {
			if ( '' !== $phrase && mb_strtolower( $phrase ) === $normalized ) {
				return true;
			}
		}

		return false;
	}

	// ------------------------------------------------------------- rules

	/**
	 * First active rule in priority order whose source matches and whose
	 * keyword (empty = any) is contained in the text.
	 */
	private function match_rule( int $tenant_id, string $source, string $text ): ?array {
		$rules = $this->db->results(
			"SELECT * FROM " . $this->db->table( 'ig_inbox_rules' ) . ' WHERE tenant_id = %d AND active = 1 ORDER BY priority ASC, id ASC',
			$tenant_id
		);
		$text_lower = mb_strtolower( $text );
		foreach ( $rules as $rule ) {
			$rule_source = (string) $rule['source'];
			if ( 'any' !== $rule_source && $rule_source !== $source ) {
				continue;
			}
			$keyword = trim( (string) $rule['keyword'] );
			if ( '' !== $keyword && false === mb_strpos( $text_lower, mb_strtolower( $keyword ) ) ) {
				continue;
			}

			return $rule;
		}

		return null;
	}

	public function create_rule( int $tenant_id, array $data ): array {
		$source = (string) ( $data['source'] ?? 'any' );
		if ( ! in_array( $source, [ 'any', self::SOURCE_COMMENT, self::SOURCE_DM, self::SOURCE_MENTION ], true ) ) {
			return [ 'ok' => false, 'error' => 'bad_source' ];
		}
		$action = (string) ( $data['action'] ?? self::ACTION_IGNORE );
		if ( ! in_array( $action, [ self::ACTION_IGNORE, self::ACTION_DM, self::ACTION_REPLY ], true ) ) {
			return [ 'ok' => false, 'error' => 'bad_action' ];
		}

		$id = $this->db->insert(
			'ig_inbox_rules',
			[
				'tenant_id'  => $tenant_id,
				'name'       => substr( (string) ( $data['name'] ?? 'rule' ), 0, 128 ),
				'source'     => $source,
				'keyword'    => substr( trim( (string) ( $data['keyword'] ?? '' ) ), 0, 128 ),
				'action'     => $action,
				'template'   => (string) ( $data['template'] ?? '' ),
				'priority'   => max( 0, (int) ( $data['priority'] ?? 100 ) ),
				'active'     => isset( $data['active'] ) ? (int) (bool) $data['active'] : 1,
				'created_at' => current_time( 'mysql', true ),
			]
		);

		return [ 'ok' => true, 'rule_id' => (int) $id ];
	}

	public function list_rules( int $tenant_id ): array {
		return $this->db->results(
			"SELECT * FROM " . $this->db->table( 'ig_inbox_rules' ) . ' WHERE tenant_id = %d ORDER BY priority ASC, id ASC',
			$tenant_id
		);
	}

	public function set_rule_active( int $tenant_id, int $rule_id, bool $active ): bool {
		return $this->db->update(
			'ig_inbox_rules',
			[ 'active' => $active ? 1 : 0 ],
			[ 'id' => $rule_id, 'tenant_id' => $tenant_id ]
		) >= 0;
	}

	// ------------------------------------------------------------- listing

	/** The store's recent inbox events, newest first. */
	public function list_events( int $tenant_id, int $limit = 50 ): array {
		return $this->db->results(
			"SELECT * FROM " . $this->db->table( 'ig_zernio_inbox' ) . ' WHERE tenant_id = %d ORDER BY received_at DESC, id DESC LIMIT ' . max( 1, min( 200, $limit ) ),
			$tenant_id
		);
	}

	/** The store's delivery ledger, newest first. */
	public function list_actions( int $tenant_id, int $limit = 50 ): array {
		return $this->db->results(
			"SELECT * FROM " . $this->db->table( 'ig_inbox_actions' ) . ' WHERE tenant_id = %d ORDER BY created_at DESC, id DESC LIMIT ' . max( 1, min( 200, $limit ) ),
			$tenant_id
		);
	}

	/**
	 * Retry a failed delivery (operator action). The idempotency key is the
	 * original one, so a success can never duplicate an earlier attempt.
	 */
	public function retry_failed( int $tenant_id, int $action_id ): array {
		$action = $this->db->row(
			"SELECT * FROM " . $this->db->table( 'ig_inbox_actions' ) . ' WHERE id = %d AND tenant_id = %d LIMIT 1',
			$action_id,
			$tenant_id
		);
		if ( null === $action || self::STATE_FAILED !== (string) $action['state'] ) {
			return [ 'ok' => false, 'error' => 'not_failed' ];
		}
		$profile = $this->db->row(
			"SELECT p.* FROM " . $this->db->table( 'ig_zernio_inbox' ) . ' i
				JOIN ' . $this->db->table( 'ig_zernio_profiles' ) . ' p ON p.id = i.profile_id
				WHERE i.id = %d AND p.tenant_id = %d LIMIT 1',
			(int) $action['inbox_id'],
			$tenant_id
		);
		if ( null === $profile ) {
			return [ 'ok' => false, 'error' => 'no_profile' ];
		}
		$this->deliver( $action_id, $tenant_id, $profile );

		return [ 'ok' => true, 'action_id' => $action_id ];
	}

	// ------------------------------------------------------------- helpers

	private function set_status( int $inbox_id, string $status ): void {
		$this->db->update( 'ig_zernio_inbox', [ 'status' => $status ], [ 'id' => $inbox_id ] );
	}

	private function quoted_states(): string {
		return "'" . self::STATE_SENT . "','" . self::STATE_QUEUED . "','" . self::STATE_PENDING_APPROVAL . "'";
	}

	private function normalize_source( string $event ): string {
		$event = strtolower( $event );
		if ( str_contains( $event, 'comment' ) ) {
			return self::SOURCE_COMMENT;
		}
		if ( str_contains( $event, 'message' ) ) {
			return self::SOURCE_DM;
		}
		if ( str_contains( $event, 'mention' ) ) {
			return self::SOURCE_MENTION;
		}
		if ( str_contains( $event, 'account' ) ) {
			return self::SOURCE_ACCOUNT;
		}

		return self::SOURCE_OTHER;
	}

	/**
	 * @param array<int,string[]> $paths
	 */
	private function first_string( array $decoded, array $paths ): string {
		foreach ( $paths as $path ) {
			$value = $decoded;
			foreach ( $path as $key ) {
				if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
					$value = null;
					break;
				}
				$value = $value[ $key ];
			}
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}

		return '';
	}

	private function to_mysql_datetime( string $iso ): string {
		$ts = strtotime( $iso );
		if ( false === $ts ) {
			return current_time( 'mysql', true );
		}

		return gmdate( 'Y-m-d H:i:s', $ts );
	}
}
