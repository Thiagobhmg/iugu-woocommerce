<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * iugu Payment Bank Slip Gateway class.
 *
 * Extended by individual payment gateways to handle payments.
 *
 * @class   WC_Iugu_Bank_Slip_Gateway
 * @extends WC_Payment_Gateway
 * @version 1.0.0
 * @author  iugu
 */
class WC_Iugu_Bank_Slip_Gateway extends WC_Payment_Gateway {

	/**
	 * Constructor for the gateway.
	 */
	public function __construct() {
		global $woocommerce;

		$this->id                   = 'iugu-bank-slip';
		$this->icon                 = apply_filters( 'iugu_woocommerce_bank_slip_icon', '' );
		$this->method_title         = __( 'iugu - Bank slip', 'iugu-woocommerce' );
		$this->method_description   = __( 'Accept bank slip payments using iugu.', 'iugu-woocommerce' );
		$this->has_fields           = true;
		$this->view_transaction_url = 'https://iugu.com/a/invoices/%s';
		$this->supports             = array(
			'subscriptions',
			'products',
			'subscription_cancellation',
			'subscription_reactivation',
			'subscription_suspension',
			'subscription_amount_changes',
			'subscription_payment_method_change', // Subscriptions 1.n compatibility.
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change_admin',
			'subscription_date_changes',
			'refunds',
			'pre-orders'
		);

		// Load the form fields.
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		// Options.
		$this->title           = $this->get_option( 'title' );
		$this->description     = $this->get_option( 'description' );
		$this->account_id      = $this->get_option( 'account_id' );
		$this->api_token       = $this->get_option( 'api_token' );
		$this->deadline        = $this->get_option( 'deadline' );
		$this->send_only_total = $this->get_option( 'send_only_total', 'no' );
		$this->sandbox         = $this->get_option( 'sandbox', 'no' );
		$this->debug           = $this->get_option( 'debug' );

		// Active logs.
		if ( 'yes' == $this->debug ) {
			if ( class_exists( 'WC_Logger' ) ) {
				$this->log = new WC_Logger();
			} else {
				$this->log = $woocommerce->logger();
			}
		}

		$this->api = new WC_Iugu_API( $this, 'bank-slip' );

		// Actions.
		add_action( 'woocommerce_api_wc_iugu_bank_slip_gateway', array( $this, 'notification_handler' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'email_instructions' ), 10, 3 );
	}

	/**
	 * Returns a value indicating the the Gateway is available or not. It's called
	 * automatically by WooCommerce before allowing customers to use the gateway
	 * for payment.
	 *
	 * @return bool
	 */
	public function is_available() {
		// Test if is valid for use.
		$api = ! empty( $this->account_id ) && ! empty( $this->api_token );

		$available = 'yes' == $this->get_option( 'enabled' ) && $api && $this->api->using_supported_currency();

		return $available;
	}

