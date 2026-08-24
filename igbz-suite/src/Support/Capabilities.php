<?php
namespace IGBZ\Suite\Support;

defined( 'ABSPATH' ) || exit;

final class Capabilities {

	public const ROLE_TENANT_OWNER = 'igbz_tenant_owner';
	public const ROLE_TENANT_STAFF = 'igbz_tenant_staff';
	public const ROLE_INSTRUCTOR   = 'igbz_instructor';

	public const MANAGE_SUITE      = 'igbz_manage_suite';
	public const MANAGE_TENANTS    = 'igbz_manage_tenants';
	public const MANAGE_OWN_TENANT = 'igbz_manage_own_tenant';
	public const MANAGE_WALLET     = 'igbz_manage_wallet';
	public const MANAGE_PLANS      = 'igbz_manage_plans';
	public const MANAGE_BNPL       = 'igbz_manage_bnpl';
	public const MANAGE_LMS        = 'igbz_manage_lms';
	public const MANAGE_AFFILIATE  = 'igbz_manage_affiliate';
	public const MANAGE_INSTAGRAM  = 'igbz_manage_instagram';
	public const MANAGE_API        = 'igbz_manage_api';
	public const MANAGE_PADO   = 'igbz_manage_pado';

	/** @return string[] */
	public static function all(): array {
		return [
			self::MANAGE_SUITE,
			self::MANAGE_TENANTS,
			self::MANAGE_OWN_TENANT,
			self::MANAGE_WALLET,
			self::MANAGE_PLANS,
			self::MANAGE_BNPL,
			self::MANAGE_LMS,
			self::MANAGE_AFFILIATE,
			self::MANAGE_INSTAGRAM,
			self::MANAGE_API,
			self::MANAGE_PADO,
		];
	}

	public static function current_user_can( string $cap ): bool {
		return current_user_can( $cap ) || current_user_can( 'manage_options' );
	}

	public static function require( string $cap ): void {
		if ( ! self::current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'igbz-suite' ), 403 );
		}
	}
}
