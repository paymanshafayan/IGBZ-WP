<?php
namespace IGBZ\Suite\Modules\Instagram\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * A service that can put a message into an Instagram subscriber's direct messages.
 *
 * This interface exists because the paid-post feature — the one the business runs on — depends on
 * a capability no single vendor reliably provides. The plugin must never call graph.instagram.com
 * itself: talking to Meta directly is outside what this project is allowed to do, so every
 * gateway here is a vendor that holds its own Meta app and its own tokens. Phase 50 pins that
 * vendor to the single social provider (Zernio official OAuth); the gateway rebuilt on it lands
 * with the inbox (phase 51).
 *
 * Hence: capability is queried, not assumed. `supports()` lets the delivery service pick a
 * gateway that can actually do the job, and every send returns a DirectMessageResult that
 * distinguishes "this gateway can't" from "this failed".
 *
 * ## On the 24-hour window
 *
 * Every implementation is bound by it. Meta only permits a message when the subscriber opened the
 * conversation within the last 24 hours — by messaging, commenting, or replying to a story. There
 * is no cold send and no gateway can grant one. So delivery is always a *response*, which is why
 * the paid-post funnel ends with a button the buyer taps: the tap re-opens the window and the
 * content follows. Treat a `window_closed` result as "park it until they come back", never as an
 * error to show the buyer.
 */
interface DirectMessageGatewayInterface {

	/** A published post the account owns, rendered as a native card in the thread. */
	public const CAP_MEDIA_SHARE = 'media_share';

	/** An arbitrary video file, sent by URL. */
	public const CAP_VIDEO = 'video';

	/** An arbitrary image file, sent by URL. */
	public const CAP_IMAGE = 'image';

	/** Plain text, optionally carrying one URL button. */
	public const CAP_TEXT = 'text';

	/** Run a pre-built automation the vendor stores on its own side. */
	public const CAP_FLOW = 'flow';

	/** Stable identifier used by the `dm.provider` setting and by the account overrides. */
	public function id(): string;

	/** Human-readable name for the settings screen and the health check. */
	public function title(): string;

	/**
	 * Whether this gateway has everything it needs to be called for the given account.
	 *
	 * @param array<string,mixed> $account An `ig_accounts` row, or [] for the site-wide default.
	 */
	public function is_configured( array $account = [] ): bool;

	/**
	 * Whether this gateway can send a given kind of message.
	 *
	 * Implementations answer from their vendor's documented limits, not from an API probe: the
	 * answer has to be available before a send is attempted so the delivery service can route.
	 *
	 * @param string $capability One of the CAP_* constants.
	 */
	public function supports( string $capability ): bool;

	/**
	 * Send plain text, optionally with a single URL button.
	 *
	 * The button matters more than it looks: after a comment-triggered automation Instagram allows
	 * only one message, and a bare link in it is likely to be suppressed. A button is the
	 * compliant way to move the buyer to checkout, and their tap refreshes the 24-hour window.
	 *
	 * @param array<string,mixed> $account
	 */
	public function send_text(
		array $account,
		string $subscriber_id,
		string $text,
		string $button_label = '',
		string $button_url = ''
	): DirectMessageResult;

	/**
	 * Send an image by public URL.
	 *
	 * @param array<string,mixed> $account
	 */
	public function send_image( array $account, string $subscriber_id, string $url, string $caption = '' ): DirectMessageResult;

	/**
	 * Send a video by public URL.
	 *
	 * Note that a URL is a liability for paid content — anyone who receives it can pass it on —
	 * which is why `send_media_share()` is preferred wherever the content is already a post.
	 *
	 * @param array<string,mixed> $account
	 */
	public function send_video( array $account, string $subscriber_id, string $url, string $caption = '' ): DirectMessageResult;

	/**
	 * Send one of the account's own published posts as a native card.
	 *
	 * This is the primary delivery mechanism for paid access. Nothing is hosted by us, nothing is
	 * transcodable, and there is no forwardable link — the buyer gets the post itself, which is as
	 * close to Instagram's own Close Friends behaviour as an outside integration can get.
	 *
	 * `$media_ref` is deliberately loose. A vendor that wraps Meta wants a numeric media id; a
	 * vendor whose builder picks the post from a dropdown wants that post's permalink or its own
	 * internal handle. Implementations interpret it and say so in their docblock.
	 *
	 * @param array<string,mixed> $account
	 */
	public function send_media_share( array $account, string $subscriber_id, string $media_ref ): DirectMessageResult;

	/**
	 * Trigger a pre-built automation the vendor holds, passing fields for it to interpolate.
	 *
	 * The escape hatch, and often the best option: when the vendor's builder can do something its
	 * API cannot be told to do directly, the flow is authored there and we only name it. Delivery
	 * of a chosen feed post is exactly that case today.
	 *
	 * @param array<string,mixed>  $account
	 * @param array<string,string> $fields Custom fields to set before the flow runs.
	 */
	public function send_flow( array $account, string $subscriber_id, string $flow_ref, array $fields = [] ): DirectMessageResult;
}
