<?php
/**
 * Phase 29 — the durable webhook inbox.
 *
 * Contract:
 *  - Capture is fast and synchronous: receive() inserts the raw event and returns. No business
 *    logic runs in the request path, so a slow handler can never make a provider time out and
 *    re-deliver into a pile-up.
 *  - Deduplication is at the database level: (source, event_key) is UNIQUE. A provider that
 *    replays a delivery — or an attacker replaying a captured request — gets `duplicate`, and
 *    exactly one copy is ever processed.
 *  - Processing is asynchronous through the durable job queue: a drain job claims due events,
 *    dispatches them to the registered source handler and records the outcome.
 *  - Unknown is not an error: a handler may answer "I cannot decide yet". The event returns to
 *    `received` with exponential backoff and retries until it resolves or exhausts its attempts,
 *    at which point it is dead-lettered with the reason — never silently dropped.
 *  - Signatures: HMAC-SHA256 over the raw body with the per-source secret
 *    (`webhooks.{source}.secret`, falling back to `webhooks.hmac_secret`), compared
 *    timing-safe. An unsigned or badly signed delivery is stored as `invalid` and never
 *    dispatched.
 *
 * @package IGBZ\Suite\Support\Webhooks
 */

declare( strict_types = 1 );

namespace IGBZ\Suite\Support\Webhooks;

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;
use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class WebhookInbox {

	public const STATUS_RECEIVED   = 'received';
	public const STATUS_PROCESSING = 'processing';
	public const STATUS_DONE       = 'done';
	public const STATUS_DEAD       = 'dead';

	public const SIG_VALID     = 'valid';
	public const SIG_INVALID   = 'invalid';
	public const SIG_UNCHECKED = 'unchecked';

	public const BACKOFF_BASE_SECONDS = 30;
	public const BACKOFF_CAP_SECONDS  = 3600;

	/** @var array<string,callable> source => handler(array $payload, array $event): 'done'|'unknown' */
	private array $handlers = [];

	public function __construct( private Db $db, private Settings $settings, private Logger $logger ) {}

	/**
	 * Register the processor for a source. The handler receives the decoded payload plus the
	 * inbox row and returns 'done' or 'unknown'; throwing marks the attempt failed.
	 */
	public function register_source( string $source, callable $handler ): void {
		$this->handlers[ $source ] = $handler;
	}

	/**
	 * Fast capture. Idempotent on (source, event_key): a replayed delivery reports `duplicate`.
	 *
	 * @return array{status:string,id:int} status: stored|duplicate
	 */
	public function receive( string $source, string $event_key, string $payload, int $tenant_id = 0, string $signature_status = self::SIG_UNCHECKED ): array {
		$now = current_time( 'mysql', true );

		$id = $this->db->insert(
			'webhook_events',
			[
				'tenant_id'        => $tenant_id,
				'source'           => $source,
				'event_key'        => $event_key,
				'status'           => self::STATUS_RECEIVED,
				'signature_status' => $signature_status,
				'payload'          => $payload,
				'attempts'         => 0,
				'available_at'     => $now,
				'created_at'       => $now,
				'updated_at'       => $now,
			]
		);
		if ( $id > 0 ) {
			$this->logger->info( 'webhooks', 'event received', [ 'id' => $id, 'source' => $source, 'key' => $event_key ] );
			return [ 'status' => 'stored', 'id' => $id ];
		}

		// The unique index refused a replay — find the original so the caller can ack it.
		$existing = $this->db->row(
			'SELECT id, status FROM ' . $this->db->table( 'webhook_events' ) . ' WHERE source = %s AND event_key = %s',
			$source,
			$event_key
		);
		return [ 'status' => 'duplicate', 'id' => (int) ( $existing['id'] ?? 0 ) ];
	}

	/**
	 * Timing-safe HMAC-SHA256 check against the per-source secret. No secret configured for the
	 * source (nor a shared fallback) means nothing can be validated.
	 */
	public function verify_signature( string $source, string $payload, string $signature ): bool {
		$secret = $this->settings->string( 'webhooks.' . $source . '.secret', '' );
		if ( '' === $secret ) {
			$secret = $this->settings->string( 'webhooks.hmac_secret', '' );
		}
		if ( '' === $secret || '' === $signature ) {
			return false;
		}
		return hash_equals( hash_hmac( 'sha256', $payload, $secret ), $signature );
	}

	/**
	 * Claim due events atomically (conditional UPDATE — two drains can never win the same row).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function claim_due( int $limit = 20 ): array {
		$now  = current_time( 'mysql', true );
		$due  = $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'webhook_events' ) . '
			 WHERE status = %s AND available_at <= %s
			 ORDER BY available_at ASC, id ASC LIMIT %d',
			self::STATUS_RECEIVED,
			$now,
			$limit
		);
		$out = [];
		foreach ( $due as $row ) {
			$changed = $this->db->update(
				'webhook_events',
				[
					'status'     => self::STATUS_PROCESSING,
					'attempts'   => (int) $row['attempts'] + 1,
					'updated_at' => $now,
				],
				[
					'id'     => (int) $row['id'],
					'status' => self::STATUS_RECEIVED,
				]
			);
			if ( $changed > 0 ) {
				$row['attempts'] = (int) $row['attempts'] + 1;
				$out[]           = $row;
			}
		}
		return $out;
	}

	/**
	 * Drain one batch of due events. Returns the outcome tallies.
	 *
	 * @return array{done:int,unknown:int,failed:int,dead:int}
	 */
	public function process_batch( int $limit = 20 ): array {
		$totals = [ 'done' => 0, 'unknown' => 0, 'failed' => 0, 'dead' => 0 ];

		foreach ( $this->claim_due( $limit ) as $event ) {
			$id     = (int) $event['id'];
			$source = (string) $event['source'];

			if ( self::SIG_INVALID === $event['signature_status'] ) {
				$this->dead( $id, 'invalid signature' );
				++$totals['dead'];
				continue;
			}
			if ( ! isset( $this->handlers[ $source ] ) ) {
				$this->dead( $id, 'no handler registered for ' . $source );
				++$totals['dead'];
				continue;
			}

			$payload = json_decode( (string) $event['payload'], true );
			$payload = is_array( $payload ) ? $payload : [];

			try {
				$verdict = (string) ( $this->handlers[ $source ] )( $payload, $event );
			} catch ( \Throwable $e ) {
				if ( $this->fail( $event, $e->getMessage() ) ) {
					++$totals['dead'];
				} else {
					++$totals['failed'];
				}
				continue;
			}

			if ( 'unknown' === $verdict ) {
				if ( (int) $event['attempts'] >= (int) $event['max_attempts'] ) {
					$this->dead( $id, 'unknown state after final attempt' );
					++$totals['dead'];
				} else {
					$this->retry( $event );
					++$totals['unknown'];
				}
				continue;
			}

			$this->complete( $id );
			++$totals['done'];
		}
		return $totals;
	}

	/** @return array{received:int,processing:int,done:int,dead:int} */
	public function stats(): array {
		$out = [ 'received' => 0, 'processing' => 0, 'done' => 0, 'dead' => 0 ];
		$rows = $this->db->results(
			'SELECT status, COUNT(*) AS total FROM ' . $this->db->table( 'webhook_events' ) . ' GROUP BY status'
		);
		foreach ( $rows as $row ) {
			if ( isset( $out[ (string) $row['status'] ] ) ) {
				$out[ (string) $row['status'] ] = (int) $row['total'];
			}
		}
		return $out;
	}

	/** @return array<int,array<string,mixed>> */
	public function dead_letters( int $limit = 30 ): array {
		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'webhook_events' ) . '
			 WHERE status = %s ORDER BY updated_at DESC, id DESC LIMIT %d',
			self::STATUS_DEAD,
			$limit
		);
	}

	// ------------------------------------------------------------ internals

	private function complete( int $id ): void {
		$this->db->update(
			'webhook_events',
			[
				'status'       => self::STATUS_DONE,
				'processed_at' => current_time( 'mysql', true ),
				'updated_at'   => current_time( 'mysql', true ),
			],
			[ 'id' => $id ]
		);
	}

	private function dead( int $id, string $reason ): void {
		$this->db->update(
			'webhook_events',
			[
				'status'       => self::STATUS_DEAD,
				'last_error'   => mb_substr( $reason, 0, 255 ),
				'processed_at' => current_time( 'mysql', true ),
				'updated_at'   => current_time( 'mysql', true ),
			],
			[ 'id' => $id ]
		);
		$this->logger->error( 'webhooks', 'event dead-lettered', [ 'id' => $id, 'reason' => $reason ] );
	}

	/** @param array<string,mixed> $event @return bool true when the event was dead-lettered */
	private function fail( array $event, string $reason ): bool {
		if ( (int) $event['attempts'] >= (int) $event['max_attempts'] ) {
			$this->dead( (int) $event['id'], $reason );
			return true;
		}
		$this->retry( $event, $reason );
		return false;
	}

	/** Back to the pool with exponential backoff (capped). */
	private function retry( array $event, string $reason = '' ): void {
		$delay = min( self::BACKOFF_CAP_SECONDS, self::BACKOFF_BASE_SECONDS * ( 2 ** max( 0, (int) $event['attempts'] - 1 ) ) );
		$this->db->update(
			'webhook_events',
			[
				'status'       => self::STATUS_RECEIVED,
				'available_at' => gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql', true ) . ' UTC' ) + $delay ),
				'last_error'   => mb_substr( $reason, 0, 255 ),
				'updated_at'   => current_time( 'mysql', true ),
			],
			[ 'id' => (int) $event['id'] ]
		);
		$this->logger->warning( 'webhooks', 'event scheduled for retry', [ 'id' => (int) $event['id'], 'attempt' => (int) $event['attempts'], 'reason' => $reason ] );
	}
}
