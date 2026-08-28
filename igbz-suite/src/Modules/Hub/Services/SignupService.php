<?php
namespace IGBZ\Suite\Modules\Hub\Services;

use IGBZ\Suite\Modules\MultiTenant\Payments\PaymentService;
use IGBZ\Suite\Modules\MultiTenant\Plans\PlanService;
use IGBZ\Suite\Modules\MultiTenant\Repository\Tenant;
use IGBZ\Suite\Modules\MultiTenant\Repository\TenantRepository;
use IGBZ\Suite\Support\Capabilities;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Self-service store provisioning driven from the mother site.
 *
 * Port note: this closes the worst blocker found in the audit. In the nopCommerce original
 * `ProvisionNewTenantStoreAsync` never created the owner customer, so a signup produced a store
 * nobody could log into, and the chosen plan id was accepted but never consumed. Here the user,
 * the tenant, the ownership role, the subscription and (when the plan costs money) the payment
 * attempt are all created in one place, and the caller gets a redirect URL back.
 */
final class SignupService {

	public function __construct(
		private TenantRepository $tenants,
		private PlanService $plans,
		private PaymentService $payments,
		private Logger $logger
	) {}

	public function enabled(): bool {
		return igbz()->settings()->bool( 'general.allow_self_signup', false );
	}

	/**
	 * @return array{available:bool,slug:string,url:string,message:string}
	 */
	public function check_slug( string $slug ): array {
		$slug = sanitize_title( $slug );

		if ( '' === $slug || strlen( $slug ) < 3 ) {
			return [
				'available' => false,
				'slug'      => $slug,
				'url'       => '',
				'message'   => __( 'The store address must be at least three characters.', 'igbz-suite' ),
			];
		}

		if ( in_array( $slug, $this->reserved_slugs(), true ) ) {
			return [
				'available' => false,
				'slug'      => $slug,
				'url'       => '',
				'message'   => __( 'This address is reserved.', 'igbz-suite' ),
			];
		}

		$taken = $this->tenants->find_by_slug( $slug ) instanceof Tenant;

		return [
			'available' => ! $taken,
			'slug'      => $slug,
			'url'       => $this->preview_url( $slug ),
			'message'   => $taken
				? __( 'This address is already taken.', 'igbz-suite' )
				: __( 'This address is free.', 'igbz-suite' ),
		];
	}

	/** @return string[] */
	private function reserved_slugs(): array {
		return (array) apply_filters(
			'igbz_hub_reserved_slugs',
			[ 'www', 'admin', 'api', 'app', 'shop', 'store', 'blog', 'mail', 'cdn', 'static', 'hub', 'igbz' ]
		);
	}

	private function preview_url( string $slug ): string {
		$base = igbz()->settings()->string( 'hub.subdomain_base', '' );
		if ( '' !== $base ) {
			return 'https://' . $slug . '.' . ltrim( $base, '.' ) . '/';
		}
		return home_url( '/' . trim( igbz()->settings()->string( 'general.tenant_path_base', 'store' ), '/' ) . '/' . $slug . '/' );
	}

