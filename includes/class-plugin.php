<?php
namespace C2CReceipts;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public static function activate(): void {
		Receipts::install();
		if ( ! wp_next_scheduled( 'c2c_receipts_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'c2c_receipts_cleanup' );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'c2c_receipts_cleanup' );
	}

	public static function boot(): void {
		add_action( 'init', array( self::class, 'translations' ), 0 );
		if ( ! class_exists( 'WooCommerce' ) || ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, '11.0', '<' ) ) {
			add_action( 'admin_notices', static function () {
				$screen = get_current_screen();
				if ( current_user_can( 'activate_plugins' ) && $screen && in_array( $screen->id, array( 'plugins', 'woocommerce_page_wc-settings' ), true ) ) {
					echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Card to Card Receipts requires WooCommerce 11.0 or later.', 'c2c-receipts' ) . '</p></div>';
				}
			} );
			return;
		}
		if ( '1' !== get_option( 'c2c_receipts_db_version' ) ) {
			self::activate();
		}
		foreach ( array( 'gateway', 'http', 'customer', 'admin', 'privacy' ) as $file ) {
			require_once __DIR__ . '/class-' . $file . '.php';
		}
		add_filter( 'woocommerce_payment_gateways', static function ( $gateways ) {
			$gateways[] = Gateway::class;
			return $gateways;
		} );
		Http::register();
		Customer::register();
		Admin::register();
		Privacy::register();
		add_action( 'c2c_receipts_cleanup', array( Receipts::class, 'cleanup' ) );
		add_action( 'wp_enqueue_scripts', static function () {
			if ( is_checkout() || is_account_page() || '' !== Support::get( 'c2c_access' ) ) {
				self::enqueue_assets();
			}
		} );
	}

	/** Directory language packs load automatically; load the bundled catalogue only when no pack exists. */
	public static function translations(): void {
		$locale = determine_locale();
		if ( ! preg_match( '/^[A-Za-z0-9_@.-]+$/D', $locale ) || str_contains( $locale, '..' ) || is_textdomain_loaded( 'c2c-receipts' ) ) {
			return;
		}
		$pack = WP_LANG_DIR . '/plugins/c2c-receipts-' . $locale . '.mo';
		if ( is_readable( $pack ) || is_readable( WP_LANG_DIR . '/plugins/c2c-receipts-' . $locale . '.l10n.php' ) ) {
			return;
		}
		$bundled = C2C_RECEIPTS_DIR . '/languages/c2c-receipts-' . $locale . '.mo';
		if ( is_readable( $bundled ) ) {
			load_textdomain( 'c2c-receipts', $bundled, $locale );
		}
	}

	public static function register_assets(): void {
		if ( wp_script_is( 'c2c-common', 'registered' ) ) {
			return;
		}
		wp_register_script( 'c2c-common', plugins_url( 'assets/common.js', C2C_RECEIPTS_FILE ), array(), C2C_RECEIPTS_VERSION, true );
		wp_register_script( 'c2c-classic', plugins_url( 'assets/classic.js', C2C_RECEIPTS_FILE ), array( 'jquery', 'c2c-common' ), C2C_RECEIPTS_VERSION, true );
		wp_localize_script( 'c2c-common', 'c2cReceiptsConfig', array(
			'bootUrl' => \WC_AJAX::get_endpoint( 'c2c_boot' ),
			'uploadUrl' => \WC_AJAX::get_endpoint( 'c2c_upload' ),
			'replaceUrl' => \WC_AJAX::get_endpoint( 'c2c_replace' ),
			'statusUrl' => \WC_AJAX::get_endpoint( 'c2c_status' ),
			'maxBytes' => min( wp_max_upload_size(), max( 1, min( 10, (int) ( Support::settings()['max_mb'] ?? 5 ) ) ) * MB_IN_BYTES ),
			'labels' => array(
				'account' => __( 'Destination account', 'c2c-receipts' ), 'copy' => __( 'Copy card number', 'c2c-receipts' ),
				'copied' => __( 'Copied', 'c2c-receipts' ), 'reference' => __( 'Tracking number (optional)', 'c2c-receipts' ),
				'receipt' => __( 'Receipt image', 'c2c-receipts' ), 'uploading' => __( 'Uploading receipt…', 'c2c-receipts' ),
				'ready' => __( 'Receipt uploaded. Submit your order to send it for review.', 'c2c-receipts' ),
				'required' => __( 'Upload a receipt before submitting your order.', 'c2c-receipts' ),
				'error' => __( 'The request failed. Your transfer may already have succeeded; do not transfer again. Retry or contact the store.', 'c2c-receipts' ),
				'fileError' => __( 'Choose a JPEG, PNG or WebP image within the displayed size limit.', 'c2c-receipts' ),
				'submitted' => __( 'Receipt received; payment awaiting verification.', 'c2c-receipts' ),
				'changed' => __( 'The order total changed. Do not transfer again; the store must reconcile the difference.', 'c2c-receipts' ),
				/* translators: %s: maximum allowed upload size in megabytes. */
				'help' => __( 'JPEG, PNG or WebP. Maximum %s MB; 12 megapixels. Uploads expire after 24 hours.', 'c2c-receipts' ),
			),
		) );
	}

	public static function enqueue_assets(): void {
		self::register_assets();
		wp_enqueue_script( 'c2c-classic' );
		wp_enqueue_style( 'c2c-receipts', plugins_url( 'assets/checkout.css', C2C_RECEIPTS_FILE ), array(), C2C_RECEIPTS_VERSION );
	}
}
