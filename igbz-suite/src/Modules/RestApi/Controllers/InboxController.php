<?php
namespace IGBZ\Suite\Modules\RestApi\Controllers;

use IGBZ\Suite\Modules\Instagram\Services\InboxService;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 51 — the Zernio inbox surface.
 *
 *   POST /igbz/v1/zernio/inbox
 *       The provider's webhook. Self-authenticating: the per-profile HMAC
 *       signature (header X-Zernio-Signature over payload+timestamp) replaces
 *       the JWT on this route, exactly as the phase-49 connection webhook.
 *
 *   GET  /igbz/v1/ig/inbox                  the store's captured events
 *   GET  /igbz/v1/ig/inbox/actions          the delivery ledger (approve/retry live here)
 *   POST /igbz/v1/ig/inbox/approve          { action_id } — human approval -> delivery
 *   POST /igbz/v1/ig/inbox/reject           { action_id } — final refusal
 *   POST /igbz/v1/ig/inbox/retry            { action_id } — redrive a failed delivery
 *   POST /igbz/v1/ig/inbox/optout           { sender_id, sender_username? }
 *   GET  /igbz/v1/ig/inbox/rules            the store's backend rules
 *   POST /igbz/v1/ig/inbox/rules            create a rule
 *   POST /igbz/v1/ig/inbox/rules/active     { rule_id, active }
 *
 * The store routes are owner-scoped; a store only ever sees its own inbox.
 * The decision pipeline (rules, opt-out, rate limit, approval) is entirely
 * backend — the provider only receives the final, approved, idempotent send.
 */
final class InboxController extends BaseController {

	public function register_routes(): void {
		// The webhook: no JWT — the signature is the authentication.
		register_rest_route(
			self::NAMESPACE,
			'/zernio/inbox',
			$this->route( 'POST', [ $this, 'webhook' ] )
		);

		register_rest_route(
			self::NAMESPACE,
			'/ig/inbox',
			$this->route( 'GET', [ $this, 'events' ], [ $this, 'can_manage_tenant' ] )
		);
		register_rest_route(
			self::NAMESPACE,
			'/ig/inbox/actions',
			$this->route( 'GET', [ $this, 'actions' ], [ $this, 'can_manage_tenant' ] )
		);
		register_rest_route(
			self::NAMESPACE,
			'/ig/inbox/approve',
			$this->route( 'POST', [ $this, 'approve' ], [ $this, 'can_manage_tenant' ] )
		);
		register_rest_route(
			self::NAMESPACE,
			'/ig/inbox/reject',
			$this->route( 'POST', [ $this, 'reject' ], [ $this, 'can_manage_tenant' ] )
		);
		register_rest_route(
			self::NAMESPACE,
			'/ig/inbox/retry',
			$this->route( 'POST', [ $this, 'retry' ], [ $this, 'can_manage_tenant' ] )
		);
		register_rest_route(
			self::NAMESPACE,
			'/ig/inbox/optout',
			$this->route( 'POST', [ $this, 'optout' ], [ $this, 'can_manage_tenant' ] )
		);
		register_rest_route(
			self::NAMESPACE,
			'/ig/inbox/rules',
			$this->route( 'GET', [ $this, 'rules' ], [ $this, 'can_manage_tenant' ] )
		);
		register_rest_route(
			self::NAMESPACE,
			'/ig/inbox/rules',
			$this->route( 'POST', [ $this, 'create_rule' ], [ $this, 'can_manage_tenant' ] )
		);
		register_rest_route(
			self::NAMESPACE,
			'/ig/inbox/rules/active',
			$this->route( 'POST', [ $this, 'rule_active' ], [ $this, 'can_manage_tenant' ] )
		);
	}

