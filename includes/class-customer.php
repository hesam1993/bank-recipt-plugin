<?php
namespace C2CReceipts;

defined( 'ABSPATH' ) || exit;

final class Customer {
	public static function register(): void {
		add_action( 'woocommerce_thankyou_c2c_receipts', array( self::class, 'order_panel' ) );
		add_action( 'woocommerce_view_order', array( self::class, 'order_panel' ) );
		add_action( 'woocommerce_email_after_order_table', array( self::class, 'email' ), 20, 4 );
		add_action( 'template_redirect', static function () {
			if ( '' !== Support::get( 'c2c_access' ) ) {
				nocache_headers();
				header( 'Cache-Control: private, no-store, max-age=0' );
				header( 'Referrer-Policy: no-referrer' );
			}
		} );
		add_shortcode( 'c2c_receipt', static function () {
			$id = absint( Support::get( 'c2c_order' ) );
			ob_start();
			self::order_panel( $id );
			return ob_get_clean();
		} );
		// A receipt management view is served through the normal storefront, without a required extra page.
		add_action( 'template_redirect', static function () {
			if ( '' === Support::get( 'c2c_access' ) || '' === Support::get( 'c2c_order' ) ) {
				return;
			}
			$id = absint( Support::get( 'c2c_order' ) );
			$order = wc_get_order( $id );
			if ( ! $order || ! self::allowed( $order, Support::access_input() ) ) {
				wp_die( esc_html__( 'This order link is invalid or expired.', 'card-to-card-receipts-for-woocommerce' ), '', array( 'response' => 403 ) );
			}
			Plugin::enqueue_assets();
			get_header();
			echo '<main class="c2c-manage"><h1>' . esc_html__( 'Bank transfer receipt', 'card-to-card-receipts-for-woocommerce' ) . '</h1>';
			self::order_panel( $id );
			echo '</main>';
			get_footer();
			exit;
		}, 20 );
	}

	public static function access( \WC_Order $order ): array {
		$expires = time() + 7 * DAY_IN_SECONDS;
		return array( 'c2c_order' => $order->get_id(), 'c2c_expires' => $expires, 'c2c_access' => self::signature( $order, $expires ) );
	}

	private static function signature( \WC_Order $order, int $expires ): string {
		return hash_hmac( 'sha256', implode( '|', array( $order->get_id(), $expires, $order->get_order_key(), $order->get_billing_email() ) ), wp_salt( 'secure_auth' ) );
	}

	public static function allowed( \WC_Order $order, array $input ): bool {
		if ( Support::reviewer( $order->get_id() ) || ( $order->get_customer_id() > 0 && get_current_user_id() === $order->get_customer_id() ) ) {
			return true;
		}
		$expires = isset( $input['c2c_expires'] ) && is_scalar( $input['c2c_expires'] ) ? absint( $input['c2c_expires'] ) : 0;
		$signature = isset( $input['c2c_access'] ) && is_string( $input['c2c_access'] ) ? $input['c2c_access'] : '';
		if ( $expires >= time() && $expires <= time() + 7 * DAY_IN_SECONDS && hash_equals( self::signature( $order, $expires ), $signature ) ) {
			return true;
		}
		$row = Receipts::get( (int) $order->get_meta( '_c2c_receipt_id' ) );
		// Guest access in the original checkout session; no access by order ID/email alone.
		return $row && WC()->session && WC()->session->get( 'c2c_owner' ) && hash_equals( $row->owner_hash, Support::owner() );
	}

