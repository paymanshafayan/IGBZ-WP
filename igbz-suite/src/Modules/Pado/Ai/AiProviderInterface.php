<?php
namespace IGBZ\Suite\Modules\Pado\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * The versioned inference contract every Pado Playbook talks to (phase 56).
 *
 * ADR-0004 §4: `Playbook → AiProviderInterface → DeepInfraAdapter → مدل تأییدشده`.
 * The contract is versioned so a Playbook can pin what it expects; providers report
 * the contract version they implement and anything older refuses loudly rather than
 * drifting into undefined behaviour.
 *
 * Three rules live in the contract itself, not in the adapters:
 *   1. The credential is a *runtime* input (the store's own account key) — it is never
 *      read from or written to IGBZ storage by the provider layer.
 *   2. Generated output is data. Nothing in this layer executes it — no eval, no
 *      include, no shell. Turning model text into an action is the permission queue's
 *      job (phases 57+), with a human or an explicit policy in the loop.
 *   3. Data and commands travel on separate planes: the system prompt and the tool
 *      definitions come from the Playbook; user-supplied content rides as user/assistant
 *      messages and can never pose as system instructions.
 */
interface AiProviderInterface {

	/** Bump on any breaking change to this contract or its value objects. */
	public const CONTRACT_VERSION = 1;

	/** The machine name of the provider, e.g. 'deepinfra'. */
	public function provider(): string;

	/** The contract version this provider implements. */
	public function contract_version(): int;

	/**
	 * Run one inference request. Refusals are honest errors, never invented output.
	 */
	public function run( AiRequest $request ): AiResult;
}
