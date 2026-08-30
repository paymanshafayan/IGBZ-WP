<?php
/**
 * Phase 59 — publishing, campaigns and policy changes ride the phase-57 approval
 * queue, with an authority ceiling per kind and a provable outcome.
 *
 * Authority ceiling (ADR-0004 §7): the three content categories (viral/growth,
 * trust, lifestyle) may ride one batch approval of at most BATCH_LIMIT rows; a
 * sales/campaign post needs its own explicit request with a mandatory reason;
 * a customer-facing campaign blast is capped in recipients; a policy change of
 * the AI gates is critical and compensable.
 *
 * The three executors stop at the first refusal and record what actually happened
 * in the row's metadata (`record_outcome`), so the audit shows the receipt, not
 * just "ok". `run()` re-verifies the payload hash before executing — whatever an
 * approver clicked, an edited row never runs.
 */

declare( strict_types = 1 );

namespace IGBZ\Suite\Modules\Pado\Services;

/**
 * Direct access guard (phase 75 audit): autoloader-only file, never a URL target.
 */
defined( 'ABSPATH' ) || exit;

use IGBZ\Suite\Modules\Instagram\Services\ContentPublishService;
use IGBZ\Suite\Modules\Instagram\Vip\VipMessageService;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;
use IGBZ\Suite\Support\Settings;

class ContentOperationsService {

	public const KIND_PUBLISH_VIRAL     = 'ig_publish_viral';
	public const KIND_PUBLISH_TRUST     = 'ig_publish_trust';
	public const KIND_PUBLISH_LIFESTYLE = 'ig_publish_lifestyle';
	public const KIND_PUBLISH_CAMPAIGN  = 'ig_publish_campaign';
	public const KIND_CAMPAIGN_SEND     = 'campaign_send';
	public const KIND_POLICY_CHANGE     = 'policy_change';

	/** The AI-policy keys a policy change may touch — closed list, backend-enforced. */
	public const POLICY_KEYS = [
		'pado.deepinfra.enabled'           => 'bool',
		'pado.deepinfra.daily_token_budget' => 'int',
	];

	private const CATEGORIES = [
		'viral'     => self::KIND_PUBLISH_VIRAL,
		'trust'     => self::KIND_PUBLISH_TRUST,
		'lifestyle' => self::KIND_PUBLISH_LIFESTYLE,
		'campaign'  => self::KIND_PUBLISH_CAMPAIGN,
	];

	public const BATCH_LIMIT         = 50;
	public const MAX_CAMPAIGN_RECIPIENTS = 200;

	public const CAPABILITY = 'manage_tenant';

	public function __construct(
		private Db $db,
		private Logger $logger,
		private ApprovalRequestService $approvals,
		private ContentPublishService $publisher,
		private VipMessageService $messages,
		private Settings $settings
	) {}

	// --------------------------------------------------------------- requests

