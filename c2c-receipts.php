<?php
/**
 * Plugin Name: Card to Card Receipts for WooCommerce
 * Description: Private bank-transfer receipt uploads and manual payment review, including Checkout Blocks.
 * Version: 0.1.4
 * Author: Hesam MM
 * Author URI: https://profiles.wordpress.org/hesamst/
 * Requires at least: 7.0
 * Requires PHP: 8.3
 * Requires Plugins: woocommerce
 * WC requires at least: 11.0
 * Text Domain: c2c-receipts
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'C2C_RECEIPTS_VERSION', '0.1.4' );
define( 'C2C_RECEIPTS_FILE', __FILE__ );
define( 'C2C_RECEIPTS_DIR', __DIR__ );

require_once __DIR__ . '/includes/class-support.php';
require_once __DIR__ . '/includes/class-crypto.php';
require_once __DIR__ . '/includes/class-storage.php';
require_once __DIR__ . '/includes/class-receipts.php';
require_once __DIR__ . '/includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'C2CReceipts\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'C2CReceipts\\Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'C2CReceipts\\Plugin', 'boot' ), 20 );

add_action( 'before_woocommerce_init', static function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );

// Register before WooCommerce initializes Blocks on plugins_loaded.
add_action( 'woocommerce_blocks_loaded', static function () {
	if ( class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		require_once __DIR__ . '/includes/class-blocks.php';
		add_action( 'woocommerce_blocks_payment_method_type_registration', static function ( $registry ) {
			$registry->register( new \C2CReceipts\Blocks() );
		} );
	}
} );