	/**
	 * Create user + tenant + subscription (+ payment when the plan is not free).
	 *
	 * @param array<string,mixed> $data
	 * @return array{
	 *   ok:bool, error:string, tenant_id:int, user_id:int, store_url:string,
	 *   requires_payment:bool, payment_id:int, redirect_url:string, subscription_id:int
	 * }
	 */
	public function signup( array $data ): array {
		$fail = static fn ( string $error ): array => [
			'ok'               => false,
			'error'            => $error,
			'tenant_id'        => 0,
			'user_id'          => 0,
			'store_url'        => '',
			'requires_payment' => false,
			'payment_id'       => 0,
			'redirect_url'     => '',
			'subscription_id'  => 0,
		];

		if ( ! $this->enabled() ) {
			return $fail( __( 'Self sign-up is disabled.', 'igbz-suite' ) );
		}

		$name  = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
		$slug  = sanitize_title( (string) ( $data['slug'] ?? $name ) );
		$email = sanitize_email( (string) ( $data['email'] ?? '' ) );
		$phone = preg_replace( '/\D+/', '', (string) ( $data['phone'] ?? '' ) ) ?? '';

		if ( '' === $name || '' === $slug ) {
			return $fail( __( 'A store name and address are required.', 'igbz-suite' ) );
		}
		if ( ! is_email( $email ) ) {
			return $fail( __( 'A valid email address is required.', 'igbz-suite' ) );
		}

		$slug_check = $this->check_slug( $slug );
		if ( ! $slug_check['available'] ) {
			// Phase 16 idempotency: a re-submitted signup for a store that the very same
			// owner already provisioned returns that store instead of failing — retrying a
			// successful request must be safe.
			$taken_by      = $this->tenants->find_by_slug( $slug );
			$existing_user = get_user_by( 'email', $email );
			if ( $taken_by instanceof Tenant && $existing_user && (int) $taken_by->owner_user_id === (int) $existing_user->ID ) {
				$this->logger->info( 'hub', 'Signup re-run returned the existing store', [ 'tenant_id' => $taken_by->id, 'user_id' => (int) $existing_user->ID ] );
				return $this->result_for_existing( $taken_by, (int) $existing_user->ID );
			}
			return $fail( $slug_check['message'] );
		}

		$plan_id = (int) ( $data['plan_id'] ?? 0 );
		$plan    = $plan_id > 0 ? $this->plans->plan( $plan_id ) : null;
		if ( $plan_id > 0 && ! $plan ) {
			return $fail( __( 'The selected plan no longer exists.', 'igbz-suite' ) );
		}

		$user_id = $this->resolve_user( $email, $phone, (string) ( $data['password'] ?? '' ), $name );
		if ( is_wp_error( $user_id ) ) {
			return $fail( $user_id->get_error_message() );
		}

		$auto_approve = igbz()->settings()->bool( 'general.auto_approve_tenants', false );

		$tenant_id = $this->tenants->create(
			[
				'slug'          => $slug,
				'name'          => $name,
				'owner_user_id' => $user_id,
				'status'        => $auto_approve ? Tenant::STATUS_ACTIVE : Tenant::STATUS_PENDING,
				'plan_id'       => $plan_id,
				'currency'      => igbz()->settings()->string( 'general.default_currency', 'IRT' ),
				'settings'      => [
					'company' => sanitize_text_field( (string) ( $data['company'] ?? '' ) ),
					'phone'   => $phone,
					'source'  => 'hub_signup',
				],
			]
		);

		if ( $tenant_id <= 0 ) {
			return $fail( __( 'The store could not be created. Please try again.', 'igbz-suite' ) );
		}

		// Phase 16 partial rollback: everything between tenant creation and the payment
		// attempt either completes as a whole or is undone. A half-provisioned store —
		// owner without a membership, or a tenant without its subscription — is worse than
		// a clean failure the customer can retry. Deleting the brand-new tenant also runs
		// the offboarding sweep, so nothing created in between survives.
		$subscription_id = 0;
		try {
			$this->tenants->add_member( $tenant_id, $user_id, 'owner' );
			if ( igbz()->has( 'domain' ) ) {
				// Every provisioned store receives the free mother-site subdomain first;
				// a custom domain can be added and verified later without changing the tenant.
				$domain_result = igbz()->get( 'domain' )->use_subdomain( $tenant_id, $slug );
				if ( is_array( $domain_result ) && empty( $domain_result['ok'] ) ) {
					// The store stays reachable on the path base; a missing subdomain is a
					// degraded URL, not a provisioning failure.
					$this->logger->warning( 'hub', 'Signup subdomain step failed; path URL still works', [ 'tenant_id' => $tenant_id, 'error' => (string) ( $domain_result['error'] ?? '' ) ] );
				}
			}

			$user = get_userdata( $user_id );
			if ( $user && ! in_array( Capabilities::ROLE_TENANT_OWNER, (array) $user->roles, true ) ) {
				$user->add_role( Capabilities::ROLE_TENANT_OWNER );
			}

			if ( $plan ) {
				$subscription_id = $this->plans->subscribe( $tenant_id, $plan_id, true );
			}
		} catch ( \Throwable $e ) {
			$this->logger->error( 'hub', 'Provisioning failed; rolling back the new tenant', [ 'tenant_id' => $tenant_id, 'error' => $e->getMessage() ] );
			$this->tenants->remove_member( $tenant_id, $user_id );
			$this->tenants->delete( $tenant_id );
			return $fail( __( 'The store could not be set up. Nothing was charged — please try again.', 'igbz-suite' ) );
		}

		$payment_id       = 0;
		$redirect_url     = '';
		$requires_payment = false;

		if ( $plan ) {
			$price = $this->cycle_price( $plan, (string) ( $data['billing_cycle'] ?? '' ) );
			if ( $price > 0 && (int) $plan['trial_days'] <= 0 ) {
				$requires_payment = true;

				$attempt = $this->payments->start(
					$price,
					PaymentService::PURPOSE_SUBSCRIPTION,
					[
						'tenant_id'       => $tenant_id,
						'user_id'         => $user_id,
						'subscription_id' => $subscription_id,
						'plan_id'         => $plan_id,
						'return_url'      => esc_url_raw( (string) ( $data['return_url'] ?? '' ) ),
					],
					sanitize_key( (string) ( $data['gateway'] ?? '' ) )
				);

				$payment_id   = (int) $attempt['payment_id'];
				$redirect_url = (string) $attempt['redirect_url'];

				if ( ! $attempt['ok'] ) {
					$this->logger->error( 'hub', 'Signup payment could not start', [ 'tenant_id' => $tenant_id, 'error' => $attempt['error'] ] );
				}
			}
		}

		$this->logger->info(
			'hub',
			'Tenant provisioned from the hub',
			[ 'tenant_id' => $tenant_id, 'user_id' => $user_id, 'plan_id' => $plan_id ]
		);

		do_action( 'igbz_hub_signup_completed', $tenant_id, $user_id, $plan_id );

		$tenant = $this->tenants->find( $tenant_id );

		return [
			'ok'               => true,
			'error'            => '',
			'tenant_id'        => $tenant_id,
			'user_id'          => $user_id,
			'store_url'        => $tenant ? ( new DirectoryService( igbz()->db(), $this->tenants ) )->store_url( $tenant ) : home_url( '/' ),
			'requires_payment' => $requires_payment,
			'payment_id'       => $payment_id,
			'redirect_url'     => $redirect_url,
			'subscription_id'  => $subscription_id,
		];
	}

