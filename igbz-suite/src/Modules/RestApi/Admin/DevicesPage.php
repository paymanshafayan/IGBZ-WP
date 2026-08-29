<?php
namespace IGBZ\Suite\Modules\RestApi\Admin;

use IGBZ\Suite\Modules\RestApi\Auth\TokenService;
use IGBZ\Suite\Modules\RestApi\Controllers\BaseController;
use IGBZ\Suite\Modules\RestApi\Push\DeviceRepository;
use IGBZ\Suite\Modules\RestApi\Push\FcmService;
use IGBZ\Suite\Modules\RestApi\Push\GoogleAuth;
use IGBZ\Suite\Modules\RestApi\Push\NotificationService;
use IGBZ\Suite\Support\Admin\Menu;
use IGBZ\Suite\Support\TenantScope;
use IGBZ\Suite\Support\Admin\View;
use IGBZ\Suite\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Mobile API admin screen: registered devices, push broadcasts, live sessions and the route map.
 *
 * The nop plugin had no admin surface at all for the API — the only way to see whether push worked
 * was to read the log. Everything here is read from the same tables the endpoints use.
 */
final class DevicesPage {

	public const SLUG = 'igbz-mobile-api';

	private const NONCE = 'igbz_mobile_api';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 40 );
	}

	public function add_page(): void {
		Menu::add( self::SLUG, __( 'Mobile API', 'igbz-suite' ), [ $this, 'render' ], Capabilities::MANAGE_API );
	}

	private function devices(): DeviceRepository {
		return igbz()->get( 'api.devices' );
	}

	private function notifications(): NotificationService {
		return igbz()->get( 'api.notifications' );
	}

	private function tokens(): TokenService {
		return igbz()->get( 'api.tokens' );
	}

	private function push(): FcmService {
		return igbz()->get( 'api.push' );
	}

	private function google(): GoogleAuth {
		return igbz()->get( 'api.google_auth' );
	}

	public function render(): void {
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$this->handle_post();
		}
		$this->handle_get_actions();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'devices';

		View::open(
			__( 'Mobile API', 'igbz-suite' ),
			__( 'The back end for the mobile app: authenticated sessions, registered devices and push notifications.', 'igbz-suite' )
		);

		View::tabs(
			[
				'devices'  => __( 'Devices', 'igbz-suite' ),
				'push'     => __( 'Send a notification', 'igbz-suite' ),
				'sessions' => __( 'Sessions', 'igbz-suite' ),
				'routes'   => __( 'Endpoints', 'igbz-suite' ),
			],
			$tab,
			self::SLUG
		);

		match ( $tab ) {
			'push'     => $this->render_push(),
			'sessions' => $this->render_sessions(),
			'routes'   => $this->render_routes(),
			default    => $this->render_devices(),
		};

		View::close();
	}

	// ------------------------------------------------------------- devices

	private function render_devices(): void {
		$db       = igbz()->db();
		$per_page = 30;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged    = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
		$offset   = ( $paged - 1 ) * $per_page;

		$total = $this->devices()->count();
		$rows  = $db->results(
			'SELECT * FROM ' . $db->table( 'devices' ) . ' ORDER BY last_seen_at DESC, id DESC LIMIT %d OFFSET %d',
			$per_page,
			$offset
		);

		echo '<div class="igbz-cards">';
		foreach (
			[
				__( 'Registered devices', 'igbz-suite' ) => (string) $total,
				__( 'Push ready', 'igbz-suite' )         => (string) $this->devices()->count( [ 'with_token' => true ] ),
				__( 'Android', 'igbz-suite' )            => (string) $this->platform_count( 'android' ),
				__( 'iOS', 'igbz-suite' )                => (string) $this->platform_count( 'ios' ),
			] as $label => $value
		) {
			printf( '<div class="igbz-card"><strong>%1$s</strong><span>%2$s</span></div>', esc_html( $value ), esc_html( $label ) );
		}
		echo '</div>';

		$display = [];
		foreach ( $rows as $row ) {
			$user_id = (int) $row['user_id'];
			$user    = $user_id > 0 ? get_userdata( $user_id ) : null;

			$display[] = [
				'device'  => sprintf(
					'<code>%1$s</code><br /><span class="description">%2$s %3$s</span>',
					esc_html( substr( (string) $row['device_id'], 0, 24 ) ),
					esc_html( (string) $row['platform'] ?: '—' ),
					esc_html( (string) $row['app_version'] )
				),
				'user'    => $user
					? sprintf(
						'<a href="%1$s">%2$s</a>',
						esc_url( get_edit_user_link( $user_id ) ),
						esc_html( $user->display_name )
					)
					: '<span class="description">' . esc_html__( 'not signed in', 'igbz-suite' ) . '</span>',
				'push'    => View::status_pill( '' !== (string) $row['fcm_token'] ? 'ok' : 'warn' ),
				'seen'    => esc_html( (string) $row['last_seen_at'] ),
				'actions' => sprintf(
					'<a class="button button-small" href="%1$s">%2$s</a> <a class="button button-small" href="%3$s" onclick="return confirm(\'%4$s\')">%5$s</a>',
					esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'test' => (int) $row['id'] ] ), self::NONCE ) ),
					esc_html__( 'Test push', 'igbz-suite' ),
					esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'forget' => (int) $row['id'] ] ), self::NONCE ) ),
					esc_js( __( 'Remove this device registration?', 'igbz-suite' ) ),
					esc_html__( 'Forget', 'igbz-suite' )
				),
			];
		}

		View::table(
			[
				'device'  => __( 'Device', 'igbz-suite' ),
				'user'    => __( 'Customer', 'igbz-suite' ),
				'push'    => __( 'Push token', 'igbz-suite' ),
				'seen'    => __( 'Last seen (UTC)', 'igbz-suite' ),
				'actions' => __( 'Actions', 'igbz-suite' ),
			],
			$display,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'No app has registered a device yet.', 'igbz-suite' )
		);

		View::pagination( $total, $per_page, $paged, self::SLUG );
	}

	private function platform_count( string $platform ): int {
		$db = igbz()->db();
		return (int) $db->scalar(
			'SELECT COUNT(*) FROM ' . $db->table( 'devices' ) . ' WHERE platform = %s',
			$platform
		);
	}

	// ---------------------------------------------------------------- push

	private function render_push(): void {
		if ( ! $this->push()->is_enabled() ) {
			View::notice(
				__( 'Push is switched off or Firebase is not configured. Fill in the service account JSON on the Mobile API settings tab.', 'igbz-suite' ),
				'warning'
			);
		}

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Delivered through FCM HTTP v1. Tokens Google reports as dead are cleared automatically, so the counts below are real deliveries, not attempts.', 'igbz-suite' )
		);

		echo '<form method="post">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="igbz_action" value="broadcast" />';
		echo '<table class="form-table" role="presentation"><tbody>';

		View::field( [ 'key' => 'title', 'label' => __( 'Title', 'igbz-suite' ) ], '' );
		View::field( [ 'key' => 'body', 'label' => __( 'Message', 'igbz-suite' ), 'type' => 'textarea' ], '' );
		View::field(
			[
				'key'   => 'link',
				'label' => __( 'Deep link', 'igbz-suite' ),
				'help'  => __( 'Optional, e.g. igbz://products/42 — the app opens this screen when the notification is tapped.', 'igbz-suite' ),
			],
			''
		);
		View::field( [ 'key' => 'image', 'label' => __( 'Image URL', 'igbz-suite' ) ], '' );
		View::field(
			[
				'key'     => 'platform',
				'label'   => __( 'Limit to platform', 'igbz-suite' ),
				'type'    => 'select',
				'options' => [
					''        => __( 'Everyone', 'igbz-suite' ),
					'android' => __( 'Android only', 'igbz-suite' ),
					'ios'     => __( 'iOS only', 'igbz-suite' ),
				],
			],
			''
		);
		View::field(
			[
				'key'   => 'user_ids',
				'label' => __( 'Only these user ids', 'igbz-suite' ),
				'help'  => __( 'Comma separated. Leave empty to reach every registered device.', 'igbz-suite' ),
			],
			''
		);

		echo '</tbody></table>';
		submit_button( __( 'Send notification', 'igbz-suite' ) );
		echo '</form>';

		$last = get_transient( TenantScope::cache_key( 'igbz_push_last_result_' . get_current_user_id() ) );
		if ( is_array( $last ) ) {
			delete_transient( TenantScope::cache_key( 'igbz_push_last_result_' . get_current_user_id() ) );
			echo '<h2>' . esc_html__( 'Last send', 'igbz-suite' ) . '</h2>';
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: delivered, 2: total targeted, 3: dead tokens, 4: failures */
						__( 'Delivered to %1$d of %2$d device(s). %3$d dead token(s) cleared, %4$d failure(s).', 'igbz-suite' ),
						(int) $last['sent'],
						(int) $last['total'],
						(int) $last['invalid'],
						(int) $last['failed']
					)
				)
			);
			if ( '' !== (string) ( $last['error'] ?? '' ) ) {
				View::notice( (string) $last['error'], 'error' );
			}
		}
	}

	// ------------------------------------------------------------ sessions

	private function render_sessions(): void {
		$db   = igbz()->db();
		$rows = $db->results(
			'SELECT * FROM ' . $db->table( 'api_tokens' ) . '
			 WHERE revoked_at IS NULL AND refresh_expires_at > %s
			 ORDER BY last_used_at DESC, id DESC LIMIT 100',
			current_time( 'mysql', true )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Every issued access token is recorded, so a lost phone can be signed out from here without resetting the password.', 'igbz-suite' )
		);

		$display = [];
		foreach ( $rows as $row ) {
			$user = get_userdata( (int) $row['user_id'] );

			$display[] = [
				'user'    => $user ? esc_html( $user->display_name ) : '#' . (int) $row['user_id'],
				'device'  => '<code>' . esc_html( substr( (string) $row['device_id'], 0, 24 ) ?: '—' ) . '</code>',
				'issued'  => esc_html( (string) $row['issued_at'] ),
				'used'    => esc_html( (string) ( $row['last_used_at'] ?: '—' ) ),
				'expires' => esc_html( (string) $row['refresh_expires_at'] ),
				'actions' => sprintf(
					'<a class="button button-small" href="%1$s">%2$s</a>',
					esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'tab' => 'sessions', 'revoke' => rawurlencode( (string) $row['jti'] ) ] ), self::NONCE ) ),
					esc_html__( 'Revoke', 'igbz-suite' )
				),
			];
		}

		View::table(
			[
				'user'    => __( 'Customer', 'igbz-suite' ),
				'device'  => __( 'Device', 'igbz-suite' ),
				'issued'  => __( 'Issued', 'igbz-suite' ),
				'used'    => __( 'Last used', 'igbz-suite' ),
				'expires' => __( 'Refresh expires', 'igbz-suite' ),
				'actions' => __( 'Actions', 'igbz-suite' ),
			],
			$display,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'Nobody is signed in from the app.', 'igbz-suite' )
		);
	}

	// -------------------------------------------------------------- routes

	private function render_routes(): void {
		$base = rest_url( BaseController::NAMESPACE );

		echo '<table class="form-table" role="presentation"><tbody>';
		View::field( [ 'key' => 'api_base', 'label' => __( 'API base', 'igbz-suite' ), 'type' => 'readonly' ], $base );
		View::field(
			[
				'key'   => 'api_auth_header',
				'label' => __( 'Authentication', 'igbz-suite' ),
				'type'  => 'readonly',
				'help'  => __( 'Access tokens are short lived; call /auth/refresh with the refresh token to rotate them.', 'igbz-suite' ),
			],
			'Authorization: Bearer <access_token>'
		);
		View::field(
			[
				'key'   => 'api_fcm',
				'label' => __( 'Firebase project', 'igbz-suite' ),
				'type'  => 'readonly',
			],
			$this->google()->is_configured() ? $this->google()->project_id() : __( 'not configured', 'igbz-suite' )
		);
		echo '</tbody></table>';

		$routes = [
			__( 'Authentication', 'igbz-suite' ) => [
				'GET  /auth/login-options'         => __( 'Which sign-in methods this store offers.', 'igbz-suite' ),
				'POST /auth/otp/request'           => __( 'Send a one-time code by SMS.', 'igbz-suite' ),
				'POST /auth/otp/verify'            => __( 'Exchange the code for a token pair.', 'igbz-suite' ),
				'POST /auth/password'              => __( 'Username and password sign-in.', 'igbz-suite' ),
				'POST /auth/refresh'               => __( 'Rotate the token pair.', 'igbz-suite' ),
				'POST /auth/logout'                => __( 'Revoke this device.', 'igbz-suite' ),
				'GET  /auth/sessions'              => __( 'List the caller\'s sessions.', 'igbz-suite' ),
				'POST /auth/sessions/revoke'       => __( 'Sign a specific device out.', 'igbz-suite' ),
				'GET  /auth/me'                    => __( 'The signed-in customer.', 'igbz-suite' ),
			],
			__( 'Catalogue', 'igbz-suite' ) => [
				'GET  /catalog/categories'         => __( 'Category tree for the current store.', 'igbz-suite' ),
				'GET  /catalog/products'           => __( 'Product list with filters and paging.', 'igbz-suite' ),
				'GET  /catalog/products/{id}'      => __( 'One product with variations and gallery.', 'igbz-suite' ),
				'GET  /catalog/search-suggest'     => __( 'Type-ahead suggestions.', 'igbz-suite' ),
			],
			__( 'Account', 'igbz-suite' ) => [
				'GET|POST /account/profile'        => __( 'Read and update the profile.', 'igbz-suite' ),
				'GET  /account/orders'             => __( 'Order history.', 'igbz-suite' ),
				'GET  /account/orders/{id}'        => __( 'One order with its lines.', 'igbz-suite' ),
				'GET  /account/wallet'             => __( 'Balance and ledger.', 'igbz-suite' ),
				'POST /account/wallet/topup'       => __( 'Start a wallet top-up payment.', 'igbz-suite' ),
				'GET  /account/instalments'        => __( 'BNPL contracts and schedule.', 'igbz-suite' ),
				'POST /account/instalments/{id}/pay' => __( 'Pay an instalment from the wallet.', 'igbz-suite' ),
				'GET  /account/courses'            => __( 'Enrolled courses and progress.', 'igbz-suite' ),
				'POST /account/courses/progress'   => __( 'Record lesson progress.', 'igbz-suite' ),
				'GET  /account/affiliate'          => __( 'Referral link, clicks and commissions.', 'igbz-suite' ),
				'GET  /account/payments'           => __( 'PSP payment history.', 'igbz-suite' ),
			],
			__( 'Devices and app', 'igbz-suite' ) => [
				'POST /devices/register'           => __( 'Register or refresh a push token.', 'igbz-suite' ),
				'POST /devices/unregister'         => __( 'Forget a device.', 'igbz-suite' ),
				'GET  /devices'                    => __( 'The caller\'s own devices.', 'igbz-suite' ),
				'POST /devices/test'               => __( 'Send a test push to the caller.', 'igbz-suite' ),
				'POST /notifications/send'         => __( 'Broadcast (store owner only).', 'igbz-suite' ),
				'GET  /app/config'                 => __( 'Deep-link scheme, update gate, branding and feature flags.', 'igbz-suite' ),
				'POST /app/resolve-store'          => __( 'Deferred deep link: find the store for a phone number.', 'igbz-suite' ),
			],
		];

		foreach ( $routes as $group => $entries ) {
			echo '<h2>' . esc_html( $group ) . '</h2>';

			$rows = [];
			foreach ( $entries as $route => $description ) {
				$rows[] = [
					'route' => '<code>' . esc_html( $route ) . '</code>',
					'what'  => esc_html( $description ),
				];
			}

			View::table(
				[ 'route' => __( 'Route', 'igbz-suite' ), 'what' => __( 'Purpose', 'igbz-suite' ) ],
				$rows,
				static fn ( array $row, string $key ): string => (string) $row[ $key ]
			);
		}
	}

	// ------------------------------------------------------------- actions

	private function handle_post(): void {
		$action = isset( $_POST['igbz_action'] ) ? sanitize_key( wp_unslash( $_POST['igbz_action'] ) ) : '';
		if ( 'broadcast' !== $action ) {
			return;
		}

		View::check_nonce( self::NONCE );
		Capabilities::require( Capabilities::MANAGE_API );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$raw = isset( $_POST['igbz'] ) && is_array( $_POST['igbz'] ) ? wp_unslash( $_POST['igbz'] ) : [];
		// phpcs:enable

		$title = sanitize_text_field( (string) ( $raw['title'] ?? '' ) );
		$body  = sanitize_textarea_field( (string) ( $raw['body'] ?? '' ) );

		if ( '' === $title || '' === $body ) {
			View::notice( __( 'A title and a message are required.', 'igbz-suite' ), 'error' );
			return;
		}

		$audience = [];

		$platform = sanitize_key( (string) ( $raw['platform'] ?? '' ) );
		if ( in_array( $platform, [ 'ios', 'android' ], true ) ) {
			$audience['platform'] = $platform;
		}

		$ids = array_filter( array_map( 'intval', preg_split( '/[\s,]+/', (string) ( $raw['user_ids'] ?? '' ) ) ?: [] ) );
		if ( $ids ) {
			$audience['user_ids'] = array_values( $ids );
		}

		// A store owner may only reach their own customers; a platform admin reaches everyone.
		if ( ! Capabilities::current_user_can( Capabilities::MANAGE_TENANTS ) ) {
			$audience['tenant_id'] = igbz()->tenancy()->id();
		}

		$result = $this->notifications()->broadcast(
			[
				'title' => $title,
				'body'  => $body,
				'type'  => 'broadcast',
				'link'  => esc_url_raw( (string) ( $raw['link'] ?? '' ) ),
				'image' => esc_url_raw( (string) ( $raw['image'] ?? '' ) ),
			],
			$audience
		);

		set_transient( TenantScope::cache_key( 'igbz_push_last_result_' . get_current_user_id() ), $result, 120 );

		View::notice(
			$result['ok']
				? __( 'Notification sent.', 'igbz-suite' )
				: ( $result['error'] ?: __( 'The notification could not be sent.', 'igbz-suite' ) ),
			$result['ok'] ? 'success' : 'error'
		);
	}

	private function handle_get_actions(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$test   = isset( $_GET['test'] ) ? (int) $_GET['test'] : 0;
		$forget = isset( $_GET['forget'] ) ? (int) $_GET['forget'] : 0;
		$revoke = isset( $_GET['revoke'] ) ? sanitize_text_field( rawurldecode( (string) $_GET['revoke'] ) ) : '';
		// phpcs:enable

		if ( ! $test && ! $forget && '' === $revoke ) {
			return;
		}

		View::check_nonce( self::NONCE );
		Capabilities::require( Capabilities::MANAGE_API );

		$db = igbz()->db();

		if ( $test > 0 ) {
			$row = $db->row( 'SELECT * FROM ' . $db->table( 'devices' ) . ' WHERE id = %d', $test );
			if ( ! $row || '' === (string) $row['fcm_token'] ) {
				View::notice( __( 'That device has no push token.', 'igbz-suite' ), 'error' );
			} else {
				$result = $this->push()->send(
					[
						'title' => __( 'Test notification', 'igbz-suite' ),
						'body'  => __( 'If you can read this, push is working.', 'igbz-suite' ),
						'type'  => 'test',
					],
					[ 'device_ids' => [ (int) $row['id'] ] ]
				);

				View::notice(
					$result['sent'] > 0
						? __( 'Test notification delivered.', 'igbz-suite' )
						: ( $result['error'] ?: __( 'Firebase rejected the send.', 'igbz-suite' ) ),
					$result['sent'] > 0 ? 'success' : 'error'
				);
			}
		}

		if ( $forget > 0 ) {
			$db->delete( 'devices', [ 'id' => $forget ] );
			View::notice( __( 'Device registration removed.', 'igbz-suite' ) );
		}

		if ( '' !== $revoke ) {
			$this->tokens()->revoke_jti( $revoke );
			View::notice( __( 'Session revoked. That device must sign in again.', 'igbz-suite' ) );
		}
	}
}
