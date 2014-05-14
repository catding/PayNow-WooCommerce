<?php
/**
 * Sage Pay Now Payment Gateway
 *
 * Provides a Sage Pay Now Payment Gateway.
 *
 * @class 		woocommerce_paynow
 * @package		WooCommerce
 * @category	Payment Gateways
 * @author		Gateway Modules
 * 
 * Note:
 *  All references to sanitize replace with mysql_real_escape_string
 *
 */
class WC_Gateway_PayNow extends WC_Payment_Gateway {
	public $version = '1.0.2';
	public function __construct() {
		global $woocommerce;
		
		// $this->notify_url = str_replace( 'https:', 'http:', add_query_arg( 'wc-api', 'WC_Gateway_PayNow', home_url( '/' ) ) );
		
		$this->id = 'paynow';
		$this->method_title = __ ( 'Pay Now', 'woothemes' );
		$this->icon = $this->plugin_url () . '/assets/images/icon.png';
		$this->has_fields = true;
		$this->debug_email = get_option ( 'admin_email' );
		
		// Setup available countries.
		$this->available_countries = array (
				'ZA' 
		);
		
		// Setup available currency codes.
		$this->available_currencies = array (
				'ZAR' 
		);
		
		// Load the form fields.
		$this->init_form_fields ();
		
		// Load the settings.
		$this->init_settings ();
		
		// Setup constants.
		$this->setup_constants ();
		
		// Setup default merchant data.
		$this->service_key = $this->settings ['service_key'];
		
		$this->url = 'https://paynow.sagepay.co.za/site/paynow.aspx';

		$this->title = $this->settings ['title'];
		
		$this->response_url = add_query_arg ( 'wc-api', 'WC_Gateway_PayNow', home_url ( '/' ) );
		
		add_action ( 'woocommerce_api_wc_gateway_paynow', array (
				$this,
				'check_ipn_response' 
		) );
		
		add_action ( 'valid-paynow-standard-ipn-request', array (
				$this,
				'successful_request' 
		) );
		
		/* 1.6.6 */
		add_action ( 'woocommerce_update_options_payment_gateways', array (
				$this,
				'process_admin_options' 
		) );
		
		/* 2.0.0 */
		add_action ( 'woocommerce_update_options_payment_gateways_' . $this->id, array (
				$this,
				'process_admin_options' 
		) );
		
		add_action ( 'woocommerce_receipt_paynow', array (
				$this,
				'receipt_page' 
		) );
		
		// Check if the base currency supports this gateway.
		if (! $this->is_valid_for_use ())
			$this->enabled = false;
	}
	
	/**
	 * Initialise Gateway Settings Form Fields
	 *
	 * @since 1.0.0
	 */
	function init_form_fields() {
		$this->form_fields = array (
				'enabled' => array (
						'title' => __ ( 'Enable/Disable', 'woothemes' ),
						'label' => __ ( 'Enable Pay Now', 'woothemes' ),
						'type' => 'checkbox',
						'description' => __ ( 'This controls whether or not this gateway is enabled within WooCommerce.', 'woothemes' ),
						'default' => 'yes' 
				),
				'title' => array (
						'title' => __ ( 'Title', 'woothemes' ),
						'type' => 'text',
						'description' => __ ( 'This controls the title which the user sees during checkout.', 'woothemes' ),
						'default' => __ ( 'Sage Pay Now', 'woothemes' ) 
				),
				'description' => array (
						'title' => __ ( 'Description', 'woothemes' ),
						'type' => 'text',
						'description' => __ ( 'This controls the description which the user sees during checkout.', 'woothemes' ),
						'default' => '' 
				),
				'service_key' => array (
						'title' => __ ( 'Service Key', 'woothemes' ),
						'type' => 'text',
						'description' => __ ( 'This is the service key, received from Pay Now.', 'woothemes' ),
						'default' => '' 
				),
				'send_email_confirm' => array (
						'title' => __ ( 'Send Email Confirmations', 'woothemes' ),
						'type' => 'checkbox',
						'label' => __ ( 'An email confirmation will be sent from the Pay Now gateway to the client after each transaction.', 'woothemes' ),
						'default' => 'yes' 
				),
				'send_debug_email' => array(
						'title' => __( 'Enable Debug', 'woothemes' ),
						'type' => 'checkbox',
						'label' => __( 'Send debug e-mails for transactions and creates a log file in WooCommerce log folder called sagepaynow.log', 'woothemes' ),
						'default' => 'yes'
				),
				'debug_email' => array(
						'title' => __( 'Who Receives Debug Emails?', 'woothemes' ),
						'type' => 'text',
						'description' => __( 'The e-mail address to which debugging error e-mails are sent when debugging is on.', 'woothemes' ),
						'default' => get_option( 'admin_email' )
				)
		);
	} // End init_form_fields()
	
