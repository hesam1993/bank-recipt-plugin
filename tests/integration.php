<?php
/** Run inside a disposable WordPress/WooCommerce installation: wp eval-file tests/integration.php */
use C2CReceipts\Customer;
use C2CReceipts\Receipts;
use C2CReceipts\Storage;
use C2CReceipts\Support;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

$checks = 0;
function c2c_check( bool $condition, string $message ): void {
	global $checks;
	if ( ! $condition ) {
		throw new RuntimeException( 'FAILED: ' . $message );
	}
	++$checks;
}
function c2c_rejects( callable $callback, string $message ): void {
	try {
		$callback();
	} catch ( RuntimeException | InvalidArgumentException $e ) {
		c2c_check( true, $message );
		return;
	}
	c2c_check( false, $message );
}

wc_load_cart();
wp_set_current_user( 1 );
$settings = array( 'enabled' => 'yes', 'accounts' => 'Test Bank | Test Holder | 6037997512345678 | 001234 | ', 'retention_days' => 1 );
update_option( 'woocommerce_c2c_receipts_settings', $settings );
$account = array_values( Support::accounts() )[0];
c2c_check( Storage::ready(), 'Private storage and GD are available' );
c2c_check( Support::digits( '۰۱۲٣٤۵' ) === '012345', 'Persian and Arabic digits normalize' );
c2c_check( Support::accounts( 'Bank | Holder | ۰۱۲۳۴۵۶۷۸۹۰۱۲۳۴۵ | ۰۰۱۲ | ' ) !== array(), 'Leading zeroes survive account parsing' );
c2c_rejects( fn() => Support::accounts( 'Bank | Holder | 123' ), 'Short cards rejected' );
c2c_rejects( fn() => Storage::path( '../public.php' ), 'Path traversal rejected' );

// Regressions: the merchant entered Card | Holder in Persian, and save errors were invisible.
require_once WC_ABSPATH . 'includes/admin/class-wc-admin-settings.php';
$persian_account = '۶۰۳۷۹۹۷۵۱۲۳۴۵۶۷۸ | علی رضایی';
$parsed = array_values( Support::accounts( $persian_account ) )[0];
c2c_check( $parsed['card'] === '6037997512345678' && $parsed['holder'] === 'علی رضایی', 'Persian Card | Holder input parses without a bank' );
c2c_check( Support::account_label( $parsed ) === 'علی رضایی', 'Bankless account label has no dangling separator' );
$gateway = new \C2CReceipts\Gateway();
$gateway->set_post_data( array( 'woocommerce_c2c_receipts_enabled' => '1', 'woocommerce_c2c_receipts_accounts' => wp_slash( $persian_account ) ) );
$gateway->process_admin_options();
$reloaded = new \C2CReceipts\Gateway();
c2c_check( $reloaded->get_option( 'accounts' ) === $persian_account, 'Persian shorthand survives actual WooCommerce save and reload' );
c2c_check( ! $reloaded->needs_setup() && $reloaded->is_available(), 'Valid accounts and private storage make the enabled gateway available' );
$fields = array( array( 'card' => '۶۰۳۷۹۹۷۵۱۲۳۴۵۶۷۸', 'bank' => 'بانک نمونه', 'holder' => 'علی رضایی', 'id' => '' ) );
$reloaded->set_post_data( array( 'woocommerce_c2c_receipts_enabled' => '1', 'woocommerce_c2c_receipts_accounts' => wp_slash( $fields ) ) );
$reloaded->process_admin_options();
$stored = ( new \C2CReceipts\Gateway() )->get_option( 'accounts' );
$saved_account = array_values( Support::accounts() )[0];
c2c_check( $saved_account['bank'] === 'بانک نمونه' && $saved_account['holder'] === 'علی رضایی' && $saved_account['card'] === '6037997512345678', 'Three independent Persian account fields save correctly' );
$fields[0]['card'] = '۱۲۳';
$reloaded->set_post_data( array( 'woocommerce_c2c_receipts_enabled' => '1', 'woocommerce_c2c_receipts_accounts' => wp_slash( $fields ) ) );
$reloaded->process_admin_options();
c2c_check( ( new \C2CReceipts\Gateway() )->get_option( 'accounts' ) === $stored, 'Invalid edit cannot overwrite the previously saved account' );
ob_start();
WC_Admin_Settings::show_messages();
$error_html = ob_get_clean();
c2c_check( str_contains( $error_html, 'Account 1 needs' ), 'Account validation reaches the visible WooCommerce error queue' );
ob_start();
( new \C2CReceipts\Gateway() )->admin_options();
$settings_html = ob_get_clean();
c2c_check( str_contains( $settings_html, 'value="۱۲۳"' ) && str_contains( $settings_html, 'value="علی رضایی"' ), 'Rejected input survives gateway recreation for correction' );
c2c_check( str_contains( $settings_html, '6037997512345678' ), 'Saved-account summary shows the actual stored account' );
c2c_check( str_contains( $settings_html, 'Card number' ) && str_contains( $settings_html, 'Bank name' ) && str_contains( $settings_html, 'Account holder name' ), 'Settings render three labeled account fields' );
c2c_check( ! str_contains( $settings_html, '[private_dir]' ), 'New uploads require no private directory setting' );
update_option( 'woocommerce_c2c_receipts_settings', $settings );

