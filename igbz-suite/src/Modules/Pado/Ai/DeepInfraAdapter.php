<?php
namespace IGBZ\Suite\Modules\Pado\Ai;

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Http;
use IGBZ\Suite\Support\Logger;
use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * DeepInfraAdapter — the only inference provider of version one (phase 56, ADR-0004 §4).
 *
 * Activation gates (all three settings, default off, senior-admin owned):
 *   pado.deepinfra.enabled          the operator turned the plane on
 *   pado.deepinfra.benchmark_passed the Persian benchmark was accepted
 *   pado.deepinfra.geo_eligible     geographic/contractual eligibility confirmed
 * Until all three are on, every run refuses honestly with `provider_disabled`.
 *
 * Credential policy: the store's DeepInfra key arrives per request (its own independent
 * account — billing, caps and quota belong to the store). It is used for exactly one
 * HTTPS call to the configured endpoint and then forgotten: never stored, never logged,
 * never attached to a different host. This class reads no key setting and writes no
 * option.
 *
 * Budget: per-run `max_tokens` (clamped ≤ 4096, under the provider's own 16384 hard cap)
 * and a per-tenant daily token budget (`pado.deepinfra.daily_token_budget`, default
 * 200k) checked against the usage ledger before the call and appended after it.
 *
 * Data/command separation: the system prompt comes only from the request's Playbook
 * field; data-plane messages may be user/assistant only — a message trying to arrive as
 * `system` or `tool` is refused before any network traffic. Tool definitions are
 * generated from the AiToolbox allowlist, never from user input.
 *
 * No execution: model output (content or tool calls) is returned as data. There is no
 * eval/include/exec anywhere in this class, and tool calls are only *reported* — acting
 * on them is the permission queue's job (phases 57+).
 */
final class DeepInfraAdapter implements AiProviderInterface {

	public const DEFAULT_ENDPOINT = 'https://api.deepinfra.com/v1/openai/chat/completions';

	/** ADR-0004 §4 initial pinned list; the ratified post-benchmark list lands with the boss's sign-off. */
	public const DEFAULT_MODELS = [
		'deepseek-ai/DeepSeek-V3',
		'moonshotai/Kimi-K2.7-Code',
		'moonshotai/Kimi-K3',
	];

	public const MAX_TOKENS_CAP  = 4096;
	public const TIMEOUT_MIN     = 5;
	public const TIMEOUT_MAX     = 120;
	public const DEFAULT_TIMEOUT = 60;

	public const REASON_USAGE = 'ai_usage';

	private const DEFAULT_DAILY_TOKEN_BUDGET = 200000;

	public function __construct(
		private Http $http,
		private Db $db,
		private Logger $logger,
		private Settings $settings,
		private AiToolbox $toolbox
	) {}

	public function provider(): string {
		return 'deepinfra';
	}

	public function contract_version(): int {
		return self::CONTRACT_VERSION;
	}

	public function run( AiRequest $request ): AiResult {
		if ( ! $this->activated() ) {
			return AiResult::refused( 'provider_disabled' );
		}

		$endpoint = $this->endpoint();
		if ( '' === $endpoint ) {
			return AiResult::refused( 'provider_not_configured' );
		}

		$api_key = $request->api_key;
		if ( '' === $api_key && 'staging' === (string) getenv( 'WP_ENVIRONMENT_TYPE' ) ) {
			$api_key = trim( (string) getenv( 'DEEPINFRA_API_KEY' ) );
		}
		if ( '' === $api_key ) {
			return AiResult::refused( 'missing_runtime_key' );
		}

		if ( ! in_array( $request->model, $this->models(), true ) ) {
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
		$budget     = $this->daily_token_budget();
		$used       = $this->tokens_used_today( $request->tenant_id );
		if ( $used >= $budget ) {
			return AiResult::refused( 'daily_budget_exhausted' );
		}

		$messages = [];
		if ( '' !== $request->system ) {
			$messages[] = [ 'role' => 'system', 'content' => $request->system ];
		}
		foreach ( $request->messages as $message ) {
			$messages[] = [
				'role'    => (string) $message['role'],
				'content' => (string) ( $message['content'] ?? '' ),
			];
		}

		$body = [
			'model'      => $request->model,
			'messages'   => $messages,
			'max_tokens' => $max_tokens,
		];

		$definitions = $this->toolbox->definitions( $request->tools );
		if ( $definitions ) {
			$body['tools'] = $definitions;
		}

		$response = $this->http->post(
			$endpoint,
			[
				'json'    => $body,
				'headers' => [
					// The runtime key travels to exactly this endpoint, once, and nowhere else.
					'Authorization' => 'Bearer ' . $api_key,
					'Accept'        => 'application/json',
				],
				'channel' => 'pado',
				'timeout' => $this->clamp_timeout( $request->timeout ),
				'retries' => 0,
			]
		);

		$json = $response->json();
		if ( ! $response->ok() || ! is_array( $json ) ) {
			$this->logger->warning( 'pado', 'DeepInfra request failed', $request->to_log_context() + [ 'status' => $response->status ] );
			return AiResult::refused( 'provider_request_failed' );
		}

		$choice = (array) ( $json['choices'][0] ?? [] );
		$message = (array) ( $choice['message'] ?? [] );

		// Tool calls: allowlisted names, backend-validated arguments, everything else dropped.
		$tool_calls = [];
		foreach ( (array) ( $message['tool_calls'] ?? [] ) as $call ) {
			$call = (array) $call;
			$fn   = (array) ( $call['function'] ?? [] );
			$name = (string) ( $fn['name'] ?? '' );
			if ( '' === $name || ! $this->toolbox->exists( $name ) || ! in_array( $name, $request->tools, true ) ) {
				continue;
			}
			$args = json_decode( (string) ( $fn['arguments'] ?? '{}' ), true );
			if ( ! is_array( $args ) || ! $this->toolbox->valid_args( $name, $args ) ) {
				continue;
			}
			$tool_calls[] = [ 'name' => $name, 'args' => $args ];
		}

		$usage = null;
		if ( is_array( $json['usage'] ?? null ) ) {
			$usage = [
				'prompt_tokens'     => (int) ( $json['usage']['prompt_tokens'] ?? 0 ),
				'completion_tokens' => (int) ( $json['usage']['completion_tokens'] ?? 0 ),
				'total_tokens'      => (int) ( $json['usage']['total_tokens'] ?? 0 ),
				'estimated_cost'    => (float) ( $json['usage']['estimated_cost'] ?? 0.0 ),
			];
			$this->record_usage( $request, (string) ( $json['id'] ?? '' ), $usage );
		}

		$this->logger->info( 'pado', 'DeepInfra inference', $request->to_log_context() + [
			'status' => $response->status,
			'tokens' => $usage['total_tokens'] ?? 0,
		] );

		return new AiResult(
			ok: true,
			error: '',
			content: (string) ( $message['content'] ?? '' ),
			usage: $usage,
			model: (string) ( $json['model'] ?? $request->model ),
			provider: $this->provider(),
			reference: '' !== $request->reference ? $request->reference : (string) ( $json['id'] ?? '' ),
			tool_calls: $tool_calls
		);
	}

	// ---------------------------------------------------------------- gates

	/** All three activation flags must be on; each defaults to off. */
	public function activated(): bool {
		return $this->settings->bool( 'pado.deepinfra.enabled' )
			&& $this->settings->bool( 'pado.deepinfra.benchmark_passed' )
			&& $this->settings->bool( 'pado.deepinfra.geo_eligible' );
	}

	/** HTTPS-only, settings-overridable, empty when misconfigured (http is refused). */
	public function endpoint(): string {
		$endpoint = esc_url_raw( $this->settings->string( 'pado.deepinfra.endpoint', self::DEFAULT_ENDPOINT ) );
		if ( '' === $endpoint ) {
			return '';
		}
		return 'https' === strtolower( (string) wp_parse_url( $endpoint, PHP_URL_SCHEME ) ) ? $endpoint : '';
	}

	/** @return array<int,string> */
	public function models(): array {
		$csv = $this->settings->string( 'pado.deepinfra.models', '' );
		$list = '' !== $csv
			? array_filter( array_map( 'trim', explode( ',', $csv ) ) )
			: self::DEFAULT_MODELS;
		return array_values( $list );
	}

	public function daily_token_budget(): int {
		return max( 0, $this->settings->int( 'pado.deepinfra.daily_token_budget', self::DEFAULT_DAILY_TOKEN_BUDGET ) );
	}

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
	private function record_usage( AiRequest $request, string $response_id, array $usage ): void {
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
			'tenant_id'  => $request->tenant_id,
			'user_id'    => $request->user_id,
			'delta'      => 0.0, // usage accounting, not a credit mutation
			'reason'     => self::REASON_USAGE,
			'reference'  => $reference,
			'meta'       => wp_json_encode( [
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

	private function clamp_timeout( int $timeout ): int {
		return max( self::TIMEOUT_MIN, min( self::TIMEOUT_MAX, $timeout ) );
	}
}
