<?php
/**
 * Harness only. GET /?igbz_health=1 -> JSON summary of the environment.
 */
add_action( 'init', function () {
	if ( ! isset( $_GET['igbz_health'] ) ) { return; }
	global $wp_version, $wpdb;

	$out = [
		'wp'          => $wp_version,
		'php'         => PHP_VERSION,
		'wc_active'   => class_exists( 'WooCommerce' ),
		'wc_version'  => defined( 'WC_VERSION' ) ? WC_VERSION : null,
		'igbz_loaded' => function_exists( 'igbz' ),
		'active'      => (array) get_option( 'active_plugins', [] ),
	];

	if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
		$out['hpos'] = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	if ( function_exists( 'igbz' ) ) {
		$tables = 0;
		foreach ( \IGBZ\Suite\Support\Schema::tables() as $t ) {
			$full = $wpdb->prefix . 'igbz_' . $t;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) ) { $tables++; }
		}
		$out['igbz_tables'] = $tables . '/' . count( \IGBZ\Suite\Support\Schema::tables() );
		$out['modules']     = get_option( 'igbz_enabled_modules' );
	}

	wp_send_json( $out );
}, 99 );
