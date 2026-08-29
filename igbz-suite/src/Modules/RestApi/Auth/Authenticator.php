<?php
namespace IGBZ\Suite\Modules\RestApi\Auth;

use IGBZ\Suite\Modules\RestApi\Controllers\BaseController;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a Bearer token into the current WordPress user for our namespace only, and enforces a
 * per-token rate limit.
 *
 * Deliberately scoped: `determine_current_user` fires for every request, so we only act when the
 * request targets `igbz/v1` and actually carries an Authorization header. Core cookie auth is
 * untouched everywhere else.
 */
final class Authenticator {

	/**
	 * Prefixes within `igbz/v1` that perform their own authentication.
	 *
	 * @var string[]
	 */
	private const SELF_AUTHENTICATING_ROUTES = [
		'/manychat/',
		'/manus/',
	];

	private ?array $resolved = null;

	public function __construct( private TokenService $tokens, private Logger $logger ) {}

	public function register(): void {
		add_filter( 'determine_current_user', [ $this, 'determine_current_user' ], 20 );
		add_filter( 'rest_authentication_errors', [ $this, 'rest_authentication_errors' ] );
	}

	public static function bearer_token(): string {
		$header = '';
		foreach ( [ 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ] as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$header = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				break;
			}
		}

		if ( '' === $header && function_exists( 'apache_request_headers' ) ) {
			$headers = apache_request_headers();
			foreach ( (array) $headers as $name => $value ) {
				if ( 0 === strcasecmp( (string) $name, 'authorization' ) ) {
					$header = sanitize_text_field( (string) $value );
					break;
				}
			}
		}

		if ( 0 !== stripos( $header, 'bearer ' ) ) {
			return '';
		}

		return trim( substr( $header, 7 ) );
	}

	public static function is_api_request(): bool {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		if ( ! str_contains( $uri, '/' . BaseController::NAMESPACE . '/' ) ) {
			return false;
		}

		return ! self::is_self_authenticating_route( $uri );
	}

	/**
	 * Routes inside `igbz/v1` that carry their own shared-secret check and must not be touched here.
	 *
	 * The Manus and ManyChat webhooks live in this namespace but authenticate with a shared token
	 * that may arrive as `Authorization: Bearer <token>` — the same header this class uses for JWT
	 * access tokens. Without this exclusion the authenticator saw the webhook secret, failed to
	 * validate it as a JWT and short-circuited the request with a 401 from
	 * `rest_authentication_errors`, so the route's own `authorize()` never ran and every webhook
	 * delivery was rejected. These endpoints are third-party callbacks: they are anonymous by
	 * design and their permission_callback is the security boundary.
	 *
	 * @param string $uri Request URI, which may be either /wp-json/<ns>/... or ?rest_route=/<ns>/...
	 */
	private static function is_self_authenticating_route( string $uri ): bool {
		foreach ( self::SELF_AUTHENTICATING_ROUTES as $route ) {
			if ( str_contains( $uri, '/' . BaseController::NAMESPACE . $route ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param int|false $user_id
	 * @return int|false
	 */
	public function determine_current_user( $user_id ) {
		if ( $user_id ) {
			return $user_id;
		}
		if ( ! self::is_api_request() ) {
			return $user_id;
		}

		$token = self::bearer_token();
		if ( '' === $token ) {
			return $user_id;
		}

		$result = $this->tokens->validate( $token );
		$this->resolved = $result;

		if ( ! $result['ok'] ) {
			return $user_id;
		}

		$this->tokens->touch( $result['jti'] );

		if ( $result['tenant_id'] > 0 ) {
			igbz()->tenancy()->force( $result['tenant_id'] );
		}

		return $result['user_id'];
	}

	/**
	 * Surfaces a precise reason (expired vs revoked) instead of a blanket 401, and applies the
	 * per-token rate limit the original had no equivalent of.
	 *
	 * @param \WP_Error|null|true $errors
	 * @return \WP_Error|null|true
	 */
	public function rest_authentication_errors( $errors ) {
		if ( ! empty( $errors ) || ! self::is_api_request() ) {
			return $errors;
		}

		$token = self::bearer_token();
		if ( '' === $token ) {
			return $errors;
		}

		$result = $this->resolved ?? $this->tokens->validate( $token );

		if ( ! $result['ok'] ) {
			return new \WP_Error(
				'igbz_invalid_token',
				$this->message_for( $result['error'] ),
				[ 'status' => 401, 'reason' => $result['error'] ]
			);
		}

		if ( ! $this->within_rate_limit( $result['jti'], (int) ( $result['tenant_id'] ?? 0 ) ) ) {
			$this->logger->warning( 'api', 'Rate limit hit', [ 'user_id' => $result['user_id'], 'tenant_id' => (int) ( $result['tenant_id'] ?? 0 ) ] );
			return new \WP_Error(
				'igbz_rate_limited',
				__( 'Too many requests. Please slow down.', 'igbz-suite' ),
				[ 'status' => 429 ]
			);
		}

		return $errors;
	}

	private function message_for( string $reason ): string {
		return match ( $reason ) {
			'expired'  => __( 'The access token has expired. Use the refresh token.', 'igbz-suite' ),
			'revoked'  => __( 'This session was revoked. Please sign in again.', 'igbz-suite' ),
			'user_gone' => __( 'The account behind this token no longer exists.', 'igbz-suite' ),
			default    => __( 'The access token is not valid.', 'igbz-suite' ),
		};
	}

	/**
	 * Two independent buckets: one per token (one loud device cannot spend another session's
	 * budget) and one per tenant per minute (noisy neighbour — a store that spins up many
	 * devices still cannot drown the shared infrastructure; every other store keeps its own
	 * budget). Both keys carry the minute and the tenant so they can never collide across
	 * stores or across time.
	 */
	private function within_rate_limit( string $jti, int $tenant_id ): bool {
		$minute = gmdate( 'YmdHi' );

		$max = igbz()->settings()->int( 'api.rate_limit_per_minute', 120 );
		if ( $max > 0 ) {
			$key  = 'igbz_api_rl_' . $tenant_id . '_' . md5( $jti . '|' . $minute );
			$hits = (int) get_transient( $key );
			if ( $hits >= $max ) {
				return false;
			}
			set_transient( $key, $hits + 1, MINUTE_IN_SECONDS + 5 );
		}

		$tenant_max = igbz()->settings()->int( 'api.tenant_rate_limit_per_minute', 600 );
		if ( $tenant_max > 0 && $tenant_id > 0 ) {
			$key  = 'igbz_api_rl_tenant_' . $tenant_id . '_' . $minute;
			$hits = (int) get_transient( $key );
			if ( $hits >= $tenant_max ) {
				return false;
			}
			set_transient( $key, $hits + 1, MINUTE_IN_SECONDS + 5 );
		}

		return true;
	}
}
