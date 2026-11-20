<?php
/** Dependency-free storage/encryption regressions: php tests/storage.php. */
$root = sys_get_temp_dir() . '/c2c-storage-' . bin2hex( random_bytes( 8 ) );
mkdir( $root, 0700 );
mkdir( $root . '/public_html', 0700 );
mkdir( $root . '/receipts', 0700 );
mkdir( $root . '/public_html/receipts', 0700 );
define( 'ABSPATH', $root . '/public_html/' );
define( 'DB_NAME', 'storage_test' );
define( 'MB_IN_BYTES', 1048576 );
$_SERVER['DOCUMENT_ROOT'] = $root . '/public_html';
$settings = array();
$options = array();
function get_option( $name, $default = false ) {
	global $settings, $options;
	return 'woocommerce_c2c_receipts_settings' === $name ? $settings : ( $options[ $name ] ?? $default );
}
function add_option( $name, $value, $deprecated = '', $autoload = false ) {
	global $options;
	if ( isset( $options[ $name ] ) ) {
		return false;
	}
	$options[ $name ] = $value;
	return true;
}
function wp_cache_delete( $name, $group ) {}
function wp_upload_dir( $time = null, $create = true ) {
	global $root;
	return array( 'basedir' => $root . '/public_html/uploads', 'error' => false );
}
function wp_mkdir_p( $path ) {
	return is_dir( $path ) || mkdir( $path, 0755, true );
}
function __( $message, $domain = '' ) {
	return $message;
}
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8', false ); }
function esc_html__( $text, $domain = '' ) { return esc_html( $text ); }
function wp_specialchars_decode( $text, $flags = ENT_NOQUOTES ) { return htmlspecialchars_decode( $text, $flags ); }
function wp_unslash( $text ) { return stripslashes( $text ); }
function sanitize_text_field( $text ) { return trim( strip_tags( $text ) ); }
function wp_is_writable( $path ) { return is_writable( $path ); }
$veto_delete = false;
function wp_delete_file( $path ) {
	global $veto_delete;
	if ( ! $veto_delete ) { unlink( $path ); }
}
$wpdb = new class() {
	public $prefix = 'wp_';
	public function prepare( $query, ...$args ) { return $query; }
	public function get_var( $query ) { return '1'; }
};
require_once dirname( __DIR__ ) . '/includes/class-support.php';
require_once dirname( __DIR__ ) . '/includes/class-crypto.php';
require_once dirname( __DIR__ ) . '/includes/class-storage.php';
function check_storage( bool $valid, string $message ): void {
	if ( ! $valid ) {
		throw new RuntimeException( 'FAILED: ' . $message );
	}
}
function expect_storage_error( callable $callback, string $fragment ): void {
	try {
		$callback();
	} catch ( RuntimeException $error ) {
		check_storage( str_contains( $error->getMessage(), $fragment ), 'Wrong diagnostic: ' . $error->getMessage() );
		return;
	}
	throw new RuntimeException( 'Expected a storage failure: ' . $fragment );
}
$name = str_repeat( 'a', 64 ) . '.c2c';
try {
	check_storage( \C2CReceipts\Storage::directory() === realpath( $root . '/public_html/uploads/c2c-receipts' ), 'Automatic WordPress uploads folder without configuration' );
	check_storage( \C2CReceipts\Crypto::available(), 'OpenSSL AES-256-GCM must be available' );
	$plain = "\xff\xd8\xffreceipt-test-bytes";
	$blob = \C2CReceipts\Crypto::seal( $plain, $name );
	check_storage( ! str_contains( $blob, $plain ), 'Stored ciphertext contains no plaintext receipt' );
	check_storage( \C2CReceipts\Crypto::open( $blob, $name ) === $plain, 'Authenticated round trip' );
	check_storage( \C2CReceipts\Crypto::seal( $plain, $name ) !== $blob, 'Fresh IV for every encryption' );
	$key = $options['c2c_receipts_encryption_key'];
	\C2CReceipts\Crypto::seal( $plain, $name );
	check_storage( $options['c2c_receipts_encryption_key'] === $key, 'Encryption never replaces the stored key' );
	$corrupt = $blob;
	$corrupt[20] = chr( ord( $corrupt[20] ) ^ 1 );
	expect_storage_error( fn() => \C2CReceipts\Crypto::open( $corrupt, $name ), 'cannot be decrypted' );
	expect_storage_error( fn() => \C2CReceipts\Crypto::open( $blob, str_repeat( 'b', 64 ) . '.c2c' ), 'cannot be decrypted' );
	expect_storage_error( fn() => \C2CReceipts\Crypto::open( 'truncated', $name ), 'cannot be decrypted' );
	$options['c2c_receipts_encryption_key'] = base64_encode( random_bytes( 32 ) );
	expect_storage_error( fn() => \C2CReceipts\Crypto::open( $blob, $name ), 'cannot be decrypted' );
	$options['c2c_receipts_encryption_key'] = $key;
	file_put_contents( \C2CReceipts\Storage::path( $name ), $blob );
	check_storage( \C2CReceipts\Storage::read( $name ) === $plain, 'Reader decrypts the file in uploads' );
	expect_storage_error( fn() => \C2CReceipts\Storage::path( '../public.php' ), 'Invalid receipt' );
	expect_storage_error( fn() => \C2CReceipts\Storage::legacy_directory(), 'not configured' );
	$settings['private_dir'] = $root . '/receipts';
	check_storage( \C2CReceipts\Storage::legacy_directory() === realpath( $root . '/receipts' ), 'Legacy private directory remains supported' );
	$legacy_name = str_repeat( 'c', 64 ) . '.jpg';
	file_put_contents( \C2CReceipts\Storage::path( $legacy_name ), $plain );
	check_storage( \C2CReceipts\Storage::read( $legacy_name ) === $plain, 'Old private receipt is still readable' );
	$veto_delete = true;
	check_storage( ! \C2CReceipts\Storage::delete( $legacy_name ), 'A WordPress deletion veto cannot report success' );
	check_storage( \C2CReceipts\Storage::read( $legacy_name ) === $plain, 'Vetoed deletion keeps the receipt available' );
	$veto_delete = false;
	check_storage( \C2CReceipts\Storage::delete( $legacy_name ), 'Successful deletion is confirmed' );
	check_storage( \C2CReceipts\Storage::delete( $legacy_name ), 'Already deleted receipts are idempotent' );
	$settings['private_dir'] = $root . '/public_html/receipts';
	expect_storage_error( fn() => \C2CReceipts\Storage::legacy_directory(), 'outside every public web root' );
	check_storage( \C2CReceipts\Storage::directory() === realpath( $root . '/public_html/uploads/c2c-receipts' ), 'Invalid old folder cannot disable new uploads' );
	echo "WordPress storage, authenticated encryption, tampering, key stability and legacy-file checks passed.\n";
} finally {
	@unlink( $root . '/receipts/' . str_repeat( 'c', 64 ) . '.jpg' );
	@unlink( $root . '/public_html/uploads/c2c-receipts/' . $name );
	@rmdir( $root . '/public_html/uploads/c2c-receipts' );
	@rmdir( $root . '/public_html/uploads' );
	rmdir( $root . '/public_html/receipts' );
	rmdir( $root . '/public_html' );
	rmdir( $root . '/receipts' );
	rmdir( $root );
}
