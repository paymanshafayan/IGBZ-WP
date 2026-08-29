<?php
namespace IGBZ\Suite\Modules\RestApi\Contract;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 65 — the API contract: one OpenAPI 3.1 document generated from the
 * routes the plugin actually registers, the shared error taxonomy, and the
 * compatibility policy that governs how `igbz/v1` may evolve.
 *
 * The policy (mirrored from the phase research):
 *
 *  - MAJOR IN THE URL ONLY. `igbz/v1` evolves additively; a real break (removed
 *    path/method/parameter, newly-required parameter, parameter type change,
 *    strengthened auth) opens `igbz/v2`, it is never patched into v1.
 *  - ADDITIVE CHANGES ARE FREE: new paths, new optional parameters, new
 *    optional response fields, weakened auth — all allowed in place, and they
 *    bump the contract's minor version.
 *  - DEPRECATION IS RUNTIME-VISIBLE, not just documentation: a deprecated
 *    operation carries `deprecated: true` plus `x-sunset` in the contract, and
 *    its live responses carry the `Deprecation`, `Sunset` (RFC 8594) and
 *    `Link: rel="successor-version"` headers.
 *  - THE BASELINE IS PINNED: `igbz-suite/contracts/api-v1.json` is the
 *    published contract. The diff gate (`breaking_changes()`) compares any new
 *    document against it and names every violation — the CI-facing half of the
 *    policy, so a breaking change fails the build instead of a client.
 */
class ApiContractService {

	public const NAMESPACE_V1   = 'igbz/v1';
	public const CONTRACT_VERSION = '1.0.0';
	public const OPENAPI_VERSION = '3.1.0';

	/** Auth levels, weakest to strongest. Strengthening = breaking. */
	public const AUTH_ANONYMOUS = 'anonymous';
	public const AUTH_USER      = 'user';
	public const AUTH_TENANT    = 'tenant';
	public const AUTH_PLATFORM  = 'platform';
	public const AUTH_STRENGTH  = [ self::AUTH_ANONYMOUS => 0, self::AUTH_USER => 1, self::AUTH_TENANT => 2, self::AUTH_PLATFORM => 3 ];

	/**
	 * The shared error taxonomy — every controller answers failures through
	 * BaseController::fail(), so these are the codes the contract promises.
	 *
	 * @var array<string,int> code => HTTP status
	 */
	public const ERROR_TAXONOMY = [
		'igbz_unauthorized' => 401,
		'igbz_forbidden'    => 403,
		'igbz_validation'   => 400,
		'igbz_not_found'    => 404,
		'igbz_conflict'     => 409,
		'igbz_rate_limited' => 429,
		'igbz_server_error' => 500,
	];

	/**
	 * Deprecated operations, keyed "<namespace>:<route>|<METHOD>". Nothing is
	 * deprecated today; the machinery ships in v1.0.0 so the first real
	 * deprecation is a data change, not a code change.
	 *
	 * @var array<string,array{sunset:string,successor:string}>
	 */
	public const DEPRECATIONS = [];

	/** @var array<string,array{sunset:string,successor:string}> */
	private array $deprecations;

	/**
	 * @param array<string,array{sunset:string,successor:string}>|null $deprecations override the registry (test seam)
	 */
	public function __construct( private string $namespace = self::NAMESPACE_V1, ?array $deprecations = null ) {
		$this->deprecations = $deprecations ?? self::DEPRECATIONS;
	}