$product = new WC_Product_Simple();
$product->set_name( 'C2CReceipts integration test' );
$product->set_regular_price( '100' );
$product->set_virtual( false );
$product->set_manage_stock( true );
$product->set_stock_quantity( 5 );
$product->save();
$order = wc_create_order();
$order->add_product( $product );
$order->set_billing_email( 'buyer@example.test' );
$order->set_payment_method( 'c2c_receipts' );
$order->calculate_totals();
$order->save();
$token = bin2hex( random_bytes( 32 ) );
$name = bin2hex( random_bytes( 32 ) ) . '.c2c';
$image = imagecreatetruecolor( 2, 2 );
ob_start();
imagejpeg( $image );
$image_bytes = ob_get_clean();
file_put_contents( Storage::path( $name ), \C2CReceipts\Crypto::seal( $image_bytes, $name ) );
imagedestroy( $image );
c2c_check( Storage::read( $name ) === $image_bytes, 'Normal uploads storage decrypts only through the protected reader' );
c2c_check( ! str_contains( file_get_contents( Storage::path( $name ) ), $image_bytes ), 'The uploads file never contains plaintext image bytes' );
global $wpdb;
$wpdb->insert( Receipts::table(), array(
	'token_hash' => hash( 'sha256', $token ), 'owner_hash' => Support::owner(),
	'filename' => $name, 'bytes' => filesize( Storage::path( $name ) ), 'amount' => $order->get_total(),
	'currency' => $order->get_currency(), 'account' => wp_json_encode( $account ), 'reason' => '',
	'created_at' => gmdate( 'Y-m-d H:i:s' ), 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
) );
$id = (int) $wpdb->insert_id;
c2c_check( $id > 0, 'Receipt table exists' );

// Owner binding and scoped replacement checks use real WooCommerce sessions and the real database.
$secret = WC()->session->get( 'c2c_owner' );
WC()->session->set( 'c2c_owner', bin2hex( random_bytes( 32 ) ) );
c2c_rejects( fn() => Receipts::validate( $token ), 'Another session cannot use a receipt token' );
WC()->session->set( 'c2c_owner', $secret );
c2c_rejects( fn() => Receipts::attach( $order, $token, true ), 'Checkout upload cannot masquerade as an order replacement' );
$wpdb->update( Receipts::table(), array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'id' => $id ) );
c2c_rejects( fn() => Receipts::validate( $token ), 'Expired staged token rejected' );
$wpdb->update( Receipts::table(), array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ), array( 'id' => $id ) );
Receipts::attach( $order, $token );
$order = wc_get_order( $order->get_id() );
c2c_check( $order->has_status( 'on-hold' ), 'Submission places order on hold' );
c2c_check( ! $order->is_paid(), 'Upload never marks payment complete' );
c2c_check( (int) $order->get_meta( '_c2c_receipt_id' ) === $id, 'Order points to receipt via CRUD' );
c2c_check( wc_get_product( $product->get_id() )->get_stock_quantity() === 4, 'On hold reduces stock once' );
Receipts::attach( $order, $token );
c2c_check( wc_get_product( $product->get_id() )->get_stock_quantity() === 4, 'Retry does not reduce stock twice' );
$other = wc_create_order();
$other->set_payment_method( 'c2c_receipts' );
$other->save();
c2c_rejects( fn() => Receipts::attach( $other, $token ), 'Receipt cannot be claimed by another order' );

