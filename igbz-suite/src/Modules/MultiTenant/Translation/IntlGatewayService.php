<?php
namespace IGBZ\Suite\Modules\MultiTenant\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * International payment-gateway core (phase 48).
 *
 * Deliberately a capability registry, not a provider: gateways are listed
 * and validated from settings the same way shipping adapters are, and each
 * real provider is verified separately in its own `PV-INTL-*` phase before
 * any money moves. Nothing here invents an API.
 */
final class IntlGatewayService {

	/** @return array<string,array{id:string,title:string,enabled:bool,configured:bool,currencies:array<int,string>}> */
	public function gateways(): array {
		$settings = igbz()->settings();
		$ids      = array_values( array_filter( array_map( 'trim', explode( ',', $settings->string( 'intl.psp_ids', '' ) ) ) ) );

		$out = [];
		foreach ( $ids as $id ) {
			$id = sanitize_key( $id );
			if ( '' === $id ) {
				continue;
			}
			$currencies = array_values( array_filter( array_map( 'trim', explode( ',', $settings->string( 'intl.psp_' . $id . '_currencies', 'USD,EUR' ) ) ) ) );

			$out[ $id ] = [
				'id'         => $id,
				'title'      => $settings->string( 'intl.psp_' . $id . '_title', $id ),
				'enabled'    => $settings->bool( 'intl.psp_' . $id . '_enabled', false ),
				'configured' => '' !== $settings->string( 'intl.psp_' . $id . '_base_url' )
					&& '' !== $settings->string( 'intl.psp_' . $id . '_api_key' ),
				'currencies' => array_values( array_intersect( $currencies, IntlCommerceService::ALLOWED_CURRENCIES ) ),
			];
		}

		return $out;
	}

	/** Gateways that are both switched on and hold real credentials. */
	public function available( string $currency = '' ): array {
		$ready = [];
		foreach ( $this->gateways() as $id => $gateway ) {
			if ( ! $gateway['enabled'] || ! $gateway['configured'] ) {
				continue;
			}
			if ( '' !== $currency && ! in_array( $currency, $gateway['currencies'], true ) ) {
				continue;
			}
			$ready[ $id ] = $gateway;
		}

		return $ready;
	}
}
