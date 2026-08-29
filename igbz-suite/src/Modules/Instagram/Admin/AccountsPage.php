<?php
namespace IGBZ\Suite\Modules\Instagram\Admin;

use IGBZ\Suite\Modules\Instagram\Gateways\ZernioAdapterInterface;
use IGBZ\Suite\Modules\Instagram\Services\SocialMigrationService;
use IGBZ\Suite\Modules\Instagram\Services\ZernioConnectionService;
use IGBZ\Suite\Support\Admin\Menu;
use IGBZ\Suite\Support\Admin\View;
use IGBZ\Suite\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * IG accounts (phase 50): the store's Zernio connection and the account rows.
 *
 * The page is deliberately narrow — one panel for the provider connection
 * (official OAuth: start → provider → sync), one table for the store's
 * accounts (brand profile data only; no credentials, ever) and the migration
 * ledger state. Publishing/inbox screens come back with the phase 51–53
 * rebuild on the same single provider.
 */
final class AccountsPage {

	public const SLUG = 'igbz-ig-accounts';

	private const NONCE = 'igbz_ig_accounts';

	private const TIMEZONES = [
		'Asia/Tehran'       => 'Asia/Tehran (Iran)',
		'Asia/Dubai'        => 'Asia/Dubai (Gulf)',
		'Europe/Istanbul'   => 'Europe/Istanbul',
		'Europe/London'     => 'Europe/London',
		'Europe/Berlin'     => 'Europe/Berlin',
		'America/New_York'  => 'America/New_York',
		'America/Los_Angeles' => 'America/Los_Angeles',
		'Australia/Sydney'  => 'Australia/Sydney',
	];

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 20 );
	}

	public function add_page(): void {
		Menu::add( self::SLUG, __( 'IG Accounts', 'igbz-suite' ), [ $this, 'render' ], Capabilities::MANAGE_INSTAGRAM );
	}

	/** @return ZernioConnectionService */
	private function zernio(): ZernioConnectionService {
		return igbz()->get( 'ig.zernio' );
	}

	/** @return ZernioAdapterInterface */
	private function client(): ZernioAdapterInterface {
		return igbz()->get( 'ig.zernio_client' );
	}

	/** @return SocialMigrationService */
	private function migration(): SocialMigrationService {
		return igbz()->get( 'ig.social_migration' );
	}

	public function render(): void {
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$this->handle_post();
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$account_id = isset( $_GET['account'] ) ? (int) $_GET['account'] : 0;
		// phpcs:enable

		View::open(
			__( 'Instagram accounts', 'igbz-suite' ),
			__( 'The store connects through the single social provider (Zernio) with official OAuth — no stored passwords, no scraped sessions. This page holds the connection and each account&rsquo;s brand profile.', 'igbz-suite' )
		);

		if ( $account_id > 0 ) {
			$this->render_editor( $account_id );
			View::close();
			return;
		}

		$this->render_connection();
		$this->render_list();
		$this->render_migration();
		View::close();
	}

	private function handle_post(): void {
		View::check_nonce( self::NONCE );
		// phpcs:disable WordPress.Security.NonceVerification.Missing — nonce verified above.
		$action     = (string) ( $_POST['action'] ?? '' );
		$tenant_id  = (int) igbz()->tenancy()->id();
		$account_id = (int) ( $_POST['account_id'] ?? 0 );
		// phpcs:enable

		try {
			switch ( $action ) {
				case 'save_account':
					$this->handle_save( $account_id );
					break;

				case 'provision':
					$slug = $this->store_slug( $tenant_id );
					if ( '' === $slug ) {
						$this->notify( false, __( 'This store has no slug yet; the profile name comes from it.', 'igbz-suite' ) );
						break;
					}
					$result = $this->zernio()->provision( $tenant_id, $slug );
					$this->notify( ! empty( $result['ok'] ), $this->connect_error_message( (string) $result['error'] ) );
					break;

				case 'connect':
					$result = $this->zernio()->start_connect( $tenant_id );
					if ( ! empty( $result['ok'] ) && '' !== (string) $result['auth_url'] ) {
						// Official OAuth: the provider redirects back, then the operator
						// presses "Sync accounts" here to close the loop (ADR-0004 §5).
						wp_safe_redirect( (string) $result['auth_url'] );
						exit;
					}
					$this->notify( false, $this->connect_error_message( (string) $result['error'] ) );
					break;

				case 'sync':
					$result = $this->zernio()->sync_accounts( $tenant_id );
					$this->notify( ! empty( $result['ok'] ), ! empty( $result['ok'] ) ? __( 'Account mapping synced — the store is connected.', 'igbz-suite' ) : $this->connect_error_message( (string) $result['error'] ) );
					break;

				case 'revoke':
					$result = $this->zernio()->revoke( $tenant_id );
					$this->notify( ! empty( $result['ok'] ), ! empty( $result['ok'] ) ? __( 'Connection revoked; the provider key was destroyed.', 'igbz-suite' ) : __( 'Revoke failed; the profile is in a state that cannot be revoked.', 'igbz-suite' ) );
					break;
			}
		} catch ( \Throwable $e ) {
			$this->notify( false, __( 'Action failed; check the security log for details.', 'igbz-suite' ) );
		}
	}

	private function handle_save( int $account_id ): void {
		if ( $account_id <= 0 ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing — nonce verified in handle_post().
		$tenant_id = (int) igbz()->tenancy()->id();
		$existing  = igbz()->db()->row(
			'SELECT * FROM ' . igbz()->db()->table( 'ig_accounts' ) . ' WHERE id = %d AND tenant_id = %d',
			[ $account_id, $tenant_id ]
		);
		// phpcs:enable
		if ( null === $existing ) {
			return;
		}

		// Brand profile data only — credential and provider columns are not
		// touchable from this form and no longer exist as inputs anywhere.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$data = [
			'name'       => sanitize_text_field( (string) ( $_POST['name'] ?? (string) $existing['name'] ) ),
			'username'   => sanitize_text_field( (string) ( $_POST['username'] ?? (string) $existing['username'] ) ),
			'niche'      => sanitize_text_field( (string) ( $_POST['niche'] ?? (string) $existing['niche'] ) ),
			'timezone'   => (string) ( $_POST['timezone'] ?? (string) $existing['timezone'] ),
			'brand_voice' => sanitize_textarea_field( (string) ( $_POST['brand_voice'] ?? (string) $existing['brand_voice'] ) ),
			'peak_hours' => sanitize_text_field( (string) ( $_POST['peak_hours'] ?? (string) $existing['peak_hours'] ) ),
			'is_active'  => isset( $_POST['is_active'] ) ? 1 : 0,
			'updated_at' => current_time( 'mysql', true ),
		];
		// phpcs:enable
		if ( ! array_key_exists( $data['timezone'], self::TIMEZONES ) ) {
			$data['timezone'] = 'Asia/Tehran';
		}

		igbz()->db()->update( 'ig_accounts', $data, [ 'id' => $account_id ] );
		$this->notify( true, __( 'Account saved.', 'igbz-suite' ) );
	}

	private function render_connection(): void {
		$tenant_id = (int) igbz()->tenancy()->id();
		$profile   = $this->zernio()->profile( $tenant_id );
		$configured = $this->client()->is_configured();

		echo '<h2>' . esc_html__( 'Provider connection (Zernio)', 'igbz-suite' ) . '</h2>';

		if ( ! $configured ) {
			View::notice(
				__( 'No central Zernio key is configured. Set it under IGBZ settings &rarr; Zernio first; nothing can connect until then.', 'igbz-suite' ),
				'error'
			);
			return;
		}

		if ( null === $profile ) {
			View::notice( __( 'This store has no provider profile yet.', 'igbz-suite' ), 'warning' );
			echo $this->button_form( 'provision', __( 'Create store profile', 'igbz-suite' ) );
			return;
		}

		$status     = (string) $profile['status'];
		$status_map = [
			ZernioConnectionService::STATUS_PENDING     => [ 'warn', __( 'pending', 'igbz-suite' ) ],
			ZernioConnectionService::STATUS_PROVISIONED => [ 'warn', __( 'provisioned — OAuth pending', 'igbz-suite' ) ],
			ZernioConnectionService::STATUS_CONNECTED   => [ 'ok', __( 'connected', 'igbz-suite' ) ],
			ZernioConnectionService::STATUS_REVOKED     => [ 'error', __( 'revoked', 'igbz-suite' ) ],
		];
		[$pill, $label] = $status_map[ $status ] ?? [ 'warn', $status ];

		echo '<p>' . View::status_pill( $pill ) . ' ' . esc_html( $label ) . '</p>';

		if ( ZernioConnectionService::STATUS_PROVISIONED === $status ) {
			echo '<p>' . esc_html__( 'Continue: start the official OAuth flow in your browser, finish it on the provider side, then sync the account back here.', 'igbz-suite' ) . '</p>';
			echo $this->button_form( 'connect', __( 'Connect via official OAuth', 'igbz-suite' ), 'button-primary' );
			echo $this->button_form( 'sync', __( 'Sync accounts after connecting', 'igbz-suite' ) );
		}

		if ( ZernioConnectionService::STATUS_CONNECTED === $status ) {
			echo '<p class="description">' . esc_html( sprintf(
				/* translators: 1: profile id, 2: key version */
				__( 'Profile %1$s, key version %2$d. Rotate or revoke from IGBZ settings &rarr; Zernio.', 'igbz-suite' ),
				mb_substr( (string) $profile['profile_id'], 0, 12 ),
				(int) $profile['key_version']
			) ) . '</p>';
			echo $this->button_form( 'revoke', __( 'Revoke connection', 'igbz-suite' ) );
		}

		if ( ZernioConnectionService::STATUS_REVOKED === $status ) {
			View::notice( __( 'This connection was revoked. Create a new profile to connect again.', 'igbz-suite' ), 'error' );
			echo $this->button_form( 'provision', __( 'Create new store profile', 'igbz-suite' ) );
		}
	}

	private function render_list(): void {
		echo '<h2>' . esc_html__( 'Accounts', 'igbz-suite' ) . '</h2>';

		$db   = igbz()->db();
		$rows = $db->results(
			'SELECT * FROM ' . $db->table( 'ig_accounts' ) . ' WHERE tenant_id = %d ORDER BY is_active DESC, id ASC',
			[ (int) igbz()->tenancy()->id() ]
		);

		if ( empty( $rows ) ) {
			View::notice( __( 'No account rows yet. The provider sync creates the mapping; the row above carries the brand profile.', 'igbz-suite' ), 'warning' );
			return;
		}

		View::table(
			[
				'id'          => __( 'ID', 'igbz-suite' ),
				'name'        => __( 'Name', 'igbz-suite' ),
				'username'    => __( 'Username', 'igbz-suite' ),
				'nich'        => __( 'Niche', 'igbz-suite' ),
				'peak_hours'  => __( 'Peak hours', 'igbz-suite' ),
				'status'      => __( 'Status', 'igbz-suite' ),
				'legacy'      => __( 'Legacy', 'igbz-suite' ),
				'action'      => '',
			],
			$rows,
			static function ( array $row, string $key ) {
				switch ( $key ) {
					case 'status':
						return ( 1 === (int) $row['is_active'] )
							? View::status_pill( 'ok' ) . ' ' . esc_html__( 'active', 'igbz-suite' )
							: View::status_pill( 'warn' ) . ' ' . esc_html__( 'inactive', 'igbz-suite' );
					case 'legacy':
						return empty( $row['legacy_deprecated_at'] )
							? esc_html__( '—', 'igbz-suite' )
							: View::status_pill( 'ok' ) . ' ' . esc_html( (string) $row['legacy_deprecated_at'] );
					case 'action':
						$url = esc_url( Menu::url( self::SLUG, [ 'account' => (int) $row['id'] ] ) );
						return '<a class="button" href="' . $url . '">' . esc_html__( 'Edit brand profile', 'igbz-suite' ) . '</a>';
				}
				return esc_html( (string) ( $row[ $key ] ?? '' ) );
			},
			__( 'No accounts in this store yet.', 'igbz-suite' )
		);
	}

	private function render_editor( int $account_id ): void {
		$tenant_id = (int) igbz()->tenancy()->id();
		$row       = igbz()->db()->row(
			'SELECT * FROM ' . igbz()->db()->table( 'ig_accounts' ) . ' WHERE id = %d AND tenant_id = %d',
			[ $account_id, $tenant_id ]
		);

		if ( null === $row ) {
			View::notice( __( 'Account not found in this store.', 'igbz-suite' ), 'error' );
			View::close();
			return;
		}

		echo '<h2>' . esc_html__( 'Brand profile', 'igbz-suite' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'What the rebuilt publishing flow (phase 53) writes from. Credentials and provider fields do not exist here by design.', 'igbz-suite' ) . '</p>';

		echo '<form method="post">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="save_account" />
				<input type="hidden" name="account_id" value="' . esc_attr( (string) $account_id ) . '" />';
		echo '<table class="form-table">';

		View::field( [ 'key' => 'name', 'label' => __( 'Account name', 'igbz-suite' ) ], (string) $row['name'] );
		View::field( [ 'key' => 'username', 'label' => __( 'Username', 'igbz-suite' ) ], (string) $row['username'] );
		View::field( [ 'key' => 'niche', 'label' => __( 'Niche', 'igbz-suite' ) ], (string) $row['niche'] );
		View::field(
			[
				'key'     => 'timezone',
				'label'   => __( 'Timezone', 'igbz-suite' ),
				'type'    => 'select',
				'options' => self::TIMEZONES,
			],
			(string) ( $row['timezone'] ?? 'Asia/Tehran' )
		);
		View::field(
			[
				'key'     => 'brand_voice',
				'label'   => __( 'Brand voice', 'igbz-suite' ),
				'type'    => 'textarea',
				'help'    => __( 'Tone, vocabulary, what the account never does. The publish flow turns this into the content brief.', 'igbz-suite' ),
			],
			(string) $row['brand_voice']
		);
		View::field(
			[
				'key'   => 'peak_hours',
				'label' => __( 'Peak posting hours', 'igbz-suite' ),
				'help'  => __( 'Comma separated 24h hours, e.g. 12,18,21. Empty = automatic (Zernio best-time).', 'igbz-suite' ),
			],
			(string) $row['peak_hours']
		);
		View::field( [ 'key' => 'is_active', 'label' => __( 'Active', 'igbz-suite' ), 'type' => 'checkbox' ], 1 === (int) $row['is_active'] );

		echo '</table>';
		submit_button( __( 'Save account', 'igbz-suite' ) );
		echo '</form>';
	}

	private function render_migration(): void {
		echo '<h2>' . esc_html__( 'Legacy &rarr; Zernio migration', 'igbz-suite' ) . '</h2>';

		$state = $this->migration()->status( (int) igbz()->tenancy()->id() );
		$journal = (array) ( $state['journal'] ?? [] );

		if ( empty( $journal ) && 'none' === (string) ( $state['profile_status'] ?? 'none' ) ) {
			echo '<p class="description">' . esc_html__( 'Nothing to migrate in this store yet; the hourly round journals each step as it lands.', 'igbz-suite' ) . '</p>';
			return;
		}

		$rows = [];
		foreach ( [ 'profile_ensured', 'legacy_deprecated' ] as $step ) {
			$rows[] = [
				'step'   => $step,
				'status' => (string) ( $journal[ $step ] ?? 'pending' ),
			];
		}

		View::table(
			[
				'step'   => __( 'Step', 'igbz-suite' ),
				'status' => __( 'State', 'igbz-suite' ),
			],
			$rows,
			static function ( array $row, string $key ) {
				if ( 'status' === $key ) {
					$pill = 'done' === $row['status'] ? 'ok' : ( 'failed' === $row['status'] ? 'error' : 'warn' );
					return View::status_pill( $pill ) . ' ' . esc_html( $row['status'] );
				}
				return esc_html( (string) $row[ $key ] );
			}
		);

		printf(
			'<p class="description">%s</p>',
			esc_html( sprintf(
				/* translators: 1: deprecated count, 2: total count */
				__( 'Legacy account rows: %1$d of %2$d deprecated (encrypted keys stay until offboarding).', 'igbz-suite' ),
				(int) ( $state['deprecated_accounts'] ?? 0 ),
				(int) ( $state['legacy_accounts'] ?? 0 )
			) )
		);
	}

	// ------------------------------------------------------------- helpers

	private function store_slug( int $tenant_id ): string {
		$row = igbz()->db()->row( 'SELECT slug FROM ' . igbz()->db()->table( 'tenants' ) . ' WHERE id = %d', $tenant_id );
		return (string) ( $row['slug'] ?? '' );
	}

	private function connect_error_message( string $error ): string {
		return match ( $error ) {
			'not_configured' => __( 'The central Zernio key is not configured yet (IGBZ settings &rarr; Zernio).', 'igbz-suite' ),
			'no_account_yet' => __( 'The provider has no account attached to this profile yet — finish the OAuth step first, then sync.', 'igbz-suite' ),
			'bad_state'      => __( 'The profile is not in a state that allows this action.', 'igbz-suite' ),
			default          => __( 'The provider request failed; the security log carries the detail. The queue will retry automatically.', 'igbz-suite' ),
		};
	}

	private function notify( bool $ok, string $message ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$redirect = add_query_arg(
			[ 'notice' => $ok ? 'ok' : 'error', 'msg' => rawurlencode( $message ) ],
			Menu::url( self::SLUG )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	private function button_form( string $action, string $label, string $class = '' ): string {
		$html = '<form method="post" style="display:inline-block;margin-right:8px;">';
		$html .= wp_nonce_field( self::NONCE, '_wpnonce', true, false );
		$html .= '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		$html .= '<button type="submit" class="button ' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button>';
		$html .= '</form>';

		return $html;
	}
}
