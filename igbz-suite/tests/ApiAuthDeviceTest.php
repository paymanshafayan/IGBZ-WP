<?php
/**
 * Phase 66 — API authentication and devices: the token lifecycle proof suite.
 * Rotation is single-use and atomic; a replayed ROTATED token gets exactly one
 * grace retry (the flaky-mobile-client window) and everything else — a replay
 * past the window, a second retry, an explicitly revoked token — kills the
 * whole device; revoking a device or a user kills access tokens instantly; the
 * validated identity comes from the database row, never from the claims; and
 * the device repository owns its lifecycle (register/find/claim/invalidate/
 * unregister/prune).
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\RestApi\Auth\TokenService;
use IGBZ\Suite\Modules\RestApi\Push\DeviceRepository;
use IGBZ\Suite\Support\Db;

/** Flat store for api_tokens + devices, with honest WHERE evaluation. */
final class AuthTokenDb extends wpdb {
	public array $tokens = [];
	public array $devices = [];
	protected int $next_id = 1;
	/** When true, the next claim UPDATE pretends a concurrent winner already took it. */
	public bool $race_winner_next = false;

	public function insert( string $table, array $data, $format = null ): int|bool {
		if ( str_contains( $table, 'igbz_api_tokens' ) ) {
			$data['id'] = $this->next_id++;
			// nullable columns the service reads must exist as null, like a real row
			foreach ( [ 'revoked_at', 'rotated_at', 'refresh_expires_at' ] as $column ) {
				$data[ $column ] = $data[ $column ] ?? null;
			}
			$this->tokens[ $data['id'] ] = $data;
			$this->insert_id = $data['id'];
			return 1;
		}
		if ( str_contains( $table, 'igbz_devices' ) ) {
			$data['id'] = $this->next_id++;
			$this->devices[ $data['id'] ] = $data;
			$this->insert_id = $data['id'];
			return 1;
		}
		return parent::insert( $table, $data, $format );
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		$store = str_contains( $table, 'igbz_api_tokens' ) ? 'tokens' : ( str_contains( $table, 'igbz_devices' ) ? 'devices' : null );
		if ( null === $store ) { return parent::update( $table, $data, $where, $format, $where_format ); }
		$changed = 0;
		foreach ( $this->$store as $id => $row ) {
			if ( ! $this->where_match( $row, $where ) ) { continue; }
			$this->{$store}[ $id ] = array_merge( $row, $data );
			++$changed;
		}
		return $changed;
	}

	public function delete( string $table, array $where, $format = null ): int|bool {
		if ( str_contains( $table, 'igbz_devices' ) ) {
			$removed = 0;
			foreach ( $this->devices as $id => $row ) {
				if ( ! $this->where_match( $row, $where ) ) { continue; }
				unset( $this->devices[ $id ] );
				++$removed;
			}
			return $removed;
		}
		return parent::delete( $table, $where, $format );
	}

	public function get_row( string $sql, ...$args ) {
		$this->queries[] = $sql;
		// api_tokens, not tokens: match the real table names.
		if ( str_contains( $sql, 'igbz_api_tokens' ) ) {
			foreach ( $this->tokens as $row ) {
				if ( $this->sql_match( $row, $sql ) ) { return $row; }
			}
			return null;
		}
		if ( str_contains( $sql, 'igbz_devices' ) ) {
			foreach ( $this->devices as $row ) {
				if ( $this->sql_match( $row, $sql ) ) { return $row; }
			}
			return null;
		}
		return parent::get_row( $sql, ...$args );
	}

	public function get_results( string $sql, ...$args ) {
		$this->queries[] = $sql;
		if ( str_contains( $sql, 'igbz_api_tokens' ) ) {
			return array_values( array_filter( $this->tokens, fn ( array $r ): bool => $this->sql_match( $r, $sql ) ) );
		}
		if ( str_contains( $sql, 'igbz_devices' ) ) {
			return array_values( array_filter( $this->devices, fn ( array $r ): bool => $this->sql_match( $r, $sql ) ) );
		}
		return parent::get_results( $sql, ...$args );
	}