	/**
	 * Initialise Gateway Settings Form Fields.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'Enable/Disable', 'iugu-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable bank slip payments with iugu', 'iugu-woocommerce' ),
				'default' => 'no'
			),
			'title' => array(
				'title'       => __( 'Title', 'iugu-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Payment method title seen on the checkout page.', 'iugu-woocommerce' ),
				'default'     => __( 'Bank slip', 'iugu-woocommerce' )
			),
			'description' => array(
				'title'       => __( 'Description', 'iugu-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description seen on the checkout page.', 'iugu-woocommerce' ),
				'default'     => __( 'Pay with bank slip', 'iugu-woocommerce' )
			),
			'integration' => array(
				'title'       => __( 'Integration settings', 'iugu-woocommerce' ),
				'type'        => 'title',
				'description' => ''
			),
			'account_id' => array(
				'title'             => __( 'Account ID', 'iugu-woocommerce' ),
				'type'              => 'text',
				'description'       => sprintf( __( 'Your iugu account\'s unique ID, found in %s.', 'iugu-woocommerce' ), '<a href="https://app.iugu.com/account" target="_blank">' . __( 'iugu account settings', 'iugu-woocommerce' ) . '</a>' ),
				'default'           => '',
				'custom_attributes' => array(
					'required' => 'required'
				)
			),
			'api_token' => array(
				'title'            => __( 'API Token', 'iugu-woocommerce' ),
				'type'              => 'text',
				'description'       => sprintf( __( 'For real payments, use a LIVE API token. When iugu sandbox is enabled, use a TEST API token. API tokens can be found/created in %s.', 'iugu-woocommerce' ), '<a href="https://app.iugu.com/account" target="_blank">' . __( 'iugu account settings', 'iugu-woocommerce' ) . '</a>' ),
				'default'           => '',
				'custom_attributes' => array(
					'required' => 'required'
				)
			),
			'ignore_due_email' => array(
				'title'            => __( 'Ignore due email', 'iugu-woocommerce' ),
				'type'              => 'checkbox',
				'label'       => __( 'When checked, Iugu will not send emails billing emails to the payer', 'iugu-woocommerce' ),
				'default'           => 'no'
			),
			'payment' => array(
				'title'       => __( 'Payment options', 'iugu-woocommerce' ),
				'type'        => 'title',
				'description' => ''
			),
			'deadline' => array(
				'title'             => __( 'Default payment deadline', 'iugu-woocommerce' ),
				'type'              => 'number',
				'description'       => __( 'Number of days the customer will have to pay the bank slip.', 'iugu-woocommerce' ),
				'default'           => '5',
				'custom_attributes' => array(
					'step' => '1',
					'min'  => '1'
				)
			),
			'behavior' => array(
				'title'       => __( 'Integration behavior', 'iugu-woocommerce' ),
				'type'        => 'title',
				'description' => ''
			),
			'send_only_total' => array(
				'title'   => __( 'Send only the order total', 'iugu-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'When enabled, the customer only gets the order total, not the list of purchased items.', 'iugu-woocommerce' ),
				'default' => 'no'
			),
			'testing' => array(
				'title'       => __( 'Gateway testing', 'iugu-woocommerce' ),
				'type'        => 'title',
				'description' => ''
			),
			'sandbox' => array(
				'title'       => __( 'iugu sandbox', 'iugu-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable iugu sandbox', 'iugu-woocommerce' ),
				'default'     => 'no',
				'description' => sprintf( __( 'Used to test payments. Don\'t forget to use a TEST API token, which can be found/created in %s.', 'iugu-woocommerce' ), '<a href="https://iugu.com/settings/account" target="_blank">' . __( 'iugu account settings', 'iugu-woocommerce' ) . '</a>' )
			),
			'debug' => array(
				'title'       => __( 'Debugging', 'iugu-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable logging', 'iugu-woocommerce' ),
				'default'     => 'no',
				'description' => sprintf( __( 'Log iugu events, such as API requests, for debugging purposes. The log can be found in %s.', 'iugu-woocommerce' ), WC_Iugu::get_log_view( $this->id ) )
			)
		);
	}

	/**
	 * Payment fields.
	 */
	public function payment_fields() {
		if ( $description = $this->get_description() ) {
			echo wpautop( wptexturize( $description ) );
		}

		wc_get_template(
			'bank-slip/checkout-instructions.php',
			array(),
			'woocommerce/iugu/',
			WC_Iugu::get_templates_path()
		);
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param  int $order_id Order ID.
	 *
	 * @return array         Redirect.
	 */
	public function process_payment( $order_id ) {
		return $this->api->process_payment( $order_id );
	}

	/**
	 * Thank You page message.
	 *
	 * @param  int    $order_id Order ID.
	 *
	 * @return string
	 */
	public function thankyou_page( $order_id ) {
		$data = get_post_meta( $order_id, '_iugu_wc_transaction_data', true );

		if ( isset( $data['pdf'] ) ) {
			wc_get_template(
				'bank-slip/payment-instructions.php',
				array(
					'pdf' => $data['pdf']
				),
				'woocommerce/iugu/',
				WC_Iugu::get_templates_path()
			);
		}
	}

	/**
	 * Add content to the WC emails.
	 *
	 * @param  object $order         Order object.
	 * @param  bool   $sent_to_admin Send to admin.
	 * @param  bool   $plain_text    Plain text or HTML.
	 *
	 * @return string                Payment instructions.
	 */
	public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		if ( $sent_to_admin || ! in_array( $order->get_status(), array( 'processing', 'on-hold' ) ) || $this->id !== $order->get_payment_method() ) {
			return;
		}

		$data = get_post_meta( $order->get_id(), '_iugu_wc_transaction_data', true );

		if ( isset( $data['pdf'] ) ) {
			if ( $plain_text ) {
				wc_get_template(
					'bank-slip/emails/plain-instructions.php',
					array(
						'pdf' => $data['pdf']
					),
					'woocommerce/iugu/',
					WC_Iugu::get_templates_path()
				);
			} else {
				wc_get_template(
					'bank-slip/emails/html-instructions.php',
					array(
						'pdf' => $data['pdf']
					),
					'woocommerce/iugu/',
					WC_Iugu::get_templates_path()
				);
			}
		}
	}

	/**
	 * Notification handler.
	 */
	public function notification_handler() {
		$this->api->notification_handler();
	}
}
