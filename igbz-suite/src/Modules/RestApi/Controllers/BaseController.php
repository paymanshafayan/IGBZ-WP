<?php
namespace IGBZ\Suite\Modules\RestApi\Controllers;

use IGBZ\Suite\Modules\RestApi\Idempotency\IdempotencyService;
use IGBZ\Suite\Modules\RestApi\Pagination\CursorCodec;
use IGBZ\Suite\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Shared plumbing for every mobile API controller: the namespace, permission callbacks and
 * response helpers.
 *
 * Port note: the nop base class (`AuthorizedTenantOwnerApiController`) was referenced by five
 * controllers but never existed, so the whole admin API failed to compile. It also delegated its
 * tenant scoping to a filter that ran elsewhere. Here the check is in one place and every
 * privileged route calls it.
 */
abstract class BaseController {

	public const NAMESPACE = 'igbz/v1';

	abstract public function register_routes(): void;

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	// ------------------------------------------------------------ routing

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	protected function route( string $methods, callable $callback, ?callable $permission = null, array $args = [] ): array {
		return [
			'methods'             => $methods,
			'callback'            => $callback,
			'permission_callback' => $permission ?? '__return_true',
			'args'                => $args,
		];
	}

	// -------------------------------------------------------- permissions

	public function is_logged_in(): bool|\WP_Error {
		if ( get_current_user_id() > 0 ) {
			return true;
		}
		return new \WP_Error( 'igbz_unauthorized', __( 'Authentication is required.', 'igbz-suite' ), [ 'status' => 401 ] );
	}

	public function can_manage_tenant(): bool|\WP_Error {
		$logged_in = $this->is_logged_in();
		if ( is_wp_error( $logged_in ) ) {
			return $logged_in;
		}

		if ( Capabilities::current_user_can( Capabilities::MANAGE_OWN_TENANT )
			|| Capabilities::current_user_can( Capabilities::MANAGE_TENANTS ) ) {
			return true;
		}

		return new \WP_Error( 'igbz_forbidden', __( 'This endpoint is limited to store owners.', 'igbz-suite' ), [ 'status' => 403 ] );
	}

	public function can_manage_platform(): bool|\WP_Error {
		$logged_in = $this->is_logged_in();
		if ( is_wp_error( $logged_in ) ) {
			return $logged_in;
		}
		if ( Capabilities::current_user_can( Capabilities::MANAGE_TENANTS ) ) {
			return true;
		}
		return new \WP_Error( 'igbz_forbidden', __( 'Super admin only.', 'igbz-suite' ), [ 'status' => 403 ] );
	}

	// ------------------------------------------------------------ context

	/**
	 * The tenant this request is allowed to act on. A store owner is pinned to their own tenant
	 * even if the client asks for a different id; a platform admin may pass ?tenant_id=.
	 */
	protected function scoped_tenant_id( ?\WP_REST_Request $request = null ): int {
		$tenancy = igbz()->tenancy();

		if ( Capabilities::current_user_can( Capabilities::MANAGE_TENANTS ) && $request ) {
			$requested = (int) $request->get_param( 'tenant_id' );
			if ( $requested > 0 ) {
				return $requested;
			}
		}

		$current = $tenancy->id();
		if ( $current > 0 && $tenancy->user_can_access( $current ) ) {
			return $current;
		}

		$accessible = $tenancy->accessible_tenant_ids();

		return $accessible ? (int) $accessible[0] : 0;
	}

	// ---------------------------------------------------------- responses

	/** @param mixed $data */
	protected function ok( $data, int $status = 200 ): \WP_REST_Response {
		return new \WP_REST_Response( $data, $status );
	}

	protected function fail( string $code, string $message, int $status = 400 ): \WP_REST_Response {
		return new \WP_REST_Response( [ 'ok' => false, 'code' => $code, 'error' => $message ], $status );
	}

