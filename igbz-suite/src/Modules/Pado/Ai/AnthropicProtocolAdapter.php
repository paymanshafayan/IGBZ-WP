<?php
namespace IGBZ\Suite\Modules\Pado\Ai;

use IGBZ\Suite\Support\HttpResponse;

defined( 'ABSPATH' ) || exit;

/**
 * The `anthropic` wire dialect (ADR-0005 §7). Anthropic is the notable exception that
 * does not speak OpenAI chat completions, so it gets its own adapter instead of riding
 * the openai one. Its Messages API is version-pinned by the `anthropic-version` header
 * and uses `X-Api-Key` (not a Bearer token).
 *
 * Wire shape: POST {endpoint} → /v1/messages with `X-Api-Key` and
 * `anthropic-version: 2023-06-01`; body `{model, max_tokens, system, messages}`. The
 * reply text is `content[].{type=text}.text`, usage is
 * `usage.{input_tokens,output_tokens}`, and the response id is `id`. Anthropic's
 * Messages API has no native function-calling wire, so tool calls never surface from
 * this dialect (they are empty by contract).
 *
 * The guards, credential resolution, budget and usage ledger live in
 * AbstractProtocolAdapter; this class only translates the wire.
 */
final class AnthropicProtocolAdapter extends AbstractProtocolAdapter {

	public const ANTHROPIC_VERSION = '2023-06-01';

	public function endpoint(): string {
		$base = rtrim( $this->definition->https_base_url(), '/' );
		if ( '' === $base ) {
			return '';
		}
		return str_ends_with( $base, '/v1/messages' ) ? $base : $base . '/v1/messages';
	}

	protected function transmit( AiRequest $request, string $api_key, int $max_tokens, string $endpoint ): HttpResponse {
		// The system prompt travels in its own top-level field, not inside messages.
		$messages = [];
		foreach ( $request->messages as $message ) {
			$messages[] = [
				'role'    => (string) $message['role'],
				'content' => (string) ( $message['content'] ?? '' ),
			];
		}

		$body = [
			'model'      => $request->model,
			'max_tokens' => $max_tokens, // required by the Messages API
			'messages'   => $messages,
		];
		if ( '' !== $request->system ) {
			$body['system'] = $request->system;
		}

		return $this->http->post(
			$endpoint,
			[
				'json'    => $body,
				'headers' => [
					'X-Api-Key'         => $api_key,
					'anthropic-version' => self::ANTHROPIC_VERSION,
					'Accept'            => 'application/json',
				],
				'channel' => 'pado',
				'timeout' => $this->clamp_timeout( $request->timeout ),
				'retries' => 0,
			]
		);
	}

	protected function parse( array $json, AiRequest $request ): AiResult {
		// content is an array of blocks; we concatenate the text ones in order.
		$text = '';
		foreach ( (array) ( $json['content'] ?? [] ) as $block ) {
			$block = (array) $block;
			if ( 'text' === (string) ( $block['type'] ?? '' ) && isset( $block['text'] ) && is_string( $block['text'] ) ) {
				$text .= $block['text'];
			}
		}

		$usage = null;
		if ( is_array( $json['usage'] ?? null ) ) {
			$usage = $this->normalise_usage(
				(int) ( $json['usage']['input_tokens'] ?? 0 ),
				(int) ( $json['usage']['output_tokens'] ?? 0 )
			);
			if ( null !== $usage ) {
				$this->record_usage( $request, (string) ( $json['id'] ?? '' ), $usage );
			}
		}

		return new AiResult(
			ok: true,
			error: '',
			content: $text,
			usage: $usage,
			model: (string) ( $json['model'] ?? $request->model ),
			provider: $this->provider(),
			reference: '' !== $request->reference ? $request->reference : (string) ( $json['id'] ?? '' ),
			tool_calls: [] // Messages API has no tool wire here; nothing is ever invented
		);
	}
}
