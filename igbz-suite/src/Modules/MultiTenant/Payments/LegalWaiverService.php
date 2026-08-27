<?php
namespace IGBZ\Suite\Modules\MultiTenant\Payments;

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * The legal waiver a store admin must accept before taking bank payments without Shahkar
 * national-id matching.
 *
 * WHY THIS EXISTS
 * ---------------
 * DESIGN-LEGAL-AUTH.md opens with a blunt warning: failing to run lawful registration can end in
 * court and heavy damages. Its §3 answers that with a fork — either the admin turns on national-id
 * matching, or the admin signs a waiver accepting that the consequences are theirs alone.
 *
 * The table for that waiver has shipped since DB v17. Nothing ever wrote to it or read it. So the
 * fork had only one arm: payments could run with neither the technical check nor the legal cover.
 * This class is the missing arm.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 * --------------------------------
 * It does not decide whether a gateway is configured, and it does not process payments. It answers
 * one question — may this tenant take bank payments right now — and records the answer's basis.
 */
final class LegalWaiverService {

	/** The only waiver type in use today. The column is wider so more can follow. */
	public const TYPE_PAYMENT_WITHOUT_NID = 'payment_without_nid';

	/**
	 * Bump this when the waiver wording changes in a way that alters what the admin agreed to.
	 *
	 * An acceptance is tied to the version and hash that were on screen. Reworded terms are new
	 * terms, and consent to the old ones is not consent to the new ones — which is the whole point
	 * of storing a hash rather than a boolean.
	 */
	public const CURRENT_VERSION = '1.0';

	public function __construct( private Db $db, private Logger $logger ) {}

	/**
	 * The gate that matters: may this tenant take bank payments?
	 *
	 * Order is deliberate. National-id matching is the preferred path, so it is checked first; the
	 * waiver is the fallback for admins who decline it.
	 *
	 * @return array{allowed:bool,reason:string,needs_waiver:bool}
	 */
	public function payment_allowed( int $tenant_id ): array {
		if ( igbz()->settings()->bool( 'legal.national_id_check', false )
			&& '' !== igbz()->settings()->string( 'legal.shahkar_api_key', '' )
			&& '' !== igbz()->settings()->string( 'legal.shahkar_base_url', '' ) ) {
			return [ 'allowed' => true, 'reason' => '', 'needs_waiver' => false ];
		}

		if ( $this->has_accepted( $tenant_id ) ) {
			return [ 'allowed' => true, 'reason' => '', 'needs_waiver' => false ];
		}

		return [
			'allowed'      => false,
			'needs_waiver' => true,
			'reason'       => __( 'Bank payments need either national-id matching or an accepted legal waiver.', 'igbz-suite' ),
		];
	}

	/**
	 * Has this tenant accepted the waiver as it currently reads?
	 *
	 * A stored acceptance of an older wording does not count. That is why the hash is compared and
	 * not merely the presence of a row.
	 */
	public function has_accepted( int $tenant_id, string $type = self::TYPE_PAYMENT_WITHOUT_NID ): bool {
		$row = $this->find( $tenant_id, $type );
		if ( null === $row ) {
			return false;
		}
		return (string) $row['content_hash'] === $this->current_hash( $type );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function find( int $tenant_id, string $type = self::TYPE_PAYMENT_WITHOUT_NID ): ?array {
		return $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'ig_legal_agreements' ) . '
			 WHERE tenant_id = %d AND type = %s LIMIT 1',
			$tenant_id,
			$type
		);
	}

	/**
	 * Record an acceptance.
	 *
	 * The table carries UNIQUE KEY (tenant_id, type), so a tenant holds one row per waiver type and
	 * re-accepting a new version updates it in place. The audit trail of who accepted what and when
	 * lives in igbz_audit, not here — this row answers "is consent current", not "what happened".
	 *
	 * @return array{ok:bool,error:string}
	 */
	public function accept( int $tenant_id, int $user_id, string $type = self::TYPE_PAYMENT_WITHOUT_NID ): array {
		if ( $tenant_id <= 0 || $user_id <= 0 ) {
			return [ 'ok' => false, 'error' => __( 'A store and a user are required.', 'igbz-suite' ) ];
		}

		$data = [
			'tenant_id'    => $tenant_id,
			'type'         => $type,
			'version'      => self::CURRENT_VERSION,
			'accepted_by'  => $user_id,
			'accepted_at'  => current_time( 'mysql', true ),
			'ip'           => $this->request_ip(),
			'content_hash' => $this->current_hash( $type ),
		];

		$existing = $this->find( $tenant_id, $type );
		if ( null !== $existing ) {
			$this->db->update( 'ig_legal_agreements', $data, [ 'id' => (int) $existing['id'] ] );
		} else {
			$this->db->insert( 'ig_legal_agreements', $data );
		}

		// A legal acceptance is exactly the kind of event that must survive log pruning.
		$this->logger->info(
			'legal',
			'Legal waiver accepted',
			[
				'tenant_id' => $tenant_id,
				'user_id'   => $user_id,
				'type'      => $type,
				'version'   => self::CURRENT_VERSION,
			]
		);

		return [ 'ok' => true, 'error' => '' ];
	}

	/**
	 * The waiver text shown to the admin.
	 *
	 * Filterable so the wording can be set from legal review without touching code — but note that
	 * changing it changes the hash, which correctly invalidates prior acceptances.
	 */
	public function text( string $type = self::TYPE_PAYMENT_WITHOUT_NID ): string {
		$text = __(
			'National-id matching is switched off for this store. By accepting, the store administrator confirms that bank payments will be taken without verifying that the payer\'s mobile number and national id belong to the same person, and accepts that all resulting consequences and liability rest with the administrator alone.',
			'igbz-suite'
		);

		/**
		 * @param string $text The waiver wording.
		 * @param string $type The waiver type.
		 */
		return (string) apply_filters( 'igbz_legal_waiver_text', $text, $type );
	}

	/**
	 * Fingerprint of the exact wording plus version, so we can tell later what was agreed to.
	 */
	public function current_hash( string $type = self::TYPE_PAYMENT_WITHOUT_NID ): string {
		return hash( 'sha256', self::CURRENT_VERSION . '|' . $this->text( $type ) );
	}

	private function request_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return substr( $ip, 0, 64 );
	}
}
