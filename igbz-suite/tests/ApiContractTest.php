<?php
/**
 * Phase 65 — the API contract: the OpenAPI document generated from registered
 * routes (paths, parameters from args and URL shapes, auth levels), the shared
 * error taxonomy, the runtime deprecation/sunset headers, and the compatibility
 * gate that names every breaking change and lets additive ones through free.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\RestApi\Contract\ApiContractService;

final class ApiContractTest extends TestCase {

	/** A miniature but honest route map in the exact shape WP's REST server reports. */
	private function routes(): array {
		return [
			'/igbz/v1'                                   => [ [ 'methods' => [ 'GET' ], 'permission_callback' => '__return_true' ] ],
			'/igbz/v1/products'                          => [
				[ 'methods' => [ 'GET' ], 'permission_callback' => '__return_true', 'args' => [
					'page'    => [ 'type' => 'integer', 'required' => false, 'description' => 'صفحه' ],
					'kind'    => [ 'type' => 'string', 'required' => false, 'enum' => [ 'post', 'reel', 'story' ] ],
				] ],
			],
			'/igbz/v1/products/(?P<id>\d+)'              => [
				[ 'methods' => [ 'GET', 'POST' ], 'permission_callback' => 'is_logged_in' ],
			],
			'/igbz/v1/notifications/send'                => [
				[ 'methods' => [ 'POST' ], 'permission_callback' => 'can_manage_tenant' ],
			],
			'/igbz/v1/platform/stats'                    => [
				[ 'methods' => [ 'GET' ], 'permission_callback' => 'can_manage_platform' ],
			],
			'/igbz/v1/contract'                          => [
				[ 'methods' => [ 'GET' ], 'permission_callback' => '__return_true' ],
			],
			'/wp/v2/posts'                               => [ // another namespace — must never leak in
				[ 'methods' => [ 'GET' ], 'permission_callback' => '__return_true' ],
			],
		];
	}

	public function run(): void {
		$this->the_document_is_valid_openapi_for_the_one_namespace();
		$this->declared_args_become_typed_parameters();
		$this->url_shapes_become_required_path_parameters();
		$this->auth_levels_become_security_and_never_leak();
		$this->the_error_taxonomy_is_pinned_and_consistent();
		$this->deprecation_headers_follow_the_policy();
		$this->the_gate_names_every_breaking_change();
		$this->additive_changes_pass_free();
		$this->the_published_baseline_is_pinned();
	}

	// ------------------------------------------------------------ scenarios

	private function the_document_is_valid_openapi_for_the_one_namespace(): void {
		$doc = ( new ApiContractService() )->document( $this->routes() );
		$this->assert_same( '3.1.0', (string) $doc['openapi'], 'OpenAPI 3.1' );
		$this->assert_same( ApiContractService::CONTRACT_VERSION, (string) $doc['info']['version'], 'the contract carries its own semver' );
		$this->assert_same( '/igbz/v1', (string) $doc['servers'][0]['url'], 'the server is the v1 namespace' );
		$this->assert_false( isset( $doc['paths']['/wp/v2/posts'] ), 'routes of other namespaces never leak into the contract' );
		$this->assert_true( isset( $doc['paths']['/products'] ), 'own routes are documented' );
		$this->assert_true( isset( $doc['components']['securitySchemes']['bearerAuth'] ), 'JWT bearer scheme declared' );
	}

	private function declared_args_become_typed_parameters(): void {
		$doc  = ( new ApiContractService() )->document( $this->routes() );
		$params = [];
		foreach ( $doc['paths']['/products']['GET']['parameters'] as $p ) { $params[ $p['name'] ] = $p; }
		$this->assert_same( 'integer', (string) $params['page']['schema']['type'], 'arg types map to JSON Schema types' , 'the invariant holds' );
		$this->assert_false( (bool) $params['page']['required'], 'optional args stay optional' , 'the invariant holds' );
		$this->assert_same( [ 'post', 'reel', 'story' ], $params['kind']['schema']['enum'], 'enum constraints survive into the contract' );
		$this->assert_same( 'صفحه', (string) $params['page']['description'], 'descriptions ride along' , 'the invariant holds' );
	}

	private function url_shapes_become_required_path_parameters(): void {
		$doc  = ( new ApiContractService() )->document( $this->routes() );
		$this->assert_true( isset( $doc['paths']['/products/{id}'] ), 'regex groups become {name} placeholders' );
		$param = null;
		foreach ( $doc['paths']['/products/{id}']['GET']['parameters'] as $p ) {
			if ( 'id' === $p['name'] && 'path' === $p['in'] ) { $param = $p; }
		}
		$this->assert_same( true, null !== $param && ! empty( $param['required'] ), 'the URL shape is part of the contract even without a declared arg' );
	}

	private function auth_levels_become_security_and_never_leak(): void {
		$doc = ( new ApiContractService() )->document( $this->routes() );
		$this->assert_same( [], $doc['paths']['/products']['GET']['security'], 'anonymous routes declare no security' , 'the invariant holds' );
		$this->assert_same( 'anonymous', (string) $doc['paths']['/products']['GET']['x-auth'], 'the auth level is explicit for the gate' );
		$this->assert_same( [ [ 'bearerAuth' => [] ] ], $doc['paths']['/products/{id}']['GET']['security'], 'gated routes require the bearer token' , 'the invariant holds' );
	}

	private function the_error_taxonomy_is_pinned_and_consistent(): void {
		$doc = ( new ApiContractService() )->document( $this->routes() );
		$taxonomy = $doc['x-error-taxonomy'];
		$this->assert_same( ApiContractService::ERROR_TAXONOMY, $taxonomy, 'the document carries the shared error taxonomy' );
		foreach ( $taxonomy as $code => $status ) {
			$this->assert_true( $status >= 400 && $status <= 599, 'کد خطا ' . $code . ' به وضعیت HTTP معتبر نگاشت شده' );
		}
		foreach ( array_keys( $taxonomy ) as $code ) {
			$key = [ 'igbz_unauthorized' => 'Unauthorized', 'igbz_forbidden' => 'Forbidden' ][ $code ] ?? null;
			if ( null !== $key ) {
				$this->assert_same( $code, (string) $doc['components']['responses'][ $key ]['x-error-code'], 'پاسخ ' . $key . ' به کد اصلی گره خورده' );
			}
		}
		$error_schema = $doc['components']['schemas']['Error'];
		$this->assert_same( array_keys( $taxonomy ), $error_schema['properties']['code']['enum'], 'the Error schema only promises the pinned codes' );

		// The permission gate answers 401/403 in WP's own shape before any controller
		// runs — the contract documents both envelopes instead of pretending.
		$this->assert_true( isset( $doc['components']['schemas']['PermissionError'] ), 'the WP permission-error envelope is documented' );
		$unauthorized = $doc['components']['responses']['Unauthorized']['content']['application/json']['schema'];
		$this->assert_same( [ '#/components/schemas/PermissionError', '#/components/schemas/Error' ], array_column( $unauthorized['oneOf'], '$ref' ), '401 promises exactly the two real envelopes' );
		$bad_request = $doc['components']['responses']['BadRequest']['content']['application/json']['schema'];
		$this->assert_same( '#/components/schemas/Error', (string) $bad_request['$ref'], '400 is always the controller envelope' );
	}

	private function deprecation_headers_follow_the_policy(): void {
		$today = new ApiContractService();
		$this->assert_same( [], $today->deprecation_headers( '/products', 'GET' ), 'nothing is deprecated today — no headers' );
		$this->assert_same( [], $today->deprecation_headers( '/legacy', 'GET' ), 'undeclared routes emit nothing — the machinery is data-driven' );

		// a published deprecation (through the registry seam) is runtime-visible
		$svc = new ApiContractService( 'igbz/v1', [ 'igbz/v1:/legacy|GET' => [ 'sunset' => '2027-03-21T00:00:00+00:00', 'successor' => '/catalog/items' ] ] );
		$headers = $svc->deprecation_headers( '/legacy', 'GET' );
		$this->assert_same( 'true', (string) ( $headers['Deprecation'] ?? '' ), 'سرصفحهٔ Deprecation مطابق پیش‌نویس RFC' );
		$this->assert_true( (bool) preg_match( '/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT$/', (string) ( $headers['Sunset'] ?? '' ) ), 'Sunset مطابق RFC 8594 با تاریخ کامل' );
		$this->assert_same( '</igbz/v1/catalog/items>; rel="successor-version"', (string) ( $headers['Link'] ?? '' ), 'لینک جایگزین قابل خواندن توسط ماشین' );
		$this->assert_same( [], $svc->deprecation_headers( '/legacy', 'POST' ), 'فقط همان متدِ اعلام‌شده' );

		// and the contract document marks it too
		$doc = $svc->document( $this->routes() + [ '/igbz/v1/legacy' => [ [ 'methods' => [ 'GET' ], 'permission_callback' => '__return_true' ] ] ] );
		$this->assert_true( ! empty( $doc['paths']['/legacy']['GET']['deprecated'] ), 'عملیات منسوخ در قرارداد هم deprecated است' );
		$this->assert_same( '2027-03-21T00:00:00+00:00', (string) $doc['paths']['/legacy']['GET']['x-sunset'], 'با تاریخ انقضا' );
	}

	private function the_gate_names_every_breaking_change(): void {
		$svc = new ApiContractService();
		$old = $svc->document( $this->routes() );

		// removed path
		$mutated = $this->with_paths( $old, function ( array &$paths ): void { unset( $paths['/platform/stats'] ); } );
		$this->assert_same( [ 'removed path /platform/stats' ], ApiContractService::breaking_changes( $old, $mutated ), 'حذف مسیر = شکست' );

		// removed method
		$mutated = $this->with_paths( $old, function ( array &$paths ): void { unset( $paths['/products/{id}']['POST'] ); } );
		$this->assert_same( [ 'removed POST /products/{id}' ], ApiContractService::breaking_changes( $old, $mutated ), 'حذف متد = شکست' );

		// removed parameter
		$mutated = $this->with_paths( $old, function ( array &$paths ): void {
			$paths['/products']['GET']['parameters'] = array_values( array_filter( $paths['/products']['GET']['parameters'], fn ( array $p ): bool => 'kind' !== $p['name'] ) );
		} );
		$this->assert_same( [ 'removed parameter kind on GET /products' ], ApiContractService::breaking_changes( $old, $mutated ), 'حذف پارامتر = شکست' );

		// newly-required parameter
		$mutated = $this->with_paths( $old, function ( array &$paths ): void {
			foreach ( $paths['/products']['GET']['parameters'] as &$p ) { if ( 'page' === $p['name'] ) { $p['required'] = true; } }
		} );
		$this->assert_same( [ 'parameter page became required on GET /products' ], ApiContractService::breaking_changes( $old, $mutated ), 'اجباری‌شدن پارامتر = شکست' );

		// type change
		$mutated = $this->with_paths( $old, function ( array &$paths ): void {
			foreach ( $paths['/products']['GET']['parameters'] as &$p ) { if ( 'page' === $p['name'] ) { $p['schema']['type'] = 'string'; } }
		} );
		$this->assert_same( [ 'parameter page changed type on GET /products' ], ApiContractService::breaking_changes( $old, $mutated ), 'تغییر نوع = شکست' );

		// enum contraction
		$mutated = $this->with_paths( $old, function ( array &$paths ): void {
			foreach ( $paths['/products']['GET']['parameters'] as &$p ) { if ( 'kind' === $p['name'] ) { $p['schema']['enum'] = [ 'post' ]; } }
		} );
		$this->assert_same( [ 'parameter kind lost enum values on GET /products' ], ApiContractService::breaking_changes( $old, $mutated ), 'کوچک‌شدن enum = شکست (کلاینت تولیدی می‌شکند)' );

		// strengthened auth
		$mutated = $this->with_paths( $old, function ( array &$paths ): void {
			$paths['/products']['GET']['x-auth'] = 'user';
			$paths['/products']['GET']['security'] = [ [ 'bearerAuth' => [] ] ];
		} );
		$this->assert_same( [ 'auth strengthened on GET /products' ], ApiContractService::breaking_changes( $old, $mutated ), 'سخت‌شدن مجوز = شکست (کلاینت ناشناس می‌شکند)' );

		// version regression
		$mutated = $old;
		$mutated['info']['version'] = '0.9.0';
		$this->assert_contains( 'contract version regressed', implode( "\n", ApiContractService::breaking_changes( $old, $mutated ) ), 'نسخهٔ قرارداد هرگز عقب نمی‌رود' );

		// un-deprecating without a new major
		$deprecated_old = $this->with_paths( $old, function ( array &$paths ): void {
			$paths['/legacy']['GET'] = $paths['/products']['GET'];
			$paths['/legacy']['GET']['deprecated'] = true;
			$paths['/legacy']['GET']['x-sunset'] = '2027-01-01';
		} );
		$mutated = $this->with_paths( $deprecated_old, function ( array &$paths ): void {
			unset( $paths['/legacy']['GET']['deprecated'], $paths['/legacy']['GET']['x-sunset'] );
		} );
		$this->assert_contains( 'un-deprecated GET /legacy without a new major', implode( "\n", ApiContractService::breaking_changes( $deprecated_old, $mutated ) ), 'برداشتن deprecation هم شکست است' );
	}

	private function additive_changes_pass_free(): void {
		$svc = new ApiContractService();
		$old = $svc->document( $this->routes() );

		$grown = $this->with_paths( $old, function ( array &$paths ): void {
			// a brand-new path
			$paths['/catalog/items']['GET'] = $paths['/products']['GET'];
			// a new OPTIONAL parameter on an existing operation
			$paths['/products']['GET']['parameters'][] = [ 'name' => 'sort', 'in' => 'query', 'required' => false, 'schema' => [ 'type' => 'string' ] ];
			// an enum that only grew
			foreach ( $paths['/products']['GET']['parameters'] as &$p ) { if ( 'kind' === $p['name'] ) { $p['schema']['enum'][] = 'live'; } }
		} );
		$this->assert_same( [], ApiContractService::breaking_changes( $old, $grown ), 'مسیر جدید، پارامتر اختیاری جدید و رشد enum = آزاد و افزودنی' );
	}

	private function the_published_baseline_is_pinned(): void {
		$path = __DIR__ . '/../contracts/api-v1.json';
		$this->assert_true( is_readable( $path ), 'قرارداد منتشرشده در مخزن پین شده است' , 'the invariant holds' );
		$baseline = json_decode( (string) file_get_contents( $path ), true );
		$this->assert_true( is_array( $baseline ), 'the baseline is valid JSON' , 'the invariant holds' );
		$this->assert_same( '3.1.0', (string) ( $baseline['openapi'] ?? '' ), 'the baseline is an OpenAPI 3.1 document' );
		$this->assert_same( ApiContractService::CONTRACT_VERSION, (string) ( $baseline['info']['version'] ?? '' ), 'the baseline version matches the code' );
		foreach ( array_keys( $baseline['paths'] ?? [] ) as $p ) {
			$this->assert_true( str_starts_with( (string) $p, '/' ), 'every path is rooted' , 'the invariant holds' );
		}
		$this->assert_true( isset( $baseline['paths']['/contract'] ), 'قرارداد خودش را مستند می‌کند' );
	}

	// -------------------------------------------------------------- helpers

	/** Deep-copy the document, mutate its paths, return the copy. */
	private function with_paths( array $doc, callable $mutate ): array {
		$copy = json_decode( (string) wp_json_encode( $doc ), true );
		$mutate( $copy['paths'] );
		return $copy;
	}
}
