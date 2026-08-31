<?php
namespace IGBZ\Suite\Modules\Fx\Admin;

use IGBZ\Suite\Modules\Fx\FxBillingService;
use IGBZ\Suite\Modules\Fx\FxMath;
use IGBZ\Suite\Modules\Fx\FxWalletService;
use IGBZ\Suite\Support\Admin\Menu;
use IGBZ\Suite\Support\Admin\View;
use IGBZ\Suite\Support\Capabilities;
use IGBZ\Suite\Support\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * FX payments screen: top up the USD wallet with Rials, see the rate, the
 * prices the meter charges, and the wallet ledger.
 */
final class FxPage {

	public const SLUG = 'igbz-fx';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 16 );
	}

	public function add_page(): void {
		Menu::add( self::SLUG, __( 'FX payments', 'igbz-suite' ), [ $this, 'render' ], Capabilities::MANAGE_SUITE );
	}

	public function render(): void {
		$this->handle_post();

		View::open(
			__( 'FX payments', 'igbz-suite' ),
			__( 'Top up the USD credit wallet with Rials. The foreign-currency payout itself is handled by the payout adapter.', 'igbz-suite' )
		);

		$rates  = igbz()->get( 'fx.rates' );
		$wallet = igbz()->get( 'fx.wallet' );
		$tenant = (int) igbz()->tenancy()->id();
		$rate   = $rates->current();

		echo '<div class="igbz-cards">';
		printf( '<div class="igbz-card"><strong>%1$s</strong><span>%2$s</span></div>', esc_html( number_format( $rate, 0 ) ), esc_html__( 'IRT per USD', 'igbz-suite' ) );
		printf( '<div class="igbz-card"><strong>%1$s</strong><span>%2$s</span></div>', esc_html( number_format( $wallet->balance( $tenant )['balance_usd'], 2 ) ), esc_html__( 'USD credit', 'igbz-suite' ) );
		printf( '<div class="igbz-card"><strong>%1$s%%</strong><span>%2$s</span></div>', esc_html( (string) igbz()->settings()->float( 'fx.fee_percent', 10 ) ), esc_html__( 'Top-up fee', 'igbz-suite' ) );
		echo '</div>';

		$this->render_topup_form( $rate, $tenant );
		$this->render_prices();
		$this->render_ramp();
		$this->render_reports();
		$this->render_accounts( $tenant );
		$this->render_bills( $tenant );
		$this->render_ledger( $tenant );

		View::close();
	}

	private function render_topup_form( float $rate, int $tenant ): void {
		$payments = igbz()->has( 'payments' ) ? igbz()->get( 'payments' ) : null;
		$gateways = $payments ? $payments->enabled_gateways() : [];

		echo '<h2>' . esc_html__( 'Top up', 'igbz-suite' ) . '</h2>';

		if ( ! $payments ) {
			echo '<p>' . esc_html__( 'Enable the Multi-Tenant Stores module to charge with the Iranian gateways.', 'igbz-suite' ) . '</p>';
			return;
		}
		if ( $rate <= 0 ) {
			echo '<p>' . esc_html__( 'Set the exchange rate first: IGBZ → Settings → FX payments.', 'igbz-suite' ) . '</p>';
			return;
		}

		echo '<form method="post" style="max-width:420px">';
		wp_nonce_field( 'igbz_fx_topup' );
		printf( '<input type="hidden" name="igbz_fx_action" value="topup" />' );

		$fee = (float) igbz()->settings()->float( 'fx.fee_percent', 10 );
		$sample = FxMath::quote( 10, $fee, $rate );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row"><label for="igbz_fx_usd">' . esc_html__( 'USD amount', 'igbz-suite' ) . '</label></th><td>';
		printf( '<input type="number" id="igbz_fx_usd" name="usd" min="0.01" step="0.01" value="10" class="small-text" required />' );
		printf( ' <span class="description">%s</span>', esc_html( sprintf( '10 USD costs %s IRT incl. %s%% fee — you get 10.00 USD credit.', number_format( $sample['amount_irt'], 0 ), number_format( $fee, 0 ) ) ) );
		echo '</td></tr>';

		if ( count( $gateways ) > 1 ) {
			echo '<tr><th scope="row"><label for="igbz_fx_gateway">' . esc_html__( 'Gateway', 'igbz-suite' ) . '</label></th><td><select id="igbz_fx_gateway" name="gateway">';
			foreach ( $gateways as $gateway ) {
				printf( '<option value="%1$s">%2$s</option>', esc_attr( $gateway->id() ), esc_html( $gateway->title() ) );
			}
			echo '</select></td></tr>';
		}

		echo '</tbody></table>';
		submit_button( __( 'Charge with Rials', 'igbz-suite' ) );
		echo '</form>';
	}

	private function render_prices(): void {
		$db   = igbz()->db();
		$rows = $db->results( 'SELECT * FROM ' . $db->table( 'fx_prices' ) . ' ORDER BY id LIMIT 500' ); // Phase 20: bounded catalog list.

		echo '<h2>' . esc_html__( 'Prices', 'igbz-suite' ) . '</h2>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No prices seeded yet.', 'igbz-suite' ) . '</p>';
			return;
		}

		echo '<form method="post" style="max-width:520px">';
		wp_nonce_field( 'igbz_fx_price' );
		printf( '<input type="hidden" name="igbz_fx_action" value="price" />' );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Service', 'igbz-suite' ) . '</th><th>' . esc_html__( 'USD', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Active', 'igbz-suite' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			printf(
				'<tr><td>%1$s</td><td><input type="number" name="price[%2$d]" min="0" step="0.01" value="%3$s" class="small-text" /></td><td>%4$s</td></tr>',
				esc_html( (string) $row['service'] ),
				(int) $row['id'],
				esc_attr( (string) $row['price_usd'] ),
				$row['is_active'] ? esc_html__( 'yes', 'igbz-suite' ) : esc_html__( 'no', 'igbz-suite' )
			);
		}
		echo '</tbody></table>';
		submit_button( __( 'Save prices', 'igbz-suite' ) );
		echo '</form>';
	}

	private function render_ramp(): void {
		echo '<h2>' . esc_html__( 'USDT on-ramp', 'igbz-suite' ) . '</h2>';

		$ramp    = igbz()->get( 'fx.ramp' );
		$price   = $ramp->usdt_price();
		$enabled = igbz()->settings()->bool( 'fx.ramp_enabled', false );

		echo '<div class="igbz-cards">';
		printf( '<div class="igbz-card"><strong>%1$s</strong><span>%2$s</span></div>', esc_html( $enabled ? __( 'on', 'igbz-suite' ) : __( 'off', 'igbz-suite' ) ), esc_html__( 'On-ramp', 'igbz-suite' ) );
		printf( '<div class="igbz-card"><strong>%1$s</strong><span>%2$s</span></div>', esc_html( $price > 0 ? number_format( $price, 0 ) : '—' ), esc_html__( 'USDT price (IRT)', 'igbz-suite' ) );

		$card = igbz()->get( 'fx.payouts' )->active();
		if ( $card && $card->is_configured() ) {
			printf( '<div class="igbz-card"><strong>%1$s</strong><span>%2$s</span></div>', esc_html( number_format( $card->card_balance(), 2 ) ), esc_html__( 'Card balance (USD)', 'igbz-suite' ) );
		}
		echo '</div>';

		echo '<form method="post" style="max-width:420px">';
		wp_nonce_field( 'igbz_fx_ramp' );
		printf( '<input type="hidden" name="igbz_fx_action" value="ramp_buy" />' );
		submit_button( __( 'Buy USDT now', 'igbz-suite' ), 'secondary', '', false );
		echo '</form>';
	}

	private function render_reports(): void {
		echo '<h2>' . esc_html__( 'Operator report', 'igbz-suite' ) . '</h2>';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$from = isset( $_GET['from'] ) ? sanitize_text_field( (string) $_GET['from'] ) : gmdate( 'Y-m-01' );
		$to   = isset( $_GET['to'] ) ? sanitize_text_field( (string) $_GET['to'] ) : gmdate( 'Y-m-d' );
		// phpcs:enable

		echo '<form method="get" style="margin:12px 0">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::SLUG ) );
		printf( '<label>%s <input type="date" name="from" value="%s" /></label> ', esc_html__( 'From', 'igbz-suite' ), esc_attr( $from ) );
		printf( '<label>%s <input type="date" name="to" value="%s" /></label> ', esc_html__( 'To', 'igbz-suite' ), esc_attr( $to ) );
		submit_button( __( 'Show', 'igbz-suite' ), 'secondary', '', false );
		echo '</form>';

		$summary = igbz()->get( 'fx.reports' )->operator_summary( $from, $to );

		echo '<table class="widefat striped"><tbody>';
		foreach (
			[
				__( 'Top-ups (count / IRT / USD)', 'igbz-suite' ) => sprintf( '%d / %s / %s', $summary['topup_count'], number_format( $summary['topups_irt'], 0 ), number_format( $summary['topups_usd'], 2 ) ),
				__( 'Top-up fees (USD)', 'igbz-suite' )   => number_format( $summary['fees_usd'], 2 ),
				__( 'Social task spend (USD)', 'igbz-suite' ) => number_format( $summary['task_spend_usd'], 2 ),
				__( 'Subscriptions (USD)', 'igbz-suite' ) => number_format( $summary['subscriptions_usd'], 2 ),
				__( 'Refunds (USD)', 'igbz-suite' )       => number_format( $summary['refunds_usd'], 2 ),
				__( 'Ramp purchases (IRT)', 'igbz-suite' ) => number_format( $summary['ramp_irt'], 0 ),
				__( 'Bills paid (count / USD)', 'igbz-suite' ) => sprintf( '%d / %s', $summary['bills_paid'], number_format( $summary['bills_paid_usd'], 2 ) ),
				__( 'Bills unpaid', 'igbz-suite' )        => (string) $summary['bills_unpaid'],
			] as $label => $value
		) {
			printf( '<tr><th scope="row">%1$s</th><td>%2$s</td></tr>', esc_html( $label ), esc_html( $value ) );
		}
		echo '</tbody></table>';
	}

	private function render_accounts( int $tenant ): void {
		echo '<h2>' . esc_html__( 'Foreign accounts', 'igbz-suite' ) . '</h2>';

		if ( ! Modules::enabled( Modules::INSTAGRAM ) ) {
			echo '<p>' . esc_html__( 'The Instagram module is off; there is nothing to bill yet.', 'igbz-suite' ) . '</p>';
			return;
		}

		$accounts = igbz()->get( 'fx.accounts' )->all( $tenant );
		if ( ! $accounts ) {
			echo '<p>' . esc_html__( 'No foreign accounts for this tenant yet. They are created from the Instagram module once accounts are linked.', 'igbz-suite' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Provider', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Account', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Billing day', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Status', 'igbz-suite' ) . '</th></tr></thead><tbody>';
		foreach ( $accounts as $account ) {
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td></tr>',
				esc_html( (string) $account['provider'] ),
				esc_html( (string) $account['provider_account_id'] ),
				(int) $account['billing_day'],
				esc_html__( (string) $account['status'], 'igbz-suite' )
			);
		}
		echo '</tbody></table>';
	}

	private function render_bills( int $tenant ): void {
		echo '<h2>' . esc_html__( 'Bills', 'igbz-suite' ) . '</h2>';

		$db   = igbz()->db();
		$rows = $db->results(
			'SELECT * FROM ' . $db->table( 'fx_bills' ) . ' WHERE tenant_id = %d ORDER BY id DESC LIMIT 50',
			$tenant
		);

		if ( $rows ) {
			echo '<form method="post" style="margin:12px 0">';
			wp_nonce_field( 'igbz_fx_manual' );
			printf( '<input type="hidden" name="igbz_fx_action" value="manual_settle" />' );
			echo '<select name="bill_id">';
			foreach ( $rows as $row ) {
				if ( FxBillingService::STATUS_DUE === $row['status'] ) {
					printf(
						'<option value="%1$d">%2$s — %3$s USD</option>',
						(int) $row['id'],
						esc_html( (string) $row['period_start'] ),
						esc_html( number_format( (float) $row['amount_usd'], 2 ) )
					);
				}
			}
			echo '</select> ';
			submit_button( __( 'Settle manually', 'igbz-suite' ), 'secondary', '', false );
			echo '</form>';
		}

		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No bills yet.', 'igbz-suite' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Period', 'igbz-suite' ) . '</th><th>' . esc_html__( 'USD', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Status', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Paid', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Payout ref', 'igbz-suite' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>',
				esc_html( (string) $row['period_start'] . ' — ' . (string) $row['period_end'] ),
				esc_html( number_format( (float) $row['amount_usd'], 2 ) ),
				esc_html__( (string) $row['status'], 'igbz-suite' ),
				esc_html( (string) ( $row['paid_at'] ?? '' ) ),
				esc_html( (string) $row['payout_ref'] )
			);
		}
		echo '</tbody></table>';
	}

	private function render_ledger( int $tenant ): void {
		$wallet = igbz()->get( 'fx.wallet' );
		$rows   = $wallet->ledger( $tenant, 50 );

		echo '<h2>' . esc_html__( 'Ledger', 'igbz-suite' ) . '</h2>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No entries yet.', 'igbz-suite' ) . '</p>';
			return;
		}

		$display = [];
		foreach ( $rows as $row ) {
			$display[] = [
				'id'        => '#' . (int) $row['id'],
				'reason'    => esc_html( (string) $row['reason'] ),
				'usd'       => sprintf( '%+.2f', (float) $row['amount_usd'] ),
				'irt'       => number_format( (float) $row['amount_irt'], 0 ),
				'reference' => esc_html( (string) $row['reference'] ),
				'when'      => esc_html( (string) $row['created_at'] ),
			];
		}

		View::table(
			[
				'#'         => '',
				'reason'    => __( 'Reason', 'igbz-suite' ),
				'usd'       => __( 'USD', 'igbz-suite' ),
				'irt'       => __( 'IRT', 'igbz-suite' ),
				'reference' => __( 'Reference', 'igbz-suite' ),
				'when'      => __( 'When', 'igbz-suite' ),
			],
			$display
		);
	}

	private function handle_post(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified per action below.
		$action = isset( $_POST['igbz_fx_action'] ) ? sanitize_key( (string) $_POST['igbz_fx_action'] ) : '';
		if ( '' === $action ) {
			return;
		}

		if ( 'topup' === $action ) {
			View::check_nonce( 'igbz_fx_topup' );

			$usd     = (float) ( $_POST['usd'] ?? 0 );
			$gateway = isset( $_POST['gateway'] ) ? sanitize_key( (string) $_POST['gateway'] ) : '';
			if ( $usd <= 0 ) {
				View::notice( __( 'Enter a positive USD amount.', 'igbz-suite' ), 'error' );
				return;
			}

			$result = igbz()->get( 'fx.topup' )->start(
				(int) igbz()->tenancy()->id(),
				get_current_user_id(),
				$usd,
				$gateway
			);

			if ( ! $result['ok'] ) {
				View::notice( $result['error'], 'error' );
				return;
			}

			wp_safe_redirect( (string) $result['redirect_url'] );
			exit;
		}

		if ( 'ramp_buy' === $action ) {
			View::check_nonce( 'igbz_fx_ramp' );

			$result = igbz()->get( 'fx.ramp' )->buy_now();
			View::notice( $result['message'], $result['ok'] ? 'success' : 'error' );
			return;
		}

		if ( 'manual_settle' === $action ) {
			View::check_nonce( 'igbz_fx_manual' );

			$bill_id = (int) ( $_POST['bill_id'] ?? 0 );
			if ( $bill_id <= 0 ) {
				View::notice( __( 'Choose a due bill.', 'igbz-suite' ), 'error' );
				return;
			}

			$bill = igbz()->db()->row(
				'SELECT * FROM ' . igbz()->db()->table( 'fx_bills' ) . ' WHERE id = %d AND tenant_id = %d',
				$bill_id,
				(int) igbz()->tenancy()->id()
			);
			if ( ! $bill ) {
				View::notice( __( 'Bill not found.', 'igbz-suite' ), 'error' );
				return;
			}

			$result = igbz()->get( 'fx.billing' )->settle_bill_manually( $bill, get_current_user_id() );
			View::notice(
				$result['ok'] ? __( 'Bill settled manually.', 'igbz-suite' ) : $result['error'],
				$result['ok'] ? 'success' : 'error'
			);
			return;
		}

		if ( 'price' === $action ) {
			View::check_nonce( 'igbz_fx_price' );

			$prices = isset( $_POST['price'] ) && is_array( $_POST['price'] ) ? $_POST['price'] : [];
			$db     = igbz()->db();
			foreach ( $prices as $id => $price ) {
				$db->update(
					'fx_prices',
					[
						'price_usd'  => max( 0.0, (float) $price ),
						'updated_at' => current_time( 'mysql', true ),
					],
					[ 'id' => max( 1, (int) $id ) ]
				);
			}
			View::notice( __( 'Prices saved.', 'igbz-suite' ) );
		}
	}
}
