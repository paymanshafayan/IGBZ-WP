<?php
/**
 * Phase 50 — the controlled legacy→Zernio migration (ADR-0004 §6).
 *
 * Per-tenant and idempotent: provisioning the store profile, stamping the
 * legacy credentials, the journal, the due-list, and the honest pending state
 * while the provider is unreachable.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\Instagram\Gateways\ZernioAdapterInterface;
use IGBZ\Suite\Modules\Instagram\Services\SocialMigrationService;
use IGBZ\Suite\Modules\Instagram\Services\ZernioConnectionService;
use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

/** Scripted provider for the migration. */
final class MigrationScriptedZernio implements ZernioAdapterInterface {

	public int $provision_calls = 0;

	public function __construct( public bool $configured = true ) {}

	public function is_configured(): bool {
		return $this->configured;
	}

	public function create_profile( string $store_slug ): array {
		++$this->provision_calls;

		return [ 'ok' => true, 'profile_id' => 'prof-' . $store_slug, 'error' => '' ];
	}

	public function issue_profile_key( string $profile_id ): array {
		return [ 'ok' => true, 'key' => 'key-' . $profile_id, 'key_id' => 'kid-' . $profile_id, 'error' => '' ];
	}

	public function revoke_profile_key( string $key_id ): array {
		return [ 'ok' => true, 'error' => '' ];
	}

	public function start_connect( string $profile_id ): array {
		return [ 'ok' => true, 'auth_url' => 'https://connect.zernio.test/' . $profile_id, 'error' => '' ];
	}

	public function list_accounts( string $profile_id ): array {
		return [ 'ok' => true, 'accounts' => [], 'error' => '' ];
	}

	public function delete_profile( string $profile_id ): array {
		return [ 'ok' => true, 'error' => '' ];
	}

	public function publish_content( string $key, string $account_id, array $content ): array {
		return [ 'ok' => false, 'post_id' => '', 'error' => 'not_in_this_test' ];
	}

	public function get_post( string $key, string $post_id ): array {
		return [ 'ok' => false, 'status' => '', 'permalink' => '', 'media_id' => '', 'error' => 'not_in_this_test' ];
	}

	public function retry_post( string $key, string $post_id ): array {
		return [ 'ok' => false, 'error' => 'not_in_this_test' ];
	}

	public function send_direct_message( string $key, string $account_id, string $recipient_id, array $message ): array {
		return [ 'ok' => false, 'message_id' => '', 'error' => 'not_in_this_test' ];
	}

	public function send_story_reply( string $key, string $account_id, string $story_id, string $recipient_id, string $text ): array {
		return [ 'ok' => false, 'message_id' => '', 'error' => 'not_in_this_test' ];
	}

	public function get_inbox( string $key, string $kind, string $cursor = '', int $limit = 50 ): array {
		return [ 'ok' => false, 'items' => [], 'next_cursor' => '', 'error' => 'not_in_this_test' ];
	}

	public function get_analytics( string $key, string $account_id, string $period = '30d' ): array {
		return [ 'ok' => false, 'metrics' => [], 'error' => 'not_in_this_test' ];
	}

	public function get_trending_audio( string $key, int $limit = 20 ): array {
		return [ 'ok' => false, 'audios' => [], 'error' => 'not_in_this_test' ];
	}

	public function account_health( string $key, string $account_id ): array {
		return [ 'ok' => false, 'healthy' => false, 'error' => 'not_in_this_test' ];
	}
}

/**
 * In-memory engine for the migration: profiles, journal and account rows are
 * real rows, so the service's UPDATE/SELECT statements run against state.
 */
final class MigrationDb extends wpdb {

	/** @var array<string,array<int,array<string,mixed>>> tenant_id => rows */
	public array $profiles = [];
	public array $journal  = [];
	public array $accounts = [];

	/** @var array<int,array{id:int,slug:string}> */
	public array $tenants = [];

