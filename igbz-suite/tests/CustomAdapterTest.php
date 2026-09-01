<?php
/**
 * ADR-0005 — the custom wire adapter. For a provider whose endpoint is neither OpenAI-
 * nor Anthropic-shaped, the operator records an HTTP method, a request path and dot-paths
 * into the decoded response for the text and the usage fields. This suite pins that
 * config-driven JSON mapping — and its honest defaults — on top of the inherited guard
 * plane from AbstractProtocolAdapter.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\Pado\Ai\AiProviderInterface;
use IGBZ\Suite\Modules\Pado\Ai\AiRequest;
use IGBZ\Suite\Modules\Pado\Ai\AiToolbox;
use IGBZ\Suite\Modules\Pado\Ai\CustomProtocolAdapter;
use IGBZ\Suite\Modules\Pado\Ai\KeyVault;
use IGBZ\Suite\Modules\Pado\Ai\ProviderDefinition;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Http;

require_once __DIR__ . '/OpenAiAdapterTest.php';

final class CustomAdapterTest extends TestCase {

	private AiLedgerDb $db;
	private KeyVault $vault;
	private CustomProtocolAdapter $adapter;

	public function run(): void {
		$this->the_contract_names_the_custom_dialect();
		$this->the_endpoint_is_base_url_plus_the_configured_path();
		$this->post_is_the_default_method_and_carries_a_body();
		$this->a_get_method_is_honoured_without_a_body_header();
		$this->content_is_extracted_from_the_configured_dot_path();
		$this->usage_paths_map_into_the_shared_ledger();
		$this->missing_paths_fall_back_to_openai_shaped_defaults();
		$this->tool_calls_are_never_invented();
		$this->the_model_allowlist_is_enforced();
	}

	private function the_contract_names_the_custom_dialect(): void {
		$this->fresh( activated: true );
		$this->assert_same( 'custom-host', $this->adapter->provider(), 'the provider name comes from the record' );
		$this->assert_same( 'custom', $this->adapter->protocol(), 'the wire dialect is pinned to custom' );
		$this->assert_same( AiProviderInterface::CONTRACT_VERSION, $this->adapter->contract_version(), 'the adapter implements the current contract' );
	}

	private function the_endpoint_is_base_url_plus_the_configured_path(): void {
		$this->fresh( activated: true );
		$this->assert_same( 'https://example.com/llm/chat', $this->adapter->endpoint(), 'the endpoint is base_url + request_path' );

		$this->fresh( activated: true, overrides: [ 'request_path' => '' ] );
		$this->assert_same( 'https://example.com/llm', $this->adapter->endpoint(), 'an empty path leaves the base_url alone' );
	}

	private function post_is_the_default_method_and_carries_a_body(): void {
		$this->fresh( activated: true );
		$this->queue_http( [
			'match'  => '/llm/chat',
			'status' => 200,
			'body'   => wp_json_encode( [ 'choices' => [ [ 'message' => [ 'content' => 'ok' ] ] ] ] ),
		] );

		$this->adapter->run( $this->request( key: 'custom-key', reference: 'post' ) );

		$requests = $this->http_requests();
		$this->assert_same( 1, count( $requests ), 'exactly one provider call' );
		$this->assert_same( 'POST', (string) ( $requests[0]['method'] ?? '' ), 'POST is the default method' );
		$this->assert_same( 'Bearer custom-key', (string) ( $requests[0]['headers']['Authorization'] ?? '' ), 'the key rides as a Bearer header' );
		$this->assert_contains( 'application/json', (string) ( $requests[0]['headers']['Content-Type'] ?? '' ), 'a body method carries Content-Type' );

		$body = json_decode( (string) $requests[0]['body'], true );
		$this->assert_same( 'acme/custom-1', (string) $body['model'], 'the model field is the request model' );
		$this->assert_same( 256, (int) $body['max_tokens'], 'max_tokens rides the wire' );
		$this->assert_same( 'You are the growth assistant.', (string) ( $body['system'] ?? '' ), 'the system prompt rides its own field' );
	}

	private function a_get_method_is_honoured_without_a_body_header(): void {
		$this->fresh( activated: true, overrides: [ 'request_method' => 'GET' ] );
		$this->queue_http( [
			'match'  => '/llm/chat',
			'status' => 200,
			'body'   => wp_json_encode( [ 'choices' => [ [ 'message' => [ 'content' => 'ok' ] ] ] ] ),
		] );

		$this->adapter->run( $this->request( reference: 'get' ) );

		$requests = $this->http_requests();
		$this->assert_same( 'GET', (string) ( $requests[0]['method'] ?? '' ), 'the configured method is honoured' );
		$this->assert_false( isset( $requests[0]['headers']['Content-Type'] ), 'a GET has no body header' );
	}

	private function content_is_extracted_from_the_configured_dot_path(): void {
		$this->fresh( activated: true, overrides: [ 'response_content_path' => 'result.text' ] );
		$this->queue_http( [
			'match'  => '/llm/chat',
			'status' => 200,
			'body'   => wp_json_encode( [ 'result' => [ 'text' => 'پاسخ سفارشی' ] ] ),
		] );

		$result = $this->adapter->run( $this->request( reference: 'content' ) );
		$this->assert_true( $result->ok, 'the run succeeds' );
		$this->assert_same( 'پاسخ سفارشی', $result->content, 'the text comes from the configured dot-path' );
	}

	private function usage_paths_map_into_the_shared_ledger(): void {
		$this->fresh( activated: true, overrides: [
			'response_content_path'          => 'result.text',
			'response_usage_prompt_path'     => 'usage.prompt',
			'response_usage_completion_path' => 'usage.completion',
			'response_usage_total_path'      => 'usage.total',
		] );
		$this->queue_http( [
			'match'  => '/llm/chat',
			'status' => 200,
			'body'   => wp_json_encode( [
				'id'     => 'custom-1',
				'result' => [ 'text' => 'تحلیل' ],
				'usage'  => [ 'prompt' => 80, 'completion' => 20, 'total' => 100 ],
			] ),
		] );

		$result = $this->adapter->run( $this->request( reference: 'usage' ) );
		$this->assert_true( $result->ok, 'the run succeeds' );
		$this->assert_same( 100, $result->usage['total_tokens'], 'the configured total path is read' );
		$this->assert_same( 80, $result->usage['prompt_tokens'], 'the configured prompt path is read' );

		$this->assert_same( 1, count( $this->db->ledger ), 'one usage row lands' );
		$meta = json_decode( (string) $this->db->ledger[1]['meta'], true );
		$this->assert_same( 'custom-host', (string) $meta['provider'], 'provenance: the provider id is recorded' );
		$this->assert_same( 100, (int) $meta['total_tokens'], 'the configured total is in the meta' );
	}

	private function missing_paths_fall_back_to_openai_shaped_defaults(): void {
		$this->fresh( activated: true );
		$this->queue_http( [
			'match'  => '/llm/chat',
			'status' => 200,
			'body'   => wp_json_encode( [
				'choices' => [ [ 'message' => [ 'content' => 'پیش‌فرض' ] ] ],
				'usage'   => [ 'prompt_tokens' => 1, 'completion_tokens' => 2, 'total_tokens' => 3 ],
			] ),
		] );

		$result = $this->adapter->run( $this->request( reference: 'defaults' ) );
		$this->assert_true( $result->ok, 'the run succeeds' );
		$this->assert_same( 'پیش‌فرض', $result->content, 'an unset content path defaults to choices.0.message.content' );
		$this->assert_same( 3, $result->usage['total_tokens'], 'an unset usage path defaults to usage.total_tokens' );
	}

	private function tool_calls_are_never_invented(): void {
		$this->fresh( activated: true );
		$this->queue_http( [
			'match'  => '/llm/chat',
			'status' => 200,
			'body'   => wp_json_encode( [
				'choices' => [ [ 'message' => [ 'content' => '', 'tool_calls' => [ [ 'function' => [ 'name' => 'shell_exec', 'arguments' => '{}' ] ] ] ] ] ],
			] ),
		] );

		$result = $this->adapter->run( $this->request( tools: [ 'product_search' ], reference: 'tools' ) );
		$this->assert_true( $result->ok, 'the run succeeds' );
		$this->assert_same( [], $result->tool_calls, 'the custom envelope has no tool wire; nothing is invented' );
	}

	private function the_model_allowlist_is_enforced(): void {
		$this->fresh( activated: true );
		$result = $this->adapter->run( $this->request( model: 'openai/gpt-9' ) );
		$this->assert_false( $result->ok, 'a model outside the pinned list is refused' );
		$this->assert_same( 'model_not_allowed', $result->error, 'the refusal names the allowlist' );
		$this->assert_same( 0, count( $this->http_requests() ), 'no traffic for a refused model' );
	}

	// --------------------------------------------------------------- setup

	/** @param array<string,mixed> $overrides */
	private function fresh( bool $activated = false, array $overrides = [] ): void {
		igbz_test_reset_settings();
		$this->db = new AiLedgerDb();
		$GLOBALS['wpdb'] = $this->db;

		$logger = igbz()->get( 'logger' );
		$record = ProviderDefinition::seed_defaults()[1]; // openrouter, reshaped below
		$record = array_merge( $record, [
			'id'              => 'custom-host',
			'title'           => 'Custom Host',
			'protocol'        => ProviderDefinition::PROTOCOL_CUSTOM,
			'base_url'        => 'https://example.com/llm',
			'request_method'  => 'POST',
			'request_path'    => 'chat',
			'model_allowlist' => [ 'acme/custom-1' ],
			'default_model'   => 'acme/custom-1',
		] );
		if ( $activated ) {
			$record['enabled'] = true;
			$record['benchmark_passed'] = true;
			$record['geo_eligible'] = true;
		}
		$record = array_merge( $record, $overrides );

		$this->vault   = new KeyVault( igbz()->settings() );
		$this->adapter = new CustomProtocolAdapter(
			ProviderDefinition::from_array( $record ),
			$this->vault,
			new Http( $logger ),
			new Db(),
			$logger,
			new AiToolbox()
		);
	}

	private function request(
		string $key = 'custom-test-key',
		string $model = 'acme/custom-1',
		array $tools = [ 'product_search', 'insight_read' ],
		int $max_tokens = 256,
		int $timeout = 60,
		string $reference = 'custom-run'
	): AiRequest {
		return new AiRequest(
			tenant_id: 1,
			user_id: 7,
			api_key: $key,
			model: $model,
			system: 'You are the growth assistant.',
			messages: [ [ 'role' => 'user', 'content' => 'پیشنهاد کپشن برای پست جدید' ] ],
			tools: $tools,
			max_tokens: $max_tokens,
			timeout: $timeout,
			reference: $reference
		);
	}

	private function queue_http( array $response ): void {
		igbz_test_queue_http( $response );
	}

	/** @return array<int,array<string,mixed>> */
	private function http_requests(): array {
		return $GLOBALS['igbz_test_http_requests'];
	}
}
