<?php
/**
 * Phase 49 — Zernio connection (ADR-0004 §5): one store gets exactly one
 * profile, the tenant↔profile↔account mapping is decided by the backend,
 * keys rotate and revoke honestly, webhook identity is a timed HMAC, and
 * erasure leaves no row behind.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\Instagram\Gateways\ZernioAdapterInterface;
use IGBZ\Suite\Modules\Instagram\Services\ZernioConnectionService;
use IGBZ\Suite\Support\Db;

/** A scripted Zernio provider. */
final class ScriptedZernio implements ZernioAdapterInterface {

	public int $key_issuances = 0;

	/** @var array<int,string> */
	public array $issued_keys = [];

	public function __construct( public bool $configured = true ) {}

	public function is_configured(): bool {
		return $this->configured;
	}

	public function create_profile( string $store_slug ): array {
		return [ 'ok' => true, 'profile_id' => 'prof-' . $store_slug, 'error' => '' ];
	}

	public function issue_profile_key( string $profile_id ): array {
		$key = 'zernio-key-' . (string) ( ++$this->key_issuances );
		$this->issued_keys[] = $key;

		return [ 'ok' => true, 'key' => $key, 'error' => '' ];
	}

	public function revoke_profile_key( string $profile_id ): array {
		return [ 'ok' => true, 'error' => '' ];
	}

	public function connect_account( string $profile_id ): array {
		return [ 'ok' => true, 'account_id' => 'acct-1', 'instagram_account_id' => 'ig-77', 'error' => '' ];
	}
}

/** In-memory engine for the connection registry. */
final class ZernioDb extends wpdb {

	/** @var array<string,array<int,array<string,mixed>>> */
	public array $tables = [ 'ig_zernio_profiles' => [] ];

	private int $next_id = 1;

	/** @return array<string,mixed>|null */
	public function first_row(): ?array {
		$rows = array_values( $this->tables['ig_zernio_profiles'] );
		return $rows[0] ?? null;
	}

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'ig_zernio_profiles' ) && preg_match( "/tenant_id = '(\d+)'/", $sql, $m ) ) {
			foreach ( $this->tables['ig_zernio_profiles'] as $row ) {
				if ( (string) $row['tenant_id'] === $m[1] ) {
					return $row;
				}
			}
			return null;
		}

		return parent::get_row( $sql, $output );
	}

	public function insert( string $table, array $data, $format = null ): int|bool {
		$this->queries[] = 'INSERT ' . $table;

		if ( str_ends_with( $table, 'ig_zernio_profiles' ) ) {
			$id = $this->next_id++;
			$data['id'] = $id;
			$this->tables['ig_zernio_profiles'][ $id ] = $data;
			$this->insert_id = $id;
			return $id;
		}

		return parent::insert( $table, $data, $format );
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		$this->queries[] = 'UPDATE ' . $table;

		if ( str_ends_with( $table, 'ig_zernio_profiles' ) ) {
			$changed = 0;
			foreach ( $this->tables['ig_zernio_profiles'] as $id => $row ) {
				$hit = true;
				foreach ( $where as $column => $value ) {
					if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) {
						$hit = false;
						break;
					}
				}
				if ( $hit ) {
					$this->tables['ig_zernio_profiles'][ $id ] = array_merge( $row, $data );
					++$changed;
				}
			}
			return $changed;
		}

		return parent::update( $table, $data, $where, $format, $where_format );
	}

	public function delete( string $table, array $where, $where_format = null ): int|bool {
		$this->queries[] = 'DELETE ' . $table;

		if ( str_ends_with( $table, 'ig_zernio_profiles' ) ) {
			$deleted = 0;
			foreach ( $this->tables['ig_zernio_profiles'] as $id => $row ) {
				$hit = true;
				foreach ( $where as $column => $value ) {
					if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) {
						$hit = false;
						break;
					}
				}
				if ( $hit ) {
					unset( $this->tables['ig_zernio_profiles'][ $id ] );
					++$deleted;
				}
			}
			return $deleted;
		}

		return parent::delete( $table, $where, $where_format );
	}
}

final class ZernioConnectTest extends TestCase {

	private ZernioDb $zdb;
	private ZernioConnectionService $service;
	private ScriptedZernio $zernio;

	private function boot(): void {
		igbz_test_reset_settings();

		$this->zdb       = new ZernioDb();
		$GLOBALS['wpdb'] = $this->zdb;

		$db  = new Db();
		$ref = new ReflectionProperty( Db::class, 'is_sqlite' );
		$ref->setValue( $db, true );

		$this->zernio  = new ScriptedZernio();
		$this->service = new ZernioConnectionService( $db, new IGBZ\Suite\Support\Logger( igbz()->settings() ), $this->zernio );
	}

	private function row(): ?array {
		return $this->zdb->first_row();
	}

	public function run(): void {
		$this->test_one_store_gets_exactly_one_profile();
		$this->test_the_mapping_is_decided_by_the_backend();
		$this->test_keys_rotate_and_revoke_honestly();
		$this->test_webhook_identity_is_a_timed_hmac();
		$this->test_erasure_leaves_no_row_behind();
	}

