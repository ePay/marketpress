<?php
/*
Copyright (c) 2013. All rights reserved ePay - www.epay.dk.

This program is free software. You are allowed to use the software but NOT allowed to modify the software. 
It is also not legal to do any changes to the software and distribute it in your own name / brand. 
*/

/*
MarketPress ePay Gateway Plugin
Author: Michael Korsgaard
*/

class MP_Gateway_EPay extends MP_Gateway_API
{
	//private gateway slug. Lowercase alpha (a-z) and dashes (-) only please!
	var $plugin_name = 'epay';
	
	//name of your gateway, for the admin side.
	var $admin_name = '';
	
	//public name of your gateway, for lists and such.
	var $public_name = '';
	
	//url for an image for your checkout method. Displayed on checkout form if set
	var $method_img_url = '';
	
	//url for an submit button image for your checkout method. Displayed on checkout form if set
	var $method_button_img_url = '';
	
	//whether or not ssl is needed for checkout page
	var $force_ssl = false;
	
	//always contains the url to send payment notifications to if needed by your gateway. Populated by the parent class
	var $ipn_url;
	
	//whether if this is the only enabled gateway it can skip the payment_form step
	var $skip_form = true;
	
	//credit card vars
	var $epay_merchantnumber, $epay_md5key, $epay_windowid, $epay_instantcapture, $epay_group, $epay_authmail, $epay_authsms;
	
	/****** Below are the public methods you may overwrite via a plugin ******/
	
	/**
	* Runs when your class is instantiated. Use to setup your plugin instead of __construct()
	*/
	function on_creation()
	{
		global $mp;
		$settings = get_option('mp_settings');
		
		//set names here to be able to translate
		$this->admin_name = __('ePay / Payment Solutions', 'mp');
		$this->public_name = __('ePay / Payment Solutions', 'mp');
		
		if(isset($settings['gateways']['epay']))
		{
			$this->epay_merchantnumber = $settings['gateways']['epay']['epay_merchantnumber'];
			$this->epay_md5key = $settings['gateways']['epay']['epay_md5key'];
		}
	}
	
	/**
	* Return fields you need to add to the top of the payment screen, like your credit card info fields
	*
	* @param array $cart. Contains the cart contents for the current blog, global cart if $mp->global_cart is true
	* @param array $shipping_info. Contains shipping info and email in case you need it
	*/
	function payment_form($cart, $shipping_info)
	{
		global $mp;
		if(isset($_GET['epay_cancel']))
		{
			echo '<div class="mp_checkout_error">' . __('Your ePay transaction has been canceled.', 'mp') . '</div>';
		}
	}
	
	/**
	* Use this to process any fields you added. Use the $_REQUEST global,
	*  and be sure to save it to both the $_SESSION and usermeta if logged in.
	*  DO NOT save credit card details to usermeta as it's not PCI compliant.
	*  Call $mp->cart_checkout_error($msg, $context); to handle errors. If no errors
	*  it will redirect to the next step.
	*
	* @param array $cart. Contains the cart contents for the current blog, global cart if $mp->global_cart is true
	* @param array $shipping_info. Contains shipping info and email in case you need it
	*/
	function process_payment_form($cart, $shipping_info)
	{
		global $mp;
		
		$mp->generate_order_id();
	}
	
	/**
	* Return the chosen payment details here for final confirmation. You probably don't need
	*  to post anything in the form as it should be in your $_SESSION var already.
	*
	* @param array $cart. Contains the cart contents for the current blog, global cart if $mp->global_cart is true
	* @param array $shipping_info. Contains shipping info and email in case you need it
	*/
	function confirm_payment_form($cart, $shipping_info)
	{
		global $mp;
	}
	
