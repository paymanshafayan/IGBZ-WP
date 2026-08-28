<?php
namespace IGBZ\Suite\Modules\MultiTenant\Admin;

use IGBZ\Suite\Modules\MultiTenant\Marketplace\MarketplaceService;
use IGBZ\Suite\Modules\MultiTenant\Payments\PaymentService;
use IGBZ\Suite\Support\Admin\Menu;
use IGBZ\Suite\Support\Admin\View;
use IGBZ\Suite\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/** Payment attempts, gateway readiness, OTP traffic and marketplace feeds. */
final class PaymentsPage {

	public const SLUG = 'igbz-payments';

	private const PER_PAGE = 30;

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 16 );
	}

	public function add_page(): void {
		Menu::add( self::SLUG, __( 'Payments', 'igbz-suite' ), [ $this, 'render' ], Capabilities::MANAGE_SUITE );
	}

	private function payments(): PaymentService {
		return igbz()->get( 'payments' );
	}

	private function marketplace(): MarketplaceService {
		return igbz()->get( 'marketplace' );
	}

	public function render(): void {
		$this->handle_test();
		$this->handle_legal();
		$this->handle_get_actions();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'payments';
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$status = isset( $_GET['status'] ) ? sanitize_key( (string) $_GET['status'] ) : '';
		// phpcs:enable

		View::open(
			__( 'Payments', 'igbz-suite' ),
			__( 'Every attempt is persisted before the customer leaves the site, so an interrupted callback can always be replayed.', 'igbz-suite' )
		);

		View::tabs(
			[
				'payments'    => __( 'Attempts', 'igbz-suite' ),
				'gateways'    => __( 'Gateways', 'igbz-suite' ),
				'otp'         => __( 'OTP', 'igbz-suite' ),
				'legal'       => __( 'Legal', 'igbz-suite' ),
				'marketplace' => __( 'Marketplace feeds', 'igbz-suite' ),
			],
			$tab,
			self::SLUG
		);

		match ( $tab ) {
			'gateways'    => $this->render_gateways(),
			'otp'         => $this->render_otp(),
			'legal'       => $this->render_legal(),
			'marketplace' => $this->render_marketplace(),
			default       => $this->render_payments( $status, $paged ),
		};

		View::close();
	}

	private function handle_test(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['igbz_pay_action'] ) || 'test' !== $_POST['igbz_pay_action'] ) {
			return;
		}
		View::check_nonce( 'igbz_payment_test' );

		$id      = sanitize_key( (string) ( $_POST['gateway_id'] ?? '' ) );
		$gateway = $this->payments()->gateway( $id );
		if ( ! $gateway ) {
			View::notice( __( 'Unknown gateway.', 'igbz-suite' ), 'error' );
			return;
		}

		// A 1-Rial sandbox request verifies credentials + endpoint without charging.
		$result = $gateway->request(
			0.0001,
			add_query_arg( [ 'igbz_payment_callback' => $gateway->id(), 'payment_id' => 'test' ], home_url( '/' ) ),
			[ 'order_id' => 'TEST-' . time(), 'description' => 'IGBZ connection test' ]
		);

		if ( $result->success ) {
			View::notice( sprintf( /* translators: %s: gateway title */ __( '%s responded — connection OK.', 'igbz-suite' ), $gateway->title() ), 'success' );
		} else {
			View::notice( sprintf( /* translators: 1: gateway title, 2: error */ __( '%1$s test failed: %2$s', 'igbz-suite' ), $gateway->title(), $result->error_message ), 'error' );
		}
	}

	private function render_payments( string $status, int $paged ): void {
		$stats = $this->payments()->stats();
		echo '<div class="igbz-cards">';
		foreach (
			[
				__( 'Attempts', 'igbz-suite' )   => (string) $stats['count'],
				__( 'Successful', 'igbz-suite' ) => (string) $stats['paid'],
				__( 'Volume', 'igbz-suite' )     => View::money( (float) $stats['volume'] ),
			] as $label => $value
		) {
			printf( '<div class="igbz-card"><strong>%1$s</strong><span>%2$s</span></div>', esc_html( $value ), esc_html( $label ) );
		}
		echo '</div>';

		echo '<form method="get" style="margin:12px 0">';
		printf( '<input type="hidden" name="page" value="%s" /><input type="hidden" name="tab" value="payments" />', esc_attr( self::SLUG ) );
		echo '<select name="status">';
		printf( '<option value="">%s</option>', esc_html__( '— any status —', 'igbz-suite' ) );
		foreach (
			[
				PaymentService::STATUS_CREATED,
				PaymentService::STATUS_PENDING,
				PaymentService::STATUS_PAID,
				PaymentService::STATUS_FAILED,
				PaymentService::STATUS_CANCELLED,
			] as $value
		) {
			printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $value ), selected( $status, $value, false ) );
		}
		echo '</select> ';
		submit_button( __( 'Filter', 'igbz-suite' ), 'secondary', '', false );
		echo '</form>';

		$db     = igbz()->db();
		$where  = '' !== $status ? 'WHERE status = %s' : '';
		$params = '' !== $status ? [ $status ] : [];

		$total = (int) $db->scalar( 'SELECT COUNT(*) FROM ' . $db->table( 'payments' ) . ' ' . $where, ...$params );
		$rows  = $db->results(
			'SELECT * FROM ' . $db->table( 'payments' ) . ' ' . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d',
			...array_merge( $params, [ self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE ] )
		);

		$display = [];
		foreach ( $rows as $row ) {
			$user      = get_userdata( (int) $row['user_id'] );
			$display[] = [
				'id'      => '#' . (int) $row['id'],
				'user'    => esc_html( $user ? $user->display_name : '—' ),
				'gateway' => esc_html( (string) $row['gateway'] ),
				'purpose' => esc_html( (string) $row['purpose'] ),
				'amount'  => esc_html( View::money( (float) $row['amount'] ) ),
				'status'  => View::status_pill(
					match ( (string) $row['status'] ) {
						PaymentService::STATUS_PAID => 'ok',
						PaymentService::STATUS_CREATED, PaymentService::STATUS_PENDING => 'warn',
						default => 'error',
					}
				) . ' ' . esc_html( (string) $row['status'] ),
				'ref'     => esc_html( (string) ( $row['reference_id'] ?: $row['authority'] ) ),
				'card'    => esc_html( (string) $row['card_pan'] ),
				'error'   => esc_html( trim( $row['error_code'] . ' ' . $row['error_message'] ) ),
				'created' => esc_html( (string) $row['created_at'] ),
			];
		}

		View::table(
			[
				'id'      => __( 'Id', 'igbz-suite' ),
				'user'    => __( 'Customer', 'igbz-suite' ),
				'gateway' => __( 'Gateway', 'igbz-suite' ),
				'purpose' => __( 'Purpose', 'igbz-suite' ),
				'amount'  => __( 'Amount', 'igbz-suite' ),
				'status'  => __( 'Status', 'igbz-suite' ),
				'ref'     => __( 'Reference', 'igbz-suite' ),
				'card'    => __( 'Card', 'igbz-suite' ),
				'error'   => __( 'Error', 'igbz-suite' ),
				'created' => __( 'Created', 'igbz-suite' ),
			],
			$display,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'No payment attempts yet.', 'igbz-suite' )
		);

		View::pagination( $total, self::PER_PAGE, $paged, self::SLUG, [ 'tab' => 'payments', 'status' => $status ] );
	}

	private function render_gateways(): void {
		$default = igbz()->settings()->string( 'payments.default_gateway', 'zarinpal' );
		$rows    = [];

		foreach ( $this->payments()->gateways() as $gateway ) {
			$missing = [];
			foreach ( $gateway->required_settings() as $key ) {
				if ( ! igbz()->settings()->has( $key ) ) {
					$missing[] = $key;
				}
			}

			// Test connection button for each configured gateway.
			$test = '';
			if ( $gateway->is_configured() ) {
				$test = '<form method="post" style="display:inline">'
					. wp_nonce_field( 'igbz_payment_test', '_wpnonce', true, false )
					. '<input type="hidden" name="igbz_pay_action" value="test" />'
					. '<input type="hidden" name="gateway_id" value="' . esc_attr( $gateway->id() ) . '" />'
					. '<button class="button button-small">' . esc_html__( 'Test', 'igbz-suite' ) . '</button></form>';
			}

			$rows[] = [
				'title'    => esc_html( $gateway->title() ),
				'id'       => '<code>' . esc_html( $gateway->id() ) . '</code>',
				'ready'    => View::status_pill( $gateway->is_configured() ? 'ok' : 'error' )
					. ' ' . esc_html( $gateway->is_configured() ? __( 'configured', 'igbz-suite' ) : __( 'missing credentials', 'igbz-suite' ) ),
				'missing'  => $missing ? '<code>' . esc_html( implode( ', ', $missing ) ) . '</code>' : '—',
				'default'  => $default === $gateway->id() ? esc_html__( 'yes', 'igbz-suite' ) : '—',
				'test'     => $test,
				'callback' => '<code>' . esc_html(
					add_query_arg(
						[ 'igbz_payment_callback' => $gateway->id(), 'payment_id' => '{id}' ],
						home_url( '/' )
					)
				) . '</code>',
			];
		}

		View::table(
			[
				'title'    => __( 'Gateway', 'igbz-suite' ),
				'test'     => __( 'Test', 'igbz-suite' ),
				'id'       => __( 'Id', 'igbz-suite' ),
				'ready'    => __( 'Status', 'igbz-suite' ),
				'missing'  => __( 'Missing settings', 'igbz-suite' ),
				'default'  => __( 'Default', 'igbz-suite' ),
				'callback' => __( 'Callback URL', 'igbz-suite' ),
			],
			$rows,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'No gateways registered.', 'igbz-suite' )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Credentials live in Settings → Payments and are stored encrypted.', 'igbz-suite' )
		);
	}

	private function render_otp(): void {
		$db   = igbz()->db();
		$rows = $db->results(
			'SELECT id, tenant_id, phone, purpose, attempts, consumed_at, expires_at, created_at
			 FROM ' . $db->table( 'otp_codes' ) . ' ORDER BY id DESC LIMIT 50'
		);

		$display = [];
		foreach ( $rows as $row ) {
			$display[] = [
				'phone'    => esc_html( $this->mask_phone( (string) $row['phone'] ) ),
				'purpose'  => esc_html( (string) $row['purpose'] ),
				'attempts' => esc_html( (string) $row['attempts'] ),
				'state'    => $row['consumed_at']
					? View::status_pill( 'ok' ) . ' ' . esc_html__( 'used', 'igbz-suite' )
					: ( strtotime( (string) $row['expires_at'] ) < time()
						? View::status_pill( 'error' ) . ' ' . esc_html__( 'expired', 'igbz-suite' )
						: View::status_pill( 'warn' ) . ' ' . esc_html__( 'pending', 'igbz-suite' ) ),
				'expires'  => esc_html( (string) $row['expires_at'] ),
				'created'  => esc_html( (string) $row['created_at'] ),
			];
		}

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Only a hash of each code is stored; the plaintext never touches the database.', 'igbz-suite' )
		);

		View::table(
			[
				'phone'    => __( 'Phone', 'igbz-suite' ),
				'purpose'  => __( 'Purpose', 'igbz-suite' ),
				'attempts' => __( 'Attempts', 'igbz-suite' ),
				'state'    => __( 'State', 'igbz-suite' ),
				'expires'  => __( 'Expires', 'igbz-suite' ),
				'created'  => __( 'Created', 'igbz-suite' ),
			],
			$display,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'No codes have been sent.', 'igbz-suite' )
		);

		printf(
			'<p><a class="button" href="%1$s">%2$s</a></p>',
			esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'tab' => 'otp', 'purge_otp' => 1 ] ), 'igbz_payments_action' ) ),
			esc_html__( 'Purge expired codes', 'igbz-suite' )
		);
	}

	private function handle_legal(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || 'accept_legal_waiver' !== ( $_POST['igbz_pay_action'] ?? '' ) ) {
			return;
		}
		View::check_nonce( 'igbz_legal_waiver' );
		$result = igbz()->get( 'legal.waiver' )->accept( igbz()->tenancy()->id(), get_current_user_id() );
		View::notice( $result['ok'] ? __( 'Legal waiver accepted for this store.', 'igbz-suite' ) : $result['error'], $result['ok'] ? 'success' : 'error' );
	}

	private function render_legal(): void {
		$service = igbz()->get( 'legal.waiver' );
		$tenant_id = igbz()->tenancy()->id();
		$allowed = $service->payment_allowed( $tenant_id );
		echo '<h2>' . esc_html__( 'Bank payment legal gate', 'igbz-suite' ) . '</h2>';
		echo '<p>' . wp_kses_post( nl2br( esc_html( $service->text() ) ) ) . '</p>';
		printf( '<p><strong>%s</strong></p>', esc_html( $allowed['allowed'] ? __( 'Bank payments are allowed.', 'igbz-suite' ) : __( 'Bank payments are blocked until national-id matching or this waiver is accepted.', 'igbz-suite' ) ) );
		if ( ! $service->has_accepted( $tenant_id ) ) {
			echo '<form method="post">';
			wp_nonce_field( 'igbz_legal_waiver' );
			echo '<input type="hidden" name="igbz_pay_action" value="accept_legal_waiver">';
			submit_button( __( 'Accept legal waiver', 'igbz-suite' ), 'primary', 'submit', false );
			echo '</form>';
		}
	}

	private function render_marketplace(): void {
		$rows = [];
		foreach ( $this->marketplace()->channels() as $channel => $label ) {
			$enabled = $this->marketplace()->is_channel_enabled( $channel );
			$count   = count( $this->marketplace()->links( (int) igbz()->tenancy()->id(), $channel ) );
			$rows[]  = [
				'channel' => esc_html( $label ),
				'enabled' => View::status_pill( $enabled ? 'ok' : 'warn' )
					. ' ' . esc_html( $enabled ? __( 'enabled', 'igbz-suite' ) : __( 'disabled', 'igbz-suite' ) ),
				'links'   => esc_html( (string) $count ),
				'feed'    => sprintf(
					'<a href="%1$s" target="_blank" rel="noopener"><code>%1$s</code></a>',
					esc_url( $this->marketplace()->feed_url( $channel ) )
				),
			];
		}

		View::table(
			[
				'channel' => __( 'Channel', 'igbz-suite' ),
				'enabled' => __( 'Status', 'igbz-suite' ),
				'links'   => __( 'Linked products', 'igbz-suite' ),
				'feed'    => __( 'Feed URL', 'igbz-suite' ),
			],
			$rows,
			static fn ( array $row, string $key ): string => (string) $row[ $key ]
		);

		printf(
			'<p><a class="button" href="%1$s">%2$s</a> <span class="description">%3$s</span></p>',
			esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'tab' => 'marketplace', 'flush_feeds' => 1 ] ), 'igbz_payments_action' ) ),
			esc_html__( 'Flush feed cache', 'igbz-suite' ),
			esc_html__( 'Feeds are cached; flush after a bulk price change.', 'igbz-suite' )
		);

		$links   = $this->marketplace()->links( (int) igbz()->tenancy()->id() );
		$display = [];
		foreach ( array_slice( $links, 0, 50 ) as $link ) {
			$display[] = [
				'product'  => esc_html( get_the_title( (int) $link['product_id'] ) ?: '#' . $link['product_id'] ),
				'channel'  => esc_html( (string) $link['channel'] ),
				'external' => esc_html( (string) $link['external_id'] ),
				'status'   => esc_html( (string) $link['sync_status'] ),
				'message'  => esc_html( (string) $link['sync_message'] ),
				'synced'   => esc_html( (string) ( $link['last_synced_at'] ?? '—' ) ),
			];
		}

		echo '<h2>' . esc_html__( 'Channel links', 'igbz-suite' ) . '</h2>';
		View::table(
			[
				'product'  => __( 'Product', 'igbz-suite' ),
				'channel'  => __( 'Channel', 'igbz-suite' ),
				'external' => __( 'External id', 'igbz-suite' ),
				'status'   => __( 'Sync status', 'igbz-suite' ),
				'message'  => __( 'Message', 'igbz-suite' ),
				'synced'   => __( 'Last synced', 'igbz-suite' ),
			],
			$display,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'No products linked to a channel yet.', 'igbz-suite' )
		);
	}

	private function mask_phone( string $phone ): string {
		$len = strlen( $phone );
		return $len > 6 ? substr( $phone, 0, 4 ) . str_repeat( '*', $len - 6 ) . substr( $phone, -2 ) : $phone;
	}

	private function handle_get_actions(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['purge_otp'] ) && ! isset( $_GET['flush_feeds'] ) ) {
			return;
		}
		check_admin_referer( 'igbz_payments_action' );
		Capabilities::require( Capabilities::MANAGE_SUITE );

		if ( isset( $_GET['purge_otp'] ) ) {
			$db      = igbz()->db();
			// Phase 20: bounded batches even for the operator-triggered purge.
			$deleted = $db->delete_batches( 'otp_codes', 'expires_at < %s', [ current_time( 'mysql', true ) ] );
			View::notice(
				sprintf(
					/* translators: %d: number of deleted rows. */
					__( '%d expired codes removed.', 'igbz-suite' ),
					(int) $deleted
				)
			);
		}

		if ( isset( $_GET['flush_feeds'] ) ) {
			$this->marketplace()->flush_cache();
			View::notice( __( 'Feed cache flushed.', 'igbz-suite' ) );
		}
		// phpcs:enable
	}
}