	/**
	 * Get the plugin URL
	 *
	 * @since 1.0.0
	 */
	function plugin_url() {
		if (isset ( $this->plugin_url ))
			return $this->plugin_url;
		
		if (is_ssl ()) {
			return $this->plugin_url = str_replace ( 'http://', 'https://', WP_PLUGIN_URL ) . "/" . plugin_basename ( dirname ( dirname ( __FILE__ ) ) );
		} else {
			return $this->plugin_url = WP_PLUGIN_URL . "/" . plugin_basename ( dirname ( dirname ( __FILE__ ) ) );
		}
	} // End plugin_url()
	
	/**
	 * is_valid_for_use()
	 *
	 * Check if this gateway is enabled and available in the base currency being traded with.
	 *
	 * @since 1.0.0
	 */
	function is_valid_for_use() {
		global $woocommerce;
		
		$is_available = false;
		
		$user_currency = get_option ( 'woocommerce_currency' );
		
		$is_available_currency = in_array ( $user_currency, $this->available_currencies );
		
		if ($is_available_currency && $this->enabled == 'yes' && $this->settings ['service_key'] != '')
			$is_available = true;
		
		return $is_available;
	} // End is_valid_for_use()
	
	/**
	 * Admin Panel Options
	 * - Options for bits like 'title' and availability on a country-by-country basis
	 *
	 * @since 1.0.0
	 */
	public function admin_options() {
		// Make sure to empty the log file if not in debug mode.
		// TODO Test mode does not exist so improve the lines below
		if ($this->settings ['send_debug_email'] != 'yes') {
			$this->log ( '' );
			$this->log ( '', true );
		}
		
		?>
<h3><?php _e( 'Pay Now', 'woothemes' ); ?></h3>
<p><?php printf( __( 'Pay Now works by sending the user to %sSage Pay Now%s to enter their payment information.', 'woothemes' ), '<a href="http://www.netcash.co.za/sagepay/pay_now_gateway.asp">', '</a>' ); ?></p>

<?php
		if ('ZAR' == get_option ( 'woocommerce_currency' )) {
			?><table class="form-table"><?php
			// Generate the HTML For the settings form.
			$this->generate_settings_html ();
			?></table>
<!--/.form-table-->
<?php
		} else {
			?>
<div class="inline error">
	<p>
		<strong><?php _e( 'Gateway Disabled', 'woothemes' ); ?></strong> <?php echo sprintf( __( 'Choose South African Rands as your store currency in <a href="%s">Pricing Options</a> to enable the Sage Pay Now Gateway.', 'woocommerce' ), admin_url( '?page=woocommerce&tab=catalog' ) ); ?></p>
</div>
<?php
		} // End check currency
		?>
    	<?php
	} // End admin_options()
	
	/**
	 * There are no payment fields for Sage Pay Now, but we want to show the description if set.
	 *
	 * @since 1.0.0
	 */
	function payment_fields() {
		if (isset ( $this->settings ['description'] ) && ('' != $this->settings ['description'])) {
			echo wpautop ( wptexturize ( $this->settings ['description'] ) );
		}
	} // End payment_fields()
	
