<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

// TODO: add localization

/*
Plugin Name: WooCommerce LibertyBank installments
Author: Serhii Fesiura
URI: https://github.com/peinguin/WooCommerce_LibertyBank_installments
*/

class WC_Gateway_LibertyBank_Initer {
	const PERMALINK = 'prelibrebankinstallment';

	public function __construct() {
		add_action( 'plugins_loaded', 'init_libertybank_gateway_class' );
		add_action( 'init', array( $this, 'add_page') );
		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_libertybank_gateway_class') );
		add_shortcode( 'libertybank_precheckout', array($this, 'showForm') );
	}

	public function add_page() {
		if (!get_page_by_path(self::PERMALINK)) {
				wp_insert_post(array(
					'post_type' => 'page',
					'post_title' => 'Libertybank preredirect page',
					'post_content' => '[libertybank_precheckout]',
					'post_name' => self::PERMALINK,
					'post_status' => 'publish'
			));
		}
	}

	public function add_libertybank_gateway_class( $methods ) {
		$methods[] = 'WC_Gateway_LibertyBank_Installments'; 
		return $methods;
	}

	public function showForm() {
		global $wp;
		$order_id = $_GET['order_id'];
		if (!$order_id) {
			$order_id = $wp->query_vars['page'];
		}
		if (!$order_id) {
			return '';
		}
		$order = wc_get_order($order_id);
		if (!$order) {
			return '';
		}
		$callid = urldecode($order->order_key);

		$settings_key = 'woocommerce_libertybankinstallments_settings'; // TODO: remove hardcode
		$settings = get_option($settings_key);

		$secretkey = $settings['secretkey'];
		$merchant  = $settings['merchant'];
		$testmode  = $settings['testmode'] === 'yes' ? 1 : 0;
		$ordercode = $order->id;
		$callid = $order->order_key;
		$shipping_address = $order->get_formatted_shipping_address();
		$installmenttype = 0;
		$products = $order->get_items();

		$str = $secretkey
			. $merchant
			. $ordercode
			. $callid
			. $shipping_address
			. $testmode;

		foreach ($products as $key => $product) {
			$str .= $key.$product['name'].$product['qty'].$product['line_total'].$product['type'].$installmenttype; 
		}         
		$check = strtoupper(hash('sha256', $str));
		return $this->getHTMLTemplate($products, $merchant, $testmode, $ordercode, $callid, $shipping_address, $installmenttype, $check);
	}

	public function getHTMLTemplate($products, $merchant, $testmode, $ordercode, $callid, $shipping_address, $installmenttype, $check) {
		ob_start(); ?>

		<h1>Go to bank page.</h1>
		<p>Please, confirm payment.</p>
		<form method="post" action="http://onlineinstallment.lb.ge/installment">
			<input type="hidden" name="ordercode" value="<?php echo htmlentities($ordercode); ?>" />
			<input type="hidden"   name="callid" value="<?php echo htmlentities($callid); ?>" />
			<input type="hidden" name="shipping_address" value="<?php echo htmlentities($shipping_address, ENT_QUOTES, 'UTF-8'); ?>" />
			<input type="hidden" name="merchant" value="<?php echo $merchant; ?>" />
			<input type="hidden" name="testmode" value="<?php echo $testmode; ?>" />
			<input type="hidden" name="check" value="<?php echo $check; ?>" />
			<?php $i = 0; foreach ($products as $key => $product) { ?>
				<input
					type="hidden"
					name="products[<?php echo $i; ?>][id]"
					value="<?php echo $key; ?>"
					/>
				<input
					type="hidden"
					name="products[<?php echo $i; ?>][title]"
					value="<?php echo htmlentities($product['name'], ENT_QUOTES, 'UTF-8'); ?>"
					/>
				<input
					type="hidden"
					name="products[<?php echo $i; ?>][amount]"
					value="<?php echo $product['qty']; ?>"
					/>
				<input
					type="hidden"
					name="products[<?php echo $i; ?>][price]"
					value="<?php echo $product['line_total']; ?>"
					/>
				<input
					type="hidden"
					name="products[<?php echo $i; ?>][type]"
					value="<?php echo $product['type']; ?>"
					/>
				<input
					type="hidden"
					name="products[<?php echo $i; ?>][installmenttype]"
					value="<?php echo $installmenttype; ?>"
					/>
			<?php $i++; } ?>
			<input type="submit" value="Go to bank page" />
		</form> 

		<?php
		return ob_get_clean();
	}
}

