<?php
namespace IGBZ\Suite\Modules\MultiTenant\Admin;

use IGBZ\Suite\Modules\MultiTenant\Affiliate\AffiliateService;
use IGBZ\Suite\Support\Admin\Menu;
use IGBZ\Suite\Support\Admin\View;
use IGBZ\Suite\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/** Affiliates, their commissions and the payout run. */
final class AffiliatePage {

	public const SLUG = 'igbz-affiliate';

	private const PER_PAGE = 25;

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 14 );
	}

	public function add_page(): void {
		Menu::add( self::SLUG, __( 'Affiliates', 'igbz-suite' ), [ $this, 'render' ], Capabilities::MANAGE_AFFILIATE );
	}

	private function affiliate(): AffiliateService {
		return igbz()->get( 'affiliate' );
	}

	public function render(): void {
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$this->handle_post();
		}
		$this->handle_get_actions();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$affiliate_id = isset( $_GET['affiliate'] ) ? (int) $_GET['affiliate'] : 0;
		$tenant_id    = \IGBZ\Suite\Support\TenantScope::page_tenant_id( isset( $_GET['tenant_id'] ) ? (int) $_GET['tenant_id'] : null );
		$paged        = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:enable

		View::open(
			__( 'Affiliates', 'igbz-suite' ),
			__( 'Commissions are written when an order completes and paid into the wallet once approved. Voiding a refunded order reverses them.', 'igbz-suite' )
		);

		if ( $affiliate_id ) {
			$this->render_affiliate( $affiliate_id );
			View::close();
			return;
		}

		$this->render_summary( $tenant_id );
		$this->render_affiliates( $tenant_id, $paged );
		$this->render_pending_commissions();
		$this->render_enroll_form();

		View::close();
	}

	private function render_summary( int $tenant_id ): void {
		$db  = igbz()->db();
		$row = $db->row(
			'SELECT COUNT(*) AS affiliates, COALESCE(SUM(clicks),0) AS clicks, COALESCE(SUM(signups),0) AS signups,
					COALESCE(SUM(total_earned),0) AS earned, COALESCE(SUM(total_paid),0) AS paid
			 FROM ' . $db->table( 'affiliates' ) . ' WHERE tenant_id = %d',
			$tenant_id
		);

		echo '<div class="igbz-cards">';
		foreach (
			[
				__( 'Affiliates', 'igbz-suite' ) => (string) ( $row['affiliates'] ?? 0 ),
				__( 'Clicks', 'igbz-suite' )     => (string) ( $row['clicks'] ?? 0 ),
				__( 'Signups', 'igbz-suite' )    => (string) ( $row['signups'] ?? 0 ),
				__( 'Earned', 'igbz-suite' )     => View::money( (float) ( $row['earned'] ?? 0 ) ),
				__( 'Paid out', 'igbz-suite' )   => View::money( (float) ( $row['paid'] ?? 0 ) ),
			] as $label => $value
		) {
			printf( '<div class="igbz-card"><strong>%1$s</strong><span>%2$s</span></div>', esc_html( $value ), esc_html( $label ) );
		}
		echo '</div>';
	}

	private function render_affiliates( int $tenant_id, int $paged ): void {
		$db    = igbz()->db();
		$total = (int) $db->scalar( 'SELECT COUNT(*) FROM ' . $db->table( 'affiliates' ) . ' WHERE tenant_id = %d', $tenant_id );
		$rows  = $db->results(
			'SELECT * FROM ' . $db->table( 'affiliates' ) . ' WHERE tenant_id = %d ORDER BY total_earned DESC LIMIT %d OFFSET %d',
			$tenant_id,
			self::PER_PAGE,
			( $paged - 1 ) * self::PER_PAGE
		);

		$display = [];
		foreach ( $rows as $row ) {
			$user      = get_userdata( (int) $row['user_id'] );
			$display[] = [
				'code'    => sprintf(
					'<a href="%1$s"><code>%2$s</code></a>',
					esc_url( Menu::url( self::SLUG, [ 'affiliate' => (int) $row['id'] ] ) ),
					esc_html( (string) $row['code'] )
				),
				'user'    => esc_html( $user ? $user->display_name : '#' . $row['user_id'] ),
				'tier'    => esc_html( (string) $row['tier'] ),
				'rate'    => esc_html( number_format_i18n( (float) $row['commission_rate'] * 100, 2 ) . '%' ),
				'clicks'  => esc_html( (string) $row['clicks'] ),
				'signups' => esc_html( (string) $row['signups'] ),
				'earned'  => esc_html( View::money( (float) $row['total_earned'] ) ),
				'paid'    => esc_html( View::money( (float) $row['total_paid'] ) ),
				'status'  => View::status_pill( AffiliateService::STATUS_APPROVED === (string) $row['status'] ? 'ok' : 'warn' )
					. ' ' . esc_html__( (string) $row['status'], 'igbz-suite' ),
			];
		}

		echo '<h2>' . esc_html__( 'Affiliate list', 'igbz-suite' ) . '</h2>';
		View::table(
			[
				'code'    => __( 'Code', 'igbz-suite' ),
				'user'    => __( 'User', 'igbz-suite' ),
				'tier'    => __( 'Tier', 'igbz-suite' ),
				'rate'    => __( 'Rate', 'igbz-suite' ),
				'clicks'  => __( 'Clicks', 'igbz-suite' ),
				'signups' => __( 'Signups', 'igbz-suite' ),
				'earned'  => __( 'Earned', 'igbz-suite' ),
				'paid'    => __( 'Paid', 'igbz-suite' ),
				'status'  => __( 'Status', 'igbz-suite' ),
			],
			$display,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'No affiliates enrolled yet.', 'igbz-suite' )
		);

		View::pagination( $total, self::PER_PAGE, $paged, self::SLUG, [ 'tenant_id' => $tenant_id ] );
	}

	private function render_affiliate( int $affiliate_id ): void {
		$affiliate = $this->affiliate()->find( $affiliate_id );
		if ( ! $affiliate ) {
			View::notice( __( 'Affiliate not found.', 'igbz-suite' ), 'error' );
			return;
		}

		$stats = $this->affiliate()->stats( $affiliate_id );
		$user  = get_userdata( (int) $affiliate['user_id'] );

		printf(
			'<p><a href="%1$s">&larr; %2$s</a></p>',
			esc_url( Menu::url( self::SLUG ) ),
			esc_html__( 'Back to affiliates', 'igbz-suite' )
		);
		printf(
			'<h2>%1$s — <code>%2$s</code></h2>',
			esc_html( $user ? $user->display_name : '#' . $affiliate['user_id'] ),
			esc_html( (string) $affiliate['code'] )
		);
		printf(
			'<p>%1$s <code>%2$s</code></p>',
			esc_html__( 'Referral link:', 'igbz-suite' ),
			esc_html( $this->affiliate()->referral_url( (string) $affiliate['code'] ) )
		);

		echo '<div class="igbz-cards">';
		foreach (
			[
				__( 'Clicks', 'igbz-suite' )         => (string) $stats['clicks'],
				__( 'Signups', 'igbz-suite' )        => (string) $stats['signups'],
				__( 'Orders', 'igbz-suite' )         => (string) $stats['orders'],
				__( 'Pending', 'igbz-suite' )        => View::money( (float) $stats['pending'] ),
				__( 'Paid', 'igbz-suite' )           => View::money( (float) $stats['paid'] ),
				__( 'Wallet balance', 'igbz-suite' ) => View::money( (float) $stats['balance'] ),
			] as $label => $value
		) {
			printf( '<div class="igbz-card"><strong>%1$s</strong><span>%2$s</span></div>', esc_html( $value ), esc_html( $label ) );
		}
		echo '</div>';

		$rows = [];
		foreach ( $this->affiliate()->commissions( $affiliate_id, 100 ) as $row ) {
			$rows[] = [
				'order'    => sprintf( '#%d', (int) $row['order_id'] ),
				'referred' => esc_html( $this->user_label( (int) $row['referred_user_id'] ) ),
				'tier'     => esc_html( (string) $row['tier'] ),
				'base'     => esc_html( View::money( (float) $row['base_amount'] ) ),
				'rate'     => esc_html( number_format_i18n( (float) $row['rate'] * 100, 2 ) . '%' ),
				'amount'   => esc_html( View::money( (float) $row['amount'] ) ),
				'status'   => esc_html__( (string) $row['status'], 'igbz-suite' ),
				'created'  => esc_html( (string) $row['created_at'] ),
			];
		}

		echo '<h2>' . esc_html__( 'Commissions', 'igbz-suite' ) . '</h2>';
		View::table(
			[
				'order'    => __( 'Order', 'igbz-suite' ),
				'referred' => __( 'Referred user', 'igbz-suite' ),
				'tier'     => __( 'Tier', 'igbz-suite' ),
				'base'     => __( 'Order base', 'igbz-suite' ),
				'rate'     => __( 'Rate', 'igbz-suite' ),
				'amount'   => __( 'Commission', 'igbz-suite' ),
				'status'   => __( 'Status', 'igbz-suite' ),
				'created'  => __( 'Created', 'igbz-suite' ),
			],
			$rows,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'No commissions recorded.', 'igbz-suite' )
		);

		echo '<h2>' . esc_html__( 'Adjust affiliate', 'igbz-suite' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'igbz_affiliate_update' );
		printf( '<input type="hidden" name="igbz_action" value="update" /><input type="hidden" name="affiliate_id" value="%d" />', $affiliate_id );
		echo '<table class="form-table" role="presentation"><tbody>';
		printf(
			'<tr><th scope="row">%1$s</th><td><input type="number" step="0.0001" min="0" max="1" name="commission_rate" value="%2$s" class="small-text" /> <span class="description">%3$s</span></td></tr>',
			esc_html__( 'Commission rate', 'igbz-suite' ),
			esc_attr( (string) $affiliate['commission_rate'] ),
			esc_html__( '0.05 = five percent', 'igbz-suite' )
		);
		echo '<tr><th scope="row">' . esc_html__( 'Status', 'igbz-suite' ) . '</th><td><select name="status">';
		foreach (
			[ AffiliateService::STATUS_PENDING, AffiliateService::STATUS_APPROVED, AffiliateService::STATUS_REJECTED ] as $value
		) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( (string) $affiliate['status'], $value, false ), esc_html__( (string) $value, 'igbz-suite' ) );
		}
		echo '</select></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save', 'igbz-suite' ) );
		echo '</form>';
	}

	private function render_pending_commissions(): void {
		$db   = igbz()->db();
		$rows = $db->results(
			'SELECT c.*, a.code FROM ' . $db->table( 'affiliate_commissions' ) . ' c
			 LEFT JOIN ' . $db->table( 'affiliates' ) . ' a ON a.id = c.affiliate_id
			 WHERE c.status = %s ORDER BY c.id DESC LIMIT 25',
			AffiliateService::STATUS_PENDING
		);

		$display = [];
		foreach ( $rows as $row ) {
			$display[] = [
				'code'    => esc_html( (string) ( $row['code'] ?? '' ) ),
				'order'   => sprintf( '#%d', (int) $row['order_id'] ),
				'amount'  => esc_html( View::money( (float) $row['amount'] ) ),
				'created' => esc_html( (string) $row['created_at'] ),
			];
		}

		echo '<h2>' . esc_html__( 'Pending commissions', 'igbz-suite' ) . '</h2>';
		View::table(
			[
				'code'    => __( 'Affiliate', 'igbz-suite' ),
				'order'   => __( 'Order', 'igbz-suite' ),
				'amount'  => __( 'Amount', 'igbz-suite' ),
				'created' => __( 'Created', 'igbz-suite' ),
			],
			$display,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'Nothing is waiting for approval.', 'igbz-suite' )
		);

		printf(
			'<p><a class="button button-primary" href="%1$s">%2$s</a> <span class="description">%3$s</span></p>',
			esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'run' => 'payout' ] ), 'igbz_affiliate_action' ) ),
			esc_html__( 'Approve and pay matured commissions', 'igbz-suite' ),
			esc_html__( 'Only commissions older than the hold period configured in Settings are paid.', 'igbz-suite' )
		);
	}

	private function render_enroll_form(): void {
		echo '<h2>' . esc_html__( 'Enroll an affiliate', 'igbz-suite' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'igbz_affiliate_enroll' );
		echo '<input type="hidden" name="igbz_action" value="enroll" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'User', 'igbz-suite' ) . '</th><td>';
		wp_dropdown_users( [ 'name' => 'user_id', 'number' => 200 ] );
		echo '</td></tr>';
		printf(
			'<tr><th scope="row">%1$s</th><td><input type="number" name="tenant_id" value="0" class="small-text" /></td></tr>',
			esc_html__( 'Tenant id', 'igbz-suite' )
		);
		printf(
			'<tr><th scope="row">%1$s</th><td><input type="number" name="parent_id" value="0" class="small-text" /><p class="description">%2$s</p></td></tr>',
			esc_html__( 'Parent affiliate id', 'igbz-suite' ),
			esc_html__( 'Set this to build a second-tier relationship.', 'igbz-suite' )
		);
		echo '</tbody></table>';
		submit_button( __( 'Enroll', 'igbz-suite' ) );
		echo '</form>';
	}

	private function user_label( int $user_id ): string {
		$user = $user_id ? get_userdata( $user_id ) : null;
		return $user ? $user->display_name : '—';
	}

	private function handle_post(): void {
		Capabilities::require( Capabilities::MANAGE_AFFILIATE );

		$action = isset( $_POST['igbz_action'] ) ? sanitize_key( (string) $_POST['igbz_action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( 'enroll' === $action ) {
			check_admin_referer( 'igbz_affiliate_enroll' );
			$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
			if ( ! $user_id ) {
				View::notice( __( 'Pick a user first.', 'igbz-suite' ), 'error' );
				return;
			}
			$affiliate = $this->affiliate()->enroll(
				$user_id,
				\IGBZ\Suite\Support\TenantScope::page_tenant_id( isset( $_POST['tenant_id'] ) ? (int) $_POST['tenant_id'] : null ),
				isset( $_POST['parent_id'] ) ? (int) $_POST['parent_id'] : 0
			);
			View::notice(
				sprintf(
					/* translators: %s: affiliate code. */
					__( 'Enrolled with code %s.', 'igbz-suite' ),
					(string) $affiliate['code']
				)
			);
			return;
		}

		if ( 'update' === $action ) {
			check_admin_referer( 'igbz_affiliate_update' );
			$id = isset( $_POST['affiliate_id'] ) ? (int) $_POST['affiliate_id'] : 0;
			if ( ! $id ) {
				return;
			}
			igbz()->db()->update(
				'affiliates',
				[
					'commission_rate' => isset( $_POST['commission_rate'] ) ? min( 1.0, max( 0.0, (float) $_POST['commission_rate'] ) ) : 0.0,
					'status'          => isset( $_POST['status'] ) ? sanitize_key( (string) $_POST['status'] ) : AffiliateService::STATUS_PENDING,
				],
				[ 'id' => $id ]
			);
			View::notice( __( 'Affiliate updated.', 'igbz-suite' ) );
		}
	}

	private function handle_get_actions(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['run'] ) ) {
			return;
		}
		check_admin_referer( 'igbz_affiliate_action' );
		Capabilities::require( Capabilities::MANAGE_AFFILIATE );

		$count = $this->affiliate()->process_pending_commissions();
		View::notice(
			sprintf(
				/* translators: %d: number of commissions paid. */
				__( '%d commissions approved and credited.', 'igbz-suite' ),
				$count
			)
		);
	}
}
