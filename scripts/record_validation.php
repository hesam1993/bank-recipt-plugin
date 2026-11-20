<?php
/** Run only after all preceding checks succeed in a disposable CI WordPress installation. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}
require __DIR__ . '/assert_test_environment.php';
$record = array(
	'plugin_version' => C2C_RECEIPTS_VERSION,
	'source_sha256' => getenv( 'C2C_SOURCE_SHA256' ),
	'php_version' => PHP_VERSION,
	'wordpress_version' => get_bloginfo( 'version' ),
	'woocommerce_version' => WC_VERSION,
	'hpos' => get_option( 'woocommerce_custom_orders_table_enabled' ),
	'requested_php' => getenv( 'C2C_TEST_PHP' ),
	'requested_hpos' => getenv( 'C2C_TEST_HPOS' ),
	'profile' => getenv( 'C2C_TEST_PROFILE' ),
	'checks' => array( 'javascript' => true, 'php_lint' => true, 'storage_crypto' => true, 'woocommerce' => true, 'http' => true ),
);
if ( ! preg_match( '/^[a-f0-9]{64}$/D', $record['source_sha256'] ) || ! in_array( $record['profile'], array( 'latest', 'minimum' ), true ) ) {
	WP_CLI::error( 'Validation environment is incomplete.' );
}
file_put_contents( getenv( 'RUNNER_TEMP' ) . '/validation.json', wp_json_encode( $record, JSON_PRETTY_PRINT ) . "\n" );