	/** @return \WP_REST_Response */
	public function webhook( \WP_REST_Request $request ) {
		$raw = (string) $request->get_body();

		/** @var InboxService $inbox */
		$inbox  = igbz()->get( 'ig.inbox' );
		$result = $inbox->handle_webhook(
			$raw,
			[
				'X-Zernio-Signature' => (string) $request->get_header( 'X-Zernio-Signature' ),
				'X-Zernio-Timestamp' => (string) $request->get_header( 'X-Zernio-Timestamp' ),
			]
		);

		switch ( $result['status'] ) {
			case 'received':
				return $this->ok( [ 'ok' => true, 'status' => 'received', 'id' => (int) $result['id'] ], 202 );
			case 'duplicate':
				return $this->ok( [ 'ok' => true, 'status' => 'duplicate', 'id' => (int) $result['id'] ] );
			case 'bad_payload':
				return $this->fail( 'bad_payload', __( 'Malformed webhook payload.', 'igbz-suite' ), 400 );
			case 'unknown_account':
				return $this->fail( 'unknown_account', __( 'Unknown account.', 'igbz-suite' ), 404 );
			case 'invalid_signature':
				return $this->fail( 'invalid_signature', __( 'Webhook signature check failed.', 'igbz-suite' ), 401 );
		}

		return $this->fail( 'bad_payload', __( 'Malformed webhook payload.', 'igbz-suite' ), 400 );
	}

	/** @return \WP_REST_Response */
	public function events( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		if ( $tenant <= 0 ) {
			return $this->fail( 'no_tenant', __( 'No store is bound to this session.', 'igbz-suite' ), 404 );
		}

		/** @var InboxService $inbox */
		$inbox = igbz()->get( 'ig.inbox' );

		return $this->ok(
			[
				'ok'      => true,
				'tenant'  => $tenant,
				'events'  => $this->sanitize_events( $inbox->list_events( $tenant ) ),
			]
		);
	}

	/** @return \WP_REST_Response */
	public function actions( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		if ( $tenant <= 0 ) {
			return $this->fail( 'no_tenant', __( 'No store is bound to this session.', 'igbz-suite' ), 404 );
		}

		/** @var InboxService $inbox */
		$inbox = igbz()->get( 'ig.inbox' );

		return $this->ok(
			[
				'ok'       => true,
				'tenant'   => $tenant,
				'actions'  => $this->sanitize_actions( $inbox->list_actions( $tenant ) ),
			]
		);
	}

	/** @return \WP_REST_Response */
	public function approve( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		if ( $tenant <= 0 ) {
			return $this->fail( 'no_tenant', __( 'No store is bound to this session.', 'igbz-suite' ), 404 );
		}

		/** @var InboxService $inbox */
		$inbox  = igbz()->get( 'ig.inbox' );
		$result = $inbox->approve( $tenant, (int) $request->get_param( 'action_id' ) );

		return $result['ok']
			? $this->ok( [ 'ok' => true, 'action_id' => (int) $result['action_id'], 'state' => 'delivered_or_failed' ] )
			: $this->fail( (string) $result['error'], __( 'The action is not pending approval.', 'igbz-suite' ), 409 );
	}

	/** @return \WP_REST_Response */
	public function reject( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		if ( $tenant <= 0 ) {
			return $this->fail( 'no_tenant', __( 'No store is bound to this session.', 'igbz-suite' ), 404 );
		}

		/** @var InboxService $inbox */
		$inbox  = igbz()->get( 'ig.inbox' );
		$result = $inbox->reject( $tenant, (int) $request->get_param( 'action_id' ) );

		return $result['ok']
			? $this->ok( [ 'ok' => true, 'action_id' => (int) $result['action_id'], 'state' => 'rejected' ] )
			: $this->fail( (string) $result['error'], __( 'The action is not pending approval.', 'igbz-suite' ), 409 );
	}

	/** @return \WP_REST_Response */
	public function retry( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		if ( $tenant <= 0 ) {
			return $this->fail( 'no_tenant', __( 'No store is bound to this session.', 'igbz-suite' ), 404 );
		}

		/** @var InboxService $inbox */
		$inbox  = igbz()->get( 'ig.inbox' );
		$result = $inbox->retry_failed( $tenant, (int) $request->get_param( 'action_id' ) );

		return $result['ok']
			? $this->ok( [ 'ok' => true, 'action_id' => (int) $result['action_id'], 'state' => 'delivered_or_failed' ] )
			: $this->fail( (string) $result['error'], __( 'The action is not in a failed state.', 'igbz-suite' ), 409 );
	}