	/**
	 * Generate the Sage Pay Now button link.
	 *
	 * @since 1.0.0
	 */
	public function generate_paynow_form($order_id) {
		global $woocommerce;
		
		$order = new WC_Order ( $order_id );
		
		$shipping_name = explode ( ' ', $order->shipping_method );
		
		// Construct variables for post
		$this->data_to_send = array (
				// Merchant details
				'm1' => $this->settings ['service_key'],
				// m2 is Pay Now's internally used Software vendor key
				'm2' => '24ade73c-98cf-47b3-99be-cc7b867b3080',				
								
				// Item details
				'p4' => $order->order_total,
				'p2' => $order->order_key,
				'p3' => sprintf ( __ ( 'New order from %s', 'woothemes' ), get_bloginfo ( 'name' ) ),
				
				// Extra fields
				'm4' => $this->get_return_url ( $order ),
				'm5' => $order->get_cancel_order_url (),
				'm6' => $order->id,				
				'm10' => 'wc-api=WC_Gateway_PayNow',
				
				// Unused but useful reference fields for debugging
				'return_url' => $this->get_return_url ( $order ),
				'cancel_url' => $order->get_cancel_order_url (),
				'notify_url' => $this->response_url,
				
				// More unused fields useful in debugging
				'first_name' => $order->billing_first_name,
				'last_name' => $order->billing_last_name,
				'email_address' => $order->billing_email
				
		);
		
		$paynow_args_array = array ();
		
		foreach ( $this->data_to_send as $key => $value ) {
			$paynow_args_array [] = '<input type="hidden" name="' . $key . '" value="' . $value . '" />';
		}
		
		$this->log ( "Sage Pay Now form post paynow_args_array: " . print_r ( $paynow_args_array, true ) );
		
		return '<form action="' . $this->url . '" method="post" id="paynow_payment_form">
				' . implode ( '', $paynow_args_array ) . '
				<input type="submit" class="button-alt" id="submit_paynow_payment_form" value="' . __ ( 'Pay via Pay Now', 'woothemes' ) . '" /> <a class="button cancel" href="' . $order->get_cancel_order_url () . '">' . __ ( 'Cancel order &amp; restore cart', 'woothemes' ) . '</a>
				<script type="text/javascript">
					jQuery(function(){
						jQuery("body").block(
							{
								message: "<img src=\"' . $woocommerce->plugin_url () . '/assets/images/ajax-loader.gif\" alt=\"Redirecting...\" />' . __ ( 'Thank you for your order. We are now redirecting you to Sage Pay Now to make payment.', 'woothemes' ) . '",
								overlayCSS:
								{
									background: "#fff",
									opacity: 0.6
								},
								css: {
							        padding:        20,
							        textAlign:      "center",
							        color:          "#555",
							        border:         "3px solid #aaa",
							        backgroundColor:"#fff",
							        cursor:         "wait"
							    }
							});
						jQuery( "#submit_paynow_payment_form" ).click();
					});
				</script>
			</form>';
	} // End generate_paynow_form()
	
	/**
	 * Process the payment and return the result.
	 *
	 * @since 1.0.0
	 */
	function process_payment($order_id) {
		$order = new WC_Order ( $order_id );
		
		return array (
				'result' => 'success',
				'redirect' => $order->get_checkout_payment_url ( true ) 
		);
	}
	
	/**
	 * Receipt page.
	 *
	 * Display text and a button to direct the user to Pay Now.
	 *
	 * @since 1.0.0
	 */
	function receipt_page($order) {
		echo '<p>' . __ ( 'Thank you for your order, please click the button below to pay with Pay Now.', 'woothemes' ) . '</p>';
		
		echo $this->generate_paynow_form ( $order );
	} // End receipt_page()
	
