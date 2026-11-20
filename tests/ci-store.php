<?php
/** CI-only must-use plugin for the disposable WooCommerce test store. */
defined( 'ABSPATH' ) || exit;

if ( ! defined( 'C2C_TEST_HPOS' ) || ! in_array( C2C_TEST_HPOS, array( 'yes', 'no' ), true ) ) {
	throw new RuntimeException( 'The CI store requires C2C_TEST_HPOS to be yes or no.' );
}

// Keep every CLI and HTTP request on the matrix job's selected order datastore.
add_filter( 'pre_option_woocommerce_custom_orders_table_enabled', static fn() => C2C_TEST_HPOS, PHP_INT_MAX );
add_filter( 'pre_option_woocommerce_custom_orders_table_data_sync_enabled', static fn() => 'no', PHP_INT_MAX );
