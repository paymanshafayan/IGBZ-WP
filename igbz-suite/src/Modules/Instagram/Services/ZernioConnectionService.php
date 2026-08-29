<?php
namespace IGBZ\Suite\Modules\Instagram\Services;

use IGBZ\Suite\Modules\Instagram\Gateways\ZernioAdapterInterface;
use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Zernio connection, profile mapping, key lifecycle and webhook identity
 * (phase 49/50, ADR-0004 §5).
 *
 * The rules this service enforces are the ADR's rules:
 * - exactly one profile per store, and the tenant↔profile↔account↔Instagram
 *   mapping lives in the backend — raw client input can claim anything, the
 *   mapping decides;
 * - the central Zernio key stays in the secret store and never travels to a
 *   store's context; stores only ever hold a profile-scoped key, encrypted
 *   at rest;
 * - rotation replaces the key and bumps the version; revocation clears it and
 *   tells the provider by key id;
 * - webhook identity is an HMAC over payload+timestamp inside a replay
 *   window, with a per-profile secret;
 * - connection is two-step official OAuth: start (browser) then sync
 *   (backend pulls the accounts and persists the mapping).
 */
final class ZernioConnectionService {

	public const STATUS_PENDING     = 'pending';
	public const STATUS_PROVISIONED = 'provisioned';
	public const STATUS_CONNECTED   = 'connected';
	public const STATUS_REVOKED     = 'revoked';

	/** Webhook timestamps older than this are replay suspects. */
	public const WEBHOOK_WINDOW_SECONDS = 300;

	public function __construct(
		private Db $db,
		private Logger $logger,
		private ZernioAdapterInterface $adapter
	) {}

	// ------------------------------------------------------------ profiles

