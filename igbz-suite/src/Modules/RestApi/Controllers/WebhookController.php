<?php
namespace IGBZ\Suite\Modules\RestApi\Controllers;

use IGBZ\Suite\Support\Webhooks\WebhookInbox;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 29 — the provider notification surface.
 *
 *   POST /igbz/v1/webhook/{source}     raw body + X-Webhook-Id + X-Webhook-Signature headers
 *
 * Capture is deliberately dumb and fast: the raw body lands in the durable inbox and the caller
 * gets 200 immediately. Signature verification is recorded on the event (HMAC-SHA256 over the
 * raw body with the per-source secret) and enforced at processing time — an invalid delivery is
 * stored as evidence but never dispatched. When the provider sends no event id, the content hash
 * doubles as the deduplication key: an identical body can never be processed twice.
 */
final class WebhookController extends BaseController {

	public function __construct( private WebhookInbox $inbox ) {}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/webhook/(?P<source>[a-z0-9_-]+)',
			$this->route( 'POST', [ $this, 'receive' ] )
		);
	}

	/** @return \WP_REST_Response */
	public function receive( \WP_REST_Request $request ) {
		$source = sanitize_key( (string) $request->get_param( 'source' ) );
		$raw    = (string) $request->get_body();

		$event_key = trim( (string) $request->get_header( 'X-Webhook-Id' ) );
		if ( '' === $event_key ) {
			$event_key = hash( 'sha256', $raw );
		}

		$signature = trim( (string) $request->get_header( 'X-Webhook-Signature' ) );
		$sig_state = $this->inbox->verify_signature( $source, $raw, $signature )
			? WebhookInbox::SIG_VALID
			: WebhookInbox::SIG_INVALID;

		$stored = $this->inbox->receive( $source, $event_key, $raw, 0, $sig_state );

		return rest_ensure_response(
			[
				'ok'        => true,
				'status'    => $stored['status'],
				'id'        => $stored['id'],
				'signature' => $sig_state,
			]
		);
	}
}
