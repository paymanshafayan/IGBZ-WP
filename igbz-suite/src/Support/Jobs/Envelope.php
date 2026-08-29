<?php
/**
 * Phase 23 — the versioned envelope every queued job travels in.
 *
 * The envelope is the contract between whoever enqueued a job and whatever worker eventually
 * runs it, possibly days and several deploys later. It therefore carries its own schema version:
 * a worker that meets a version it does not understand must dead-letter the job, never guess.
 *
 * @package IGBZ\Suite\Support\Jobs
 */

declare( strict_types = 1 );

namespace IGBZ\Suite\Support\Jobs;

defined( 'ABSPATH' ) || exit;

final class Envelope {

	/** Bumped only when the stored JSON shape changes; old envelopes stay readable forever. */
	public const VERSION = 1;

	/**
	 * @param array<string,mixed>  $payload Job arguments; keep small — store references, not data.
	 * @param array<string,mixed>  $meta    queue/group/idempotency_key context echoed back to handlers.
	 */
	public static function wrap( string $job_type, array $payload, string $trace_id, array $meta = [] ): string {
		return (string) wp_json_encode(
			[
				'v'           => self::VERSION,
				'job_type'    => $job_type,
				'payload'     => (object) $payload,
				'trace_id'    => $trace_id,
				'enqueued_at' => current_time( 'mysql', true ),
				'meta'        => (object) $meta,
			]
		);
	}

	/**
	 * Decode and validate an envelope. Returns null when the JSON is malformed; a well-formed
	 * envelope from a newer schema version still parses — the dispatcher owns the decision
	 * (dead-letter, not guess).
	 *
	 * @return array{v:int,job_type:string,payload:array<string,mixed>,trace_id:string,meta:array<string,mixed>}|null
	 */
	public static function open( string $json ): ?array {
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		$version = (int) ( $decoded['v'] ?? 0 );
		$type    = (string) ( $decoded['job_type'] ?? '' );
		if ( $version <= 0 || '' === $type ) {
			return null;
		}

		return [
			'v'        => $version,
			'job_type' => $type,
			'payload'  => is_array( $decoded['payload'] ?? null ) ? $decoded['payload'] : [],
			'trace_id' => (string) ( $decoded['trace_id'] ?? '' ),
			'meta'     => is_array( $decoded['meta'] ?? null ) ? $decoded['meta'] : [],
		];
	}
}