	public static function form( ?\WC_Order $order = null, array $access = array() ): void {
		$accounts = $order ? array( (array) $order->get_meta( '_c2c_account' ) ) : array_values( Support::accounts() );
		echo '<div class="c2c-form" data-order-id="' . esc_attr( $order ? $order->get_id() : 0 ) . '" data-access="' . esc_attr( wp_json_encode( $access ) ) . '">';
		if ( $order ) {
			echo '<p>' . esc_html( Support::amount( $order->get_total(), $order->get_currency() ) ) . '</p>';
		} elseif ( WC()->cart ) {
			echo '<p>' . esc_html( Support::amount( WC()->cart->get_total( 'edit' ), get_woocommerce_currency() ) ) . '</p>';
		}
		echo '<label>' . esc_html__( 'Destination account', 'card-to-card-receipts-for-woocommerce' ) . '<select name="c2c_account" class="c2c-account">';
		foreach ( $accounts as $account ) {
			echo '<option value="' . esc_attr( $account['id'] ?? '' ) . '" data-account="' . esc_attr( wp_json_encode( $account ) ) . '">' . esc_html( Support::account_label( $account ) ) . '</option>';
		}
		echo '</select></label><div class="c2c-details"></div>';
		echo '<label>' . esc_html__( 'Tracking number (optional)', 'card-to-card-receipts-for-woocommerce' ) . '<input type="text" class="c2c-reference" maxlength="120" autocomplete="off"></label>';
		echo '<label>' . esc_html__( 'Receipt image', 'card-to-card-receipts-for-woocommerce' ) . '<input type="file" class="c2c-file" accept="image/jpeg,image/png,image/webp"></label>';
		echo '<p class="c2c-help"></p><progress class="c2c-progress" max="100" value="0" hidden></progress><img class="c2c-preview" alt="' . esc_attr__( 'Receipt preview', 'card-to-card-receipts-for-woocommerce' ) . '" hidden>';
		echo '<p class="c2c-status" role="status" aria-live="polite"></p><input type="hidden" name="c2c_token" class="c2c-token">';
		if ( $order ) {
			echo '<button type="button" class="button c2c-replace" disabled>' . esc_html__( 'Submit replacement receipt', 'card-to-card-receipts-for-woocommerce' ) . '</button>';
		}
		echo '</div>';
	}

	public static function order_panel( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || 'c2c_receipts' !== $order->get_payment_method() || ! self::allowed( $order, Support::access_input() ) ) {
			return;
		}
		$row = Receipts::get( (int) $order->get_meta( '_c2c_receipt_id' ) );
		if ( ! $row ) {
			return;
		}
		$access = self::access( $order );
		echo '<section class="c2c-order" data-c2c-clear="1"><h2>' . esc_html__( 'Bank transfer receipt', 'card-to-card-receipts-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html( $order->is_paid() ? __( 'Payment verified.', 'card-to-card-receipts-for-woocommerce' ) : ( 'rejected' === $row->state ? __( 'Receipt rejected. Please submit a replacement.', 'card-to-card-receipts-for-woocommerce' ) : __( 'Receipt received; payment awaiting verification.', 'card-to-card-receipts-for-woocommerce' ) ) ) . '</p>';
		if ( 'rejected' === $row->state ) {
			echo '<p>' . esc_html( $row->reason ) . '</p>';
		}
		if ( '' !== $row->filename ) {
			echo '<p><a target="_blank" rel="noopener noreferrer" href="' . esc_url( Http::file_url( $row, $access ) ) . '">' . esc_html__( 'View receipt', 'card-to-card-receipts-for-woocommerce' ) . '</a></p>';
		}
		if ( 'rejected' === $row->state && $order->has_status( 'on-hold' ) ) {
			self::form( $order, $access );
		}
		echo '</section>';
	}

	public static function email( $order, $sent_to_admin, $plain_text, $email ): void {
		if ( ! $order instanceof \WC_Order || 'c2c_receipts' !== $order->get_payment_method() || $sent_to_admin ) {
			return;
		}
		$url = add_query_arg( self::access( $order ), home_url( '/' ) );
		$text = __( 'View your receipt or submit a replacement. This private link expires in seven days; do not share it.', 'card-to-card-receipts-for-woocommerce' );
		if ( $plain_text ) {
			// A text/plain message must preserve URL ampersands rather than emit HTML entities.
			echo "\n" . wp_strip_all_tags( $text ) . "\n" . esc_url_raw( $url ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text email; static translated text and a sanitized generated URL.
		} else {
			echo '<p><a href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a></p>';
		}
	}
}
