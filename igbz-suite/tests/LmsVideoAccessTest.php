<?php
/**
 * Phase 42 — secure video access: a signed link is necessary but not sufficient. After a
 * refund (revoked enrollment) or a lapsed access window the link stops working immediately,
 * free previews stay open, and expired or forged signatures never pass.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\MultiTenant\Lms\LmsService;
use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;

/** In-memory engine for lessons + enrollments. */
final class LmsVideoDb extends wpdb {

	/** @var array<string,array<int,array<string,mixed>>> */
	public array $tables = [
		'lessons'     => [],
		'enrollments' => [],
	];

	private int $next_id = 1;

	/** @param array<string,mixed> $row */
	public function seed( string $table, array $row ): int {
		$id                        = $this->next_id++;
		$row['id']                 = $id;
		$this->tables[ $table ][ $id ] = $row;

		return $id;
	}

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'lessons' ) && preg_match( "/video_key = '([^']*)'/", $sql, $m ) ) {
			foreach ( $this->tables['lessons'] as $row ) {
				if ( (string) $row['video_key'] === $m[1] ) {
					return $row;
				}
			}
			return null;
		}

		if ( str_contains( $sql, 'enrollments' )
			&& preg_match( "/WHERE course_id = '?(\d+)'? AND user_id = '?(\d+)'? AND tenant_id = '?(\d+)'?/", $sql, $m ) ) {
			foreach ( $this->tables['enrollments'] as $row ) {
				if ( (string) $row['course_id'] === $m[1] && (string) $row['user_id'] === $m[2] && (string) $row['tenant_id'] === $m[3] ) {
					return $row;
				}
			}
			return null;
		}

		return parent::get_row( $sql, $output );
	}

	public function get_results( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'enrollments' ) && preg_match( "/WHERE order_id = '?(\d+)'?/", $sql, $m ) ) {
			$out = [];
			foreach ( $this->tables['enrollments'] as $row ) {
				if ( (string) $row['order_id'] === $m[1] ) {
					$out[] = $row;
				}
			}
			return $out;
		}

		return parent::get_results( $sql, $output );
	}

	public function delete( string $table, array $where, $where_format = null ): int|bool {
		$this->queries[] = 'DELETE FROM ' . $table;

		if ( str_contains( (string) $table, 'enrollments' ) ) {
			foreach ( $this->tables['enrollments'] as $id => $row ) {
				$hit = true;
				foreach ( (array) $where as $column => $value ) {
					if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) {
						$hit = false;
						break;
					}
				}
				if ( $hit ) {
					unset( $this->tables['enrollments'][ $id ] );
					return 1;
				}
			}
			return 0;
		}

		return parent::delete( $table, $where, $where_format );
	}
}

final class LmsVideoAccessTest extends TestCase {

	private Db $db;
	private LmsVideoDb $ldb;
	private LmsService $lms;

	private function boot(): void {
		igbz_test_reset_settings();
		igbz()->settings()->set( 'lms.video_hmac_secret', 'video-secret' );

		$this->ldb         = new LmsVideoDb();
		$GLOBALS['wpdb']   = $this->ldb;

		$this->db = new Db();
		$ref = new ReflectionProperty( Db::class, 'is_sqlite' );
		$ref->setValue( $this->db, true );

		$this->lms = new LmsService( $this->db );

		$this->ldb->seed( 'lessons', [ 'course_id' => 5, 'tenant_id' => 0, 'video_key' => 'vid-1', 'is_free_preview' => 0 ] );
		$this->ldb->seed( 'lessons', [ 'course_id' => 5, 'tenant_id' => 0, 'video_key' => 'vid-free', 'is_free_preview' => 1 ] );
	}

	private function link( string $key, int $user_id, int $ttl = 3600 ): array {
		$expires = time() + $ttl;
		$sig     = Crypto::hmac( $key . '|' . $user_id . '|' . $expires, 'video-secret' );

		return [ $key, $user_id, $expires, $sig ];
	}

	public function run(): void {
		$this->test_a_signed_link_passes_with_a_live_enrollment();
		$this->test_a_revoked_enrollment_kills_the_link_immediately();
		$this->test_a_lapsed_access_window_kills_the_link();
		$this->test_free_previews_stay_open_and_unknown_keys_stay_closed();
		$this->test_expired_and_forged_links_never_pass();
	}

	public function test_a_signed_link_passes_with_a_live_enrollment(): void {
		$this->boot();
		$this->ldb->seed( 'enrollments', [ 'tenant_id' => 0, 'course_id' => 5, 'user_id' => 3, 'order_id' => 1, 'expires_at' => null, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ] );

		[ $key, $user, $expires, $sig ] = $this->link( 'vid-1', 3 );
		$this->assert_true( $this->lms->verify_video_signature( $key, $user, $expires, $sig ), 'a live enrollment watches' );
	}

	public function test_a_revoked_enrollment_kills_the_link_immediately(): void {
		$this->boot();
		$this->ldb->seed( 'enrollments', [ 'tenant_id' => 0, 'course_id' => 5, 'user_id' => 3, 'order_id' => 9, 'expires_at' => null, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ] );

		[ $key, $user, $expires, $sig ] = $this->link( 'vid-1', 3 );
		$this->assert_true( $this->lms->verify_video_signature( $key, $user, $expires, $sig ), 'the link works before the refund' );

		$this->lms->revoke_from_order( 9 );
		$this->assert_false( $this->lms->verify_video_signature( $key, $user, $expires, $sig ), 'the refund kills the still-valid link at once' );
	}

	public function test_a_lapsed_access_window_kills_the_link(): void {
		$this->boot();
		$this->ldb->seed( 'enrollments', [ 'tenant_id' => 0, 'course_id' => 5, 'user_id' => 3, 'order_id' => 1, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ), 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ] );

		[ $key, $user, $expires, $sig ] = $this->link( 'vid-1', 3 );
		$this->assert_false( $this->lms->verify_video_signature( $key, $user, $expires, $sig ), 'expired access refuses a valid link' );
	}

	public function test_free_previews_stay_open_and_unknown_keys_stay_closed(): void {
		$this->boot();

		[ $key, $user, $expires, $sig ] = $this->link( 'vid-free', 3 );
		$this->assert_true( $this->lms->verify_video_signature( $key, $user, $expires, $sig ), 'a free preview needs no enrollment' );

		[ $key2, $user2, $expires2, $sig2 ] = $this->link( 'vid-ghost', 3 );
		$this->assert_false( $this->lms->verify_video_signature( $key2, $user2, $expires2, $sig2 ), 'an unknown video key is refused' );
	}

	public function test_expired_and_forged_links_never_pass(): void {
		$this->boot();
		$this->ldb->seed( 'enrollments', [ 'tenant_id' => 0, 'course_id' => 5, 'user_id' => 3, 'order_id' => 1, 'expires_at' => null, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ] );

		[ $key, $user, $expires, $sig ] = $this->link( 'vid-1', 3, -60 );
		$this->assert_false( $this->lms->verify_video_signature( $key, $user, $expires, $sig ), 'an expired link is dead' );

		[ $key2, $user2, $expires2 ] = $this->link( 'vid-1', 3 );
		$this->assert_false( $this->lms->verify_video_signature( $key2, $user2, $expires2, 'forged' ), 'a forged signature is refused' );
	}
}