	/**
	 * Phase 16: shape the return value for a store that already exists, so a re-run of the
	 * same signup request looks exactly like a success and never duplicates anything.
	 *
	 * @return array{
	 *   ok:bool, error:string, tenant_id:int, user_id:int, store_url:string,
	 *   requires_payment:bool, payment_id:int, redirect_url:string, subscription_id:int
	 * }
	 */
	private function result_for_existing( Tenant $tenant, int $user_id ): array {
		return [
			'ok'               => true,
			'error'            => '',
			'tenant_id'        => $tenant->id,
			'user_id'          => $user_id,
			'store_url'        => ( new DirectoryService( igbz()->db(), $this->tenants ) )->store_url( $tenant ),
			'requires_payment' => false,
			'payment_id'       => 0,
			'redirect_url'     => '',
			'subscription_id'  => 0,
		];
	}

	/** @param array<string,mixed> $plan */
	private function cycle_price( array $plan, string $cycle ): float {
		$price = (float) $plan['price'];

		return match ( strtolower( $cycle ) ) {
			'sixmonths', 'six_months', '6months' => $price * 6 * ( 1 - igbz()->settings()->float( 'plans.six_month_discount', 0.1 ) ),
			'yearly', 'annual'                   => $price * 12 * ( 1 - igbz()->settings()->float( 'plans.yearly_discount', 0.2 ) ),
			default                              => $price,
		};
	}

	/** @return int|\WP_Error */
	private function resolve_user( string $email, string $phone, string $password, string $display_name ) {
		$existing = get_user_by( 'email', $email );
		if ( $existing ) {
			return (int) $existing->ID;
		}

		$login = sanitize_user( current( explode( '@', $email ) ), true );
		if ( '' === $login || username_exists( $login ) ) {
			$login = 'igbz_' . wp_generate_password( 8, false, false );
		}

		$user_id = wp_insert_user(
			[
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => '' !== $password ? $password : wp_generate_password( 16 ),
				'display_name' => $display_name,
				'role'         => 'customer',
			]
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		if ( '' !== $phone ) {
			update_user_meta( $user_id, 'billing_phone', $phone );
			update_user_meta( $user_id, 'igbz_phone', $phone );
		}

		if ( '' === $password ) {
			// No password chosen on the mother site: send the WordPress "set your password" email.
			wp_new_user_notification( (int) $user_id, null, 'user' );
		}

		return (int) $user_id;
	}
}
