<?php
namespace IGBZ\Suite\Modules\RestApi\Auth;

use IGBZ\Suite\Support\Capabilities;
use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Access + refresh token lifecycle backed by the `igbz_api_tokens` table.
 *
 * Port note: the nopCommerce API issued a 30-day JWT with no refresh, no jti and no way to revoke
 * it — a stolen phone kept API access for a month. Here every access token carries a `jti` that
 * must exist and be unrevoked in the database, refresh tokens are stored as SHA-256 hashes and
 * rotate on every use, and revoking a device or a user kills the session immediately.
 */
final class TokenService {

	public function __construct( private Db $db, private Logger $logger ) {}

	public function secret(): string {
		$secret = igbz()->settings()->string( 'api.jwt_secret', '' );
		if ( '' === $secret ) {
			$secret = Crypto::token( 32 );
			igbz()->settings()->set( 'api.jwt_secret', $secret );
		}
		return $secret;
	}

	public function access_ttl(): int {
		return max( 300, min( 86400, igbz()->settings()->int( 'api.jwt_ttl', 3600 ) ) );
	}

	public function refresh_ttl(): int {
		return max( 3600, min( 31536000, igbz()->settings()->int( 'api.refresh_ttl', 2592000 ) ) );
	}

	/**
	 * Phase 66: the rotation overlap window. A mobile client on a flaky network can
	 * have its refresh response die in transit — the old token is already burned and
	 * the user is logged out for no reason. Within this window a replayed
	 * ROTATED token gets one fresh pair instead of killing the device; outside it
	 * (or for explicitly revoked tokens) the theft response stays total. 0 disables.
	 */
	public function refresh_grace_seconds(): int {
		return max( 0, min( 300, igbz()->settings()->int( 'api.refresh_grace_seconds', 30 ) ) );
	}

	/**
	 * Issue a fresh pair.
	 *
	 * @return array{
	 *   access_token:string, refresh_token:string, token_type:string,
	 *   expires_in:int, refresh_expires_in:int, user:array<string,mixed>
	 * }
	 */
	public function issue( int $user_id, int $tenant_id = 0, string $device_id = '' ): array {
		$now     = time();
		$jti     = Crypto::token( 16 );
		$refresh = Crypto::token( 32 );

		$claims = [
			'iss'    => Jwt::issuer(),
			'sub'    => $user_id,
			'jti'    => $jti,
			'iat'    => $now,
			'nbf'    => $now,
			'exp'    => $now + $this->access_ttl(),
			'tenant' => $tenant_id,
			'device' => $device_id,
			'scope'  => $this->scopes( $user_id ),
		];

		$this->db->insert(
			'api_tokens',
			[
				'tenant_id'          => $tenant_id,
				'user_id'            => $user_id,
				'jti'                => $jti,
				'refresh_hash'       => hash( 'sha256', $refresh ),
				'device_id'          => $device_id,
				'issued_at'          => gmdate( 'Y-m-d H:i:s', $now ),
				'expires_at'         => gmdate( 'Y-m-d H:i:s', $now + $this->access_ttl() ),
				'refresh_expires_at' => gmdate( 'Y-m-d H:i:s', $now + $this->refresh_ttl() ),
				'last_used_at'       => gmdate( 'Y-m-d H:i:s', $now ),
			]
		);

		$this->logger->info( 'api', 'Token issued', [ 'user_id' => $user_id, 'device_id' => $device_id ] );

		return [
			'access_token'       => Jwt::encode( $claims, $this->secret() ),
			'refresh_token'      => $refresh,
			'token_type'         => 'Bearer',
			'expires_in'         => $this->access_ttl(),
			'refresh_expires_in' => $this->refresh_ttl(),
			'user'               => $this->user_payload( $user_id, $tenant_id ),
		];
	}

