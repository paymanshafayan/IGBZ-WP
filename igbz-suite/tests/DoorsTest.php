<?php
use IGBZ\Suite\Support\Admin\SettingsPage;
use IGBZ\Suite\Support\Capabilities;
use IGBZ\Suite\Support\CoreSurfaceGuard;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

/**
 * Phase 06: roles and the core doors. The senior-admin emergency lane (audited), the export
 * gate, oEmbed author stripping, and the rule that only a super admin may point the emergency
 * lane at someone.
 */
final class DoorsTest extends TestCase {

	private function logger(): Logger {
		return new Logger( igbz()->settings() );
	}

	private function guard(): CoreSurfaceGuard {
		return new CoreSurfaceGuard( $this->logger() );
	}

	public function run(): void {
		$this->senior_lane_closed_until_configured();
		$this->senior_lane_opens_only_for_the_configured_id();
		$this->senior_bulk_access_is_audit_logged();
		$this->export_gate();
		$this->oembed_author_is_stripped();
		$this->only_super_admin_may_set_the_senior_id();
	}

	private function become( int $user_id, array $caps = [] ): void {
		$GLOBALS['igbz_test_user_id'] = $user_id;
		$GLOBALS['igbz_test_capabilities'][ $user_id ] = array_fill_keys( $caps, true );
	}

	private function senior_lane( CoreSurfaceGuard $guard ): bool {
		$method = new ReflectionMethod( $guard, 'is_permitted' );
		return (bool) $method->invoke( $guard, new \WP_REST_Request() );
	}

	private function senior_lane_closed_until_configured(): void {
		igbz_test_reset_settings();
		$this->become( 7, [ Capabilities::MANAGE_SUITE ] );

		$this->assert_false( $this->guard()->is_senior_admin( 7 ), 'no senior id configured -> nobody is senior' );
		$this->assert_false( $this->senior_lane( $this->guard() ), 'bulk lane stays closed without super admin or capability' );
	}

	private function senior_lane_opens_only_for_the_configured_id(): void {
		$settings = igbz_test_reset_settings();
		$settings->set( 'security.senior_admin_id', 7 );

		$this->become( 7 );
		$this->assert_true( $this->guard()->is_senior_admin( 7 ), 'the configured id is senior' );
		$this->assert_true( $this->senior_lane( $this->guard() ), 'senior admin gets the audited bulk lane' );

		$this->become( 8 );
		$this->assert_false( $this->guard()->is_senior_admin( 8 ), 'any other id is not senior' );
		$this->assert_false( $this->senior_lane( $this->guard() ), 'the lane stays closed for everyone else' );
	}

	private function senior_bulk_access_is_audit_logged(): void {
		$settings = igbz_test_reset_settings();
		$settings->set( 'security.senior_admin_id', 7 );
		$this->become( 7 );

		$this->senior_lane( $this->guard() );

		// The wpdb stub records writes instead of persisting rows.
		global $wpdb;
		$audited = false;
		foreach ( $wpdb->writes ?? [] as $write ) {
			$data = (array) ( $write['data'] ?? [] );
			if (
				str_contains( (string) ( $write['table'] ?? '' ), 'igbz_logs' )
				&& 'security' === ( $data['channel'] ?? '' )
				&& str_contains( (string) ( $data['message'] ?? '' ), 'emergency bulk access' )
			) {
				$audited = true;
			}
		}
		$this->assert_true( $audited, 'senior emergency bulk access is written to the audit log' );
	}

	private function export_gate(): void {
		igbz_test_reset_settings();
		$guard = $this->guard();

		$this->become( 5 );
		try {
			$guard->guard_export();
			$this->assert_true( false, 'plain admin must be refused the XML export' );
		} catch ( \RuntimeException $e ) {
			$this->assert_contains( 'restricted to the platform administrator', $e->getMessage(), 'export refusal message' );
		}

		$this->become( 5, [ 'delete_users' ] );
		$guard->guard_export(); // must not throw for a super admin
		$this->assert_true( true, 'super admin passes the export gate' );

		igbz()->settings()->set( 'security.senior_admin_id', 9 );
		$this->become( 9 );
		$guard->guard_export(); // must not throw for the senior admin either
		$this->assert_true( true, 'senior admin passes the export gate through the audited lane' );
	}

	private function oembed_author_is_stripped(): void {
		$data = $this->guard()->strip_oembed_author(
			[ 'author_name' => 'admin', 'author_url' => 'https://x/author/admin', 'title' => 't' ]
		);
		$this->assert_false( isset( $data['author_name'] ), 'author name removed from oembed data' );
		$this->assert_false( isset( $data['author_url'] ), 'author url removed from oembed data' );
		$this->assert_same( 't', (string) ( $data['title'] ?? '' ), 'non-identity fields survive' );
	}

	private function only_super_admin_may_set_the_senior_id(): void {
		$settings = igbz_test_reset_settings();
		$settings->set( 'security.senior_admin_id', 0 );

		$page = new SettingsPage( $settings );
		$post = static function ( int $user_id, array $caps ) use ( $page ): void {
			$GLOBALS['igbz_test_user_id']              = $user_id;
			$GLOBALS['igbz_test_capabilities'][ $user_id ] = array_fill_keys( $caps, true );
			$_POST['igbz']    = [ 'security.senior_admin_id' => '42' ];
			$_POST['_wpnonce'] = wp_create_nonce( 'igbz_save_settings_advanced' );
			$method = new ReflectionMethod( $page, 'handle_post' );
			$method->invoke( $page, 'advanced' );
		};

		// A suite manager without delete_users must not be able to appoint the senior admin.
		$post( 11, [ Capabilities::MANAGE_SUITE ] );
		$this->assert_same( 0, igbz()->settings()->int( 'security.senior_admin_id', 0 ), 'non super admin save of the senior id is dropped' );

		// The platform super admin may.
		$post( 12, [ Capabilities::MANAGE_SUITE, 'delete_users' ] );
		$this->assert_same( 42, igbz()->settings()->int( 'security.senior_admin_id', 0 ), 'super admin save of the senior id sticks' );

		unset( $_POST['igbz'], $_POST['_wpnonce'] );
	}
}
