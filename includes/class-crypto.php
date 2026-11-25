<?php
namespace C2CReceipts;

defined( 'ABSPATH' ) || exit;

/** Authenticated file encryption; the key is stored in a non-autoloaded WordPress option. */
final class Crypto {
	private const MAGIC = "C2CR\x01";
	private const OPTION = 'c2c_receipts_encryption_key';

	public static function available(): bool {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' ) && function_exists( 'openssl_get_cipher_methods' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true );
	}

	private static function key( bool $create ): string {
		$encoded = get_option( self::OPTION, false );
		if ( false === $encoded && $create ) {
			$encoded = Support::locked( -2, static function () {
				// add_option alone is not a compare-and-set. Recheck under a DB lock with fresh caches.
				wp_cache_delete( self::OPTION, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				$existing = get_option( self::OPTION, false );
				if ( false === $existing ) {
					add_option( self::OPTION, base64_encode( random_bytes( 32 ) ), '', false );
				}
				return get_option( self::OPTION, false );
			} );
		}
		$key = is_string( $encoded ) ? base64_decode( $encoded, true ) : false;
		if ( false === $key || strlen( $key ) !== 32 ) {
			throw new \RuntimeException( esc_html( __( 'The receipt encryption key is unavailable. Restore it from the database backup.', 'card-to-card-receipts-for-woocommerce' ) ) );
		}
		return $key;
	}

	public static function seal( string $image, string $filename ): string {
		if ( ! self::available() ) {
			throw new \RuntimeException( esc_html( __( 'PHP OpenSSL with AES-256-GCM support is required for protected receipt uploads.', 'card-to-card-receipts-for-woocommerce' ) ) );
		}
		$iv = random_bytes( 12 );
		$tag = '';
		$ciphertext = openssl_encrypt( $image, 'aes-256-gcm', self::key( true ), OPENSSL_RAW_DATA, $iv, $tag, self::MAGIC . $filename, 16 );
		if ( false === $ciphertext || strlen( $tag ) !== 16 ) {
			throw new \RuntimeException( esc_html( __( 'Receipt encryption failed.', 'card-to-card-receipts-for-woocommerce' ) ) );
		}
		return self::MAGIC . $iv . $tag . $ciphertext;
	}

	public static function open( string $blob, string $filename ): string {
		if ( ! self::available() || strlen( $blob ) < 34 || ! str_starts_with( $blob, self::MAGIC ) ) {
			throw new \RuntimeException( esc_html( __( 'The protected receipt is invalid or cannot be decrypted.', 'card-to-card-receipts-for-woocommerce' ) ) );
		}
		$image = openssl_decrypt( substr( $blob, 33 ), 'aes-256-gcm', self::key( false ), OPENSSL_RAW_DATA, substr( $blob, 5, 12 ), substr( $blob, 17, 16 ), self::MAGIC . $filename );
		if ( false === $image ) {
			throw new \RuntimeException( esc_html( __( 'The protected receipt is invalid or cannot be decrypted.', 'card-to-card-receipts-for-woocommerce' ) ) );
		}
		return $image;
	}
}
