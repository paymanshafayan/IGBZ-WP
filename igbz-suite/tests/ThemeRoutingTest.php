<?php
use IGBZ\Suite\Modules\MultiTenant\Frontend\TenantThemeRouter;
use IGBZ\Suite\Modules\Pado\Services\ThemeService;
use IGBZ\Suite\Support\Capabilities;
use IGBZ\Suite\Support\Db;

/**
 * Persisting double for the theme path: tenants + themes with the usual equality matcher.
 */
final class ThemeDb extends wpdb {

	/** @var array<string,array<int,array<string,mixed>>> */
	public array $tables = [
		'tenants' => [],
		'themes'  => [],
	];

	private int $next_id = 40;

	private function short( string $table ): string {
		foreach ( array_keys( $this->tables ) as $name ) {
			if ( str_ends_with( $table, 'igbz_' . $name ) ) {
				return $name;
			}
		}
		return '';
	}

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
		if ( str_contains( $sql, 'SELECT theme FROM' ) ) {
			$rows = $this->rows_for( $sql );
			return $rows ? (string) ( $rows[0]['theme'] ?? '' ) : null;
		}
		$rows = $this->rows_for( $sql );
		return $rows ? (string) reset( $rows )['id'] : null;
	}

	public function insert( string $table, array $data, $format = null ): int|bool {
		$short = $this->short( $table );
		if ( '' === $short ) {
			return parent::insert( $table, $data, $format );
		}
		$id                            = $this->next_id++;
		$this->insert_id               = $id;
		$data['id']                    = $id;
		$this->tables[ $short ][ $id ] = $data;
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

		// Honour the tenant-scoped archive statements so later assertions see real effects.
		// (The stub prepare quotes every value, so numbers arrive as '1'.)
		if ( str_contains( $sql, 'SET status' ) && preg_match( "/tenant_id = '?(\d+)'?/", $sql, $m ) ) {
			$not_id = preg_match( "/id != '?(\d+)'?/", $sql, $x ) ? (int) $x[1] : 0;
			foreach ( $this->tables['themes'] as $id => $row ) {
				if ( (int) $row['tenant_id'] === (int) $m[1] && 'live' === $row['status'] && $id !== $not_id ) {
					$this->tables['themes'][ $id ]['status'] = 'archived';
				}
			}
		}
		return 1;
	}
}

/**
 * Phase 18: a store's theme is per-tenant state applied at request time. Activating or
 * rolling back must never repaint another store, and a preview slug must not cross a tenant
 * boundary.
 */
final class ThemeRoutingTest extends TestCase {

	public function run(): void {
		igbz_test_reset_settings();
		$GLOBALS['igbz_test_themes'] = [
			'nanvaie-live' => [ 'Name' => 'Nanvaie live' ],
			'fallback'     => [ 'Name' => 'Fallback' ],
			'pv-theme'     => [ 'Name' => 'Preview' ],
		];
		$GLOBALS['igbz_test_stylesheet'] = 'fallback';

		$this->activation_is_tenant_state_not_a_global_switch();
		$this->rollback_touches_only_its_own_tenant();
		$this->router_serves_the_tenants_own_installed_theme();
		$this->preview_slug_does_not_cross_a_tenant_boundary();
	}

	private function fresh_db(): ThemeDb {
		$db                          = new ThemeDb();
		$GLOBALS['wpdb']             = $db;
		$GLOBALS['igbz_test_options'] = [];
		$GLOBALS['igbz_test_cache']  = []; // TenantRepository caches tenant rows; reseeded tables need a cold cache.
		unset( $_GET['igbz_theme_preview'] );
		$db->tables['tenants'][1] = [ 'id' => 1, 'slug' => 't1', 'name' => 'T1', 'owner_user_id' => 1, 'status' => 'active', 'plan_id' => 0, 'currency' => 'IRT', 'locale' => 'fa', 'theme' => '' ];
		$db->tables['tenants'][2] = [ 'id' => 2, 'slug' => 't2', 'name' => 'T2', 'owner_user_id' => 2, 'status' => 'active', 'plan_id' => 0, 'currency' => 'IRT', 'locale' => 'fa', 'theme' => '' ];
		return $db;
	}

