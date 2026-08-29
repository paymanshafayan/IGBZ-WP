<?php
namespace IGBZ\Suite\Modules\MultiTenant\Translation;

use IGBZ\Suite\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * International commerce foundations (phase 48): currency + timezone per
 * tenant, and the consent ledger every cross-border step must check before
 * it touches a customer's data.
 *
 * Consent is a ledger, not a boolean: grant and revoke both leave a dated
 * row, so the history of a customer's choices is auditable.
 */
final class IntlCommerceService {

	/** The ISO 4217 codes a store may honestly price in. */
	public const ALLOWED_CURRENCIES = [ 'IRR', 'IRT', 'USD', 'EUR', 'GBP', 'AED', 'TRY', 'CNY', 'INR' ];

	public const PURPOSE_CROSS_BORDER = 'intl_commerce';

	public function __construct( private Db $db ) {}

	// ------------------------------------------------------- currency & zone

	/** The tenant's ISO 4217 currency, always a code the platform knows. */
	public function currency( int $tenant_id ): string {
		$code = strtoupper( igbz()->settings()->string( 'intl.currency', 'IRR' ) );
		return in_array( $code, self::ALLOWED_CURRENCIES, true ) ? $code : 'IRR';
	}

	/** The tenant's IANA timezone, falling back to the platform home zone. */
	public function timezone( int $tenant_id ): string {
		$zone = igbz()->settings()->string( 'intl.timezone', 'Asia/Tehran' );
		return in_array( $zone, timezone_identifiers_list(), true ) ? $zone : 'Asia/Tehran';
	}

	// ---------------------------------------------------------------- consent

	/** @return array<string,mixed>|null */
	private function consent_row( int $tenant_id, int $user_id, string $purpose ): ?array {
		$row = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'ig_intl_consents' ) . '
			 WHERE tenant_id = %d AND user_id = %d AND purpose = %s LIMIT 1',
			$tenant_id,
			$user_id,
			$purpose
		);

		return $row ?: null;
	}

	public function grant_consent( int $tenant_id, int $user_id, string $purpose = self::PURPOSE_CROSS_BORDER ): void {
		$existing = $this->consent_row( $tenant_id, $user_id, $purpose );
		$now      = current_time( 'mysql', true );

		if ( $existing ) {
			$this->db->update(
				'ig_intl_consents',
				[ 'granted' => 1, 'granted_at' => $now, 'revoked_at' => null ],
				[ 'id' => (int) $existing['id'] ]
			);
			return;
		}

		$this->db->insert(
			'ig_intl_consents',
			[
				'tenant_id'  => $tenant_id,
				'user_id'    => $user_id,
				'purpose'    => $purpose,
				'granted'    => 1,
				'granted_at' => $now,
				'revoked_at' => null,
			]
		);
	}

	public function revoke_consent( int $tenant_id, int $user_id, string $purpose = self::PURPOSE_CROSS_BORDER ): void {
		$existing = $this->consent_row( $tenant_id, $user_id, $purpose );
		if ( null === $existing ) {
			// Revoking something never granted still deserves a dated row.
			$this->db->insert(
				'ig_intl_consents',
				[
					'tenant_id'  => $tenant_id,
					'user_id'    => $user_id,
					'purpose'    => $purpose,
					'granted'    => 0,
					'granted_at' => null,
					'revoked_at' => current_time( 'mysql', true ),
				]
			);
			return;
		}

		$this->db->update(
			'ig_intl_consents',
			[ 'granted' => 0, 'revoked_at' => current_time( 'mysql', true ) ],
			[ 'id' => (int) $existing['id'] ]
		);
	}

	public function has_consent( int $tenant_id, int $user_id, string $purpose = self::PURPOSE_CROSS_BORDER ): bool {
		$row = $this->consent_row( $tenant_id, $user_id, $purpose );

		return null !== $row && 1 === (int) $row['granted'];
	}

	/**
	 * Cross-border processing is allowed only when the customer granted it
	 * and never took it back. No consent, no crossing.
	 */
	public function crossborder_allowed( int $tenant_id, int $user_id ): bool {
		return $this->has_consent( $tenant_id, $user_id, self::PURPOSE_CROSS_BORDER );
	}
}
