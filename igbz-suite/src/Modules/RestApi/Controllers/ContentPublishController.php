<?php
namespace IGBZ\Suite\Modules\RestApi\Controllers;

use IGBZ\Suite\Modules\Instagram\Services\ContentPublishService;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 53 — the store-facing publishing surface.
 *
 *   GET  /igbz/v1/ig/content               the content queue + publishing report
 *   GET  /igbz/v1/ig/content/{id}          one row
 *   POST /igbz/v1/ig/content/{id}/publish  publish now (time selection: "now")
 *   POST /igbz/v1/ig/content/{id}/schedule { at }  publish later (time selection)
 *   POST /igbz/v1/ig/content/{id}/retry    retry a failed row (capped, reconciles first)
 *   GET  /igbz/v1/ig/publish/events        the publishing event ledger
 *   POST /igbz/v1/zernio/posts             the provider's post lifecycle webhook
 *
 * Every content route is owner-scoped: a store can only ever touch its own rows.
 * The webhook is self-authenticating: the per-profile HMAC inside the replay
 * window is the authentication (no JWT), exactly as the phase-51 inbox webhook.
 * The engine (`ig.content_publish`) is what does the work; these routes only carry
 * the operator's intention.
 */
final class ContentPublishController extends BaseController {

	public function register_routes(): void {
		$base = '/ig/content';

		register_rest_route( self::NAMESPACE, $base, $this->route( 'GET', [ $this, 'list' ], [ $this, 'can_manage_tenant' ], [
			'args' => [
				'status' => [ 'type' => 'string', 'required' => false, 'default' => '' ],
				'limit'  => [ 'type' => 'integer', 'required' => false, 'default' => 50 ],
			],
		] ) );
		register_rest_route( self::NAMESPACE, $base . '/(?P<id>\d+)', $this->route( 'GET', [ $this, 'get' ], [ $this, 'can_manage_tenant' ] ) );
		register_rest_route( self::NAMESPACE, $base . '/(?P<id>\d+)/publish', $this->route( 'POST', [ $this, 'publish' ], [ $this, 'can_manage_tenant' ] ) );
		register_rest_route( self::NAMESPACE, $base . '/(?P<id>\d+)/schedule', $this->route( 'POST', [ $this, 'schedule' ], [ $this, 'can_manage_tenant' ], [
			'args' => [
				'at' => [ 'type' => 'string', 'required' => true ],
			],
		] ) );
		register_rest_route( self::NAMESPACE, $base . '/(?P<id>\d+)/retry', $this->route( 'POST', [ $this, 'retry' ], [ $this, 'can_manage_tenant' ] ) );

		register_rest_route( self::NAMESPACE, '/ig/publish/events', $this->route( 'GET', [ $this, 'events' ], [ $this, 'can_manage_tenant' ], [
			'args' => [
				'limit' => [ 'type' => 'integer', 'required' => false, 'default' => 50 ],
			],
		] ) );

		// The webhook: no JWT — the signature is the authentication.
		register_rest_route(
			self::NAMESPACE,
			'/zernio/posts',
			$this->route( 'POST', [ $this, 'webhook' ] )
		);
	}

	/** @return \WP_REST_Response */
	public function list( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		if ( $tenant <= 0 ) {
			return $this->fail( 'no_tenant', __( 'No store is bound to this session.', 'igbz-suite' ), 404 );
		}

		/** @var ContentPublishService $publisher */
		$publisher = igbz()->get( 'ig.content_publish' );

		return $this->ok(
			[
				'ok'       => true,
				'tenant'   => $tenant,
				'content'  => array_map( [ $this, 'sanitize' ], $publisher->list_content( $tenant, (string) $request->get_param( 'status' ), (int) $request->get_param( 'limit' ) ) ),
			]
		);
	}

	/** @return \WP_REST_Response */
	public function get( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		if ( $tenant <= 0 ) {
			return $this->fail( 'no_tenant', __( 'No store is bound to this session.', 'igbz-suite' ), 404 );
		}

		/** @var ContentPublishService $publisher */
		$publisher = igbz()->get( 'ig.content_publish' );
		$row       = $publisher->get( $tenant, (int) $request->get_param( 'id' ) );

		if ( null === $row ) {
			return $this->fail( 'not_found', __( 'Content not found.', 'igbz-suite' ), 404 );
		}

		return $this->ok( [ 'ok' => true, 'content' => $this->sanitize( $row ) ] );
	}

