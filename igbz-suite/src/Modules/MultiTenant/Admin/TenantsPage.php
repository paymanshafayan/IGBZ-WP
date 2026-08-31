<?php
namespace IGBZ\Suite\Modules\MultiTenant\Admin;

use IGBZ\Suite\Modules\MultiTenant\Repository\Tenant;
use IGBZ\Suite\Modules\MultiTenant\Repository\TenantRepository;
use IGBZ\Suite\Support\Admin\Menu;
use IGBZ\Suite\Support\Admin\View;
use IGBZ\Suite\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Tenant CRUD: list, create, edit, members and custom domains.
 *
 * Port note: the nop version could only attach an existing customer to a store; here creating a
 * tenant can also create its owner account, which was listed as a blocker in PLACEMENT-GUIDE 6.
 */
final class TenantsPage {

	public const SLUG = 'igbz-tenants';

	private const PER_PAGE = 20;

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 10 );
	}

	public function add_page(): void {
		Menu::add( self::SLUG, __( 'Tenants', 'igbz-suite' ), [ $this, 'render' ], Capabilities::MANAGE_TENANTS );
	}

	private function repo(): TenantRepository {
		return igbz()->get( 'tenants' );
	}

	public function render(): void {
		$action = $this->query( 'action' );

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$this->handle_post();
		}

		if ( 'edit' === $action || 'new' === $action ) {
			$this->render_form( (int) $this->query( 'id' ) );
			return;
		}

		$this->render_list();
	}

	private function query( string $key, string $default = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list navigation only.
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : $default;
	}

	// ------------------------------------------------------------------ list

	private function render_list(): void {
		$status = $this->query( 'status' );
		$search = $this->query( 's' );
		$paged  = max( 1, (int) $this->query( 'paged', '1' ) );

		$args = [
			'status' => $status,
			'search' => $search,
			'limit'  => self::PER_PAGE,
			'offset' => ( $paged - 1 ) * self::PER_PAGE,
		];

		$tenants = $this->repo()->all( $args );
		$total   = $this->repo()->count( [ 'status' => $status ] );

		View::open(
			__( 'Tenant stores', 'igbz-suite' ),
			__( 'Every store hosted on this installation. Each row is isolated by tenant_id across the wallet, orders, courses and Instagram data.', 'igbz-suite' )
		);

		printf(
			'<a href="%1$s" class="page-title-action">%2$s</a>',
			esc_url( Menu::url( self::SLUG, [ 'action' => 'new' ] ) ),
			esc_html__( 'Add tenant', 'igbz-suite' )
		);

		echo '<form method="get" style="margin:12px 0">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::SLUG ) );
		echo '<select name="status">';
		printf( '<option value="">%s</option>', esc_html__( 'Any status', 'igbz-suite' ) );
		foreach ( $this->statuses() as $value => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $status, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select> ';
		printf(
			'<input type="search" name="s" value="%1$s" placeholder="%2$s" /> ',
			esc_attr( $search ),
			esc_attr__( 'Name or slug', 'igbz-suite' )
		);
		submit_button( __( 'Filter', 'igbz-suite' ), 'secondary', '', false );
		echo '</form>';

		$rows = [];
		foreach ( $tenants as $tenant ) {
			$rows[] = [
				'id'     => (string) $tenant->id,
				'name'   => sprintf(
					'<strong><a href="%1$s">%2$s</a></strong><br /><code>%3$s</code>',
					esc_url( Menu::url( self::SLUG, [ 'action' => 'edit', 'id' => $tenant->id ] ) ),
					esc_html( $tenant->name ),
					esc_html( $tenant->slug )
				),
				'owner'  => $this->owner_label( $tenant->owner_user_id ),
				'status' => View::status_pill( $this->status_severity( $tenant->status ) ) . ' ' . esc_html__( (string) $tenant->status, 'igbz-suite' ),
				'plan'   => $this->plan_label( $tenant->plan_id ),
				'wallet' => esc_html( View::money( $this->tenant_balance( $tenant->id ) ) ),
				'domain' => esc_html( $this->repo()->primary_domain( $tenant->id ) ),
			];
		}

		View::table(
			[
				'id'     => __( 'ID', 'igbz-suite' ),
				'name'   => __( 'Store', 'igbz-suite' ),
				'owner'  => __( 'Owner', 'igbz-suite' ),
				'status' => __( 'Status', 'igbz-suite' ),
				'plan'   => __( 'Plan', 'igbz-suite' ),
				'wallet' => __( 'Wallet liability', 'igbz-suite' ),
				'domain' => __( 'Primary domain', 'igbz-suite' ),
			],
			$rows,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'No tenants yet. Create the first store to get going.', 'igbz-suite' )
		);

		View::pagination( $total, self::PER_PAGE, $paged, self::SLUG, [ 'status' => $status, 's' => $search ] );
		View::close();
	}

	// ------------------------------------------------------------------ form

	private function render_form( int $id ): void {
		$tenant = $id ? $this->repo()->find( $id ) : null;

		View::open(
			$tenant ? __( 'Edit tenant', 'igbz-suite' ) : __( 'New tenant', 'igbz-suite' )
		);

		echo '<form method="post" action="' . esc_url( Menu::url( self::SLUG, [ 'action' => $tenant ? 'edit' : 'new', 'id' => $id ] ) ) . '">';
		wp_nonce_field( 'igbz_save_tenant' );
		printf( '<input type="hidden" name="igbz_action" value="save_tenant" /><input type="hidden" name="tenant_id" value="%d" />', (int) $id );

		echo '<table class="form-table" role="presentation"><tbody>';

		$this->text_row( 'name', __( 'Store name', 'igbz-suite' ), $tenant->name ?? '', true );
		$this->text_row( 'slug', __( 'Slug', 'igbz-suite' ), $tenant->slug ?? '', false, __( 'Left empty it is derived from the name. Changing it breaks existing path based URLs.', 'igbz-suite' ) );

		echo '<tr><th scope="row">' . esc_html__( 'Status', 'igbz-suite' ) . '</th><td><select name="status">';
		foreach ( $this->statuses() as $value => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $tenant->status ?? Tenant::STATUS_PENDING, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Owner', 'igbz-suite' ) . '</th><td>';
		wp_dropdown_users(
			[
				'name'             => 'owner_user_id',
				'selected'         => $tenant->owner_user_id ?? 0,
				'show_option_none' => __( '— create a new user —', 'igbz-suite' ),
				'option_none_value' => 0,
				'number'           => 200,
			]
		);
		echo '<p class="description">' . esc_html__( 'Choosing "create a new user" uses the e-mail and phone below to register the owner account and assign the tenant owner role.', 'igbz-suite' ) . '</p>';
		echo '</td></tr>';

		$this->text_row( 'owner_email', __( 'New owner e-mail', 'igbz-suite' ), '' );
		$this->text_row( 'owner_phone', __( 'New owner phone', 'igbz-suite' ), '' );

		echo '<tr><th scope="row">' . esc_html__( 'Plan', 'igbz-suite' ) . '</th><td><select name="plan_id">';
		printf( '<option value="0">%s</option>', esc_html__( '— none —', 'igbz-suite' ) );
		foreach ( igbz()->get( 'plans' )->plans( false ) as $plan ) {
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				(int) $plan['id'],
				selected( (int) ( $tenant->plan_id ?? 0 ), (int) $plan['id'], false ),
				esc_html( (string) $plan['name'] )
			);
		}
		echo '</select></td></tr>';

		$this->text_row( 'currency', __( 'Currency', 'igbz-suite' ), $tenant->currency ?? igbz()->settings()->string( 'general.default_currency', 'IRT' ) );
		$this->text_row( 'locale', __( 'Locale', 'igbz-suite' ), $tenant->locale ?? 'fa_IR' );
		$this->text_row( 'theme', __( 'Theme slug', 'igbz-suite' ), $tenant->theme ?? '' );
		$this->text_row( 'logo_url', __( 'Logo URL', 'igbz-suite' ), $tenant->logo_url ?? '' );
		$this->text_row( 'primary_color', __( 'Primary colour', 'igbz-suite' ), $tenant->primary_color ?? '', false, '#22aa66' );
		$this->text_row( 'trial_ends_at', __( 'Trial ends at', 'igbz-suite' ), $tenant->trial_ends_at ?? '', false, __( 'UTC, format YYYY-MM-DD HH:MM:SS. Leave empty for no trial.', 'igbz-suite' ) );

		echo '</tbody></table>';
		submit_button( $tenant ? __( 'Update tenant', 'igbz-suite' ) : __( 'Create tenant', 'igbz-suite' ) );
		echo '</form>';

		if ( $tenant ) {
			$this->render_members( $tenant );
			$this->render_domains( $tenant );
			$this->render_danger_zone( $tenant );
		}

		printf(
			'<p><a href="%1$s">&larr; %2$s</a></p>',
			esc_url( Menu::url( self::SLUG ) ),
			esc_html__( 'Back to the tenant list', 'igbz-suite' )
		);

		View::close();
	}

	private function text_row( string $name, string $label, string $value, bool $required = false, string $help = '' ): void {
		printf(
			'<tr><th scope="row"><label for="igbz_%1$s">%2$s</label></th><td><input type="text" class="regular-text" id="igbz_%1$s" name="%1$s" value="%3$s" %4$s />%5$s</td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $value ),
			$required ? 'required' : '',
			'' !== $help ? '<p class="description">' . esc_html( $help ) . '</p>' : ''
		);
	}

	private function render_members( Tenant $tenant ): void {
		echo '<h2>' . esc_html__( 'Staff members', 'igbz-suite' ) . '</h2>';

		$rows = [];
		foreach ( $this->repo()->members( $tenant->id ) as $member ) {
			$user   = get_userdata( (int) $member['user_id'] );
			$rows[] = [
				'user' => $user ? esc_html( $user->display_name . ' <' . $user->user_email . '>' ) : esc_html( '#' . $member['user_id'] ),
				'role' => esc_html( (string) $member['role'] ),
				'since' => esc_html( (string) $member['created_at'] ),
				'action' => sprintf(
					'<a class="button button-small" href="%1$s" onclick="return confirm(\'%2$s\')">%3$s</a>',
					esc_url(
						wp_nonce_url(
							Menu::url( self::SLUG, [ 'action' => 'edit', 'id' => $tenant->id, 'remove_member' => (int) $member['user_id'] ] ),
							'igbz_remove_member'
						)
					),
					esc_js( __( 'Remove this member?', 'igbz-suite' ) ),
					esc_html__( 'Remove', 'igbz-suite' )
				),
			];
		}

		View::table(
			[
				'user'   => __( 'User', 'igbz-suite' ),
				'role'   => __( 'Role', 'igbz-suite' ),
				'since'  => __( 'Member since', 'igbz-suite' ),
				'action' => __( 'Action', 'igbz-suite' ),
			],
			$rows,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'Only the owner has access so far.', 'igbz-suite' )
		);

		echo '<form method="post" style="margin-top:8px">';
		wp_nonce_field( 'igbz_add_member' );
		printf( '<input type="hidden" name="igbz_action" value="add_member" /><input type="hidden" name="tenant_id" value="%d" />', $tenant->id );
		wp_dropdown_users( [ 'name' => 'member_user_id', 'number' => 200 ] );
		echo ' <select name="member_role">';
		printf( '<option value="staff">%s</option>', esc_html__( 'Staff', 'igbz-suite' ) );
		printf( '<option value="manager">%s</option>', esc_html__( 'Manager', 'igbz-suite' ) );
		printf( '<option value="instructor">%s</option>', esc_html__( 'Instructor', 'igbz-suite' ) );
		echo '</select> ';
		submit_button( __( 'Add member', 'igbz-suite' ), 'secondary', '', false );
		echo '</form>';
	}

	private function render_domains( Tenant $tenant ): void {
		echo '<h2>' . esc_html__( 'Domains', 'igbz-suite' ) . '</h2>';

		$rows = [];
		foreach ( $this->repo()->domains( $tenant->id ) as $domain ) {
			$rows[] = [
				'domain'   => esc_html( (string) $domain['domain'] ),
				'primary'  => ! empty( $domain['is_primary'] ) ? esc_html__( 'yes', 'igbz-suite' ) : '',
				'verified' => empty( $domain['verified_at'] )
					? sprintf(
						'<a class="button button-small" href="%1$s">%2$s</a>',
						esc_url(
							wp_nonce_url(
								Menu::url( self::SLUG, [ 'action' => 'edit', 'id' => $tenant->id, 'verify_domain' => (int) $domain['id'] ] ),
								'igbz_verify_domain'
							)
						),
						esc_html__( 'Mark verified', 'igbz-suite' )
					)
					: esc_html( (string) $domain['verified_at'] ),
				'action'   => sprintf(
					'<a class="button button-small" href="%1$s">%2$s</a>',
					esc_url(
						wp_nonce_url(
							Menu::url( self::SLUG, [ 'action' => 'edit', 'id' => $tenant->id, 'delete_domain' => (int) $domain['id'] ] ),
							'igbz_delete_domain'
						)
					),
					esc_html__( 'Delete', 'igbz-suite' )
				),
			];
		}

		View::table(
			[
				'domain'   => __( 'Domain', 'igbz-suite' ),
				'primary'  => __( 'Primary', 'igbz-suite' ),
				'verified' => __( 'Verified', 'igbz-suite' ),
				'action'   => __( 'Action', 'igbz-suite' ),
			],
			$rows,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'No custom domain mapped; the store resolves by slug.', 'igbz-suite' )
		);

		echo '<form method="post" style="margin-top:8px">';
		wp_nonce_field( 'igbz_add_domain' );
		printf( '<input type="hidden" name="igbz_action" value="add_domain" /><input type="hidden" name="tenant_id" value="%d" />', $tenant->id );
		printf( '<input type="text" name="domain" class="regular-text" placeholder="%s" /> ', esc_attr__( 'shop.example.com', 'igbz-suite' ) );
		printf( '<label><input type="checkbox" name="is_primary" value="1" /> %s</label> ', esc_html__( 'Primary', 'igbz-suite' ) );
		submit_button( __( 'Add domain', 'igbz-suite' ), 'secondary', '', false );
		echo '</form>';
	}

	private function render_danger_zone( Tenant $tenant ): void {
		echo '<h2>' . esc_html__( 'Danger zone', 'igbz-suite' ) . '</h2>';
		echo '<form method="post" onsubmit="return confirm(\'' . esc_js( __( 'Delete this tenant? Its ledger and content rows stay in the database for auditing.', 'igbz-suite' ) ) . '\')">';
		wp_nonce_field( 'igbz_delete_tenant' );
		printf( '<input type="hidden" name="igbz_action" value="delete_tenant" /><input type="hidden" name="tenant_id" value="%d" />', $tenant->id );
		submit_button( __( 'Delete tenant', 'igbz-suite' ), 'delete', '', false );
		echo '</form>';
	}

	// ------------------------------------------------------------------ post

	private function handle_post(): void {
		Capabilities::require( Capabilities::MANAGE_TENANTS );

		$action = isset( $_POST['igbz_action'] ) ? sanitize_key( wp_unslash( $_POST['igbz_action'] ) ) : '';
		$id     = isset( $_POST['tenant_id'] ) ? (int) $_POST['tenant_id'] : 0;

		switch ( $action ) {
			case 'save_tenant':
				check_admin_referer( 'igbz_save_tenant' );
				$this->save_tenant( $id );
				break;

			case 'add_member':
				check_admin_referer( 'igbz_add_member' );
				$user_id = isset( $_POST['member_user_id'] ) ? (int) $_POST['member_user_id'] : 0;
				$role    = isset( $_POST['member_role'] ) ? sanitize_key( wp_unslash( $_POST['member_role'] ) ) : 'staff';
				if ( $user_id && $this->repo()->add_member( $id, $user_id, $role ) ) {
					$user = get_userdata( $user_id );
					if ( $user && ! in_array( 'administrator', (array) $user->roles, true ) ) {
						$user->add_role( 'instructor' === $role ? \IGBZ\Suite\Support\Capabilities::ROLE_INSTRUCTOR : \IGBZ\Suite\Support\Capabilities::ROLE_TENANT_STAFF );
					}
					View::notice( __( 'Member added.', 'igbz-suite' ) );
				} else {
					View::notice( __( 'That user is already a member.', 'igbz-suite' ), 'warning' );
				}
				break;

			case 'add_domain':
				check_admin_referer( 'igbz_add_domain' );
				$domain = isset( $_POST['domain'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['domain'] ) ) ) : '';
				$domain = preg_replace( '~^https?://~', '', $domain );
				$domain = trim( (string) $domain, '/ ' );
				if ( '' === $domain ) {
					View::notice( __( 'Enter a hostname.', 'igbz-suite' ), 'error' );
					break;
				}
				$this->repo()->add_domain( $id, $domain, ! empty( $_POST['is_primary'] ) );
				View::notice( __( 'Domain added. Point its DNS at this server, then mark it verified.', 'igbz-suite' ) );
				break;

			case 'delete_tenant':
				check_admin_referer( 'igbz_delete_tenant' );
				$this->repo()->delete( $id );
				View::notice( __( 'Tenant deleted.', 'igbz-suite' ) );
				wp_safe_redirect( Menu::url( self::SLUG ) );
				exit;
		}

		$this->handle_get_actions();
	}

	private function handle_get_actions(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- each branch verifies its own nonce.
		if ( isset( $_GET['remove_member'] ) ) {
			check_admin_referer( 'igbz_remove_member' );
			$this->repo()->remove_member( (int) $this->query( 'id' ), (int) $_GET['remove_member'] );
			View::notice( __( 'Member removed.', 'igbz-suite' ) );
		}
		if ( isset( $_GET['verify_domain'] ) ) {
			check_admin_referer( 'igbz_verify_domain' );
			$domain_id = (int) $_GET['verify_domain'];
			$result = igbz()->has( 'hub.domains' ) ? igbz()->get( 'hub.domains' )->check( $domain_id ) : [ 'ok' => false, 'message' => __( 'Domain verification service is unavailable.', 'igbz-suite' ) ];
			View::notice( $result['ok'] ? __( 'Domain verified after DNS check.', 'igbz-suite' ) : (string) ( $result['message'] ?? __( 'The required DNS record was not found.', 'igbz-suite' ) ), $result['ok'] ? 'success' : 'error' );
		}
		if ( isset( $_GET['delete_domain'] ) ) {
			check_admin_referer( 'igbz_delete_domain' );
			$this->repo()->delete_domain( (int) $_GET['delete_domain'] );
			View::notice( __( 'Domain removed.', 'igbz-suite' ) );
		}
		// phpcs:enable
	}

	private function save_tenant( int $id ): void {
		$field = static function ( string $key ): string {
			return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller verified.
		};

		$owner = isset( $_POST['owner_user_id'] ) ? (int) $_POST['owner_user_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$name  = $field( 'name' );

		if ( '' === $name ) {
			View::notice( __( 'A store name is required.', 'igbz-suite' ), 'error' );
			return;
		}

		if ( ! $owner ) {
			$owner = $this->create_owner( $field( 'owner_email' ), $field( 'owner_phone' ), $name );
			if ( ! $owner ) {
				return;
			}
		}

		$data = [
			'name'          => $name,
			'slug'          => $field( 'slug' ),
			'status'        => $field( 'status' ),
			'owner_user_id' => $owner,
			'plan_id'       => isset( $_POST['plan_id'] ) ? (int) $_POST['plan_id'] : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'currency'      => $field( 'currency' ),
			'locale'        => $field( 'locale' ),
			'theme'         => $field( 'theme' ),
			'logo_url'      => $field( 'logo_url' ),
			'primary_color' => $field( 'primary_color' ),
			'trial_ends_at' => '' !== $field( 'trial_ends_at' ) ? $field( 'trial_ends_at' ) : null,
		];

		if ( $id ) {
			$this->repo()->update( $id, $data );
			View::notice( __( 'Tenant updated.', 'igbz-suite' ) );
			return;
		}

		if ( '' === $data['slug'] ) {
			unset( $data['slug'] );
		}
		$new_id = $this->repo()->create( $data );
		if ( ! $new_id ) {
			View::notice( __( 'Could not create the tenant.', 'igbz-suite' ), 'error' );
			return;
		}

		$this->repo()->add_member( $new_id, $owner, 'owner' );
		if ( igbz()->has( 'domain' ) ) {
			$created = $this->repo()->find( $new_id );
			if ( $created ) {
				igbz()->get( 'domain' )->use_subdomain( $new_id, $created->slug );
			}
		}

		$plan_id = (int) $data['plan_id'];
		if ( $plan_id ) {
			igbz()->get( 'plans' )->subscribe( $new_id, $plan_id );
		}

		View::notice( __( 'Tenant created.', 'igbz-suite' ) );
		wp_safe_redirect( Menu::url( self::SLUG, [ 'action' => 'edit', 'id' => $new_id ] ) );
		exit;
	}

	private function create_owner( string $email, string $phone, string $store_name ): int {
		if ( ! is_email( $email ) ) {
			View::notice( __( 'Pick an existing user or provide a valid e-mail for the new owner.', 'igbz-suite' ), 'error' );
			return 0;
		}
		$existing = get_user_by( 'email', $email );
		if ( $existing ) {
			$existing->add_role( Capabilities::ROLE_TENANT_OWNER );
			return (int) $existing->ID;
		}

		$login = sanitize_user( current( explode( '@', $email ) ), true );
		if ( username_exists( $login ) ) {
			$login .= '_' . wp_generate_password( 4, false, false );
		}

		$user_id = wp_insert_user(
			[
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 20 ),
				'display_name' => $store_name,
				'role'         => Capabilities::ROLE_TENANT_OWNER,
			]
		);

		if ( is_wp_error( $user_id ) ) {
			View::notice( $user_id->get_error_message(), 'error' );
			return 0;
		}

		if ( '' !== $phone ) {
			update_user_meta( (int) $user_id, 'igbz_phone', $phone );
		}
		wp_new_user_notification( (int) $user_id, null, 'user' );

		return (int) $user_id;
	}

	// ---------------------------------------------------------------- helpers

	/** @return array<string,string> */
	private function statuses(): array {
		return [
			Tenant::STATUS_PENDING   => __( 'Pending', 'igbz-suite' ),
			Tenant::STATUS_TRIAL     => __( 'Trial', 'igbz-suite' ),
			Tenant::STATUS_ACTIVE    => __( 'Active', 'igbz-suite' ),
			Tenant::STATUS_SUSPENDED => __( 'Suspended', 'igbz-suite' ),
			Tenant::STATUS_CLOSED    => __( 'Closed', 'igbz-suite' ),
		];
	}

	private function status_severity( string $status ): string {
		return match ( $status ) {
			Tenant::STATUS_ACTIVE, Tenant::STATUS_TRIAL => 'ok',
			Tenant::STATUS_SUSPENDED, Tenant::STATUS_CLOSED => 'error',
			default => 'warn',
		};
	}

	private function owner_label( int $user_id ): string {
		$user = $user_id ? get_userdata( $user_id ) : null;
		return $user ? esc_html( $user->display_name ) : '<em>' . esc_html__( 'none', 'igbz-suite' ) . '</em>';
	}

	private function plan_label( int $plan_id ): string {
		if ( ! $plan_id ) {
			return '<em>' . esc_html__( 'free', 'igbz-suite' ) . '</em>';
		}
		$plan = igbz()->get( 'plans' )->plan( $plan_id );
		return esc_html( $plan['name'] ?? ( '#' . $plan_id ) );
	}

	private function tenant_balance( int $tenant_id ): float {
		$db = igbz()->db();
		return (float) $db->scalar(
			'SELECT COALESCE(SUM(balance),0) FROM ' . $db->table( 'wallet_balances' ) . ' WHERE tenant_id = %d',
			$tenant_id
		);
	}
}