	/**
	 * Check Pay Now IPN validity.
	 *
	 * @param array $data        	
	 *
	 * @since 1.0.0
	 */
	function check_ipn_request_is_valid($data) {
		global $woocommerce;
		
		$this->log ( "Checking if IPN request is valid..." );
		
		$pnError = false;
		$pnDone = false;
		$pnDebugEmail = $this->settings ['debug_email'];
		
		if (! is_email ( $pnDebugEmail )) {
			$pnDebugEmail = get_option ( 'admin_email' );
		}
		
		$sessionid = $data ['Reference'];
		$transaction_id = $data ['pn_payment_id'];
		$vendor_name = get_option ( 'blogname' );
		$vendor_url = home_url ( '/' );
		
		$order_id = ( int ) $data ['Extra3'];
		$order_key = esc_attr ( $sessionid );
		$order = new WC_Order ( $order_id );
		
		$data_string = '';
		$data_array = array ();
		
		// Dump the submitted variables and calculate security signature
		foreach ( $data as $key => $val ) {
			$data_string .= $key . '=' . urlencode ( $val ) . '&';
			$data_array [$key] = $val;
		}
		
		// Remove the last '&' from the parameter string
		$data_string = substr ( $data_string, 0, - 1 );
		
		$this->log ( "\n" . '----------' . "\n" . 'Pay Now IPN call received' );
		
		// Notify Sage Pay Now that information has been received
		if (! $pnError && ! $pnDone) {
			header ( 'HTTP/1.0 200 OK' );
			flush ();
		}
		
		// Get data sent by Sage Pay Now
		if (! $pnError && ! $pnDone) {
			$this->log ( 'Get posted data' );
			
			$this->log ( 'Sage Pay Now Data: ' . print_r ( $data, true ) );
			
			if ($data === false) {
				$pnError = true;
				$pnErrMsg = PN_ERR_BAD_ACCESS;
			}
		}
		
		// Get internal order and verify it hasn't already been processed
		if (! $pnError && ! $pnDone) {
			
			$this->log ( "Purchase:\n" . print_r ( $order, true ) );
			
			// Check if order has already been processed
			if ($order->status == 'completed') {
				$this->log ( 'Order has already been processed' );
				$pnDone = true;
			}
		}
		
		// Check data against internal order
		if (! $pnError && ! $pnDone) {
			$this->log ( 'Check data against internal order' );
			
			// Check order amount
			if (! $this->amounts_equal ( $data ['Amount'], $order->order_total )) {
				$pnError = true;
				$pnErrMsg = PN_ERR_AMOUNT_MISMATCH;
			}			// Check session ID
			elseif (strcasecmp ( $data ['Reference'], $order->order_key ) != 0) {
				$pnError = true;
				$pnErrMsg = PN_ERR_SESSIONID_MISMATCH;
			}
		}
		
		// Check status and update order
		if (! $pnError && ! $pnDone) {
			$this->log ( 'Check status and update order' );
			
			if ($order->order_key !== $order_key) {
				$this->log ( "Order key object: " . $order->order_key );
				$this->log ( "Order key variable: " . $order_key );
				$this->log ( "order->order_key != order_key so exiting" );
				exit ();
			}
			
			switch ($data ['TransactionAccepted']) {
				case 'true' :
					$this->log ( '- Complete' );
					
					// Payment completed
					$order->add_order_note ( __ ( 'IPN payment completed', 'woothemes' ) );
					$order->payment_complete ();
					$this->log ( 'Note added to order' );
					if ($this->settings ['send_debug_email'] == 'yes') {
						$this->log ( 'Debug on so sending e-mail' );						
						$subject = "Sage Pay Now Successful Transaction on your site";
						$body = "Hi,\n\n" . "A Sage Pay Now transaction has been completed successfully on your website\n" . "------------------------------------------------------------\n" . "Site: " . $vendor_name . " (" . $vendor_url . ")\n" . "Purchase ID: " . $data ['Reference'] . "\n" . "RequestTrace: " . $data ['RequestTrace'] . "\n" . "Payment Status: " . $data ['TransactionAccepted'] . "\n" . "Order Status Code: " . $order->status;
						$pathinfo = pathinfo ( __FILE__ );
						$filename = $pathinfo['dirname'] . "/../../woocommerce/logs/sagepaynow.log";
						$body .= file_get_contents($filename);
						$this->log ( "Email:" . $body );
						wp_mail ( $pnDebugEmail, $subject, $body );
					} else {
						$this->log ( 'Debug off so not sending email' );
					}
					
					break;
				
				case 'false' :
					$this->log ( '- Failed, updating status with failed message: ' . $data['Reason'] );
					
					$order->update_status ( 'failed', sprintf ( __ ( 'Payment %s via IPN.', 'woothemes' ), strtolower ( mysql_real_escape_string ( $data ['Reason'] ) ) ) );
					$this->log("Checking if mail must be sent");
					if ($this->settings ['send_debug_email'] == 'yes') {
						$this->log("Debug on so sending mail that transaction failed.");
						$subject = "Sage Pay Now Failed Transaction on your site";
						$body = "Hi,\n\n" . "A failed Sage Pay Now transaction on your website requires attention\n" . "------------------------------------------------------------\n" . "Site: " . $vendor_name . " (" . $vendor_url . ")\n" . "Purchase ID: " . $order->id . "\n" . "User ID: " . $order->user_id . "\n" . "RequestTrace: " . $data ['RequestTrace'] . "\n" . "Payment Status: " . $data ['TransactionAccepted'] . "\n" . "Order Status Code: " . $order->status . "\n" . "Failure Reason: " . $data ['Reason'];
						$pathinfo = pathinfo ( __FILE__ );
						$filename = $pathinfo['dirname'] . "/../../woocommerce/logs/sagepaynow.log";
						$body .= file_get_contents($filename);
						$this->log ( "Email:" . $body );
						wp_mail ( $pnDebugEmail, $subject, $body );
					} else {
						$this->log("Not sending failed mail.");
					}					
					break;				
				
				default :
					// If unknown status, do nothing (safest course of action)
					break;
			}
		}
		
		// If an error occurred
		if ($pnError) {
			$this->log ( 'Error occurred: ' . $pnErrMsg );
			
			if ($this->settings ['send_debug_email'] == 'yes') {
				$this->log ( 'Debug on so sending email notification' );
				
				// Send an email
				$subject = "Sage Pay Now Processing Error: " . $pnErrMsg;
				$body = "Hi,\n\n" . "An invalid Pay Now transaction on your website requires attention\n" . "------------------------------------------------------------\n" . "Site: " . $vendor_name . " (" . $vendor_url . ")\n" . "Remote IP Address: " . $_SERVER ['REMOTE_ADDR'] . "\n" . "Remote host name: " . gethostbyaddr ( $_SERVER ['REMOTE_ADDR'] ) . "\n" . "Purchase ID: " . $order->id . "\n" . "User ID: " . $order->user_id . "\n";
				if (isset ( $data ['RequestTrace'] ))
					$body .= "Pay Now RequestTrace: " . $data ['RequestTrace'] . "\n";
				if (isset ( $data ['Reason'] ))
					$body .= "Pay Now Payment Transaction Failed Reason: " . $data ['Reason'] . "\n";
				$body .= "\nError: " . $pnErrMsg . "\n";
				
				switch ($pnErrMsg) {
					case PN_ERR_AMOUNT_MISMATCH :
						$body .= "Value received : " . $data ['Amount'] . "\n" . "Value should be: " . $order->order_total;
						break;
					
					case PN_ERR_ORDER_ID_MISMATCH :
						$body .= "Value received : " . $data ['Extra3'] . "\n" . "Value should be: " . $order->id;
						break;
					
					case PN_ERR_SESSION_ID_MISMATCH :
						$body .= "Value received : " . $data ['Reference'] . "\n" . "Value should be: " . $order->id;
						break;
					
					// For all other errors there is no need to add additional information
					default :
						break;
				}
				$pathinfo = pathinfo ( __FILE__ );
				$filename = $pathinfo['dirname'] . "/../../woocommerce/logs/sagepaynow.log";
				$body .= file_get_contents($filename);
				$this->log ( "Sage Pay Now error log body: " . $body );
				wp_mail ( $pnDebugEmail, $subject, $body );
			}
		}
		$this->log ( "Looks like we're done. pnError, if any, = " . $pnError );
		
		// Close log
		$this->log ( '', true );
		
		if ($data['TransactionAccepted'] == 'true') {
			wp_redirect($data['Extra1']);
		}
		else {
			wp_redirect($data['Extra2']);
		}
		
		//return $pnError;
	} // End check_ipn_request_is_valid()
	
