<?php
namespace IGBZ\Suite\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Closes the data doors WordPress and WooCommerce open by default.
 *
 * WHY THIS EXISTS
 * ---------------
 * `Authenticator` only engages when a request carries a Bearer token; without one it returns
 * early and every core route runs unguarded. That is correct for its own job — it authenticates
 * app users — but it means a request arriving with a WordPress session cookie or an Application
 * Password reaches `wc/v3/customers` (email, phone, address for every buyer) with no rate limit,
 * no signature, and no audit trail.
 *
 * At the scale this platform targets — hundreds of stores, thousands of customers each — a single
 * leaked store-admin account could export an entire customer base in one request. That is the gap
 * this class closes.
 *
 * DESIGN: ALLOW-LIST, NOT BLOCK-LIST
 * ----------------------------------
 * Enumerating back doors and shutting them one by one is a losing game: the next plugin opens a
 * new one. Bulk-collection routes are therefore denied by default and must be explicitly
 * permitted.
 *
 * WHAT THIS CLASS DOES NOT DO
 * ---------------------------
 * It does not authenticate — `Authenticator` owns that. It does not decide who a user is, only
 * whether an already-identified user may pull people's data in bulk. Single-record reads are
 * untouched: blocking those would break the admin screens we ship.
 *
 * Reference: OWASP API Security Top 10 (2023) API3 (Broken Object Property Level Authorization)
 * and API6 (Unrestricted Access to Sensitive Business Flows). Summaries under
 * `امنیت و مراقبت/منابع/OWASP/`.
 */
final class CoreSurfaceGuard {

	/**
	 * Core and WooCommerce routes that hand back people's data in bulk.
	 *
	 * Matched as a prefix against the REST route, so `/wc/v3/customers` also covers
	 * `/wc/v3/customers/batch`. Single-record reads such as `/wc/v3/customers/42` are matched too
	 * and then released by `is_single_record()` below — see that method for why.
	 *
	 * @var string[]
	 */
	private const BULK_PEOPLE_ROUTES = [
		'/wp/v2/users',
		'/wc/v3/customers',
		'/wc/v3/orders',
		'/wc/v2/customers',
		'/wc/v2/orders',
		'/wc-analytics/customers',
		'/wc-analytics/orders',
	];

	public function __construct( private Logger $logger ) {}

	public function register(): void {
		// rest_pre_dispatch fires for EVERY route including core and WooCommerce, which is the
		// whole point. rest_authentication_errors would not do: it is skipped when no Bearer
		// token is present, and that is exactly the case we are defending against.
		add_filter( 'rest_pre_dispatch', [ $this, 'guard_rest' ], 10, 3 );

		// XML-RPC predates the REST API and carries its own auth. It is a standing brute-force
		// and amplification vector and nothing in this plugin uses it.
		if ( igbz()->settings()->bool( 'security.disable_xmlrpc', true ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'xmlrpc_methods', '__return_empty_array' );
		}

		// Application Passwords are a parallel authentication path built into core. They bypass
		// our token service entirely, so a leaked one would sidestep every control we add.
		if ( igbz()->settings()->bool( 'security.disable_app_passwords', true ) ) {
			add_filter( 'wp_is_application_passwords_available', '__return_false' );
		}

		// User enumeration needs no REST call at all: /?author=1 redirects to the author archive
		// and leaks the login name. Same for author feeds and oEmbed.
		if ( igbz()->settings()->bool( 'security.block_user_enumeration', true ) ) {
			add_action( 'template_redirect', [ $this, 'block_author_enumeration' ], 1 );
			add_filter( 'oembed_response_data', [ $this, 'strip_oembed_author' ] );
		}

		// WordPress XML exports can contain users, comments and site content. Keep this
		// bulk escape hatch for the platform administrator only.
		add_action( 'load-export.php', [ $this, 'guard_export' ], 1 );
	}

	/**
	 * The gate itself.
	 *
	 * @param mixed            $result  Short-circuit value; non-null stops dispatch.
	 * @param \WP_REST_Server  $server  Unused, part of the filter contract.
	 * @param \WP_REST_Request $request The incoming request.
	 * @return mixed
	 */
	public function guard_rest( $result, $server, $request ) {
		// Someone else already refused it. Do not second-guess them.
		if ( null !== $result ) {
			return $result;
		}

		$route = (string) $request->get_route();

		if ( ! $this->is_bulk_people_route( $route ) ) {
			return $result;
		}

		// A request for one record is not bulk collection. Our own admin screens read single
		// customers and orders constantly; refusing those would break the product to no security
		// gain, since the caller already had to know the ID.
		if ( $this->is_single_record( $route ) ) {
			return $result;
		}

		if ( $this->is_permitted( $request ) ) {
			return $result;
		}

		// Log before refusing: a refusal nobody can see is a refusal nobody can investigate.
		// This is the event class that feeds Vira's security layer.
		$this->logger->warning(
			'security',
			'Bulk people-data request refused',
			[
				'route'   => $route,
				'user_id' => get_current_user_id(),
				'method'  => (string) $request->get_method(),
			]
		);

		return new \WP_Error(
			'igbz_bulk_access_denied',
			__( 'Bulk access to customer data is not available on this route.', 'igbz-suite' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Is this one of the routes that returns people's data in bulk?
	 */
	private function is_bulk_people_route( string $route ): bool {
		foreach ( self::BULK_PEOPLE_ROUTES as $prefix ) {
			if ( 0 === strpos( $route, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * True when the route addresses exactly one record, e.g. `/wc/v3/customers/42`.
	 *
	 * `/batch` is deliberately excluded: it is bulk wearing a single-record shape.
	 */
	private function is_single_record( string $route ): bool {
		if ( str_ends_with( $route, '/batch' ) ) {
			return false;
		}
		return 1 === preg_match( '#/\d+$#', $route );
	}

	/**
	 * Who may still collect in bulk.
	 *
	 * Deliberately narrow. The signed-request path described in DESIGN-LEGAL-AUTH.md §۷.۶
	 * (biometric + device + app key) is not built yet — the admin app that produces those
	 * signatures is deferred until the backend settles. Until it exists, only the network's own
	 * super admin gets through, and that is a conscious, documented restriction rather than an
	 * oversight.
	 */
	private function is_permitted( \WP_REST_Request $request ): bool {
		/**
		 * Escape hatch for integrations we explicitly trust.
		 *
		 * @param bool             $allowed Whether to permit this bulk request.
		 * @param \WP_REST_Request $request The request being judged.
		 */
		if ( (bool) apply_filters( 'igbz_allow_bulk_people_access', false, $request ) ) {
			return true;
		}

		return is_super_admin() || current_user_can( 'igbz_bulk_export_people' );
	}

	/** Refuse the core XML export screen to non-platform administrators. */
	public function guard_export(): void {
		if ( ! is_super_admin() ) {
			wp_die( esc_html__( 'Site export is restricted to the platform administrator.', 'igbz-suite' ), 403 );
		}
	}

	/**
	 * Stops `/?author=N` from confirming which user IDs exist and leaking their login names.
	 */
	public function block_author_enumeration(): void {
		if ( is_admin() || is_user_logged_in() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only probe check.
		if ( ! isset( $_GET['author'] ) ) {
			return;
		}

		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}

	/**
	 * oEmbed answers "who wrote this?" to anyone who asks. Nothing we ship needs that.
	 *
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	public function strip_oembed_author( $data ) {
		if ( is_array( $data ) ) {
			unset( $data['author_name'], $data['author_url'] );
		}
		return $data;
	}
}
