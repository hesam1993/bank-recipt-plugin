<?php
/** Verify the measured runtime matches the requested CI matrix entry. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

$expected_php = getenv( 'C2C_TEST_PHP' );
$expected_hpos = getenv( 'C2C_TEST_HPOS' );
$actual_php = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
$actual_hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'yes' : 'no';
$expected_store = 'yes' === $expected_hpos
	? \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::class
	: WC_Order_Data_Store_CPT::class;
$actual_store = WC_Data_Store::load( 'order' )->get_current_class_name();

if ( $actual_php !== $expected_php || ! in_array( $expected_hpos, array( 'yes', 'no' ), true ) || $actual_hpos !== $expected_hpos || $actual_store !== $expected_store ) {
	WP_CLI::error( sprintf(
		'CI environment mismatch: requested PHP %s / HPOS %s; measured PHP %s / HPOS %s / datastore %s.',
		$expected_php, $expected_hpos, $actual_php, $actual_hpos, $actual_store
	) );
}

WP_CLI::success( sprintf( 'Verified PHP %s / HPOS %s / datastore %s.', $actual_php, $actual_hpos, $actual_store ) );
