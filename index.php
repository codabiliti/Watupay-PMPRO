<?php
/**
 * Plugin Name: Watupay for Paid Membership Pro
 * Description: Watupay Payment Gateway for Paid Membership Pro
 * Author: Omaa
 * Version: 1.0.0
 * Plugin URI: https://digitar.link
 * Author URI: https://twitter.com/yojobless
 * License: GPLv2 or later
 **/
defined('ABSPATH') or die('No script kiddies please!');
if (!function_exists('watupay_Pmp_Gateway_load')) {
    add_action('plugins_loaded', 'watupay_Pmp_Gateway_load', 20);

    DEFINE('WATUPAYPMP', "pmpro");

 function watupay_Pmp_Gateway_load()
    {
        // paid membership pro required
        if (!class_exists('PMProGateway')) {
            return;
        }
    //load classes init method
    add_action('init', array('PMProGateway_watupay', 'init'));

    // plugin links
    add_filter('plugin_action_links', array('PMProGateway_watupay', 'plugin_action_links'), 10, 2);

	/**
	 * PMProGateway_gatewayname Class
	 *
	 * Handles watupay integration.
	 *
	 */
	class PMProGateway_watupay extends PMProGateway
	{
		function PMProGateway($gateway = NULL)
		{
            $this->gateway = $gateway;
            $this->id = 'watu-pay'; // payment gateway plugin ID
            return $this->gateway;
		}										

		/**
		 * Run on WP init
		 *
		 * @since 1.8
		 */
        static function init()
        {
			//make sure watupay is a gateway option
			add_filter('pmpro_gateways', array('PMProGateway_watupay', 'pmpro_gateways'));

			//add fields to payment settings
			add_filter('pmpro_payment_options', array('PMProGateway_watupay', 'pmpro_payment_options'));
            add_filter('pmpro_payment_option_fields', array('PMProGateway_watupay', 'pmpro_payment_option_fields'), 10, 2);
            add_action('wp_ajax_pmpro_watupay_ipn', array('PMProGateway_watupay', 'pmpro_watupay_ipn'));
            add_action('wp_ajax_nopriv_pmpro_watupay_ipn', array('PMProGateway_watupay', 'pmpro_watupay_ipn'));

			//add some fields to edit user page (Updates)
			add_action('pmpro_after_membership_level_profile_fields', array('PMProGateway_watupay', 'user_profile_fields'));
			add_action('profile_update', array('PMProGateway_watupay', 'user_profile_fields_save'));

			//updates cron
			add_action('pmpro_activation', array('PMProGateway_watupay', 'pmpro_activation'));
			add_action('pmpro_deactivation', array('PMProGateway_watupay', 'pmpro_deactivation'));
			add_action('pmpro_cron_watupay_recurrent_updates', array('PMProGateway_watupay', 'pmpro_cron_watupay_recurrent_updates'));

			//code to add at checkout if watupay is the current gateway
			$gateway = pmpro_getOption("gateway");
			if($gateway == "watupay")
			{
				
                add_filter('pmpro_include_billing_address_fields', '__return_false');
                add_filter('pmpro_required_billing_fields', array('PMProGateway_watupay', 'pmpro_required_billing_fields'));
                add_filter('pmpro_include_payment_information_fields', '__return_false');
                add_filter('pmpro_checkout_before_change_membership_level', array('PMProGateway_watupay', 'pmpro_checkout_before_change_membership_level'), 10, 2);

                add_filter('pmpro_gateways_with_pending_status', array('PMProGateway_watupay', 'pmpro_gateways_with_pending_status'));
                add_filter('pmpro_pages_shortcode_checkout', array('PMProGateway_watupay', 'pmpro_pages_shortcode_checkout'), 20, 1);
                add_filter('pmpro_checkout_default_submit_button', array('PMProGateway_watupay', 'pmpro_checkout_default_submit_button'));

                // custom confirmation page
                add_filter('pmpro_pages_shortcode_confirmation', array('PMProGateway_watupay', 'pmpro_pages_shortcode_confirmation'), 20, 1);
			}
		}

		        /**
                 * Make sure Watupay is in the gateways list
                 */
                static function pmpro_gateways($gateways)
                {
                    if (empty($gateways['watupay'])) {
                        $gateways = array_slice($gateways, 0, 1) + array("watupay" => __('Watupay', WATUPAYPMP)) + array_slice($gateways, 1);
                    }
                    return $gateways;
                }

                /**
                 * Redirect Settings to PMPro settings
                 */
                static function plugin_action_links($links, $file)
                {
                    static $this_plugin;

                    if (false === isset($this_plugin) || true === empty($this_plugin)) {
                        $this_plugin = plugin_basename(__FILE__);
                    }

                    if ($file == $this_plugin) {
                        $settings_link = '<a href="'.admin_url('admin.php?page=pmpro-paymentsettings').'">'.__('Settings', WATUPAYPMP).'</a>';
                        array_unshift($links, $settings_link);
                    }

                    return $links;
                }
                    static function pmpro_checkout_default_submit_button($show)
                {
                    global $gateway, $pmpro_requirebilling;

                    //show our submit buttons
                    ?>
                    <span id="pmpro_submit_span">
                    <input type="hidden" name="submit-checkout" value="1" />
                    <input type="submit" class="pmpro_btn pmpro_btn-submit-checkout" value="<?php if ($pmpro_requirebilling) { _e('Check Out with Watupay', 'pmpro'); } else { _e('Submit and Confirm', 'pmpro');}?> &raquo;" />
                    </span>
                    <?php

                    //don't show the default
                    return false;
                }
                
                function pmprosd_convert_date( $date ) {
                    // handle lower-cased y/m values.
                    $set_date = strtoupper($date);
                
                    // Change "M-" and "Y-" to "M1-" and "Y1-".
                    $set_date = preg_replace('/Y-/', 'Y1-', $set_date);
                    $set_date = preg_replace('/M-/', 'M1-', $set_date);
                
                    // Get number of months and years to add.
                    $m_pos = stripos( $set_date, 'M' );
                    $y_pos = stripos( $set_date, 'Y' );
                    if($m_pos !== false) {
                        $add_months = intval( pmpro_getMatches( '/M([0-9]*)/', $set_date, true ) );		
                    }
                    if($y_pos !== false) {
                        $add_years = intval( pmpro_getMatches( '/Y([0-9]*)/', $set_date, true ) );
                    }
                
                    // Allow new dates to be set from a custom date.
                    if(empty($current_date)) $current_date = current_time( 'timestamp' );
                
                    // Get current date parts.
                    $current_y = intval(date('Y', $current_date));
                    $current_m = intval(date('m', $current_date));
                    $current_d = intval(date('d', $current_date));
                
                    // Get set date parts.
                    $date_parts = explode( '-', $set_date);
                    $set_y = intval($date_parts[0]);
                    $set_m = intval($date_parts[1]);
                    $set_d = intval($date_parts[2]);
                
                    // Get temporary date parts.
                    $temp_y = $set_y > 0 ? $set_y : $current_y;
                    $temp_m = $set_m > 0 ? $set_m : $current_m;
                    $temp_d = $set_d;
                
                    // Add months.
                    if(!empty($add_months)) {
                        for($i = 0; $i < $add_months; $i++) {
                            // If "M1", only add months if current date of month has already passed.
                            if(0 == $i) {
                                if($temp_d < $current_d) {
                                    $temp_m++;
                                    $add_months--;
                                }
                            } else {
                                $temp_m++;
                            }
                
                            // If we hit 13, reset to Jan of next year and subtract one of the years to add.
                            if($temp_m == 13) {
                                $temp_m = 1;
                                $temp_y++;
                                $add_years--;
                            }
                        }
                    }
                
                    // Add years.
                    if(!empty($add_years)) {
                        for($i = 0; $i < $add_years; $i++) {
                            // If "Y1", only add years if current date has already passed.
                            if(0 == $i) {
                                $temp_date = strtotime(date("{$temp_y}-{$temp_m}-{$temp_d}"));
                                if($temp_date < $current_date) {
                                    $temp_y++;
                                    $add_years--;
                                }
                            } else {
                                $temp_y++;
                            }
                        }
                    }
                
                    // Pad dates if necessary.
                    $temp_m = str_pad($temp_m, 2, '0', STR_PAD_LEFT);
                    $temp_d = str_pad($temp_d, 2, '0', STR_PAD_LEFT);
                
                    // Put it all together.
                    $set_date = date("{$temp_y}-{$temp_m}-{$temp_d}");
                
                    // Make sure we use the right day of the month for dates > 28
                    // From: http://stackoverflow.com/a/654378/1154321
                    $dotm = pmpro_getMatches('/\-([0-3][0-9]$)/', $set_date, true);
                    if ( $temp_m == '02' && intval($dotm) > 28 || intval($dotm) > 30 ) {
                        $set_date = date('Y-m-t', strtotime(substr($set_date, 0, 8) . "01"));
                    }
                
                  
                    
                    return $set_date;
                }

                function pmpro_watupay_ipn()
                {
                    global $wpdb;
                    // if ((strtoupper($_SERVER['REQUEST_METHOD']) != 'POST' ) || !array_key_exists('HTTP_X_WATUPAY_SIGNATURE', $_SERVER) ) {
                    //     exit();
                    // }
                    define('SHORTINIT', true);
                    $input = @file_get_contents("php://input");
                    $event = json_decode($input);
                    // echo "<pre>";
                    // print_r($event);
                    // if(!$_SERVER['HTTP_X_WATUPAY_SIGNATURE'] || ($_SERVER['HTTP_X_WATUPAY_SIGNATURE'] !== hash_hmac('sha512', $input, watupay_recurrent_billing_get_secret_key()))){
                    //   exit();
                    // }
                    switch($event->event){
                    case 'recurrent.create':

                        break;
                    case 'recurrent.disable':
                        $amount = $event->data->recurrent->amount/100;
                        $morder = new MemberOrder();
                        $recurrent_code = $event->data->recurrent_code;
                        $email = $event->data->customer->email;
                        $morder->Email = $email;
                        $users_row = $wpdb->get_row( "SELECT ID, display_name FROM $wpdb->users WHERE user_email = '" . $email. "' LIMIT 1" );
                        if ( ! empty( $users_row )  ) {
                            $user_id = $users_row->ID;
                            $user = get_userdata($user_id);
                            $user->membership_level = pmpro_getMembershipLevelForUser($user_id);
                        }
                        if (empty($user)) {
                            print_r('Could not get user');
                            exit();
                        }
                        self::cancelMembership($user);
                        break;
                    case 'charge.success':
                        $morder =  new MemberOrder($event->data->reference);
                        $morder->getMembershipLevel();
                        $morder->getUser();
                        $morder->Gateway->pmpro_pages_shortcode_confirmation('', $event->data->reference);
                        $mode = pmpro_getOption("gateway");
                        if ($mode == 'testmode') {
                            $key = pmpro_getOption("watupay_test_public_key");
                        } else {
                            $key = pmpro_getOption("watupay_live_public_key");
                        }
                        break;
                    case 'invoice.create':
                        self::renewpayment($event);
                    case 'invoice.update':
                        self::renewpayment($event);
                  
                    }
                    http_response_code(200);
                    exit();
                }
                /**
                 * Get a list of payment options that the watupay gateway needs/supports.
                 */
                static function getGatewayOptions()
                {
                    $options = array (
                        'watupay_live_public_key',
                        'watupay_test_public_key',
                        'gateway',
                        'currency',
                        'tax_state',
                        'tax_rate'
                        );

                    return $options;
                }
                /**
                 * Set payment options for payment settings page.
                 */
                static function pmpro_payment_options($options)
                {
                    //get Watupay options
                    $watupay_options = self::getGatewayOptions();

                    //merge with others.
                    $options = array_merge($watupay_options, $options);

                    return $options;
                }
                /**
                 * Display fields for Watupay options.
                 */
                static function pmpro_payment_option_fields($values, $gateway)
                {
                    ?>
                    <tr class="pmpro_settings_divider gateway gateway_watupay" <?php if($gateway != "watupay") { ?>style="display: none;"<?php } ?>>
                        <td colspan="2">
                            <?php _e('Watupay API Keys Configuration', 'pmpro'); ?>
                        </td>
                    </tr>
                    <tr class="gateway gateway_watupay" <?php if($gateway != "watupay") { ?>style="display: none;"<?php } ?>>
                        <th scope="row" valign="top">
                            <label><?php _e('Webhook', 'pmpro');?>:</label>
                        </th>
                        <td>
                            <p><?php _e('To fully integrate with Watupay, be sure to use the following for your Webhook URL', 'pmpro');?> <pre><?php echo admin_url("admin-ajax.php") . "?action=pmpro_watupay_ipn";?></pre></p>

                        </td>
                    </tr>
                    <tr class="gateway gateway_watupay" <?php if($gateway != "watupay") { ?>style="display: none;"<?php } ?>>
                        <th scope="row" valign="top">
                            <label for="watupay_live_public_key"><?php _e('Live Public Key', 'pmpro');?>:</label>
                        </th>
                        <td>
                            <input type="text" id="watupay_live_public_key" name="watupay_live_public_key" size="60" value="<?php echo esc_attr($values['watupay_live_public_key'])?>" />
                        </td>
                    </tr>
                    <tr class="gateway gateway_watupay" <?php if($gateway != "watupay") { ?>style="display: none;"<?php } ?>>
                        <th scope="row" valign="top">
                            <label for="watupay_test_public_key"><?php _e('Test Public Key', 'pmpro');?>:</label>
                        </th>
                        <td>
                            <input type="text" id="watupay_test_public_key" name="watupay_test_public_key" size="60" value="<?php echo esc_attr($values['watupay_test_public_key'])?>" />
                        </td>
                    </tr>
                   

                    <?php
                }
                /**
                 * Remove required billing fields
                 */
                static function pmpro_required_billing_fields($fields)
                {
                    unset($fields['bfirstname']);
                    unset($fields['blastname']);
                    unset($fields['baddress1']);
                    unset($fields['bcity']);
                    unset($fields['bstate']);
                    unset($fields['bzipcode']);
                    unset($fields['bphone']);
                    unset($fields['bemail']);
                    unset($fields['bcountry']);
                    unset($fields['CardType']);
                    unset($fields['AccountNumber']);
                    unset($fields['ExpirationMonth']);
                    unset($fields['ExpirationYear']);
                    unset($fields['CVV']);

                    return $fields;
                }
                static function pmpro_gateways_with_pending_status($gateways) {
                    $morder = new MemberOrder();
                    $found = $morder->getLastMemberOrder(get_current_user_id(), apply_filters("pmpro_confirmation_order_status", array("pending")));

                    if ((!in_array("watupay", $gateways)) && $found) {
                        array_push($gateways, "watupay");
                    } elseif (($key = array_search("watupay", $gateways)) !== false) {
                        unset($gateways[$key]);
                    }

                    return $gateways;
                }
                /**
                 * Instead of change membership levels, send users to Watupay payment page.
                 */
                static function pmpro_checkout_before_change_membership_level($user_id, $morder)
                {
                    global $wpdb, $discount_code_id;

                    //if no order, no need to pay
                    if (empty($morder)) {
                        return;
                    }
                    if (empty($morder->code))
                        $morder->code = $morder->getRandomCode();

                    $morder->payment_type = "watupay";
                    $morder->status = "pending";
                    $morder->user_id = $user_id;
                    $morder->saveOrder();

                    //save discount code use
                    if (!empty($discount_code_id))
                        $wpdb->query("INSERT INTO $wpdb->pmpro_discount_codes_uses (code_id, user_id, order_id, timestamp) VALUES('" . $discount_code_id . "', '" . $user_id . "', '" . $morder->id . "', now())");

                    $morder->Gateway->sendToWatupay($morder);
                }

                function sendToWatupay(&$order)
                {
                    global $wp;

                    do_action("pmpro_paypalexpress_session_vars");

                    $params = array();
                    $amount = $order->PaymentAmount;
                    $amount_tax = $order->getTaxForPrice($amount);
                    $amount = round((float)$amount + (float)$amount_tax, 2);

                    //call directkit to get Webkit Token
                    $amount = floatval($order->InitialPayment);

                    // echo pmpro_url("confirmation", "?level=" . $order->membership_level->id);
                    // die();
                    $mode = pmpro_getOption("gateway");
                    if ($mode == 'testmode') {
                        $key = pmpro_getOption("watupay_test_public_key");
                    } else {
                        $key = pmpro_getOption("watupay_live_public_key");
                    }
                    if ($key  == '') {
                        echo "Api Keys not set";
                    }

                    // $txn_code = $txn.'_'.$order_id;

                    $koboamount = $amount*100;
                    // $mcurrency =     

                    // foreach($level_currencies as $level_currency_id => $level_currency)
                    // {
                    // if($level_id == $level_currency_id)
                    // {
                    // $pmpro_currency = $level_currency[0];
                    // $pmpro_currency_symbol = $level_currency[1];

                    // }
                    // }
                $watupay_url = 'https://api.watu.global/v1/payment/initiate';
                    $headers = array(
                        'Accept'=>'application/json',
                        'Content-Type'  => 'application/json',
                        'Authorization' => 'Bearer '.$key
                    );
                  
                    //Create Plan
               $body = array(
                'email'        => $order->Email,
                'amount'       => $koboamount,
                'reference'    => $order->code,
                'currency'     => $currency,
                'service_type' => $this->id,
                'callback_url' => pmpro_url("confirmation", "?level=" . $order->membership_level->id),
                'metadata' => json_encode(array('custom_fields' => array(
                    array(
                        "display_name"=>"Plugin",
                        "variable_name"=>"plugin",
                        "value"=>"pm-pro"
                    ),
                    
                ), 'custom_filters' => array("recurring" => true))),

            );
                    $args = array(
                        'body'      => json_encode($body),
                        'headers'   => $headers,
                        'timeout'   => 60
                    );

            $request = wp_remote_post($watupay_url, $args);
                    // print_r($request);
                    if (!is_wp_error($request)) {
                        $watupay_response = json_decode(wp_remote_retrieve_body($request));
                        if ($watupay_response->status){
                            $url = $watupay_response->data->authorization_url;
                            wp_redirect($url);
                            exit;
                        } else {
                            $order->Gateway->delete($order);
                            wp_redirect(pmpro_url("checkout", "?level=" . $order->membership_level->id . "&error=" . $watupay_response->message));
                            exit();
                        }
                    } else {
                        $order->Gateway->delete($order);
                        wp_redirect(pmpro_url("checkout", "?level=" . $order->membership_level->id . "&error=Failed"));
                        exit();
                    }
                    exit;
                }
                static function renewpayment($event)
                {
                    global $wp,$wpdb;

                    if (isset($event->data->paid) && ($event->data->paid == 1)) {

                        $amount = $event->data->recurrent->amount/100;
                        $old_order = new MemberOrder();
                        $recurrent_code = $event->data->recurrent->recurrent_code;
                        $email = $event->data->customer->email;
                        $old_order->getLastMemberOrderByRecurrentTransactionID($recurrent_code);

                        if (empty($old_order)) {
                            exit();
                        }
                        $user_id = $old_order->user_id;
                        $user = get_userdata($user_id);
                        $user->membership_level = pmpro_getMembershipLevelForUser($user_id);

                        if (empty($user)) {
                            exit();
                        }

                        $morder = new MemberOrder();
                        $morder->user_id = $old_order->user_id;
                        $morder->membership_id = $old_order->membership_id;
                        $morder->InitialPayment = $amount;  //not the initial payment, but the order class is expecting this
                        $morder->PaymentAmount = $amount;
                        $morder->payment_transaction_id = $event->data->invoice_code;
                        $morder->recurrent_transaction_id = $recurrent_code;

                        $morder->gateway = $old_order->gateway;
                        $morder->gateway = $old_order->gateway;

                        $morder->Email = $email;
                        $pmpro_level = $wpdb->get_row("SELECT * FROM $wpdb->pmpro_membership_levels WHERE id = '" . (int)$morder->membership_id . "' LIMIT 1");
                        $pmpro_level = apply_filters("pmpro_checkout_level", $pmpro_level);
                        $startdate = apply_filters("pmpro_checkout_start_date", "'" . current_time("mysql") . "'", $morder->user_id, $pmpro_level);

                        $enddate = "'" . date("Y-m-d", strtotime("+ " . $pmpro_level->expiration_number . " " . $pmpro_level->expiration_period, current_time("timestamp"))) . "'";

                        $custom_level = array(
                            'user_id'           => $morder->user_id,
                            'membership_id'     => $pmpro_level->id,
                            'code_id'           => '',
                            'initial_payment'   => $pmpro_level->initial_payment,
                            'billing_amount'    => $pmpro_level->billing_amount,
                            'cycle_number'      => $pmpro_level->cycle_number,
                            'cycle_period'      => $pmpro_level->cycle_period,
                            'billing_limit'     => $pmpro_level->billing_limit,
                            'trial_amount'      => $pmpro_level->trial_amount,
                            'trial_limit'       => $pmpro_level->trial_limit,
                            'startdate'         => $startdate,
                            'enddate'           => $enddate
                        );

                        //get CC info that is on file
                        $morder->expirationmonth = get_user_meta($user_id, "pmpro_ExpirationMonth", true);
                        $morder->expirationyear = get_user_meta($user_id, "pmpro_ExpirationYear", true);
                        $morder->ExpirationDate = $morder->expirationmonth . $morder->expirationyear;
                        $morder->ExpirationDate_YdashM = $morder->expirationyear . "-" . $morder->expirationmonth;


                        //save
                        if ($morder->status != 'success') {

                            $_REQUEST['cancel_membership'] = false; // Do NOT cancel gateway recurrent

                            if (pmpro_changeMembershipLevel($custom_level, $morder->user_id, 'changed')) {
                                $morder->status = "success";
                                $morder->saveOrder();
                            }

                        }
                        $morder->getMemberOrderByID($morder->id);

                        //email the user their invoice
                        $pmproemail = new PMProEmail();
                        $pmproemail->sendInvoiceEmail($user, $morder);

                        do_action('pmpro_recurrent_payment_completed', $morder);
                        exit();
                    }

                }
                static function pmpro_pages_shortcode_checkout($content)
                {
                    $morder = new MemberOrder();
                    $found = $morder->getLastMemberOrder(get_current_user_id(), apply_filters("pmpro_confirmation_order_status", array("pending")));
                    if ($found) {
                        $morder->Gateway->delete($morder);
                    }

                    if (isset($_REQUEST['error'])) {
                        global $pmpro_msg, $pmpro_msgt;

                        $pmpro_msg = __("IMPORTANT: Something went wrong during the payment. Please try again later or contact the site support to fix this issue.<br/>" . urldecode($_REQUEST['error']), "pmpro");
                        $pmpro_msgt = "pmpro_error";

                        $content = "<div id='pmpro_message' class='pmpro_message ". $pmpro_msgt . "'>" . $pmpro_msg . "</div>" . $content;
                    }

                    return $content;
                }
                
                /**
                 * Custom confirmation page
                 */
                static function pmpro_pages_shortcode_confirmation($content,$reference = null)
                {
                    global $wpdb, $current_user, $pmpro_invoice, $pmpro_currency,$gateway;
                    if (!isset($_REQUEST['trxref'])) {
                        $_REQUEST['trxref'] = null;
                    }
                    if ($reference != null) {
                        $_REQUEST['trxref'] = $reference;
                    }
                   

                    if (empty($pmpro_invoice)) {
                        $morder =  new MemberOrder($_REQUEST['trxref']);
                        // $morder = new MemberOrder();
                        // $morder->getLastMemberOrder(get_current_user_id(), apply_filters("pmpro_confirmation_order_status", array("pending", "success")));
                        if (!empty($morder) && $morder->gateway == "watupay") $pmpro_invoice = $morder;
                    }

                    if (!empty($pmpro_invoice) && $pmpro_invoice->gateway == "watupay" && isset($pmpro_invoice->total) && $pmpro_invoice->total > 0) {
                            $morder = $pmpro_invoice;
                        if ($morder->code == $_REQUEST['trxref']) {
                            $pmpro_level = $wpdb->get_row("SELECT * FROM $wpdb->pmpro_membership_levels WHERE id = '" . (int)$morder->membership_id . "' LIMIT 1");
                            $pmpro_level = apply_filters("pmpro_checkout_level", $pmpro_level);
                            $startdate = apply_filters("pmpro_checkout_start_date", "'" . current_time("mysql") . "'", $morder->user_id, $pmpro_level);

                            $mode = pmpro_getOption("gateway");
                            if ($mode == "testmode") {
                                $key = pmpro_getOption("watupay_test_public_key");
                            } else {
                                $key = pmpro_getOption("watupay_live_public_key");
                            }
                            $watupay_url = 'https://api.watu.global/v1/transaction/verify/' . $_REQUEST['trxref'];
                            $headers = array(
                                'Accept'=>'application/json',
                                'Content-Type'  => 'application/json',
                                'Authorization' => 'Bearer '.$key
                            );
                            $args = array(
                                'headers'   => $headers,
                                'timeout'   => 60
                            );
                            $request = wp_remote_get($watupay_url, $args);
                            if (!is_wp_error($request) && 200 == wp_remote_retrieve_response_code($request) ) {
                                $watupay_response = json_decode(wp_remote_retrieve_body($request));
                                if ('success' == $watupay_response->data->status && $pmpro_level->initial_payment ==  ($watupay_response->data->amount/100)) {
                                    $customer_code = $watupay_response->data->customer->customer_code;

                                    do_action('pmpro_after_checkout', $morder->user_id, $morder);
                                    //--------------------------------------------------
                                    
                                    if (strlen($morder->recurrent_transaction_id) > 3) {
                                        $enddate = "'" . date("Y-m-d", strtotime("+ " . $morder->recurrent_transaction_id, current_time("timestamp"))) . "'";
                                    } elseif (!empty($pmpro_level->expiration_number)) {
                                        $enddate = "'" . date("Y-m-d", strtotime("+ " . $pmpro_level->expiration_number . " " . $pmpro_level->expiration_period, current_time("timestamp"))) . "'";
                                    } else {
                                        $enddate = "NULL";
                                    }
                                    if (($pmpro_level->cycle_number > 0) && ($pmpro_level->billing_amount > 0) && ($pmpro_level->cycle_period != "")) {
                                        if ($pmpro_level->cycle_number < 10 && $pmpro_level->cycle_period == 'Day') {
                                            $interval = 'weekly';
                                        } elseif (($pmpro_level->cycle_number == 90) && ($pmpro_level->cycle_period == 'Day')) {
                                            $interval = 'quarterly';
                                        }
                                        elseif (($pmpro_level->cycle_number == 180) && ($pmpro_level->cycle_period == 'Day')) {
                                            $interval = 'biannually';
                                        }
                                         elseif (($pmpro_level->cycle_number >= 10) && ($pmpro_level->cycle_period == 'Day')) {
                                            $interval = 'monthly';
                                        } elseif (($pmpro_level->cycle_number == 3) && ($pmpro_level->cycle_period == 'Month')) {
                                            $interval = 'quarterly';
                                        
                                        }
                                        elseif (($pmpro_level->cycle_number == 6) && ($pmpro_level->cycle_period == 'Month')) {
                                            $interval = 'biannually';
                                        
                                        }
                                        elseif (($pmpro_level->cycle_number > 0) && ($pmpro_level->cycle_period == 'Month')) {
                                            $interval = 'monthly';
                                        } elseif (($pmpro_level->cycle_number > 0) && ($pmpro_level->cycle_period == 'Year')) {
                                            $interval = 'annually';
                                        }

                                        $amount = $pmpro_level->billing_amount;
                                        $koboamount = $amount*100;
                                        //Create Plan
                                        $watupay_url = 'https://api.watu.global/v1/payment/charge';
                                        $recurrent_url = 'https://api.watu.global/v1/payment/charge/recurrent';
                                        $check_url = 'https://api.watu.global/v1/payment/charge?amount='.$koboamount.'&interval='.$interval;
                                        $headers = array(
                                            'Accept'=>'application/json',
                                            'Content-Type'  => 'application/json',
                                            'Authorization' => 'Bearer '.$key
                                        );

                                        $checkargs = array(
                                            'headers' => $headers,
                                            'timeout' => 60
                                        );
                                        // Check if plan exist
                                        $checkrequest = wp_remote_get($check_url, $checkargs);
                                        if (!is_wp_error($checkrequest)) {
                                            $response = json_decode(wp_remote_retrieve_body($checkrequest));
                                            if ($response->meta->total >= 1) {
                                                $plan = $response->data[0];
                                                $plancode = $plan->plan_code;

                                            } else {
                                                //Create Plan
                                                $body = array(
                                                    'name'      => '('.number_format($amount).') - '.$interval.' - ['.$pmpro_level->cycle_number.' - '.$pmpro_level->cycle_period.']' ,
                                                    'amount'    => $koboamount,
                                                    'interval'  => $interval
                                                );
                                                $args = array(
                                                    'body'      => json_encode($body),
                                                    'headers'   => $headers,
                                                    'timeout'   => 60
                                                );

                                                $request = wp_remote_post($watupay_url, $args);
                                                if (!is_wp_error($request)) {
                                                    $watupay_response = json_decode(wp_remote_retrieve_body($request));
                                                    $plancode = $watupay_response->data->plan_code;
                                                }
                                            }

                                        }
                                        $recurrent_delay = get_option( 'pmpro_recurrent_delay_' . $pmpro_level->id, 0 );
                                        if($recurrent_delay)
                                        if ( ! is_numeric( $recurrent_delay ) ) {
                                            $start_date = pmprosd_convert_date( $recurrent_delay );
                                        } else {
                                            $start_date = date( 'Y-m-d', strtotime( '+ ' . intval( $recurrent_delay ) . ' Days', current_time( 'timestamp' ) ) );
                                        }
                                        
                                        $body = array(
                                            'customer'  => $customer_code,
                                            'plan'      => $plancode,
                                            'start_date' => $start_date
                                        );
                                        $args = array(
                                            'body'      => json_encode($body),
                                            'headers'   => $headers,
                                            'timeout'   => 60
                                        );

                                        $request = wp_remote_post($recurrent_url, $args);
                                        if (!is_wp_error($request)) {
                                            $watupay_response = json_decode(wp_remote_retrieve_body($request));
                                            $recurrent_code = $watupay_response->data->recurrent_code;
                                            $token = $watupay_response->data->email_token;
                                            $morder->recurrent_transaction_id = $recurrent_code;
                                            $morder->recurrent_token = $token;
										
                                           

                                        }

                                    }
                                    // die();

                                    $custom_level = array(
                                            'user_id'           => $morder->user_id,
                                            'membership_id'     => $pmpro_level->id,
                                            'code_id'           => '',
                                            'initial_payment'   => $pmpro_level->initial_payment,
                                            'billing_amount'    => $pmpro_level->billing_amount,
                                            'cycle_number'      => $pmpro_level->cycle_number,
                                            'cycle_period'      => $pmpro_level->cycle_period,
                                            'billing_limit'     => $pmpro_level->billing_limit,
                                            'trial_amount'      => $pmpro_level->trial_amount,
                                            'trial_limit'       => $pmpro_level->trial_limit,
                                            'startdate'         => $startdate,
                                            'enddate'           => $enddate
                                        );
                                    if ($morder->status != 'success') {

                                        $_REQUEST['cancel_membership'] = false; // Do NOT cancel gateway recurrent

                                        if (pmpro_changeMembershipLevel($custom_level, $morder->user_id, 'changed')) {
                                            $morder->membership_id = $pmpro_level->id;
                                            $morder->payment_transaction_id = $_REQUEST['trxref'];
                                            $morder->status = "success";
                                            $morder->saveOrder();
                                        }

                                    }
                                    // echo "<pre>";
                                    // print_r($morder);
                                    // die();
                                    //setup some values for the emails
                                    if (!empty($morder)) {
                                        $pmpro_invoice = new MemberOrder($morder->id);
                                    } else {
                                        $pmpro_invoice = null;
                                    }

                                    $current_user->membership_level = $pmpro_level; //make sure they have the right level info
                                    $current_user->membership_level->enddate = $enddate;
                                    if ($current_user->ID) {
                                        $current_user->membership_level = pmpro_getMembershipLevelForUser($current_user->ID);
                                        // echo "interesting";
                                    }

                                    //send email to member
                                    $pmproemail = new PMProEmail();
                                    $pmproemail->sendCheckoutEmail($current_user, $pmpro_invoice);

                                    //send email to admin
                                    $pmproemail = new PMProEmail();
                                    $pmproemail->sendCheckoutAdminEmail($current_user, $pmpro_invoice);
                                    // echo "<pre>";
                                    // print_r($pmpro_level);
                                    $content = "<ul>
                                        <li><strong>".__('Account', WATUPAYPMP).":</strong> ".$current_user->display_name." (".$current_user->user_email.")</li>
                                        <li><strong>".__('Order', WATUPAYPMP).":</strong> ".$pmpro_invoice->code."</li>
                                        <li><strong>".__('Membership Level', WATUPAYPMP).":</strong> ".$pmpro_level->name."</li>
                                        <li><strong>".__('Amount Paid', WATUPAYPMP).":</strong> ".$pmpro_invoice->total." ".$pmpro_currency."</li>
                                    </ul>";
                                    ob_start();
                                    if (file_exists(get_stylesheet_directory() . "/paid-memberships-pro/pages/confirmation.php")) {
                                        include get_stylesheet_directory() . "/paid-memberships-pro/pages/confirmation.php";
                                    } else {
                                        include PMPRO_DIR . "/pages/confirmation.php";
                                    }

                                    $content .= ob_get_contents();
                                    ob_end_clean();
                                } else {
                                    $content = 'Invalid Reference';

                                }

                            } else {
                                    $content = 'Unable to Verify Transaction';

                            }

                        } else {
                            $content = 'Invalid Transaction Reference';
                        }
                    }


                    return $content;

                }

                function cancelMembership(&$user){
                    //                  
                    if (empty($user)) {
                        print_r("Empty user object");
                        exit();
                    }
                    $user_id = $user->ID;
                    $level_to_cancel = $user->membership_level->ID;
                    if(empty($user_id) || empty($level_to_cancel)){
                        exit();
                    }
                    global $wpdb;
                    $memberships_users_row = $wpdb->get_row( "SELECT * FROM $wpdb->pmpro_memberships_users WHERE user_id = '" . $user_id. "' AND membership_id = '" . $level_to_cancel . "' AND status = 'active' LIMIT 1" );
                    if ( ! empty( $memberships_users_row )  ) {
					
						$days_grace  = 0;
						$new_enddate = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + 3600 * 24 * $days_grace );
						$result = $wpdb->update( $wpdb->pmpro_memberships_users, array( 'enddate' => $new_enddate ), array(
							'user_id'       => $user_id,
							'membership_id' => $level_to_cancel,
							'status'        => 'active'
						), array( '%s' ), array( '%d', '%d', '%s' ) );
						print_r($result);
					}else{
                        print_r("No records were found with user - ". $user_id." level - ". $level_to_cancel);
                    }
                }
                function cancel(&$order)
                {

                    //no matter what happens below, we're going to cancel the order in our system
                    $order->updateStatus("cancelled");
                    $mode = pmpro_getOption("gateway");
                    $code = $order->recurrent_transaction_id;
                    if ($mode == 'testmode') {
                        $key = pmpro_getOption("watupay_test_public_key");
                    } else {
                        $key = pmpro_getOption("watupay_live_public_key");

                    }
                    if ($order->recurrent_transaction_id != "") {
                        $watupay_url = 'https://api.watu.global/v1/payment/charge/recurrent/' . $code;
                        $headers = array(
                            'Accept'=>'application/json',
                            'Content-Type'  => 'application/json',
                            'Authorization' => 'Bearer '.$key
                        );
                        $args = array(
                            'headers' => $headers,
                            'timeout' => 60
                        );
                        $request = wp_remote_get($watupay_url, $args);
                        if (!is_wp_error($request) && 200 == wp_remote_retrieve_response_code($request)) {
                            $watupay_response = json_decode(wp_remote_retrieve_body($request));
                            if ('active' == $watupay_response->data->status && $code == $watupay_response->data->recurrent_code && '1' == $watupay_response->status) {

                                $watupay_url = 'https://api.watu.global/v1/payment/charge/recurrent/disable';
                                $headers = array(
                                    'Accept'=>'application/json',
                                    'Content-Type'  => 'application/json',
                                    'Authorization' => 'Bearer '.$key
                                );
                                $body = array(
                                    'code'  => $watupay_response->data->recurrent_code,
                                    'token' => $watupay_response->data->email_token,

                                );
                                $args = array(
                                    'body'      => json_encode($body),
                                    'headers'   => $headers,
                                    'timeout'   => 60
                                );

                                $request = wp_remote_post($watupay_url, $args);
                                // print_r($request);
                                if (!is_wp_error($request)) {
                                    $watupay_response = json_decode(wp_remote_retrieve_body($request));
                                }
                            }
                        }
                    }
                    global $wpdb;
                    $wpdb->query("DELETE FROM $wpdb->pmpro_membership_orders WHERE id = '" . $order->id . "'");
                }
                function delete(&$order)
                {
                    //no matter what happens below, we're going to cancel the order in our system
                    $order->updateStatus("cancelled");
                    global $wpdb;
                    $wpdb->query("DELETE FROM $wpdb->pmpro_membership_orders WHERE id = '" . $order->id . "'");
                }
            }
        }
    }

?>