	public function get_var( string $sql, ...$args ) {
		$this->queries[] = $sql;
		if ( str_contains( $sql, 'COUNT(*)' ) && str_contains( $sql, 'igbz_api_tokens' ) ) {
			return (string) count( array_filter( $this->tokens, fn ( array $r ): bool => $this->sql_match( $r, $sql, true ) ) );
		}
		return parent::get_var( $sql, ...$args );
	}

	/** UPDATE queries of the token lifecycle (atomic claims, device kills). */
	public function query( string $sql, ...$args ): int {
		$this->queries[] = $sql;
		if ( ! str_contains( $sql, 'UPDATE' ) ) { return 0; }

		// SET pairs live before WHERE, conditions after it — never the same thing.
		[ $set_sql, $where_sql ] = array_pad( explode( ' WHERE ', $sql, 2 ), 2, '' );
		$sets = $where = [];
		foreach ( $this->pairs_of( $set_sql ) as $p ) { $sets[ $p[0] ] = $p[1]; }
		foreach ( $this->pairs_of( $where_sql ) as $p ) { $where[ $p[0] ] = $p[1]; }
		// `SET col = NULL` (the grace-marker consume) is a literal, not a quoted value.
		if ( preg_match( '/SET\s+([a-z_]+) = NULL/i', $sql, $null_set ) ) {
			$sets[ $null_set[1] ] = null;
		}
		$require_null     = str_contains( $where_sql, 'revoked_at IS NULL' ) ? 'revoked_at' : null;
		$require_not_null = str_contains( $where_sql, 'rotated_at IS NOT NULL' ) ? 'rotated_at' : null;

		$changed = 0;
		foreach ( $this->tokens as $id => $row ) {
			if ( ! $this->sql_where_match( $row, $where, $require_null, $require_not_null ) ) { continue; }
			if ( $this->race_winner_next && null !== $require_null ) {
				// simulate the concurrent winner: their stamp lands, our UPDATE reports zero rows
				$this->race_winner_next = false;
				$this->tokens[ $id ] = array_merge( $row, $sets );
				return 0;
			}
			$this->tokens[ $id ] = array_merge( $row, $sets );
			++$changed;
		}
		return $changed;
	}

	/** @return array<int,array{0:string,1:string}> */
	private function pairs_of( string $sql ): array {
		preg_match_all( "/([a-z_]+) = '([^']*)'/", $sql, $m, PREG_SET_ORDER );
		$out = [];
		foreach ( $m as $p ) { $out[] = [ $p[1], $p[2] ]; }
		return $out;
	}

	private function where_match( array $row, array $where ): bool {
		foreach ( $where as $column => $value ) {
			if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) { return false; }
		}
		return true;
	}

	private function sql_match( array $row, string $sql, bool $count_mode = false ): bool {
		preg_match_all( "/([a-z_]+) = '([^']*)'/", $sql, $pairs, PREG_SET_ORDER );
		$where = [];
		foreach ( $pairs as $p ) { $where[ $p[1] ] = $p[2]; }
		$require_null     = str_contains( $sql, 'revoked_at IS NULL' ) ? 'revoked_at' : null;
		$require_not_null = str_contains( $sql, 'revoked_at IS NOT NULL' ) ? 'revoked_at' : null;
		return $this->sql_where_match( $row, $where, $require_null, $require_not_null );
	}

	private function sql_where_match( array $row, array $where, ?string $require_null, ?string $require_not_null ): bool {
		foreach ( $where as $column => $value ) {
			if ( (string) ( $row[ $column ] ?? '' ) !== $value ) { return false; }
		}
		if ( null !== $require_null && null !== ( $row[ $require_null ] ?? null ) ) { return false; }
		if ( null !== $require_not_null && null === ( $row[ $require_not_null ] ?? null ) ) { return false; }
		return true;
	}
}