$access = Customer::access( $order );
wp_set_current_user( 0 );
WC()->session->set( 'c2c_owner', bin2hex( random_bytes( 32 ) ) );
c2c_check( Customer::allowed( $order, $access ), 'Signed guest link grants scoped access' );
c2c_check( ! Customer::allowed( $other, $access ), 'Signed guest link cannot access another order' );
$bad = $access;
$bad['c2c_access'] = str_repeat( '0', 64 );
c2c_check( ! Customer::allowed( $order, $bad ), 'Forged guest link rejected' );
$expired = $access;
$expired['c2c_expires'] = time() - 1;
c2c_check( ! Customer::allowed( $order, $expired ), 'Expired guest link rejected' );
$saved_get = $_GET;
$saved_post = $_POST;
try {
	$_GET = $access;
	$_GET['c2c_expires'] = (string) $access['c2c_expires'];
	c2c_check( Customer::allowed( $order, Support::access_input() ), 'Sanitized signed GET parameters still authorize guests' );
	$_GET['c2c_access'] = array( $access['c2c_access'] );
	c2c_check( ! Customer::allowed( $order, Support::access_input() ), 'Array-shaped signatures cannot authorize access' );
	$_POST = array( 'reference' => wp_slash( "علی's ۱۲۳" ), 'nonce' => array( 'forged' ) );
	c2c_check( Support::post( 'reference' ) === "علی's ۱۲۳", 'Input parsing unslashes once and preserves Persian text' );
	c2c_check( '' === Support::post( 'nonce' ) && '' === Support::post( 'missing' ), 'Malformed and missing nonce inputs fail closed' );
	$error = new RuntimeException( esc_html( "علی <script>alert('x')</script> & بانک" ) );
	c2c_check( ! str_contains( $error->getMessage(), '<script>' ), 'Exception message is safe for HTML notices' );
	c2c_check( Support::plain_error( $error ) === "علی <script>alert('x')</script> & بانک", 'JSON transport recovers plain text for textContent rendering' );
} finally {
	$_GET = $saved_get;
	$_POST = $saved_post;
}
// Both published retention filters must be able to deny erasure independently.
add_filter( 'deprecated_hook_trigger_error', '__return_false' );
add_filter( 'c2c_receipts_allow_erasure', '__return_false' );
add_filter( 'c2creceipts_allow_erasure', '__return_true' );
c2c_check( ! Support::allow_erasure( (object) array( 'id' => $id ), $order ), 'New filter cannot override a legacy retention hold' );
remove_filter( 'c2c_receipts_allow_erasure', '__return_false' );
remove_filter( 'c2creceipts_allow_erasure', '__return_true' );
add_filter( 'c2creceipts_allow_erasure', '__return_false' );
c2c_check( ! Support::allow_erasure( (object) array( 'id' => $id ), $order ), 'New retention filter independently denies erasure' );
remove_filter( 'c2creceipts_allow_erasure', '__return_false' );
remove_filter( 'deprecated_hook_trigger_error', '__return_false' );
c2c_check( Support::allow_erasure( (object) array( 'id' => $id ), $order ), 'No retention hold allows eligible erasure' );
wp_set_current_user( 1 );
WC()->session->set( 'c2c_owner', $secret );

$original_total = $order->get_total();
$order->set_total( (float) $original_total + 1 );
$order->save();
c2c_rejects( fn() => Receipts::review( $order->get_id(), $id, 'approved', '' ), 'Changed amount blocks approval' );
c2c_rejects( fn() => Receipts::review( $order->get_id(), $id, 'rejected', '' ), 'Rejection needs a reason' );
$order->set_total( $original_total );
$order->save();
$original_currency = $order->get_currency();
$order->set_currency( 'USD' === $original_currency ? 'EUR' : 'USD' );
$order->save();
c2c_rejects( fn() => Receipts::review( $order->get_id(), $id, 'approved', '' ), 'Changed currency blocks approval' );
$order->set_currency( $original_currency );
$order->save();