	private function activation_is_tenant_state_not_a_global_switch(): void {
		$db = $this->fresh_db();
		$db->tables['themes'][11] = [ 'id' => 11, 'tenant_id' => 1, 'slug' => 'nanvaie-live', 'status' => 'preview' ];
		igbz()->tenancy()->force( 1 );

		$service = new ThemeService( new Db() );
		$result  = $service->activate_live( 11 );

		$this->assert_true( $result['ok'], 'activation succeeds for the tenant\'s own theme' );
		$this->assert_same( 'nanvaie-live', (string) $db->tables['tenants'][1]['theme'], 'the tenant row carries the live theme' );
		$this->assert_same( 'live', (string) $db->tables['themes'][11]['status'], 'the theme row is marked live' );
		$this->assert_same( 'fallback', get_option( 'igbz_previous_theme_slug_1' ), 'the previous slug is remembered for rollback' );
		$this->assert_same( 'fallback', get_stylesheet(), 'the site-wide stylesheet is untouched — no global switch happened' );
	}

	private function rollback_touches_only_its_own_tenant(): void {
		$db = $this->fresh_db();
		$db->tables['tenants'][1]['theme'] = 'nanvaie-live';
		$db->tables['tenants'][2]['theme'] = 'nanvaie-live';
		$db->tables['themes'][21] = [ 'id' => 21, 'tenant_id' => 1, 'slug' => 'nanvaie-live', 'status' => 'live' ];
		$db->tables['themes'][22] = [ 'id' => 22, 'tenant_id' => 2, 'slug' => 'nanvaie-live', 'status' => 'live' ];
		update_option( 'igbz_previous_theme_slug_1', 'fallback', false );
		update_option( 'igbz_previous_theme_slug_2', 'fallback', false );

		$service = new ThemeService( new Db() );
		$result  = $service->rollback( 1 );

		$this->assert_true( $result['ok'], 'rollback succeeds with a tenant id' );
		$this->assert_same( 'fallback', (string) $db->tables['tenants'][1]['theme'], 'tenant one returns to its previous theme' );
		$this->assert_same( 'archived', (string) $db->tables['themes'][21]['status'], 'tenant one\'s live theme is archived' );
		$this->assert_same( 'live', (string) $db->tables['themes'][22]['status'], 'tenant two\'s live theme is untouched' );
		$this->assert_same( 'nanvaie-live', (string) $db->tables['tenants'][2]['theme'], 'tenant two keeps serving its theme' );
	}

	private function router_serves_the_tenants_own_installed_theme(): void {
		$db = $this->fresh_db();
		$db->tables['tenants'][1]['theme'] = 'nanvaie-live';
		$db->tables['tenants'][2]['theme'] = '';

		$router = new TenantThemeRouter( new Db() );

		igbz()->tenancy()->force( 1 );
		$this->assert_same( 'nanvaie-live', $router->route( 'fallback' ), 'the storefront is served the tenant\'s own theme' );

		igbz()->tenancy()->force( 2 );
		$this->assert_same( 'fallback', $router->route( 'fallback' ), 'a tenant without a theme keeps the site default' );

		$db->tables['tenants'][2]['theme'] = 'missing-theme';
		igbz()->tenancy()->force( 2 );
		$this->assert_same( 'fallback', $router->route( 'fallback' ), 'a theme that is not installed is never served' );
		igbz()->tenancy()->force( null );
	}

	private function preview_slug_does_not_cross_a_tenant_boundary(): void {
		$db = $this->fresh_db();
		$db->tables['themes'][31] = [ 'id' => 31, 'tenant_id' => 1, 'slug' => 'pv-theme', 'status' => 'preview' ];
		$_GET['igbz_theme_preview'] = 'pv-theme';

		$router = new TenantThemeRouter( new Db() );

		// A visitor on another tenant's storefront asking for tenant one's preview.
		igbz()->tenancy()->force( 2 );
		$this->assert_same( 'fallback', $router->route( 'fallback' ), 'a preview slug never renders outside its own tenant' );

		// On the right tenant, but not a member and not a platform admin.
		$GLOBALS['igbz_test_user_id'] = 0;
		$GLOBALS['igbz_test_capabilities'] = [];
		igbz()->tenancy()->force( 1 );
		$this->assert_same( 'fallback', $router->route( 'fallback' ), 'an anonymous visitor cannot preview' );

		// A platform admin on the right tenant sees it.
		$GLOBALS['igbz_test_user_id'] = 5;
		igbz_test_grant( 5, Capabilities::MANAGE_TENANTS );
		$this->assert_same( 'pv-theme', $router->route( 'fallback' ), 'a platform admin previews on the tenant\'s own storefront' );

		unset( $_GET['igbz_theme_preview'] );
		igbz()->tenancy()->force( null );
	}
}
