<?php
namespace IGBZ\Suite\Modules\Instagram\Speech;

use IGBZ\Suite\Modules\Instagram\Contracts\SpeechToTextInterface;
use IGBZ\Suite\Modules\Instagram\Contracts\TranscriptionResult;
use IGBZ\Suite\Support\Logger;
use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Generic multipart speech-to-text client.
 *
 * Deliberately vendor-neutral. Whisper (OpenAI or self-hosted), Groq, Deepgram and the Iranian
 * providers all speak the same basic dialect — POST a multipart body containing the audio file,
 * get JSON back — and differ only in the endpoint, the name of the file field, whether a model
 * has to be named and where the text sits in the response. All four of those are settings, so
 * switching provider is a configuration change rather than a code change.
 *
 * The response path is searched rather than hard-coded: `stt.response_path` accepts a dotted path
 * ('results.0.alternatives.0.transcript'), and when it is empty the usual keys are tried in turn.
 * That is what lets one class cover providers nobody has thought of yet.
 */
final class HttpSpeechToText implements SpeechToTextInterface {

	public const ID = 'http';

	/** Keys checked, in order, when no explicit response path is configured. */
	private const COMMON_PATHS = [ 'text', 'transcript', 'transcription', 'result', 'data.text', 'data.transcript', 'results.0.text' ];

	public function __construct( private Settings $settings, private Logger $logger ) {}

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Custom speech-to-text endpoint', 'igbz-suite' );
	}

	public function is_configured(): bool {
		return '' !== $this->endpoint();
	}

	private function endpoint(): string {
		return trim( $this->settings->string( 'stt.endpoint', '' ) );
	}

	public function transcribe( string $path, string $language = '', array $context = [] ): TranscriptionResult {
		unset( $context );

		if ( ! $this->is_configured() ) {
			return TranscriptionResult::failure( __( 'No speech-to-text endpoint is configured.', 'igbz-suite' ), self::ID );
		}
		if ( ! is_readable( $path ) ) {
			return TranscriptionResult::failure( __( 'The audio file could not be read.', 'igbz-suite' ), self::ID );
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents ) {
			return TranscriptionResult::failure( __( 'The audio file could not be read.', 'igbz-suite' ), self::ID );
		}

		$fields = [
			'model'           => $this->settings->string( 'stt.model', '' ),
			'language'        => '' !== $language ? $language : $this->settings->string( 'stt.language', '' ),
			'response_format' => 'json',
		];

		/**
		 * Extra multipart fields for providers with their own required parameters.
		 *
		 * @param array<string,string> $fields
		 */
		$fields = (array) apply_filters( 'igbz_stt_fields', array_filter( $fields, static fn ( $v ): bool => '' !== (string) $v ), $path, $language );

		$boundary = 'igbz' . bin2hex( random_bytes( 12 ) );
		$body     = $this->multipart( $boundary, $fields, $this->settings->string( 'stt.file_field', 'file' ), basename( $path ), $contents );

		$headers = [ 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ];
		$key     = (string) $this->settings->get( 'stt.api_key', '' );
		if ( '' !== $key ) {
			$header = $this->settings->string( 'stt.auth_header', 'Authorization' );
			$scheme = $this->settings->string( 'stt.auth_scheme', 'Bearer' );
			// A blank scheme means "send the key bare", which is what X-API-KEY style headers want.
			$headers[ $header ] = '' === trim( $scheme ) ? $key : trim( $scheme ) . ' ' . $key;
		}

		// Deliberately not routed through Http::post(): that helper JSON-encodes or passes the
		// body straight through and the multipart Content-Type must carry the exact boundary
		// used to build the body, so the request is assembled here in full. The SSRF gate the
		// wrapper would apply still has to run, though.
		if ( ! \IGBZ\Suite\Support\UrlGuard::is_safe( $this->endpoint() ) ) {
			return TranscriptionResult::failure( 'Blocked by the SSRF guard.', self::ID );
		}

		$response = wp_remote_post(
			$this->endpoint(),
			[
				'headers'   => $headers,
				'body'      => $body,
				'timeout'   => max( 30, $this->settings->int( 'stt.timeout', 120 ) ),
				'sslverify' => true,
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->logger->error( 'stt', 'Transcription request failed', [ 'error' => $response->get_error_message() ] );
			return TranscriptionResult::failure( $response->get_error_message(), self::ID );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );

		if ( $status < 200 || $status >= 300 ) {
			$this->logger->error( 'stt', 'Transcription rejected', [ 'status' => $status, 'body' => mb_substr( $raw, 0, 300 ) ] );
			return TranscriptionResult::failure(
				sprintf( /* translators: %d: HTTP status */ __( 'The speech-to-text service answered HTTP %d.', 'igbz-suite' ), $status ),
				self::ID
			);
		}

		$text = $this->extract( $raw );
		if ( '' === $text ) {
			$this->logger->warning( 'stt', 'Transcription returned no text', [ 'body' => mb_substr( $raw, 0, 300 ) ] );
			return TranscriptionResult::failure( __( 'The speech-to-text service returned no text.', 'igbz-suite' ), self::ID );
		}

		return TranscriptionResult::done( $text, self::ID );
	}

	/** Pull the transcript out of whatever envelope the provider chose. */
	private function extract( string $raw ): string {
		$decoded = json_decode( $raw, true );

		// A provider that answers with bare text rather than JSON is still perfectly usable.
		if ( ! is_array( $decoded ) ) {
			$trimmed = trim( $raw );
			return ( '' !== $trimmed && ! str_starts_with( $trimmed, '{' ) && ! str_starts_with( $trimmed, '[' ) ) ? $trimmed : '';
		}

		$configured = trim( $this->settings->string( 'stt.response_path', '' ) );
		$paths      = '' !== $configured ? array_merge( [ $configured ], self::COMMON_PATHS ) : self::COMMON_PATHS;

		foreach ( $paths as $path ) {
			$value = $this->dig( $decoded, $path );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}

		return '';
	}

	/** @param array<string,mixed> $data */
	private function dig( array $data, string $path ): mixed {
		$cursor = $data;
		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $cursor ) || ! array_key_exists( $segment, $cursor ) ) {
				return null;
			}
			$cursor = $cursor[ $segment ];
		}
		return $cursor;
	}

	/** @param array<string,string> $fields */
	private function multipart( string $boundary, array $fields, string $file_field, string $filename, string $contents ): string {
		$eol  = "\r\n";
		$body = '';

		foreach ( $fields as $name => $value ) {
			$body .= '--' . $boundary . $eol;
			$body .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
			$body .= $value . $eol;
		}

		$body .= '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="' . $file_field . '"; filename="' . $filename . '"' . $eol;
		$body .= 'Content-Type: ' . ( $this->mime( $filename ) ) . $eol . $eol;
		$body .= $contents . $eol;
		$body .= '--' . $boundary . '--' . $eol;

		return $body;
	}

	private function mime( string $filename ): string {
		$type = wp_check_filetype( $filename );
		return '' !== (string) ( $type['type'] ?? '' ) ? (string) $type['type'] : 'application/octet-stream';
	}
}
