<?php
namespace IGBZ\Suite\Modules\Pado\Ai;

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Http;
use IGBZ\Suite\Support\HttpResponse;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * The shared scaffold for the three wire adapters (ADR-0005 §2). Every dialect adapter
 * — openai, anthropic, custom — enforces the same plane before a single byte leaves the
 * host, and every one records the same usage row afterwards. The adapters differ only in
 * the wire shape (`transmit`) and the response shape (`parse`).
 *
 * The guards here are the invariant the tests pin:
 *   - activation gates from the record (enabled / benchmark_passed / geo_eligible),
 *   - an HTTPS endpoint (a plaintext endpoint is not a configuration),
 *   - a credential resolved exactly once (store runtime key → panel vault → staging env),
 *   - the pinned model allowlist,
 *   - data/command separation (user-supplied turns may never pose as system),
 *   - the per-tenant daily token budget,
 *   - max_tokens clamped to MAX_TOKENS_CAP and the timeout clamped to [TIMEOUT_MIN, TIMEOUT_MAX],
 *   - the usage row deduped on its reference (delta 0; tokens live in the meta).
 */
abstract class AbstractProtocolAdapter implements AiProviderInterface {

	public const MAX_TOKENS_CAP = 4096;
	public const TIMEOUT_MIN    = 5;
	public const TIMEOUT_MAX    = 120;

	public const REASON_USAGE = 'ai_usage';

	public function __construct(
		protected ProviderDefinition $definition,
		protected KeyVault $vault,
		protected Http $http,
		protected Db $db,
		protected Logger $logger,
		protected AiToolbox $toolbox
	) {}

	public function provider(): string {
		return $this->definition->id();
	}

	public function contract_version(): int {
		return self::CONTRACT_VERSION;
	}

	public function protocol(): string {
		return $this->definition->protocol();
	}

	public function capabilities(): array {
		return $this->definition->capabilities();
	}

	public function is_configured(): bool {
		return $this->definition->activated() && '' !== $this->endpoint();
	}

	/** The full HTTPS endpoint this dialect posts to; empty when misconfigured. */
	abstract public function endpoint(): string;

	public function run( AiRequest $request ): AiResult {
		if ( ! $this->definition->activated() ) {
			return AiResult::refused( 'provider_disabled' );
		}

		$endpoint = $this->endpoint();
		if ( '' === $endpoint ) {
			return AiResult::refused( 'provider_not_configured' );
		}

		$api_key = $this->resolve_key( $request );
		if ( '' === $api_key ) {
			return AiResult::refused( 'missing_runtime_key' );
		}

		if ( ! in_array( $request->model, $this->definition->models(), true ) ) {
			return AiResult::refused( 'model_not_allowed' );
		}

		foreach ( $request->messages as $message ) {
			if ( ! in_array( (string) ( $message['role'] ?? '' ), [ AiRequest::ROLE_USER, AiRequest::ROLE_ASSISTANT ], true ) ) {
				// Data may never pose as command. Refused before any traffic.
				return AiResult::refused( 'data_role_forbidden' );
			}
		}

		// Budget: per-run cap and the tenant's daily allowance.
		$max_tokens = max( 1, min( self::MAX_TOKENS_CAP, $request->max_tokens ) );
		$budget     = $this->definition->daily_token_budget();
		if ( $this->tokens_used_today( $request->tenant_id ) >= $budget ) {
			return AiResult::refused( 'daily_budget_exhausted' );
		}

		$response = $this->transmit( $request, $api_key, $max_tokens, $endpoint );

		$json = $response->json();
		if ( ! $response->ok() || [] === $json ) {
			$this->logger->warning( 'pado', 'Provider request failed', $request->to_log_context() + [ 'status' => $response->status ] );
			return AiResult::refused( 'provider_request_failed' );
		}

		$result = $this->parse( $json, $request );

		$this->logger->info( 'pado', 'Provider inference', $request->to_log_context() + [
			'status' => $response->status,
			'tokens' => $result->usage['total_tokens'] ?? 0,
		] );

		return $result;
	}

	/** The credential, resolved exactly once at call time. */
	protected function resolve_key( AiRequest $request ): string {
		$api_key = $request->api_key;
		if ( '' === $api_key ) {
			// keyRef resolution: the panel key, from the vault, right here.
			$api_key = $this->vault->get( $this->definition->id() );
		}
		if ( '' === $api_key && 'staging' === (string) getenv( 'WP_ENVIRONMENT_TYPE' ) ) {
			$api_key = trim( (string) getenv( strtoupper( $this->definition->id() ) . '_API_KEY' ) );
		}
		return $api_key;
	}