	/** @return \WP_REST_Response */
	public function optout( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		if ( $tenant <= 0 ) {
			return $this->fail( 'no_tenant', __( 'No store is bound to this session.', 'igbz-suite' ), 404 );
		}

		$sender_id = sanitize_text_field( (string) $request->get_param( 'sender_id' ) );
		if ( '' === $sender_id ) {
			return $this->fail( 'bad_sender', __( 'The sender id is required.', 'igbz-suite' ), 400 );
		}

		/** @var InboxService $inbox */
		$inbox = igbz()->get( 'ig.inbox' );
		$inbox->add_opt_out( $tenant, $sender_id, (string) $request->get_param( 'sender_username' ), 'manual' );

		return $this->ok( [ 'ok' => true, 'sender_id' => $sender_id ] );
	}

	/** @return \WP_REST_Response */
	public function rules( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		if ( $tenant <= 0 ) {
			return $this->fail( 'no_tenant', __( 'No store is bound to this session.', 'igbz-suite' ), 404 );
		}

		/** @var InboxService $inbox */
		$inbox = igbz()->get( 'ig.inbox' );

		return $this->ok( [ 'ok' => true, 'tenant' => $tenant, 'rules' => $inbox->list_rules( $tenant ) ] );
	}

	/** @return \WP_REST_Response */
	public function create_rule( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		if ( $tenant <= 0 ) {
			return $this->fail( 'no_tenant', __( 'No store is bound to this session.', 'igbz-suite' ), 404 );
		}

		/** @var InboxService $inbox */
		$inbox  = igbz()->get( 'ig.inbox' );
		$result = $inbox->create_rule(
			$tenant,
			[
				'name'     => (string) $request->get_param( 'name' ),
				'source'   => (string) $request->get_param( 'source' ),
				'keyword'  => (string) $request->get_param( 'keyword' ),
				'action'   => (string) $request->get_param( 'action' ),
				'template' => (string) $request->get_param( 'template' ),
				'priority' => (int) ( $request->get_param( 'priority' ) ?? 100 ),
			]
		);

		return $result['ok']
			? $this->ok( [ 'ok' => true, 'rule_id' => (int) $result['rule_id'] ] )
			: $this->fail( (string) $result['error'], __( 'The rule is invalid.', 'igbz-suite' ), 400 );
	}

	/** @return \WP_REST_Response */
	public function rule_active( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		if ( $tenant <= 0 ) {
			return $this->fail( 'no_tenant', __( 'No store is bound to this session.', 'igbz-suite' ), 404 );
		}

		/** @var InboxService $inbox */
		$inbox = igbz()->get( 'ig.inbox' );
		$inbox->set_rule_active( $tenant, (int) $request->get_param( 'rule_id' ), (bool) $request->get_param( 'active' ) );

		return $this->ok( [ 'ok' => true, 'rule_id' => (int) $request->get_param( 'rule_id' ) ] );
	}

	/** @param array<int,array<string,mixed>> $rows */
	private function sanitize_events( array $rows ): array {
		return array_map(
			static fn ( $r ) => [
				'id'        => (int) $r['id'],
				'source'    => (string) $r['source'],
				'event'     => (string) $r['event'],
				'post_id'   => (string) $r['post_id'],
				'sender'    => (string) $r['sender_username'],
				'text'      => (string) $r['text'],
				'status'    => (string) $r['status'],
				'received'  => (string) $r['received_at'],
			],
			$rows
		);
	}

	/** @param array<int,array<string,mixed>> $rows */
	private function sanitize_actions( array $rows ): array {
		return array_map(
			static fn ( $r ) => [
				'id'            => (int) $r['id'],
				'inbox_id'      => (int) $r['inbox_id'],
				'kind'          => (string) $r['kind'],
				'text'          => (string) $r['text'],
				'state'         => (string) $r['state'],
				'error'         => (string) $r['error'],
				'provider_ref'  => (string) $r['provider_ref'],
				'created'       => (string) $r['created_at'],
				'delivered_at'  => null === $r['delivered_at'] ? null : (string) $r['delivered_at'],
			],
			$rows
		);
	}
}
