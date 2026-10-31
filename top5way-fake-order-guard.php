<?php
/**
 * Plugin Name: Top5Way Fake Order Guard
 * Plugin URI: https://top5way.com
 * Description: Stops orders from blocked phones, devices and IPs. A blocked customer is sent to your WhatsApp with the order already typed, so you see their real number.
 * Version: 1.0.0
 * Author: Top5Way Team
 * Author URI: https://top5way.com
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Text Domain: t5fob
 */

defined( 'ABSPATH' ) || exit;

// The older T5 Fake Order Blocker uses the same code and tables: only one of them can run.
if ( defined( 'T5FOB_VERSION' ) ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p><strong>Top5Way Fake Order Guard</strong> is not running. Deactivate T5 Fake Order Blocker first: the block list and history carry over.</p></div>';
	} );
	return;
}

define( 'T5FOB_VERSION', '1.0.0' );
define( 'T5FOB_DB_VERSION', '2' );
define( 'T5FOB_FILE', __FILE__ );
define( 'T5FOB_DIR', plugin_dir_path( __FILE__ ) );
define( 'T5FOB_URL', plugin_dir_url( __FILE__ ) );

require_once T5FOB_DIR . 'includes/store.php';
require_once T5FOB_DIR . 'includes/checkout.php';
require_once T5FOB_DIR . 'includes/admin.php';
require_once T5FOB_DIR . 'includes/order-box.php';
require_once T5FOB_DIR . 'includes/verify.php';

register_activation_hook( __FILE__, 't5fob_install' );
add_action( 'plugins_loaded', function () {
	if ( get_option( 't5fob_db_version' ) !== T5FOB_DB_VERSION ) {
		t5fob_install();
	}
	if ( ! wp_next_scheduled( 't5fob_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 't5fob_cleanup' );
	}
} );
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 't5fob_cleanup' );
} );

// HPOS compatible.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );
