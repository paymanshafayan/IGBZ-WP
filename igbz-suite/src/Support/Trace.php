<?php
namespace IGBZ\Suite\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 71 — the request trace: one CSPRNG id per request (and one per queued
 * job while it runs), stamped into every log line, so "a customer says it
 * failed at 14:03" turns into one grep instead of an archaeology dig.
 *
 * The web request id and a job's envelope trace id meet in the log table: a
 * handler that enqueues follow-up work while serving a request stamps its own
 * trace onto the envelope (JobQueue::enqueue), and the worker runs the job
 * under that same id (Trace::fork) — the async boundary stops losing the
 * thread.
 */
final class Trace {

	private static ?string $current = null;

	/** The id of the work happening right now (lazily minted once per request). */
	public static function id(): string {
		if ( null === self::$current ) {
			self::$current = Crypto::token( 16 );
		}

		return self::$current;
	}

	/** Adopt a specific id (e.g. a job's envelope trace) for the duration of $fn. */
	public static function fork( string $id, callable $fn ): mixed {
		$previous      = self::$current;
		self::$current = '' !== $id ? $id : $previous;

		try {
			return $fn();
		} finally {
			self::$current = $previous;
		}
	}

	/** Test/CLI seam: forget the current id. */
	public static function reset(): void {
		self::$current = null;
	}
}