	/**
	 * Check Pay Now IPN response.
	 *
	 * @since 1.0.0
	 */
	function check_ipn_response() {
		$this->log ( "check_ipn_response" );
		
		// TODO To see if IPN is received, remove comment below
		// wp_die( "Sage Pay Now IPN Request Failure", "PayPal IPN", array( 'response' => 200 ) );
		
		$_POST = stripslashes_deep ( $_POST );
		
		if ($this->check_ipn_request_is_valid ( $_POST )) {
			$this->log ("OK:ipn_request_is_valid");
			do_action ( 'valid-paynow-standard-ipn-request', $_POST );
		} else {
			$this->log ("NOT OK:ipn_request_is_valid");
		}
	} // End check_ipn_response()
	
	/**
	 * Successful Payment!
	 *
	 * @since 1.0.0
	 */
	function successful_request($posted) {
		$this->log("Successful Payment so successful_request is called");
		if (! isset ( $posted ['Extra3'] ) && ! is_numeric ( $posted ['Extra3'] )) {
			return false;
		}
		
		$order_id = ( int ) $posted ['Extra3'];
		$order_key = esc_attr ( $posted ['Reference'] );
		$order = new WC_Order ( $order_id );
		
		if ($order->order_key !== $order_key) {
			exit ();
		}
		
		if ($order->status !== 'completed') {
			// We are here so lets check status and do actions
			switch (strtolower ( $posted ['TransactionAccepted'] )) {
				case 'true' :
					// Payment completed
					$order->add_order_note ( __ ( 'IPN payment completed', 'woothemes' ) );
					$order->payment_complete ();
					break;
				case 'denied' :
				case 'expired' :
				case 'failed' :
				case 'voided' :
					// Failed order
					$order->update_status ( 'failed', sprintf ( __ ( 'Payment %s via IPN.', 'woothemes' ), strtolower ( mysql_real_escape_string ( $posted ['Reason'] ) ) ) );
					break;
				default :
					// Hold order
					$order->update_status ( 'on-hold', sprintf ( __ ( 'Payment %s via IPN.', 'woothemes' ), strtolower ( mysql_real_escape_string ( $posted ['Reason'] ) ) ) );
					break;
			} // End SWITCH Statement
			
			wp_redirect ( $this->get_return_url ( $order ) );
			exit ();
		} // End IF Statement
		
		exit ();
	}
	
