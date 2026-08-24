<?php
namespace IGBZ\Suite\Support;

defined( 'ABSPATH' ) || exit;

final class Modules {

	public const MULTITENANT = 'multitenant';
	public const INSTAGRAM   = 'instagram';
	public const HUB         = 'hub';
	public const REST_API    = 'rest_api';
	public const FX          = 'fx';
	public const PADO    = 'pado';

	public const OPTION = 'igbz_enabled_modules';

	/** @return string[] */
	public static function all(): array {
		return [ self::MULTITENANT, self::INSTAGRAM, self::HUB, self::FX, self::REST_API, self::PADO ];
	}

	/** @return string[] */
	public static function defaults(): array {
		return [ self::MULTITENANT, self::PADO ];
	}

	/** @return string[] */
	public static function enabled_list(): array {
		$stored = get_option( self::OPTION, null );
		if ( ! is_array( $stored ) ) {
			return self::defaults();
		}
		return array_values( array_intersect( self::all(), $stored ) );
	}

	public static function enabled( string $id ): bool {
		return in_array( $id, self::enabled_list(), true );
	}

	/** @param string[] $ids */
	public static function save( array $ids ): void {
		update_option( self::OPTION, array_values( array_intersect( self::all(), $ids ) ), true );
	}
}
