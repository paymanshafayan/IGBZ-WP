<?php
namespace IGBZ\Suite\Modules\MultiTenant\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 30 — the shared HTTP policy for every PSP adapter.
 *
 * Before this, adapters carried three different hard-coded timeouts (25/30), so one slow bank
 * behaved differently from another and none of it was tunable without code changes. There is now
 * one value: `payments.timeout` seconds (default 30, clamped 5–60 — short enough to fail a stuck
 * checkout fast, long enough for SOAP bank stacks).
 *
 * Retry policy: verify() and refund() are safe to retry (reads, and refunds are keyed). Creating
 * a payment request is NOT auto-retried anywhere in this codebase — a timeout after the PSP
 * accepted the request would mint a second authority and double-charge is the worst outcome a
 * gateway layer can produce. The caller may present a fresh request to the user instead.
 */
final class PspHttp {

	public const DEFAULT_TIMEOUT = 30;

	private function __construct() {}

	public static function timeout(): int {
		$seconds = (int) igbz()->settings()->int( 'payments.timeout', self::DEFAULT_TIMEOUT );
		return max( 5, min( 60, $seconds ) );
	}
}
