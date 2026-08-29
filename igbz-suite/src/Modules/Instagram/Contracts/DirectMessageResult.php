<?php
namespace IGBZ\Suite\Modules\Instagram\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * The outcome of one direct-message send.
 *
 * Four states rather than two, because "it did not send" is not one thing and the caller has to
 * react differently to each. A paid post that failed to arrive is a refund conversation, so the
 * distinction is worth carrying in the type.
 *
 *   ok            the gateway accepted it
 *   unsupported   this gateway cannot send this kind of message at all — some vendors and video, say.
 *                 Retrying is pointless; the caller should fall back to another delivery route.
 *   window_closed the 24-hour window has expired. Also pointless to retry now, but it becomes
 *                 possible again the moment the subscriber interacts, so the caller should park
 *                 the delivery rather than abandon it.
 *   failure       anything else: a network blip, a bad key, a rate limit. Worth retrying.
 *
 * Collapsing `unsupported` into `failure` was the original design and it was wrong: the retry
 * loop would hammer an endpoint that was never going to say yes.
 */
final class DirectMessageResult {

	public const STATUS_OK            = 'ok';
	public const STATUS_UNSUPPORTED   = 'unsupported';
	public const STATUS_WINDOW_CLOSED = 'window_closed';
	public const STATUS_FAILURE       = 'failure';

	private function __construct(
		public readonly bool $ok,
		public readonly string $status,
		public readonly string $message_id,
		public readonly string $error,
		public readonly string $gateway,
		public readonly int $code
	) {}

	public static function sent( string $message_id = '', string $gateway = '' ): self {
		return new self( true, self::STATUS_OK, $message_id, '', $gateway, 0 );
	}

	public static function unsupported( string $error, string $gateway = '' ): self {
		return new self( false, self::STATUS_UNSUPPORTED, '', $error, $gateway, 0 );
	}

	public static function window_closed( string $error, string $gateway = '' ): self {
		return new self( false, self::STATUS_WINDOW_CLOSED, '', $error, $gateway, 0 );
	}

	public static function failure( string $error, string $gateway = '', int $code = 0 ): self {
		return new self( false, self::STATUS_FAILURE, '', $error, $gateway, $code );
	}

	/** Whether trying the same call again could plausibly succeed. */
	public function is_retryable(): bool {
		return self::STATUS_FAILURE === $this->status;
	}

	/** Whether another gateway might be able to do what this one could not. */
	public function needs_another_gateway(): bool {
		return self::STATUS_UNSUPPORTED === $this->status;
	}
}
