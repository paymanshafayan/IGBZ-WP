<?php
namespace IGBZ\Suite\Modules\RestApi\Controllers;

use IGBZ\Suite\Modules\Fx\FxTopupService;
use IGBZ\Suite\Modules\Fx\FxWalletService;

defined( 'ABSPATH' ) || exit;

/**
 * The store owner's FX wallet from the mobile app.
 *
 *   GET  /igbz/v1/fx/balance
 *   POST /igbz/v1/fx/topup        { usd, gateway? }
 *   GET  /igbz/v1/fx/ledger        ?page=&per_page=
 *   GET  /igbz/v1/fx/prices
 *   GET  /igbz/v1/fx/bills
 */
final class FxController extends BaseController {

	public function register_routes(): void {
		$ns   = self::NAMESPACE;
		$auth = [ $this, 'can_manage_tenant' ];

		register_rest_route( $ns, '/fx/balance', $this->route( 'GET', [ $this, 'balance' ], $auth ) );
		register_rest_route( $ns, '/fx/topup', $this->route( 'POST', [ $this, 'topup' ], $auth ) );
		register_rest_route( $ns, '/fx/ledger', $this->route( 'GET', [ $this, 'ledger' ], $auth ) );
		register_rest_route( $ns, '/fx/prices', $this->route( 'GET', [ $this, 'prices' ], $auth ) );
		register_rest_route( $ns, '/fx/bills', $this->route( 'GET', [ $this, 'bills' ], $auth ) );

		// Payout provider webhooks. The shared-secret check is the permission boundary: this
		// route is reachable without a user session, so it must not use the tenant auth.
		register_rest_route(
			$ns,
			'/fx/payout-webhook/(?P<provider>[a-z]+)',
			$this->route( 'POST', [ $this, 'payout_webhook' ], [ $this, 'check_webhook_token' ] )
		);
	}

	private function wallet(): FxWalletService {
		return igbz()->get( 'fx.wallet' );
	}

	public function balance(): \WP_REST_Response {
		$tenant = $this->scoped_tenant_id();

		return $this->ok(
			[
				'balance_usd' => $this->wallet()->balance( $tenant )['balance_usd'],
				'fee_percent' => igbz()->settings()->float( 'fx.fee_percent', 10 ),
				'rate_irt'    => igbz()->get( 'fx.rates' )->current(),
			]
		);
	}

	public function topup( \WP_REST_Request $request ): \WP_REST_Response {
		$tenant = $this->scoped_tenant_id( $request );
		if ( $tenant <= 0 ) {
			return $this->fail( 'no_tenant', __( 'No tenant is associated with this account.', 'igbz-suite' ), 403 );
		}

		$usd     = (float) $request->get_param( 'usd' );
		$gateway = $request->get_param( 'gateway' ) ? sanitize_key( (string) $request->get_param( 'gateway' ) ) : '';

		if ( $usd <= 0 ) {
			return $this->fail( 'invalid_amount', __( 'Enter a positive USD amount.', 'igbz-suite' ) );
		}

		/** @var FxTopupService $topup */
		$topup  = igbz()->get( 'fx.topup' );
		$result = $topup->start( $tenant, get_current_user_id(), $usd, $gateway );

		if ( ! $result['ok'] ) {
			return $this->fail( 'topup_failed', $result['error'] );
		}

		return $this->ok(
			[
				'ok'           => true,
				'payment_id'   => $result['payment_id'],
				'redirect_url' => $result['redirect_url'],
				'amount_irt'   => $result['amount_irt'],
				'gross_usd'    => $result['gross_usd'],
				'net_usd'      => $result['net_usd'],
			],
			201
		);
	}

	public function ledger( \WP_REST_Request $request ): \WP_REST_Response {
		$tenant = $this->scoped_tenant_id( $request );
		[ $page, $per_page, $offset ] = $this->page_args( $request, 20 );

		$rows = $this->wallet()->ledger( $tenant, $per_page, $offset );

		return $this->paged( $rows, $this->ledger_count( $tenant ), $page, $per_page );
	}

	private function ledger_count( int $tenant ): int {
		return (int) igbz()->db()->scalar(
			'SELECT COUNT(*) FROM ' . igbz()->db()->table( 'fx_ledger' ) . ' WHERE tenant_id = %d',
			$tenant
		);
	}

	public function prices(): \WP_REST_Response {
		$db    = igbz()->db();
		$rows  = $db->results( 'SELECT service, price_usd, is_active FROM ' . $db->table( 'fx_prices' ) . ' ORDER BY id LIMIT 500' ); // Phase 20: bounded catalog list.

		return $this->ok( [ 'items' => $rows ] );
	}

	public function bills( \WP_REST_Request $request ): \WP_REST_Response {
		$tenant = $this->scoped_tenant_id( $request );

		$rows = igbz()->db()->results(
			'SELECT id, fx_account_id, period_start, period_end, amount_usd, status, paid_at
			   FROM ' . igbz()->db()->table( 'fx_bills' ) . '
			  WHERE tenant_id = %d ORDER BY id DESC LIMIT 50',
			$tenant
		);

		return $this->ok( [ 'items' => $rows ] );
	}

	/**
	 * Verify the shared secret for payout webhooks. The token is
	 * `fx.webhook_token` (a secret the operator generates), sent as
	 * `?token=`, an `X-IGBZ-Token` header, or `Authorization: Bearer`.
	 */
	public function check_webhook_token( \WP_REST_Request $request ): bool|\WP_Error {
		$token = trim( (string) $request->get_param( 'token' ) );
		if ( '' === $token ) {
			$token = trim( (string) $request->get_header( 'X-IGBZ-Token' ) );
		}
		if ( '' === $token ) {
			$auth = trim( (string) $request->get_header( 'Authorization' ) );
			if ( str_starts_with( $auth, 'Bearer ' ) ) {
				$token = trim( substr( $auth, 7 ) );
			}
		}

		$expected = (string) igbz()->settings()->string( 'fx.webhook_token', '' );
		if ( '' === $expected || ! hash_equals( $expected, $token ) ) {
			return new \WP_Error( 'igbz_fx_bad_token', __( 'Invalid webhook token.', 'igbz-suite' ), [ 'status' => 401 ] );
		}

		return true;
	}

	public function payout_webhook( \WP_REST_Request $request ): \WP_REST_Response {
		$provider = sanitize_key( (string) $request->get_param( 'provider' ) );

		/** @var \IGBZ\Suite\Modules\Fx\FxPayoutRegistry $payouts */
		$payouts = igbz()->get( 'fx.payouts' );
		$adapter = $payouts->get( $provider );
		if ( ! $adapter ) {
			return $this->fail( 'unknown_provider', __( 'Unknown payout provider.', 'igbz-suite' ), 404 );
		}

		$payload = $request->get_json_params();
		$adapter->webhook( is_array( $payload ) ? $payload : [] );

		return $this->ok( [ 'ok' => true ] );
	}
}
