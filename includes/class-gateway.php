<?php
namespace C2CReceipts;

defined( 'ABSPATH' ) || exit;

final class Gateway extends \WC_Payment_Gateway {
	private static ?array $accounts_draft = null;

	public function __construct() {
		$this->id = 'c2c_receipts';
		$this->has_fields = true;
		$this->method_title = __( 'Card to card', 'card-to-card-receipts-for-woocommerce' );
		$this->method_description = __( 'Receive bank transfers with private receipt uploads and manual verification.', 'card-to-card-receipts-for-woocommerce' );
		$this->supports = array( 'products' );
		$this->init_form_fields();
		$this->init_settings();
		$this->title = $this->get_option( 'title', __( 'Card to card', 'card-to-card-receipts-for-woocommerce' ) );
		$this->description = $this->get_option( 'description', __( 'Transfer the exact total to a listed account, then upload your receipt. Your order will await verification.', 'card-to-card-receipts-for-woocommerce' ) );
		$this->enabled = $this->get_option( 'enabled', 'no' );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled' => array( 'title' => __( 'Enable', 'card-to-card-receipts-for-woocommerce' ), 'type' => 'checkbox', 'label' => __( 'Enable card to card payments', 'card-to-card-receipts-for-woocommerce' ), 'default' => 'no' ),
			'title' => array( 'title' => __( 'Title', 'card-to-card-receipts-for-woocommerce' ), 'type' => 'text', 'default' => __( 'Card to card', 'card-to-card-receipts-for-woocommerce' ) ),
			'description' => array( 'title' => __( 'Instructions', 'card-to-card-receipts-for-woocommerce' ), 'type' => 'textarea', 'default' => __( 'Transfer the exact total to a listed account, then upload your receipt. Your order will await verification.', 'card-to-card-receipts-for-woocommerce' ) ),
			'accounts' => array( 'title' => __( 'Destination accounts', 'card-to-card-receipts-for-woocommerce' ), 'type' => 'accounts', 'default' => '' ),
			'max_mb' => array( 'title' => __( 'Maximum upload size (MB)', 'card-to-card-receipts-for-woocommerce' ), 'type' => 'number', 'default' => 5, 'custom_attributes' => array( 'min' => 1, 'max' => 10 ) ),
			'retention_days' => array( 'title' => __( 'Receipt retention (days)', 'card-to-card-receipts-for-woocommerce' ), 'type' => 'number', 'default' => 90, 'description' => __( 'Files are removed after this age only for closed or deleted orders. Active reviews are retained. Unattached uploads expire after 24 hours.', 'card-to-card-receipts-for-woocommerce' ), 'custom_attributes' => array( 'min' => 1, 'max' => 3650 ) ),
			'delete_on_uninstall' => array( 'title' => __( 'Uninstall', 'card-to-card-receipts-for-woocommerce' ), 'type' => 'checkbox', 'label' => __( 'Delete receipt files and plugin records when uninstalling. WooCommerce orders are preserved.', 'card-to-card-receipts-for-woocommerce' ), 'default' => 'no' ),
		);
	}

	public function generate_accounts_html( $key, $field ): string {
		$rows = self::$accounts_draft;
		if ( null === $rows ) {
			try {
				$rows = array_values( Support::accounts( (string) $this->get_option( $key, '' ) ) );
			} catch ( \InvalidArgumentException $e ) {
				$rows = array();
			}
		}
		if ( array() === $rows ) {
			$rows = array( array() );
		}
		$name = $this->get_field_key( $key );
		ob_start();
		echo '<tr valign="top"><th scope="row">' . esc_html( $field['title'] ) . '</th><td><div class="c2c-accounts" data-next-index="' . esc_attr( count( $rows ) ) . '">';
		// The empty fallback lets removing every row clear the accounts setting.
		echo '<input class="c2c-accounts-empty" type="hidden" name="' . esc_attr( $name ) . '" value="">';
		echo '<div class="c2c-account-rows">';
		foreach ( array_values( $rows ) as $index => $account ) {
			$this->account_row( $account, (string) $index, $name );
		}
		echo '</div><template class="c2c-account-template">';
		$this->account_row( array(), '__index__', $name );
		echo '</template><button type="button" class="button c2c-account-add" hidden>' . esc_html__( 'Add bank account', 'card-to-card-receipts-for-woocommerce' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'Enter each account in its own fields. Persian digits are accepted. Bank name is optional; maximum 20 accounts.', 'card-to-card-receipts-for-woocommerce' ) . '</p>';
		echo '</div></td></tr>';
		return ob_get_clean();
	}

	private function account_row( array $account, string $index, string $name ): void {
		echo '<fieldset class="c2c-account-row"><legend>' . esc_html__( 'Bank account', 'card-to-card-receipts-for-woocommerce' ) . '</legend>';
		echo '<input type="hidden" name="' . esc_attr( $name . '[' . $index . '][id]' ) . '" value="' . esc_attr( $account['id'] ?? '' ) . '">';
		foreach ( array( 'card' => __( 'Card number', 'card-to-card-receipts-for-woocommerce' ), 'bank' => __( 'Bank name', 'card-to-card-receipts-for-woocommerce' ), 'holder' => __( 'Account holder name', 'card-to-card-receipts-for-woocommerce' ) ) as $field => $label ) {
			echo '<label>' . esc_html( $label ) . '<input type="text" name="' . esc_attr( $name . '[' . $index . '][' . $field . ']' ) . '" value="' . esc_attr( $account[ $field ] ?? '' ) . '" maxlength="' . esc_attr( 'card' === $field ? 32 : 120 ) . '" autocomplete="off"' . ( 'card' === $field ? ' dir="ltr" inputmode="numeric"' : '' ) . '></label>';
		}
		echo '<button type="button" class="button-link-delete c2c-account-remove" hidden>' . esc_html__( 'Remove account', 'card-to-card-receipts-for-woocommerce' ) . '</button></fieldset>';
	}

	public function validate_accounts_field( $key, $value ): string {
		// Keep compatibility with existing settings integrations that post the old text format.
		if ( is_string( $value ) || null === $value ) {
			$raw = sanitize_textarea_field( wp_unslash( $value ?? '' ) );
			Support::accounts( $raw );
			return $raw;
		}
		if ( ! is_array( $value ) || count( $value ) > 20 ) {
			throw new \InvalidArgumentException( esc_html( __( 'Use at most 20 bank accounts.', 'card-to-card-receipts-for-woocommerce' ) ) );
		}
		$rows = array();
		foreach ( wp_unslash( $value ) as $row ) {
			if ( ! is_array( $row ) ) {
				throw new \InvalidArgumentException( esc_html( __( 'Invalid bank account fields.', 'card-to-card-receipts-for-woocommerce' ) ) );
			}
			$clean = array();
			foreach ( array( 'card', 'bank', 'holder', 'id' ) as $field ) {
				if ( isset( $row[ $field ] ) && ! is_string( $row[ $field ] ) ) {
					throw new \InvalidArgumentException( esc_html( __( 'Invalid bank account fields.', 'card-to-card-receipts-for-woocommerce' ) ) );
				}
				$clean[ $field ] = trim( sanitize_text_field( $row[ $field ] ?? '' ) );
			}
			if ( '' !== $clean['card'] || '' !== $clean['bank'] || '' !== $clean['holder'] ) {
				$rows[] = $clean;
			}
		}
		self::$accounts_draft = $rows;
		try {
			$existing = Support::accounts();
		} catch ( \InvalidArgumentException $e ) {
			$existing = array();
		}
		$lines = array();
		foreach ( $rows as $index => $row ) {
			if ( str_contains( $row['bank'] . $row['holder'], '|' ) ) {
				throw new \InvalidArgumentException( esc_html( __( 'Bank and holder names cannot contain the | character.', 'card-to-card-receipts-for-woocommerce' ) ) );
			}
			// Preserve legacy optional account/Sheba information without adding unwanted UI fields.
			$previous = $existing[ $row['id'] ] ?? array();
			$line = implode( ' | ', array( $row['bank'], $row['holder'], $row['card'], $previous['number'] ?? '', $previous['iban'] ?? '' ) );
			try {
				Support::accounts( $line );
			} catch ( \InvalidArgumentException $e ) {
				/* translators: %d: one-based bank account row number. */
				throw new \InvalidArgumentException( esc_html( sprintf( __( 'Account %d needs a holder name and a 16-digit card number.', 'card-to-card-receipts-for-woocommerce' ), $index + 1 ) ) );
			}
			$lines[] = $line;
		}
		self::$accounts_draft = null;
		return implode( "\n", $lines );
	}

	public function process_admin_options(): bool {
		self::$accounts_draft = null;
		$this->errors = array();
		$saved = parent::process_admin_options();
		// WC_Settings_API collects exceptions, but its gateway page does not display that array.
		foreach ( $this->get_errors() as $error ) {
			\WC_Admin_Settings::add_error( $error );
		}
		return (bool) $saved;
	}

	public function needs_setup(): bool {
		try {
			return ! Storage::ready() || array() === Support::accounts();
		} catch ( \InvalidArgumentException $e ) {
			return true;
		}
	}

	public function validate_max_mb_field( $key, $value ): int {
		return max( 1, min( 10, absint( $value ) ) );
	}

	public function validate_retention_days_field( $key, $value ): int {
		return max( 1, min( 3650, absint( $value ) ) );
	}

	public function is_available(): bool {
		try {
			return parent::is_available() && Storage::ready() && count( Support::accounts() ) > 0 && ! is_wc_endpoint_url( 'order-pay' );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public function admin_options(): void {
		parent::admin_options();
		$problems = Storage::problems();
		try {
			$accounts = Support::accounts();
			if ( array() === $accounts ) {
				$problems[] = __( 'No valid destination accounts are saved. Complete the card number and holder name fields, then save changes.', 'card-to-card-receipts-for-woocommerce' );
			} else {
				echo '<h3>' . esc_html__( 'Saved destination accounts', 'card-to-card-receipts-for-woocommerce' ) . '</h3><ul>';
				foreach ( $accounts as $account ) {
					echo '<li><bdi dir="ltr">' . esc_html( $account['card'] ) . '</bdi> — ' . esc_html( Support::account_label( $account ) ) . '</li>';
				}
				echo '</ul>';
			}
		} catch ( \InvalidArgumentException $e ) {
			$problems[] = $e->getMessage();
		}
		if ( $problems ) {
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'Card to card is hidden at checkout until these setup issues are resolved:', 'card-to-card-receipts-for-woocommerce' ) . '</strong></p><ul>';
			foreach ( $problems as $problem ) {
				echo '<li>' . esc_html( $problem ) . '</li>';
			}
			echo '</ul></div>';
		}
	}

	public function payment_fields(): void {
		echo '<p>' . esc_html( $this->description ) . '</p>';
		Customer::form();
	}

	public function validate_fields(): bool {
		try {
			Receipts::validate( Support::post( 'c2c_token' ) );
			return true;
		} catch ( \Throwable $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
			return false;
		}
	}

	public function process_payment( $order_id ): array {
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				throw new \RuntimeException( esc_html( __( 'Order not found.', 'card-to-card-receipts-for-woocommerce' ) ) );
			}
			Receipts::attach( $order, Support::post( 'c2c_token' ) );
			if ( WC()->cart ) {
				WC()->cart->empty_cart();
			}
			return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
		} catch ( \Throwable $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
			return array( 'result' => 'failure' );
		}
	}
}
