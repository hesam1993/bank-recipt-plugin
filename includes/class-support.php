<?php
namespace C2CReceipts;

defined( 'ABSPATH' ) || exit;

final class Support {

	public static function settings(): array {
		return (array) get_option( 'woocommerce_c2c_receipts_settings', array() );
	}

	public static function digits( string $value ): string {
		return strtr( $value, array_combine( preg_split( '//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY ), str_split( '01234567890123456789' ) ) );
	}

	/** Account values remain strings, preserving leading zeroes. */
	public static function accounts( ?string $raw = null ): array {
		$raw = $raw ?? (string) ( self::settings()['accounts'] ?? '' );
		$accounts = array();
		foreach ( preg_split( '/\R/u', $raw ) as $index => $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( count( $parts ) > 5 || count( $accounts ) >= 20 ) {
				throw new \InvalidArgumentException( esc_html( __( 'Use at most 20 accounts, with five fields per line.', 'card-to-card-receipts-for-woocommerce' ) ) );
			}
			if ( count( $parts ) === 2 ) {
				[ $card, $holder ] = $parts;
				$bank = $number = $iban = '';
			} else {
				[ $bank, $holder, $card, $number, $iban ] = array_pad( $parts, 5, '' );
			}
			$card = preg_replace( '/[\s-]+/u', '', self::digits( $card ) );
			$number = preg_replace( '/[\s-]+/u', '', self::digits( $number ) );
			$iban = strtoupper( preg_replace( '/\s+/u', '', self::digits( $iban ) ) );
			if ( '' === $holder || ! preg_match( '/^\d{16}$/D', $card ) || ( '' !== $number && ! preg_match( '/^\d{1,40}$/D', $number ) ) || ( '' !== $iban && ! preg_match( '/^IR\d{24}$/D', $iban ) ) ) {
				/* translators: %d: one-based line number in legacy account configuration. */
				throw new \InvalidArgumentException( esc_html( sprintf( __( 'Account line %d is invalid. Use Card | Holder, or Bank | Holder | Card | Account | Sheba. The card needs 16 digits; optional Sheba needs IR and 24 digits.', 'card-to-card-receipts-for-woocommerce' ), $index + 1 ) ) );
			}
			$account = array( 'bank' => sanitize_text_field( $bank ), 'holder' => sanitize_text_field( $holder ), 'card' => $card, 'number' => $number, 'iban' => $iban );
			$account['id'] = substr( hash( 'sha256', wp_json_encode( $account ) ), 0, 24 );
			$accounts[ $account['id'] ] = $account;
		}
		return $accounts;
	}

	public static function account_label( array $account ): string {
		return implode( ' — ', array_filter( array( $account['bank'] ?? '', $account['holder'] ?? '' ), static fn( $value ) => '' !== $value ) );
	}

	public static function owner(): string {
		if ( ! WC()->session ) {
			WC()->initialize_session();
		}
		WC()->session->set_customer_session_cookie( true );
		$secret = WC()->session->get( 'c2c_owner' );
		if ( ! is_string( $secret ) || strlen( $secret ) !== 64 ) {
			$secret = bin2hex( random_bytes( 32 ) );
			WC()->session->set( 'c2c_owner', $secret );
		}
		return hash_hmac( 'sha256', $secret, wp_salt( 'auth' ) );
	}

	public static function reviewer( int $order_id ): bool {
		return current_user_can( 'manage_woocommerce' ) && current_user_can( 'edit_shop_order', $order_id );
	}

	public static function locked( int $order_id, callable $callback ): mixed {
		global $wpdb;
		$name = 'c2c_' . md5( DB_NAME . ':' . $wpdb->prefix . ':' . $order_id );
		// Connection-scoped MySQL locks are released even if a PHP request crashes.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-scoped advisory locks are side effects and must never be object-cached.
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $name ) ) ) {
			throw new \RuntimeException( esc_html( __( 'This order is being updated. Please try again.', 'card-to-card-receipts-for-woocommerce' ) ) );
		}
		try {
			return $callback();
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-scoped advisory locks are side effects and must never be object-cached.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	public static function amount( string $amount, string $currency ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount, array( 'currency' => $currency ) ) ), ENT_QUOTES, 'UTF-8' ) . ' (' . $currency . ')';
	}

	public static function post( string $key ): string {
		// This parser also reads the nonce itself; mutation entry points verify their action-specific nonce/capability.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Parsing only; no state changes occur here.
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	public static function get( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only parameters; receipt access is separately authenticated against its order.
		return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
	}

	public static function server( string $key ): string {
		return isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) : '';
	}

	public static function request_method(): string {
		return strtoupper( self::server( 'REQUEST_METHOD' ) );
	}

	public static function access_input( bool $post = false ): array {
		$read = $post ? array( self::class, 'post' ) : array( self::class, 'get' );
		return array( 'c2c_access' => $read( 'c2c_access' ), 'c2c_expires' => $read( 'c2c_expires' ) );
	}

	/** Exceptions carry HTML-safe messages for WooCommerce; JSON and URL transports need plain text. */
	public static function plain_error( \Throwable $error ): string {
		return wp_specialchars_decode( $error->getMessage(), ENT_QUOTES );
	}

	public static function allow_erasure( object $receipt, ?\WC_Order $order ): bool {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Retain the published legacy filter so existing retention holds are never bypassed.
		$legacy = apply_filters_deprecated( 'c2c_receipts_allow_erasure', array( true, $receipt, $order ), '0.1.4', 'c2creceipts_allow_erasure' );
		$current = apply_filters( 'c2creceipts_allow_erasure', $legacy, $receipt, $order );
		return (bool) $legacy && (bool) $current;
	}
}
