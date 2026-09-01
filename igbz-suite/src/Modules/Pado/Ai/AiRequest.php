<?php
namespace IGBZ\Suite\Modules\Pado\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * One inference request (phase 56). Built by a Playbook, consumed by an adapter.
 *
 * The store's provider key rides here as a runtime-only value: it is supplied per run
 * from the store's own custody, never read from IGBZ options and never persisted by the
 * adapter (ADR-0004 §4, ADR-0005 §key-storage). When the store has no key of its own,
 * the adapter resolves the panel's key from the key vault exactly once, at call time.
 *
 * Planes:
 *   - command plane: $system (Playbook instructions) + $tools (allowlisted tool names);
 *   - data plane:    $messages, roles limited to user/assistant — the adapter rejects
 *                    anything trying to arrive as system/tool.
 */
final class AiRequest {

	public const ROLE_USER      = 'user';
	public const ROLE_ASSISTANT = 'assistant';

	/**
	 * @param array<int,array{role:string,content:string}> $messages data-plane turns
	 * @param array<int,string> $tools allowlisted tool names the model may call
	 */
	public function __construct(
		public readonly int $tenant_id,
		public readonly int $user_id,
		public readonly string $api_key,
		public readonly string $model,
		public readonly string $system,
		public readonly array $messages,
		public readonly array $tools = [],
		public readonly int $max_tokens = 1024,
		public readonly int $timeout = 60,
		public readonly string $reference = ''
	) {}

	/** @return array<string,mixed> */
	public function to_log_context(): array {
		// The key is deliberately absent: log context is written as-is.
		return [
			'tenant_id'   => $this->tenant_id,
			'user_id'     => $this->user_id,
			'model'       => $this->model,
			'tools'       => $this->tools,
			'max_tokens'  => $this->max_tokens,
			'reference'   => $this->reference,
			'messages'    => count( $this->messages ),
		];
	}
}
