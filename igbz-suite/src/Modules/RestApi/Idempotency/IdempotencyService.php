<?php
namespace IGBZ\Suite\Modules\RestApi\Idempotency;

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 67 — safe retries for mobile writes (the Stripe/Adyen idempotency-key model).
 *
 * A phone on a bad network times out *after* the server did the work, then retries — and
 * without help the retry charges the wallet or submits the quiz twice. So a write the
 * client deems retryable carries an `Idempotency-Key` it generated once per logical
 * operation, and this service guarantees one outcome per key:
 *
 *  - the claim is an atomic INSERT against the unique (user_id, idem_key) index, so of
 *    two concurrent attempts exactly one wins;
 *  - the winner records its response (status + body) and every later retry with the same
 *    key and the same request fingerprint replays that stored response verbatim — the
 *    client sees the same answer it missed, not a second side effect;
 *  - a retry while the first attempt is still in flight answers 409 (come back), a retry
 *    with the same key but a *different* body answers 409 (client bug: key reuse across
 *    intents must never silently return an unrelated stored response);
 *  - a claim whose holder died mid-request is reclaimable after the lease, and every row
 *    expires and is pruned, so the table cannot grow unbounded.
 *
 * Keys are scoped to the caller (user_id), never global: one tenant can neither collide
 * with nor probe another tenant's key.
 */
final class IdempotencyService {

	public const STATE_IN_FLIGHT = 'in_flight';
	public const STATE_DONE      = 'done';

	/** Seconds a crashed claimant may block retries before the lease lets someone else take over. */
	public const LEASE_SECONDS = 120;

	/** Hours a finished (or abandoned) outcome stays replayable. 24–72h is the industry window. */
	public const RETENTION_HOURS = 48;

	/** Stored responses above this size are not kept for replay (guards the LONGTEXT row). */
	private const MAX_STORED_BYTES = 262144;

	/** @var callable():int test seam for the clock (UTC timestamp). */
	private $now;

	public function __construct( private Db $db, private ?Logger $logger = null, ?callable $now = null ) {
		$this->now = $now ?? static fn (): int => time();
	}

	/**
	 * The key format the API accepts: client-generated, per logical operation (a UUID
	 * qualifies). 8–191 chars of portable, non-whitespace token characters.
	 */
	public static function valid_key( string $key ): bool {
		return (bool) preg_match( '/^[A-Za-z0-9._:=+-]{8,191}$/', $key );
	}

	/**
	 * Recursively key-sorted copy, so two bodies that differ only in key order are the
	 * same intent.
	 *
	 * @param array<array-key,mixed> $value
	 * @return array<array-key,mixed>
	 */
	private static function canonical( array $value ): array {
		ksort( $value, SORT_STRING );
		foreach ( $value as $k => $v ) {
			if ( is_array( $v ) ) {
				$value[ $k ] = self::canonical( $v );
			}
		}

		return $value;
	}

