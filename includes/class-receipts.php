<?php
namespace C2CReceipts;

defined( 'ABSPATH' ) || exit;

final class Receipts {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'c2c_receipts';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token_hash char(64) NOT NULL,
			owner_hash char(64) NOT NULL,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			scope_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			filename varchar(80) NOT NULL,
			bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			state varchar(20) NOT NULL DEFAULT 'staged',
			amount varchar(40) NOT NULL,
			currency varchar(12) NOT NULL,
			account longtext NOT NULL,
			reference varchar(120) NOT NULL DEFAULT '',
			reviewer bigint(20) unsigned NOT NULL DEFAULT 0,
			reason text NOT NULL,
			created_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			reviewed_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY order_id (order_id),
			KEY owner_created (owner_hash,created_at),
			KEY expiry (state,expires_at)
		) $charset;" );
		update_option( 'c2c_receipts_db_version', '1', false );
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned receipt table has no core CRUD API; authorization, review, cleanup and erasure require fresh transactional state.
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ) );
	}

	public static function by_token( string $token ): ?object {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned receipt table has no core CRUD API; authorization, review, cleanup and erasure require fresh transactional state.
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token_hash = %s', self::table(), hash( 'sha256', $token ) ) );
	}

	public static function history( int $order_id, int $page = 1 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned receipt table has no core CRUD API; authorization, review, cleanup and erasure require fresh transactional state.
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY id DESC LIMIT 100 OFFSET %d', self::table(), $order_id, max( 0, $page - 1 ) * 100 ) );
	}

	public static function stage( array $file, string $account_id, string $reference, ?\WC_Order $order ): array {
		global $wpdb;
		$accounts = Support::accounts();
		if ( $order ) {
			$account = (array) $order->get_meta( '_c2c_account' );
			$amount = $order->get_total();
			$currency = $order->get_currency();
		} else {
			$account = $accounts[ $account_id ] ?? null;
			if ( ! WC()->cart || WC()->cart->is_empty() ) {
				throw new \RuntimeException( esc_html( __( 'Your cart is empty. Please return to checkout.', 'card-to-card-receipts-for-woocommerce' ) ) );
			}
			WC()->cart->calculate_totals();
			$amount = WC()->cart->get_total( 'edit' );
			$currency = get_woocommerce_currency();
		}
		if ( ! $account ) {
			throw new \RuntimeException( esc_html( __( 'Choose a valid destination account.', 'card-to-card-receipts-for-woocommerce' ) ) );
		}
		$owner = Support::owner();
		// Serialize storage writes across sessions so concurrent requests cannot bypass safety budgets.
		return Support::locked( -1, function () use ( $wpdb, $file, $owner, $account, $amount, $currency, $reference, $order ) {
			// Do not trust forwarded headers: proxy-aware rate limits belong at the proxy.
			$raw_ip = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
			$ip = filter_var( $raw_ip, FILTER_VALIDATE_IP ) ?: 'unknown';
			$rate_key = 'c2c_rate_' . hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
			$rate = (array) get_transient( $rate_key );
			if ( (int) ( $rate['count'] ?? 0 ) >= 30 && (int) ( $rate['reset'] ?? 0 ) > time() ) {
				throw new \RuntimeException( esc_html( __( 'The receipt upload limit has been reached. Please contact the store.', 'card-to-card-receipts-for-woocommerce' ) ) );
			}
			if ( (int) ( $rate['reset'] ?? 0 ) <= time() ) {
				$rate = array( 'count' => 0, 'reset' => time() + HOUR_IN_SECONDS );
			}
			++$rate['count'];
			set_transient( $rate_key, $rate, max( 1, $rate['reset'] - time() ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Safety budgets must read authoritative plugin-table totals while holding the storage lock.
			$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE owner_hash = %s AND created_at > %s', self::table(), $owner, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Safety budgets must read authoritative plugin-table totals while holding the storage lock.
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(bytes),0) FROM %i WHERE filename <> %s', self::table(), '' ) );
			$quota = defined( 'C2C_RECEIPTS_QUOTA_MB' ) ? max( 10, (int) C2C_RECEIPTS_QUOTA_MB ) * MB_IN_BYTES : 1024 * MB_IN_BYTES;
			if ( $count >= 10 || $total + 10 * MB_IN_BYTES > $quota ) {
				throw new \RuntimeException( esc_html( __( 'The receipt upload limit has been reached. Please contact the store.', 'card-to-card-receipts-for-woocommerce' ) ) );
			}
			$saved = Storage::save( $file );
			if ( $total + $saved['bytes'] > $quota ) {
				Storage::delete( $saved['filename'] );
				throw new \RuntimeException( esc_html( __( 'The receipt upload limit has been reached. Please contact the store.', 'card-to-card-receipts-for-woocommerce' ) ) );
			}
			$token = bin2hex( random_bytes( 32 ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned receipt table has no core CRUD API; insert is serialized with the upload safety-budget lock.
			$ok = $wpdb->insert( self::table(), array(
				'token_hash' => hash( 'sha256', $token ), 'owner_hash' => $owner,
				'scope_order_id' => $order ? $order->get_id() : 0,
				'filename' => $saved['filename'], 'bytes' => $saved['bytes'], 'amount' => $amount,
				'currency' => $currency, 'account' => wp_json_encode( $account ),
				'reference' => substr( sanitize_text_field( Support::digits( $reference ) ), 0, 120 ),
				'reason' => '', 'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			) );
			if ( ! $ok ) {
				Storage::delete( $saved['filename'] );
				throw new \RuntimeException( esc_html( __( 'The receipt could not be recorded. Please try again.', 'card-to-card-receipts-for-woocommerce' ) ) );
			}
			return array( 'token' => $token, 'expires' => time() + DAY_IN_SECONDS, 'amount' => Support::amount( $amount, $currency ) );
		} );
	}

	public static function validate( string $token ): object {
		$row = self::by_token( $token );
		if ( ! $row || ! hash_equals( $row->owner_hash, Support::owner() ) || ( 'staged' === $row->state && strtotime( $row->expires_at . ' UTC' ) < time() ) || ! in_array( $row->state, array( 'staged', 'submitted' ), true ) || '' === $row->filename ) {
			throw new \RuntimeException( esc_html( __( 'Please upload a valid receipt. Uploads expire after 24 hours.', 'card-to-card-receipts-for-woocommerce' ) ) );
		}
		if ( ! is_file( Storage::path( $row->filename ) ) ) {
			throw new \RuntimeException( esc_html( __( 'The receipt is unavailable. Please contact the store.', 'card-to-card-receipts-for-woocommerce' ) ) );
		}
		return $row;
	}

	public static function attach( \WC_Order $order, string $token, bool $replacement = false ): void {
		Support::locked( $order->get_id(), function () use ( $order, $token, $replacement ) {
			global $wpdb;
			$order = wc_get_order( $order->get_id() );
			$row = self::validate( $token );
			if ( ( $replacement && (int) $row->scope_order_id !== $order->get_id() ) || ( ! $replacement && (int) $row->scope_order_id !== 0 ) ) {
				throw new \RuntimeException( esc_html( __( 'This receipt was uploaded for a different checkout or order.', 'card-to-card-receipts-for-woocommerce' ) ) );
			}
			if ( (int) $row->order_id !== 0 && (int) $row->order_id !== $order->get_id() ) {
				/* translators: %d: existing WooCommerce order ID. */
				throw new \RuntimeException( esc_html( sprintf( __( 'This receipt already belongs to order #%d. Do not transfer again; contact the store if needed.', 'card-to-card-receipts-for-woocommerce' ), $row->order_id ) ) );
			}
			if ( $order->is_paid() || $order->has_status( array( 'cancelled', 'refunded', 'completed', 'failed' ) ) || 'c2c_receipts' !== $order->get_payment_method() ) {
				throw new \RuntimeException( esc_html( __( 'This order cannot accept a receipt.', 'card-to-card-receipts-for-woocommerce' ) ) );
			}
			$previous = (int) $order->get_meta( '_c2c_receipt_id' );
			if ( $previous === (int) $row->id && $order->has_status( 'on-hold' ) ) {
				return;
			}
			$old = $previous && $previous !== (int) $row->id ? self::get( $previous ) : null;
			if ( $old && ( ! $replacement || 'rejected' !== $old->state ) ) {
				throw new \RuntimeException( esc_html( __( 'A receipt is already awaiting review.', 'card-to-card-receipts-for-woocommerce' ) ) );
			}
			if ( ! $row->order_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned receipt table has no core CRUD API; authorization, review, cleanup and erasure require fresh transactional state.
				$claimed = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET order_id = %d, state = %s WHERE id = %d AND order_id = 0 AND state = %s', self::table(), $order->get_id(), 'submitted', $row->id, 'staged' ) );
				if ( 1 !== $claimed ) {
					throw new \RuntimeException( esc_html( __( 'The receipt was claimed by another request. Please retry.', 'card-to-card-receipts-for-woocommerce' ) ) );
				}
			}
			$order->update_meta_data( '_c2c_receipt_id', (int) $row->id );
			$order->update_meta_data( '_c2c_account', json_decode( $row->account, true ) );
			$order->save();
			if ( $old ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned receipt table has no core CRUD API; authorization, review, cleanup and erasure require fresh transactional state.
				$wpdb->update( self::table(), array( 'state' => 'superseded' ), array( 'id' => $old->id, 'state' => 'rejected' ) );
			}
			$order->update_status( 'on-hold', __( 'Bank transfer receipt received; payment requires manual verification.', 'card-to-card-receipts-for-woocommerce' ) );
			if ( ! self::matches( $row, $order ) ) {
				$order->add_order_note( __( 'The order amount or currency changed after the receipt upload. Reconcile the difference before approval.', 'card-to-card-receipts-for-woocommerce' ) );
			}
			$order->add_order_note( __( 'A payment receipt is available in the private receipt review panel.', 'card-to-card-receipts-for-woocommerce' ) );
			// Additional submissions may not trigger a status-change email.
			if ( $replacement ) {
				self::notify( $order, __( 'A replacement payment receipt was submitted.', 'card-to-card-receipts-for-woocommerce' ) );
			}
		} );
	}

	public static function matches( object $row, \WC_Order $order ): bool {
		return $row->currency === $order->get_currency() && wc_format_decimal( $row->amount, 8 ) === wc_format_decimal( $order->get_total(), 8 );
	}

	public static function review( int $order_id, int $receipt_id, string $decision, string $reason ): void {
		Support::locked( $order_id, function () use ( $order_id, $receipt_id, $decision, $reason ) {
			global $wpdb;
			$order = wc_get_order( $order_id );
			$row = self::get( $receipt_id );
			if ( ! $order || ! $row || (int) $row->order_id !== $order_id || (int) $order->get_meta( '_c2c_receipt_id' ) !== $receipt_id || ! Support::reviewer( $order_id ) || 'c2c_receipts' !== $order->get_payment_method() || ! in_array( $decision, array( 'approved', 'rejected' ), true ) ) {
				throw new \RuntimeException( esc_html( __( 'You cannot review this receipt.', 'card-to-card-receipts-for-woocommerce' ) ) );
			}
			if ( ! $order->has_status( 'on-hold' ) || $order->is_paid() || ! in_array( $row->state, array( 'submitted', 'approving' ), true ) ) {
				throw new \RuntimeException( esc_html( __( 'This receipt or order has already been updated. Reload the order.', 'card-to-card-receipts-for-woocommerce' ) ) );
			}
			if ( 'approving' === $row->state && 'approved' !== $decision ) {
				throw new \RuntimeException( esc_html( __( 'An approval was interrupted. Reconcile the bank deposit and retry approval.', 'card-to-card-receipts-for-woocommerce' ) ) );
			}
			if ( 'approved' === $decision ) {
				if ( ! self::matches( $row, $order ) ) {
					throw new \RuntimeException( esc_html( __( 'The total or currency changed. Restore the submitted total or request a replacement receipt before approval.', 'card-to-card-receipts-for-woocommerce' ) ) );
				}
				if ( '' === $row->filename || ! is_file( Storage::path( $row->filename ) ) ) {
					throw new \RuntimeException( esc_html( __( 'The receipt is unavailable. Please contact the store.', 'card-to-card-receipts-for-woocommerce' ) ) );
				}
				Storage::read( $row->filename );
				// Persist intent first. A crash can be reconciled from order paid state.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned receipt table has no core CRUD API; authorization, review, cleanup and erasure require fresh transactional state.
				if ( false === $wpdb->update( self::table(), array( 'state' => 'approving', 'reviewer' => get_current_user_id(), 'reviewed_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $receipt_id ) ) ) {
					throw new \RuntimeException( esc_html( __( 'The review could not be saved.', 'card-to-card-receipts-for-woocommerce' ) ) );
				}
				$order->payment_complete();
				$order = wc_get_order( $order_id );
				if ( ! $order->is_paid() ) {
					throw new \RuntimeException( esc_html( __( 'WooCommerce did not complete payment. Review the order before retrying.', 'card-to-card-receipts-for-woocommerce' ) ) );
				}
			} elseif ( '' === trim( $reason ) ) {
				throw new \RuntimeException( esc_html( __( 'A rejection reason is required.', 'card-to-card-receipts-for-woocommerce' ) ) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned receipt table has no core CRUD API; authorization, review, cleanup and erasure require fresh transactional state.
			if ( false === $wpdb->update( self::table(), array( 'state' => $decision, 'reviewer' => get_current_user_id(), 'reason' => $reason, 'reviewed_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $receipt_id ) ) ) {
				throw new \RuntimeException( esc_html( __( 'The review could not be saved.', 'card-to-card-receipts-for-woocommerce' ) ) );
			}
			/* translators: 1: review decision, 2: reviewer user ID, 3: review reason. */
			$order->add_order_note( sprintf( __( 'Receipt %1$s by user #%2$d. %3$s', 'card-to-card-receipts-for-woocommerce' ), $decision, get_current_user_id(), $reason ) );
			if ( 'rejected' === $decision ) {
				/* translators: %s: reason the merchant rejected the receipt. */
				$order->add_order_note( sprintf( __( 'Payment receipt rejected: %s. Please submit a replacement using the receipt link in your order email.', 'card-to-card-receipts-for-woocommerce' ), $reason ), true );
				self::notify( $order, __( 'A payment receipt was rejected.', 'card-to-card-receipts-for-woocommerce' ) );
			}
		} );
	}

	private static function notify( \WC_Order $order, string $message ): void {
		/* translators: %s: merchant-facing order number. */
		wp_mail( get_option( 'admin_email' ), sprintf( __( 'Bank transfer receipt: order #%s', 'card-to-card-receipts-for-woocommerce' ), $order->get_order_number() ), $message . "\n" . $order->get_edit_order_url() );
	}

	public static function cleanup(): void {
		global $wpdb;
		$days = max( 1, min( 3650, (int) ( Support::settings()['retention_days'] ?? 90 ) ) );
		$cursor = (int) get_option( 'c2c_receipts_cleanup_cursor', 0 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned receipt table has no core CRUD API; authorization, review, cleanup and erasure require fresh transactional state.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE id > %d AND ((order_id = 0 AND expires_at < %s) OR (order_id > 0 AND created_at < %s AND filename <> %s) OR state = %s) ORDER BY id LIMIT 100', self::table(), $cursor, gmdate( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ), '', 'approving' ) );
		foreach ( $rows as $row ) {
			try {
				Support::locked( $row->order_id ? (int) $row->order_id : -1, function () use ( $wpdb, $row ) {
					$row = self::get( (int) $row->id );
					if ( ! $row ) {
						return;
					}
					$order = $row->order_id ? wc_get_order( $row->order_id ) : null;
					if ( 'approving' === $row->state && $order && $order->is_paid() ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned receipt table has no core CRUD API; authorization, review, cleanup and erasure require fresh transactional state.
						$wpdb->update( self::table(), array( 'state' => 'approved' ), array( 'id' => $row->id ) );
						return;
					}
					if ( $row->order_id && $order && ! $order->has_status( array( 'completed', 'cancelled', 'refunded', 'failed' ) ) ) {
						return;
					}
					if ( $row->order_id && ! Support::allow_erasure( $row, $order ) ) {
						return;
					}
					if ( Storage::delete( $row->filename ) ) {
						if ( ! $row->order_id ) {
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned receipt table has no core CRUD API; authorization, review, cleanup and erasure require fresh transactional state.
							$wpdb->delete( self::table(), array( 'id' => $row->id ) );
						} else {
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned receipt table has no core CRUD API; authorization, review, cleanup and erasure require fresh transactional state.
							$wpdb->update( self::table(), array( 'filename' => '', 'bytes' => 0 ), array( 'id' => $row->id ) );
						}
					}
				} );
			} catch ( \Throwable $e ) {
				// Fail closed and retry next run; never fall back to a public path.
				continue;
			}
		}
		update_option( 'c2c_receipts_cleanup_cursor', count( $rows ) === 100 ? (int) end( $rows )->id : 0, false );
	}
}
