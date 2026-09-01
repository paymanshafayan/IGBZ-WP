<?php
namespace IGBZ\Suite\Modules\Pado\Ai;

use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The panel key vault (مخزن کلید پنل, ADR-0005 §key-storage).
 *
 * One registered secret option (`pado.ai.key_vault`, encrypted at rest by the Crypto
 * module through Settings::SECRETS) holds every provider api key, keyed by provider id
 * — the `keyRef` the provider layer hands around. The real value is resolved exactly
 * once, at the moment of a call, by the connector edge (the adapter); it is never
 * re-rendered into the DOM, never logged, never written to a plain option.
 */
final class KeyVault {

	public const OPTION = 'pado.ai.key_vault';

	public function __construct( private Settings $settings ) {}

	public function has( string $id ): bool {
		return array_key_exists( $id, $this->all() );
	}

	public function get( string $id ): string {
		return (string) ( $this->all()[ $id ] ?? '' );
	}

	public function set( string $id, string $key ): void {
		$keys      = $this->all();
		$keys[ $id ] = $key;
		$this->save( $keys );
	}

	public function remove( string $id ): void {
		$keys = $this->all();
		unset( $keys[ $id ] );
		$this->save( $keys );
	}

	/** @return array<string,string> provider id => api key (decrypted) */
	private function all(): array {
		$raw     = $this->settings->string( self::OPTION, '' );
		if ( '' === $raw ) {
			return [];
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? array_map( 'strval', $decoded ) : [];
	}

	/** @param array<string,string> $keys */
	private function save( array $keys ): void {
		$this->settings->set( self::OPTION, wp_json_encode( $keys ) );
	}
}