	/**
	 * Setup constants.
	 *
	 * Setup common values and messages used by the Sage Pay Now gateway.
	 *
	 * @since 1.0.0
	 */
	function setup_constants() {
		global $woocommerce;
		// // Create user agent string
		// User agent constituents (for cURL)
		define ( 'PN_SOFTWARE_NAME', 'WooCommerce' );
		define ( 'PN_SOFTWARE_VER', $woocommerce->version );
		define ( 'PN_MODULE_NAME', 'WooCommerce-PayNow-Free' );
		define ( 'PN_MODULE_VER', $this->version );
		
		// Features
		// - PHP
		$pnFeatures = 'PHP ' . phpversion () . ';';
		
		// - cURL
		if (in_array ( 'curl', get_loaded_extensions () )) {
			define ( 'PN_CURL', '' );
			$pnVersion = curl_version ();
			$pnFeatures .= ' curl ' . $pnVersion ['version'] . ';';
		} else
			$pnFeatures .= ' nocurl;';
			
			// Create user agrent
		define ( 'PN_USER_AGENT', PN_SOFTWARE_NAME . '/' . PN_SOFTWARE_VER . ' (' . trim ( $pnFeatures ) . ') ' . PN_MODULE_NAME . '/' . PN_MODULE_VER );
		
		// General Defines
		define ( 'PN_TIMEOUT', 15 );
		define ( 'PN_EPSILON', 0.01 );
		
		// Messages
		// Error
		define ( 'PN_ERR_AMOUNT_MISMATCH', __ ( 'Amount mismatch', 'woothemes' ) );
		define ( 'PN_ERR_BAD_ACCESS', __ ( 'Bad access of page', 'woothemes' ) );
		define ( 'PN_ERR_BAD_SOURCE_IP', __ ( 'Bad source IP address', 'woothemes' ) );
		define ( 'PN_ERR_CONNECT_FAILED', __ ( 'Failed to connect to Sage Pay Now', 'woothemes' ) );
		define ( 'PN_ERR_INVALID_SIGNATURE', __ ( 'Security signature mismatch', 'woothemes' ) );
		define ( 'PN_ERR_NO_SESSION', __ ( 'No saved session found for IPN transaction', 'woothemes' ) );
		define ( 'PN_ERR_ORDER_ID_MISSING_URL', __ ( 'Order ID not present in URL', 'woothemes' ) );
		define ( 'PN_ERR_ORDER_ID_MISMATCH', __ ( 'Order ID mismatch', 'woothemes' ) );
		define ( 'PN_ERR_ORDER_INVALID', __ ( 'This order ID is invalid', 'woothemes' ) );
		define ( 'PN_ERR_ORDER_NUMBER_MISMATCH', __ ( 'Order Number mismatch', 'woothemes' ) );
		define ( 'PN_ERR_ORDER_PROCESSED', __ ( 'This order has already been processed', 'woothemes' ) );
		define ( 'PN_ERR_PDT_FAIL', __ ( 'PDT query failed', 'woothemes' ) );
		define ( 'PN_ERR_PDT_TOKEN_MISSING', __ ( 'PDT token not present in URL', 'woothemes' ) );
		define ( 'PN_ERR_SESSIONID_MISMATCH', __ ( 'Session ID mismatch', 'woothemes' ) );
		define ( 'PN_ERR_UNKNOWN', __ ( 'Unkown error occurred', 'woothemes' ) );
		
		// General
		define ( 'PN_MSG_OK', __ ( 'Payment was successful', 'woothemes' ) );
		define ( 'PN_MSG_FAILED', __ ( 'Payment has failed', 'woothemes' ) );
		define ( 'PN_MSG_PENDING', __ ( 'The payment is pending. Please note, you will receive another Instant', 'woothemes' ) . __ ( ' Transaction Notification when the payment status changes to', 'woothemes' ) . __ ( ' "Completed", or "Failed"', 'woothemes' ) );
	} // End setup_constants()
	
