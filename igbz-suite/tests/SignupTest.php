<?php
use IGBZ\Suite\Modules\Hub\Services\SignupService;
use IGBZ\Suite\Modules\MultiTenant\Payments\PaymentService;
use IGBZ\Suite\Modules\MultiTenant\Plans\PlanService;
use IGBZ\Suite\Modules\MultiTenant\Repository\TenantRepository;
use IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService;
use IGBZ\Suite\Support\Capabilities;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Http;
use IGBZ\Suite\Support\Logger;

/**
 * Persisting double for the hub signup path: tenants, memberships, subscriptions and plans.
 * Every equality pair in a SELECT must hold on the row (quoted string or bare number), which
 * is how the real engine answers these statements.
 */
final class SignupDb extends wpdb {

	/** @var array<string,array<int,array<string,mixed>>> */
	public array $tables = [
		'tenants'         => [],
		'tenant_members'  => [],
		'subscriptions'   => [],
		'plans'           => [],
	];

	/** When set, the next insert into this table throws — simulates a broken provisioning step. */
	public string $fail_insert_table = '';

	private int $next_id = 100;

	private function short( string $table ): string {
		foreach ( array_keys( $this->tables ) as $name ) {
			if ( str_ends_with( $table, 'igbz_' . $name ) ) {
				return $name;
			}
		}
		return '';
	}

	/** @return array<int,array<string,mixed>> */
	private function rows_for( string $sql ): array {
		if ( ! preg_match( '/igbz_(\w+)/', $sql, $m ) || ! isset( $this->tables[ $m[1] ] ) ) {
			return [];
		}

		$pairs = [];
		if ( preg_match_all( "/\b([a-z_]+) = (?:'([^']*)'|(\d+))/", $sql, $found, PREG_SET_ORDER ) ) {
			foreach ( $found as $p ) {
				$pairs[ $p[1] ] = '' !== $p[2] ? $p[2] : $p[3];
			}
		}

		$out = [];
		foreach ( $this->tables[ $m[1] ] as $row ) {
			foreach ( $pairs as $column => $value ) {
				if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) {
					continue 2;
				}
			}
			$out[] = $row;
		}
		return $out;
	}

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;
		$rows            = $this->rows_for( $sql );
		return $rows[0] ?? null;
	}

	public function get_results( string $sql, $output = null ) {
		$this->queries[] = $sql;
		return $this->rows_for( $sql );
	}

	public function get_var( string $sql ) {
		$this->queries[] = $sql;
		$rows            = $this->rows_for( $sql );
		return $rows ? (string) reset( $rows )['id'] : null;
	}

	public function insert( string $table, array $data, $format = null ): int|bool {
		$short = $this->short( $table );
		if ( '' === $short ) {
			return parent::insert( $table, $data, $format );
		}
		if ( $short === $this->fail_insert_table ) {
			throw new \RuntimeException( 'Simulated provisioning failure while writing ' . $short );
		}

		$id                              = $this->next_id++;
		$this->insert_id                 = $id;
		$data['id']                      = $id;
		$this->tables[ $short ][ $id ]   = $data;
		return 1;
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		$short = $this->short( $table );
		if ( '' === $short ) {
			return parent::update( $table, $data, $where, $format, $where_format );
		}
		$changed = 0;
		foreach ( $this->tables[ $short ] as $id => $row ) {
			foreach ( $where as $column => $value ) {
				if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) {
					continue 2;
				}
			}
			$this->tables[ $short ][ $id ] = array_merge( $row, $data );
			++$changed;
		}
		return $changed;
	}

	public function delete( string $table, array $where, $where_format = null ): int|bool {
		$short = $this->short( $table );
		if ( '' === $short ) {
			return parent::delete( $table, $where, $where_format );
		}
		$removed = 0;
		foreach ( $this->tables[ $short ] as $id => $row ) {
			foreach ( $where as $column => $value ) {
				if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) {
					continue 2;
				}
			}
			unset( $this->tables[ $short ][ $id ] );
			++$removed;
		}
		return $removed;
	}

	public function query( string $sql ): int|bool {
		$this->queries[] = $sql;
		return 1;
	}
}

/**
 * Phase 16: hub signup must provision user + tenant + membership + owner role in one piece,
 * re-running it must never duplicate a store, and a broken step must roll the half-built
 * store back instead of leaving an orphan tenant behind.
 */
final class SignupTest extends TestCase {

