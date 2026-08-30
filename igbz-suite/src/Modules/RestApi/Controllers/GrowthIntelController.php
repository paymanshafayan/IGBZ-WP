<?php
namespace IGBZ\Suite\Modules\RestApi\Controllers;

use IGBZ\Suite\Modules\Instagram\Growth\CompetitorService;
use IGBZ\Suite\Modules\Instagram\Growth\GiveawayDrawService;
use IGBZ\Suite\Modules\Instagram\Growth\InsightService;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 55 — the growth-intel surface, all owner-scoped:
 *
 *   GET  /igbz/v1/ig/giveaways                     the store's giveaways
 *   POST /igbz/v1/ig/giveaways                     create (returns the seed commitment)
 *   GET  /igbz/v1/ig/giveaways/{id}                one giveaway (audit included when drawn)
 *   POST /igbz/v1/ig/giveaways/{id}/entries        record an entry (backend guards apply)
 *   GET  /igbz/v1/ig/giveaways/{id}/entries        the frozen pool, id ascending
 *   POST /igbz/v1/ig/giveaways/{id}/draw           run the auditable draw
 *   POST /igbz/v1/ig/giveaways/{id}/audit/verify   re-derive the winner from the packet
 *   POST /igbz/v1/ig/giveaways/{id}/cancel         open → cancelled
 *
 *   GET  /igbz/v1/ig/insights                      stored rows (source + provider ref kept)
 *   POST /igbz/v1/ig/insights                      record a manual metric row
 *   POST /igbz/v1/ig/insights/ingest               pull the connected account's analytics
 *
 *   GET  /igbz/v1/ig/competitors                   the manager's tracked handles
 *   POST /igbz/v1/ig/competitors                   create/update a competitor
 *   DELETE /igbz/v1/ig/competitors/{id}            remove one and its snapshots
 *   POST /igbz/v1/ig/competitors/{id}/snapshots    record/correct a timed snapshot
 *   GET  /igbz/v1/ig/competitors/{id}/snapshots    the growth history
 *
 * A store only ever sees its own rows: every call is scoped to the requesting tenant and
 * the cross-tenant rows simply do not exist for it.
 */
final class GrowthIntelController extends BaseController {

	public function register_routes(): void {
		$perm = [ $this, 'can_manage_tenant' ];

		$g = '/ig/giveaways';
		register_rest_route( self::NAMESPACE, $g, $this->route( 'GET', [ $this, 'giveaways' ], $perm, [
			'args' => [
				'status' => [ 'type' => 'string', 'required' => false, 'default' => '' ],
				'limit'  => [ 'type' => 'integer', 'required' => false, 'default' => 50 ],
			],
		] ) );
		register_rest_route( self::NAMESPACE, $g, $this->route( 'POST', [ $this, 'create_giveaway' ], $perm ) );
		register_rest_route( self::NAMESPACE, $g . '/(?P<id>\d+)', $this->route( 'GET', [ $this, 'get_giveaway' ], $perm ) );
		register_rest_route( self::NAMESPACE, $g . '/(?P<id>\d+)/entries', $this->route( 'POST', [ $this, 'add_entry' ], $perm ) );
		register_rest_route( self::NAMESPACE, $g . '/(?P<id>\d+)/entries', $this->route( 'GET', [ $this, 'list_entries' ], $perm ) );
		register_rest_route( self::NAMESPACE, $g . '/(?P<id>\d+)/draw', $this->route( 'POST', [ $this, 'draw' ], $perm ) );
		register_rest_route( self::NAMESPACE, $g . '/(?P<id>\d+)/audit/verify', $this->route( 'POST', [ $this, 'verify' ], $perm ) );
		register_rest_route( self::NAMESPACE, $g . '/(?P<id>\d+)/cancel', $this->route( 'POST', [ $this, 'cancel' ], $perm ) );

		$i = '/ig/insights';
		register_rest_route( self::NAMESPACE, $i, $this->route( 'GET', [ $this, 'insights' ], $perm, [
			'args' => [
				'account_id' => [ 'type' => 'integer', 'required' => false, 'default' => 0 ],
				'metric'     => [ 'type' => 'string', 'required' => false, 'default' => '' ],
				'limit'      => [ 'type' => 'integer', 'required' => false, 'default' => 200 ],
			],
		] ) );
		register_rest_route( self::NAMESPACE, $i, $this->route( 'POST', [ $this, 'record_insight' ], $perm ) );
		register_rest_route( self::NAMESPACE, $i . '/ingest', $this->route( 'POST', [ $this, 'ingest_insights' ], $perm ) );

		$c = '/ig/competitors';
		register_rest_route( self::NAMESPACE, $c, $this->route( 'GET', [ $this, 'competitors' ], $perm ) );
		register_rest_route( self::NAMESPACE, $c, $this->route( 'POST', [ $this, 'save_competitor' ], $perm ) );
		register_rest_route( self::NAMESPACE, $c . '/(?P<id>\d+)', $this->route( 'DELETE', [ $this, 'delete_competitor' ], $perm ) );
		register_rest_route( self::NAMESPACE, $c . '/(?P<id>\d+)/snapshots', $this->route( 'POST', [ $this, 'record_snapshot' ], $perm ) );
		register_rest_route( self::NAMESPACE, $c . '/(?P<id>\d+)/snapshots', $this->route( 'GET', [ $this, 'snapshots' ], $perm ) );
	}