	/**
	 * log()
	 *
	 * Log system processes.
	 *
	 * @since 1.0.0
	 */
	function log($message, $close = false) {		
		if ( ( $this->settings['send_debug_email'] != 'yes' && ! is_admin() ) ) { return; }
		static $fh = 0;
		
		if ($close) {
			@fclose ( $fh );
		} else {
			// If file doesn't exist, create it
			if (! $fh) {
				$pathinfo = pathinfo ( __FILE__ );				
				$dir = $pathinfo['dirname'] . "/../../woocommerce/logs";
				$fh = @fopen ( $dir . '/sagepaynow.log', 'a+' );
			}
			
			// If file was successfully created
			if ($fh) {
				$line = date( 'Y-m-d H:i:s' ) .' : '. $message . "\n";
				
				fwrite ( $fh, $line );
			}
		}
	} // End log()
	
	/**
	 * amounts_equal()
	 *
	 * Checks to see whether the given amounts are equal using a proper floating
	 * point comparison with an Epsilon which ensures that insignificant decimal
	 * places are ignored in the comparison.
	 *
	 * eg. 100.00 is equal to 100.0001
	 *
	 * @param $amount1 Float
	 *        	1st amount for comparison
	 * @param $amount2 Float
	 *        	2nd amount for comparison
	 * @since 1.0.0
	 */
	function amounts_equal($amount1, $amount2) {
		if (abs ( floatval ( $amount1 ) - floatval ( $amount2 ) ) > PN_EPSILON) {
			return (false);
		} else {
			return (true);
		}
	} // End amounts_equal()
} // End Class