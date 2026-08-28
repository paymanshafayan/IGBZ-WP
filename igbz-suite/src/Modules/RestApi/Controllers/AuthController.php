<?php
namespace IGBZ\Suite\Modules\RestApi\Controllers;

use IGBZ\Suite\Modules\MultiTenant\Otp\OtpService;
use IGBZ\Suite\Modules\RestApi\Auth\Authenticator;
use IGBZ\Suite\Modules\RestApi\Auth\TokenService;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Mobile authentication.
 *
 *   GET  /igbz/v1/auth/login-options
 *   POST /igbz/v1/auth/otp/request   { phone }
 *   POST /igbz/v1/auth/otp/verify    { phone, code, device_id? }
 *   POST /igbz/v1/auth/password      { username, password, device_id? }
 *   POST /igbz/v1/auth/refresh       { refresh_token, device_id? }
 *   POST /igbz/v1/auth/logout
 *   GET  /igbz/v1/auth/sessions
 *   POST /igbz/v1/auth/sessions/revoke { jti? | all }
 *   GET  /igbz/v1/auth/me
 *
 * Port note: the original advertised an "Instagram business login" option that Meta does not allow
 * for personal accounts, and it is impossible here anyway because the Graph API integration was
 * deliberately replaced by Manus and ManyChat. The advertised options are therefore phone OTP and
 * username/password only, driven by what is actually configured.
 */
final class AuthController extends BaseController {

	public function __construct(
		private TokenService $tokens,
		private OtpService $otp,
		private Logger $logger
	) {}

	public function register_routes(): void {
		$ns = self::NAMESPACE;

		register_rest_route( $ns, '/auth/login-options', $this->route( 'GET', [ $this, 'login_options' ] ) );
		register_rest_route( $ns, '/auth/otp/request', $this->route( 'POST', [ $this, 'request_otp' ] ) );
		register_rest_route( $ns, '/auth/otp/verify', $this->route( 'POST', [ $this, 'verify_otp' ] ) );
		register_rest_route( $ns, '/auth/password', $this->route( 'POST', [ $this, 'password_login' ] ) );
		register_rest_route( $ns, '/auth/refresh', $this->route( 'POST', [ $this, 'refresh' ] ) );
		register_rest_route( $ns, '/auth/logout', $this->route( 'POST', [ $this, 'logout' ], [ $this, 'is_logged_in' ] ) );
		register_rest_route( $ns, '/auth/sessions', $this->route( 'GET', [ $this, 'sessions' ], [ $this, 'is_logged_in' ] ) );
		register_rest_route( $ns, '/auth/sessions/revoke', $this->route( 'POST', [ $this, 'revoke_session' ], [ $this, 'is_logged_in' ] ) );
		register_rest_route( $ns, '/auth/me', $this->route( 'GET', [ $this, 'me' ], [ $this, 'is_logged_in' ] ) );
	}

	// -------------------------------------------------------------- options

	public function login_options(): \WP_REST_Response {
		$settings = igbz()->settings();

		$sms_provider  = $settings->string( 'otp.sms_provider', 'log' );
		$otp_available = $settings->bool( 'otp.enabled', true )
			&& ( 'log' === $sms_provider || '' !== $settings->string( 'otp.' . $sms_provider . '.api_key', '' ) );

		return $this->ok(
			[
				'options' => [
					[
						'method'    => 'phone_otp',
						'label'     => __( 'Sign in with your phone number', 'igbz-suite' ),
						'available' => $otp_available,
						'note'      => $otp_available
							? __( 'A one-time code is sent by SMS.', 'igbz-suite' )
							: __( 'No SMS provider is configured for this store.', 'igbz-suite' ),
					],
					[
						'method'    => 'password',
						'label'     => __( 'Sign in with a username and password', 'igbz-suite' ),
						'available' => true,
						'note'      => __( 'Uses the store account credentials.', 'igbz-suite' ),
					],
				],
				'store'   => [
					'name'      => get_bloginfo( 'name' ),
					'tenant_id' => igbz()->tenancy()->id(),
					'currency'  => $settings->string( 'general.default_currency', 'IRT' ),
				],
			]
		);
	}

	// ------------------------------------------------------------------ otp

	public function request_otp( \WP_REST_Request $request ): \WP_REST_Response {
		$phone = (string) $request->get_param( 'phone' );
		if ( '' === $phone ) {
			return $this->fail( 'missing_phone', __( 'A phone number is required.', 'igbz-suite' ) );
		}

		$result = $this->otp->send( $phone, OtpService::PURPOSE_LOGIN, igbz()->tenancy()->id() );

		if ( ! $result['ok'] ) {
			return $this->ok(
				[
					'ok'          => false,
					'error'       => $result['error'],
					'retry_after' => $result['retry_after'],
				],
				429
			);
		}

		return $this->ok(
			[
				'ok'          => true,
				'expires_in'  => $result['expires_in'],
				'retry_after' => $result['retry_after'],
			]
		);
	}