	/**
	 * Queue a publish of one or many content rows. The three content categories may
	 * batch (one approval, up to BATCH_LIMIT rows); a sales/campaign post is exactly
	 * one row and requires a reason.
	 *
	 * @param array<int,int> $content_ids
	 * @return array{ok:bool,id:int,error:string,duplicate:bool}
	 */
	public function request_publish( int $tenant_id, array $content_ids, string $category, int $requested_by, string $reason = '', ?string $when_iso = null ): array {
		$kind = self::CATEGORIES[ $category ] ?? '';
		if ( '' === $kind ) {
			return [ 'ok' => false, 'id' => 0, 'error' => 'invalid_category', 'duplicate' => false ];
		}

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $content_ids ), static fn ( int $id ): bool => $id > 0 ) ) );
		if ( ! $ids ) {
			return [ 'ok' => false, 'id' => 0, 'error' => 'invalid_request', 'duplicate' => false ];
		}

		if ( self::KIND_PUBLISH_CAMPAIGN === $kind ) {
			// Sales content carries money: explicit, single, and justified.
			if ( count( $ids ) > 1 || '' === trim( $reason ) ) {
				return [ 'ok' => false, 'id' => 0, 'error' => 'campaign_needs_single_row_and_reason', 'duplicate' => false ];
			}
		} elseif ( count( $ids ) > self::BATCH_LIMIT ) {
			return [ 'ok' => false, 'id' => 0, 'error' => 'batch_too_large', 'duplicate' => false ];
		}

		sort( $ids );

		return $this->approvals->enqueue( [
			'tenant_id'       => $tenant_id,
			'kind'            => $kind,
			'title'           => sprintf( 'انتشار %s (%d محتوا)', $this->category_label( $category ), count( $ids ) ),
			'reason'          => $reason,
			'payload'         => [
				'content_ids' => $ids,
				'category'    => $category,
				'when'        => $when_iso,
			],
			'capability'      => self::CAPABILITY,
			'idempotency_key' => 'publish:' . hash( 'sha256', $kind . '|' . implode( ',', $ids ) . '|' . (string) $when_iso ),
			'impact'          => self::KIND_PUBLISH_CAMPAIGN === $kind ? ApprovalRequestService::IMPACT_HIGH : ApprovalRequestService::IMPACT_MEDIUM,
			'requested_by'    => $requested_by,
		] );
	}

	/**
	 * Queue a customer-facing campaign blast: one message to the active VIP members,
	 * capped at MAX_CAMPAIGN_RECIPIENTS. The recipient list rides the executor, not
	 * the payload — the queue stores intent, not the membership roll.
	 *
	 * @return array{ok:bool,id:int,error:string,duplicate:bool}
	 */
	public function request_campaign_send( int $tenant_id, string $title, string $body, int $requested_by, string $reason = '' ): array {
		$title = trim( $title );
		$body  = trim( $body );
		if ( '' === $title || '' === $body || mb_strlen( $body ) > 5000 ) {
			return [ 'ok' => false, 'id' => 0, 'error' => 'invalid_request', 'duplicate' => false ];
		}

		return $this->approvals->enqueue( [
			'tenant_id'       => $tenant_id,
			'kind'            => self::KIND_CAMPAIGN_SEND,
			'title'           => sprintf( 'ارسال کمپین: %s', mb_substr( $title, 0, 120 ) ),
			'reason'          => $reason,
			'payload'         => [
				'title' => $title,
				'body'  => $body,
			],
			'capability'      => self::CAPABILITY,
			'idempotency_key' => 'camp:' . hash( 'sha256', $title . '|' . $body ),
			'impact'          => ApprovalRequestService::IMPACT_HIGH,
			'requested_by'    => $requested_by,
		] );
	}

	/**
	 * Queue a Pado AI-policy change. Closed key list with per-key types; the old
	 * value travels in the payload so the executor can compensate.
	 *
	 * @param mixed $new_value
	 * @return array{ok:bool,id:int,error:string,duplicate:bool}
	 */
	public function request_policy_change( int $tenant_id, string $key, $new_value, int $requested_by, string $reason = '' ): array {
		$type = self::POLICY_KEYS[ $key ] ?? '';
		if ( '' === $type ) {
			return [ 'ok' => false, 'id' => 0, 'error' => 'policy_key_not_allowed', 'duplicate' => false ];
		}

		if ( 'bool' === $type ) {
			$new_value = (bool) $new_value;
		} else {
			$new_value = (int) $new_value;
			if ( str_contains( $key, 'budget' ) && ( $new_value < 0 || $new_value > 1000000 ) ) {
				return [ 'ok' => false, 'id' => 0, 'error' => 'invalid_request', 'duplicate' => false ];
			}
		}

		$old = $this->get_policy( $key );

		return $this->approvals->enqueue( [
			'tenant_id'       => $tenant_id,
			'kind'            => self::KIND_POLICY_CHANGE,
			'title'           => sprintf( 'تغییر سیاست پادو: %s', $key ),
			'reason'          => $reason,
			'payload'         => [
				'key'       => $key,
				'old_value' => $old,
				'new_value' => $new_value,
			],
			'capability'      => self::CAPABILITY,
			'idempotency_key' => 'policy:' . hash( 'sha256', $key . '|' . wp_json_encode( $new_value ) ),
			'impact'          => ApprovalRequestService::IMPACT_CRITICAL,
			'requested_by'    => $requested_by,
		] );
	}

	// --------------------------------------------------------------- execute

	/**
	 * The queue's executor. Runs under the claim/complete contract; refuses a
	 * tampered payload whatever the approval said; records the provable outcome.
	 *
	 * @param array<string,mixed> $row
	 */
	public function run( array $row ): bool {
		$id = (int) ( $row['id'] ?? 0 );
		if ( $id <= 0 ) {
			return false;
		}

		if ( ! $this->approvals->verify_payload_integrity( $id, (int) ( $row['tenant_id'] ?? 0 ) ) ) {
			$this->logger->error( 'pado', 'Refused to execute a request whose payload was edited after submission', [ 'request' => $id ] );
			return false;
		}

		$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
		if ( ! is_array( $payload ) ) {
			return false;
		}

		switch ( (string) $row['kind'] ) {
			case self::KIND_PUBLISH_VIRAL:
			case self::KIND_PUBLISH_TRUST:
			case self::KIND_PUBLISH_LIFESTYLE:
			case self::KIND_PUBLISH_CAMPAIGN:
				return $this->run_publish( $id, (int) ( $row['tenant_id'] ?? 0 ), $payload );
			case self::KIND_CAMPAIGN_SEND:
				return $this->run_campaign_send( $id, (int) ( $row['tenant_id'] ?? 0 ), (int) ( $row['requested_by'] ?? 0 ), $payload );
			case self::KIND_POLICY_CHANGE:
				return $this->run_policy_change( $id, $payload );
		}

		return false; // unknown kind: nothing executes
	}

	/**
	 * Publish or schedule the queued rows. Stops at the first refusal — what is
	 * already on the provider stays (publishing is not compensable); the outcome
	 * records exactly how far the batch went.
	 *
	 * @param array<string,mixed> $payload
	 */
	private function run_publish( int $request_id, int $tenant_id, array $payload ): bool {
		$ids  = array_values( array_filter( array_map( 'intval', (array) ( $payload['content_ids'] ?? [] ) ), static fn ( int $id ): bool => $id > 0 ) );
		$when = isset( $payload['when'] ) && is_string( $payload['when'] ) && '' !== $payload['when'] ? $payload['when'] : null;
		if ( ! $ids ) {
			return false;
		}

		$done    = 0;
		$last    = '';
		$statuses = [];
		foreach ( $ids as $content_id ) {
			$result = null === $when
				? $this->publish_now( $tenant_id, $content_id )
				: $this->schedule( $tenant_id, $content_id, $when );
			if ( empty( $result['ok'] ) ) {
				$last = (string) ( $result['error'] ?? 'unknown' );
				break;
			}
			++$done;
			$statuses[] = [ 'id' => $content_id, 'status' => (string) $result['status'] ];
		}

		$this->record_outcome( $request_id, [
			'published' => $done,
			'requested' => count( $ids ),
			'rows'      => $statuses,
			'error'     => $last,
		] );

		if ( $last !== '' ) {
			$this->logger->error( 'pado', 'Publish batch stopped at a refusal', [ 'request' => $request_id, 'done' => $done, 'error' => $last ] );
			return false;
		}

		return true;
	}

	/**
	 * One message to every active VIP member (capped). Messages already delivered
	 * cannot be unsent; the executor stops at the first failure and reports counts.
	 *
	 * @param array<string,mixed> $payload
	 */
	private function run_campaign_send( int $request_id, int $tenant_id, int $sender_id, array $payload ): bool {
		$title = (string) ( $payload['title'] ?? '' );
		$body  = (string) ( $payload['body'] ?? '' );
		if ( '' === $title || '' === $body ) {
			return false;
		}

		$recipients = $this->load_recipients( $tenant_id, self::MAX_CAMPAIGN_RECIPIENTS );
		$sent       = 0;
		$last       = '';
		foreach ( $recipients as $user_id ) {
			$thread = $this->thread_for_user( (int) $user_id, $tenant_id, $title );
			if ( $thread <= 0 ) {
				$last = 'thread_failed';
				break;
			}
			if ( $this->send_message( $thread, $sender_id, $body ) <= 0 ) {
				$last = 'send_failed';
				break;
			}
			++$sent;
		}

		$this->record_outcome( $request_id, [
			'recipients' => count( $recipients ),
			'sent'       => $sent,
			'error'      => $last,
		] );

		if ( $last !== '' ) {
			$this->logger->error( 'pado', 'Campaign blast stopped at a failure', [ 'request' => $request_id, 'sent' => $sent, 'error' => $last ] );
			return false;
		}

		return true;
	}

	/**
	 * Apply the policy change, verify on re-read, compensate to the old value when
	 * it does not stick.
	 *
	 * @param array<string,mixed> $payload
	 */
	private function run_policy_change( int $request_id, array $payload ): bool {
		$key = (string) ( $payload['key'] ?? '' );
		$new = $payload['new_value'] ?? null;
		$old = $payload['old_value'] ?? null;
		if ( '' === $key || ! isset( self::POLICY_KEYS[ $key ] ) || null === $new ) {
			return false;
		}

		$this->set_policy( $key, $new );
		$after = $this->get_policy( $key );
		if ( $this->policy_differs( $after, $new ) ) {
			$this->set_policy( $key, $old );
			$this->record_outcome( $request_id, [ 'key' => $key, 'applied' => false, 'error' => 'write_did_not_stick' ] );
			$this->logger->warning( 'pado', 'Policy change compensated back to the captured value', [ 'request' => $request_id, 'key' => $key ] );
			return false;
		}

		$this->record_outcome( $request_id, [ 'key' => $key, 'applied' => true, 'from' => $old, 'to' => $after ] );
		return true;
	}

	// ------------------------------------------------------------------ util

	/** The provable outcome, written into the row's metadata once it settled. */
	public function record_outcome( int $request_id, array $outcome ): void {
		$this->db->update(
			'approval_requests',
			[ 'metadata' => wp_json_encode( [ 'outcome' => $outcome ], JSON_UNESCAPED_UNICODE ) ],
			[ 'id' => $request_id, 'status' => ApprovalRequestService::STATUS_CLAIMED ]
		);
	}

	private function category_label( string $category ): string {
		$labels = [ 'viral' => 'وایرال/رشد', 'trust' => 'تخصصی/اعتماد', 'lifestyle' => 'شخصی/وفاداری', 'campaign' => 'فروش/کمپین' ];
		return $labels[ $category ] ?? $category;
	}

	private function policy_differs( mixed $a, mixed $b ): bool {
		if ( is_bool( $a ) || is_bool( $b ) ) {
			return (bool) $a !== (bool) $b;
		}
		return abs( (float) $a - (float) $b ) > 0.0001;
	}

	// ------------------------------------------------- environment seams

	/** The active VIP members of the tenant, at most $cap user ids. @return array<int,int|string> */
	protected function load_recipients( int $tenant_id, int $cap ): array {
		$rows = $this->db->results(
			'SELECT DISTINCT user_id FROM ' . $this->db->table( 'vip_memberships' ) . '
			 WHERE tenant_id = %d AND status = %s ORDER BY user_id ASC LIMIT %d',
			$tenant_id,
			'active',
			max( 1, $cap )
		);
		return array_map( static fn ( array $r ) => (int) $r['user_id'], is_array( $rows ) ? $rows : [] );
	}

	/** @return array<string,mixed> */
	protected function publish_now( int $tenant_id, int $content_id ): array {
		return $this->publisher->publish_now( $tenant_id, $content_id );
	}

	/** @return array<string,mixed> */
	protected function schedule( int $tenant_id, int $content_id, string $when_iso ): array {
		return $this->publisher->schedule( $tenant_id, $content_id, $when_iso );
	}

	protected function thread_for_user( int $user_id, int $tenant_id, string $subject ): int {
		return $this->messages->thread_for_user( $user_id, $tenant_id, $subject );
	}

	protected function send_message( int $thread_id, int $sender_id, string $body ): int {
		return $this->messages->send( $thread_id, $sender_id, $body, VipMessageService::SENDER_ADMIN );
	}

	protected function get_policy( string $key ): mixed {
		return 'bool' === ( self::POLICY_KEYS[ $key ] ?? 'bool' )
			? $this->settings->bool( $key, false )
			: $this->settings->int( $key, 0 );
	}

	protected function set_policy( string $key, mixed $value ): void {
		$this->settings->set( $key, $value );
	}
}
