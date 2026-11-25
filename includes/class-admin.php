<?php
namespace C2CReceipts;

defined( 'ABSPATH' ) || exit;

final class Admin {
	public static function register(): void {
		add_action( 'add_meta_boxes', static function () {
			$screens = array( 'shop_order' );
			if ( function_exists( 'wc_get_page_screen_id' ) ) {
				$screens[] = wc_get_page_screen_id( 'shop-order' );
			}
			foreach ( array_unique( $screens ) as $screen ) {
				add_meta_box( 'c2c-receipt-review', __( 'Bank transfer receipt', 'card-to-card-receipts-for-woocommerce' ), array( self::class, 'panel' ), $screen, 'normal', 'high' );
			}
		} );
		add_action( 'admin_post_c2c_review', array( self::class, 'review' ) );
		add_action( 'admin_notices', static function () {
			$message = Support::get( 'c2c_review_message' );
			if ( '' !== $message && current_user_can( 'manage_woocommerce' ) ) {
				echo '<div class="notice notice-info"><p>' . esc_html( $message ) . '</p></div>';
			}
		} );
		add_action( 'admin_enqueue_scripts', static function () {
			$screen = get_current_screen();
			if ( $screen && 'woocommerce_page_wc-settings' === $screen->id ) {
				wp_enqueue_script( 'c2c-accounts', plugins_url( 'assets/accounts.js', C2CRECEIPTS_FILE ), array(), C2CRECEIPTS_VERSION, true );
				wp_enqueue_style( 'c2c-accounts', plugins_url( 'assets/accounts.css', C2CRECEIPTS_FILE ), array(), C2CRECEIPTS_VERSION );
			}
			if ( $screen && in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
				wp_enqueue_script( 'c2c-admin', plugins_url( 'assets/admin.js', C2CRECEIPTS_FILE ), array(), C2CRECEIPTS_VERSION, true );
			}
		} );
	}

	public static function panel( $object ): void {
		$order = $object instanceof \WC_Order ? $object : wc_get_order( $object->ID ?? 0 );
		if ( ! $order || ! Support::reviewer( $order->get_id() ) || 'c2c_receipts' !== $order->get_payment_method() ) {
			echo '<p>' . esc_html__( 'No card to card payment for this order.', 'card-to-card-receipts-for-woocommerce' ) . '</p>';
			return;
		}
		$current = (int) $order->get_meta( '_c2c_receipt_id' );
		foreach ( Receipts::history( $order->get_id() ) as $row ) {
			$account = json_decode( $row->account, true );
			echo '<div style="border-block-end:1px solid #ddd;padding-block:12px">';
			echo '<p><strong>' . esc_html( '#' . $row->id . ' · ' . $row->state ) . '</strong> · ' . esc_html( $row->created_at . ' UTC' ) . '</p>';
			echo '<p>' . esc_html( Support::amount( $row->amount, $row->currency ) ) . ' · ' . esc_html( Support::account_label( (array) $account ) ) . ' · <bdi dir="ltr">' . esc_html( $account['card'] ?? '' ) . '</bdi></p>';
			echo '<p>' . esc_html__( 'Tracking number (optional)', 'card-to-card-receipts-for-woocommerce' ) . ': ' . esc_html( $row->reference ) . '</p>';
			if ( '' !== $row->filename ) {
				$url = Http::file_url( $row );
				echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer"><img src="' . esc_url( $url ) . '" alt="' . esc_attr__( 'Receipt preview', 'card-to-card-receipts-for-woocommerce' ) . '" loading="lazy" style="max-width:100%;max-height:240px"></a>';
			} else {
				echo '<p>' . esc_html__( 'Receipt file removed by the retention or privacy policy.', 'card-to-card-receipts-for-woocommerce' ) . '</p>';
			}
			if ( $row->reviewer ) {
				/* translators: 1: reviewer user ID, 2: review date/time in UTC. */
				echo '<p>' . esc_html( sprintf( __( 'Reviewed by user #%1$d at %2$s UTC.', 'card-to-card-receipts-for-woocommerce' ), $row->reviewer, $row->reviewed_at ) ) . ' ' . esc_html( $row->reason ) . '</p>';
			}
			if ( $current === (int) $row->id && in_array( $row->state, array( 'submitted', 'approving' ), true ) && $order->has_status( 'on-hold' ) ) {
				if ( ! Receipts::matches( $row, $order ) ) {
					echo '<p><strong>' . esc_html__( 'The order total or currency changed. Approval is blocked; reconcile the difference or reject and request a replacement.', 'card-to-card-receipts-for-woocommerce' ) . '</strong></p>';
				}
				// Order screens already contain a form: JS sends this separate review action without nesting forms.
				echo '<div class="c2c-review" data-url="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-order="' . esc_attr( $order->get_id() ) . '" data-receipt="' . esc_attr( $row->id ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'c2c_review_' . $row->id ) ) . '">';
				echo '<p><label><input type="checkbox" class="c2c-confirm"> ' . esc_html__( 'I verified the full deposit in the bank account.', 'card-to-card-receipts-for-woocommerce' ) . '</label></p>';
				echo '<p><label>' . esc_html__( 'Review reason (required for rejection)', 'card-to-card-receipts-for-woocommerce' ) . '<textarea class="c2c-reason" maxlength="2000" style="display:block;width:100%"></textarea></label></p>';
				echo '<button type="button" class="button button-primary c2c-decision" data-decision="approved">' . esc_html__( 'Approve payment', 'card-to-card-receipts-for-woocommerce' ) . '</button> ';
				echo '<button type="button" class="button c2c-decision" data-decision="rejected">' . esc_html__( 'Reject receipt', 'card-to-card-receipts-for-woocommerce' ) . '</button>';
				echo '<p class="c2c-review-status" role="status"></p><noscript>' . esc_html__( 'JavaScript is required for receipt review.', 'card-to-card-receipts-for-woocommerce' ) . '</noscript></div>';
			}
			echo '</div>';
		}
	}

	public static function review(): void {
		$order_id = absint( Support::post( 'order_id' ) );
		$receipt_id = absint( Support::post( 'receipt_id' ) );
		if ( 'POST' !== Support::request_method() || ! Support::reviewer( $order_id ) || ! wp_verify_nonce( Support::post( 'nonce' ), 'c2c_review_' . $receipt_id ) ) {
			wp_die( esc_html__( 'Access denied.', 'card-to-card-receipts-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'card-to-card-receipts-for-woocommerce' ), '', array( 'response' => 404 ) );
		}
		try {
			if ( 'approved' === Support::post( 'decision' ) && 'yes' !== Support::post( 'confirmed' ) ) {
				throw new \RuntimeException( esc_html( __( 'Confirm that you verified the full deposit in the bank account.', 'card-to-card-receipts-for-woocommerce' ) ) );
			}
			Receipts::review( $order_id, $receipt_id, Support::post( 'decision' ), substr( Support::post( 'reason' ), 0, 2000 ) );
			$message = __( 'Receipt review saved.', 'card-to-card-receipts-for-woocommerce' );
		} catch ( \Throwable $e ) {
			$message = $e instanceof \RuntimeException ? Support::plain_error( $e ) : __( 'The review failed. Reload and check the order before retrying.', 'card-to-card-receipts-for-woocommerce' );
		}
		wp_safe_redirect( add_query_arg( 'c2c_review_message', $message, $order->get_edit_order_url() ) );
		exit;
	}
}
