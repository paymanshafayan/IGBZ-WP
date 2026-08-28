<?php
namespace IGBZ\Suite\Modules\MultiTenant\Frontend;

use IGBZ\Suite\Modules\MultiTenant\Affiliate\AffiliateService;
use IGBZ\Suite\Modules\MultiTenant\Bnpl\BnplService;
use IGBZ\Suite\Modules\MultiTenant\Lms\LmsService;
use IGBZ\Suite\Modules\MultiTenant\Payments\PaymentService;
use IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService;
use IGBZ\Suite\Support\TenantScope;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce "My account" endpoints: wallet, instalments, courses and affiliate dashboard.
 *
 * Port note: nopCommerce exposed these as bare controllers with no customer-facing navigation.
 * Registering real endpoints means they inherit the theme, the account menu and login protection.
 */
final class AccountEndpoints {

	public const EP_WALLET    = 'igbz-wallet';
	public const EP_BNPL      = 'igbz-installments';
	public const EP_COURSES   = 'igbz-courses';
	public const EP_AFFILIATE = 'igbz-affiliate';

	public function register(): void {
		add_action( 'init', [ $this, 'add_endpoints' ] );
		add_filter( 'woocommerce_account_menu_items', [ $this, 'menu_items' ] );
		add_filter( 'query_vars', [ $this, 'query_vars' ] );

		add_action( 'woocommerce_account_' . self::EP_WALLET . '_endpoint', [ $this, 'render_wallet' ] );
		add_action( 'woocommerce_account_' . self::EP_BNPL . '_endpoint', [ $this, 'render_bnpl' ] );
		add_action( 'woocommerce_account_' . self::EP_COURSES . '_endpoint', [ $this, 'render_courses' ] );
		add_action( 'woocommerce_account_' . self::EP_AFFILIATE . '_endpoint', [ $this, 'render_affiliate' ] );

		add_action( 'template_redirect', [ $this, 'handle_post' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/** @return array<int,string> */
	public function endpoints(): array {
		$endpoints = [ self::EP_WALLET ];
		$settings  = igbz()->settings();

		if ( $settings->bool( 'bnpl.enabled', true ) ) {
			$endpoints[] = self::EP_BNPL;
		}
		if ( $settings->bool( 'lms.enabled', true ) ) {
			$endpoints[] = self::EP_COURSES;
		}
		if ( $settings->bool( 'affiliate.enabled', true ) ) {
			$endpoints[] = self::EP_AFFILIATE;
		}

		return $endpoints;
	}

	public function add_endpoints(): void {
		foreach ( $this->endpoints() as $endpoint ) {
			add_rewrite_endpoint( $endpoint, EP_ROOT | EP_PAGES );
		}
	}

	/**
	 * @param array<string,string> $vars
	 * @return array<string,string>
	 */
	public function menu_items( $vars ): array {
		$vars   = is_array( $vars ) ? $vars : [];
		$labels = [
			self::EP_WALLET    => __( 'Wallet', 'igbz-suite' ),
			self::EP_BNPL      => __( 'Instalments', 'igbz-suite' ),
			self::EP_COURSES   => __( 'My courses', 'igbz-suite' ),
			self::EP_AFFILIATE => __( 'Affiliate', 'igbz-suite' ),
		];

		$logout = $vars['customer-logout'] ?? null;
		unset( $vars['customer-logout'] );

		foreach ( $this->endpoints() as $endpoint ) {
			$vars[ $endpoint ] = $labels[ $endpoint ];
		}

		if ( null !== $logout ) {
			$vars['customer-logout'] = $logout;
		}

		return $vars;
	}

	/**
	 * @param array<int,string> $vars
	 * @return array<int,string>
	 */
	public function query_vars( $vars ): array {
		$vars = is_array( $vars ) ? $vars : [];
		foreach ( $this->endpoints() as $endpoint ) {
			$vars[] = $endpoint;
		}
		return $vars;
	}

	public function enqueue(): void {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}
		wp_enqueue_style( 'igbz-account', IGBZ_URL . 'assets/css/account.css', [], IGBZ_VERSION );
	}

	// ------------------------------------------------------------------ wallet

	public function render_wallet(): void {
		/** @var WalletService $wallet */
		$wallet    = igbz()->get( 'wallet' );
		$user_id   = get_current_user_id();
		$tenant_id = igbz()->tenancy()->id();
		$balance   = $wallet->balance( $user_id, $tenant_id );

		$this->flash();

		printf(
			'<div class="igbz-balance"><span>%1$s</span><strong>%2$s</strong></div>',
			esc_html__( 'Current balance', 'igbz-suite' ),
			wp_kses_post( wc_price( $balance ) )
		);

		if ( igbz()->settings()->bool( 'wallet.topup_enabled', true ) ) {
			$this->topup_form();
		}

		$entries = $wallet->history( $user_id, [ 'tenant_id' => $tenant_id, 'limit' => 25 ] );

		echo '<h3>' . esc_html__( 'Recent activity', 'igbz-suite' ) . '</h3>';
		echo '<table class="woocommerce-table shop_table igbz-ledger"><thead><tr>';
		echo '<th>' . esc_html__( 'Date', 'igbz-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Description', 'igbz-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Amount', 'igbz-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Balance', 'igbz-suite' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( ! $entries ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No wallet activity yet.', 'igbz-suite' ) . '</td></tr>';
		}

		foreach ( $entries as $entry ) {
			$amount = (float) $entry['amount'];
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td class="%3$s">%4$s</td><td>%5$s</td></tr>',
				esc_html( date_i18n( get_option( 'date_format' ) . ' H:i', strtotime( (string) $entry['created_at'] . ' UTC' ) ) ),
				esc_html( '' !== (string) $entry['note'] ? (string) $entry['note'] : $this->reason_label( (string) $entry['reason'] ) ),
				esc_attr( $amount >= 0 ? 'igbz-credit' : 'igbz-debit' ),
				wp_kses_post( wc_price( $amount ) ),
				wp_kses_post( wc_price( (float) $entry['balance_after'] ) )
			);
		}

		echo '</tbody></table>';
	}

	private function topup_form(): void {
		/** @var PaymentService $payments */
		$payments = igbz()->get( 'payments' );
		$gateways = $payments->enabled_gateways();

		if ( ! $gateways ) {
			printf( '<p class="woocommerce-info">%s</p>', esc_html__( 'Online top-up is not available right now.', 'igbz-suite' ) );
			return;
		}

		$min = (float) igbz()->settings()->get( 'wallet.min_topup', 10000 );
		$max = (float) igbz()->settings()->get( 'wallet.max_topup', 50000000 );

		echo '<form method="post" class="igbz-topup-form">';
		wp_nonce_field( 'igbz_wallet_topup', 'igbz_nonce' );
		echo '<input type="hidden" name="igbz_action" value="wallet_topup" />';
		echo '<h3>' . esc_html__( 'Top up your wallet', 'igbz-suite' ) . '</h3>';

		printf(
			'<p><label for="igbz_topup_amount">%1$s</label><input type="number" step="1" min="%2$s" max="%3$s" required id="igbz_topup_amount" name="amount" /></p>',
			esc_html__( 'Amount', 'igbz-suite' ),
			esc_attr( (string) $min ),
			esc_attr( (string) $max )
		);

		if ( count( $gateways ) > 1 ) {
			echo '<p><label for="igbz_topup_gateway">' . esc_html__( 'Gateway', 'igbz-suite' ) . '</label><select id="igbz_topup_gateway" name="gateway">';
			foreach ( $gateways as $gateway ) {
				printf( '<option value="%1$s">%2$s</option>', esc_attr( $gateway->id() ), esc_html( $gateway->title() ) );
			}
			echo '</select></p>';
		} else {
			printf( '<input type="hidden" name="gateway" value="%s" />', esc_attr( reset( $gateways )->id() ) );
		}

		printf( '<p><button type="submit" class="button">%s</button></p>', esc_html__( 'Continue to payment', 'igbz-suite' ) );
		echo '</form>';
	}

	// -------------------------------------------------------------- instalments

	public function render_bnpl(): void {
		/** @var BnplService $bnpl */
		$bnpl      = igbz()->get( 'bnpl' );
		$user_id   = get_current_user_id();
		$tenant_id = igbz()->tenancy()->id();

		$this->flash();

		$profile   = $bnpl->ensure_credit_profile( $user_id, $tenant_id );
		$available = max( 0, (float) $profile['credit_limit'] - (float) $profile['used_credit'] );

		printf(
			'<div class="igbz-balance"><span>%1$s</span><strong>%2$s</strong><small>%3$s</small></div>',
			esc_html__( 'Available instalment credit', 'igbz-suite' ),
			wp_kses_post( wc_price( $available ) ),
			esc_html(
				sprintf(
					/* translators: 1: credit limit, 2: score */
					__( 'Limit %1$s · score %2$d', 'igbz-suite' ),
					wp_strip_all_tags( wc_price( (float) $profile['credit_limit'] ) ),
					(int) $profile['score']
				)
			)
		);

		$contracts = $bnpl->contracts_for_user( $user_id, $tenant_id );

		if ( ! $contracts ) {
			printf( '<p class="woocommerce-info">%s</p>', esc_html__( 'You have no instalment plans.', 'igbz-suite' ) );
			return;
		}

		foreach ( $contracts as $contract ) {
			printf(
				'<h3>%1$s</h3><p>%2$s</p>',
				esc_html(
					sprintf(
						/* translators: 1: contract id, 2: status */
						__( 'Plan #%1$d — %2$s', 'igbz-suite' ),
						(int) $contract['id'],
						(string) $contract['status']
					)
				),
				esc_html(
					sprintf(
						/* translators: 1: total, 2: outstanding */
						__( 'Total %1$s · outstanding %2$s', 'igbz-suite' ),
						wp_strip_all_tags( wc_price( (float) $contract['total_payable'] ) ),
						wp_strip_all_tags( wc_price( $bnpl->outstanding( (int) $contract['id'] ) ) )
					)
				)
			);

			echo '<table class="woocommerce-table shop_table"><thead><tr>';
			echo '<th>#</th>';
			echo '<th>' . esc_html__( 'Due date', 'igbz-suite' ) . '</th>';
			echo '<th>' . esc_html__( 'Amount', 'igbz-suite' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'igbz-suite' ) . '</th>';
			echo '<th></th></tr></thead><tbody>';

			foreach ( $bnpl->installments( (int) $contract['id'] ) as $installment ) {
				$payable = in_array(
					(string) $installment['status'],
					[ BnplService::INSTALLMENT_DUE, BnplService::INSTALLMENT_OVERDUE ],
					true
				);

				echo '<tr>';
				printf( '<td>%d</td>', (int) $installment['sequence'] );
				printf( '<td>%s</td>', esc_html( date_i18n( get_option( 'date_format' ), strtotime( (string) $installment['due_date'] ) ) ) );
				printf(
					'<td>%s</td>',
					wp_kses_post( wc_price( (float) $installment['amount'] + (float) $installment['penalty'] ) )
				);
				printf( '<td>%s</td>', esc_html( (string) $installment['status'] ) );
				echo '<td>';
				if ( $payable ) {
					echo '<form method="post">';
					wp_nonce_field( 'igbz_bnpl_pay', 'igbz_nonce' );
					echo '<input type="hidden" name="igbz_action" value="bnpl_pay" />';
					printf( '<input type="hidden" name="installment_id" value="%d" />', (int) $installment['id'] );
					printf( '<button type="submit" class="button">%s</button>', esc_html__( 'Pay from wallet', 'igbz-suite' ) );
					echo '</form>';
				}
				echo '</td></tr>';
			}

			echo '</tbody></table>';
		}
	}

	// ------------------------------------------------------------------ courses

	public function render_courses(): void {
		/** @var LmsService $lms */
		$lms         = igbz()->get( 'lms' );
		$enrollments = $lms->enrollments_for_user( get_current_user_id(), igbz()->tenancy()->id() );

		if ( ! $enrollments ) {
			printf(
				'<p class="woocommerce-info">%1$s <a href="%2$s">%3$s</a></p>',
				esc_html__( 'You are not enrolled in any course yet.', 'igbz-suite' ),
				esc_url( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' ) ),
				esc_html__( 'Browse the catalogue', 'igbz-suite' )
			);
			return;
		}

		echo '<div class="igbz-course-grid">';
		foreach ( $enrollments as $enrollment ) {
			$progress = (int) $enrollment['progress_percent'];
			echo '<article class="igbz-course-card">';
			if ( ! empty( $enrollment['cover_url'] ) ) {
				printf( '<img src="%1$s" alt="%2$s" loading="lazy" />', esc_url( (string) $enrollment['cover_url'] ), esc_attr( (string) $enrollment['title'] ) );
			}
			printf( '<h3>%s</h3>', esc_html( (string) $enrollment['title'] ) );
			printf(
				'<div class="igbz-progress"><div class="igbz-progress-bar" style="width:%1$d%%"></div></div><small>%2$s</small>',
				$progress,
				esc_html(
					sprintf(
						/* translators: %d: percentage */
						__( '%d%% complete', 'igbz-suite' ),
						$progress
					)
				)
			);
			printf(
				'<p><a class="button" href="%1$s">%2$s</a></p>',
				esc_url( $this->course_url( (string) $enrollment['slug'] ) ),
				esc_html__( 'Continue', 'igbz-suite' )
			);
			if ( ! empty( $enrollment['certificate_code'] ) ) {
				$code = (string) $enrollment['certificate_code'];
				printf(
					'<p class="igbz-certificate">%1$s <a href="%2$s"><code>%3$s</code></a></p>',
					esc_html__( 'Certificate:', 'igbz-suite' ),
					esc_url( $this->certificate_url( $code ) ),
					esc_html( $code )
				);
			}
			echo '</article>';
		}
		echo '</div>';
	}

	private function course_url( string $slug ): string {
		$page_id = (int) igbz()->settings()->get( 'lms.course_page_id', 0 );
		$base    = $page_id > 0 ? get_permalink( $page_id ) : home_url( '/' );
		return add_query_arg( 'igbz_course', $slug, $base ?: home_url( '/' ) );
	}

	private function certificate_url( string $code ): string {
		$slug = trim( igbz()->settings()->string( 'lms.certificate_slug', 'certificate' ), '/' );
		return home_url( '/' . ( '' !== $slug ? $slug : 'certificate' ) . '/' . rawurlencode( $code ) );
	}

	// ---------------------------------------------------------------- affiliate

	public function render_affiliate(): void {
		/** @var AffiliateService $affiliate */
		$affiliate = igbz()->get( 'affiliate' );
		$user_id   = get_current_user_id();
		$tenant_id = igbz()->tenancy()->id();
		$record    = $affiliate->find_by_user( $user_id, $tenant_id );

		$this->flash();

		if ( ! $record ) {
			printf( '<p>%s</p>', esc_html__( 'Join the affiliate programme and earn a commission on every order you refer.', 'igbz-suite' ) );
			echo '<form method="post">';
			wp_nonce_field( 'igbz_affiliate_join', 'igbz_nonce' );
			echo '<input type="hidden" name="igbz_action" value="affiliate_join" />';
			printf( '<button type="submit" class="button">%s</button>', esc_html__( 'Join now', 'igbz-suite' ) );
			echo '</form>';
			return;
		}

		$stats = $affiliate->stats( (int) $record['id'] );
		$url   = $affiliate->referral_url( (string) $record['code'] );

		printf(
			'<p><label for="igbz_ref_url">%1$s</label><input type="text" id="igbz_ref_url" class="igbz-copy" readonly onfocus="this.select()" value="%2$s" /></p>',
			esc_html__( 'Your referral link', 'igbz-suite' ),
			esc_attr( $url )
		);

		echo '<ul class="igbz-stats">';
		foreach (
			[
				__( 'Clicks', 'igbz-suite' )      => (string) $stats['clicks'],
				__( 'Signups', 'igbz-suite' )     => (string) $stats['signups'],
				__( 'Orders', 'igbz-suite' )      => (string) $stats['orders'],
				__( 'Pending', 'igbz-suite' )     => wp_strip_all_tags( wc_price( (float) $stats['pending'] ) ),
				__( 'Paid out', 'igbz-suite' )    => wp_strip_all_tags( wc_price( (float) $stats['paid'] ) ),
			] as $label => $value
		) {
			printf( '<li><strong>%1$s</strong><span>%2$s</span></li>', esc_html( $value ), esc_html( $label ) );
		}
		echo '</ul>';

		$commissions = $affiliate->commissions( (int) $record['id'], 20 );

		echo '<h3>' . esc_html__( 'Commissions', 'igbz-suite' ) . '</h3>';
		echo '<table class="woocommerce-table shop_table"><thead><tr>';
		echo '<th>' . esc_html__( 'Date', 'igbz-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Order', 'igbz-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Tier', 'igbz-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Amount', 'igbz-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'igbz-suite' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( ! $commissions ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No commissions recorded yet.', 'igbz-suite' ) . '</td></tr>';
		}

		foreach ( $commissions as $commission ) {
			printf(
				'<tr><td>%1$s</td><td>#%2$d</td><td>%3$d</td><td>%4$s</td><td>%5$s</td></tr>',
				esc_html( date_i18n( get_option( 'date_format' ), strtotime( (string) $commission['created_at'] . ' UTC' ) ) ),
				(int) $commission['order_id'],
				(int) $commission['tier'],
				wp_kses_post( wc_price( (float) $commission['amount'] ) ),
				esc_html( (string) $commission['status'] )
			);
		}

		echo '</tbody></table>';
	}

	// ------------------------------------------------------------------ actions

	public function handle_post(): void {
		if ( empty( $_POST['igbz_action'] ) || ! is_user_logged_in() ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['igbz_action'] ) );
		$nonce  = isset( $_POST['igbz_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['igbz_nonce'] ) ) : '';

		match ( $action ) {
			'wallet_topup'   => $this->do_topup( $nonce ),
			'bnpl_pay'       => $this->do_bnpl_pay( $nonce ),
			'affiliate_join' => $this->do_affiliate_join( $nonce ),
			default          => null,
		};
	}

	private function do_topup( string $nonce ): void {
		if ( ! wp_verify_nonce( $nonce, 'igbz_wallet_topup' ) ) {
			$this->redirect_back( 'error', __( 'Security check failed.', 'igbz-suite' ), self::EP_WALLET );
		}

		$amount  = isset( $_POST['amount'] ) ? (float) wp_unslash( $_POST['amount'] ) : 0.0;
		$gateway = isset( $_POST['gateway'] ) ? sanitize_key( wp_unslash( $_POST['gateway'] ) ) : '';
		$max     = (float) igbz()->settings()->get( 'wallet.max_topup', 50000000 );

		if ( $amount <= 0 || $amount > $max ) {
			$this->redirect_back( 'error', __( 'The amount is outside the allowed range.', 'igbz-suite' ), self::EP_WALLET );
		}

		/** @var PaymentService $payments */
		$payments = igbz()->get( 'payments' );
		$result   = $payments->start(
			$amount,
			PaymentService::PURPOSE_WALLET_TOPUP,
			[ 'user_id' => get_current_user_id(), 'tenant_id' => igbz()->tenancy()->id() ],
			$gateway
		);

		if ( ! $result['ok'] ) {
			$this->redirect_back( 'error', $result['error'], self::EP_WALLET );
		}

		wp_redirect( $result['redirect_url'] ); // phpcs:ignore WordPress.Security.SafeRedirect -- external PSP.
		exit;
	}

	private function do_bnpl_pay( string $nonce ): void {
		if ( ! wp_verify_nonce( $nonce, 'igbz_bnpl_pay' ) ) {
			$this->redirect_back( 'error', __( 'Security check failed.', 'igbz-suite' ), self::EP_BNPL );
		}

		$installment_id = isset( $_POST['installment_id'] ) ? absint( wp_unslash( $_POST['installment_id'] ) ) : 0;

		/** @var BnplService $bnpl */
		$bnpl = igbz()->get( 'bnpl' );
		$paid = $bnpl->pay_installment_from_wallet( $installment_id );

		$this->redirect_back(
			$paid ? 'success' : 'error',
			$paid
				? __( 'Instalment paid from your wallet.', 'igbz-suite' )
				: __( 'The instalment could not be paid. Check your wallet balance.', 'igbz-suite' ),
			self::EP_BNPL
		);
	}

	private function do_affiliate_join( string $nonce ): void {
		if ( ! wp_verify_nonce( $nonce, 'igbz_affiliate_join' ) ) {
			$this->redirect_back( 'error', __( 'Security check failed.', 'igbz-suite' ), self::EP_AFFILIATE );
		}

		igbz()->get( 'affiliate' )->enroll( get_current_user_id(), igbz()->tenancy()->id() );
		$this->redirect_back( 'success', __( 'Welcome to the affiliate programme.', 'igbz-suite' ), self::EP_AFFILIATE );
	}

	private function redirect_back( string $type, string $message, string $endpoint ): void {
		set_transient( TenantScope::cache_key( 'igbz_flash_' . get_current_user_id() ), [ 'type' => $type, 'message' => $message ], 60 );
		wp_safe_redirect( wc_get_account_endpoint_url( $endpoint ) );
		exit;
	}

	private function flash(): void {
		$key   = TenantScope::cache_key( 'igbz_flash_' . get_current_user_id() );
		$flash = get_transient( $key );
		if ( ! is_array( $flash ) ) {
			return;
		}
		delete_transient( $key );

		printf(
			'<div class="woocommerce-%1$s">%2$s</div>',
			'error' === $flash['type'] ? 'error' : 'message',
			esc_html( (string) $flash['message'] )
		);
	}

	private function reason_label( string $reason ): string {
		$labels = [
			WalletService::REASON_TOPUP        => __( 'Wallet top-up', 'igbz-suite' ),
			WalletService::REASON_ORDER_PAY    => __( 'Order payment', 'igbz-suite' ),
			WalletService::REASON_REFUND       => __( 'Refund', 'igbz-suite' ),
			WalletService::REASON_CASHBACK     => __( 'Cashback', 'igbz-suite' ),
			WalletService::REASON_COMMISSION   => __( 'Affiliate commission', 'igbz-suite' ),
			WalletService::REASON_PAYOUT       => __( 'Affiliate payout', 'igbz-suite' ),
			WalletService::REASON_BNPL_PAY     => __( 'Instalment payment', 'igbz-suite' ),
			WalletService::REASON_SUBSCRIPTION => __( 'Subscription', 'igbz-suite' ),
			WalletService::REASON_PROMO        => __( 'Promotion', 'igbz-suite' ),
			WalletService::REASON_ADJUSTMENT   => __( 'Manual adjustment', 'igbz-suite' ),
			WalletService::REASON_IG_REWARD    => __( 'Instagram reward', 'igbz-suite' ),
		];

		return $labels[ $reason ] ?? $reason;
	}
}
