<?php
namespace IGBZ\Suite\Modules\MultiTenant\Frontend;

use IGBZ\Suite\Support\Capabilities;
use IGBZ\Suite\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 18: per-tenant theme application at request time.
 *
 * WordPress keeps one active theme per install, so activating a store's theme globally would
 * repaint every other store and the mother site. Instead, activation writes the slug to the
 * tenant row, and this router applies it only while that tenant's storefront is being served:
 * the `template` and `stylesheet` filters answer the tenant's own installed theme, and never
 * leak one tenant's theme into another tenant's request. The preview parameter is honoured
 * only for members of the tenant the preview theme belongs to — a slug alone never crosses a
 * tenant boundary.
 */
final class TenantThemeRouter {

	public function __construct( private Db $db ) {}

	public function register(): void {
		add_filter( 'template', [ $this, 'route' ] );
		add_filter( 'stylesheet', [ $this, 'route' ] );
	}

	public function route( string $current ): string {
		if ( is_admin() ) {
			return $current;
		}

		$preview = isset( $_GET['igbz_theme_preview'] ) ? sanitize_title( wp_unslash( (string) $_GET['igbz_theme_preview'] ) ) : '';
		if ( '' !== $preview ) {
			return $this->preview_theme( $preview ) ?: $current;
		}

		$tenant = igbz()->tenancy()->current();
		if ( ! $tenant || '' === $tenant->theme ) {
			return $current;
		}

		$slug = sanitize_title( $tenant->theme );
		return isset( wp_get_themes()[ $slug ] ) ? $slug : $current;
	}

	/**
	 * A preview renders only when the requested theme exists, is in preview/live state,
	 * belongs to the tenant being served, and the visitor is a member of that tenant (or a
	 * platform admin). Anything else falls back to the current theme.
	 */
	private function preview_theme( string $slug ): string {
		$row = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'themes' ) . ' WHERE slug = %s ORDER BY id DESC LIMIT 1',
			$slug
		);
		if ( ! $row ) {
			return '';
		}
		if ( ! in_array( (string) $row['status'], [ 'preview', 'live' ], true ) ) {
			return '';
		}

		$tenant_id = (int) $row['tenant_id'];
		$tenant    = igbz()->tenancy()->current();
		if ( ! $tenant || $tenant->id !== $tenant_id ) {
			return '';
		}

		if ( Capabilities::current_user_can( Capabilities::MANAGE_TENANTS ) ) {
			return $slug;
		}

		$user_id = get_current_user_id();
		if ( $user_id > 0 && igbz()->get( 'tenants' )->user_belongs_to( $user_id, $tenant_id ) ) {
			return $slug;
		}

		return '';
	}
}