	/**
	 * One wire round-trip. Builds the dialect body and headers, then posts to the
	 * endpoint with no retries (a retry is the caller's decision, not the adapter's).
	 */
	abstract protected function transmit( AiRequest $request, string $api_key, int $max_tokens, string $endpoint ): HttpResponse;

	/**
	 * Normalise a decoded response into an AiResult. Records the usage row when the
	 * response carried a usage block, and validates any tool calls before they surface.
	 */
	abstract protected function parse( array $json, AiRequest $request ): AiResult;

	// -------------------------------------------------------------- ledger

	/** Tokens this tenant burned today, from the usage rows (delta 0; tokens live in meta). */
	public function tokens_used_today( int $tenant_id ): int {
		$since = gmdate( 'Y-m-d 00:00:00' );
		$rows  = $this->db->column(
			'SELECT meta FROM ' . $this->db->table( 'ig_ai_credit_ledger' ) . ' WHERE tenant_id = %d AND reason = %s AND created_at >= %s',
			$tenant_id,
			self::REASON_USAGE,
			$since
		);

		$total = 0;
		foreach ( $rows as $meta ) {
			$decoded = is_string( $meta ) ? json_decode( $meta, true ) : null;
			$total  += (int) ( is_array( $decoded ) ? ( $decoded['total_tokens'] ?? 0 ) : 0 );
		}
		return $total;
	}

	/** @param array<string,mixed> $usage */
	protected function record_usage( AiRequest $request, string $response_id, array $usage ): void {
		$reference = '' !== $request->reference ? 'run:' . $request->reference : 'resp:' . $response_id;
		if ( '' === $response_id && '' === $request->reference ) {
			return; // nothing dedupeable to anchor on
		}

		$existing = $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'ig_ai_credit_ledger' ) . ' WHERE tenant_id = %d AND user_id = %d AND reason = %s AND reference = %s',
			$request->tenant_id,
			$request->user_id,
			self::REASON_USAGE,
			$reference
		);
		if ( (int) $existing > 0 ) {
			return;
		}

		$this->db->insert( 'ig_ai_credit_ledger', [
			'tenant_id' => $request->tenant_id,
			'user_id'   => $request->user_id,
			'delta'     => 0.0, // usage accounting, not a credit mutation
			'reason'    => self::REASON_USAGE,
			'reference' => $reference,
			'meta'      => wp_json_encode( [
				'provider'          => $this->provider(),
				'model'             => $request->model,
				'prompt_tokens'     => $usage['prompt_tokens'],
				'completion_tokens' => $usage['completion_tokens'],
				'total_tokens'      => $usage['total_tokens'],
				'estimated_cost'    => $usage['estimated_cost'],
			] ),
			'created_at' => current_time( 'mysql', true ),
		] );
	}

	protected function clamp_timeout( int $timeout ): int {
		return max( self::TIMEOUT_MIN, min( self::TIMEOUT_MAX, $timeout ) );
	}

	/** Walk a dot-path into a decoded JSON body; null when it does not resolve. */
	protected function dot_path( array $body, string $path ): mixed {
		if ( '' === $path ) {
			return null;
		}
		$value = $body;
		foreach ( explode( '.', $path ) as $segment ) {
			if ( is_array( $value ) && array_key_exists( $segment, $value ) ) {
				$value = $value[ $segment ];
				continue;
			}
			if ( is_array( $value ) && array_is_list( $value ) && ctype_digit( $segment ) && array_key_exists( (int) $segment, $value ) ) {
				$value = $value[ (int) $segment ];
				continue;
			}
			return null;
		}
		return $value;
	}

	/**
	 * Normalise a usage shape into the internal {prompt_tokens, completion_tokens,
	 * total_tokens, estimated_cost} map the ledger and AiResult share.
	 *
	 * @return array{prompt_tokens:int,completion_tokens:int,total_tokens:int,estimated_cost:float}|null
	 */
	protected function normalise_usage( int $prompt, int $completion, int $total = -1, float $cost = 0.0 ): ?array {
		if ( $prompt <= 0 && $completion <= 0 ) {
			return null;
		}
		if ( $total <= 0 ) {
			$total = $prompt + $completion;
		}
		return [
			'prompt_tokens'     => $prompt,
			'completion_tokens' => $completion,
			'total_tokens'      => $total,
			'estimated_cost'    => $cost,
		];
	}
}
