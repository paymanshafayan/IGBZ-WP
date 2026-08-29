<?php
namespace IGBZ\Suite\Modules\RestApi\Controllers;

use IGBZ\Suite\Modules\Instagram\Services\ProductRegistrationService;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 52 — the app-facing surface of the 13-step product registration.
 *
 *   POST /igbz/v1/ig/product-registrations            start (idempotent on client_token)
 *   GET  /igbz/v1/ig/product-registrations/{id}       the row, including its checkpoint
 *   POST /igbz/v1/ig/product-registrations/{id}/start_grading
 *   POST /igbz/v1/ig/product-registrations/{id}/complete_grading    { pass, reason? }
 *   POST /igbz/v1/ig/product-registrations/{id}/manual_grade        { pass, reason? }
 *   POST /igbz/v1/ig/product-registrations/{id}/start_image
 *   POST /igbz/v1/ig/product-registrations/{id}/complete_image      { url }
 *   POST /igbz/v1/ig/product-registrations/{id}/manual_prepared_image { url? }
 *   POST /igbz/v1/ig/product-registrations/{id}/mark_edited
 *   POST /igbz/v1/ig/product-registrations/{id}/start_describing
 *   POST /igbz/v1/ig/product-registrations/{id}/complete_transcription { text }
 *   POST /igbz/v1/ig/product-registrations/{id}/manual_transcription  { text }
 *   POST /igbz/v1/ig/product-registrations/{id}/start_writing
 *   POST /igbz/v1/ig/product-registrations/{id}/complete_writing      { copy }
 *   POST /igbz/v1/ig/product-registrations/{id}/manual_copy           { copy }
 *   POST /igbz/v1/ig/product-registrations/{id}/create_product
 *   POST /igbz/v1/ig/product-registrations/{id}/await_kind
 *   POST /igbz/v1/ig/product-registrations/{id}/choose_kind           { kind }
 *   POST /igbz/v1/ig/product-registrations/{id}/complete_compose      { post }
 *   POST /igbz/v1/ig/product-registrations/{id}/manual_composed       { caption, media_url? }
 *   POST /igbz/v1/ig/product-registrations/{id}/approve
 *   POST /igbz/v1/ig/product-registrations/{id}/reject                { reason? }
 *   POST /igbz/v1/ig/product-registrations/{id}/retry
 *   POST /igbz/v1/ig/product-registrations/{id}/compensate
 *
 * Every route is owner-scoped: a store can only ever touch its own registrations.
 * The machine decides what is legal; these routes only carry the operator's
 * intention to the right checkpoint.
 */
final class ProductRegistrationController extends BaseController {

	public function register_routes(): void {
		$base = '/ig/product-registrations';

		register_rest_route( self::NAMESPACE, $base, $this->route( 'POST', [ $this, 'start' ], [ $this, 'can_manage_tenant' ] ) );
		register_rest_route( self::NAMESPACE, $base . '/(?P<id>\d+)', $this->route( 'GET', [ $this, 'get' ], [ $this, 'can_manage_tenant' ] ) );

		foreach (
			[
				'start_grading',
				'complete_grading',
				'manual_grade',
				'start_image',
				'complete_image',
				'manual_prepared_image',
				'mark_edited',
				'start_describing',
				'complete_transcription',
				'manual_transcription',
				'start_writing',
				'complete_writing',
				'manual_copy',
				'create_product',
				'await_kind',
				'choose_kind',
				'complete_compose',
				'manual_composed',
				'approve',
				'reject',
				'retry',
				'compensate',
			] as $action
		) {
			register_rest_route( self::NAMESPACE, $base . '/(?P<id>\d+)' . '/' . $action, $this->route( 'POST', [ $this, 'step' ], [ $this, 'can_manage_tenant' ] ) );
		}
	}

	/** @return \WP_REST_Response */
	public function start( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		if ( $tenant <= 0 ) {
			return $this->fail( 'no_tenant', __( 'No store is bound to this session.', 'igbz-suite' ), 404 );
		}

		/** @var ProductRegistrationService $reg */
		$reg = igbz()->get( 'ig.product_registration' );

		$result = $reg->start(
			$tenant,
			[
				'client_token' => (string) $request->get_param( 'client_token' ),
				'input_type'   => (string) $request->get_param( 'input_type' ),
				'image_url'    => (string) $request->get_param( 'image_url' ),
				'voice_url'    => (string) $request->get_param( 'voice_url' ),
				'account_id'   => (int) ( $request->get_param( 'account_id' ) ?? 0 ),
			]
		);

		return $this->respond( $result, 'start' );
	}

	/** @return \WP_REST_Response */
	public function get( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		if ( $tenant <= 0 ) {
			return $this->fail( 'no_tenant', __( 'No store is bound to this session.', 'igbz-suite' ), 404 );
		}

		/** @var ProductRegistrationService $reg */
		$reg   = igbz()->get( 'ig.product_registration' );
		$row   = $reg->get( $tenant, (int) $request->get_param( 'id' ) );

		if ( null === $row ) {
			return $this->fail( 'not_found', __( 'Registration not found.', 'igbz-suite' ), 404 );
		}

		return $this->ok( [ 'ok' => true, 'registration' => $this->sanitize( $row ) ] );
	}

