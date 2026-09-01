<?php
namespace IGBZ\Suite\Modules\Pado\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * The versioned inference contract every Pado Playbook talks to (phase 56).
 *
 * ADR-0005 supersedes the old `Playbook → AiProviderInterface → DeepInfraAdapter`
 * chain: a provider is now a settings record, an adapter is its wire dialect, and
 * the router picks a provider per workload. The run() contract and its value
 * objects are unchanged — the three extra methods (protocol / capabilities /
 * is_configured) are registry metadata the router reads, not part of the versioned
 * run contract, so CONTRACT_VERSION stays at 1.
 *
 * Three rules live in the contract itself, not in the adapters:
 *   1. The credential is a *runtime* input (the store's own account key) — it is never
 *      read from or written to IGBZ storage by the provider layer. The panel's own
 *      provider keys live encrypted in the key vault and are resolved only at the
 *      moment of the call, never stored on the request.
 *   2. Generated output is data. Nothing in this layer executes it — no eval, no
 *      include, no shell. Turning model text into an action is the permission queue's
 *      job (phases 57+), with a human or an explicit policy in the loop.
 *   3. Data and commands travel on separate planes: the system prompt and the tool
 *      definitions come from the Playbook; user-supplied content rides as user/assistant
 *      messages and can never pose as system instructions.
 */
interface AiProviderInterface {

	/** Bump on any breaking change to the run() contract or its value objects. */
	public const CONTRACT_VERSION = 1;

	/** The machine name of the provider, e.g. 'groq'. */
	public function provider(): string;

	/** The contract version this provider implements. */
	public function contract_version(): int;

	/** The wire dialect this adapter speaks: openai | anthropic | custom. */
	public function protocol(): string;

	/** The capabilities the provider record declares (chat, tools, json, …). */
	public function capabilities(): array;

	/** All activation gates on and a usable HTTPS endpoint present. */
	public function is_configured(): bool;

	/**
	 * Run one inference request. Refusals are honest errors, never invented output.
	 */
	public function run( AiRequest $request ): AiResult;
}