	/** @return \WP_REST_Response */
	public function publish( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		if ( $tenant <= 0 ) {
			return $this->fail( 'no_tenant', __( 'No store is bound to this session.', 'igbz-suite' ), 404 );
		}

		/** @var ContentPublishService $publisher */
		$publisher = igbz()->get( 'ig.content_publish' );
		$result    = $publisher->publish_now( $tenant, (int) $request->get_param( 'id' ) );

		if ( empty( $result['ok'] ) ) {
			return $this->fail( (string) $result['error'], __( 'The post could not be published.', 'igbz-suite' ), 409 );
		}

		return $this->ok( [ 'ok' => true, 'id' => (int) $result['id'], 'status' => (string) $result['status'] ] );
	}

	/** @return \WP_REST_Response */
	public function schedule( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		if ( $tenant <= 0 ) {
			return $this->fail( 'no_tenant', __( 'No store is bound to this session.', 'igbz-suite' ), 404 );
		}

		/** @var ContentPublishService $publisher */
		$publisher = igbz()->get( 'ig.content_publish' );
		$result    = $publisher->schedule( $tenant, (int) $request->get_param( 'id' ), (string) $request->get_param( 'at' ) );

		if ( empty( $result['ok'] ) ) {
			return $this->fail( (string) $result['error'], __( 'The post could not be scheduled.', 'igbz-suite' ), 409 );
		}

		return $this->ok( [ 'ok' => true, 'id' => (int) $result['id'], 'status' => (string) $result['status'] ] );
	}

	/** @return \WP_REST_Response */
	public function retry( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		if ( $tenant <= 0 ) {
			return $this->fail( 'no_tenant', __( 'No store is bound to this session.', 'igbz-suite' ), 404 );
		}

		/** @var ContentPublishService $publisher */
		$publisher = igbz()->get( 'ig.content_publish' );
		$result    = $publisher->retry( $tenant, (int) $request->get_param( 'id' ) );

		if ( empty( $result['ok'] ) ) {
			return $this->fail( (string) $result['error'], __( 'The post could not be retried.', 'igbz-suite' ), 409 );
		}

		return $this->ok( [ 'ok' => true, 'id' => (int) $result['id'], 'status' => (string) $result['status'] ] );
	}

	/** @return \WP_REST_Response */
	public function events( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		if ( $tenant <= 0 ) {
			return $this->fail( 'no_tenant', __( 'No store is bound to this session.', 'igbz-suite' ), 404 );
		}

		/** @var ContentPublishService $publisher */
		$publisher = igbz()->get( 'ig.content_publish' );

		return $this->ok(
			[
				'ok'     => true,
				'tenant' => $tenant,
				'events' => $publisher->list_events( $tenant, (int) $request->get_param( 'limit' ) ),
			]
		);
	}

	/** @return \WP_REST_Response */
	public function webhook( \WP_REST_Request $request ) {
		$raw = (string) $request->get_body();

		/** @var ContentPublishService $publisher */
		$publisher = igbz()->get( 'ig.content_publish' );
		$result    = $publisher->handle_post_event(
			$raw,
			[
				'X-Zernio-Signature' => (string) $request->get_header( 'X-Zernio-Signature' ),
				'X-Zernio-Timestamp' => (string) $request->get_header( 'X-Zernio-Timestamp' ),
			]
		);

		switch ( $result['status'] ) {
			case 'received':
				return $this->ok( [ 'ok' => true, 'status' => 'received', 'id' => (int) $result['id'], 'event' => (string) ( $result['event'] ?? '' ) ], 202 );
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

	/**
	 * The content row is internal machinery; the store only sees what it needs.
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	public function sanitize( array $row ): array {
		return [
			'id'               => (int) $row['id'],
			'kind'             => (string) $row['kind'],
			'title'            => (string) $row['title'],
			'caption'          => (string) $row['caption'],
			'hashtags'         => (string) $row['hashtags'],
			'media'            => (string) $row['media'],
			'product_id'       => (int) ( $row['product_id'] ?? 0 ),
			'status'           => (string) $row['status'],
			'provider'         => (string) ( $row['provider'] ?? '' ),
			'provider_status'  => (string) ( $row['provider_status'] ?? '' ),
			'scheduled_for'    => (string) ( $row['scheduled_for'] ?? '' ),
			'published_at'     => (string) ( $row['published_at'] ?? '' ),
			'permalink'        => (string) ( $row['permalink'] ?? '' ),
			'last_error'       => (string) ( $row['last_error'] ?? '' ),
			'retry_count'      => (int) ( $row['retry_count'] ?? 0 ),
			'created_at'       => (string) ( $row['created_at'] ?? '' ),
			'updated_at'       => (string) ( $row['updated_at'] ?? '' ),
		];
	}
}
