<?php
namespace IGBZ\Suite\Modules\RestApi\Controllers;

defined( 'ABSPATH' ) || exit;

/**
 * Domain + web presence + i18n + master payment REST (store admin).
 *
 *   GET  /igbz/v1/domains
 *   POST /igbz/v1/domains/search          { q, tld }
 *   POST /igbz/v1/domains/register        { name, tld }
 *   POST /igbz/v1/domains/subdomain       { slug }
 *   POST /igbz/v1/domains/{id}/verify-dns
 *   GET  /igbz/v1/domains/web-presence
 *   POST /igbz/v1/domains/web-presence/register
 *   GET  /igbz/v1/i18n/config
 *   POST /igbz/v1/master-payment/agreement
 *   GET  /igbz/v1/master-payment
 */
final class DomainController extends BaseController {

	public function register_routes(): void {
		$ns   = self::NAMESPACE;
		$auth = [ $this, 'can_manage_tenant' ];

		register_rest_route( $ns, '/domains', $this->route( 'GET', [ $this, 'domains' ], $auth ) );
		register_rest_route( $ns, '/domains/search', $this->route( 'POST', [ $this, 'search' ], $auth ) );
		register_rest_route( $ns, '/domains/register', $this->route( 'POST', [ $this, 'register_domain' ], $auth ) );
		register_rest_route( $ns, '/domains/transfer', $this->route( 'POST', [ $this, 'transfer' ], $auth ) );
		register_rest_route( $ns, '/domains/subdomain', $this->route( 'POST', [ $this, 'subdomain' ], $auth ) );
		register_rest_route( $ns, '/domains/(?P<id>\\d+)/verify-dns', $this->route( 'POST', [ $this, 'verify_dns' ], $auth ) );
		register_rest_route( $ns, '/domains/web-presence', $this->route( 'GET', [ $this, 'web_presence' ], $auth ) );
		register_rest_route( $ns, '/domains/web-presence/register', $this->route( 'POST', [ $this, 'web_register' ], $auth ) );
		register_rest_route( $ns, '/i18n/config', $this->route( 'GET', [ $this, 'i18n' ], $auth ) );
		register_rest_route( $ns, '/legal/waiver', $this->route( 'GET', [ $this, 'legal_waiver' ], $auth ) );
		register_rest_route( $ns, '/legal/waiver/accept', $this->route( 'POST', [ $this, 'accept_legal_waiver' ], $auth ) );
		register_rest_route( $ns, '/master-payment', $this->route( 'GET', [ $this, 'master' ], $auth ) );
		register_rest_route( $ns, '/master-payment/agreement', $this->route( 'POST', [ $this, 'master_agree' ], $auth ) );
		register_rest_route( $ns, '/master-payment/withdraw', $this->route( 'POST', [ $this, 'master_withdraw' ], $auth ) );
	}

	private function tenant(): int {
		return $this->scoped_tenant_id();
	}

	public function domains(): \WP_REST_Response {
		return $this->ok( [ 'items' => igbz()->get( 'domain' )->domains( $this->tenant() ) ] );
	}

	public function search( \WP_REST_Request $request ): \WP_REST_Response {
		$result = igbz()->get( 'domain' )->search(
			sanitize_title( (string) $request->get_param( 'q' ) ),
			sanitize_key( (string) $request->get_param( 'tld' ) ?: 'ir' )
		);
		return $result['ok'] ? $this->ok( [ 'results' => $result['results'] ] ) : $this->fail( 'search_failed', $result['error'] );
	}

	public function register_domain( \WP_REST_Request $request ): \WP_REST_Response {
		$result = igbz()->get( 'domain' )->register(
			$this->tenant(),
			sanitize_title( (string) $request->get_param( 'name' ) ),
			sanitize_key( (string) $request->get_param( 'tld' ) ?: 'ir' )
		);
		return $result['ok'] ? $this->ok( [ 'ok' => true, 'domain_id' => $result['domain_id'] ], 201 ) : $this->fail( 'register_failed', $result['error'] );
	}

	public function transfer( \WP_REST_Request $request ): \WP_REST_Response {
		$result = igbz()->get( 'domain' )->transfer(
			$this->tenant(),
			sanitize_text_field( (string) $request->get_param( 'name' ) ),
			sanitize_text_field( (string) $request->get_param( 'auth_code' ) )
		);
		return $result['ok'] ? $this->ok( [ 'ok' => true, 'domain_id' => $result['domain_id'] ], 201 ) : $this->fail( 'transfer_failed', $result['error'] );
	}

