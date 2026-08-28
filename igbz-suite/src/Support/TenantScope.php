<?php
namespace IGBZ\Suite\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 14: the single path that decides which tenant an admin screen acts on.
 *
 * A client-supplied tenant id is a request, not an identity. Store owners and staff are pinned
 * to the tenant resolved from where the request arrived; only a platform admin
 * (`MANAGE_TENANTS`) may point a screen at an explicit tenant. Every admin page that used to
 * read `$_GET['tenant_id']` straight from the URL goes through here instead.
 */
final class TenantScope {

	public static function page_tenant_id( ?int $requested = null ): int {
		if ( null !== $requested && $requested > 0 && Capabilities::current_user_can( Capabilities::MANAGE_TENANTS ) ) {
			return $requested;
		}

		return (int) igbz()->tenancy()->id();
	}

	/**
	 * Phase 15: the single path for shared-memory keys. A cache or transient that holds
	 * tenant data is namespaced with the resolved tenant so two stores can never collide on
	 * the same logical key; control-plane code without a tenant lands in the explicit `t0`
	 * namespace instead of colliding with tenant one.
	 */
	public static function cache_key( string $logical_key ): string {
		return 't' . (int) igbz()->tenancy()->id() . ':' . $logical_key;
	}
}
