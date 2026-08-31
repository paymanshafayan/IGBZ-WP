<?php
namespace IGBZ\Suite\Modules\Pado\Ai;

use IGBZ\Suite\Support\HttpResponse;

defined( 'ABSPATH' ) || exit;

/**
 * The `custom` wire dialect (ADR-0005 §7). For a provider whose chat endpoint is not
 * OpenAI- nor Anthropic-shaped, the operator records an HTTP method, a response text
 * path and the usage field paths in the `ProviderDefinition`, and this adapter renders
 * them against a fixed prompt/messages envelope. The paths are ordinary dot-paths
 * (`choices.0.message.content`, `usage.input_tokens`), so a first-party or proxied
 * endpoint needs no code.
 *
 * Auth is a plain `Authorization` header carrying the runtime key; a prefix header is
 * emitted only when the definition's method is POST/PUT/PATCH.
 *
 * The guards, credential resolution, budget and usage ledger live in
 * AbstractProtocolAdapter; this class only renders the configured shape.
 */
final class CustomProtocolAdapter extends AbstractProtocolAdapter {

	public function endpoint(): string {
		$base = rtrim( $this->definition->https_base_url(), '/' );
		if ( '' === $base ) {
			return '';
		}
		$path = trim( $this->definition->request_path(), '/' );
		if ( '' !== $path && ! str_ends_with( $base, '/' . $path ) ) {
			return $base . '/' . $path;
		}
		return $base;
	}

	protected function transmit( AiRequest $request, string $api_key, int $max_tokens, string $endpoint ): HttpResponse {
		$method = strtoupper( $this->definition->request_method() );
		if ( ! in_array( $method, [ 'GET', 'POST', 'PUT', 'PATCH' ], true ) ) {
			$method = 'POST';
		}

		$body = [
			'model'      => $request->model,
			'max_tokens' => $max_tokens,
			'messages'   => $request->messages,
		];
		if ( '' !== $request->system ) {
			$body['system'] = $request->system;
		}

		$headers = [
			'Authorization' => 'Bearer ' . $api_key,
			'Accept'        => 'application/json',
		];

		// A GET carries the envelope as query parameters — no body, no Content-Type.
		if ( 'GET' === $method ) {
			$endpoint .= ( str_contains( $endpoint, '?' ) ? '&' : '?' ) . http_build_query( $body );
			return $this->http->request(
				$method,
				$endpoint,
				[
					'headers' => $headers,
					'channel' => 'pado',
					'timeout' => $this->clamp_timeout( $request->timeout ),
					'retries' => 0,
				]
			);
		}

		$headers['Content-Type'] = 'application/json';

		return $this->http->request(
			$method,
			$endpoint,
			[
				'json'    => $body,
				'headers' => $headers,
				'channel' => 'pado',
				'timeout' => $this->clamp_timeout( $request->timeout ),
				'retries' => 0,
			]
		);
	}

	protected function parse( array $json, AiRequest $request ): AiResult {
		$content = $this->dot_path( $json, $this->definition->response_content_path() );
		$content = is_string( $content ) ? $content : '';

		$usage = null;
		$prompt_path = $this->definition->response_usage_prompt_path();
		$completion_path = $this->definition->response_usage_completion_path();
		if ( '' !== $prompt_path || '' !== $completion_path ) {
			$prompt     = (int) $this->dot_path( $json, $prompt_path );
			$completion = (int) $this->dot_path( $json, $completion_path );
			$total_path = $this->definition->response_usage_total_path();
			$total      = '' !== $total_path ? (int) $this->dot_path( $json, $total_path ) : -1;
			$usage      = $this->normalise_usage( $prompt, $completion, $total );
			if ( null !== $usage ) {
				$this->record_usage( $request, (string) ( $json['id'] ?? '' ), $usage );
			}
		}

		return new AiResult(
			ok: true,
			error: '',
			content: $content,
			usage: $usage,
			model: (string) ( $json['model'] ?? $request->model ),
			provider: $this->provider(),
			reference: '' !== $request->reference ? $request->reference : (string) ( $json['id'] ?? '' ),
			tool_calls: [] // no tool wire in the custom envelope; nothing is invented
		);
	}
}
