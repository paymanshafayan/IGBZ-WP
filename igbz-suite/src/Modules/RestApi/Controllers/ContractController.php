<?php
namespace IGBZ\Suite\Modules\RestApi\Controllers;

use IGBZ\Suite\Modules\RestApi\Contract\ApiContractService;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 65 — the contract endpoint: `GET /igbz/v1/contract` serves the OpenAPI
 * document generated from the routes this server has actually registered, so
 * the published contract can never drift silently from the implementation.
 *
 * Anonymous by design (it is public documentation), GET-only, and it carries
 * the runtime deprecation headers through the standard rest_pre_serve_request
 * filter the module installs.
 */
final class ContractController extends BaseController {

	public function __construct( private ApiContractService $contract ) {}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/contract', $this->route( 'GET', [ $this, 'serve' ] ) );
	}

	public function serve(): \WP_REST_Response {
		if ( ! function_exists( 'rest_get_server' ) ) {
			return $this->fail( 'igbz_server_error', __( 'The REST server is not available.', 'igbz-suite' ), 500 );
		}

		$server  = rest_get_server();
		$routes  = is_callable( [ $server, 'get_routes' ] ) ? $server->get_routes() : [];
		$doc     = $this->contract->document( is_array( $routes ) ? $routes : [] );

		// The contract documents itself: this very operation appears in the paths.
		$response = $this->ok( $doc );
		$response->header( 'X-Contract-Version', ApiContractService::CONTRACT_VERSION );
		$response->header( 'Cache-Control', 'public, max-age=3600' );
		return $response;
	}
}