	/**
	 * Validate a bearer token: signature, expiry and the database row behind its `jti`.
	 *
	 * @return array{ok:bool,error:string,user_id:int,tenant_id:int,jti:string,claims:array<string,mixed>}
	 */
	public function validate( string $token ): array {
		$fail = static fn ( string $error ): array => [
			'ok'        => false,
			'error'     => $error,
			'user_id'   => 0,
			'tenant_id' => 0,
			'jti'       => '',
			'claims'    => [],
		];

		$decoded = Jwt::decode( $token, $this->secret() );
		if ( ! $decoded['ok'] ) {
			return $fail( $decoded['error'] );
		}

		$claims = $decoded['claims'];
		$jti    = (string) ( $claims['jti'] ?? '' );
		if ( '' === $jti ) {
			return $fail( 'missing_jti' );
		}

		$row = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'api_tokens' ) . ' WHERE jti = %s', $jti );
		if ( ! $row ) {
			return $fail( 'unknown_token' );
		}
		if ( null !== $row['revoked_at'] ) {
			return $fail( 'revoked' );
		}

		$user_id = (int) $row['user_id'];
		if ( ! get_userdata( $user_id ) ) {
			return $fail( 'user_gone' );
		}

		return [
			'ok'        => true,
			'error'     => '',
			'user_id'   => $user_id,
			'tenant_id' => (int) $row['tenant_id'],
			'jti'       => $jti,
			'claims'    => $claims,
		];
	}

	/**
	 * Rotate: the presented refresh token is revoked and a brand-new pair is issued. Presenting a
	 * refresh token twice therefore fails, which is what makes theft detectable.
	 *
	 * @return array{ok:bool,error:string,tokens:array<string,mixed>}
	 */
	public function refresh( string $refresh_token, string $device_id = '' ): array {
		$hash = hash( 'sha256', $refresh_token );
		$row  = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'api_tokens' ) . ' WHERE refresh_hash = %s', $hash );

		if ( ! $row ) {
			return [ 'ok' => false, 'error' => 'unknown_refresh_token', 'tokens' => [] ];
		}
		if ( null !== $row['revoked_at'] ) {
			// A revoked refresh token being replayed means the value leaked — unless it was
			// consumed by ROTATION moments ago and the response never reached the client.
			// Rotation stamps rotated_at; an explicit revoke (logout / device kill) never does,
			// so the theft response stays total for everything except a fresh rotation.
			if ( null !== $row['rotated_at'] && $this->refresh_grace_seconds() > 0 ) {
				$age = time() - (int) strtotime( (string) $row['rotated_at'] . ' UTC' );
				if ( $age >= 0 && $age <= $this->refresh_grace_seconds() ) {
					// Consume the grace marker atomically: exactly ONE retry per rotation.
					$consumed = (int) $this->db->query(
						'UPDATE ' . $this->db->table( 'api_tokens' ) . ' SET rotated_at = NULL WHERE id = %d AND rotated_at IS NOT NULL',
						(int) $row['id']
					);
					if ( $consumed >= 1 ) {
						$this->logger->warning( 'api', 'Refresh token replayed inside the grace window — reissuing once', [ 'user_id' => (int) $row['user_id'], 'age' => $age ] );
						return [
							'ok'     => true,
							'error'  => '',
							'tokens' => $this->issue( (int) $row['user_id'], (int) $row['tenant_id'], '' !== $device_id ? $device_id : (string) $row['device_id'] ),
						];
					}
				}
			}

			// Everything else is theft: kill the whole device.
			$this->revoke_device( (int) $row['user_id'], (string) $row['device_id'] );
			$this->logger->warning( 'api', 'Refresh token replay detected', [ 'user_id' => (int) $row['user_id'] ] );
			return [ 'ok' => false, 'error' => 'refresh_token_revoked', 'tokens' => [] ];
		}
		if ( null !== $row['refresh_expires_at'] && strtotime( (string) $row['refresh_expires_at'] . ' UTC' ) < time() ) {
			return [ 'ok' => false, 'error' => 'refresh_token_expired', 'tokens' => [] ];
		}

		// Atomic claim: exactly one request can burn this refresh token. Two parallel
		// presentations race on the UPDATE; the loser sees zero affected rows, which is the
		// same evidence as a replay, so the whole device goes down with it.
		$now_mysql = current_time( 'mysql', true );
		$claimed   = (int) $this->db->query(
			'UPDATE ' . $this->db->table( 'api_tokens' ) . ' SET revoked_at = %s, rotated_at = %s WHERE id = %d AND revoked_at IS NULL',
			$now_mysql,
			$now_mysql,
			(int) $row['id']
		);
		if ( $claimed < 1 ) {
			// The row was claimed between our read and this write — the classic double
			// refresh of a flaky client. The winner's stamp is now on the row, so one
			// grace retry answers the loser honestly instead of killing the device.
			$fresh = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'api_tokens' ) . ' WHERE id = %d', (int) $row['id'] );
			if ( $fresh && null !== $fresh['rotated_at'] ) {
				return $this->refresh( $refresh_token, $device_id ); // re-enters on the rotated row → grace path
			}
			$this->revoke_device( (int) $row['user_id'], (string) $row['device_id'] );
			$this->logger->warning( 'api', 'Concurrent refresh token reuse detected', [ 'user_id' => (int) $row['user_id'] ] );
			return [ 'ok' => false, 'error' => 'refresh_token_revoked', 'tokens' => [] ];
		}

		return [
			'ok'     => true,
			'error'  => '',
			'tokens' => $this->issue(
				(int) $row['user_id'],
				(int) $row['tenant_id'],
				'' !== $device_id ? $device_id : (string) $row['device_id']
			),
		];
	}

	public function revoke_jti( string $jti ): bool {
		return $this->db->update( 'api_tokens', [ 'revoked_at' => current_time( 'mysql', true ) ], [ 'jti' => $jti ] ) >= 0;
	}

	public function revoke_device( int $user_id, string $device_id ): int {
		if ( '' === $device_id ) {
			return 0;
		}
		return $this->db->query(
			'UPDATE ' . $this->db->table( 'api_tokens' ) . ' SET revoked_at = %s WHERE user_id = %d AND device_id = %s AND revoked_at IS NULL',
			current_time( 'mysql', true ),
			$user_id,
			$device_id
		);
	}

	public function revoke_all_for_user( int $user_id ): int {
		return $this->db->query(
			'UPDATE ' . $this->db->table( 'api_tokens' ) . ' SET revoked_at = %s WHERE user_id = %d AND revoked_at IS NULL',
			current_time( 'mysql', true ),
			$user_id
		);
	}

	/**
	 * Housekeeping. Revoked and long-expired rows are dead weight; the table is on the hot path
	 * of every authenticated request, so it gets trimmed daily rather than growing forever.
	 */
	public function prune_expired( int $grace_days = 7 ): int {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 0, $grace_days ) * DAY_IN_SECONDS ) );

		// Phase 20: bounded batches on the hottest table of the API path.
		return $this->db->delete_batches(
			'api_tokens',
			'( refresh_expires_at IS NOT NULL AND refresh_expires_at < %s ) OR ( revoked_at IS NOT NULL AND revoked_at < %s )',
			[ $cutoff, $cutoff ]
		);
	}

	public function active_session_count(): int {
		return (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'api_tokens' ) . '
			 WHERE revoked_at IS NULL AND refresh_expires_at > %s',
			current_time( 'mysql', true )
		);
	}

	public function touch( string $jti ): void {
		$this->db->update( 'api_tokens', [ 'last_used_at' => current_time( 'mysql', true ) ], [ 'jti' => $jti ] );
	}

	/** @return array<int,array<string,mixed>> */
	public function sessions( int $user_id ): array {
		return $this->db->results(
			'SELECT jti, device_id, issued_at, expires_at, last_used_at, revoked_at
			 FROM ' . $this->db->table( 'api_tokens' ) . '
			 WHERE user_id = %d ORDER BY id DESC LIMIT 50',
			$user_id
		);
	}

	/** @return string[] */
	public function scopes( int $user_id ): array {
		$scopes = [ 'account' ];

		foreach (
			[
				Capabilities::MANAGE_OWN_TENANT => 'tenant',
				Capabilities::MANAGE_TENANTS    => 'platform',
				Capabilities::MANAGE_INSTAGRAM  => 'instagram',
				Capabilities::MANAGE_LMS        => 'lms',
			] as $cap => $scope
		) {
			if ( user_can( $user_id, $cap ) || user_can( $user_id, 'manage_options' ) ) {
				$scopes[] = $scope;
			}
		}

		return array_values( array_unique( $scopes ) );
	}

	/** @return array<string,mixed> */
	public function user_payload( int $user_id, int $tenant_id = 0 ): array {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return [];
		}

		return [
			'id'           => $user_id,
			'display_name' => $user->display_name,
			'email'        => $user->user_email,
			'phone'        => (string) get_user_meta( $user_id, 'igbz_phone', true ),
			'avatar'       => get_avatar_url( $user_id ),
			'roles'        => array_values( (array) $user->roles ),
			'scopes'       => $this->scopes( $user_id ),
			'tenant_id'    => $tenant_id,
		];
	}
}