	/**
	* Use this to do the final payment. Create the order then process the payment. If
	*  you know the payment is successful right away go ahead and change the order status
	*  as well.
	*  Call $mp->cart_checkout_error($msg, $context); to handle errors. If no errors
	*  it will redirect to the next step.
	*
	* @param array $cart. Contains the cart contents for the current blog, global cart if $mp->global_cart is true
	* @param array $shipping_info. Contains shipping info and email in case you need it
	*/
	function process_payment($cart, $shipping_info)
	{
		global $mp;
		
		$timestamp = time();
		$settings = get_option('mp_settings');
		
		$url = "https://ssl.ditonlinebetalingssystem.dk/integration/ewindow/Default.aspx";
		
		$params = array();
		
		$params['windowstate'] = "3"; //Full screen
		$params['merchantnumber'] = $this->epay_merchantnumber;
		$params['windowid'] = ($this->epay_windowid ? $this->epay_windowid : "1");
		$params['instantcapture'] = ($this->epay_instantcapture ? "1" : "0");
		$params['group'] = $this->epay_group;
		$params['mailreceipt'] = $this->epay_authmail;
		$params['smsreceipt'] = $this->epay_authsms;
		$params['orderid'] = $_SESSION['mp_order'];
		$params['currency'] = $mp->get_setting('currency');
		$params['accepturl'] = mp_checkout_step_url('confirmation');
		$params['cancelurl'] = mp_checkout_step_url('checkout') . '/?' . 'epay_cancel';
		$params['callbackurl'] = $this->ipn_url;
		$params['ownreceipt'] = "1";
		
		$totals = array();
		$product_count = 0;
		
		foreach($cart as $product_id => $variations)
		{
			foreach($variations as $data)
			{
				//we're sending tax included prices here if tax included is on
				$totals[] = $data['price'] * $data['quantity'];
				$product_count++;
			}
		}
		
		$total = array_sum($totals);
		
		if($coupon = $mp->coupon_value($mp->get_coupon_code(), $total))
			$total = $coupon['new_total'];
		
		//shipping line
		if(($shipping_price = $mp->shipping_price()) !== false)
		{
			if($mp->get_setting('tax->tax_inclusive'))
				$shipping_price = $mp->shipping_tax_price($shipping_price);
			
			$total = $total + $shipping_price;
		}
		
		//tax line if tax inclusive pricing is off. It it's on it would screw up the totals
		if(!$mp->get_setting('tax->tax_inclusive') && ($tax_price = $mp->tax_price()) !== false)
		{
			$total = $total + $tax_price;
		}
		
		$params['amount'] = $total * 100;
		
		$param_list = array();
		
		foreach($params as $k => $v)
		{
			$param_list[] = "{$k}=" . rawurlencode($v);
		}
		
		$param_list[] = "hash=" . md5(implode("", array_values($params)) + $this->epay_md5key);
		
		$param_str = implode('&', $param_list);
		
		wp_redirect("{$url}?{$param_str}");
		
		exit(0);
	}
	
	/**
	* Filters the order confirmation email message body. You may want to append something to
	*  the message. Optional
	*
	* Don't forget to return!
	*/
	function order_confirmation_email($msg, $order)
	{
		return $msg;
	}
	
	/**
	* Return any html you want to show on the confirmation screen after checkout. This
	*  should be a payment details box and message.
	*
	* Don't forget to return!
	*/
	function order_confirmation_msg($content, $order)
	{
		global $mp;
		if($order->post_status == 'order_received')
		{
			$content .= '<p>' . sprintf(__('Your payment via ePay for this order totaling %s is not yet complete. Here is the latest status:', 'mp'), $mp->format_currency($order->mp_payment_info['currency'], $order->mp_payment_info['total'])) . '</p>';
			$statuses = $order->mp_payment_info['status'];
			krsort($statuses); //sort with latest status at the top
			$status = reset($statuses);
			$timestamp = key($statuses);
			$content .= '<p><strong>' . $mp->format_date($timestamp) . ':</strong> ' . esc_html($status) . '</p>';
		}
		else
		{
			$content .= '<p>' . sprintf(__('Your payment via ePay for this order totaling %s is complete. The transaction number is <strong>%s</strong>.', 'mp'), $mp->format_currency($order->mp_payment_info['currency'], $order->mp_payment_info['total']), $order->mp_payment_info['transaction_id']) . '</p>';
		}
		return $content;
	}
	
