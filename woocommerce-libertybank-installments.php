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


add_action( 'plugins_loaded', 'init_your_gateway_class' );

function init_your_gateway_class() {
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
			$order  = wc_get_order( $order_id );
			$callid = urldecode($order->order_key);
			
			// TODO: go to bank form
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
			$ordercode     = urldecode($_GET['ordercode']);
			$callid        = urldecode($_GET['callid']);
			$check         = urldecode($_GET['check']);

			$str = $status.$installmentid.$ordercode.$callid.$this->secretkey;
			$calculatedCheck = hash('sha256',$str);
			
			if (strcasecmp($check,$calculatedCheck)==0) 
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
						$order->payment_complete( 'Status APPROVED' );
					}
					else {
						WC_Gateway_Paypal::log( 'Error: Strange status: ' . $status );
						$resultcode = -3;
						$resultdesc = -3;
					}
				}
			}
			else {
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

function add_your_gateway_class( $methods ) {
	$methods[] = 'WC_Gateway_LibertyBank_Installments'; 
	return $methods;
}

add_filter( 'woocommerce_payment_gateways', 'add_your_gateway_class' );