	public function subdomain( \WP_REST_Request $request ): \WP_REST_Response {
		$result = igbz()->get( 'domain' )->use_subdomain( $this->tenant(), sanitize_title( (string) $request->get_param( 'slug' ) ) );
		return $result['ok'] ? $this->ok( [ 'ok' => true, 'domain_id' => $result['domain_id'] ], 201 ) : $this->fail( 'failed', $result['error'] );
	}

	public function verify_dns( \WP_REST_Request $request ): \WP_REST_Response {
		$ok = igbz()->get( 'domain' )->verify_dns( (int) $request->get_param( 'id' ) );
		return $ok ? $this->ok( [ 'ok' => true ] ) : $this->fail( 'dns_not_verified', __( 'The required DNS record was not found.', 'igbz-suite' ), 400 );
	}

	public function legal_waiver(): \WP_REST_Response {
		if ( ! igbz()->has( 'legal.waiver' ) ) {
			return $this->fail( 'not_available', __( 'Legal agreement service is not available.', 'igbz-suite' ), 503 );
		}
		$waiver = igbz()->get( 'legal.waiver' );
		return $this->ok(
			[
				'tenant_id' => $this->tenant(),
				'type'      => \IGBZ\Suite\Modules\MultiTenant\Payments\LegalWaiverService::TYPE_PAYMENT_WITHOUT_NID,
				'version'   => \IGBZ\Suite\Modules\MultiTenant\Payments\LegalWaiverService::CURRENT_VERSION,
				'text'      => $waiver->text(),
				'accepted'  => $waiver->has_accepted( $this->tenant() ),
				'hash'      => $waiver->current_hash(),
			]
		);
	}

	public function accept_legal_waiver(): \WP_REST_Response {
		if ( ! igbz()->has( 'legal.waiver' ) ) {
			return $this->fail( 'not_available', __( 'Legal agreement service is not available.', 'igbz-suite' ), 503 );
		}
		$result = igbz()->get( 'legal.waiver' )->accept( $this->tenant(), get_current_user_id() );
		return $result['ok'] ? $this->ok( [ 'accepted' => true ] ) : $this->fail( 'accept_failed', $result['error'], 400 );
	}

	public function web_presence(): \WP_REST_Response {
		return $this->ok( [ 'items' => igbz()->get( 'webpresence' )->status( $this->tenant() ) ] );
	}

	public function web_register(): \WP_REST_Response {
		$domain = igbz()->get( 'domain' )->domains( $this->tenant() );
		$verified = null;
		foreach ( $domain as $d ) {
			if ( (int) $d['dns_verified'] ) {
				$verified = (string) $d['name'];
				break;
			}
		}
		if ( ! $verified ) {
			return $this->fail( 'no_verified_domain', __( 'Verify a standalone domain first.', 'igbz-suite' ), 400 );
		}
		$result = igbz()->get( 'webpresence' )->register( $this->tenant(), $verified );
		return $this->ok( [ 'ok' => true, 'results' => $result['results'] ] );
	}

	public function i18n(): \WP_REST_Response {
		$i18n = igbz()->get( 'i18n' );
		return $this->ok(
			[
				'enabled'          => $i18n->is_enabled(),
				'languages'        => $i18n->languages(),
				'default_language' => $i18n->default_language(),
			]
		);
	}

	public function master(): \WP_REST_Response {
		$master = igbz()->get( 'master.payment' );
		return $this->ok(
			[
				'agreement' => $master->has_agreement( $this->tenant() ),
				'payments'  => $master->payments( $this->tenant(), 20 ),
				'disputes'  => $master->disputes( $this->tenant(), 20 ),
			]
		);
	}

	public function master_withdraw( \WP_REST_Request $request ): \WP_REST_Response {
		$result = igbz()->get( 'master.payment' )->request_withdrawal(
			$this->tenant(),
			get_current_user_id(),
			(float) $request->get_param( 'amount' ),
			sanitize_key( (string) $request->get_param( 'method' ) ?: 'card' ),
			sanitize_text_field( (string) $request->get_param( 'detail' ) )
		);
		return $result['ok'] ? $this->ok( [ 'ok' => true ] ) : $this->fail( 'withdraw_failed', $result['error'], 400 );
	}

	public function master_agree(): \WP_REST_Response {
		$result = igbz()->get( 'master.payment' )->accept_agreement( $this->tenant(), get_current_user_id() );
		return $result['ok'] ? $this->ok( [ 'ok' => true ] ) : $this->fail( 'failed', $result['error'] );
	}
}