	/** @return array<string,mixed>|null */
	public function profile( int $tenant_id ): ?array {
		$row = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'ig_zernio_profiles' ) . ' WHERE tenant_id = %d',
			$tenant_id
		);

		return $row ?: null;
	}

	/** Provision the store's profile under the central account. One store, one profile. */
	public function provision( int $tenant_id, string $store_slug ): array {
		$existing = $this->profile( $tenant_id );
		if ( null !== $existing && self::STATUS_REVOKED !== (string) $existing['status'] ) {
			return [ 'ok' => false, 'error' => 'already_provisioned' ];
		}
		if ( ! $this->adapter->is_configured() ) {
			return [ 'ok' => false, 'error' => 'not_configured' ];
		}

		$result = $this->adapter->create_profile( $store_slug );
		if ( ! $result['ok'] ) {
			return [ 'ok' => false, 'error' => 'provider_failed' ];
		}

		$key = $this->adapter->issue_profile_key( (string) $result['profile_id'] );
		if ( ! $key['ok'] ) {
			return [ 'ok' => false, 'error' => 'key_issuance_failed' ];
		}

		$now = current_time( 'mysql', true );
		$data = [
			'profile_id'         => (string) $result['profile_id'],
			'status'             => self::STATUS_PROVISIONED,
			'key_enc'            => Crypto::encrypt( (string) $key['key'] ),
			'key_id'             => (string) ( $key['key_id'] ?? '' ),
			'key_version'        => 1,
			'webhook_secret_enc' => Crypto::encrypt( bin2hex( Crypto::token( 16 ) ) ),
			'updated_at'         => $now,
		];

		if ( null !== $existing ) {
			// Re-provisioning a revoked store keeps the version climbing; it never rewinds.
			$data['key_version'] = (int) $existing['key_version'] + 1;
			$this->db->update( 'ig_zernio_profiles', $data + [ 'revoked_at' => null, 'status' => self::STATUS_PROVISIONED ], [ 'id' => (int) $existing['id'] ] );
		} else {
			$this->db->insert( 'ig_zernio_profiles', $data + [ 'tenant_id' => $tenant_id, 'account_id' => '', 'instagram_account_id' => '', 'connected_at' => null, 'revoked_at' => null, 'created_at' => $now ] );
		}

		$this->logger->info( 'zernio', 'Profile provisioned', [ 'tenant' => $tenant_id, 'profile' => (string) $result['profile_id'] ] );

		return [ 'ok' => true, 'error' => '' ];
	}

	/**
	 * Step one of the official OAuth connect: start it and hand the admin the
	 * browser URL. Nothing is "connected" until the account is actually
	 * visible in the profile — that is sync_accounts().
	 *
	 * @return array{ok:bool,auth_url:string,error:string}
	 */
	public function start_connect( int $tenant_id ): array {
		$profile = $this->profile( $tenant_id );
		if ( null === $profile || self::STATUS_PROVISIONED !== (string) $profile['status'] ) {
			return [ 'ok' => false, 'auth_url' => '', 'error' => 'bad_state' ];
		}

		$result = $this->adapter->start_connect( (string) $profile['profile_id'] );
		if ( ! $result['ok'] ) {
			return [ 'ok' => false, 'auth_url' => '', 'error' => 'provider_failed' ];
		}

		return [ 'ok' => true, 'auth_url' => (string) $result['auth_url'], 'error' => '' ];
	}

	/**
	 * Step two: pull the accounts currently attached to the profile and
	 * persist the mapping. The backend decides what lands where — the provider
	 * only reports.
	 *
	 * @return array{ok:bool,error:string}
	 */
	public function sync_accounts( int $tenant_id ): array {
		$profile = $this->profile( $tenant_id );
		if ( null === $profile || in_array( (string) $profile['status'], [ self::STATUS_PENDING, self::STATUS_REVOKED ], true ) ) {
			return [ 'ok' => false, 'error' => 'bad_state' ];
		}

		$result = $this->adapter->list_accounts( (string) $profile['profile_id'] );
		if ( ! $result['ok'] ) {
			return [ 'ok' => false, 'error' => 'provider_failed' ];
		}

		$account = null;
		foreach ( (array) $result['accounts'] as $candidate ) {
			if ( 'instagram' === (string) ( $candidate['platform'] ?? 'instagram' ) ) {
				$account = $candidate;
				break;
			}
		}
		if ( null === $account && 1 === count( (array) $result['accounts'] ) ) {
			// A single attached account is the Instagram one by construction of the connect flow.
			$account = (array) array_values( (array) $result['accounts'] )[0];
		}
		if ( null === $account ) {
			return [ 'ok' => false, 'error' => 'no_account_yet' ];
		}

		$account_id = (string) $account['account_id'];
		$this->db->update(
			'ig_zernio_profiles',
			[
				'account_id'           => $account_id,
				// Zernio's account id is the platform (Meta) account id itself.
				'instagram_account_id' => $account_id,
				'status'               => self::STATUS_CONNECTED,
				'connected_at'         => current_time( 'mysql', true ),
				'updated_at'           => current_time( 'mysql', true ),
			],
			[ 'id' => (int) $profile['id'] ]
		);

		$this->logger->info( 'zernio', 'Account mapping synced', [ 'tenant' => $tenant_id, 'account' => $account_id ] );

		return [ 'ok' => true, 'error' => '' ];
	}

	// ------------------------------------------------------------- mapping

	/**
	 * The backend decides whether raw client identifiers belong to this
	 * tenant. A profile alone never substitutes for authorization.
	 *
	 * @return array{ok:bool,error:string}
	 */
	public function resolve( int $tenant_id, string $claimed_profile_id, string $claimed_account_id = '' ): array {
		$profile = $this->profile( $tenant_id );
		if ( null === $profile ) {
			return [ 'ok' => false, 'error' => 'no_profile' ];
		}
		if ( self::STATUS_CONNECTED !== (string) $profile['status'] ) {
			return [ 'ok' => false, 'error' => 'not_connected' ];
		}
		if ( '' !== $claimed_profile_id && $claimed_profile_id !== (string) $profile['profile_id'] ) {
			return [ 'ok' => false, 'error' => 'profile_mismatch' ];
		}
		if ( '' !== $claimed_account_id && $claimed_account_id !== (string) $profile['account_id'] ) {
			return [ 'ok' => false, 'error' => 'account_mismatch' ];
		}

		return [ 'ok' => true, 'error' => '' ];
	}

	// ---------------------------------------------------------------- keys

	/** The store's profile-scoped key, decrypted — only while connected. */
	public function key_for( int $tenant_id ): string {
		$profile = $this->profile( $tenant_id );
		if ( null === $profile || self::STATUS_CONNECTED !== (string) $profile['status'] ) {
			return '';
		}

		return (string) ( Crypto::decrypt( (string) $profile['key_enc'] ) ?? '' );
	}

	/** Rotate the profile-scoped key. The old key is dropped, the version climbs. */
	public function rotate( int $tenant_id ): array {
		$profile = $this->profile( $tenant_id );
		if ( null === $profile || in_array( (string) $profile['status'], [ self::STATUS_REVOKED, self::STATUS_PENDING ], true ) ) {
			return [ 'ok' => false, 'error' => 'bad_state' ];
		}

		$result = $this->adapter->issue_profile_key( (string) $profile['profile_id'] );
		if ( ! $result['ok'] ) {
			return [ 'ok' => false, 'error' => 'provider_failed' ];
		}

		$this->db->update(
			'ig_zernio_profiles',
			[
				'key_enc'     => Crypto::encrypt( (string) $result['key'] ),
				'key_id'      => (string) ( $result['key_id'] ?? '' ),
				'key_version' => (int) $profile['key_version'] + 1,
				'updated_at'  => current_time( 'mysql', true ),
			],
			[ 'id' => (int) $profile['id'] ]
		);

		return [ 'ok' => true, 'error' => '' ];
	}

	/** Revoke: the provider is told by key id, the key is cleared, the stamp is dated. */
	public function revoke( int $tenant_id ): array {
		$profile = $this->profile( $tenant_id );
		if ( null === $profile || self::STATUS_REVOKED === (string) $profile['status'] ) {
			return [ 'ok' => false, 'error' => 'bad_state' ];
		}

		if ( '' !== (string) $profile['key_id'] ) {
			$this->adapter->revoke_profile_key( (string) $profile['key_id'] );
		}

		$this->db->update(
			'ig_zernio_profiles',
			[
				'status'     => self::STATUS_REVOKED,
				'key_enc'    => null,
				'key_id'     => '',
				'updated_at' => current_time( 'mysql', true ),
				'revoked_at' => current_time( 'mysql', true ),
			],
			[ 'id' => (int) $profile['id'] ]
		);

		$this->logger->info( 'zernio', 'Profile revoked', [ 'tenant' => $tenant_id ] );

		return [ 'ok' => true, 'error' => '' ];
	}

	// ------------------------------------------------------------- webhook

	/** Sign a webhook payload+timestamp with the profile's secret. */
	public function sign_webhook( int $tenant_id, string $payload, int $timestamp ): string {
		$secret = $this->webhook_secret( $tenant_id );

		return '' === $secret ? '' : Crypto::hmac( $payload . '.' . $timestamp, $secret );
	}

	/**
	 * Webhook identity: signature over payload+timestamp, inside the replay
	 * window. Anything else never reaches a handler.
	 */
	public function verify_webhook( int $tenant_id, string $payload, int $timestamp, string $signature ): bool {
		if ( abs( time() - $timestamp ) > self::WEBHOOK_WINDOW_SECONDS ) {
			return false;
		}

		$expected = $this->sign_webhook( $tenant_id, $payload, $timestamp );
		if ( '' === $expected || '' === $signature ) {
			return false;
		}

		return Crypto::hmac_equals( $expected, $signature );
	}

	private function webhook_secret( int $tenant_id ): string {
		$profile = $this->profile( $tenant_id );
		if ( null === $profile || '' === (string) $profile['webhook_secret_enc'] ) {
			return '';
		}

		return (string) ( Crypto::decrypt( (string) $profile['webhook_secret_enc'] ) ?? '' );
	}

	// --------------------------------------------------------------- erase

	/**
	 * Data erasure: the provider-side profile is deleted (best effort — the
	 * row must go regardless of provider reachability) and the tenant's row
	 * leaves no trace.
	 */
	public function erase( int $tenant_id ): void {
		$profile = $this->profile( $tenant_id );
		if ( null !== $profile && '' !== (string) $profile['profile_id'] ) {
			$this->adapter->delete_profile( (string) $profile['profile_id'] );
		}

		$this->db->delete( 'ig_zernio_profiles', [ 'tenant_id' => $tenant_id ] );
	}
}
