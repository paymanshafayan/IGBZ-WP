<?php
namespace IGBZ\Suite\Modules\Pado\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * One inference result (phase 56). Content and tool calls are DATA — nothing in the
 * provider layer executes any of it. `$executed` is always false and exists so callers
 * and tests can assert the invariant explicitly.
 */
final class AiResult {

	/**
	 * @param array<int,array{name:string,args:array<string,mixed>}> $tool_calls
	 * @param array<string,mixed>|null $usage prompt_tokens/completion_tokens/total_tokens/estimated_cost
	 */
	public function __construct(
		public readonly bool $ok,
		public readonly string $error,
		public readonly string $content,
		public readonly array $tool_calls,
		public readonly ?array $usage,
		public readonly string $model,
		public readonly string $provider,
		public readonly string $reference,
		public readonly bool $executed = false
	) {}

	/** @param array<string,mixed> $meta */
	public static function refused( string $error, array $meta = [] ): self {
		unset( $meta );
		return new self( false, $error, '', [], null, '', '', '', false );
	}
}