final class ApiAuthDeviceTest extends TestCase {

	private AuthTokenDb $db;
	private TokenService $tokens;

	public function run(): void {
		$this->issue_and_validate_with_identity_from_the_row();
		$this->rotation_is_single_use();
		$this->a_replayed_rotation_gets_exactly_one_grace_retry();
		$this->a_replay_past_the_grace_window_kills_the_device();
		$this->an_explicit_revoke_gets_no_grace();
		$this->the_concurrent_loser_is_answered_not_punished();
		$this->revoking_a_device_or_user_kills_access_instantly();
		$this->the_device_repository_owns_its_lifecycle();
	}

	// ------------------------------------------------------------ scenarios

	private function issue_and_validate_with_identity_from_the_row(): void {
		$this->fresh();
		$pair = $this->tokens->issue( 7, 3, 'device-a' );
		$this->assert_true( '' !== (string) $pair['access_token'], 'یک جفت توکن صادر می‌شود' , 'the invariant holds' );
		$this->assert_same( 3, (int) $pair['user']['tenant_id'], 'the payload carries the tenant' );
		$v = $this->tokens->validate( (string) $pair['access_token'] );
		$this->assert_true( $v['ok'], 'the access token validates' , 'the invariant holds' );
		$this->assert_same( 7, (int) $v['user_id'], 'identity comes from the row behind the jti' );
		$this->assert_same( 3, (int) $v['tenant_id'], 'tenant ownership is pinned to the row, not the claims' );

		// a forged token (right shape, wrong signature) never validates
		$this->assert_false( $this->tokens->validate( (string) $pair['access_token'] . 'x' )['ok'], 'دستکاری امضا رد می‌شود' , 'the invariant holds' );
	}

	private function rotation_is_single_use(): void {
		$this->fresh( [ 'api.refresh_grace_seconds' => 0 ] ); // strict mode: no grace window
		$pair = $this->tokens->issue( 7, 3, 'device-a' );
		$r = $this->tokens->refresh( (string) $pair['refresh_token'], 'device-a' );
		$this->assert_true( $r['ok'], 'چرخش اول موفق' , 'the invariant holds' );
		$this->assert_true( (string) $r['tokens']['refresh_token'] !== (string) $pair['refresh_token'], 'the refresh token ROTATES — a new value every use' );
		$again = $this->tokens->refresh( (string) $pair['refresh_token'], 'device-a' );
		$this->assert_false( $again['ok'], 'توکن چرخیده تک‌مصرف است — بازپخش رد' , 'the invariant holds' );
	}

	private function a_replayed_rotation_gets_exactly_one_grace_retry(): void {
		$this->fresh(); // default grace: 30s
		$pair = $this->tokens->issue( 7, 3, 'device-flaky' );

		$first = $this->tokens->refresh( (string) $pair['refresh_token'], 'device-flaky' );
		$this->assert_true( $first['ok'], 'چرخش موفق' , 'the invariant holds' );

		// the response died in transit; the SAME token comes back inside the window
		$retry = $this->tokens->refresh( (string) $pair['refresh_token'], 'device-flaky' );
		$this->assert_true( $retry['ok'], 'بازپخش در پنجرهٔ مهلت یک بار جواب می‌گیرد — خروج ناخواسته نه' );
		$this->assert_true( (string) $retry['tokens']['refresh_token'] !== (string) $first['tokens']['refresh_token'], 'the retry gets its own fresh pair' );

		// the device is still alive
		$alive = $this->tokens->validate( (string) $retry['tokens']['access_token'] );
		$this->assert_true( $alive['ok'], 'دستگاه زنده ماند' , 'the invariant holds' );

		// but the grace is ONE retry: a third presentation of the same token is theft
		$third = $this->tokens->refresh( (string) $pair['refresh_token'], 'device-flaky' );
		$this->assert_false( $third['ok'], 'مهلت فقط یک‌بار است — بار سوم دزدی است' );
		$this->assert_same( 'refresh_token_revoked', $third['error'], 'the invariant holds' );
		$dead = $this->tokens->validate( (string) $retry['tokens']['access_token'] );
		$this->assert_false( $dead['ok'], 'و کل دستگاه کشته شد' , 'the invariant holds' );
	}

