<?php
namespace IGBZ\Suite\Modules\MultiTenant\Admin;

use IGBZ\Suite\Modules\MultiTenant\Bnpl\BnplService;
use IGBZ\Suite\Support\Admin\Menu;
use IGBZ\Suite\Support\Admin\View;
use IGBZ\Suite\Support\Capabilities;
use IGBZ\Suite\Support\WooCommerceCompat;

defined( 'ABSPATH' ) || exit;

/** Instalment contracts, their schedules and the credit profiles behind them. */
final class BnplPage {

	public const SLUG = 'igbz-bnpl';

	private const PER_PAGE = 25;

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 13 );
	}

	public function add_page(): void {
		Menu::add( self::SLUG, __( 'Instalments', 'igbz-suite' ), [ $this, 'render' ], Capabilities::MANAGE_BNPL );
	}

	private function bnpl(): BnplService {
		return igbz()->get( 'bnpl' );
	}

	public function render(): void {
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$this->handle_post();
		}
		$this->handle_get_actions();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$contract_id = isset( $_GET['contract'] ) ? (int) $_GET['contract'] : 0;
		$tenant_id   = \IGBZ\Suite\Support\TenantScope::page_tenant_id( isset( $_GET['tenant_id'] ) ? (int) $_GET['tenant_id'] : null );
		$status      = isset( $_GET['status'] ) ? sanitize_key( (string) $_GET['status'] ) : '';
		$paged       = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:enable

		View::open(
			__( 'Instalments', 'igbz-suite' ),
			__( 'Contracts are scored locally first; an external provider is only called when one is configured for the contract.', 'igbz-suite' )
		);

		if ( $contract_id ) {
			$this->render_contract( $contract_id );
			View::close();
			return;
		}

		$this->render_stats( $tenant_id );
		$this->render_filters( $tenant_id, $status );
		$this->render_contracts( $tenant_id, $status, $paged );
		$this->render_overdue();
		$this->render_credit_form();

		View::close();
	}

	private function render_stats( int $tenant_id ): void {
		$stats = $this->bnpl()->stats( $tenant_id );
		echo '<div class="igbz-cards">';
		foreach (
			[
				__( 'Contracts', 'igbz-suite' )   => (string) $stats['contracts'],
				__( 'Active', 'igbz-suite' )      => (string) $stats['active'],
				__( 'Defaulted', 'igbz-suite' )   => (string) $stats['defaulted'],
				__( 'Outstanding', 'igbz-suite' ) => View::money( (float) $stats['outstanding'] ),
			] as $label => $value
		) {
			printf( '<div class="igbz-card"><strong>%1$s</strong><span>%2$s</span></div>', esc_html( $value ), esc_html( $label ) );
		}
		echo '</div>';
	}

	private function render_filters( int $tenant_id, string $status ): void {
		echo '<form method="get" style="margin:12px 0">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::SLUG ) );
		printf(
			'<input type="number" name="tenant_id" class="small-text" value="%1$s" placeholder="%2$s" /> ',
			esc_attr( $tenant_id ? (string) $tenant_id : '' ),
			esc_attr__( 'Tenant', 'igbz-suite' )
		);
		echo '<select name="status">';
		printf( '<option value="">%s</option>', esc_html__( '— any status —', 'igbz-suite' ) );
		foreach (
			[
				BnplService::STATUS_PENDING,
				BnplService::STATUS_ACTIVE,
				BnplService::STATUS_SETTLED,
				BnplService::STATUS_DEFAULTED,
				BnplService::STATUS_CANCELLED,
			] as $value
		) {
			printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $value ), selected( $status, $value, false ) );
		}
		echo '</select> ';
		submit_button( __( 'Filter', 'igbz-suite' ), 'secondary', '', false );
		echo '</form>';
	}

	private function render_contracts( int $tenant_id, string $status, int $paged ): void {
		$db     = igbz()->db();
		$where  = [ '1=1' ];
		$params = [];

		if ( $tenant_id ) {
			$where[]  = 'tenant_id = %d';
			$params[] = $tenant_id;
		}
		if ( '' !== $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}
		$clause = implode( ' AND ', $where );

		$total = (int) $db->scalar( 'SELECT COUNT(*) FROM ' . $db->table( 'bnpl_contracts' ) . ' WHERE ' . $clause, ...$params );
		$rows  = $db->results(
			'SELECT * FROM ' . $db->table( 'bnpl_contracts' ) . ' WHERE ' . $clause . ' ORDER BY id DESC LIMIT %d OFFSET %d',
			...array_merge( $params, [ self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE ] )
		);

		$display = [];
		foreach ( $rows as $row ) {
			$user      = get_userdata( (int) $row['user_id'] );
			$display[] = [
				'id'       => sprintf(
					'<a href="%1$s">#%2$d</a>',
					esc_url( Menu::url( self::SLUG, [ 'contract' => (int) $row['id'] ] ) ),
					(int) $row['id']
				),
				'user'     => esc_html( $user ? $user->display_name : '#' . $row['user_id'] ),
				'order'    => $row['order_id']
					? sprintf( '<a href="%1$s">#%2$d</a>', esc_url( WooCommerceCompat::order_edit_url( (int) $row['order_id'] ) ), (int) $row['order_id'] )
					: '—',
				'provider' => esc_html( (string) $row['provider'] ),
				'total'    => esc_html( View::money( (float) $row['total_payable'] ) ),
				'plan'     => esc_html( sprintf( '%1$d × %2$d', (int) $row['installment_count'], (int) $row['interval_days'] ) ),
				'status'   => View::status_pill( $this->severity( (string) $row['status'] ) ) . ' ' . esc_html( (string) $row['status'] ),
				'created'  => esc_html( (string) $row['created_at'] ),
			];
		}

		echo '<h2>' . esc_html__( 'Contracts', 'igbz-suite' ) . '</h2>';
		View::table(
			[
				'id'       => __( 'Contract', 'igbz-suite' ),
				'user'     => __( 'Customer', 'igbz-suite' ),
				'order'    => __( 'Order', 'igbz-suite' ),
				'provider' => __( 'Provider', 'igbz-suite' ),
				'total'    => __( 'Total payable', 'igbz-suite' ),
				'plan'     => __( 'Instalments × days', 'igbz-suite' ),
				'status'   => __( 'Status', 'igbz-suite' ),
				'created'  => __( 'Created', 'igbz-suite' ),
			],
			$display,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'No contracts yet.', 'igbz-suite' )
		);

		View::pagination( $total, self::PER_PAGE, $paged, self::SLUG, [ 'tenant_id' => $tenant_id, 'status' => $status ] );
	}

	private function render_contract( int $contract_id ): void {
		$contract = $this->bnpl()->contract( $contract_id );
		if ( ! $contract ) {
			View::notice( __( 'Contract not found.', 'igbz-suite' ), 'error' );
			return;
		}

		$user = get_userdata( (int) $contract['user_id'] );

		printf(
			'<p><a href="%1$s">&larr; %2$s</a></p>',
			esc_url( Menu::url( self::SLUG ) ),
			esc_html__( 'Back to contracts', 'igbz-suite' )
		);

		printf(
			'<h2>%1$s #%2$d — %3$s</h2>',
			esc_html__( 'Contract', 'igbz-suite' ),
			$contract_id,
			esc_html( $user ? $user->display_name : '#' . $contract['user_id'] )
		);

		echo '<table class="widefat striped" style="max-width:640px"><tbody>';
		foreach (
			[
				__( 'Status', 'igbz-suite' )       => (string) $contract['status'],
				__( 'Provider', 'igbz-suite' )     => (string) $contract['provider'],
				__( 'Provider ref', 'igbz-suite' ) => (string) $contract['provider_ref'],
				__( 'Principal', 'igbz-suite' )    => View::money( (float) $contract['principal'] ),
				__( 'Down payment', 'igbz-suite' ) => View::money( (float) $contract['down_payment'] ),
				__( 'Fees', 'igbz-suite' )         => View::money( (float) $contract['fee_amount'] ),
				__( 'Total payable', 'igbz-suite' ) => View::money( (float) $contract['total_payable'] ),
				__( 'Outstanding', 'igbz-suite' )  => View::money( $this->bnpl()->outstanding( $contract_id ) ),
				__( 'Signed at', 'igbz-suite' )    => (string) ( $contract['signed_at'] ?? '—' ),
				__( 'Settled at', 'igbz-suite' )   => (string) ( $contract['settled_at'] ?? '—' ),
			] as $label => $value
		) {
			printf( '<tr><th style="width:180px">%1$s</th><td>%2$s</td></tr>', esc_html( $label ), esc_html( $value ) );
		}
		echo '</tbody></table>';

		if ( BnplService::STATUS_PENDING === (string) $contract['status'] ) {
			printf(
				'<p><a class="button button-primary" href="%1$s">%2$s</a> <a class="button" href="%3$s">%4$s</a></p>',
				esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'contract' => $contract_id, 'activate' => $contract_id ] ), 'igbz_bnpl_action' ) ),
				esc_html__( 'Activate', 'igbz-suite' ),
				esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'contract' => $contract_id, 'cancel' => $contract_id ] ), 'igbz_bnpl_action' ) ),
				esc_html__( 'Cancel', 'igbz-suite' )
			);
		}

		$rows = [];
		foreach ( $this->bnpl()->installments( $contract_id ) as $inst ) {
			$paid  = BnplService::INSTALLMENT_PAID === (string) $inst['status'];
			$rows[] = [
				'seq'     => esc_html( (string) $inst['sequence'] ),
				'amount'  => esc_html( View::money( (float) $inst['amount'] + (float) $inst['penalty'] ) ),
				'due'     => esc_html( (string) $inst['due_date'] ),
				'status'  => View::status_pill(
					match ( (string) $inst['status'] ) {
						BnplService::INSTALLMENT_PAID => 'ok',
						BnplService::INSTALLMENT_OVERDUE => 'error',
						default => 'warn',
					}
				) . ' ' . esc_html( (string) $inst['status'] ),
				'paid_at' => esc_html( (string) ( $inst['paid_at'] ?? '—' ) ),
				'ref'     => esc_html( (string) $inst['payment_ref'] ),
				'actions' => $paid ? '' : sprintf(
					'<a class="button button-small" href="%1$s">%2$s</a> <a class="button button-small" href="%3$s">%4$s</a>',
					esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'contract' => $contract_id, 'charge' => (int) $inst['id'] ] ), 'igbz_bnpl_action' ) ),
					esc_html__( 'Charge wallet', 'igbz-suite' ),
					esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'contract' => $contract_id, 'settle' => (int) $inst['id'] ] ), 'igbz_bnpl_action' ) ),
					esc_html__( 'Mark paid', 'igbz-suite' )
				),
			];
		}

		echo '<h2>' . esc_html__( 'Schedule', 'igbz-suite' ) . '</h2>';
		View::table(
			[
				'seq'     => __( '#', 'igbz-suite' ),
				'amount'  => __( 'Amount', 'igbz-suite' ),
				'due'     => __( 'Due', 'igbz-suite' ),
				'status'  => __( 'Status', 'igbz-suite' ),
				'paid_at' => __( 'Paid at', 'igbz-suite' ),
				'ref'     => __( 'Reference', 'igbz-suite' ),
				'actions' => __( 'Actions', 'igbz-suite' ),
			],
			$rows,
			static fn ( array $row, string $key ): string => (string) $row[ $key ]
		);
	}

	private function render_overdue(): void {
		$db   = igbz()->db();
		$rows = $db->results(
			'SELECT i.*, c.provider FROM ' . $db->table( 'bnpl_installments' ) . ' i
			 LEFT JOIN ' . $db->table( 'bnpl_contracts' ) . ' c ON c.id = i.contract_id
			 WHERE i.status = %s ORDER BY i.due_date ASC LIMIT 20',
			BnplService::INSTALLMENT_OVERDUE
		);

		$display = [];
		foreach ( $rows as $row ) {
			$user      = get_userdata( (int) $row['user_id'] );
			$display[] = [
				'contract' => sprintf(
					'<a href="%1$s">#%2$d</a>',
					esc_url( Menu::url( self::SLUG, [ 'contract' => (int) $row['contract_id'] ] ) ),
					(int) $row['contract_id']
				),
				'user'     => esc_html( $user ? $user->display_name : '#' . $row['user_id'] ),
				'amount'   => esc_html( View::money( (float) $row['amount'] + (float) $row['penalty'] ) ),
				'due'      => esc_html( (string) $row['due_date'] ),
				'reminder' => esc_html( (string) ( $row['reminder_sent_at'] ?? '—' ) ),
			];
		}

		echo '<h2>' . esc_html__( 'Overdue instalments', 'igbz-suite' ) . '</h2>';
		View::table(
			[
				'contract' => __( 'Contract', 'igbz-suite' ),
				'user'     => __( 'Customer', 'igbz-suite' ),
				'amount'   => __( 'Amount due', 'igbz-suite' ),
				'due'      => __( 'Due date', 'igbz-suite' ),
				'reminder' => __( 'Last reminder', 'igbz-suite' ),
			],
			$display,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'Nothing is overdue.', 'igbz-suite' )
		);

		printf(
			'<p><a class="button" href="%1$s">%2$s</a> <a class="button" href="%3$s">%4$s</a></p>',
			esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'run' => 'overdue' ] ), 'igbz_bnpl_action' ) ),
			esc_html__( 'Re-scan for overdue', 'igbz-suite' ),
			esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'run' => 'reminders' ] ), 'igbz_bnpl_action' ) ),
			esc_html__( 'Send reminders now', 'igbz-suite' )
		);
	}

	private function render_credit_form(): void {
		echo '<h2>' . esc_html__( 'Credit limit', 'igbz-suite' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'igbz_bnpl_credit' );
		echo '<input type="hidden" name="igbz_action" value="set_credit" />';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'User', 'igbz-suite' ) . '</th><td>';
		wp_dropdown_users( [ 'name' => 'user_id', 'number' => 200 ] );
		echo '</td></tr>';

		printf(
			'<tr><th scope="row">%1$s</th><td><input type="number" name="tenant_id" value="0" class="small-text" /></td></tr>',
			esc_html__( 'Tenant id', 'igbz-suite' )
		);
		printf(
			'<tr><th scope="row">%1$s</th><td><input type="number" step="any" name="credit_limit" class="regular-text" required /></td></tr>',
			esc_html__( 'Credit limit', 'igbz-suite' )
		);

		echo '</tbody></table>';
		submit_button( __( 'Save credit limit', 'igbz-suite' ) );
		echo '</form>';
	}

	private function severity( string $status ): string {
		return match ( $status ) {
			BnplService::STATUS_ACTIVE, BnplService::STATUS_SETTLED => 'ok',
			BnplService::STATUS_PENDING => 'warn',
			default => 'error',
		};
	}

	private function handle_post(): void {
		Capabilities::require( Capabilities::MANAGE_BNPL );
		check_admin_referer( 'igbz_bnpl_credit' );

		$user_id   = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		$tenant_id = \IGBZ\Suite\Support\TenantScope::page_tenant_id( isset( $_POST['tenant_id'] ) ? (int) $_POST['tenant_id'] : null );
		$limit     = isset( $_POST['credit_limit'] ) ? (float) $_POST['credit_limit'] : 0.0;

		if ( ! $user_id ) {
			View::notice( __( 'Pick a user first.', 'igbz-suite' ), 'error' );
			return;
		}

		$this->bnpl()->set_credit_limit( $user_id, $limit, $tenant_id );
		View::notice( __( 'Credit limit saved.', 'igbz-suite' ) );
	}

	private function handle_get_actions(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$has = static fn ( string $key ): bool => isset( $_GET[ $key ] );
		if ( ! $has( 'activate' ) && ! $has( 'cancel' ) && ! $has( 'charge' ) && ! $has( 'settle' ) && ! $has( 'run' ) ) {
			return;
		}

		check_admin_referer( 'igbz_bnpl_action' );
		Capabilities::require( Capabilities::MANAGE_BNPL );

		if ( $has( 'activate' ) ) {
			$ok = $this->bnpl()->activate_contract( (int) $_GET['activate'] );
			View::notice( $ok ? __( 'Contract activated.', 'igbz-suite' ) : __( 'Activation failed.', 'igbz-suite' ), $ok ? 'success' : 'error' );
		}
		if ( $has( 'cancel' ) ) {
			$this->bnpl()->cancel_contract( (int) $_GET['cancel'], 'admin' );
			View::notice( __( 'Contract cancelled.', 'igbz-suite' ) );
		}
		if ( $has( 'charge' ) ) {
			$ok = $this->bnpl()->pay_installment_from_wallet( (int) $_GET['charge'] );
			View::notice(
				$ok ? __( 'Instalment charged to the wallet.', 'igbz-suite' ) : __( 'The wallet balance was not enough.', 'igbz-suite' ),
				$ok ? 'success' : 'error'
			);
		}
		if ( $has( 'settle' ) ) {
			$this->bnpl()->mark_installment_paid( (int) $_GET['settle'], 'admin-' . get_current_user_id() );
			View::notice( __( 'Instalment marked as paid.', 'igbz-suite' ) );
		}
		if ( $has( 'run' ) ) {
			$count = 'reminders' === $_GET['run'] ? $this->bnpl()->send_reminders() : $this->bnpl()->process_overdue();
			View::notice(
				sprintf(
					/* translators: %d: number of affected instalments. */
					__( '%d instalments processed.', 'igbz-suite' ),
					$count
				)
			);
		}
		// phpcs:enable
	}
}