	private int $next_id = 1;

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'ig_zernio_profiles' ) && preg_match( "/tenant_id = '(\d+)'/", $sql, $m ) ) {
			foreach ( $this->profiles[ $m[1] ] ?? [] as $row ) {
				return $row;
			}
			return null;
		}

		if ( str_contains( $sql, 'ig_social_migration' )
			&& preg_match( "/tenant_id = '(\d+)' AND step = '([a-z_]+)'/", $sql, $m )
		) {
			return $this->journal[ $m[1] ][ $m[2] ] ?? null;
		}

		if ( str_contains( $sql, 'tenants WHERE id' ) && preg_match( "/id = '(\d+)'/", $sql, $m ) ) {
			foreach ( $this->tenants as $t ) {
				if ( (string) $t['id'] === $m[1] ) {
					return $t;
				}
			}
			return null;
		}

		return parent::get_row( $sql, $output );
	}

	public function get_results( string $sql, $output = null ) {
		$this->queries[] = $sql;

		// The due-list: every tenant with an active legacy account or any journal row,
		// minus the fully migrated ones (both steps done).
		if ( str_contains( $sql, 'UNION' ) && str_contains( $sql, 'ig_social_migration' ) ) {
			$due = [];
			foreach ( array_unique( array_merge( array_keys( $this->accounts ), array_keys( $this->journal ) ) ) as $tenant_id ) {
				$tenant_id = (string) $tenant_id;
				if ( (int) $tenant_id <= 0 ) {
					continue;
				}
				$has_active = false;
				foreach ( $this->accounts[ $tenant_id ] ?? [] as $row ) {
					if ( 1 === (int) $row['is_active'] ) {
						$has_active = true;
						break;
					}
				}
				$has_journal = ! empty( $this->journal[ $tenant_id ] );
				$profile_done = isset( $this->journal[ $tenant_id ][ 'profile_ensured' ] )
					&& 'done' === $this->journal[ $tenant_id ][ 'profile_ensured' ]['status'];
				$legacy_done = isset( $this->journal[ $tenant_id ][ 'legacy_deprecated' ] )
					&& 'done' === $this->journal[ $tenant_id ][ 'legacy_deprecated' ]['status'];

				if ( ( $has_active || $has_journal ) && ! ( $profile_done && $legacy_done ) ) {
					$due[] = [ 'tenant_id' => (int) $tenant_id ];
				}
			}
			usort( $due, static fn ( $a, $b ) => (int) $a['tenant_id'] <=> (int) $b['tenant_id'] );

			return array_slice( $due, 0, 20 );
		}

		return [];
	}

	public function get_var( string $sql ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'SELECT COUNT(*)') && str_contains( $sql, 'ig_accounts' ) && preg_match( "/tenant_id = '(\d+)'/", $sql, $m ) ) {
			$dep = str_contains( $sql, 'legacy_deprecated_at IS NOT NULL' );
			$count = 0;
			foreach ( $this->accounts[ $m[1] ] ?? [] as $row ) {
				if ( $dep && empty( $row['legacy_deprecated_at'] ) ) {
					continue;
				}
				++$count;
			}
			return $count;
		}

		return parent::get_var( $sql );
	}

	public function insert( string $table, array $data, $format = null ): int|bool {
		$this->queries[] = 'INSERT ' . $table;

		$id = $this->next_id++;
		$data['id'] = $id;

		if ( str_ends_with( $table, 'ig_zernio_profiles' ) ) {
			$this->profiles[ (string) ( $data['tenant_id'] ?? '' ) ][] = $data;
		} elseif ( str_ends_with( $table, 'ig_social_migration' ) ) {
			$this->journal[ (string) ( $data['tenant_id'] ?? '' ) ][ (string) ( $data['step'] ?? '' ) ] = $data;
		}

		$this->insert_id = $id;

		return $id;
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		$this->queries[] = 'UPDATE ' . $table;

		if ( str_ends_with( $table, 'ig_social_migration' ) ) {
			$changed = 0;
			foreach ( $this->journal as $tenant => $steps ) {
				foreach ( $steps as $step => $row ) {
					if ( (string) $row['id'] === (string) ( $where['id'] ?? '' ) ) {
						$this->journal[ $tenant ][ $step ] = array_merge( $row, $data );
						++$changed;
					}
				}
			}
			return $changed;
		}

		if ( str_ends_with( $table, 'ig_zernio_profiles' ) ) {
			$changed = 0;
			foreach ( $this->profiles as $tenant => $rows ) {
				foreach ( $rows as $i => $row ) {
					if ( (string) $row['id'] === (string) ( $where['id'] ?? '' ) ) {
						$this->profiles[ $tenant ][ $i ] = array_merge( $row, $data );
						++$changed;
					}
				}
			}
			return $changed;
		}

		return parent::update( $table, $data, $where, $format, $where_format );
	}

	public function query( string $sql ): int|bool {
		$this->queries[] = $sql;

		// The legacy stamp: UPDATE ig_accounts SET legacy_deprecated_at = ... WHERE tenant_id AND IS NULL.
		if ( str_starts_with( trim( $sql ), 'UPDATE' ) && str_contains( $sql, 'legacy_deprecated_at' ) && preg_match( "/tenant_id = '(\d+)'/", $sql, $m ) ) {
			$stamped = 0;
			foreach ( $this->accounts[ $m[1] ] ?? [] as $i => $row ) {
				if ( empty( $row['legacy_deprecated_at'] ) ) {
					$this->accounts[ $m[1] ][ $i ]['legacy_deprecated_at'] = gmdate( 'Y-m-d H:i:s' );
					++$stamped;
				}
			}
			return $stamped;
		}

		return parent::query( $sql );
	}

	// ---------------------------------------------------------------- seeds

	public function seed_tenant( int $id, string $slug ): void {
		$this->tenants[] = [ 'id' => $id, 'slug' => $slug ];
	}

	public function seed_account( int $tenant_id, int $id, bool $active = true ): void {
		$this->accounts[ (string) $tenant_id ][ $id ] = [
			'id'                     => $id,
			'tenant_id'              => $tenant_id,
			'is_active'              => $active ? 1 : 0,
			'manus_api_key'          => 'legacy-encrypted-blob',
			'manychat_api_key'       => 'legacy-encrypted-blob',
			'legacy_deprecated_at'   => null,
		];
	}
}

