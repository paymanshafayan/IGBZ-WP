<?php
namespace IGBZ\Suite\Modules\MultiTenant\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 30 — one shared error mapping for every PSP adapter.
 *
 * Before this, a transport timeout and a PSP rejection looked the same to callers (an empty
 * body parsed into a generic `request_failed`), so nothing could tell "retry later" from
 * "permanently declined". Every adapter now classifies its outcome here:
 *
 *  - the HTTP round-trip failed            -> network_timeout  (transient — safe to retry verify/refund)
 *  - the round-trip succeeded, body empty  -> invalid_response (transient)
 *  - the PSP answered with its own code    -> passed through untouched, with the provider's
 *                                             message preserved for the operator
 *  - anything else                         -> the adapter's fallback code
 *
 * Raw provider codes and messages are never invented and never swallowed.
 */
final class GatewayErrors {

	public const NOT_CONFIGURED   = 'not_configured';
	public const NETWORK_TIMEOUT  = 'network_timeout';
	public const INVALID_RESPONSE = 'invalid_response';

	private function __construct() {}

	/**
	 * Classify a PSP round-trip.
	 *
	 * @param bool                $ok         HTTP-level success.
	 * @param string              $raw_error  Transport error message (empty when $ok).
	 * @param array<string,mixed> $body       Decoded response body.
	 * @param string              $code       Provider error code already extracted, if any.
	 * @param string              $message    Provider message already extracted, if any.
	 * @param string              $fallback   Code to use when the provider gave nothing.
	 * @param string              $fallback_message
	 * @return array{0:string,1:string} [code, message]
	 */
	public static function classify( bool $ok, string $raw_error, array $body, string $code, string $message, string $fallback, string $fallback_message ): array {
		if ( ! $ok ) {
			return [
				self::NETWORK_TIMEOUT,
				'' !== $raw_error ? $raw_error : __( 'The payment service did not respond in time.', 'igbz-suite' ),
			];
		}
		if ( [] === $body ) {
			return [ self::INVALID_RESPONSE, __( 'The payment service returned an unreadable response.', 'igbz-suite' ) ];
		}
		if ( '' !== $code || '' !== $message ) {
			return [ '' !== $code ? $code : $fallback, '' !== $message ? $message : $fallback_message ];
		}
		return [ $fallback, $fallback_message ];
	}
}