	/**
	 * The full OpenAPI document from the routes a WP REST server has
	 * registered (the production path — `rest_get_server()->get_routes()`),
	 * normalized through the same shape the tests feed in directly.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $routes raw WP route map: regex => handler defs
	 * @return array<string,mixed>
	 */
	public function document( array $routes ): array {
		$paths = [];
		foreach ( $routes as $pattern => $handlers ) {
			$route = $this->canonical_path( (string) $pattern );
			if ( '' === $route ) {
				continue; // another namespace's route (or the namespace index)
			}
			foreach ( (array) $handlers as $handler ) {
				if ( ! is_array( $handler ) || empty( $handler['methods'] ) ) {
					continue;
				}
				foreach ( (array) $handler['methods'] as $method ) {
					$method = strtoupper( (string) $method );
					if ( 'PATCH' === $method ) { continue; }
					$paths[ $route ][ $method ] = $this->operation( $route, $method, $pattern, is_array( $handler ) ? $handler : [] );
				}
			}
		}
		ksort( $paths );

		return [
			'openapi'    => self::OPENAPI_VERSION,
			'info'       => [
				'title'       => 'IGBZ Suite Mobile API',
				'version'     => self::CONTRACT_VERSION,
				'description' => 'قرارداد نسخهٔ یک API موبایل. سیاست سازگاری: تغییرات فقط افزودنی‌اند (مسیر/پارامتر اختیاری/فیلد پاسخ جدید، یا شل‌شدن مجوز)؛ هر شکست سازگاری (حذف مسیر/متد/پارامتر، اجباری‌شدن پارامتر، تغییر نوع، سخت‌شدن مجوز) یعنی گشودن igbz/v2 — هرگز وصله روی v1. عملیات‌های منسوخ با deprecated و x-sunset و در زمان اجرا با سرصفحه‌های Deprecation/Sunset/Link اعلام می‌شوند.',
			],
			'servers'    => [ [ 'url' => '/' . $this->namespace ] ],
			'security'   => [ [ 'bearerAuth' => [] ] ],
			'components' => [
				'securitySchemes' => [
					'bearerAuth' => [ 'type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT' ],
				],
				'schemas'         => [
					'Error'    => [
						'type'       => 'object',
						'required'   => [ 'ok', 'code', 'error' ],
						'properties' => [
							'ok'    => [ 'type' => 'boolean', 'enum' => [ false ] ],
							'code'  => [ 'type' => 'string', 'enum' => array_keys( self::ERROR_TAXONOMY ) ],
							'error' => [ 'type' => 'string' ],
						],
					],
					'Page'     => [
						'type'       => 'object',
						'required'   => [ 'items', 'total', 'page', 'per_page' ],
						'properties' => [
							'items'    => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
							'total'    => [ 'type' => 'integer' ],
							'page'     => [ 'type' => 'integer' ],
							'per_page' => [ 'type' => 'integer' ],
						],
					],
					// The permission gate answers with WP's own error shape before the
					// controller ever runs — an honest contract documents both envelopes.
					'PermissionError' => [
						'type'       => 'object',
						'required'   => [ 'code', 'message', 'data' ],
						'properties' => [
							'code'    => [ 'type' => 'string' ],
							'message' => [ 'type' => 'string' ],
							'data'    => [ 'type' => 'object', 'required' => [ 'status' ], 'properties' => [ 'status' => [ 'type' => 'integer' ] ] ],
						],
					],
				],
				'responses'       => $this->error_responses(),
			],
			'paths'      => $paths,
			'x-error-taxonomy' => self::ERROR_TAXONOMY,
		];
	}

	/** @return array<string,mixed> */
	private function operation( string $route, string $method, string $pattern, array $handler ): array {
		$auth = $this->auth_level( $handler );

		$op = [
			'operationId' => $this->operation_id( $route, $method ),
			'summary'     => (string) ( $handler['summary'] ?? $route ),
			'security'    => self::AUTH_ANONYMOUS === $auth ? [] : [ [ 'bearerAuth' => [] ] ],
			'x-auth'      => $auth,
			'responses'   => [
				'200' => [ 'description' => 'پاسخ موفق', 'content' => [ 'application/json' => [ 'schema' => [ 'type' => 'object' ] ] ] ],
				'401' => [ '$ref' => '#/components/responses/Unauthorized' ],
				'403' => [ '$ref' => '#/components/responses/Forbidden' ],
			],
		];

		// Parameters: declared args first, then path params from the route regex.
		foreach ( $this->parameters( $handler['args'] ?? [] ) as $param ) {
			$op['parameters'][] = $param;
		}
		foreach ( $this->path_parameters( $pattern ) as $param ) {
			if ( ! in_array( $param['name'], array_column( $op['parameters'] ?? [], 'name' ), true ) ) {
				$op['parameters'][] = $param;
			}
		}
		if ( in_array( $method, [ 'POST', 'PUT' ], true ) ) {
			$op['requestBody'] = [ 'required' => false, 'content' => [ 'application/json' => [ 'schema' => [ 'type' => 'object' ] ] ] ];
		}

		$deprecation = $this->deprecations[ $this->namespace . ':' . $route . '|' . $method ] ?? null;
		if ( null !== $deprecation ) {
			$op['deprecated'] = true;
			$op['x-sunset']   = $deprecation['sunset'];
			$op['description'] = 'این عملیات منسوخ است؛ جایگزین: ' . $deprecation['successor'];
			$op['responses']['200']['headers']['Deprecation'] = [ 'schema' => [ 'type' => 'string' ] ];
			$op['responses']['200']['headers']['Sunset']      = [ 'schema' => [ 'type' => 'string', 'format' => 'date-time' ] ];
		}

		return $op;
	}

