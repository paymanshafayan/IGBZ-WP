<?php
namespace IGBZ\Suite\Modules\MultiTenant\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 30 — the uniform shape every refund-capable adapter returns.
 */
final class PaymentRefundResult {

	private function __construct(
		public readonly bool $success,
		public readonly string $reference_id = '',
		public readonly string $error_code = '',
		public readonly string $error_message = ''
	) {}

	public static function ok( string $reference_id ): self {
		return new self( true, $reference_id );
	}

	public static function failure( string $code, string $message ): self {
		return new self( false, '', $code, $message );
	}
}