final class SocialMigrationTest extends TestCase {

	private MigrationDb $db;
	private MigrationScriptedZernio $provider;
	private SocialMigrationService $service;

	private function boot( bool $configured = true ): void {
		igbz_test_reset_settings();
		igbz()->settings()->set( 'zernio.central_api_key', 'zk-central' );

		$this->db       = new MigrationDb();
		$GLOBALS['wpdb'] = $this->db;

		$this->provider = new MigrationScriptedZernio( $configured );
		$this->service  = new SocialMigrationService(
			new Db(),
			igbz()->get( 'logger' ),
			new ZernioConnectionService( new Db(), igbz()->get( 'logger' ), $this->provider )
		);
	}

	public function run(): void {
		$this->first_round_provisions_and_stamps();
		$this->second_round_is_a_noop();
		$this->unconfigured_provider_keeps_the_tenant_due();
		$this->finished_tenant_leaves_the_due_list();
		$this->status_reports_journal_and_counts();
	}

	private function seed_store(): void {
		$this->db->seed_tenant( 7, 'store-seven' );
		$this->db->seed_account( 7, 101 );
		$this->db->seed_account( 7, 102 );
	}

	private function first_round_provisions_and_stamps(): void {
		$this->boot();
		$this->seed_store();

		$result = $this->service->run_for_tenant( 7 );

		$this->assert_true( $result['ok'], 'the round completes' );
		$this->assert_same( 'done', $result['profile'], 'the profile step is journaled done' );
		$this->assert_same( 'done', $result['legacy'], 'the legacy step is journaled done' );

		$this->assert_same( 1, $this->provider->provision_calls, 'exactly one provider provision' );
		$this->assert_same( 1, count( $this->db->profiles['7'] ), 'one profile row for the store' );
		$this->assert_same(
			ZernioConnectionService::STATUS_PROVISIONED,
			(string) $this->db->profiles['7'][0]['status'],
			'the profile is provisioned, not connected (connect is a human OAuth step)'
		);
		$this->assert_true( '' !== (string) $this->db->profiles['7'][0]['key_enc'], 'the profile-scoped key is stored' );

		foreach ( [ 101, 102 ] as $account_id ) {
			$row = $this->db->accounts['7'][ $account_id ];
			$this->assert_true( null !== $row['legacy_deprecated_at'], "account $account_id is stamped" );
			$this->assert_same( 'legacy-encrypted-blob', (string) $row['manus_api_key'], 'the encrypted legacy key itself is untouched' );
		}
	}

	private function second_round_is_a_noop(): void {
		$this->boot();
		$this->seed_store();

		$this->service->run_for_tenant( 7 );
		$attempts_after_first = (int) $this->db->journal['7'][ SocialMigrationService::STEP_PROFILE ]['attempts'];
		$this->service->run_for_tenant( 7 );

		$this->assert_same( 1, $this->provider->provision_calls, 'a finished tenant never provisions twice' );
		$this->assert_same( 1, count( $this->db->profiles['7'] ), 'the profile row is still exactly one' );
		$this->assert_same( $attempts_after_first, (int) $this->db->journal['7'][ SocialMigrationService::STEP_PROFILE ]['attempts'], 'finished steps are not re-journaled' );
	}

	private function unconfigured_provider_keeps_the_tenant_due(): void {
		$this->boot( false );
		$this->seed_store();

		$result = $this->service->run_for_tenant( 7 );

		$this->assert_false( $result['ok'], 'the round cannot claim success' );
		$this->assert_same( 'pending', $result['profile'], 'the profile step is honestly pending' );
		$this->assert_same( 'done', $result['legacy'], 'the legacy stamp does not depend on the provider' );

		$due = $this->service->due_tenant_ids();
		$this->assert_true( in_array( 7, $due, true ), 'the tenant stays due while its profile is pending' );
	}

	private function finished_tenant_leaves_the_due_list(): void {
		$this->boot();
		$this->seed_store();
		$this->db->seed_account( 9, 201 );

		$this->service->run_for_tenant( 7 );
		$due = $this->service->due_tenant_ids();

		$this->assert_false( in_array( 7, $due, true ), 'a finished tenant is not re-migrated' );
		$this->assert_true( in_array( 9, $due, true ), 'an untouched tenant is still due' );
	}

	private function status_reports_journal_and_counts(): void {
		$this->boot();
		$this->seed_store();
		$this->service->run_for_tenant( 7 );

		$state = $this->service->status( 7 );

		$this->assert_same( ZernioConnectionService::STATUS_PROVISIONED, (string) $state['profile_status'], 'the profile state comes from the registry' );
		$this->assert_same( 'done', (string) $state['journal'][ SocialMigrationService::STEP_PROFILE ], 'journal: profile done' );
		$this->assert_same( 'done', (string) $state['journal'][ SocialMigrationService::STEP_LEGACY ], 'journal: legacy done' );
		$this->assert_same( 2, (int) $state['legacy_accounts'], 'two legacy account rows' );
		$this->assert_same( 2, (int) $state['deprecated_accounts'], 'both stamped' );
	}
}
