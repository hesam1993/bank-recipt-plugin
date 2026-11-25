<?php
namespace C2CReceipts;

defined( 'ABSPATH' ) || exit;

final class Privacy {
	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', static function ( $exporters ) {
			$exporters['c2c-receipts'] = array( 'exporter_friendly_name' => __( 'Bank transfer receipts', 'card-to-card-receipts-for-woocommerce' ), 'callback' => array( self::class, 'export' ) );
			return $exporters;
		}, 5 );
		add_filter( 'wp_privacy_personal_data_erasers', static function ( $erasers ) {
			$erasers['c2c-receipts'] = array( 'eraser_friendly_name' => __( 'Bank transfer receipts', 'card-to-card-receipts-for-woocommerce' ), 'callback' => array( self::class, 'erase' ) );
			return $erasers;
		}, 5 );
		add_action( 'admin_init', static function () {
			if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
				$content = wpautop( __( 'When you pay by bank transfer, we store your receipt image, payment reference, destination account, order amount and review history. Images are privately stored and accessible to authorized store staff and the order customer. Unattached uploads expire after 24 hours. Receipts for closed orders are removed according to the store retention setting (90 days by default); active reviews and required records may be retained longer. Private guest access links expire after seven days. Contact the store to request access or erasure. No receipt is sent to third-party analysis services.', 'card-to-card-receipts-for-woocommerce' ) );
				$content .= wpautop( __( 'The browser tab temporarily stores an upload token and account/reference information to recover checkout. Upload protection uses WooCommerce session cookies and local hourly counters containing salted IP hashes. No analytics or receipt data is sent to the plugin author. Receipt notifications use the store\'s configured WordPress mail transport.', 'card-to-card-receipts-for-woocommerce' ) );
				wp_add_privacy_policy_content( 'Card to Card Receipts', $content );
			}
		} );
	}

	private static function orders( string $email, int $page ): array {
		return wc_get_orders( array( 'billing_email' => $email, 'payment_method' => 'c2c_receipts', 'limit' => 20, 'page' => max( 1, $page ), 'orderby' => 'ID', 'order' => 'ASC' ) );
	}

	private static function receipts( int $order_id ): \Generator {
		$page = 1;
		do {
			$rows = Receipts::history( $order_id, $page++ );
			yield from $rows;
		} while ( count( $rows ) === 100 );
	}

	public static function export( string $email, int $page = 1 ): array {
		$orders = self::orders( $email, $page );
		$data = array();
		foreach ( $orders as $order ) {
			foreach ( self::receipts( $order->get_id() ) as $row ) {
				$data[] = array(
					'group_id' => 'c2c-receipts', 'group_label' => __( 'Bank transfer receipts', 'card-to-card-receipts-for-woocommerce' ),
					'item_id' => 'c2c-' . $row->id,
					'data' => array(
						array( 'name' => __( 'Order', 'card-to-card-receipts-for-woocommerce' ), 'value' => $order->get_order_number() ),
						array( 'name' => __( 'Receipt state', 'card-to-card-receipts-for-woocommerce' ), 'value' => $row->state ),
						array( 'name' => __( 'Amount', 'card-to-card-receipts-for-woocommerce' ), 'value' => Support::amount( $row->amount, $row->currency ) ),
						array( 'name' => __( 'Reference', 'card-to-card-receipts-for-woocommerce' ), 'value' => $row->reference ),
						array( 'name' => __( 'Review reason', 'card-to-card-receipts-for-woocommerce' ), 'value' => $row->reason ),
						array( 'name' => __( 'Submitted at (UTC)', 'card-to-card-receipts-for-woocommerce' ), 'value' => $row->created_at ),
						array( 'name' => __( 'Receipt image', 'card-to-card-receipts-for-woocommerce' ), 'value' => __( 'Request a private copy from the store. Receipt URLs are not included in public export files.', 'card-to-card-receipts-for-woocommerce' ) ),
					),
				);
			}
		}
		return array( 'data' => $data, 'done' => count( $orders ) < 20 );
	}

	public static function erase( string $email, int $page = 1 ): array {
		global $wpdb;
		$orders = self::orders( $email, $page );
		$removed = false;
		$retained = false;
		foreach ( $orders as $order ) {
			try {
				Support::locked( $order->get_id(), function () use ( $order, $wpdb, &$removed, &$retained ) {
					$order = wc_get_order( $order->get_id() );
					if ( ! $order ) {
						$retained = true;
						return;
					}
					foreach ( self::receipts( $order->get_id() ) as $row ) {
						if ( ! $order->has_status( array( 'completed', 'cancelled', 'refunded', 'failed' ) ) || ! Support::allow_erasure( $row, $order ) ) {
							$retained = true;
							continue;
						}
						try {
							if ( ! Storage::delete( $row->filename ) ) {
								$retained = true;
								continue;
							}
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned receipt table has no core CRUD API; authorization, review, cleanup and erasure require fresh transactional state.
							$updated = $wpdb->update( Receipts::table(), array( 'filename' => '', 'bytes' => 0, 'reference' => '', 'reason' => '', 'owner_hash' => '', 'token_hash' => hash( 'sha256', random_bytes( 32 ) ) ), array( 'id' => $row->id ) );
							if ( false === $updated ) {
								$retained = true;
							} else {
								$removed = true;
							}
						} catch ( \Throwable $e ) {
							$retained = true;
						}
					}
				} );
			} catch ( \Throwable $e ) {
				$retained = true;
			}
		}
		return array( 'items_removed' => $removed, 'items_retained' => $retained, 'messages' => $retained ? array( __( 'Some receipts were retained because the order is active, an erasure restriction applies, or private storage is unavailable.', 'card-to-card-receipts-for-woocommerce' ) ) : array(), 'done' => count( $orders ) < 20 );
	}
}
