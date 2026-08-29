<?php
namespace IGBZ\Suite\Modules\Instagram\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * The outcome of one speech-to-text attempt.
 *
 * Three states rather than two, because some engines are asynchronous: they answer with a task
 * id now and a transcript some minutes later. Collapsing that into a plain "failed" would make
 * the app tell the user to type it out by hand while the transcription was still on its way, so
 * `pending` is a first-class result and the intake row parks in the transcribing state until the
 * webhook or the cron poll settles it.
 */
final class TranscriptionResult {

	private function __construct(
		public readonly bool $ok,
		public readonly string $text,
		public readonly string $task_id,
		public readonly string $error,
		public readonly string $engine
	) {}

	public static function done( string $text, string $engine = '' ): self {
		return new self( true, $text, '', '', $engine );
	}

	public static function pending( string $task_id, string $engine = '' ): self {
		return new self( false, '', $task_id, '', $engine );
	}

	public static function failure( string $error, string $engine = '' ): self {
		return new self( false, '', '', $error, $engine );
	}

	public function is_pending(): bool {
		return ! $this->ok && '' !== $this->task_id;
	}

	public function failed(): bool {
		return ! $this->ok && '' === $this->task_id;
	}
}