	/**
	 * The auth level of a handler: derived from its permission callback when it
	 * is one of the BaseController's (the honest, declared surface), otherwise
	 * from the x-auth hint a route may carry.
	 */
	private function auth_level( array $handler ): string {
		$hint = (string) ( $handler['x-auth'] ?? '' );
		if ( isset( self::AUTH_STRENGTH[ $hint ] ) ) {
			return $hint;
		}
		$permission = $handler['permission_callback'] ?? null;
		if ( is_string( $permission ) && '__return_true' === $permission ) {
			return self::AUTH_ANONYMOUS;
		}
		// A declared permission callback we cannot name is at least user-gated.
		return self::AUTH_USER;
	}

	/**
	 * Declared args → JSON-schema parameters. WP ships `type`/`required`/
	 * `enum`/`description` on args; anything undeclared degrades honestly.
	 *
	 * @param array<string,mixed> $args
	 * @return array<int,array<string,mixed>>
	 */
	private function parameters( array $args ): array {
		$out = [];
		foreach ( $args as $name => $schema ) {
			if ( ! is_array( $schema ) ) { continue; }
			$param = [
				'name'        => (string) $name,
				'in'          => 'query',
				'required'    => ! empty( $schema['required'] ),
				'schema'      => [ 'type' => $this->json_type( (string) ( $schema['type'] ?? 'string' ) ) ],
			];
			if ( ! empty( $schema['enum'] ) ) {
				$param['schema']['enum'] = array_values( (array) $schema['enum'] );
			}
			if ( isset( $schema['description'] ) ) {
				$param['description'] = (string) $schema['description'];
			}
			$out[] = $param;
		}
		return $out;
	}

	/**
	 * Path parameters straight from the route regex: `(?P<id>\d+)` → a required
	 * integer path parameter named id. Every route's URL shape belongs to the
	 * contract even when no arg schema was declared.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function path_parameters( string $pattern ): array {
		$out = [];
		if ( ! preg_match_all( '/\(\?P<(\w+)>\\\\?d\+?\)/', $pattern, $matches ) ) {
			return $out;
		}
		foreach ( $matches[1] as $name ) {
			$out[] = [ 'name' => $name, 'in' => 'path', 'required' => true, 'schema' => [ 'type' => 'integer' ] ];
		}
		return $out;
	}

	/** `/namespace/path` with regex groups as `{name}` placeholders, '' when out of scope. */
	private function canonical_path( string $pattern ): string {
		$prefix = '/' . $this->namespace . '/';
		if ( ! str_starts_with( $pattern, $prefix ) ) {
			return ''; // another namespace's route (or the namespace index)
		}
		$route = rtrim( substr( $pattern, strlen( $prefix ) - 1 ), '/' ); // keep the leading slash
		// `(?P<id>\d+)` → `{id}`; any optional quantifier `?` around groups goes too.
		$route = (string) preg_replace( '/\(\?P<(\w+)>[^)]*\)(\?)?/', '{$1}', $route );
		return str_replace( '?', '', $route );
	}

	private function operation_id( string $route, string $method ): string {
		$slug = trim( str_replace( [ '/', '-', '{', '}' ], '_', $route ), '_' );
		return strtolower( $method ) . '_' . $slug;
	}

	private function json_type( string $wp_type ): string {
		return match ( $wp_type ) {
			'integer', 'int' => 'integer',
			'number', 'float' => 'number',
			'boolean', 'bool' => 'boolean',
			'array' => 'array',
			default => 'string',
		};
	}

	/** @return array<string,mixed> */
	private function error_responses(): array {
		$out = [];
		foreach ( self::ERROR_TAXONOMY as $code => $status ) {
			$key = match ( $status ) {
				400 => 'BadRequest', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'NotFound',
				409 => 'Conflict', 429 => 'RateLimited', 500 => 'ServerError', default => 'HTTP' . $status,
			};
			// 401/403 arrive from BOTH the permission gate (WP shape) and controller
			// envelopes — the contract promises either, nothing else.
			$schema = in_array( $status, [ 401, 403 ], true )
				? [ 'oneOf' => [ [ '$ref' => '#/components/schemas/PermissionError' ], [ '$ref' => '#/components/schemas/Error' ] ] ]
				: [ '$ref' => '#/components/schemas/Error' ];
			$out[ $key ] = [
				'description' => 'خطای ' . $code,
				'content'     => [ 'application/json' => [ 'schema' => $schema ] ],
				'x-error-code' => $code,
			];
		}
		return $out;
	}