	public function run(): void {
		igbz_test_reset_settings();
		igbz()->settings()->set( 'general.allow_self_signup', true );
		igbz()->settings()->set( 'general.auto_approve_tenants', true );

		$this->provisions_store_owner_membership_and_role();
		$this->rerun_of_the_same_signup_never_duplicates();
		$this->slug_owned_by_someone_else_is_rejected();
		$this->broken_step_rolls_the_half_built_store_back();
	}

	private function fresh_service(): SignupService {
		$GLOBALS['wpdb']                 = new SignupDb();
		$GLOBALS['igbz_test_user_roles'] = [];
		$GLOBALS['igbz_test_users']      = [
			'email' => [ 'owner@shop.test' => (object) [ 'ID' => 9 ] ],
		];

		$db     = new Db();
		$logger = new Logger( igbz()->settings() );
		$wallet = new WalletService( $db, $logger );

		return new SignupService(
			new TenantRepository( $db ),
			new PlanService( $db, $wallet, $logger ),
			new PaymentService( $db, new Http( $logger ), $wallet, $logger ),
			$logger
		);
	}

	private function db(): SignupDb {
		return $GLOBALS['wpdb'];
	}

	private function signup_data(): array {
		return [ 'name' => 'Nanvaie', 'slug' => 'nanvaie', 'email' => 'owner@shop.test' ];
	}

	private function provisions_store_owner_membership_and_role(): void {
		$signup = $this->fresh_service();

		$result = $signup->signup( $this->signup_data() );

		$this->assert_true( $result['ok'], 'a free signup provisions a store' );
		$this->assert_true( $result['tenant_id'] > 0, 'the new tenant id comes back' );

		$tenants = $this->db()->tables['tenants'];
		$this->assert_same( 1, count( $tenants ), 'exactly one tenant row exists' );
		$tenant = reset( $tenants );
		$this->assert_same( 9, (int) $tenant['owner_user_id'], 'the resolved user owns the store' );
		$this->assert_same( 'active', (string) $tenant['status'], 'auto approval makes the store active' );

		$members = $this->db()->tables['tenant_members'];
		$this->assert_same( 1, count( $members ), 'the owner membership exists' );
		$this->assert_same( 'owner', (string) reset( $members )['role'], 'the membership carries the owner role' );

		$this->assert_true(
			in_array( Capabilities::ROLE_TENANT_OWNER, $GLOBALS['igbz_test_user_roles'][9] ?? [], true ),
			'the WordPress owner role is granted'
		);
	}

	private function rerun_of_the_same_signup_never_duplicates(): void {
		$signup = $this->fresh_service();

		$first  = $signup->signup( $this->signup_data() );
		$second = $signup->signup( $this->signup_data() );

		$this->assert_true( $first['ok'] && $second['ok'], 'both runs answer success' );
		$this->assert_same( $first['tenant_id'], $second['tenant_id'], 'the re-run returns the existing store' );
		$this->assert_same( 1, count( $this->db()->tables['tenants'] ), 'no duplicate tenant is created' );
		$this->assert_same( 1, count( $this->db()->tables['tenant_members'] ), 'no duplicate membership is created' );
	}

	private function slug_owned_by_someone_else_is_rejected(): void {
		$signup = $this->fresh_service();
		$this->db()->tables['tenants'][1] = [
			'id' => 1, 'slug' => 'nanvaie', 'name' => 'Taken', 'owner_user_id' => 42,
			'status' => 'active', 'plan_id' => 0, 'currency' => 'IRT', 'locale' => 'fa',
		];

		$result = $signup->signup( $this->signup_data() );

		$this->assert_false( $result['ok'], 'a slug owned by someone else is not handed over' );
		$this->assert_same( 1, count( $this->db()->tables['tenants'] ), 'nothing new is provisioned' );
	}

	private function broken_step_rolls_the_half_built_store_back(): void {
		$signup = $this->fresh_service();
		$this->db()->tables['plans'][7] = [
			'id' => 7, 'slug' => 'starter', 'name' => 'Starter', 'price' => 0, 'trial_days' => 14,
			'billing_period' => 'monthly', 'status' => 'active', 'features' => '', 'sort_order' => 0,
		];
		$this->db()->fail_insert_table = 'subscriptions';

		$result = $signup->signup( $this->signup_data() + [ 'plan_id' => 7 ] );

		$this->assert_false( $result['ok'], 'a broken subscription step fails the signup' );
		$this->assert_same( 0, count( $this->db()->tables['tenants'] ), 'the half-built tenant is rolled back' );
		$this->assert_same( 0, count( $this->db()->tables['tenant_members'] ), 'the membership is rolled back too' );
	}
}