	/** @return \WP_REST_Response */
	public function step( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		if ( $tenant <= 0 ) {
			return $this->fail( 'no_tenant', __( 'No store is bound to this session.', 'igbz-suite' ), 404 );
		}

		$id     = (int) $request->get_param( 'id' );
		$action = sanitize_key( $this->action_from_route( $request ) );

		/** @var ProductRegistrationService $reg */
		$reg = igbz()->get( 'ig.product_registration' );
		$p   = $request->get_params();

		switch ( $action ) {
			case 'start_grading':
				$result = $reg->start_grading( $tenant, $id );
				break;
			case 'complete_grading':
				$result = $reg->complete_grading( $tenant, $id, $this->assoc( $p, 'result' ) + $p );
				break;
			case 'manual_grade':
				$result = $reg->manual_grade( $tenant, $id, (bool) ( $p['pass'] ?? false ), (string) ( $p['reason'] ?? '' ) );
				break;
			case 'start_image':
				$result = $reg->start_image( $tenant, $id );
				break;
			case 'complete_image':
				$result = $reg->complete_image( $tenant, $id, (string) ( $p['url'] ?? '' ) );
				break;
			case 'manual_prepared_image':
				$result = $reg->manual_prepared_image( $tenant, $id, (string) ( $p['url'] ?? '' ) );
				break;
			case 'mark_edited':
				$result = $reg->mark_edited( $tenant, $id );
				break;
			case 'start_describing':
				$result = $reg->start_describing( $tenant, $id );
				break;
			case 'complete_transcription':
				$result = $reg->complete_transcription( $tenant, $id, (string) ( $p['text'] ?? '' ) );
				break;
			case 'manual_transcription':
				$result = $reg->manual_transcription( $tenant, $id, (string) ( $p['text'] ?? '' ) );
				break;
			case 'start_writing':
				$result = $reg->start_writing( $tenant, $id );
				break;
			case 'complete_writing':
				$result = $reg->complete_writing( $tenant, $id, $this->assoc( $p, 'copy' ) );
				break;
			case 'manual_copy':
				$result = $reg->manual_copy( $tenant, $id, $this->assoc( $p, 'copy' ) );
				break;
			case 'create_product':
				$result = $reg->create_product( $tenant, $id );
				break;
			case 'await_kind':
				$result = $reg->await_kind( $tenant, $id );
				break;
			case 'choose_kind':
				$result = $reg->choose_kind( $tenant, $id, (string) ( $p['kind'] ?? '' ) );
				break;
			case 'complete_compose':
				$post = $this->assoc( $p, 'post' );
				if ( '' === trim( (string) ( $post['caption'] ?? '' ) ) ) {
					$post['caption'] = (string) ( $p['caption'] ?? '' );
				}
				$result = $reg->complete_compose( $tenant, $id, $post );
				break;
			case 'manual_composed':
				$result = $reg->manual_composed( $tenant, $id, (string) ( $p['caption'] ?? '' ), (string) ( $p['media_url'] ?? '' ) );
				break;
			case 'approve':
				$result = $reg->approve( $tenant, $id, (int) get_current_user_id() );
				break;
			case 'reject':
				$result = $reg->reject( $tenant, $id, (int) get_current_user_id(), (string) ( $p['reason'] ?? '' ) );
				break;
			case 'retry':
				$result = $reg->retry( $tenant, $id );
				break;
			case 'compensate':
				$result = $reg->compensate( $tenant, $id );
				break;
			default:
				return $this->fail( 'unknown_action', __( 'Unknown registration step.', 'igbz-suite' ), 400 );
		}

		return $this->respond( $result, $action );
	}

	/**
	 * @param array<string,mixed> $result
	 */
	private function respond( array $result, string $action ): \WP_REST_Response {
		if ( empty( $result['ok'] ) ) {
			$error = (string) $result['error'];
			if ( in_array( $error, [ 'not_found' ], true ) ) {
				return $this->fail( $error, __( 'Registration not found.', 'igbz-suite' ), 404 );
			}
			if ( str_starts_with( $error, 'invalid_state_' ) ) {
				return $this->fail( $error, __( 'That step is not possible at the current checkpoint.', 'igbz-suite' ), 409 );
			}
			return $this->fail( $error, __( 'The step could not be completed.', 'igbz-suite' ), 422 );
		}

		return $this->ok(
			[
				'ok'     => true,
				'id'     => (int) $result['id'],
				'status' => (string) $result['status'],
			],
			'duplicate' === (string) $result['status'] ? 200 : 201
		);
	}

	/**
	 * The action name is the last path segment of the request route.
	 */
	private function action_from_route( \WP_REST_Request $request ): string {
		$path   = (string) $request->get_route();
		$parts  = explode( '/', trim( $path, '/' ) );

		return (string) end( $parts );
	}

	/** @return array<string,mixed> */
	private function assoc( array $params, string $key ): array {
		$value = $params[ $key ] ?? [];

		return is_array( $value ) ? $value : [];
	}

	/** @param array<string,mixed> $row */
	private function sanitize( array $row ): array {
		$copy = json_decode( (string) $row['copy_json'], true );

		return [
			'id'                 => (int) $row['id'],
			'status'             => (string) $row['status'],
			'input_type'         => (string) $row['input_type'],
			'kind'               => (string) $row['kind'],
			'image_url'          => (string) $row['image_url'],
			'image_prepared_url' => (string) $row['image_prepared_url'],
			'voice_url'          => (string) $row['voice_url'],
			'transcription'      => (string) $row['transcription'],
			'copy'               => is_array( $copy ) ? $copy : [],
			'product_id'         => (int) $row['product_id'],
			'content_id'         => (int) $row['content_id'],
			'public_code'        => (string) $row['public_code'],
			'stage'              => (string) $row['stage'],
			'stage_task'         => (string) $row['stage_task'],
			'approved_by'        => (int) $row['approved_by'],
			'approved_at'        => null === $row['approved_at'] ? null : (string) $row['approved_at'],
			'error'              => (string) $row['error'],
			'attempts'           => (int) $row['attempts'],
			'created_at'         => (string) $row['created_at'],
			'updated_at'         => (string) $row['updated_at'],
		];
	}
}