	// ------------------------------------------------------------ sunset

	/**
	 * The runtime half of the deprecation policy: the headers a deprecated
	 * operation's responses must carry (RFC 8594 Sunset + successor link).
	 *
	 * @return array<string,string> header name => value
	 */
	public function deprecation_headers( string $route, string $method ): array {
		$entry = $this->deprecations[ $this->namespace . ':' . $route . '|' . strtoupper( $method ) ] ?? null;
		if ( null === $entry ) {
			return [];
		}
		return [
			'Deprecation' => 'true',
			'Sunset'      => gmdate( 'D, d M Y H:i:s', (int) strtotime( $entry['sunset'] ) ) . ' GMT',
			'Link'        => '</' . $this->namespace . $entry['successor'] . '>; rel="successor-version"',
		];
	}

	// -------------------------------------------------------- diff gate

	/**
	 * The compatibility gate: name every breaking difference between the
	 * published contract ($old) and the proposed one ($new). Additive changes
	 * return [] — they are free; anything in the list must open a new major.
	 *
	 * @param array<string,mixed> $old
	 * @param array<string,mixed> $new
	 * @return array<int,string>
	 */
	public static function breaking_changes( array $old, array $new ): array {
		$violations = [];
		$old_paths = $old['paths'] ?? [];
		$new_paths = $new['paths'] ?? [];

		foreach ( $old_paths as $path => $ops ) {
			if ( ! isset( $new_paths[ $path ] ) ) {
				$violations[] = "removed path {$path}";
				continue;
			}
			foreach ( $ops as $method => $op ) {
				if ( ! isset( $new_paths[ $path ][ $method ] ) ) {
					$violations[] = "removed {$method} {$path}";
					continue;
				}
				$violations = array_merge( $violations, self::operation_breaks( (string) $path, (string) $method, $op, $new_paths[ $path ][ $method ] ) );
			}
		}

		// The contract's own version line must never go backwards.
		if ( version_compare( (string) ( $new['info']['version'] ?? '0' ), (string) ( $old['info']['version'] ?? '0' ), '<' ) ) {
			$violations[] = 'contract version regressed';
		}

		// A deprecated operation may not lose its sunset date silently.
		foreach ( $new_paths as $path => $ops ) {
			foreach ( $ops as $method => $op ) {
				$was = $old_paths[ $path ][ $method ] ?? null;
				if ( $was && ! empty( $was['deprecated'] ) && empty( $op['deprecated'] ) ) {
					$violations[] = "un-deprecated {$method} {$path} without a new major";
				}
			}
		}

		return array_values( array_unique( $violations ) );
	}

	/** @param array<string,mixed> $old @param array<string,mixed> $new @return array<int,string> */
	private static function operation_breaks( string $path, string $method, array $old, array $new ): array {
		$violations = [];

		$old_params = [];
		foreach ( ( $old['parameters'] ?? [] ) as $p ) { $old_params[ $p['name'] ] = $p; }
		$new_params = [];
		foreach ( ( $new['parameters'] ?? [] ) as $p ) { $new_params[ $p['name'] ] = $p; }

		foreach ( $old_params as $name => $param ) {
			if ( ! isset( $new_params[ $name ] ) ) {
				$violations[] = "removed parameter {$name} on {$method} {$path}";
				continue;
			}
			if ( ( $param['schema']['type'] ?? '' ) !== ( $new_params[ $name ]['schema']['type'] ?? '' ) ) {
				$violations[] = "parameter {$name} changed type on {$method} {$path}";
			}
			if ( empty( $param['required'] ) && ! empty( $new_params[ $name ]['required'] ) ) {
				$violations[] = "parameter {$name} became required on {$method} {$path}";
			}
			// An enum may only grow — losing values breaks generated clients.
			$old_enum = $param['schema']['enum'] ?? null;
			$new_enum = $new_params[ $name ]['schema']['enum'] ?? null;
			if ( null !== $old_enum && $new_enum !== null && array_diff( $old_enum, $new_enum ) ) {
				$violations[] = "parameter {$name} lost enum values on {$method} {$path}";
			}
		}

		// Strengthened auth = breaking: a client that could call anonymously no longer can.
		$old_auth = self::AUTH_STRENGTH[ $old['x-auth'] ?? '' ] ?? 0;
		$new_auth = self::AUTH_STRENGTH[ $new['x-auth'] ?? '' ] ?? 0;
		if ( $new_auth > $old_auth ) {
			$violations[] = "auth strengthened on {$method} {$path}";
		}

		return $violations;
	}
}
