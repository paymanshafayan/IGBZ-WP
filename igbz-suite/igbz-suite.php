<?php
/**
 * Plugin Name:       IGBZ Suite
 * Plugin URI:        https://github.com/paymanshafayan/IGBZ-WP
 * Description:       IGBZ multi-tenant commerce suite for WordPress + WooCommerce. Four toggleable modules: Multi-Tenant Stores (wallet, plans, BNPL, LMS, affiliate, Iranian gateways), Instagram Automation (Manus + ManyChat), Master Site Hub and Mobile REST API.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Tested up to:      7.0
 * Requires PHP:      8.1
 * Author:            IGBZ
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       igbz-suite
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   11.0
 */

defined( 'ABSPATH' ) || exit;

define( 'IGBZ_VERSION', '1.0.0' );
define( 'IGBZ_DB_VERSION', 34 );
define( 'IGBZ_FILE', __FILE__ );
define( 'IGBZ_DIR', plugin_dir_path( __FILE__ ) );
define( 'IGBZ_URL', plugin_dir_url( __FILE__ ) );
define( 'IGBZ_BASENAME', plugin_basename( __FILE__ ) );

require_once IGBZ_DIR . 'src/Support/Autoloader.php';
\IGBZ\Suite\Support\Autoloader::register( 'IGBZ\\Suite\\', IGBZ_DIR . 'src/' );

// Custom cron recurrences must exist before Activator::schedule_events() runs. Activation
// fires on a request where this file is loaded after `plugins_loaded`, so registering them
// from inside a `plugins_loaded` callback would be too late and wp_schedule_event() would
// silently refuse the unknown `igbz_five_minutes` recurrence.
\IGBZ\Suite\Support\Cron::register_schedules();

register_activation_hook( __FILE__, [ \IGBZ\Suite\Support\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \IGBZ\Suite\Support\Activator::class, 'deactivate' ] );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

/**
 * Main container accessor.
 */
function igbz(): \IGBZ\Suite\Support\Plugin {
	return \IGBZ\Suite\Support\Plugin::instance();
}

igbz()->boot();
