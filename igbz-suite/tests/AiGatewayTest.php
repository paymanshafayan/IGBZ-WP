<?php
/**
 * ADR-0005 — the provider registry and router: the two seeded providers, section routing
 * with the shared-provider switch, the one-shot default fallback, capability gating, the
 * encrypted key vault, and the honest refusal for a dialect that has no adapter yet.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\Pado\Ai\AiGateway;
use IGBZ\Suite\Modules\Pado\Ai\AiRequest;
use IGBZ\Suite\Modules\Pado\Ai\AiToolbox;
use IGBZ\Suite\Modules\Pado\Ai\KeyVault;
use IGBZ\Suite\Modules\Pado\Ai\ProviderDefinition;
use IGBZ\Suite\Modules\Pado\Ai\ProviderRegistry;
use IGBZ\Suite\Modules\Pado\Ai\Workload;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Http;
use IGBZ\Suite\Support\Settings;

require_once __DIR__ . '/OpenAiAdapterTest.php';

final class AiGatewayTest extends TestCase {

	private Settings $settings;
	private ProviderRegistry $registry;
	private AiGateway $gateway;
	private KeyVault $vault;

	public function run(): void {
		$this->the_registry_seeds_the_two_default_providers();
		$this->routing_defaults_to_groq_and_openrouter();
		$this->a_missing_provider_falls_back_to_the_default_once();
		$this->the_shared_switch_makes_judgment_follow_routine();
		$this->the_model_override_wins_and_defaults_to_the_provider_model();
		$this->an_unsupported_protocol_refuses_honestly();
		$this->anthropic_and_custom_now_resolve_to_adapters();
		$this->tools_capability_is_gated_before_running();
		$this->the_key_vault_is_encrypted_at_rest();
		$this->a_routed_run_reaches_the_provider();
	}

	// ------------------------------------------------------------ registry

	private function the_registry_seeds_the_two_default_providers(): void {
		$this->fresh();
		$this->assert_same( [ 'groq', 'openrouter' ], $this->registry->ids(), 'the two default providers are seeded' );
		$this->assert_same( 'openai', $this->registry->get( 'groq' )->protocol(), 'groq speaks openai' );
		$this->assert_same( 'openai', $this->registry->get( 'openrouter' )->protocol(), 'openrouter speaks openai' );
		$this->assert_same( 'premium', $this->registry->get( 'openrouter' )->quality(), 'openrouter is the premium tier' );
		$this->assert_true( $this->registry->seed_defaults(), 'the first seed writes the defaults' );
		$this->assert_false( $this->registry->seed_defaults(), 'the second seed is a no-op' );
	}

	private function routing_defaults_to_groq_and_openrouter(): void {
		$this->fresh();
		$this->assert_same( 'groq', $this->gateway->routing_id( Workload::ROUTINE ), 'امور اداری defaults to groq' );
		$this->assert_same( 'openrouter', $this->gateway->routing_id( Workload::JUDGMENT ), 'مدیریت defaults to openrouter' );
		$this->assert_same( 'llama-3.3-70b-versatile', $this->gateway->model_for( Workload::ROUTINE ), 'the routine model is the groq default' );
		$this->assert_same( 'anthropic/claude-sonnet-4', $this->gateway->model_for( Workload::JUDGMENT ), 'the judgment model is the openrouter default' );
	}

	private function a_missing_provider_falls_back_to_the_default_once(): void {
		$this->fresh();
		$this->settings->set( 'pado.ai.routing.routine', 'ghost' );
		$this->settings->set( 'pado.ai.default_provider', 'groq' );

		$definition = $this->gateway->definition_for( Workload::ROUTINE );
		$this->assert_same( 'groq', $definition->id(), 'a dangling route falls back to the default provider' );

		$this->settings->set( 'pado.ai.default_provider', 'also-ghost' );
		$this->assert_true( null === $this->gateway->definition_for( Workload::ROUTINE ), 'with no fallback the route resolves to nothing' );
	}

	private function the_shared_switch_makes_judgment_follow_routine(): void {
		$this->fresh();
		$this->settings->set( 'pado.ai.routing.routine', 'groq' );
		$this->settings->set( 'pado.ai.routing.judgment', 'openrouter' );

		$this->assert_same( 'openrouter', $this->gateway->routing_id( Workload::JUDGMENT ), 'without the switch each section routes on its own' );

		$this->settings->set( 'pado.ai.shared', '1' );
		$this->assert_same( 'groq', $this->gateway->routing_id( Workload::JUDGMENT ), 'the switch makes مدیریت follow امور اداری' );
		$this->assert_same( 'llama-3.3-70b-versatile', $this->gateway->model_for( Workload::JUDGMENT ), 'and its model follows too' );
	}

	private function the_model_override_wins_and_defaults_to_the_provider_model(): void {
		$this->fresh();
		$this->settings->set( 'pado.ai.model.routine', 'llama-3.1-8b-instant' );
		$this->assert_same( 'llama-3.1-8b-instant', $this->gateway->model_for( Workload::ROUTINE ), 'an explicit override wins' );
		$this->assert_same( 'anthropic/claude-sonnet-4', $this->gateway->model_for( Workload::JUDGMENT ), 'no override falls back to the provider default' );
	}

	// --------------------------------------------------------------- gates

	private function an_unsupported_protocol_refuses_honestly(): void {
		$this->fresh();
		$record            = ProviderDefinition::seed_defaults()[0];
		$record['id']      = 'bespoke-host';
		$record['protocol'] = 'bespoke'; // not one of openai|anthropic|custom
		$this->registry->upsert( ProviderDefinition::from_array( $record ) );
		$this->settings->set( 'pado.ai.routing.routine', 'bespoke-host' );

		$this->assert_true( null === $this->gateway->resolve( Workload::ROUTINE ), 'a dialect without an adapter does not resolve' );
		$result = $this->gateway->run( Workload::ROUTINE, $this->request() );
		$this->assert_false( $result->ok, 'the run refuses' );
		$this->assert_same( 'protocol_unsupported', $result->error, 'the refusal names the missing dialect' );
	}

	private function anthropic_and_custom_now_resolve_to_adapters(): void {
		$this->fresh();
		$anthropic            = ProviderDefinition::seed_defaults()[0];
		$anthropic['id']      = 'anthropic-host';
		$anthropic['protocol'] = ProviderDefinition::PROTOCOL_ANTHROPIC;
		$anthropic['base_url'] = 'https://api.anthropic.com/v1';
		$this->registry->upsert( ProviderDefinition::from_array( $anthropic ) );

		$custom            = ProviderDefinition::seed_defaults()[1];
		$custom['id']      = 'custom-host';
		$custom['protocol'] = ProviderDefinition::PROTOCOL_CUSTOM;
		$custom['base_url'] = 'https://example.com/llm';
		$this->registry->upsert( ProviderDefinition::from_array( $custom ) );

		$this->settings->set( 'pado.ai.routing.routine', 'anthropic-host' );
		$this->assert_same( 'anthropic', $this->gateway->resolve( Workload::ROUTINE )->protocol(), 'anthropic resolves to its adapter' );

		$this->settings->set( 'pado.ai.routing.routine', 'custom-host' );
		$this->assert_same( 'custom', $this->gateway->resolve( Workload::ROUTINE )->protocol(), 'custom resolves to its adapter' );
	}

	private function tools_capability_is_gated_before_running(): void {
		$this->fresh();
		$record                   = ProviderDefinition::seed_defaults()[0];
		$record['enabled']        = true;
		$record['benchmark_passed'] = true;
		$record['geo_eligible']   = true;
		$record['capabilities']   = [ 'chat' ]; // tools withheld
		$this->registry->upsert( ProviderDefinition::from_array( $record ) );
		$this->settings->set( 'pado.ai.routing.routine', 'groq' );

		$result = $this->gateway->run( Workload::ROUTINE, $this->request( tools: [ 'product_search' ] ) );
		$this->assert_false( $result->ok, 'a tool request to a tool-less provider is refused' );
		$this->assert_same( 'capability_unsupported', $result->error, 'the refusal names the missing capability' );
	}

	// ---------------------------------------------------------------- vault

	private function the_key_vault_is_encrypted_at_rest(): void {
		$this->fresh();
		$this->vault->set( 'groq', 'sk-secret-123' );
		$this->assert_same( 'sk-secret-123', $this->vault->get( 'groq' ), 'the key reads back through the vault' );
		$this->assert_true( $this->settings->is_secret( KeyVault::OPTION ), 'the vault option is a registered secret' );

		$raw = $this->settings->all()[ KeyVault::OPTION ] ?? '';
		$this->assert_not_contains( 'sk-secret-123', (string) $raw, 'the vault blob never holds the plaintext key' );
		$this->assert_contains( 'igbz1:', (string) $raw, 'the vault blob is encrypted at rest' );

		$this->vault->remove( 'groq' );
		$this->assert_false( $this->vault->has( 'groq' ), 'removal empties the slot' );
	}

	// ---------------------------------------------------------------- wiring

	private function a_routed_run_reaches_the_provider(): void {
		$this->fresh();
		$record                   = ProviderDefinition::seed_defaults()[0];
		$record['enabled']        = true;
		$record['benchmark_passed'] = true;
		$record['geo_eligible']   = true;
		$this->registry->upsert( ProviderDefinition::from_array( $record ) );

		igbz_test_queue_http( [
			'match'  => '/chat/completions',
			'status' => 200,
			'body'   => wp_json_encode( [
				'id'      => 'chatcmpl-gw',
				'choices' => [ [ 'message' => [ 'content' => 'سلام' ] ] ],
				'usage'   => [ 'prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2, 'estimated_cost' => 0 ],
			] ),
		] );

		$result = $this->gateway->run( Workload::ROUTINE, $this->request( key: 'gw-key', reference: 'gw-run' ) );
		$this->assert_true( $result->ok, 'the routed run succeeds' );
		$this->assert_same( 'groq', $result->provider, 'the result names the routed provider' );

		$requests = $GLOBALS['igbz_test_http_requests'];
		$this->assert_contains( 'https://api.groq.com/openai/v1/chat/completions', (string) $requests[0]['url'], 'the request hits the provider endpoint' );
		$this->assert_same( 'Bearer gw-key', (string) ( $requests[0]['headers']['Authorization'] ?? '' ), 'the runtime key rides the routed call' );
	}

	// --------------------------------------------------------------- setup

	private function fresh(): void {
		igbz_test_reset_settings();
		$this->settings = igbz()->settings();
		$this->vault    = new KeyVault( $this->settings );

		$logger        = igbz()->get( 'logger' );
		$this->registry = new ProviderRegistry(
			$this->settings,
			new Http( $logger ),
			new Db(),
			$logger,
			new AiToolbox(),
			$this->vault
		);
		$this->gateway  = new AiGateway( $this->settings, $this->registry );
	}

	private function request( array $tools = [], string $key = '', string $reference = 'gateway-run' ): AiRequest {
		return new AiRequest(
			tenant_id: 1,
			user_id: 7,
			api_key: $key,
			model: 'llama-3.3-70b-versatile',
			system: 'You are the growth assistant.',
			messages: [ [ 'role' => 'user', 'content' => 'پیشنهاد کپشن' ] ],
			tools: $tools,
			max_tokens: 256,
			timeout: 60,
			reference: $reference
		);
	}
}