	/** @return array<string,mixed> */
	private function giveaway_service(): GiveawayDrawService {
		return igbz()->get( 'ig.giveaways' );
	}

	// -------------------------------------------------------------- giveaways

	/** @return \WP_REST_Response */
	public function giveaways( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		if ( $tenant <= 0 ) {
			return $this->fail( 'no_tenant', __( 'No store is bound to this session.', 'igbz-suite' ), 404 );
		}
		return $this->ok( [
			'ok'        => true,
			'giveaways' => $this->giveaway_service()->list( $tenant, (string) $request->get_param( 'status' ), (int) $request->get_param( 'limit' ) ),
		] );
	}

	/** @return \WP_REST_Response */
	public function create_giveaway( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		if ( $tenant <= 0 ) {
			return $this->fail( 'no_tenant', __( 'No store is bound to this session.', 'igbz-suite' ), 404 );
		}
		$result = $this->giveaway_service()->create( $request->get_params(), $tenant );
		return $result['ok']
			? $this->ok( [ 'ok' => true, 'id' => $result['id'], 'commitment' => $result['commitment'] ], 201 )
			: $this->fail( $result['error'], __( 'The giveaway could not be created.', 'igbz-suite' ) );
	}

	/** @return \WP_REST_Response */
	public function get_giveaway( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		$row    = $this->giveaway_service()->get( $tenant, (int) $request['id'] );
		return null === $row
			? $this->fail( 'not_found', __( 'Giveaway not found.', 'igbz-suite' ), 404 )
			: $this->ok( [ 'ok' => true, 'giveaway' => $row ] );
	}

	/** @return \WP_REST_Response */
	public function add_entry( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		$result = $this->giveaway_service()->add_entry( $tenant, (int) $request['id'], $request->get_params() );
		if ( 'not_found' === $result['error'] ) {
			return $this->fail( 'not_found', __( 'Giveaway not found.', 'igbz-suite' ), 404 );
		}
		return $result['ok']
			? $this->ok( [ 'ok' => true, 'id' => $result['id'] ], 201 )
			: $this->fail( $result['error'], __( 'The entry was refused.', 'igbz-suite' ) );
	}

	/** @return \WP_REST_Response */
	public function list_entries( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		$service = $this->giveaway_service();
		if ( null === $service->get( $tenant, (int) $request['id'] ) ) {
			return $this->fail( 'not_found', __( 'Giveaway not found.', 'igbz-suite' ), 404 );
		}
		return $this->ok( [ 'ok' => true, 'entries' => $service->entries( $tenant, (int) $request['id'] ) ] );
	}

	/** @return \WP_REST_Response */
	public function draw( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		$result = $this->giveaway_service()->draw( $tenant, (int) $request['id'] );
		if ( 'not_found' === $result['error'] ) {
			return $this->fail( 'not_found', __( 'Giveaway not found.', 'igbz-suite' ), 404 );
		}
		return $result['ok']
			? $this->ok( [ 'ok' => true, 'winner' => $result['winner'], 'winner_no' => $result['winner_no'], 'winner_entry_id' => $result['winner_entry_id'], 'audit' => $result['audit'] ] )
			: $this->fail( $result['error'], __( 'The draw was refused.', 'igbz-suite' ), 'no_entries' === $result['error'] ? 409 : 400 );
	}

	/** @return \WP_REST_Response */
	public function verify( \WP_REST_Request $request ) {
		$tenant  = $this->scoped_tenant_id( $request );
		$service = $this->giveaway_service();
		$row     = $service->get( $tenant, (int) $request['id'] );
		if ( null === $row || null === ( $row['audit'] ?? null ) ) {
			return $this->fail( 'not_found', __( 'No drawn giveaway here.', 'igbz-suite' ), 404 );
		}

		$pool   = $service->entries( $tenant, (int) $request['id'] );
		$result = GiveawayDrawService::verify_audit( (array) $row['audit'], $pool );

		return $this->ok( [ 'ok' => $result['ok'], 'code' => $result['error'], 'winner_no' => $result['winner_no'], 'winner_entry_id' => $result['winner_entry_id'] ], $result['ok'] ? 200 : 409 );
	}

