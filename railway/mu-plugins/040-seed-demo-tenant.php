<?php
/**
 * Railway demo only: provision one real tenant around the sample WooCommerce store.
 * This is deliberately isolated from the production signup flow and is idempotent.
 */
add_action( 'wp_loaded', function () {
	if ( ! function_exists( 'igbz' ) || ! igbz()->has( 'tenants' ) ) {
		return;
	}

	$tenants = igbz()->get( 'tenants' );
	$tenant  = $tenants->find_by_slug( 'demo-shop' );
	if ( ! $tenant ) {
		$tenant_id = $tenants->create(
			[
				'slug'          => 'demo-shop',
				'name'          => 'فروشگاه نمونه آرایشی',
				'owner_user_id' => (int) ( get_current_user_id() ?: ( get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] )[0] ?? 0 ) ),
				'status'        => \IGBZ\Suite\Modules\MultiTenant\Repository\Tenant::STATUS_ACTIVE,
				'currency'      => 'IRT',
				'locale'        => 'fa_IR',
			]
		);
		$tenant = $tenant_id > 0 ? $tenants->find( $tenant_id ) : null;
		if ( $tenant ) {
			$tenants->add_domain( $tenant->id, 'demo-shop.igbz.ir', true );
		}
	}
	if ( ! $tenant ) {
		return;
	}

	// The Railway hostname is not the tenant's public domain, so use the demo tenant as the
	// explicit fallback for this single-site harness. Real requests use domain/path resolution.
	igbz()->settings()->set( 'general.tenant_resolution', 'hybrid' );
	igbz()->settings()->set( 'general.default_tenant_id', $tenant->id );

	if ( function_exists( 'wc_get_products' ) ) {
		foreach ( wc_get_products( [ 'limit' => 100, 'status' => [ 'publish', 'draft', 'pending' ] ] ) as $product ) {
			if ( str_starts_with( (string) $product->get_sku(), 'SAMPLE-' ) && (int) $product->get_meta( '_igbz_tenant_id' ) !== $tenant->id ) {
				$product->update_meta_data( '_igbz_tenant_id', $tenant->id );
				$product->save();
			}
		}
	}
}, 70 );
