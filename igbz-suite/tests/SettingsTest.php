<?php
declare( strict_types=1 );

use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Settings;

final class SettingsTest extends TestCase {

	public function run(): void {
		$settings = igbz_test_reset_settings();

		$settings->set( 'wallet.enabled', true );
		$settings->set( 'wallet.min_topup', '10000' );
		$settings->set( 'affiliate.tier1_rate', '5.5' );
		$settings->set( 'general.default_currency', 'IRT' );

		$this->assert_true( $settings->bool( 'wallet.enabled' ), 'bool() reads a stored true' );
		$this->assert_same( 10000, $settings->int( 'wallet.min_topup' ), 'int() casts a numeric string' );
		$this->assert_same( 5.5, $settings->float( 'affiliate.tier1_rate' ), 'float() casts a decimal string' );
		$this->assert_same( 'IRT', $settings->string( 'general.default_currency' ), 'string() reads a plain value' );

		$this->assert_same( 4, $settings->int( 'bnpl.default_installments', 4 ), 'int() falls back to the default' );
		$this->assert_true( $settings->bool( 'missing.key', true ), 'bool() falls back to the default' );
		$this->assert_same( 'x', $settings->string( 'missing.key', 'x' ), 'string() falls back to the default' );

		$settings->set( 'wallet.enabled', '0' );
		$this->assert_false( $settings->bool( 'wallet.enabled', true ), 'the string "0" is falsey' );
		$settings->set( 'wallet.enabled', 'yes' );
		$this->assert_true( $settings->bool( 'wallet.enabled' ), 'the string "yes" is truthy' );

		// Secrets: encrypted at rest, transparent through the accessor, masked for the browser.
		$this->assert_true( $settings->is_secret( 'zernio.central_api_key' ), 'the central Zernio key is registered as a secret (phase 50: the legacy provider secrets are gone)' );
		$settings->set( 'zernio.central_api_key', '123456:abcdef' );

		$stored = $GLOBALS['igbz_test_options'][ Settings::OPTION ]['zernio.central_api_key'];
		$this->assert_contains( 'igbz1:', (string) $stored, 'the secret is stored encrypted' );
		$this->assert_same( '123456:abcdef', $settings->string( 'zernio.central_api_key' ), 'the secret decrypts on read' );
		$this->assert_same( Crypto::MASK, $settings->masked( 'zernio.central_api_key' ), 'masked() hides a configured secret' );
		$this->assert_same( '', $settings->masked( 'pado.api_key' ), 'masked() is empty when nothing is stored' );

		// Submitting the form back unchanged must not overwrite the secret with the mask.
		$settings->set_many( [ 'zernio.central_api_key' => Crypto::MASK, 'wallet.min_topup' => 20000 ] );
		$this->assert_same( '123456:abcdef', $settings->string( 'zernio.central_api_key' ), 'the masked placeholder keeps the stored secret' );
		$this->assert_same( 20000, $settings->int( 'wallet.min_topup' ), 'set_many still writes normal values' );

		$settings->set_many( [ 'zernio.central_api_key' => '' ] );
		$this->assert_same( '123456:abcdef', $settings->string( 'zernio.central_api_key' ), 'an empty submission keeps the stored secret' );

		$this->assert_true( $settings->has( 'zernio.central_api_key' ), 'has() is true for a configured key' );
		$this->assert_false( $settings->has( 'pado.api_key' ), 'has() is false for an unset key' );

		$threw = false;
		try {
			$settings->required( 'pado.api_key' );
		} catch ( \RuntimeException ) {
			$threw = true;
		}
		$this->assert_true( $threw, 'required() throws instead of silently using an empty secret' );
	}
}