	/**
	 * @param array<int,mixed> $items
	 * @return \WP_REST_Response
	 */
	protected function paged( array $items, int $total, int $page, int $per_page ): \WP_REST_Response {
		$response = new \WP_REST_Response(
			[
				'items'       => $items,
				'total'       => $total,
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / max( 1, $per_page ) ),
			]
		);
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) ceil( $total / max( 1, $per_page ) ) );

		return $response;
	}

	protected function page_args( \WP_REST_Request $request, int $default_per_page = 20 ): array {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? min( 100, $per_page ) : $default_per_page;

		return [ $page, $per_page, ( $page - 1 ) * $per_page ];
	}

	// ------------------------------------------------- phase 67: cursor pages

	/**
	 * Route args shared by every cursor-capable feed, so the published contract
	 * documents them per-operation (additive: both optional).
	 *
	 * @return array<string,mixed>
	 */
	protected function cursor_args(): array {
		return [
			'cursor' => [
				'type'              => 'string',
				'required'          => false,
				'description'       => __( 'Opaque cursor from the previous page (keyset pagination; stable under inserts).', 'igbz-suite' ),
				'validate_callback' => static fn ( $v ) => is_string( $v ) && strlen( $v ) <= 512,
			],
			'limit'  => [
				'type'              => 'integer',
				'required'          => false,
				'minimum'           => 1,
				'maximum'           => 100,
				'description'       => __( 'Page size for cursor pagination (1–100).', 'igbz-suite' ),
			],
		];
	}

	/**
	 * Resolve cursor mode for a feed. Legacy requests (page/per_page, or neither) answer
	 * in the v1 page envelope; a `cursor` OR an explicit `limit` switches the feed to the
	 * cursor envelope — the first page of a cursor walk is `?limit=N` with no cursor, and
	 * it must speak the same shape as every later page or the client could not continue.
	 *
	 * Returns null (legacy page mode), an array position (cursor mode; empty = first
	 * page, no keyset filter), or a 400 response for a malformed/foreign cursor — a
	 * corrupted bookmark must fail loudly, never feed the client a wrong slice.
	 *
	 * @return array<string,int|string>|\WP_REST_Response|null
	 */
	protected function cursor_position( \WP_REST_Request $request, string $kind ) {
		$raw   = trim( (string) $request->get_param( 'cursor' ) );
		$limit = (int) $request->get_param( 'limit' );
		if ( '' === $raw && $limit <= 0 ) {
			return null;
		}
		if ( '' === $raw ) {
			return [];
		}
		$position = CursorCodec::decode( $raw, $kind );
		if ( null === $position ) {
			return $this->fail( 'igbz_validation', __( 'The cursor is malformed or foreign to this endpoint.', 'igbz-suite' ), 400 );
		}

		return $position;
	}

	/** Page size in cursor mode: `limit` if given, else per_page, clamped 1–100. */
	protected function cursor_limit( \WP_REST_Request $request, int $default ): int {
		$limit = (int) $request->get_param( 'limit' );
		if ( $limit <= 0 ) {
			$limit = (int) $request->get_param( 'per_page' );
		}

		return max( 1, min( 100, $limit > 0 ? $limit : $default ) );
	}

	/**
	 * Assemble a cursor page from a fetched batch that may hold one extra row: the extra
	 * row (count > limit) is the honest `has_more` signal, and each row carries its own
	 * cursor tuple — derived by the caller from the row, never constructed by the client.
	 *
	 * @param array<int,array{item:mixed,cursor:array<string,int|string>}> $batch
	 * @param array<string,mixed>                                          $extra
	 */
	protected function cursor_page( array $batch, int $limit, string $kind, array $extra = [] ): \WP_REST_Response {
		$has_more = count( $batch ) > $limit;
		$rows     = array_slice( $batch, 0, $limit );
		$last     = $rows ? $rows[ count( $rows ) - 1 ] : null;

		$response = $this->ok( array_merge(
			$extra,
			[
				'items'       => array_map( static fn ( array $row ) => $row['item'], $rows ),
				'has_more'    => $has_more,
				'next_cursor' => ( $has_more && null !== $last ) ? CursorCodec::encode( $kind, $last['cursor'] ) : null,
			]
		) );
		$response->header( 'X-IGBZ-Has-More', $has_more ? '1' : '0' );

		return $response;
	}

	// ---------------------------------------------- phase 67: idempotent writes

	/**
	 * Run a write behind an `Idempotency-Key`. Without the header the handler runs
	 * untouched (v1 back-compat); with it, exactly one outcome per key is guaranteed:
	 * replays return the stored response verbatim (plus Idempotency-Replayed: true), a
	 * concurrent first attempt answers 409 busy, and reusing a key for a different
	 * request answers 409.
	 *
	 * @param callable():\WP_REST_Response $handler
	 */
	protected function with_idempotency( \WP_REST_Request $request, callable $handler ): \WP_REST_Response {
		$key = trim( (string) $request->get_header( 'Idempotency-Key' ) );
		if ( '' === $key ) {
			return $handler();
		}

		/** @var IdempotencyService $service */
		$service = igbz()->get( 'api.idempotency' );

		$body = (array) $request->get_json_params();
		if ( ! $body ) {
			// Form-encoded or empty bodies still fingerprint canonically (empty tuple).
			$body = array_filter( (array) $request->get_body_params(), 'is_scalar' );
		}

		$claim = $service->claim( get_current_user_id(), $key, $request->get_method(), (string) $request->get_route(), $body );

		switch ( $claim['status'] ) {
			case 'invalid_key':
				return $this->fail( 'igbz_validation', __( 'The Idempotency-Key must be 8–191 characters of a client-generated token (a UUID).', 'igbz-suite' ), 400 );

			case 'replay':
				$response = new \WP_REST_Response( $claim['body'], (int) $claim['code'] );
				$response->header( 'Idempotency-Replayed', 'true' );
				return $response;

			case 'busy':
				return $this->fail( 'igbz_conflict', __( 'A request with this Idempotency-Key is still in flight; retry shortly with the same key.', 'igbz-suite' ), 409 );

			case 'conflict':
				return $this->fail( 'igbz_conflict', __( 'This Idempotency-Key was already used for a different request.', 'igbz-suite' ), 409 );
		}

		$response = $handler();
		$service->complete( (int) $claim['id'], $response->get_status(), $response->get_data() );

		return $response;
	}
}