	public function test_one_store_gets_exactly_one_profile(): void {
		$this->boot();

		$this->assert_true( $this->service->provision( 5, 'store-five' )['ok'], 'the first provision lands' );
		$row = $this->row();
		$this->assert_same( 'prof-store-five', (string) $row['profile_id'], 'the profile belongs to the store' );
		$this->assert_same( 'provisioned', (string) $row['status'], 'provisioned, not yet connected' );
		$this->assert_false( str_contains( (string) $row['key_enc'], 'zernio-key' ), 'the key is encrypted at rest' );
		$this->assert_same( 1, (int) $row['key_version'], 'the first key is version one' );

		$this->assert_same( 'already_provisioned', $this->service->provision( 5, 'store-five' )['error'], 'a store never gets a second profile' );
	}

	public function test_the_mapping_is_decided_by_the_backend(): void {
		$this->boot();
		$this->service->provision( 5, 'store-five' );

		$this->assert_same( 'bad_state', $this->service->attach_account( 9 )['error'], 'an unknown tenant cannot attach' );
		$this->assert_true( $this->service->attach_account( 5 )['ok'], 'the provisioned store attaches' );

		$row = $this->row();
		$this->assert_same( 'connected', (string) $row['status'], 'the connection is recorded' );
		$this->assert_same( 'ig-77', (string) $row['instagram_account_id'], 'the Instagram account is mapped' );

		$this->assert_true( $this->service->resolve( 5, 'prof-store-five', 'acct-1' )['ok'], 'the true mapping resolves' );
		$this->assert_same( 'profile_mismatch', $this->service->resolve( 5, 'prof-someone-else' )['error'], 'a claimed foreign profile is refused' );
		$this->assert_same( 'account_mismatch', $this->service->resolve( 5, '', 'acct-999' )['error'], 'a claimed foreign account is refused' );
		$this->assert_same( 'no_profile', $this->service->resolve( 6, 'prof-store-five' )['error'], 'another store cannot borrow the profile' );

		$this->assert_same( 'zernio-key-1', $this->service->key_for( 5 ), 'the connected store receives its own key' );
		$this->assert_same( '', $this->service->key_for( 6 ), 'a neighbour receives nothing' );
	}

	public function test_keys_rotate_and_revoke_honestly(): void {
		$this->boot();
		$this->service->provision( 5, 'store-five' );
		$this->service->attach_account( 5 );

		$this->assert_true( $this->service->rotate( 5 )['ok'], 'rotation lands' );
		$row = $this->row();
		$this->assert_same( 2, (int) $row['key_version'], 'the version climbs' );
		$this->assert_same( 'zernio-key-2', $this->service->key_for( 5 ), 'the new key is served' );

		$this->assert_true( $this->service->revoke( 5 )['ok'], 'revocation lands' );
		$row = $this->row();
		$this->assert_same( 'revoked', (string) $row['status'], 'the status says revoked' );
		$this->assert_true( null === $row['revoked_at'] || '' !== (string) $row['revoked_at'], 'the revocation is dated' );
		$this->assert_same( '', $this->service->key_for( 5 ), 'a revoked store holds no key' );
		$this->assert_same( 'bad_state', $this->service->rotate( 5 )['error'], 'a revoked key cannot rotate' );

		$this->assert_true( $this->service->provision( 5, 'store-five' )['ok'], 'a revoked store may reconnect' );
		$this->assert_same( 3, (int) $this->row()['key_version'], 'the version never rewinds' );
	}

	public function test_webhook_identity_is_a_timed_hmac(): void {
		$this->boot();
		$this->service->provision( 5, 'store-five' );

		$payload   = '{"event":"comment.created"}';
		$timestamp = time();
		$signature = $this->service->sign_webhook( 5, $payload, $timestamp );
		$this->assert_true( '' !== $signature, 'the profile signs with its own secret' );

		$this->assert_true( $this->service->verify_webhook( 5, $payload, $timestamp, $signature ), 'a genuine webhook passes' );
		$this->assert_false( $this->service->verify_webhook( 5, '{"event":"tampered"}', $timestamp, $signature ), 'a tampered payload fails' );
		$this->assert_false( $this->service->verify_webhook( 5, $payload, $timestamp - 3600, $this->service->sign_webhook( 5, $payload, $timestamp - 3600 ) ), 'a stale timestamp is a replay suspect' );
		$this->assert_false( $this->service->verify_webhook( 6, $payload, $timestamp, $signature ), 'another store cannot use this signature' );
	}

	public function test_erasure_leaves_no_row_behind(): void {
		$this->boot();
		$this->service->provision( 5, 'store-five' );

		$this->service->erase( 5 );
		$this->assert_true( null === $this->row(), 'the tenant row is gone' );
		$this->assert_same( '', $this->service->key_for( 5 ), 'nothing decrypts after erasure' );
	}
}
