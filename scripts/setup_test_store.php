<?php
/** Initialize the order schema and smoke-test the disposable CI store. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

$synchronizer = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class );
if ( ! $synchronizer->create_database_tables() ) {
	WP_CLI::error( 'Could not initialize WooCommerce HPOS tables in the disposable test database.' );
}

require __DIR__ . '/assert_test_environment.php';

$smoke_order = wc_create_order();
if ( is_wp_error( $smoke_order ) || ! $smoke_order instanceof WC_Order || ! $smoke_order->get_id() ) {
	WP_CLI::error( 'The test datastore could not create an order.' );
}
try {
	if ( ! wc_get_order( $smoke_order->get_id() ) instanceof WC_Order ) {
		WP_CLI::error( 'The test datastore could not reload its smoke-test order.' );
	}
} finally {
	$smoke_order->delete( true );
}
WP_CLI::success( 'Test database schema and order creation are ready.' );
