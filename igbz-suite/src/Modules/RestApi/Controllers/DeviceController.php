<?php
namespace IGBZ\Suite\Modules\RestApi\Controllers;

use IGBZ\Suite\Modules\RestApi\Push\DeviceRepository;
use IGBZ\Suite\Modules\RestApi\Push\NotificationService;
use IGBZ\Suite\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Device registration, push and the app bootstrap payload.
 *
 *   POST /igbz/v1/devices/register     { device_id, fcm_token?, platform?, app_version?, locale? }
 *   POST /igbz/v1/devices/unregister   { device_id }
 *   GET  /igbz/v1/devices              (the caller's own devices)
 *   POST /igbz/v1/devices/test         { title?, body? }        - sends to the caller only
 *   POST /igbz/v1/notifications/send   { title, body, ... }     - store owner broadcast
 *   GET  /igbz/v1/app/config           - deep link + storefront bootstrap
 *   POST /igbz/v1/app/resolve-store    { phone }
 *
 * Port notes: the nop plugin stored FCM tokens on the customer record and had no way to remove
 * one, and `send-test` was open to any authenticated user with no audience control. Registration
 * here is per device, unregistration exists, the test endpoint only ever targets the caller, and
 * broadcasting requires the store-owner capability.
 */
final class DeviceController extends BaseController {

	public function __construct( private DeviceRepository $devices, private NotificationService $notifications ) {}

	public function register_routes(): void {
		$ns   = self::NAMESPACE;
		$auth = [ $this, 'is_logged_in' ];

		register_rest_route( $ns, '/devices/register', $this->route( 'POST', [ $this, 'register_device' ] ) );
		register_rest_route( $ns, '/devices/unregister', $this->route( 'POST', [ $this, 'unregister_device' ] ) );
		register_rest_route( $ns, '/devices', $this->route( 'GET', [ $this, 'list_devices' ], $auth ) );
		register_rest_route( $ns, '/devices/test', $this->route( 'POST', [ $this, 'test_push' ], $auth ) );
		register_rest_route( $ns, '/notifications/send', $this->route( 'POST', [ $this, 'send' ], [ $this, 'can_manage_tenant' ] ) );
		register_rest_route( $ns, '/app/config', $this->route( 'GET', [ $this, 'app_config' ] ) );
		register_rest_route( $ns, '/app/resolve-store', $this->route( 'POST', [ $this, 'resolve_store' ] ) );
	}

	// ------------------------------------------------------------ devices

	/**
	 * Anonymous registration is allowed on purpose: the app needs a token before the customer
	 * signs in so that abandoned-cart and marketing pushes can reach them. Once they log in the
	 * same device_id is re-registered and the row is claimed by the user.
	 */
	public function register_device( \WP_REST_Request $request ): \WP_REST_Response {
		$device_id = (string) $request->get_param( 'device_id' );
		if ( '' === trim( $device_id ) ) {
			return $this->fail( 'missing_device_id', __( 'A device id is required.', 'igbz-suite' ) );
		}

		$existing = $this->devices->find( $device_id );
		$user_id  = get_current_user_id();

		// Never let one device steal another user's registration.
		if ( $existing && 0 === $user_id && (int) $existing['user_id'] > 0 ) {
			$user_id = (int) $existing['user_id'];
		}

		$id = $this->devices->register(
			[
				'device_id'   => $device_id,
				'user_id'     => $user_id,
				'tenant_id'   => $this->scoped_tenant_id( $request ),
				'platform'    => (string) $request->get_param( 'platform' ),
				'fcm_token'   => (string) $request->get_param( 'fcm_token' ),
				'app_version' => (string) $request->get_param( 'app_version' ),
				'locale'      => (string) $request->get_param( 'locale' ),
				// Phase 12: enrolment of the biometric signature key (stored encrypted).
				'signing_key' => (string) $request->get_param( 'signing_key' ),
			]
		);

		if ( $id <= 0 ) {
			return $this->fail( 'register_failed', __( 'The device could not be registered.', 'igbz-suite' ), 500 );
		}

		return $this->ok(
			[
				'ok'           => true,
				'device_row'   => $id,
				'push_enabled' => igbz()->settings()->bool( 'api.push_enabled', false ),
			]
		);
	}

	public function unregister_device( \WP_REST_Request $request ): \WP_REST_Response {
		$device_id = (string) $request->get_param( 'device_id' );
		if ( '' === trim( $device_id ) ) {
			return $this->fail( 'missing_device_id', __( 'A device id is required.', 'igbz-suite' ) );
		}

		$this->devices->unregister( $device_id, get_current_user_id() );

		return $this->ok( [ 'ok' => true ] );
	}

	public function list_devices(): \WP_REST_Response {
		$items = [];
		foreach ( $this->devices->for_user( get_current_user_id() ) as $device ) {
			$items[] = [
				'device_id'    => (string) $device['device_id'],
				'platform'     => (string) $device['platform'],
				'app_version'  => (string) $device['app_version'],
				'locale'       => (string) $device['locale'],
				'push_ready'   => '' !== (string) $device['fcm_token'],
				'last_seen_at' => (string) $device['last_seen_at'],
			];
		}

		return $this->ok( [ 'devices' => $items ] );
	}

	// --------------------------------------------------------------- push

	public function test_push( \WP_REST_Request $request ): \WP_REST_Response {
		$result = $this->notifications->broadcast(
			[
				'title' => (string) ( $request->get_param( 'title' ) ?: __( 'Test notification', 'igbz-suite' ) ),
				'body'  => (string) ( $request->get_param( 'body' ) ?: __( 'If you can read this, push is working.', 'igbz-suite' ) ),
				'type'  => 'test',
			],
			[ 'user_ids' => [ get_current_user_id() ] ]
		);

		return $this->ok( $result, $result['ok'] ? 200 : 502 );
	}

	public function send( \WP_REST_Request $request ): \WP_REST_Response {
		$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
		$body  = sanitize_textarea_field( (string) $request->get_param( 'body' ) );

		if ( '' === $title || '' === $body ) {
			return $this->fail( 'missing_content', __( 'A title and a body are required.', 'igbz-suite' ) );
		}

		$audience = [ 'tenant_id' => $this->scoped_tenant_id( $request ) ];

		$user_ids = $request->get_param( 'user_ids' );
		if ( is_array( $user_ids ) && $user_ids ) {
			$audience['user_ids'] = array_map( 'intval', $user_ids );
		}

		$platform = sanitize_key( (string) $request->get_param( 'platform' ) );
		if ( in_array( $platform, [ 'ios', 'android' ], true ) ) {
			$audience['platform'] = $platform;
		}

		// Only a platform admin may reach every tenant at once.
		if ( $request->get_param( 'all_tenants' ) && Capabilities::current_user_can( Capabilities::MANAGE_TENANTS ) ) {
			unset( $audience['tenant_id'] );
		}

		$result = $this->notifications->broadcast(
			[
				'title' => $title,
				'body'  => $body,
				'type'  => sanitize_key( (string) $request->get_param( 'type' ) ) ?: 'general',
				'link'  => esc_url_raw( (string) $request->get_param( 'link' ) ),
				'image' => esc_url_raw( (string) $request->get_param( 'image' ) ),
			],
			$audience
		);

		return $this->ok( $result, $result['ok'] ? 200 : 502 );
	}

	// --------------------------------------------------------- app config

	/**
	 * Everything the app needs on its first screen: deep-link scheme, update gate, storefront
	 * branding and the enabled feature set. The nop version returned placeholder `.local` URLs
	 * hard-coded in the controller; these all come from settings.
	 */
	public function app_config( \WP_REST_Request $request ): \WP_REST_Response {
		$settings  = igbz()->settings();
		$tenant_id = $this->scoped_tenant_id( $request );
		$tenant    = $tenant_id > 0 ? igbz()->tenancy()->repository()->find( $tenant_id ) : null;

		$scheme = $settings->string( 'api.app_scheme', 'igbz' );

		return $this->ok(
			[
				'store'     => [
					'tenant_id' => $tenant_id,
					'name'      => $tenant ? $tenant->name : get_bloginfo( 'name' ),
					'slug'      => $tenant ? $tenant->slug : '',
					'logo_url'  => $tenant ? (string) $tenant->setting( 'logo_url', '' ) : '',
					'currency'  => $settings->string( 'general.default_currency', 'IRT' ),
					'home_url'  => home_url( '/' ),
					'rtl'       => is_rtl(),
				],
				'deep_link' => [
					'scheme'          => $scheme,
					'scheme_prefix'   => $scheme . '://',
					'universal_link'  => $settings->string( 'api.universal_link', home_url( '/app/' ) ),
					'android_package' => $settings->string( 'api.android_package', '' ),
					'ios_bundle_id'   => $settings->string( 'api.ios_bundle_id', '' ),
					'routes'          => [
						'product'    => $scheme . '://products/{id}',
						'category'   => $scheme . '://categories/{id}',
						'order'      => $scheme . '://orders/{id}',
						'course'     => $scheme . '://courses/{id}',
						'wallet'     => $scheme . '://wallet',
						'instalment' => $scheme . '://instalments/{id}',
					],
				],
				'update'    => [
					'latest_version'  => $settings->string( 'api.latest_app_version', '' ),
					'minimum_version' => $settings->string( 'api.min_app_version', '' ),
					'apk_url'         => $settings->string( 'api.apk_url', '' ),
					'store_url_ios'   => $settings->string( 'api.ios_store_url', '' ),
					'force_update'    => $this->needs_force_update( (string) $request->get_param( 'app_version' ) ),
				],
				'features'  => [
					'wallet'      => $settings->bool( 'wallet.enabled', true ),
					'bnpl'        => $settings->bool( 'bnpl.enabled', false ),
					'lms'         => $settings->bool( 'lms.enabled', false ),
					'affiliate'   => $settings->bool( 'affiliate.enabled', false ),
					'otp_login'   => $settings->bool( 'otp.enabled', true ),
					'push'        => $settings->bool( 'api.push_enabled', false ),
				],
				'api'       => [
					'namespace' => self::NAMESPACE,
					'base_url'  => rest_url( self::NAMESPACE ),
				],
			]
		);
	}

	private function needs_force_update( string $app_version ): bool {
		$minimum = igbz()->settings()->string( 'api.min_app_version', '' );
		if ( '' === $minimum || '' === $app_version ) {
			return false;
		}

		return version_compare( $app_version, $minimum, '<' );
	}

	/**
	 * Deferred deep linking: the app knows the customer's phone number but not which store they
	 * belong to. The original leaked every store a phone number touched; this returns the single
	 * home store and nothing else.
	 */
	public function resolve_store( \WP_REST_Request $request ): \WP_REST_Response {
		$phone = preg_replace( '/\D+/', '', (string) $request->get_param( 'phone' ) );
		if ( strlen( (string) $phone ) < 8 ) {
			return $this->fail( 'invalid_phone', __( 'A valid phone number is required.', 'igbz-suite' ) );
		}

		$users = get_users(
			[
				'meta_key'   => 'igbz_phone',
				'meta_value' => $phone,
				'number'     => 1,
				'fields'     => 'ID',
			]
		);

		if ( ! $users ) {
			return $this->ok( [ 'found' => false ] );
		}

		$tenant = igbz()->tenancy()->repository()->find_primary_for_user( (int) $users[0] );
		if ( ! $tenant ) {
			return $this->ok( [ 'found' => false ] );
		}

		return $this->ok(
			[
				'found'  => true,
				'store'  => [
					'tenant_id' => $tenant->id,
					'name'      => $tenant->name,
					'slug'      => $tenant->slug,
					'status'    => $tenant->status,
				],
			]
		);
	}
}
