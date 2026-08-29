<?php
namespace IGBZ\Suite\Modules\RestApi\Controllers;

use IGBZ\Suite\Modules\Instagram\Services\SocialMigrationService;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 50 — the store's migration to the single social provider.
 *
 *   GET  /igbz/v1/ig/social/status   the store's journal, profile state and
 *                                    legacy-account counts (scoped tenant)
 *   POST /igbz/v1/ig/social/migrate  run one bounded, idempotent round for the
 *                                    scoped tenant right now (the hourly beat
 *                                    does the same; this is the operator's
 *                                    "do it now")
 *
 * Both are store-owner operations: a store can only ever see and run its own
 * migration. The distributed multi-tenant round stays a platform job.
 */
final class SocialMigrationController extends BaseController {

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/ig/social/status',
			$this->route( 'GET', [ $this, 'status' ], [ $this, 'can_manage_tenant' ] )
		);
		register_rest_route(
			self::NAMESPACE,
			'/ig/social/migrate',
			$this->route( 'POST', [ $this, 'migrate' ], [ $this, 'can_manage_tenant' ] )
		);
	}

	/** @return \WP_REST_Response */
	public function status( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		if ( $tenant <= 0 ) {
			return $this->fail( 'no_tenant', __( 'No store is bound to this session.', 'igbz-suite' ), 404 );
		}

		/** @var SocialMigrationService $migration */
		$migration = igbz()->get( 'ig.social_migration' );

		return $this->ok(
			[
				'ok'      => true,
				'tenant'  => $tenant,
				'state'   => $migration->status( $tenant ),
			]
		);
	}

	/** @return \WP_REST_Response */
	public function migrate( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		if ( $tenant <= 0 ) {
			return $this->fail( 'no_tenant', __( 'No store is bound to this session.', 'igbz-suite' ), 404 );
		}

		/** @var SocialMigrationService $migration */
		$migration = igbz()->get( 'ig.social_migration' );
		$result    = $migration->run_for_tenant( $tenant );

		return $this->ok(
			[
				'ok'      => true,
				'tenant'  => $tenant,
				'profile' => (string) $result['profile'],
				'legacy'  => (string) $result['legacy'],
				'state'   => $migration->status( $tenant ),
			]
		);
	}
}
