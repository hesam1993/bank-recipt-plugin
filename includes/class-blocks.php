<?php
namespace C2CReceipts;

defined( 'ABSPATH' ) || exit;

final class Blocks extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
	protected $name = 'c2c_receipts';

	public function initialize() {
		$this->settings = Support::settings();
	}

	public function is_active() {
		$gateways = WC()->payment_gateways()->payment_gateways();
		return isset( $gateways[ $this->name ] ) && $gateways[ $this->name ]->is_available();
	}

	public function get_payment_method_script_handles() {
		Plugin::register_assets();
		wp_register_script( 'c2c-blocks', plugins_url( 'assets/blocks.js', C2CRECEIPTS_FILE ), array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'c2c-common' ), C2CRECEIPTS_VERSION, true );
		return array( 'c2c-blocks' );
	}

	public function get_payment_method_data() {
		return array(
			'title' => $this->settings['title'] ?? __( 'Card to card', 'card-to-card-receipts-for-woocommerce' ),
			'description' => $this->settings['description'] ?? __( 'Transfer the exact total to a listed account, then upload your receipt. Your order will await verification.', 'card-to-card-receipts-for-woocommerce' ),
			'accounts' => array_values( Support::accounts() ),
			'supports' => array( 'products' ),
		);
	}
}