function init_libertybank_gateway_class() {
	class WC_Gateway_LibertyBank_Installments extends WC_Payment_Gateway {
		public function __construct() {
			$this->id = 'libertybankinstallments';
			$this->icon = null; // TODO: add icon
			$this->has_fields = false;
			$this->method_title = 'Liberty Bank installments';
			$this->method_description = 'Georgian Liberty Bank installment payment';

			// Load the settings.
			$this->init_form_fields();
			$this->init_settings();

			// Define user set variables
			$this->title        = $this->get_option( 'title' );
			$this->description  = $this->get_option( 'description' );

			$this->merchant  = $this->get_option( 'merchant' );
			$this->secretkey = $this->get_option( 'secretkey' );
			$this->testmode  = $this->get_option( 'testmode' );

			// Actions
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action( 'woocommerce_api_wc_gateway_libertybankinstallments', array( $this, 'check_response' ) );
		}

		/**
		 * Initialise Gateway Settings Form Fields.
		 */
		public function init_form_fields() {
			$this->form_fields = array(
				'enabled' => array(
					'title'   => __( 'Enable/Disable', 'woocommerce' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable Installments', 'woocommerce' ),
					'default' => 'no'
				),
				'title' => array(
					'title'       => __( 'Title', 'woocommerce' ),
					'type'        => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
					'default'     => __( 'Georgian Liberty Bank installment payment', 'woocommerce' ),
					'desc_tip'    => true,
				),
				'description' => array(
					'title'       => __( 'Description', 'woocommerce' ),
					'type'        => 'textarea',
					'description' => __( 'Payment method description that the customer will see on your checkout.', 'woocommerce' ),
					'default'     => __( 'Make your installment payment directly into our bank account.', 'woocommerce' ),
					'desc_tip'    => true,
				),
				'merchant' => array(
					'title'       => __( 'Merchant', 'woocommerce' ),
					'type'        => 'text',
					'description' => __( 'Merchant ID.', 'woocommerce' ),
					'default'     => '',
					'desc_tip'    => true,
				),

				'secretkey' => array(
					'title'       => __( 'Secretkey', 'woocommerce' ),
					'type'        => 'text',
					'description' => __( 'Secret key.', 'woocommerce' ),
					'default'     => '',
					'desc_tip'    => true,
				),
				'testmode' => array(
					'title'       => __( 'Test mode', 'woocommerce' ),
					'type'        => 'checkbox',
					'description' => __( 'Test mode.', 'woocommerce' ),
					'default'     => 'yes',
					'desc_tip'    => true,
				),
			);
		}

		/**
		 * Process the payment and return the result.
		 *
		 * @param int $order_id
		 * @return array
		 */
		public function process_payment( $order_id ) {
			$permalink = get_permalink( get_page_by_path( WC_Gateway_LibertyBank_Initer::PERMALINK ) );
			if ( '' != get_option('permalink_structure') ) {
				// using pretty permalinks, append to url
				$url = user_trailingslashit( $permalink . '/' . $order_id ); // www.example.com/pagename/test
			} else {
				$url = add_query_arg( 'order_id', $order_id, $permalink ); // www.example.com/pagename/?test
			}
			return array(
				'result'   => 'success',
				'redirect' => $url
			);
		}

		/**
		 * Process the bank response.
		 *
		 * @param int $order_id
		 * @return array
		 */
		public function check_response() {
			/*
			 *  0 - Transaction has success status
			 *  1 - duplicated transaction
			 * -1 - technical error
			 * -2 - order not found
			 * -3 - parameters error
			 */
			$resultcode = 0;
			/*
			 * Transaction has been completed successfully: 0
			 * Duplicated transaction :1
			 * Technical error : -1
			 * User not found: -2
			 * Parameters Error : -3
			*/
			$resultdesc = 0;
			/**
			 * WTF ?
			 */
			$transactioncode = '';

			$status        = urldecode($_GET["status"]);
			$installmentid = urldecode($_GET['installmentid']);
			$order_id      = urldecode($_GET['ordercode']);
			$order_key     = urldecode($_GET['callid']);
			$check         = urldecode($_GET['check']);

			$str = $status.$installmentid.$order_id.$order_key.$this->secretkey;
			$calculatedCheck = hash('sha256',$str);

			if (strcasecmp($check,$calculatedCheck) === 0) {
				if ( ! $order = wc_get_order( $order_id ) ) {
					// We have an invalid $order_id, probably because invoice_prefix has changed.
					$order_id = wc_get_order_id_by_order_key( $order_key );
					$order    = wc_get_order( $order_id );
				}

				if ( ! $order || $order->order_key !== $order_key ) {
					WC_Gateway_Paypal::log( 'Error: Order Keys do not match.' );
					$resultcode = -2;
					$resultdesc = -3;
				}
				else {
					if ('DISCARDED' === $status) {
						$order->update_status( 'failed', 'Status DISCARDED' );
					}
					else if('REVIEW' === $status) {
						$order->update_status( 'on-hold', 'Status REVIEW' );
					}
					else if('APPROVED' === $status) {
						$order->payment_complete( $installmentid );
					}
					else {
						WC_Gateway_Paypal::log( 'Error: Strange status: ' . $status );
						$resultcode = -3;
						$resultdesc = -3;
					}
				}
			} else {
				WC_Gateway_Paypal::log( 'Error: Security error.' );
				$resultcode = -3;
				$resultdesc = -3;
			}
			
			$check = hash('sha256', $resultcode.$resultdesc.$transactioncode.$this->secretkey);

			$xmlstr = self::getXMLTemplate($resultcode, $resultdesc, $transactioncode, $check);

			header('Content-type: text/xml');
			die($xmlstr);
		}

		static function getXMLTemplate($resultcode, $resultdesc, $transactioncode, $check) {
			return 
<<<XML
	<result>
	<resultcode>$resultcode</resultcode>
	<resultdesc>$resultdesc</resultdesc>
	<check>$check</check>
	<data>$transactioncode</data>
	</result>
XML;
		}
	}
}

new WC_Gateway_LibertyBank_Initer;
