<?php
/** Opt-in deletion only. Never remove WooCommerce orders. */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$c2creceipts_settings = (array) get_option( 'woocommerce_c2c_receipts_settings', array() );
wp_clear_scheduled_hook( 'c2c_receipts_cleanup' );
if ( 'yes' !== ( $c2creceipts_settings['delete_on_uninstall'] ?? 'no' ) ) {
	return;
}
require_once __DIR__ . '/includes/class-support.php';
require_once __DIR__ . '/includes/class-crypto.php';
require_once __DIR__ . '/includes/class-storage.php';
global $wpdb;
$c2creceipts_table = $wpdb->prefix . 'c2c_receipts';
$c2creceipts_cursor = 0;
do {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned receipt table has no core CRUD API; authorization, review, cleanup and erasure require fresh transactional state.
	$c2creceipts_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, filename FROM %i WHERE id > %d ORDER BY id LIMIT 100', $c2creceipts_table, $c2creceipts_cursor ) );
	foreach ( $c2creceipts_rows as $c2creceipts_row ) {
		try {
			if ( ! \C2CReceipts\Storage::delete( $c2creceipts_row->filename ) ) {
				wp_die( esc_html__( 'Receipt deletion failed. Restore private storage access and retry uninstall.', 'c2c-receipts' ) );
			}
		} catch ( \Throwable $c2creceipts_error ) {
			wp_die( esc_html__( 'Private receipt storage is unavailable. Restore access before uninstalling with deletion enabled.', 'c2c-receipts' ) );
		}
		$c2creceipts_cursor = (int) $c2creceipts_row->id;
	}
} while ( count( $c2creceipts_rows ) === 100 );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Explicit opt-in uninstall removes only this plugin-owned table after receipt file deletion succeeds.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $c2creceipts_table ) );
delete_option( 'woocommerce_c2c_receipts_settings' );
delete_option( 'c2c_receipts_db_version' );
delete_option( 'c2c_receipts_cleanup_cursor' );
delete_option( 'c2c_receipts_encryption_key' );
