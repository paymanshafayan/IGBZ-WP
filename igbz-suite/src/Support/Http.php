<?php
namespace IGBZ\Suite\Support;

defined( 'ABSPATH' ) || exit;

/**
 * wp_remote_* wrapper with JSON encoding/decoding, retries with exponential backoff for
 * idempotent verbs, and automatic redaction in the log trail.
 */
final class Http {

	public function __construct( private Logger $logger ) {}

	/**
	 * @param array<string,mixed> $args
	 * @return HttpResponse
	 */
	public function request( string $method, string $url, array $args = [] ): HttpResponse {
		// Phase 10: every outbound request passes the SSRF gate. A blocked URL never
		// reaches the transport and is written to the security channel.
		if ( ! UrlGuard::is_safe( $url ) ) {
			$this->logger->log( Logger::WARNING, 'security', sprintf( '%s blocked by URL guard: %s', strtoupper( $method ), self::scrub_url( $url ) ) );
			return new HttpResponse( 0, [], '', 'Blocked by the SSRF guard.' );
		}

		$method  = strtoupper( $method );
		$headers = (array) ( $args['headers'] ?? [] );
		$timeout = (int) ( $args['timeout'] ?? 20 );
		$retries = (int) ( $args['retries'] ?? ( in_array( $method, [ 'GET', 'HEAD' ], true ) ? 2 : 0 ) );
		$channel = (string) ( $args['channel'] ?? 'http' );

		$body = $args['json'] ?? null;
		if ( null !== $body ) {
			$headers['Content-Type'] = 'application/json';
			$body                    = wp_json_encode( $body );
		} elseif ( isset( $args['body'] ) ) {
			$body = $args['body'];
		}

		$attempt = 0;
		$last    = null;
		do {
			$response = wp_remote_request(
				$url,
				[
					'method'  => $method,
					'headers' => $headers,
					'body'    => $body,
					'timeout' => $timeout,
					'sslverify' => true,
					// Server-to-server calls must not wander: each redirect hop would skip
					// the SSRF gate, so redirects are off here (IPG pages stay browser-side).
					'redirection' => 0,
					// A runaway response cannot hold the worker hostage; callers may raise the
					// cap for big artifacts (theme zips) via `max_bytes`.
					'limit_response_size' => (int) ( $args['max_bytes'] ?? 20 * 1024 * 1024 ),
				]
			);

			if ( is_wp_error( $response ) ) {
				$last = new HttpResponse( 0, [], '', $response->get_error_message() );
			} else {
				$code = (int) wp_remote_retrieve_response_code( $response );
				$last = new HttpResponse(
					$code,
					self::headers_of( $response ),
					(string) wp_remote_retrieve_body( $response ),
					$code >= 400 ? sprintf( 'HTTP %d', $code ) : null
				);
				if ( $code < 500 ) {
					break;
				}
			}
			$attempt++;
			if ( $attempt <= $retries ) {
				usleep( (int) ( 250000 * ( 2 ** ( $attempt - 1 ) ) ) );
			}
		} while ( $attempt <= $retries );

		$this->logger->log(
			$last->ok() ? Logger::DEBUG : Logger::WARNING,
			$channel,
			sprintf( '%s %s -> %d', $method, self::scrub_url( $url ), $last->status ),
			[ 'error' => $last->error, 'body' => mb_substr( $last->body, 0, 500 ) ]
		);

		return $last;
	}

	/** @param array<string,mixed> $args */
	public function get( string $url, array $args = [] ): HttpResponse {
		return $this->request( 'GET', $url, $args );
	}

	/** @param array<string,mixed> $args */
	public function post( string $url, array $args = [] ): HttpResponse {
		return $this->request( 'POST', $url, $args );
	}

	/**
	 * Response headers as a plain array.
	 *
	 * wp_remote_retrieve_headers() normally hands back a Requests_Utility_CaseInsensitiveDictionary,
	 * but only because WP_Http built the response. A `pre_http_request` short-circuit — used by
	 * caching plugins, request mockers and offline test harnesses — returns whatever array the
	 * filter chose, and core does not normalise it. Calling ->getAll() on that is a fatal error,
	 * so the shape is checked rather than assumed.
	 *
	 * @param array<string,mixed>|mixed $response
	 * @return array<string,mixed>
	 */
	private static function headers_of( $response ): array {
		$headers = wp_remote_retrieve_headers( $response );

		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			return (array) $headers->getAll();
		}
		if ( is_object( $headers ) && $headers instanceof \Traversable ) {
			return iterator_to_array( $headers );
		}

		return is_array( $headers ) ? $headers : [];
	}

	private static function scrub_url( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! isset( $parts['query'] ) ) {
			return $url;
		}
		parse_str( $parts['query'], $query );
		foreach ( array_keys( $query ) as $key ) {
			if ( preg_match( '/key|token|secret|signature/i', (string) $key ) ) {
				$query[ $key ] = Crypto::MASK;
			}
		}
		return ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . ( $parts['path'] ?? '' ) . '?' . http_build_query( $query );
	}
}