	public function verify_otp( \WP_REST_Request $request ): \WP_REST_Response {
		$phone = (string) $request->get_param( 'phone' );
		$code  = (string) $request->get_param( 'code' );

		$result = $this->otp->verify( $phone, $code, OtpService::PURPOSE_LOGIN );
		if ( ! $result['ok'] ) {
			return $this->fail( 'otp_invalid', $result['error'], 401 );
		}

		$tenant_id = igbz()->tenancy()->id();
		$user_id   = $result['user_id'] > 0 ? $result['user_id'] : $this->otp->resolve_or_create_user( $phone, $tenant_id );

		if ( $user_id <= 0 ) {
			return $this->fail( 'user_failed', __( 'The account could not be created.', 'igbz-suite' ), 500 );
		}

		$is_new = ! (bool) get_user_meta( $user_id, 'igbz_api_seen', true );
		update_user_meta( $user_id, 'igbz_api_seen', 1 );

		$tokens               = $this->tokens->issue( $user_id, $tenant_id, $this->device_id( $request ) );
		$tokens['is_new_user'] = $is_new;

		return $this->ok( $tokens );
	}

	// ------------------------------------------------------------ password

	public function password_login( \WP_REST_Request $request ): \WP_REST_Response {
		$username = (string) $request->get_param( 'username' );
		$password = (string) $request->get_param( 'password' );

		if ( '' === $username || '' === $password ) {
			return $this->fail( 'missing_credentials', __( 'A username and password are required.', 'igbz-suite' ) );
		}

		if ( ! $this->within_login_throttle( $username ) ) {
			return $this->fail( 'throttled', __( 'Too many failed attempts. Try again in a few minutes.', 'igbz-suite' ), 429 );
		}

		$user = wp_authenticate( $username, $password );
		if ( is_wp_error( $user ) ) {
			$this->logger->warning( 'api', 'Failed password login', [ 'username' => $username ] );
			return $this->fail( 'invalid_credentials', __( 'The username or password is incorrect.', 'igbz-suite' ), 401 );
		}

		return $this->ok( $this->tokens->issue( (int) $user->ID, igbz()->tenancy()->id(), $this->device_id( $request ) ) );
	}

	private function within_login_throttle( string $username ): bool {
		$key  = 'igbz_api_login_' . md5( strtolower( $username ) );
		$hits = (int) get_transient( $key );
		if ( $hits >= 10 ) {
			return false;
		}
		set_transient( $key, $hits + 1, 5 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Phase 12: refresh tokens are unguessable, but the endpoint itself still deserves a cap —
	 * keyed by client address plus a hash of the presented token so one loud client cannot
	 * spend another's budget.
	 */
	private function within_refresh_throttle( string $refresh_token ): bool {
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key  = 'igbz_api_refresh_' . md5( $ip . '|' . substr( hash( 'sha256', $refresh_token ), 0, 32 ) );
		$hits = (int) get_transient( $key );
		if ( $hits >= 20 ) {
			return false;
		}
		set_transient( $key, $hits + 1, 5 * MINUTE_IN_SECONDS );
		return true;
	}

	// ------------------------------------------------------------- refresh

	public function refresh( \WP_REST_Request $request ): \WP_REST_Response {
		$refresh_token = (string) $request->get_param( 'refresh_token' );
		if ( '' === $refresh_token ) {
			return $this->fail( 'missing_refresh_token', __( 'A refresh token is required.', 'igbz-suite' ) );
		}

		if ( ! $this->within_refresh_throttle( $refresh_token ) ) {
			return $this->fail( 'too_many_refresh_attempts', __( 'Too many refresh attempts. Please wait.', 'igbz-suite' ), 429 );
		}

		$result = $this->tokens->refresh( $refresh_token, $this->device_id( $request ) );
		if ( ! $result['ok'] ) {
			return $this->fail( $result['error'], __( 'The refresh token is not valid. Please sign in again.', 'igbz-suite' ), 401 );
		}

		return $this->ok( $result['tokens'] );
	}

	// -------------------------------------------------------------- session

	public function logout(): \WP_REST_Response {
		$validated = $this->tokens->validate( Authenticator::bearer_token() );
		if ( $validated['ok'] ) {
			$this->tokens->revoke_jti( $validated['jti'] );
		}
		return $this->ok( [ 'ok' => true ] );
	}

	public function sessions(): \WP_REST_Response {
		return $this->ok( [ 'sessions' => $this->tokens->sessions( get_current_user_id() ) ] );
	}

	public function revoke_session( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = get_current_user_id();

		if ( $request->get_param( 'all' ) ) {
			return $this->ok( [ 'ok' => true, 'revoked' => $this->tokens->revoke_all_for_user( $user_id ) ] );
		}

		$jti = (string) $request->get_param( 'jti' );
		if ( '' === $jti ) {
			return $this->fail( 'missing_jti', __( 'Pass a session id or all=1.', 'igbz-suite' ) );
		}

		// Never let one user revoke another user's session.
		$owned = false;
		foreach ( $this->tokens->sessions( $user_id ) as $session ) {
			if ( (string) $session['jti'] === $jti ) {
				$owned = true;
				break;
			}
		}
		if ( ! $owned ) {
			return $this->fail( 'not_found', __( 'Session not found.', 'igbz-suite' ), 404 );
		}

		$this->tokens->revoke_jti( $jti );

		return $this->ok( [ 'ok' => true, 'revoked' => 1 ] );
	}

	public function me(): \WP_REST_Response {
		return $this->ok( $this->tokens->user_payload( get_current_user_id(), igbz()->tenancy()->id() ) );
	}

	private function device_id( \WP_REST_Request $request ): string {
		$device = (string) ( $request->get_param( 'device_id' ) ?: $request->get_header( 'x_igbz_device' ) );
		return substr( sanitize_text_field( $device ), 0, 128 );
	}
}
