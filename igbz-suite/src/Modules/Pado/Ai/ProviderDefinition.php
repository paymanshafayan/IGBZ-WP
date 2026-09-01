<?php
namespace IGBZ\Suite\Modules\Pado\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * One `api_provider` record read from settings (ADR-0005).
 *
 * A provider is configuration, not code: adding Groq, OpenRouter or any other
 * openai-dialect host is a settings record, never a class. The wire dialect lives in
 * `protocol` (openai | anthropic | custom); the three activation gates, the pinned
 * model allowlist, the quality tier, the daily token budget and the timeout all live
 * here, keyed by the record, so the adapter never hardcodes a provider's name.
 */
final class ProviderDefinition {

	public const PROTOCOL_OPENAI    = 'openai';
	public const PROTOCOL_ANTHROPIC = 'anthropic';
	public const PROTOCOL_CUSTOM    = 'custom';

	public const TYPE_API_PROVIDER = 'api_provider';

	public const QUALITY_STANDARD = 'standard';
	public const QUALITY_PREMIUM  = 'premium';

	/** The capability vocabulary a record may declare. */
	public const CAPABILITIES = [ 'chat', 'tools', 'json', 'vision', 'stt', 'tts' ];

	public const DEFAULT_DAILY_TOKEN_BUDGET = 200000;
	public const DEFAULT_TIMEOUT            = 60;

	/** @var array<string,mixed> */
	private array $record;

	/** @param array<string,mixed> $record */
	private function __construct( array $record ) {
		$this->record = $record;
	}

	/** @param array<string,mixed> $record */
	public static function from_array( array $record ): self {
		return new self( $record );
	}

	public function id(): string {
		return (string) ( $this->record['id'] ?? '' );
	}

	public function title(): string {
		$title = (string) ( $this->record['title'] ?? '' );
		return '' !== $title ? $title : $this->id();
	}

	public function type(): string {
		return (string) ( $this->record['type'] ?? self::TYPE_API_PROVIDER );
	}

	public function protocol(): string {
		return (string) ( $this->record['protocol'] ?? self::PROTOCOL_OPENAI );
	}

	public function base_url(): string {
		return (string) ( $this->record['base_url'] ?? '' );
	}

	public function default_model(): string {
		return (string) ( $this->record['default_model'] ?? '' );
	}

	public function quality(): string {
		return (string) ( $this->record['quality'] ?? self::QUALITY_STANDARD );
	}

	public function daily_token_budget(): int {
		return max( 0, (int) ( $this->record['daily_token_budget'] ?? self::DEFAULT_DAILY_TOKEN_BUDGET ) );
	}

	public function timeout(): int {
		return max( 1, (int) ( $this->record['timeout'] ?? self::DEFAULT_TIMEOUT ) );
	}

	/**
	 * Custom-dialect rendering (ADR-0005 §7). Only `protocol=custom` reads these; for
	 * every other dialect they are inert. The concrete mapping the CustomProtocolAdapter
	 * renders is: HTTP method + request path against base_url, then dot-paths into the
	 * decoded JSON response for the text and the usage fields. No arbitrary logic.
	 */
	public function request_method(): string {
		$method = strtoupper( (string) ( $this->record['request_method'] ?? 'POST' ) );
		return in_array( $method, [ 'GET', 'POST', 'PUT', 'PATCH' ], true ) ? $method : 'POST';
	}

	public function request_path(): string {
		return (string) ( $this->record['request_path'] ?? '' );
	}

	public function response_content_path(): string {
		return (string) ( $this->record['response_content_path'] ?? 'choices.0.message.content' );
	}

	public function response_usage_prompt_path(): string {
		return (string) ( $this->record['response_usage_prompt_path'] ?? 'usage.prompt_tokens' );
	}

	public function response_usage_completion_path(): string {
		return (string) ( $this->record['response_usage_completion_path'] ?? 'usage.completion_tokens' );
	}

	public function response_usage_total_path(): string {
		return (string) ( $this->record['response_usage_total_path'] ?? 'usage.total_tokens' );
	}

