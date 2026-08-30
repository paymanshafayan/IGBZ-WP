<?php
/**
 * Health probe observer (phase 70). The product endpoint
 * (IGBZ\Suite\Support\HealthEndpoint) answers /?igbz_health=1 with 200/503
 * semantics; this mu-plugin only matters when the SUITE ITSELF failed to boot —
 * then it still answers, honestly red (503), because a probe that goes silent
 * exactly when the plugin is broken tells the orchestrator nothing.
 */
add_action( 'init', function () {
	if ( ! isset( $_GET['igbz_health'] ) ) { return; }

	if ( class_exists( 'IGBZ\\Suite\\Support\\HealthEndpoint' ) ) {
		// The suite is up — its own endpoint owns the document and the status code.
		return;
	}

	global $wpdb, $wp_version;
	$db_ok = false;
	if ( isset( $wpdb ) ) {
		$checked = $wpdb->get_var( 'SELECT 1' );
		$db_ok   = ( '1' === (string) $checked );
	}

	status_header( $db_ok ? 200 : 503 );
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Cache-Control: no-cache, no-store, must-revalidate' );
	echo wp_json_encode(
		[
			'ok'          => false, // igbz itself did not boot — never green
			'degraded'    => true,
			'db'          => $db_ok,
			'wp'          => $wp_version,
			'php'         => PHP_VERSION,
			'igbz_loaded' => false,
		],
		JSON_UNESCAPED_UNICODE
	);
	exit;
}, 1 );
