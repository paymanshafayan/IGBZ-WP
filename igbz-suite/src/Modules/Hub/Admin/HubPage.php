<?php
namespace IGBZ\Suite\Modules\Hub\Admin;

use IGBZ\Suite\Modules\Hub\Rest\Cors;
use IGBZ\Suite\Modules\Hub\Rest\HubController;
use IGBZ\Suite\Modules\Hub\Services\ContentBlockService;
use IGBZ\Suite\Modules\Hub\Services\DirectoryService;
use IGBZ\Suite\Modules\Hub\Services\DomainVerifier;
use IGBZ\Suite\Modules\Hub\Services\HubStats;
use IGBZ\Suite\Modules\Hub\Services\VipLinkService;
use IGBZ\Suite\Support\TenantScope;
use IGBZ\Suite\Modules\MultiTenant\Repository\TenantRepository;
use IGBZ\Suite\Support\Admin\Menu;
use IGBZ\Suite\Support\Admin\View;
use IGBZ\Suite\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/** Master-site hub: platform aggregates, custom domains, landing blocks and VIP links. */
final class HubPage {

	public const SLUG = 'igbz-hub';

	private const NONCE = 'igbz_hub';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 30 );
	}

	public function add_page(): void {
		Menu::add( self::SLUG, __( 'Master hub', 'igbz-suite' ), [ $this, 'render' ], Capabilities::MANAGE_TENANTS );
	}

	private function stats(): HubStats {
		return igbz()->get( 'hub.stats' );
	}

	private function directory(): DirectoryService {
		return igbz()->get( 'hub.directory' );
	}

	private function domains(): DomainVerifier {
		return igbz()->get( 'hub.domains' );
	}

	private function blocks(): ContentBlockService {
		return igbz()->get( 'hub.blocks' );
	}

	private function vip(): VipLinkService {
		return igbz()->get( 'hub.vip' );
	}

	private function tenants(): TenantRepository {
		return igbz()->tenancy()->repository();
	}

	public function render(): void {
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$this->handle_post();
		}
		$this->handle_get_actions();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'overview';

		View::open(
			__( 'Master site hub', 'igbz-suite' ),
			__( 'Everything the separate mother site reads: platform aggregates, the store directory, custom domains and signed VIP hand-off links.', 'igbz-suite' )
		);

		View::tabs(
			[
				'overview' => __( 'Overview', 'igbz-suite' ),
				'domains'  => __( 'Custom domains', 'igbz-suite' ),
				'blocks'   => __( 'Landing blocks', 'igbz-suite' ),
				'vip'      => __( 'VIP links', 'igbz-suite' ),
				'api'      => __( 'Hub API', 'igbz-suite' ),
			],
			$tab,
			self::SLUG
		);

		match ( $tab ) {
			'domains' => $this->render_domains(),
			'blocks'  => $this->render_blocks(),
			'vip'     => $this->render_vip(),
			'api'     => $this->render_api(),
			default   => $this->render_overview(),
		};

		View::close();
	}

	// ------------------------------------------------------------ overview

	private function render_overview(): void {
		$summary = $this->stats()->summary();

		echo '<div class="igbz-cards">';
		foreach (
			[
				__( 'Stores', 'igbz-suite' )            => (string) $summary['tenants'],
				__( 'Active', 'igbz-suite' )            => (string) $summary['active_tenants'],
				__( 'Suspended', 'igbz-suite' )         => (string) $summary['suspended_tenants'],
				__( 'Custom domains', 'igbz-suite' )    => (string) $summary['domains'],
				__( 'Awaiting DNS', 'igbz-suite' )      => (string) $summary['pending_domains'],
				__( 'Subscriptions', 'igbz-suite' )     => (string) $summary['subscriptions'],
				__( 'Monthly recurring', 'igbz-suite' ) => View::money( (float) $summary['mrr'] ),
				__( 'Paid orders', 'igbz-suite' )       => (string) $summary['orders'],
				__( 'Order volume', 'igbz-suite' )      => View::money( (float) $summary['revenue'] ),
			] as $label => $value
		) {
			printf( '<div class="igbz-card"><strong>%1$s</strong><span>%2$s</span></div>', esc_html( $value ), esc_html( $label ) );
		}
		echo '</div>';

		printf(
			'<p><a class="button" href="%1$s">%2$s</a> <span class="description">%3$s</span></p>',
			esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'run' => 'refresh' ] ), self::NONCE ) ),
			esc_html__( 'Refresh now', 'igbz-suite' ),
			esc_html(
				sprintf(
					/* translators: %s: timestamp */
					__( 'Last refreshed %s (UTC). Recomputed automatically on the hub sync interval.', 'igbz-suite' ),
					(string) $summary['refreshed_at']
				)
			)
		);

		echo '<h2>' . esc_html__( 'Featured stores', 'igbz-suite' ) . '</h2>';

		$rows = [];
		foreach ( $this->directory()->featured() as $store ) {
			$rows[] = [
				'name'     => sprintf(
					'<strong>%1$s</strong><br /><span class="description">%2$s</span>',
					esc_html( (string) $store['name'] ),
					esc_html( (string) $store['slug'] )
				),
				'url'      => sprintf( '<a href="%1$s" target="_blank" rel="noopener">%1$s</a>', esc_url( (string) $store['url'] ) ),
				'category' => esc_html( (string) ( $store['category'] ?: '—' ) ),
				'products' => esc_html( (string) $store['product_count'] ),
				'status'   => View::status_pill( 'active' === $store['status'] ? 'ok' : 'warn' ),
			];
		}

		View::table(
			[
				'name'     => __( 'Store', 'igbz-suite' ),
				'url'      => __( 'Address', 'igbz-suite' ),
				'category' => __( 'Category', 'igbz-suite' ),
				'products' => __( 'Products', 'igbz-suite' ),
				'status'   => __( 'Status', 'igbz-suite' ),
			],
			$rows,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'No active stores yet.', 'igbz-suite' )
		);
	}

	// ------------------------------------------------------------- domains

	private function render_domains(): void {
		$db   = igbz()->db();
		$rows = $db->results(
			'SELECT d.*, t.name AS tenant_name FROM ' . $db->table( 'tenant_domains' ) . ' d
			 LEFT JOIN ' . $db->table( 'tenants' ) . ' t ON t.id = d.tenant_id
			 ORDER BY d.verified_at IS NOT NULL, d.id DESC'
		);

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: hostname */
					__( 'Customers point their domain at %s. Verification is a real DNS lookup — nothing is marked verified on request alone.', 'igbz-suite' ),
					$this->domains()->expected_cname()
				)
			)
		);

		$display = [];
		foreach ( $rows as $row ) {
			$id       = (int) $row['id'];
			$verified = null !== $row['verified_at'];

			$display[] = [
				'domain'  => sprintf(
					'<strong>%1$s</strong>%2$s<br /><span class="description">%3$s</span>',
					esc_html( (string) $row['domain'] ),
					$row['is_primary'] ? ' <em>' . esc_html__( '(primary)', 'igbz-suite' ) . '</em>' : '',
					esc_html( $this->domains()->instructions( (string) $row['domain'], (string) $row['verification_token'] ) )
				),
				'tenant'  => esc_html( (string) ( $row['tenant_name'] ?? '#' . (int) $row['tenant_id'] ) ),
				'status'  => View::status_pill( $verified ? 'ok' : 'warn' ),
				'actions' => sprintf(
					'<a class="button button-small" href="%1$s">%2$s</a> <a class="button button-small" href="%3$s" onclick="return confirm(\'%4$s\')">%5$s</a>',
					esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'tab' => 'domains', 'verify' => $id ] ), self::NONCE ) ),
					esc_html__( 'Check DNS', 'igbz-suite' ),
					esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'tab' => 'domains', 'delete_domain' => $id ] ), self::NONCE ) ),
					esc_js( __( 'Remove this domain mapping?', 'igbz-suite' ) ),
					esc_html__( 'Remove', 'igbz-suite' )
				),
			];
		}

		View::table(
			[
				'domain'  => __( 'Domain', 'igbz-suite' ),
				'tenant'  => __( 'Store', 'igbz-suite' ),
				'status'  => __( 'Verified', 'igbz-suite' ),
				'actions' => __( 'Actions', 'igbz-suite' ),
			],
			$display,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'No custom domains registered yet.', 'igbz-suite' )
		);

		echo '<h2>' . esc_html__( 'Add a domain', 'igbz-suite' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="igbz_action" value="add_domain" />';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="igbz_hub_tenant">' . esc_html__( 'Store', 'igbz-suite' ) . '</label></th><td><select id="igbz_hub_tenant" name="tenant_id">';
		foreach ( $this->tenants()->all( [ 'limit' => 500 ] ) as $tenant ) {
			printf( '<option value="%1$d">%2$s</option>', (int) $tenant->id, esc_html( $tenant->name ) );
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="igbz_hub_domain">' . esc_html__( 'Domain', 'igbz-suite' ) . '</label></th><td>';
		echo '<input type="text" id="igbz_hub_domain" name="domain" class="regular-text" placeholder="shop.example.com" required />';
		echo '<p><label><input type="checkbox" name="is_primary" value="1" /> ' . esc_html__( 'Make this the primary domain', 'igbz-suite' ) . '</label></p>';
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Add domain', 'igbz-suite' ) );
		echo '</form>';
	}

	// -------------------------------------------------------------- blocks

	private function render_blocks(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$editing = isset( $_GET['block'] ) ? sanitize_key( (string) $_GET['block'] ) : '';

		if ( '' !== $editing ) {
			$this->render_block_editor( $editing );
			return;
		}

		printf(
			'<p><a class="button button-primary" href="%1$s">%2$s</a></p>',
			esc_url( Menu::url( self::SLUG, [ 'tab' => 'blocks', 'block' => 'new' ] ) ),
			esc_html__( 'Add block', 'igbz-suite' )
		);

		$rows = [];
		foreach ( $this->blocks()->all() as $block ) {
			$rows[] = [
				'title'   => sprintf(
					'<a href="%1$s"><strong>%2$s</strong></a><br /><span class="description">%3$s</span>',
					esc_url( Menu::url( self::SLUG, [ 'tab' => 'blocks', 'block' => (string) $block['page_key'] ] ) ),
					esc_html( (string) $block['title'] ),
					esc_html( (string) $block['page_key'] )
				),
				'menu'    => esc_html( (string) $block['menu_title'] ),
				'order'   => esc_html( (string) $block['sort_order'] ),
				'active'  => View::status_pill( $block['is_active'] ? 'ok' : 'warn' ),
				'actions' => sprintf(
					'<a class="button button-small" href="%1$s" onclick="return confirm(\'%2$s\')">%3$s</a>',
					esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'tab' => 'blocks', 'delete_block' => (string) $block['page_key'] ] ), self::NONCE ) ),
					esc_js( __( 'Delete this block?', 'igbz-suite' ) ),
					esc_html__( 'Delete', 'igbz-suite' )
				),
			];
		}

		View::table(
			[
				'title'   => __( 'Block', 'igbz-suite' ),
				'menu'    => __( 'Menu title', 'igbz-suite' ),
				'order'   => __( 'Order', 'igbz-suite' ),
				'active'  => __( 'Active', 'igbz-suite' ),
				'actions' => __( 'Actions', 'igbz-suite' ),
			],
			$rows,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'No landing blocks defined.', 'igbz-suite' )
		);
	}

	private function render_block_editor( string $page_key ): void {
		$block = 'new' === $page_key ? null : $this->blocks()->get( $page_key );
		$block = $block ?? $this->blocks()->normalize( [ 'page_key' => '', 'is_active' => true, 'sort_order' => 100 ] );

		echo '<form method="post">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="igbz_action" value="save_block" />';
		echo '<table class="form-table" role="presentation"><tbody>';

		View::field( [ 'key' => 'page_key', 'label' => __( 'Page key', 'igbz-suite' ), 'help' => __( 'Used in the hub URL: /wp-json/igbz-hub/v1/blocks/{page_key}.', 'igbz-suite' ) ], $block['page_key'] );
		View::field( [ 'key' => 'menu_title', 'label' => __( 'Menu title', 'igbz-suite' ) ], $block['menu_title'] );
		View::field( [ 'key' => 'title', 'label' => __( 'Title', 'igbz-suite' ) ], $block['title'] );
		View::field( [ 'key' => 'summary', 'label' => __( 'Summary', 'igbz-suite' ), 'type' => 'textarea' ], $block['summary'] );
		View::field( [ 'key' => 'bullets', 'label' => __( 'Feature bullets', 'igbz-suite' ), 'type' => 'textarea', 'help' => __( 'One per line.', 'igbz-suite' ) ], implode( "\n", (array) $block['bullets'] ) );
		View::field( [ 'key' => 'image_url', 'label' => __( 'Image URL', 'igbz-suite' ) ], $block['image_url'] );
		View::field( [ 'key' => 'images', 'label' => __( 'Detail images', 'igbz-suite' ), 'type' => 'textarea', 'help' => __( 'One URL per line.', 'igbz-suite' ) ], implode( "\n", (array) $block['images'] ) );
		View::field( [ 'key' => 'cta_text', 'label' => __( 'Call to action', 'igbz-suite' ) ], $block['cta_text'] );
		View::field( [ 'key' => 'cta_url', 'label' => __( 'Call to action URL', 'igbz-suite' ) ], $block['cta_url'] );
		View::field( [ 'key' => 'content', 'label' => __( 'Full content (HTML)', 'igbz-suite' ), 'type' => 'textarea' ], $block['content'] );
		View::field( [ 'key' => 'sort_order', 'label' => __( 'Sort order', 'igbz-suite' ), 'type' => 'number', 'min' => 0, 'max' => 9999 ], $block['sort_order'] );
		View::field( [ 'key' => 'is_active', 'label' => __( 'Active', 'igbz-suite' ), 'type' => 'checkbox' ], $block['is_active'] );

		echo '</tbody></table>';
		submit_button( __( 'Save block', 'igbz-suite' ) );
		printf(
			' <a class="button" href="%1$s">%2$s</a>',
			esc_url( Menu::url( self::SLUG, [ 'tab' => 'blocks' ] ) ),
			esc_html__( 'Back', 'igbz-suite' )
		);
		echo '</form>';
	}

	// ----------------------------------------------------------------- vip

	private function render_vip(): void {
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'A VIP link signs a customer into a tenant store from the mother site. Each ticket is HMAC signed, expires quickly and can only be redeemed once.', 'igbz-suite' )
		);

		echo '<form method="post">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="igbz_action" value="mint_vip" />';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="igbz_vip_tenant">' . esc_html__( 'Store', 'igbz-suite' ) . '</label></th><td><select id="igbz_vip_tenant" name="tenant_id">';
		foreach ( $this->tenants()->all( [ 'limit' => 500 ] ) as $tenant ) {
			printf( '<option value="%1$d">%2$s</option>', (int) $tenant->id, esc_html( $tenant->name ) );
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="igbz_vip_user">' . esc_html__( 'Sign in as user id', 'igbz-suite' ) . '</label></th><td>';
		echo '<input type="number" min="0" id="igbz_vip_user" name="user_id" value="0" class="small-text" />';
		echo '<p class="description">' . esc_html__( 'Zero issues a link that only switches the tenant context, without logging anybody in.', 'igbz-suite' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="igbz_vip_redirect">' . esc_html__( 'Redirect path', 'igbz-suite' ) . '</label></th><td>';
		echo '<input type="text" id="igbz_vip_redirect" name="redirect" value="/my-account/" class="regular-text" /></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Generate link', 'igbz-suite' ) );
		echo '</form>';

		$link = get_transient( TenantScope::cache_key( 'igbz_hub_vip_' . get_current_user_id() ) );
		if ( is_string( $link ) && '' !== $link ) {
			delete_transient( TenantScope::cache_key( 'igbz_hub_vip_' . get_current_user_id() ) );
			echo '<h2>' . esc_html__( 'Your link', 'igbz-suite' ) . '</h2>';
			printf(
				'<input type="text" readonly onfocus="this.select()" class="large-text code" value="%s" />',
				esc_attr( $link )
			);
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %d: seconds */
						__( 'Valid for %d seconds and single use.', 'igbz-suite' ),
						$this->vip()->ttl()
					)
				)
			);
		}
	}

	// ----------------------------------------------------------------- api

	private function render_api(): void {
		$base    = rest_url( HubController::NAMESPACE );
		$origins = Cors::allowed_origins();

		echo '<table class="form-table" role="presentation"><tbody>';
		View::field( [ 'key' => 'hub_base', 'label' => __( 'Hub API base', 'igbz-suite' ), 'type' => 'readonly' ], $base );
		View::field(
			[
				'key'   => 'hub_origins',
				'label' => __( 'Allowed browser origins', 'igbz-suite' ),
				'type'  => 'readonly',
				'help'  => __( 'Set on the Hub settings tab. Requests from any other origin get no CORS header at all — there is no wildcard.', 'igbz-suite' ),
			],
			$origins ? implode( ', ', $origins ) : __( 'same origin only', 'igbz-suite' )
		);
		echo '</tbody></table>';

		$routes = [
			'GET  /landing'                        => __( 'Hero copy, featured stores, plans, blocks and real statistics.', 'igbz-suite' ),
			'GET  /stores?limit='                  => __( 'Store directory.', 'igbz-suite' ),
			'GET  /stores/{slug}'                  => __( 'One store plus its product grid.', 'igbz-suite' ),
			'GET  /plans'                          => __( 'Active subscription plans.', 'igbz-suite' ),
			'GET  /blocks, /blocks/{page_key}'     => __( 'Editable marketing blocks.', 'igbz-suite' ),
			'GET  /check-slug?slug='               => __( 'Store address availability.', 'igbz-suite' ),
			'POST /signup'                         => __( 'Create user + store + subscription and return the payment redirect.', 'igbz-suite' ),
			'POST /signup/verify-payment'          => __( 'Verify the PSP return and activate the store.', 'igbz-suite' ),
			'GET  /admin/summary'                  => __( 'Platform aggregates (super admin).', 'igbz-suite' ),
			'GET  /admin/domains'                  => __( 'Custom domain mappings (super admin).', 'igbz-suite' ),
			'POST /admin/domains/{id}/verify'      => __( 'Run a real DNS check (super admin).', 'igbz-suite' ),
			'POST /admin/tenants/{id}/status'      => __( 'Suspend or reactivate a store (super admin).', 'igbz-suite' ),
			'POST /admin/vip-link'                 => __( 'Mint a signed hand-off link (super admin).', 'igbz-suite' ),
		];

		$rows = [];
		foreach ( $routes as $route => $description ) {
			$rows[] = [
				'route' => '<code>' . esc_html( $route ) . '</code>',
				'what'  => esc_html( $description ),
			];
		}

		View::table(
			[ 'route' => __( 'Route', 'igbz-suite' ), 'what' => __( 'Purpose', 'igbz-suite' ) ],
			$rows,
			static fn ( array $row, string $key ): string => (string) $row[ $key ]
		);
	}

	// -------------------------------------------------------------- actions

	private function handle_post(): void {
		$action = isset( $_POST['igbz_action'] ) ? sanitize_key( wp_unslash( $_POST['igbz_action'] ) ) : '';
		if ( '' === $action ) {
			return;
		}
		View::check_nonce( self::NONCE );
		Capabilities::require( Capabilities::MANAGE_TENANTS );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		match ( $action ) {
			'add_domain' => $this->do_add_domain(),
			'save_block' => $this->do_save_block(),
			'mint_vip'   => $this->do_mint_vip(),
			default      => null,
		};
		// phpcs:enable
	}

	private function do_add_domain(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$tenant_id = isset( $_POST['tenant_id'] ) ? (int) $_POST['tenant_id'] : 0;
		$domain    = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
		$primary   = ! empty( $_POST['is_primary'] );
		// phpcs:enable

		if ( $tenant_id <= 0 || '' === $domain ) {
			View::notice( __( 'A store and a domain are required.', 'igbz-suite' ), 'error' );
			return;
		}

		$id = $this->tenants()->add_domain( $tenant_id, $domain, $primary );
		if ( $id > 0 ) {
			$this->stats()->flush();
			View::notice( __( 'Domain added. Ask the customer to create the DNS record, then run the check.', 'igbz-suite' ) );
			return;
		}

		View::notice( __( 'The domain could not be added — it may already be mapped.', 'igbz-suite' ), 'error' );
	}

	private function do_save_block(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$raw = isset( $_POST['igbz'] ) && is_array( $_POST['igbz'] ) ? wp_unslash( $_POST['igbz'] ) : [];
		// phpcs:enable

		if ( '' === sanitize_key( (string) ( $raw['page_key'] ?? '' ) ) ) {
			View::notice( __( 'A page key is required.', 'igbz-suite' ), 'error' );
			return;
		}

		$this->blocks()->save( (array) $raw );
		View::notice( __( 'Block saved.', 'igbz-suite' ) );
	}

	private function do_mint_vip(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$tenant_id = isset( $_POST['tenant_id'] ) ? (int) $_POST['tenant_id'] : 0;
		$user_id   = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		$redirect  = isset( $_POST['redirect'] ) ? sanitize_text_field( wp_unslash( $_POST['redirect'] ) ) : '/';
		// phpcs:enable

		if ( $tenant_id <= 0 ) {
			View::notice( __( 'Choose a store first.', 'igbz-suite' ), 'error' );
			return;
		}

		set_transient( TenantScope::cache_key( 'igbz_hub_vip_' . get_current_user_id() ), $this->vip()->issue_url( $tenant_id, $user_id, $redirect ), 300 );
		View::notice( __( 'Link generated.', 'igbz-suite' ) );
	}

	private function handle_get_actions(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$verify  = isset( $_GET['verify'] ) ? (int) $_GET['verify'] : 0;
		$remove  = isset( $_GET['delete_domain'] ) ? (int) $_GET['delete_domain'] : 0;
		$dblock  = isset( $_GET['delete_block'] ) ? sanitize_key( (string) $_GET['delete_block'] ) : '';
		$refresh = isset( $_GET['run'] ) ? sanitize_key( (string) $_GET['run'] ) : '';
		// phpcs:enable

		if ( ! $verify && ! $remove && '' === $dblock && '' === $refresh ) {
			return;
		}

		View::check_nonce( self::NONCE );
		Capabilities::require( Capabilities::MANAGE_TENANTS );

		if ( $verify > 0 ) {
			$result = $this->domains()->check( $verify );
			View::notice( $result['message'], $result['ok'] ? 'success' : 'error' );
		}

		if ( $remove > 0 ) {
			$this->tenants()->delete_domain( $remove );
			$this->stats()->flush();
			View::notice( __( 'Domain mapping removed.', 'igbz-suite' ) );
		}

		if ( '' !== $dblock ) {
			$this->blocks()->delete( $dblock );
			View::notice( __( 'Block deleted.', 'igbz-suite' ) );
		}

		if ( 'refresh' === $refresh ) {
			$this->stats()->summary( true );
			$this->directory()->flush();
			View::notice( __( 'Aggregates recomputed.', 'igbz-suite' ) );
		}
	}
}