	/** @return array<int,string> */
	public function models(): array {
		$raw  = $this->record['model_allowlist'] ?? [];
		$list = is_array( $raw ) ? $raw : array_filter( array_map( 'trim', explode( ',', (string) $raw ) ) );
		return array_values( array_filter( array_map( 'strval', $list ) ) );
	}

	/** @return array<int,string> */
	public function capabilities(): array {
		$raw  = $this->record['capabilities'] ?? [ 'chat', 'tools', 'json' ];
		$list = is_array( $raw ) ? $raw : array_filter( array_map( 'trim', explode( ',', (string) $raw ) ) );
		return array_values( array_intersect( self::CAPABILITIES, array_map( 'strval', $list ) ) );
	}

	public function has_capability( string $capability ): bool {
		return in_array( $capability, $this->capabilities(), true );
	}

	public function enabled(): bool {
		return $this->flag( 'enabled' );
	}

	public function benchmark_passed(): bool {
		return $this->flag( 'benchmark_passed' );
	}

	public function geo_eligible(): bool {
		return $this->flag( 'geo_eligible' );
	}

	/** All three gates on = the plane may run for this provider. */
	public function activated(): bool {
		return $this->enabled() && $this->benchmark_passed() && $this->geo_eligible();
	}

	/** HTTPS-only base URL; empty when missing or plaintext (http is refused). */
	public function https_base_url(): string {
		$url = trim( $this->base_url() );
		if ( '' === $url ) {
			return '';
		}
		return 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ? $url : '';
	}

	/** @return array<string,mixed> */
	public function to_array(): array {
		return $this->record;
	}

	/**
	 * The two seeded api providers the panel starts with (ADR-0005 §migration).
	 * Both speak the `openai` dialect; the pinned allowlists are starting points the
	 * operator edits in the panel, not hardcoded routing.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function seed_defaults(): array {
		return [
			[
				'id'                 => 'groq',
				'title'              => 'Groq',
				'type'               => self::TYPE_API_PROVIDER,
				'protocol'           => self::PROTOCOL_OPENAI,
				'base_url'           => 'https://api.groq.com/openai/v1',
				'model_allowlist'    => [ 'llama-3.3-70b-versatile', 'llama-3.1-8b-instant', 'meta-llama/llama-4-scout-17b-16e-instruct', 'mixtral-8x7b-32768', 'gemma2-9b-it' ],
				'default_model'      => 'llama-3.3-70b-versatile',
				'capabilities'       => [ 'chat', 'tools', 'json' ],
				'quality'            => self::QUALITY_STANDARD,
				'enabled'            => false,
				'benchmark_passed'   => false,
				'geo_eligible'       => false,
				'daily_token_budget' => self::DEFAULT_DAILY_TOKEN_BUDGET,
				'timeout'            => self::DEFAULT_TIMEOUT,
			],
			[
				'id'                 => 'openrouter',
				'title'              => 'OpenRouter',
				'type'               => self::TYPE_API_PROVIDER,
				'protocol'           => self::PROTOCOL_OPENAI,
				'base_url'           => 'https://openrouter.ai/api/v1',
				'model_allowlist'    => [ 'anthropic/claude-sonnet-4', 'openai/gpt-4o-mini', 'google/gemini-2.5-pro', 'meta-llama/llama-3.1-405b-instruct' ],
				'default_model'      => 'anthropic/claude-sonnet-4',
				'capabilities'       => [ 'chat', 'tools', 'json' ],
				'quality'            => self::QUALITY_PREMIUM,
				'enabled'            => false,
				'benchmark_passed'   => false,
				'geo_eligible'       => false,
				'daily_token_budget' => self::DEFAULT_DAILY_TOKEN_BUDGET,
				'timeout'            => self::DEFAULT_TIMEOUT,
			],
		];
	}

	private function flag( string $key ): bool {
		$value = $this->record[ $key ] ?? false;
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? false;
	}
}
