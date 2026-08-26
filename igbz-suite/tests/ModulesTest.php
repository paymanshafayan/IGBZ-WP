<?php
declare( strict_types=1 );

use IGBZ\Suite\Support\Capabilities;
use IGBZ\Suite\Support\Modules;
use IGBZ\Suite\Support\Plugin;

final class ModulesTest extends TestCase {

	public function run(): void {
		igbz_test_reset_settings();

		$this->assert_same( 6, count( Modules::all() ), 'the suite ships six modules' );
		$this->assert_same( [ Modules::MULTITENANT, Modules::PADO ], Modules::defaults(), 'multi-tenant and pado are on by default' );
		$this->assert_same( Modules::defaults(), Modules::enabled_list(), 'a fresh install falls back to the defaults' );
		$this->assert_true( Modules::enabled( Modules::MULTITENANT ), 'multi-tenant is enabled out of the box' );
		$this->assert_false( Modules::enabled( Modules::HUB ), 'the hub is off out of the box' );

		Modules::save( [ Modules::HUB, Modules::REST_API, 'not-a-module' ] );
		$this->assert_same( [ Modules::HUB, Modules::REST_API ], Modules::enabled_list(), 'unknown ids are dropped on save' );
		$this->assert_false( Modules::enabled( Modules::MULTITENANT ), 'saving replaces the enabled set' );

		Modules::save( [] );
		$this->assert_same( [], Modules::enabled_list(), 'every module can be switched off' );

		// The container must know a class for each declared module id.
		$map = Plugin::instance()->module_map();
		$this->assert_same( Modules::all(), array_keys( $map ), 'the module map covers every module id' );
		foreach ( $map as $id => $class ) {
			$this->assert_true( class_exists( $class ), "the {$id} module class autoloads" );
			$this->assert_true(
				in_array( \IGBZ\Suite\Support\ModuleInterface::class, class_implements( $class ) ?: [], true ),
				"the {$id} module implements ModuleInterface"
			);
		}

		// Container wiring.
		$plugin = Plugin::instance();
		$this->assert_true( $plugin->has( 'settings' ), 'settings is bound' );
		$this->assert_true( $plugin->has( 'logger' ), 'logger is bound' );
		$this->assert_true( $plugin->has( 'db' ), 'db is bound' );
		$this->assert_true( $plugin->has( 'http' ), 'http is bound' );
		$this->assert_true( $plugin->has( 'tenancy' ), 'tenancy is bound' );
		$this->assert_true( $plugin->settings() === $plugin->settings(), 'resolved services are singletons' );

		$threw = false;
		try {
			$plugin->get( 'nope' );
		} catch ( \RuntimeException ) {
			$threw = true;
		}
		$this->assert_true( $threw, 'resolving an unknown service throws' );

		// Capabilities: every constant is listed, and the list is unique.
		$caps = Capabilities::all();
		$this->assert_same( count( $caps ), count( array_unique( $caps ) ), 'capability ids are unique' );
		foreach ( $caps as $cap ) {
			$this->assert_contains( 'igbz_', $cap, 'capabilities are namespaced' );
		}
		foreach ( [ 'MANAGE_SUITE', 'MANAGE_TENANTS', 'MANAGE_INSTAGRAM', 'MANAGE_API' ] as $name ) {
			$value = constant( Capabilities::class . '::' . $name );
			$this->assert_true( in_array( $value, $caps, true ), "{$name} is included in all()" );
		}
	}
}
