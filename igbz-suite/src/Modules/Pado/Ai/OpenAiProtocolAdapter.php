<?php
namespace IGBZ\Suite\Modules\Pado\Ai;

use IGBZ\Suite\Support\HttpResponse;

defined( 'ABSPATH' ) || exit;

/**
 * The `openai` wire dialect (ADR-0005 §7). One adapter serves every host that speaks
 * OpenAI-compatible chat completions — Groq and OpenRouter both do — with the provider
 * identity supplied by its `ProviderDefinition`, never hardcoded here.
 *
 * Wire shape: POST {endpoint} with `Authorization: Bearer <key>`, body
 * `{model, messages, max_tokens, tools}`; the response is read from
 * `choices[0].message.content`, tool calls from `choices[0].message.tool_calls[].function`,
 * and usage from `usage.{prompt_tokens,completion_tokens,total_tokens,estimated_cost}`.
 *
 * The guards, credential resolution, budget and usage ledger live in
 * AbstractProtocolAdapter; this class only translates the wire.
 */
final class OpenAiProtocolAdapter extends AbstractProtocolAdapter {

	public function endpoint(): string {
		$base = rtrim( $this->definition->https_base_url(), '/' );
		if ( '' === $base ) {
			return '';
		}
		return str_ends_with( $base, '/chat/completions' ) ? $base : $base . '/chat/completions';
	}

	protected function transmit( AiRequest $request, string $api_key, int $max_tokens, string $endpoint ): HttpResponse {
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

		return $this->http->post(
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
	}

	protected function parse( array $json, AiRequest $request ): AiResult {
		$choice  = (array) ( $json['choices'][0] ?? [] );
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
			$usage = $this->normalise_usage(
				(int) ( $json['usage']['prompt_tokens'] ?? 0 ),
				(int) ( $json['usage']['completion_tokens'] ?? 0 ),
				(int) ( $json['usage']['total_tokens'] ?? -1 ),
				(float) ( $json['usage']['estimated_cost'] ?? 0.0 )
			);
			if ( null !== $usage ) {
				$this->record_usage( $request, (string) ( $json['id'] ?? '' ), $usage );
			}
		}

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
}