	/**
	* Runs before page load incase you need to run any scripts before loading the success message page
	*/
	function order_confirmation($order)
	{
		global $mp;
		
		$timestamp = time();
		$total = $_REQUEST['amount'] / 100;
		
		$params = $_GET;
		$var = "";
		
		foreach($params as $key => $value)
		{
			if($key != "hash")
			{
				$var .= $value;
			}
		}
		
		$genstamp = md5($var . $this->epay_md5key);
		
		if($genstamp == $_GET["hash"])
		{
			$status = __('The order has been received', 'mp');
			$paid = apply_filters('mp_twocheckout_post_order_paid_status', true);
			
			$payment_info['gateway_public_name'] = $this->public_name;
			$payment_info['gateway_private_name'] = $this->admin_name;
			$payment_info['status'][$timestamp] = __("Paid", 'mp');
			$payment_info['total'] = $_GET['amount'] / 100;
			$payment_info['currency'] = $mp->get_setting('currency');
			$payment_info['transaction_id'] = $_GET['txnid'];
			$payment_info['method'] = "Credit Card";
			
			$order = $mp->create_order($_SESSION['mp_order'], $mp->get_cart_contents(), $_SESSION['mp_shipping_info'], $payment_info, $paid);
		}
	}
	
	/**
	* Echo a settings meta box with whatever settings you need for you gateway.
	*  Form field names should be prefixed with mp[gateways][plugin_name], like "mp[gateways][plugin_name][mysetting]".
	*  You can access saved settings via $settings array.
	*/
	function gateway_settings_box($settings)
	{
		global $mp;
		$settings = get_option('mp_settings');
		?>
		<div id="mp_2checkout" class="postbox">
		<h3 class='handle'><span><?php _e('ePay Settings', 'mp'); ?></span></h3>
			<div class="inside">
				<table class="form-table">
					<tr>
					<th scope="row"><?php _e('Merchant number', 'mp') ?></th>
						<td>
							<p>
								<input type="text" name="mp[gateways][epay][epay_merchantnumber]" value="<?php echo $settings['gateways']['epay']['epay_merchantnumber'] ?>"> 
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php _e('Window ID', 'mp') ?></th>
						<td>
							<p>
							<input type="text" name="mp[gateways][epay][epay_windowid]" value="<?php echo $settings['gateways']['epay']['epay_windowid'] ?>"> 
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php _e('MD5 Key', 'mp') ?></th>
						<td>
							<p>
								<input type="text" name="mp[gateways][epay][epay_md5key]" value="<?php echo $settings['gateways']['epay']['epay_md5key'] ?>"> 
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php _e('Instant capture', 'mp') ?></th>
						<td>
							<p>
								<input type="checkbox" name="mp[gateways][epay][epay_instantcapture]" value="1" <?php echo ($settings['gateways']['epay']['epay_instantcapture'] == "1") ? "checked=\"checked\"" : "" ?>> 
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php _e('Group', 'mp') ?></th>
						<td>
							<p>
								<input type="text" name="mp[gateways][epay][epay_group]" value="<?php echo $settings['gateways']['epay']['epay_group'] ?>"> 
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php _e('Auth Mail', 'mp') ?></th>
						<td>
							<p>
								<input type="text" name="mp[gateways][epay][epay_authmail]" value="<?php echo $settings['gateways']['epay']['epay_authmail'] ?>"> 
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php _e('Auth SMS', 'mp') ?></th>
						<td>
							<p>
								<input type="text" name="mp[gateways][epay][epay_authsms]" value="<?php echo $settings['gateways']['epay']['epay_authsms'] ?>"> 
							</p>
						</td>
					</tr>
				</table>
			</div>
		</div>
		<?php
	}
	
	/**
	* Filters posted data from your settings form. Do anything you need to the $settings['gateways']['plugin_name']
	*  array. Don't forget to return!
	*/
	function process_gateway_settings($settings)
	{
		return $settings;
	}
	
	/**
	* INS and payment return
	*/
	function process_ipn_return()
	{
		global $mp;
		
		$settings = get_option('mp_settings');
		
		$params = $_GET;
		$var = "";
		
		foreach($params as $key => $value)
		{
			if($key != "hash")
			{
				$var .= $value;
			}
		}
		
		$genstamp = md5($var . $this->epay_md5key);
		
		if($genstamp == $_GET["hash"])
		{
			$order = $mp->get_order($_GET["orderid"]);
			
			$payment_info = $order->mp_payment_info;
			$payment_info['transaction_id'] = $_GET["txnid"];
			
			update_post_meta($order->ID, 'mp_payment_info', $payment_info);
			
			$mp->update_order_payment_status($_GET["orderid"], "paid", true);
			
			header('HTTP/1.0 200 OK');
			header('Content-type: text/plain; charset=UTF-8');
			exit(0);
		}
	}
}

//register payment gateway plugin
mp_register_gateway_plugin('MP_Gateway_EPay', 'epay', __('ePay / Payment Solutions', 'mp'));
?>