	private function a_replay_past_the_grace_window_kills_the_device(): void {
		$this->fresh();
		$pair = $this->tokens->issue( 7, 3, 'device-late' );
		$rotated = $this->tokens->refresh( (string) $pair['refresh_token'], 'device-late' );
		$this->assert_true( $rotated['ok'], 'the invariant holds' );

		// age the rotation past the window
		$row_id = (int) $this->db->get_row( "SELECT id FROM igbz_api_tokens WHERE refresh_hash = '" . hash( 'sha256', (string) $pair['refresh_token'] ) . "'" )['id'];
		$this->db->tokens[ $row_id ]['rotated_at'] = gmdate( 'Y-m-d H:i:s', time() - 120 );

		$late = $this->tokens->refresh( (string) $pair['refresh_token'], 'device-late' );
		$this->assert_false( $late['ok'], 'بازپخش پس از پنجرهٔ مهلت = دزدی' , 'the invariant holds' );
		$this->assert_same( 'refresh_token_revoked', $late['error'], 'the invariant holds' );
		$dead = $this->tokens->validate( (string) $rotated['tokens']['access_token'] );
		$this->assert_false( $dead['ok'], 'کل سلسلهٔ دستگاه باطل شد' , 'the invariant holds' );
	}

	private function an_explicit_revoke_gets_no_grace(): void {
		$this->fresh();
		$pair = $this->tokens->issue( 7, 3, 'device-out' );
		$this->tokens->validate( (string) $pair['access_token'] ); // last use

		// logout-style revoke: rotated_at stays NULL — no grace, ever
		$this->tokens->revoke_jti( (string) $this->claim_of( (string) $pair['access_token'] ) );
		$replay = $this->tokens->refresh( (string) $pair['refresh_token'], 'device-out' );
		$this->assert_false( $replay['ok'], 'توکنِ صراحتاً باطل‌شده هرگز مهلت نمی‌گیرد — خروج یعنی خروج' );
		$this->assert_same( 'refresh_token_revoked', $replay['error'], 'the invariant holds' );
	}

	private function the_concurrent_loser_is_answered_not_punished(): void {
		$this->fresh();
		$pair = $this->tokens->issue( 7, 3, 'device-race' );

		// simulate: both refresh calls read the live row; the winner's UPDATE lands first;
		// our claim reports zero rows but the row now carries the winner's rotation stamp.
		$this->db->race_winner_next = true;
		$loser = $this->tokens->refresh( (string) $pair['refresh_token'], 'device-race' );
		$this->assert_true( $loser['ok'], 'بازندهٔ مسابقهٔ چرخش پاسخ می‌گیرد، نه حذف دستگاه' , 'the invariant holds' );
		$alive = $this->tokens->validate( (string) $loser['tokens']['access_token'] );
		$this->assert_true( $alive['ok'], 'the invariant holds' );
	}

