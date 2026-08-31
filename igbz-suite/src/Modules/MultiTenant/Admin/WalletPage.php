<?php
namespace IGBZ\Suite\Modules\MultiTenant\Admin;

use IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService;
use IGBZ\Suite\Support\Admin\Menu;
use IGBZ\Suite\Support\Admin\View;
use IGBZ\Suite\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/** Wallet balances, ledger browser and manual adjustments. */
final class WalletPage {

	public const SLUG = 'igbz-wallet';

	private const PER_PAGE = 30;

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 11 );
	}

	public function add_page(): void {
		Menu::add( self::SLUG, __( 'Wallet', 'igbz-suite' ), [ $this, 'render' ], Capabilities::MANAGE_WALLET );
	}

	private function wallet(): WalletService {
		return igbz()->get( 'wallet' );
	}

	public function render(): void {
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$this->handle_post();
		}

		// Phase 14: the tenant comes from the resolved identity; only platform admins may aim elsewhere.
		$tenant_id = \IGBZ\Suite\Support\TenantScope::page_tenant_id( isset( $_GET['tenant_id'] ) ? (int) $_GET['tenant_id'] : null ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$user_id   = isset( $_GET['user_id'] ) ? (int) $_GET['user_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		View::open(
			__( 'Wallet', 'igbz-suite' ),
			__( 'Every movement is an immutable ledger row with the resulting balance. Debits take a named lock, so two concurrent orders cannot overdraw the same wallet.', 'igbz-suite' )
		);

		$totals = $this->wallet()->totals( $tenant_id );
		echo '<div class="igbz-cards">';
		printf(
			'<div class="igbz-card"><strong>%s</strong><span>%s</span></div>',
			esc_html( View::money( $totals['credit'] ) ),
			esc_html__( 'Total credited', 'igbz-suite' )
		);
		printf(
			'<div class="igbz-card"><strong>%s</strong><span>%s</span></div>',
			esc_html( View::money( $totals['debit'] ) ),
			esc_html__( 'Total debited', 'igbz-suite' )
		);
		printf(
			'<div class="igbz-card"><strong>%s</strong><span>%s</span></div>',
			esc_html( View::money( $totals['net'] ) ),
			esc_html__( 'Outstanding liability', 'igbz-suite' )
		);
		echo '</div>';

		$this->render_filters( $tenant_id, $user_id );
		$this->render_adjust_form();
		$this->render_ledger( $tenant_id, $user_id, $paged );
		$this->render_top_balances( $tenant_id );

		View::close();
	}

	private function render_filters( int $tenant_id, int $user_id ): void {
		echo '<form method="get" style="margin:12px 0">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::SLUG ) );
		printf(
			'<input type="number" name="tenant_id" value="%1$s" placeholder="%2$s" class="small-text" /> ',
			esc_attr( $tenant_id ? (string) $tenant_id : '' ),
			esc_attr__( 'Tenant id', 'igbz-suite' )
		);
		wp_dropdown_users(
			[
				'name'              => 'user_id',
				'selected'          => $user_id,
				'show_option_none'  => __( '— any user —', 'igbz-suite' ),
				'option_none_value' => 0,
				'number'            => 200,
			]
		);
		echo ' ';
		submit_button( __( 'Filter', 'igbz-suite' ), 'secondary', '', false );
		echo '</form>';
	}

	private function render_adjust_form(): void {
		echo '<h2>' . esc_html__( 'Manual adjustment', 'igbz-suite' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'igbz_wallet_adjust' );
		echo '<input type="hidden" name="igbz_action" value="adjust" />';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'User', 'igbz-suite' ) . '</th><td>';
		wp_dropdown_users( [ 'name' => 'user_id', 'number' => 200 ] );
		echo '</td></tr>';

		printf(
			'<tr><th scope="row">%1$s</th><td><input type="number" name="tenant_id" value="0" class="small-text" /></td></tr>',
			esc_html__( 'Tenant id', 'igbz-suite' )
		);
		printf(
			'<tr><th scope="row">%1$s</th><td><input type="number" step="0.0001" name="amount" class="regular-text" required /><p class="description">%2$s</p></td></tr>',
			esc_html__( 'Amount', 'igbz-suite' ),
			esc_html__( 'Positive credits the wallet, negative debits it.', 'igbz-suite' )
		);
		printf(
			'<tr><th scope="row">%1$s</th><td><input type="text" name="note" class="large-text" maxlength="255" /></td></tr>',
			esc_html__( 'Note', 'igbz-suite' )
		);
		printf(
			'<tr><th scope="row">%1$s</th><td><input type="text" name="reference_code" class="regular-text" placeholder="%2$s" /><p class="description">%3$s</p></td></tr>',
			esc_html__( 'Reference code', 'igbz-suite' ),
			esc_attr__( 'auto', 'igbz-suite' ),
			esc_html__( 'Reusing a reference for the same user and reason is a no-op: the ledger is idempotent.', 'igbz-suite' )
		);

		echo '</tbody></table>';
		submit_button( __( 'Post adjustment', 'igbz-suite' ) );
		echo '</form>';
	}

	private function render_ledger( int $tenant_id, int $user_id, int $paged ): void {
		$db     = igbz()->db();
		$where  = [ '1=1' ];
		$params = [];

		if ( $tenant_id ) {
			$where[]  = 'tenant_id = %d';
			$params[] = $tenant_id;
		}
		if ( $user_id ) {
			$where[]  = 'user_id = %d';
			$params[] = $user_id;
		}

		$clause = implode( ' AND ', $where );
		$total  = (int) $db->scalar( 'SELECT COUNT(*) FROM ' . $db->table( 'wallet_ledger' ) . ' WHERE ' . $clause, ...$params );

		$rows = $db->results(
			'SELECT * FROM ' . $db->table( 'wallet_ledger' ) . ' WHERE ' . $clause . ' ORDER BY id DESC LIMIT %d OFFSET %d',
			...array_merge( $params, [ self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE ] )
		);

		$display = [];
		foreach ( $rows as $row ) {
			$user      = get_userdata( (int) $row['user_id'] );
			$amount    = (float) $row['amount'];
			$display[] = [
				'created_at' => esc_html( (string) $row['created_at'] ),
				'user'       => esc_html( $user ? $user->display_name : '#' . $row['user_id'] ),
				'tenant'     => esc_html( (string) $row['tenant_id'] ),
				'amount'     => sprintf(
					'<span style="color:%1$s">%2$s</span>',
					$amount < 0 ? '#d63638' : '#00a32a',
					esc_html( View::money( $amount ) )
				),
				'balance'    => esc_html( View::money( (float) $row['balance_after'] ) ),
				'reason'     => esc_html( $this->translate_ledger_value( (string) $row['reason'] ) ),
				'reference'  => esc_html( (string) $row['reference_code'] ),
				'note'       => esc_html( $this->translate_ledger_value( (string) $row['note'] ) ),
			];
		}

		echo '<h2>' . esc_html__( 'Ledger', 'igbz-suite' ) . '</h2>';
		View::table(
			[
				'created_at' => __( 'Date', 'igbz-suite' ),
				'user'       => __( 'User', 'igbz-suite' ),
				'tenant'     => __( 'Tenant', 'igbz-suite' ),
				'amount'     => __( 'Amount', 'igbz-suite' ),
				'balance'    => __( 'Balance after', 'igbz-suite' ),
				'reason'     => __( 'Reason', 'igbz-suite' ),
				'reference'  => __( 'Reference', 'igbz-suite' ),
				'note'       => __( 'Note', 'igbz-suite' ),
			],
			$display,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'No movements recorded.', 'igbz-suite' )
		);

		View::pagination( $total, self::PER_PAGE, $paged, self::SLUG, [ 'tenant_id' => $tenant_id, 'user_id' => $user_id ] );
	}

	private function translate_ledger_value( string $value ): string {
		return match ( $value ) {
			'Purchase cashback' => __( 'بازگشت نقدی خرید', 'igbz-suite' ),
			'cashback' => __( 'بازگشت نقدی', 'igbz-suite' ),
			default => $value,
		};
	}

	private function render_top_balances( int $tenant_id ): void {
		$db     = igbz()->db();
		$where  = $tenant_id ? 'WHERE tenant_id = %d' : '';
		$params = $tenant_id ? [ $tenant_id ] : [];

		$rows = $db->results(
			'SELECT user_id, tenant_id, balance, currency, updated_at FROM ' . $db->table( 'wallet_balances' )
			. ' ' . $where . ' ORDER BY balance DESC LIMIT 10',
			...$params
		);

		$display = [];
		foreach ( $rows as $row ) {
			$user      = get_userdata( (int) $row['user_id'] );
			$display[] = [
				'user'    => $user ? $user->display_name : '#' . $row['user_id'],
				'tenant'  => (string) $row['tenant_id'],
				'balance' => View::money( (float) $row['balance'] ),
				'updated' => (string) $row['updated_at'],
			];
		}

		echo '<h2>' . esc_html__( 'Largest balances', 'igbz-suite' ) . '</h2>';
		View::table(
			[
				'user'    => __( 'User', 'igbz-suite' ),
				'tenant'  => __( 'Tenant', 'igbz-suite' ),
				'balance' => __( 'Balance', 'igbz-suite' ),
				'updated' => __( 'Updated', 'igbz-suite' ),
			],
			$display
		);
	}

	private function handle_post(): void {
		Capabilities::require( Capabilities::MANAGE_WALLET );
		check_admin_referer( 'igbz_wallet_adjust' );

		$user_id   = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		$tenant_id = \IGBZ\Suite\Support\TenantScope::page_tenant_id( isset( $_POST['tenant_id'] ) ? (int) $_POST['tenant_id'] : null );
		$amount    = isset( $_POST['amount'] ) ? (float) $_POST['amount'] : 0.0;
		$note      = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
		$reference = isset( $_POST['reference_code'] ) ? sanitize_text_field( wp_unslash( $_POST['reference_code'] ) ) : '';

		if ( ! $user_id || 0.0 === $amount ) {
			View::notice( __( 'Pick a user and a non-zero amount.', 'igbz-suite' ), 'error' );
			return;
		}

		if ( '' === $reference ) {
			$reference = 'manual-' . get_current_user_id() . '-' . time();
		}

		$meta = [ 'by' => get_current_user_id() ];

		$result = $amount > 0
			? $this->wallet()->credit( $user_id, $amount, WalletService::REASON_ADJUSTMENT, $reference, $meta, $tenant_id, 0, $note )
			: $this->wallet()->debit( $user_id, $amount, WalletService::REASON_ADJUSTMENT, $reference, $meta, $tenant_id, 0, $note );

		if ( $result->success ) {
			View::notice(
				sprintf(
					/* translators: %s: new balance. */
					__( 'Adjustment posted. New balance: %s', 'igbz-suite' ),
					View::money( $result->balance )
				)
			);
			return;
		}

		View::notice( $result->error_message ?: __( 'The adjustment was rejected.', 'igbz-suite' ), 'error' );
	}
}