	/**
	 * Stable fingerprint of the request the key names: method + path + canonical body
	 * (recursively key-sorted JSON), so identical retries match and any payload change
	 * does not.
	 *
	 * @param array<string,mixed> $body
	 */
	public static function fingerprint( string $method, string $path, array $body ): string {
		return hash( 'sha256', strtoupper( $method ) . ' ' . $path . "\n" . (string) wp_json_encode( self::canonical( $body ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Try to claim the key. Returns one of:
	 *  ['status'=>'new', 'id'=>int]                          — the caller runs the write, then complete(id, response);
	 *  ['status'=>'replay', 'code'=>int, 'body'=>mixed]      — answer with exactly this stored response;
	 *  ['status'=>'busy']                                    — the first attempt is still in flight (409, retryable);
	 *  ['status'=>'conflict']                                — same key, different request (409, client bug).
	 *
	 * @param array<string,mixed> $body
	 * @return array<string,mixed>
	 */
	public function claim( int $user_id, string $key, string $method, string $path, array $body ): array {
		if ( ! self::valid_key( $key ) ) {
			return [ 'status' => 'invalid_key' ];
		}

		$now         = ( $this->now )();
		$fingerprint = self::fingerprint( $method, $path, $body );
		$row         = [
			'tenant_id'    => 0, // resolved by the caller's scoping; the claim itself is per user
			'user_id'      => $user_id,
			'idem_key'     => $key,
			'method'       => strtoupper( $method ),
			'path'         => $path,
			'fingerprint'  => $fingerprint,
			'state'        => self::STATE_IN_FLIGHT,
			'response_code' => null,
			'response_body' => null,
			'claimed_at'   => gmdate( 'Y-m-d H:i:s', $now ),
			'expires_at'   => gmdate( 'Y-m-d H:i:s', $now + self::RETENTION_HOURS * HOUR_IN_SECONDS ),
		];

		$id = $this->db->insert( 'api_idempotency', $row );
		if ( $id > 0 ) {
			return [ 'status' => 'new', 'id' => $id ];
		}

		// The unique key rejected us: someone holds this key. Who, and in what state?
		$existing = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'api_idempotency' ) . ' WHERE user_id = %d AND idem_key = %s',
			$user_id,
			$key
		);
		if ( null === $existing ) {
			// Raced between INSERT-failure and SELECT (prune removed it) — claim again once.
			$id = $this->db->insert( 'api_idempotency', $row );
			return $id > 0
				? [ 'status' => 'new', 'id' => $id ]
				: [ 'status' => 'busy' ];
		}

		$expired = strtotime( (string) $existing['expires_at'] . ' UTC' ) <= $now - 60;
		if ( $expired ) {
			// The outcome is past its replay window: forget it and reclaim.
			$this->db->delete( 'api_idempotency', [ 'id' => (int) $existing['id'], 'user_id' => $user_id, 'idem_key' => $key ] );
			$id = $this->db->insert( 'api_idempotency', $row );
			return $id > 0
				? [ 'status' => 'new', 'id' => $id ]
				: [ 'status' => 'busy' ];
		}

		if ( (string) $existing['fingerprint'] !== $fingerprint ) {
			return [ 'status' => 'conflict' ];
		}

		if ( self::STATE_DONE === (string) $existing['state'] ) {
			return [
				'status' => 'replay',
				'code'   => (int) ( $existing['response_code'] ?? 200 ),
				'body'   => is_string( $existing['response_body'] ?? null ) ? json_decode( $existing['response_body'], true ) : null,
			];
		}

		// In flight. If the claimant died, the lease lets us take over atomically; if it
		// is genuinely still working, the retry must wait.
		$stale_at = gmdate( 'Y-m-d H:i:s', $now - self::LEASE_SECONDS );
		$took     = $this->db->update(
			'api_idempotency',
			[ 'claimed_at' => $row['claimed_at'], 'fingerprint' => $fingerprint, 'method' => $row['method'], 'path' => $row['path'], 'expires_at' => $row['expires_at'] ],
			[ 'id' => (int) $existing['id'], 'state' => self::STATE_IN_FLIGHT, 'fingerprint' => $fingerprint ]
		);
		if ( $took > 0 && strtotime( (string) $existing['claimed_at'] . ' UTC' ) <= strtotime( $stale_at . ' UTC' ) ) {
			return [ 'status' => 'new', 'id' => (int) $existing['id'] ];
		}

		return [ 'status' => 'busy' ];
	}

	/**
	 * Record the outcome of a won claim. The stored body is what later retries receive
	 * verbatim — including error responses, so a failed write replays its failure.
	 *
	 * @param mixed $body
	 */
	public function complete( int $id, int $code, $body ): void {
		$json = null;
		if ( null !== $body ) {
			$encoded = (string) wp_json_encode( $body, JSON_UNESCAPED_UNICODE );
			$json    = strlen( $encoded ) <= self::MAX_STORED_BYTES ? $encoded : null;
		}

		$this->db->update(
			'api_idempotency',
			[
				'state'         => self::STATE_DONE,
				'response_code' => $code,
				'response_body' => $json,
			],
			[ 'id' => $id, 'state' => self::STATE_IN_FLIGHT ]
		);
	}

	/** Daily prune: every row past its replay window goes, in bounded batches. */
	public function prune_expired(): int {
		$cutoff = gmdate( 'Y-m-d H:i:s', ( $this->now )() - 60 );

		return $this->db->delete_batches( 'api_idempotency', 'expires_at < %s', [ $cutoff ] );
	}
}