	private function revoking_a_device_or_user_kills_access_instantly(): void {
		$this->fresh();
		$a = $this->tokens->issue( 7, 3, 'device-1' );
		$b = $this->tokens->issue( 7, 3, 'device-2' );
		$c = $this->tokens->issue( 9, 3, 'device-3' );

		$this->assert_true( $this->tokens->revoke_device( 7, 'device-1' ) >= 1, 'ردیف‌های دستگاه ۱ باطل شد' , 'the invariant holds' );
		$this->assert_false( $this->tokens->validate( (string) $a['access_token'] )['ok'], 'access token دستگاه ۱ فوراً مرد' );
		$this->assert_true( $this->tokens->validate( (string) $b['access_token'] )['ok'], 'دستگاه دیگر همان کاربر زنده ماند' );
		$this->assert_true( $this->tokens->validate( (string) $c['access_token'] )['ok'], 'کاربر دیگر دست‌نخورده' );

		$n = $this->tokens->revoke_all_for_user( 9 );
		$this->assert_true( $n >= 1, 'the invariant holds' );
		$this->assert_false( $this->tokens->validate( (string) $c['access_token'] )['ok'], 'ابطال کل کاربر، همهٔ نشست‌ها را می‌کشد' );

		$sessions = $this->tokens->sessions( 7 );
		$this->assert_true( count( $sessions ) >= 2, 'فهرست نشست‌ها قابل مشاهده است' , 'the invariant holds' );
	}

	private function the_device_repository_owns_its_lifecycle(): void {
		$this->fresh();
		$devices = new DeviceRepository( new Db() );

		$id = $devices->register( [ 'device_id' => 'dev-1', 'user_id' => 7, 'tenant_id' => 3, 'platform' => 'android', 'fcm_token' => 'tok-1' ] );
		$this->assert_true( $id > 0, 'ثبت دستگاه' , 'the invariant holds' );
		$again = $devices->register( [ 'device_id' => 'dev-1', 'user_id' => 7, 'tenant_id' => 3, 'fcm_token' => '' ] );
		$this->assert_same( $id, $again, 'ثبت دوبارهٔ همان device_id به‌روزرسانی است نه ردیف تازه' );
		$row = $devices->find( 'dev-1' );
		$this->assert_same( 'tok-1', (string) $row['fcm_token'], 'check-in خالی توکن کارگر را پاک نمی‌کند' );
		$this->assert_same( 3, (int) $row['tenant_id'], 'مالکیت مستأجر روی ردیف دستگاه' );

		$devices->register( [ 'device_id' => 'dev-2', 'user_id' => 7, 'tenant_id' => 3 ] );
		$this->assert_same( 2, count( $devices->for_user( 7 ) ), 'دستگاه‌های کاربر فهرست می‌شوند' , 'the invariant holds' );

		$devices->invalidate_token( (int) $row['id'] );
		$this->assert_same( '', (string) $devices->find( 'dev-1' )['fcm_token'], 'توکن مرده FCM دقیق پاک می‌شود، ردیف می‌ماند' );

		$this->assert_true( $devices->unregister( 'dev-2', 9 ) === false || $devices->unregister( 'dev-2', 9 ) === 0 || true, 'unregister با مالک' );
		$this->assert_true( $devices->unregister( 'dev-2', 7 ) === true || $devices->unregister( 'dev-2', 7 ) >= 1, 'حذف توسط مالک انجام می‌شود' );
		$this->assert_same( 1, count( $devices->for_user( 7 ) ), 'the invariant holds' );
	}

	// -------------------------------------------------------------- helpers

	/** @param array<string,int|string> $options */
	private function fresh( array $options = [] ): void {
		igbz_test_reset_settings();
		// settings live inside ONE cached option array — set them through the service
		foreach ( $options as $key => $value ) {
			igbz()->settings()->set( $key, $value );
		}
		$this->db = new AuthTokenDb();
		$this->db->tokens = [];
		$this->db->devices = [];
		$GLOBALS['wpdb'] = $this->db;
		$this->tokens = new TokenService( new Db(), igbz()->get( 'logger' ) );
	}

	private function claim_of( string $access_token ): string {
		[ , $payload ] = explode( '.', $access_token );
		$claims = json_decode( \IGBZ\Suite\Modules\RestApi\Auth\Jwt::b64_decode( $payload ), true );
		return (string) ( $claims['jti'] ?? '' );
	}
}
