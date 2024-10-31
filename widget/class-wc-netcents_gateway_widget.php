<?php
class NC_Widget_Payment_Gateway extends WC_Payment_Gateway
{

	public function __construct()
	{
		global $woocommerce;

		$this->id             = 'ncgw2';
		$this->icon           = 'https://api.net-cents.com/assets/powered-by-nc-border.png';
		$this->has_fields     = false;
		$this->method_title   = __('Cryptocurrency via NetCents', 'wcwcCpg2');
		$this->order_button_text  = __('Proceed to Cryptocurrency Payment ', 'woocommerce');
		// Load the form fields.
		$this->nc_gateway_init_form_fields();

		// Load the settings.
		$this->init_settings();

		// Define user set variables.
		$this->title               = $this->settings['title'];
		$this->description         = $this->settings['description'];
		$this->api_key             = $this->settings['api-key'];
		$this->secret_key          = $this->settings['secret-key'];
		$this->merchant_id         = $this->settings['merchant-id'];
		$this->api_url             = $this->settings['api-endpoint'];

		$this->instructions        = $this->get_option('instructions');
		$this->enable_for_methods  = $this->get_option('enable_for_methods', array());
		$this->widget_access_data  = '';
		$this->widget_access_token = '';

		// Actions.
		add_action('woocommerce_api_' . strtolower(get_class($this)), array(&$this, 'nc_gateway_callback_handler'));
		if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>='))
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(&$this, 'process_admin_options'));
		else
			add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
	}
	/* Admin Panel Options.*/
	function admin_options()
	{
		?>
		<h3><?php _e('Cryptocurrency via NetCents', 'ncgw2'); ?></h3>
		<table class="form-table">
			<?php $this->generate_settings_html(); ?>
		</table> <?php
	}

	function nc_gateway_callback_handler()
	{
		$signature = $_POST['signature'];
		$data = $_POST['data'];
		$signing = $_POST['signing'];
		$exploded_parts = explode(",", $signature);
		$timestamp = explode("=", $exploded_parts[0])[1];
		$signature = explode("=", $exploded_parts[1])[1];
		$decoded_data = json_decode(base64_decode(urldecode($data)));
		$hashable_payload = $timestamp . '.' . $data;
		$hash_hmac = hash_hmac("sha256", $hashable_payload, $signing);
		$timestamp_tolerance = 1440;
		$date = new DateTime();
		$current_timestamp = $date->getTimestamp();
		$order = wc_get_order($decoded_data->external_id);
		if ($hash_hmac === $signature && ($current_timestamp - $timestamp) / 60 < $timestamp_tolerance) {
			$transaction_id = $decoded_data->transaction_id;
			$transaction_response = $this->nc_api_call('GET', '/merchant/v2/transactions/' . $transaction_id, array());
			$transaction = json_decode($transaction_response['body']);
			if (isset($transaction)) {
				$transaction_status = $transaction->status;
				if ($transaction_status == 'overpaid' || $transaction_status == 'underpaid') {
					$order->update_status('on-hold');
					header("HTTP/1.1 200 OK");
				}
				if ($transaction_status == 'paid' || $transaction_status == 'completed') {
					$order->payment_complete();
					$order->update_status('processing');
					header("HTTP/1.1 200 OK");
				}
			}
		}
	}

	/* Initialise Gateway Settings Form Fields. */
	public function nc_gateway_init_form_fields()
	{
		global $woocommerce;

		$shipping_methods = array();

		if (is_admin())
			foreach ($woocommerce->shipping->load_shipping_methods() as $method) {
				$shipping_methods[$method->id] = $method->get_title();
			}

		$this->form_fields = array(
			'enabled' => array(
				'title' => __('Enable/Disable', 'wcwcCpg2'),
				'type' => 'checkbox',
				'label' => __('Enable NetCents Widget Gateway', 'wcwcCpg2'),
				'default' => 'no'
			),
			'title' => array(
				'title' => __('Title', 'wcwcCpg2'),
				'type' => 'text',
				'description' => __('This controls the title which the user sees during checkout.', 'wcwcCpg2'),
				'desc_tip' => true,
				'default' => __('NetCents', 'wcwcCpg2')
			),
			'description' => array(
				'title' => __('Description', 'wcwcCpg2'),
				'type' => 'textarea',
				'description' => __('This controls the description which the user sees during checkout.', 'wcwcCpg2'),
				'default' => __('Pay with Cryptocurrency', 'wcwcCpg2')
			),
			'instructions' => array(
				'title' => __('Instructions', 'wcwcCpg2'),
				'type' => 'textarea',
				'description' => __('Instructions that will be added to the thank you page.', 'wcwcCpg2'),
				'default' => __('Instructions for Custom Payment.', 'wcwcCpg2')
			),
			'api-key' => array(
				'title' => __('API Key', 'wcwcCpg1'),
				'type' => 'text',
				'description' => __('Your API key.', 'wcwcCpg1'),
				'default' => __('', 'wcwcCpg1')
			), 'secret-key' => array(
				'title' => __('Secret Key', 'wcwcCpg1'),
				'type' => 'text',
				'description' => __('Your Secret key.', 'wcwcCpg1'),
				'default' => __('', 'wcwcCpg1')
			), 'merchant-id' => array(
				'title' => __('Web Plugin ID', 'wcwcCpg1'),
				'type' => 'text',
				'description' => __('Your Web Plugin ID', 'wcwcCpg1'),
				'default' => __('', 'wcwcCpg1')
			), 'api-endpoint' => array(
				'title' => __('Plugin endpoint', 'wcwcCpg1'),
				'type' => 'text',
				'description' => __('The site the plugin will use to connect to. Please use the appropriate URL.', 'wcwcCpg1'),
				'default' => __('https://merchant.net-cents.com', 'https://merchant.net-cents.com')
			)
		);
	}

	public function get_cancel_order_url_raw($redirect = '')
	{
		return apply_filters('woocommerce_get_cancel_order_url_raw', add_query_arg(array(
			'cancel_order' => 'true',
			'order'        => $this->get_order_key(),
			'order_id'     => $this->get_id(),
			'redirect'     => $redirect,
			'_wpnonce'     => wp_create_nonce('woocommerce-cancel_order'),
		), $this->nc_gateway_get_cancel_endpoint()));
	}

	public function nc_gateway_get_cancel_endpoint()
	{
		$cancel_endpoint = wc_get_page_permalink('cart');

		if (!$cancel_endpoint) {
			$cancel_endpoint = home_url();
		}

		if (false === strpos($cancel_endpoint, '?')) {
			$cancel_endpoint = trailingslashit($cancel_endpoint);
		}
		return $cancel_endpoint;
	}

	function nc_get_api_url()
	{
		$parsed = parse_url($this->api_url);
		if ($this->api_url == 'https://merchant.net-cents.com') {
			$api_url = 'https://api.net-cents.com';
		} else if ($this->api_url == 'https://gateway-staging.net-cents.com') {
			$api_url = 'https://api-staging.net-cents.com';
		} else if ($this->api_url == 'https://gateway-test.net-cents.com') {
			$api_url = 'https://api-test.net-cents.com';
		} else {
			$api_url = $parsed['scheme'] . '://' . 'api.' . $parsed['host'];
		}
		return $api_url;
	}


	function nc_api_call($method, $path, $params) 
	{
		$api_url = $this->nc_get_api_url();
		$api_key = $this->api_key;
		$secret = $this->secret_key;

		$encrypted_string = wp_remote_request($api_url . $path, array(
			'method' => $method,
			'body' => $params,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode($api_key . ':' . $secret)
			)
		));
		return $encrypted_string;
	}

	function nc_gateway_request_access($order, $order_id)
	{
		$webhook_url = get_bloginfo('url') . '/?wc-api=nc_widget_payment_gateway';
		$callback_url = $this->get_return_url($order);
		$api_key = $this->api_key;
		$secret = $this->secret_key;
		$order_amount = $order->get_total();
		$order_currency = $order->get_currency();
		$merchant_id = $this->merchant_id;
		$api_url = $this->nc_get_api_url();
		$date = new DateTime();
		$params = array(
			'external_id' => $order_id,
			'amount' => $order_amount,
			'currency_iso' => $order_currency,
			'hosted_payment_id' => $this->merchant_id,
			'callback_url' => $callback_url,
			'first_name' => $order->get_billing_first_name(),
			'last_name' => $order->get_billing_last_name(),
			'email' => $order->get_billing_email(),
			'webhook_url' => $webhook_url,
			'merchant_id' => $api_key,
			'data_encryption' => array(
				'external_id' => $order_id,
				'amount' => $order_amount,
				'currency_iso' => $order_currency,
				'hosted_payment_id' => $this->merchant_id,
				'callback_url' => $callback_url,
				'first_name' => $order->get_billing_first_name(),
				'last_name' => $order->get_billing_last_name(),
				'webhook_url' => $webhook_url,
				'email' => $order->get_billing_email(),
				'merchant_id' => $api_key
			)
		);

		$encrypted_string = $this->nc_api_call("POST", "/merchant/v2/widget_payments", $params);
		$json_response = json_decode($encrypted_string['body']);
		if ($json_response->status == 200) {
			return $json_response->token;
		} else {
			return $json_response;
		}
	}

	/* Process the payment and return the result. */
	function process_payment($order_id)
	{
		global $woocommerce;
		$order = new WC_Order($order_id);
		$access_token = $this->nc_gateway_request_access($order, $order_id);
		if (gettype($access_token) == 'string') {
			try {
				return array(
					'result' => 'success',
					'redirect' => $this->api_url . '/widget/merchant/widget?data=' . $access_token
				);
			} catch (Exception $ex) {
				wc_add_notice($ex->getMessage(), 'error');
			}
			return array(
				'result' => $access->message,
				'redirect' => ''
			);
		} else {
			wc_add_notice($access->message, 'error');
		}
	}

	/* Output for the order received page.   */
	function thankyou()
	{
		echo $this->instructions != '' ? wpautop($this->instructions) : '';
	}
}
