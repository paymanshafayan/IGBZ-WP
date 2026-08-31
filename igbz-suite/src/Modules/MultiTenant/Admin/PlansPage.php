<?php
namespace IGBZ\Suite\Modules\MultiTenant\Admin;

use IGBZ\Suite\Modules\MultiTenant\Plans\PlanService;
use IGBZ\Suite\Support\Admin\Menu;
use IGBZ\Suite\Support\Admin\View;
use IGBZ\Suite\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/** Subscription plans and the tenant subscriptions attached to them. */
final class PlansPage {

	public const SLUG = 'igbz-plans';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 12 );
	}

	public function add_page(): void {
		Menu::add( self::SLUG, __( 'Plans', 'igbz-suite' ), [ $this, 'render' ], Capabilities::MANAGE_PLANS );
	}

	private function plans(): PlanService {
		return igbz()->get( 'plans' );
	}

	public function render(): void {
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$this->handle_post();
		}
		$this->handle_get_actions();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;

		View::open(
			__( 'Subscription plans', 'igbz-suite' ),
			__( 'Plans price the storefront itself: what a tenant pays you, its quotas and which features it unlocks.', 'igbz-suite' )
		);

		$this->render_plan_list();
		$this->render_plan_form( $edit_id );
		$this->render_subscriptions();

		View::close();
	}

	private function render_plan_list(): void {
		$rows = [];
		foreach ( $this->plans()->plans( false ) as $plan ) {
			$rows[] = [
				'name'     => sprintf(
					'<strong>%1$s</strong><br /><code>%2$s</code>',
					esc_html( (string) $plan['name'] ),
					esc_html( (string) $plan['slug'] )
				),
				'price'    => esc_html( View::money( (float) $plan['price'] ) . ' / ' . $plan['billing_period'] ),
				'trial'    => esc_html( sprintf( '%d', (int) $plan['trial_days'] ) ),
				'quotas'   => esc_html(
					sprintf(
						/* translators: 1: product quota, 2: order quota, 3: staff quota. 0 means unlimited. */
						__( 'products %1$s / orders %2$s / staff %3$s', 'igbz-suite' ),
						$plan['max_products'] ? $plan['max_products'] : '∞',
						$plan['max_orders'] ? $plan['max_orders'] : '∞',
						$plan['max_staff'] ? $plan['max_staff'] : '∞'
					)
				),
				'active'   => View::status_pill( $plan['is_active'] ? 'ok' : 'warn' ),
				'tenants'  => esc_html( (string) $this->tenant_count( (int) $plan['id'] ) ),
				'actions'  => sprintf(
					'<a class="button button-small" href="%1$s">%2$s</a> <a class="button button-small" href="%3$s" onclick="return confirm(\'%4$s\')">%5$s</a>',
					esc_url( Menu::url( self::SLUG, [ 'edit' => (int) $plan['id'] ] ) ),
					esc_html__( 'Edit', 'igbz-suite' ),
					esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'delete' => (int) $plan['id'] ] ), 'igbz_delete_plan' ) ),
					esc_js( __( 'Delete this plan?', 'igbz-suite' ) ),
					esc_html__( 'Delete', 'igbz-suite' )
				),
			];
		}

		View::table(
			[
				'name'    => __( 'Plan', 'igbz-suite' ),
				'price'   => __( 'Price', 'igbz-suite' ),
				'trial'   => __( 'Trial days', 'igbz-suite' ),
				'quotas'  => __( 'Quotas', 'igbz-suite' ),
				'active'  => __( 'Active', 'igbz-suite' ),
				'tenants' => __( 'Tenants', 'igbz-suite' ),
				'actions' => __( 'Actions', 'igbz-suite' ),
			],
			$rows,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'No plans yet. Create one below.', 'igbz-suite' )
		);
	}

	private function render_plan_form( int $id ): void {
		$plan     = $id ? $this->plans()->plan( $id ) : null;
		$features = $plan ? (array) json_decode( (string) ( $plan['features'] ?? '[]' ), true ) : [];

		echo '<h2>' . esc_html( $plan ? __( 'Edit plan', 'igbz-suite' ) : __( 'New plan', 'igbz-suite' ) ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'igbz_save_plan' );
		printf( '<input type="hidden" name="igbz_action" value="save_plan" /><input type="hidden" name="plan_id" value="%d" />', $id );
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->row( 'name', __( 'Name', 'igbz-suite' ), (string) ( $plan['name'] ?? '' ), 'text', true );
		$this->row( 'slug', __( 'Slug', 'igbz-suite' ), (string) ( $plan['slug'] ?? '' ) );
		$this->row( 'price', __( 'Price', 'igbz-suite' ), (string) ( $plan['price'] ?? '0' ), 'number' );
		$this->row( 'currency', __( 'Currency', 'igbz-suite' ), (string) ( $plan['currency'] ?? igbz()->settings()->string( 'general.default_currency', 'IRT' ) ) );

		echo '<tr><th scope="row">' . esc_html__( 'Billing period', 'igbz-suite' ) . '</th><td><select name="billing_period">';
		foreach (
			[
				'monthly'   => __( 'Monthly', 'igbz-suite' ),
				'quarterly' => __( 'Quarterly', 'igbz-suite' ),
				'yearly'    => __( 'Yearly', 'igbz-suite' ),
				'lifetime'  => __( 'Lifetime', 'igbz-suite' ),
			] as $value => $label
		) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( (string) ( $plan['billing_period'] ?? 'monthly' ), $value, false ),
				esc_html( $label )
			);
		}
		echo '</select></td></tr>';

		$this->row( 'trial_days', __( 'Trial days', 'igbz-suite' ), (string) ( $plan['trial_days'] ?? '0' ), 'number' );
		$this->row( 'max_products', __( 'Max products (0 = unlimited)', 'igbz-suite' ), (string) ( $plan['max_products'] ?? '0' ), 'number' );
		$this->row( 'max_orders', __( 'Max orders per period', 'igbz-suite' ), (string) ( $plan['max_orders'] ?? '0' ), 'number' );
		$this->row( 'max_staff', __( 'Max staff', 'igbz-suite' ), (string) ( $plan['max_staff'] ?? '0' ), 'number' );
		$this->row( 'sort_order', __( 'Sort order', 'igbz-suite' ), (string) ( $plan['sort_order'] ?? '0' ), 'number' );

		printf(
			'<tr><th scope="row">%1$s</th><td><textarea name="description" rows="3" class="large-text">%2$s</textarea></td></tr>',
			esc_html__( 'Description', 'igbz-suite' ),
			esc_textarea( (string) ( $plan['description'] ?? '' ) )
		);

		echo '<tr><th scope="row">' . esc_html__( 'Features', 'igbz-suite' ) . '</th><td>';
		foreach ( $this->available_features() as $slug => $label ) {
			printf(
				'<label style="display:inline-block;min-width:220px"><input type="checkbox" name="features[]" value="%1$s" %2$s /> %3$s</label>',
				esc_attr( $slug ),
				checked( in_array( $slug, $features, true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</td></tr>';

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="is_active" value="1" %2$s /> %3$s</label></td></tr>',
			esc_html__( 'Active', 'igbz-suite' ),
			checked( (bool) ( $plan['is_active'] ?? 1 ), true, false ),
			esc_html__( 'Offer this plan to new tenants', 'igbz-suite' )
		);

		echo '</tbody></table>';
		submit_button( $plan ? __( 'Update plan', 'igbz-suite' ) : __( 'Create plan', 'igbz-suite' ) );
		echo '</form>';
	}

	private function render_subscriptions(): void {
		$db   = igbz()->db();
		$rows = $db->results(
			'SELECT s.*, t.name AS tenant_name, p.name AS plan_name
			 FROM ' . $db->table( 'subscriptions' ) . ' s
			 LEFT JOIN ' . $db->table( 'tenants' ) . ' t ON t.id = s.tenant_id
			 LEFT JOIN ' . $db->table( 'plans' ) . ' p ON p.id = s.plan_id
			 ORDER BY s.id DESC LIMIT 50'
		);

		$display = [];
		foreach ( $rows as $row ) {
			$display[] = [
				'tenant'  => esc_html( (string) ( $row['tenant_name'] ?? '#' . $row['tenant_id'] ) ),
				'plan'    => esc_html( (string) ( $row['plan_name'] ?? '#' . $row['plan_id'] ) ),
				'status'  => View::status_pill(
					match ( (string) $row['status'] ) {
						PlanService::STATUS_ACTIVE, PlanService::STATUS_TRIALING => 'ok',
						PlanService::STATUS_PAST_DUE => 'warn',
						default => 'error',
					}
				) . ' ' . esc_html__( (string) $row['status'], 'igbz-suite' ),
				'started' => esc_html( (string) $row['starts_at'] ),
				'ends'    => esc_html( (string) ( $row['ends_at'] ?? '—' ) ),
				'renew'   => esc_html( $row['auto_renew'] ? __( 'yes', 'igbz-suite' ) : __( 'no', 'igbz-suite' ) ),
				'failures' => esc_html( (string) $row['renewal_failures'] ),
				'actions' => PlanService::STATUS_CANCELLED === (string) $row['status'] ? '' : sprintf(
					'<a class="button button-small" href="%1$s">%2$s</a> <a class="button button-small" href="%3$s">%4$s</a>',
					esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'renew' => (int) $row['id'] ] ), 'igbz_sub_action' ) ),
					esc_html__( 'Renew now', 'igbz-suite' ),
					esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'cancel' => (int) $row['id'] ] ), 'igbz_sub_action' ) ),
					esc_html__( 'Cancel', 'igbz-suite' )
				),
			];
		}

		echo '<h2>' . esc_html__( 'Subscriptions', 'igbz-suite' ) . '</h2>';
		View::table(
			[
				'tenant'   => __( 'Tenant', 'igbz-suite' ),
				'plan'     => __( 'Plan', 'igbz-suite' ),
				'status'   => __( 'Status', 'igbz-suite' ),
				'started'  => __( 'Started', 'igbz-suite' ),
				'ends'     => __( 'Ends', 'igbz-suite' ),
				'renew'    => __( 'Auto renew', 'igbz-suite' ),
				'failures' => __( 'Failed renewals', 'igbz-suite' ),
				'actions'  => __( 'Actions', 'igbz-suite' ),
			],
			$display,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'Nobody is subscribed yet.', 'igbz-suite' )
		);
	}

	private function row( string $name, string $label, string $value, string $type = 'text', bool $required = false ): void {
		printf(
			'<tr><th scope="row"><label for="igbz_%1$s">%2$s</label></th><td><input type="%3$s" step="any" id="igbz_%1$s" name="%1$s" value="%4$s" class="regular-text" %5$s /></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $value ),
			$required ? 'required' : ''
		);
	}

	/** @return array<string,string> */
	private function available_features(): array {
		return [
			'wallet'      => __( 'Wallet', 'igbz-suite' ),
			'bnpl'        => __( 'Instalments (BNPL)', 'igbz-suite' ),
			'affiliate'   => __( 'Affiliate programme', 'igbz-suite' ),
			'lms'         => __( 'Courses', 'igbz-suite' ),
			'instagram'   => __( 'Instagram automation', 'igbz-suite' ),
			'social'      => __( 'Social (publishing + inbox via Zernio)', 'igbz-suite' ),
			'marketplace' => __( 'Marketplace feeds', 'igbz-suite' ),
			'api'         => __( 'Mobile API', 'igbz-suite' ),
			'custom_domain' => __( 'Custom domain', 'igbz-suite' ),
		];
	}

	private function tenant_count( int $plan_id ): int {
		$db = igbz()->db();
		return (int) $db->scalar(
			'SELECT COUNT(*) FROM ' . $db->table( 'subscriptions' ) . ' WHERE plan_id = %d AND status IN (%s,%s)',
			$plan_id,
			PlanService::STATUS_ACTIVE,
			PlanService::STATUS_TRIALING
		);
	}

	private function handle_post(): void {
		Capabilities::require( Capabilities::MANAGE_PLANS );
		check_admin_referer( 'igbz_save_plan' );

		$id   = isset( $_POST['plan_id'] ) ? (int) $_POST['plan_id'] : 0;
		$text = static fn ( string $key ): string => isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$data = [
			'name'           => $text( 'name' ),
			'slug'           => $text( 'slug' ),
			'price'          => isset( $_POST['price'] ) ? (float) $_POST['price'] : 0.0,
			'currency'       => $text( 'currency' ),
			'billing_period' => $text( 'billing_period' ),
			'trial_days'     => isset( $_POST['trial_days'] ) ? (int) $_POST['trial_days'] : 0,
			'max_products'   => isset( $_POST['max_products'] ) ? (int) $_POST['max_products'] : 0,
			'max_orders'     => isset( $_POST['max_orders'] ) ? (int) $_POST['max_orders'] : 0,
			'max_staff'      => isset( $_POST['max_staff'] ) ? (int) $_POST['max_staff'] : 0,
			'sort_order'     => isset( $_POST['sort_order'] ) ? (int) $_POST['sort_order'] : 0,
			'description'    => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
			'features'       => isset( $_POST['features'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['features'] ) ) : [],
			'is_active'      => ! empty( $_POST['is_active'] ),
		];

		if ( '' === $data['name'] ) {
			View::notice( __( 'The plan needs a name.', 'igbz-suite' ), 'error' );
			return;
		}

		$this->plans()->save_plan( $data, $id );
		View::notice( $id ? __( 'Plan updated.', 'igbz-suite' ) : __( 'Plan created.', 'igbz-suite' ) );
	}

	private function handle_get_actions(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['delete'] ) ) {
			check_admin_referer( 'igbz_delete_plan' );
			Capabilities::require( Capabilities::MANAGE_PLANS );
			if ( $this->plans()->delete_plan( (int) $_GET['delete'] ) ) {
				View::notice( __( 'Plan deleted.', 'igbz-suite' ) );
			} else {
				View::notice( __( 'That plan still has active subscriptions; deactivate it instead.', 'igbz-suite' ), 'error' );
			}
		}
		if ( isset( $_GET['renew'] ) ) {
			check_admin_referer( 'igbz_sub_action' );
			Capabilities::require( Capabilities::MANAGE_PLANS );
			$ok = $this->plans()->renew( (int) $_GET['renew'] );
			View::notice(
				$ok ? __( 'Subscription renewed.', 'igbz-suite' ) : __( 'Renewal failed; check the wallet balance of the tenant owner.', 'igbz-suite' ),
				$ok ? 'success' : 'error'
			);
		}
		if ( isset( $_GET['cancel'] ) ) {
			check_admin_referer( 'igbz_sub_action' );
			Capabilities::require( Capabilities::MANAGE_PLANS );
			$this->plans()->cancel( (int) $_GET['cancel'] );
			View::notice( __( 'Subscription cancelled at period end.', 'igbz-suite' ) );
		}
		// phpcs:enable
	}
}
