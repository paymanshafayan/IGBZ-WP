<?php
/**
 * Harness only. Turn on every registered module once, so every admin screen
 * and REST route exists (including the new Pado module added in v19).
 * Uses Modules::all() so future modules are auto-enabled without touching
 * this file — but we bump the guard option to force a re-sync when the list
 * grows.
 */
add_action( 'igbz_booted', function () {
	$guard = 'igbz_devenv_modules_on_v3';
	if ( get_option( $guard ) ) { return; }
	if ( ! class_exists( '\\IGBZ\\Suite\\Support\\Modules' ) ) { return; }
	update_option( \IGBZ\Suite\Support\Modules::OPTION, \IGBZ\Suite\Support\Modules::all() );
	update_option( $guard, 1 );
} );