	/** @return \WP_REST_Response */
	public function cancel( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		$result = $this->giveaway_service()->cancel( $tenant, (int) $request['id'] );
		return $result['ok']
			? $this->ok( [ 'ok' => true ] )
			: $this->fail( $result['error'], __( 'The giveaway is not open.', 'igbz-suite' ), 409 );
	}

	// --------------------------------------------------------------- insights

	/** @return \WP_REST_Response */
	public function insights( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		if ( $tenant <= 0 ) {
			return $this->fail( 'no_tenant', __( 'No store is bound to this session.', 'igbz-suite' ), 404 );
		}
		/** @var InsightService $service */
		$service = igbz()->get( 'ig.growth_insights' );
		return $this->ok( [
			'ok'       => true,
			'insights' => $service->list( $tenant, (int) $request->get_param( 'account_id' ), (string) $request->get_param( 'metric' ), (int) $request->get_param( 'limit' ) ),
		] );
	}

	/** @return \WP_REST_Response */
	public function record_insight( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		if ( $tenant <= 0 ) {
			return $this->fail( 'no_tenant', __( 'No store is bound to this session.', 'igbz-suite' ), 404 );
		}
		/** @var InsightService $service */
		$service = igbz()->get( 'ig.growth_insights' );
		$result  = $service->record( $tenant, $request->get_params() );
		return $result['ok']
			? $this->ok( [ 'ok' => true ] )
			: $this->fail( $result['error'], __( 'The metric row was refused.', 'igbz-suite' ) );
	}

	/** @return \WP_REST_Response */
	public function ingest_insights( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		if ( $tenant <= 0 ) {
			return $this->fail( 'no_tenant', __( 'No store is bound to this session.', 'igbz-suite' ), 404 );
		}
		/** @var InsightService $service */
		$service = igbz()->get( 'ig.growth_insights' );
		$result  = $service->ingest( $tenant, (string) ( $request->get_param( 'period' ) ?: '30d' ) );
		return $result['ok']
			? $this->ok( [ 'ok' => true, 'stored' => $result['stored'], 'skipped' => $result['skipped'] ] )
			: $this->fail( $result['error'], __( 'No insights were ingested.', 'igbz-suite' ), 'not_connected' === $result['error'] ? 409 : 502 );
	}

	// ------------------------------------------------------------ competitors

	/** @return \WP_REST_Response */
	public function competitors( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		if ( $tenant <= 0 ) {
			return $this->fail( 'no_tenant', __( 'No store is bound to this session.', 'igbz-suite' ), 404 );
		}
		/** @var CompetitorService $service */
		$service = igbz()->get( 'ig.competitors' );
		return $this->ok( [ 'ok' => true, 'competitors' => $service->list( $tenant ) ] );
	}

	/** @return \WP_REST_Response */
	public function save_competitor( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		if ( $tenant <= 0 ) {
			return $this->fail( 'no_tenant', __( 'No store is bound to this session.', 'igbz-suite' ), 404 );
		}
		/** @var CompetitorService $service */
		$service = igbz()->get( 'ig.competitors' );
		$result  = $service->save_competitor( $tenant, $request->get_params() );
		return $result['ok']
			? $this->ok( [ 'ok' => true, 'id' => $result['id'] ], 201 )
			: $this->fail( $result['error'], __( 'The competitor was refused.', 'igbz-suite' ) );
	}

	/** @return \WP_REST_Response */
	public function delete_competitor( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		/** @var CompetitorService $service */
		$service = igbz()->get( 'ig.competitors' );
		$result  = $service->delete( $tenant, (int) $request['id'] );
		return $result['ok']
			? $this->ok( [ 'ok' => true ] )
			: $this->fail( 'not_found', __( 'Competitor not found.', 'igbz-suite' ), 404 );
	}

	/** @return \WP_REST_Response */
	public function record_snapshot( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		/** @var CompetitorService $service */
		$service = igbz()->get( 'ig.competitors' );
		$result  = $service->record_snapshot( $tenant, (int) $request['id'], $request->get_params() );
		if ( 'not_found' === $result['error'] ) {
			return $this->fail( 'not_found', __( 'Competitor not found.', 'igbz-suite' ), 404 );
		}
		return $result['ok']
			? $this->ok( [ 'ok' => true ] )
			: $this->fail( $result['error'], __( 'The snapshot was refused.', 'igbz-suite' ) );
	}

	/** @return \WP_REST_Response */
	public function snapshots( \WP_REST_Request $request ) {
		$tenant = $this->scoped_tenant_id( $request );
		/** @var CompetitorService $service */
		$service = igbz()->get( 'ig.competitors' );
		if ( null === $service->get( $tenant, (int) $request['id'] ) ) {
			return $this->fail( 'not_found', __( 'Competitor not found.', 'igbz-suite' ), 404 );
		}
		return $this->ok( [ 'ok' => true, 'snapshots' => $service->snapshots( $tenant, (int) $request['id'] ) ] );
	}
}
