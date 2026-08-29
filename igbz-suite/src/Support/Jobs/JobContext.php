<?php
/**
 * Phase 23 — the context handed to every job handler next to its payload.
 *
 * Carries everything a handler needs to act idempotently and observably: which tenant the work
 * belongs to, the trace id that ties log lines together, the idempotency key (stable across all
 * retries of this one logical operation — never regenerate it), and which attempt this is.
 *
 * @package IGBZ\Suite\Support\Jobs
 */

declare( strict_types = 1 );

namespace IGBZ\Suite\Support\Jobs;

defined( 'ABSPATH' ) || exit;

final class JobContext {

	public function __construct(
		public readonly int $job_id,
		public readonly int $tenant_id,
		public readonly string $trace_id,
		public readonly string $idempotency_key,
		public readonly int $attempt,
		public readonly string $group
	) {}
}