// Reject, then upload an order-scoped replacement. A staged token must never attach elsewhere.
Receipts::review( $order->get_id(), $id, 'rejected', 'Unreadable test receipt' );
c2c_check( Receipts::get( $id )->state === 'rejected', 'Rejection persisted without changing order to paid' );
c2c_check( ! wc_get_order( $order->get_id() )->is_paid(), 'Rejection does not complete payment' );
$replacement_token = bin2hex( random_bytes( 32 ) );
$replacement_name = bin2hex( random_bytes( 32 ) ) . '.c2c';
file_put_contents( Storage::path( $replacement_name ), \C2CReceipts\Crypto::seal( Storage::read( $name ), $replacement_name ) );
$wpdb->insert( Receipts::table(), array(
	'token_hash' => hash( 'sha256', $replacement_token ), 'owner_hash' => Support::owner(),
	'scope_order_id' => $order->get_id(), 'filename' => $replacement_name, 'bytes' => filesize( Storage::path( $replacement_name ) ),
	'amount' => $order->get_total(), 'currency' => $order->get_currency(), 'account' => wp_json_encode( $account ), 'reason' => '',
	'created_at' => gmdate( 'Y-m-d H:i:s' ), 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
) );
$replacement_id = (int) $wpdb->insert_id;
Receipts::attach( $order, $replacement_token, true );
c2c_check( Receipts::get( $id )->state === 'superseded', 'Replacement preserves prior receipt history' );
c2c_check( (int) wc_get_order( $order->get_id() )->get_meta( '_c2c_receipt_id' ) === $replacement_id, 'Replacement becomes the current receipt' );
$id = $replacement_id;
$token = $replacement_token;
$name = $replacement_name;
Receipts::review( $order->get_id(), $id, 'approved', '' );
c2c_check( wc_get_order( $order->get_id() )->is_paid(), 'Approval completes payment' );
c2c_check( Receipts::get( $id )->state === 'approved', 'Receipt approval persisted' );
c2c_rejects( fn() => Receipts::review( $order->get_id(), $id, 'approved', '' ), 'Repeated approval rejected' );
c2c_check( wc_get_product( $product->get_id() )->get_stock_quantity() === 4, 'Approval preserves the existing stock reduction' );

// If a request dies after WooCommerce completes payment, cron reconciles the persisted intent.
$wpdb->update( Receipts::table(), array( 'state' => 'approving' ), array( 'id' => $id ) );
Receipts::cleanup();
c2c_check( Receipts::get( $id )->state === 'approved', 'Interrupted approval reconciled from the paid order' );

// Retention must retain active orders and then delete closed-order image bytes.
$wpdb->update( Receipts::table(), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) ), array( 'id' => $id ) );
Receipts::cleanup();
c2c_check( is_file( Storage::path( $name ) ), 'Active processing order retained' );
$order = wc_get_order( $order->get_id() );
$order->update_status( 'completed' );
Receipts::cleanup();
c2c_check( ! is_file( Storage::path( $name ) ), 'Closed-order receipt file removed after retention' );
c2c_check( '' === Receipts::get( $id )->filename, 'Receipt audit row survives retention' );
// A second database connection holds the order lock: erasure must report retention instead of failing fatally.
$competing_db = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$lock_name = 'c2c_' . md5( DB_NAME . ':' . $wpdb->prefix . ':' . $order->get_id() );
$competing_db->get_var( $competing_db->prepare( 'SELECT GET_LOCK(%s, 3)', $lock_name ) );
try {
	$erasure = \C2CReceipts\Privacy::erase( 'buyer@example.test' );
	c2c_check( $erasure['items_retained'] && ! empty( $erasure['messages'] ), 'Locked order produces an explicit retained-data privacy result' );
} finally {
	$competing_db->get_var( $competing_db->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
	$competing_db->close();
}
$order->delete( true );
$other->delete( true );
$product->delete( true );
WP_CLI::success( "$checks integration checks passed." );
