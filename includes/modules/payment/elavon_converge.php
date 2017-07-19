<?php
/**
 * Elavon Converge Payment Module
 * Designed for Zen Cart v1.5.4 and v1.5.5
 *
 * @package paymentMethod
 * @copyright Copyright 2003-2016 Zen Cart Development Team
 * @copyright Portions Copyright 2003 osCommerce
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: Author: DrByte  July 2017 $
 */
/**
 * Elavon Converge Payment Module
 */
class elavon_converge extends base {
  /**
   * $code determines the internal 'code' name used to designate "this" payment module
   *
   * @var string
   */
  var $code = 'elavon_converge';
  /**
   * $moduleVersion is the plugin version number
   */
  var $moduleVersion = '0.5';

  /**
   * $title is the displayed name for this payment method
   *
   * @var string
   */
  var $title;
  /**
   * $description is used to display instructions in the admin
   *
   * @var string
   */
  var $description;
  /**
   * $enabled determines whether this module shows or not... in catalog.
   *
   * @var boolean
   */
  var $enabled;
  /**
   * this module collects card-info onsite
   */
  var $collectsCardDataOnsite = TRUE;
    /**
   * log file folder
   *
   * @var string
   */
  protected $_logDir = '';
  /**
   * vars for internal processing and debug/logging
   */
  protected $response = array();

  var $auth_code = '';
  var $transaction_id = '';
  var $transaction_messages = '';
  protected $avs_codes, $cvv_codes = array();
  /**
   * $order_status determines the status assigned to orders paid-for using this module
   */
  var $order_status;
  /**
   * the currency enabled in this gateway's merchant account. Transactions will be converted to this currency.
   * @var string
   */
  protected $gateway_currency = 'USD';


  /**
   * Constructor
   */
  function __construct() {
    global $order;

    $this->title = MODULE_PAYMENT_ELAVON_CONVERGE_TEXT_CATALOG_TITLE; // Payment module title in Catalog
    if (IS_ADMIN_FLAG === true) {
      $this->description = MODULE_PAYMENT_ELAVON_CONVERGE_TEXT_DESCRIPTION;
      $this->title = MODULE_PAYMENT_ELAVON_CONVERGE_TEXT_ADMIN_TITLE; // Payment module title in Admin
      if (MODULE_PAYMENT_ELAVON_CONVERGE_STATUS == 'True' && (MODULE_PAYMENT_ELAVON_CONVERGE_LOGIN == 'testing' || MODULE_PAYMENT_ELAVON_CONVERGE_TXNKEY == 'Test' || MODULE_PAYMENT_ELAVON_CONVERGE_RESPONSEKEY == '*Enter the Response Key here*')) {
        $this->title .=  '<span class="alert"> (Not Configured)</span>';
      } elseif (MODULE_PAYMENT_ELAVON_CONVERGE_TESTMODE == 'Test') {
        $this->title .= '<span class="alert"> (in Testing mode)</span>';
      } elseif (MODULE_PAYMENT_ELAVON_CONVERGE_TESTMODE == 'Sandbox') {
        $this->title .= '<span class="alert"> (in Sandbox Developer mode)</span>';
      }

      if (defined('MODULE_PAYMENT_ELAVON_CONVERGE_STATUS')) {
        $new_version_details = plugin_version_check_for_updates(2100, $this->moduleVersion);
        if ($new_version_details !== false) {
          $this->title .= '<span class="alert">' . ' - NOTE: A NEW VERSION OF THIS PLUGIN IS AVAILABLE. <a href="' . $new_version_details['link'] . '" target="_blank">[Details]</a>' . '</span>';
        }
      }
    }

    $this->enabled = (MODULE_PAYMENT_ELAVON_CONVERGE_STATUS == 'True');
    $this->sort_order = MODULE_PAYMENT_ELAVON_CONVERGE_SORT_ORDER;

    if ((int)MODULE_PAYMENT_ELAVON_CONVERGE_ORDER_STATUS_ID > 0) {
      $this->order_status = MODULE_PAYMENT_ELAVON_CONVERGE_ORDER_STATUS_ID;
    }
    // Reset order status to pending if capture pending:
    if (MODULE_PAYMENT_ELAVON_CONVERGE_AUTHORIZATION_TYPE == 'Authorize') $this->order_status = 1;

    if (is_object($order)) $this->update_status();

    // $this->form_action_url = 'https://www.myvirtualmerchant.com/VirtualMerchant/process.do';
    // if (MODULE_PAYMENT_ELAVON_CONVERGE_TESTMODE == 'Sandbox') $this->form_action_url = 'https://demo.myvirtualmerchant.com/VirtualMerchantDemo/process.do';

    $this->_logDir = defined('DIR_FS_LOGS') ? DIR_FS_LOGS : DIR_FS_SQL_CACHE;

    // set the currency for the gateway (others will be converted to this one before submission)
    $this->gateway_currency = MODULE_PAYMENT_ELAVON_CONVERGE_CURRENCY;

    $this->setAvsCvvMeanings();
  }

  /**
   * Calculate zone matches and flag settings to determine whether this module should display to customers or not
   */
  function update_status() {
    global $order, $db;

    if ($this->enabled && (int)MODULE_PAYMENT_ELAVON_CONVERGE_ZONE > 0 && isset($order->billing['country']['id'])) {
      $check_flag = false;
      $check = $db->Execute("select zone_id from " . TABLE_ZONES_TO_GEO_ZONES . " where geo_zone_id = '" . MODULE_PAYMENT_ELAVON_CONVERGE_ZONE . "' and zone_country_id = '" . (int)$order->billing['country']['id'] . "' order by zone_id");
      while (!$check->EOF) {
        if ($check->fields['zone_id'] < 1) {
          $check_flag = true;
          break;
        } elseif ($check->fields['zone_id'] == $order->billing['zone_id']) {
          $check_flag = true;
          break;
        }
        $check->MoveNext();
      }

      if ($check_flag == false) {
        $this->enabled = false;
      }
    }

    // other status checks?
    if ($this->enabled) {
      // other checks here
    }
  }
  /**
   * JS validation which does error-checking of data-entry if this module is selected for use
   * (Number, Owner Lengths)
   *
   * @return string
   */
  function javascript_validation() {
    return '';
  }
  /**
   * Display Credit Card Information Submission Fields on the Checkout Payment Page
   *
   * @return array
   */
  function selection() {
    global $order;

    // Prepare selection of expiry dates
    for ($i=1; $i<13; $i++) {
      $expires_month[] = array('id' => sprintf('%02d', $i), 'text' => strftime('%B - (%m)',mktime(0,0,0,$i,1,2000)));
    }
    $today = getdate();
    for ($i=$today['year']; $i < $today['year']+15; $i++) {
      $expires_year[] = array('id' => strftime('%y',mktime(0,0,0,1,1,$i)), 'text' => strftime('%Y',mktime(0,0,0,1,1,$i)));
    }

    // helper for auto-selecting the radio-button next to this module so the user doesn't have to make that choice
    $onFocus = ' onfocus="methodSelect(\'pmt-' . $this->code . '\')"';

    $selection = array(
        'id' => $this->code,
        'module' => MODULE_PAYMENT_ELAVON_CONVERGE_TEXT_CATALOG_TITLE,
        'fields' => array(
            array(
                'title' => MODULE_PAYMENT_ELAVON_CONVERGE_TEXT_CREDIT_CARD_OWNER,
                'field' => zen_draw_input_field($this->code . '_cc_owner',
                    $order->billing['firstname'] . ' ' . $order->billing['lastname'], 'id="' . $this->code . '_cc-owner"' . $onFocus . ' autocomplete="off"'),
                'tag' => $this->code . '_cc-owner'
            ),
            array(
                'title' => MODULE_PAYMENT_ELAVON_CONVERGE_TEXT_CREDIT_CARD_NUMBER,
                'field' => zen_draw_input_field($this->code . '_cc_number', '', 'id="' . $this->code . '_cc-number"' . $onFocus . ' autocomplete="off"'),
                'tag' => $this->code . '_cc-number'
            ),
            array(
                'title' => MODULE_PAYMENT_ELAVON_CONVERGE_TEXT_CREDIT_CARD_EXPIRES,
                'field' => zen_draw_pull_down_menu($this->code . '_cc_expires_month', $expires_month, strftime('%m'), 'id="' . $this->code . '_cc-expires-month"' . $onFocus) . '&nbsp;' .
                         zen_draw_pull_down_menu($this->code . '_cc_expires_year', $expires_year, '', 'id="' . $this->code . '_cc-expires-year"' . $onFocus),
                'tag' => $this->code . '_cc-expires-month'
            ),
            array(
                'title' => MODULE_PAYMENT_ELAVON_CONVERGE_TEXT_CVV,
                'field' => zen_draw_input_field($this->code. '_cc_cvv', '', 'size="4" maxlength="4"' . 'id="'.$this->code.'_cc-cvv"' . $onFocus . ' autocomplete="off"'),
                'tag' => $this->code.'_cc-cvv'
            ),
        )
    );
    return $selection;
  }
  /**
   * Evaluates the Credit Card Type for acceptance and the validity of the Credit Card Number & Expiration Date
   *
   */
  function pre_confirmation_check() {
    global $messageStack;

    include(DIR_WS_CLASSES . 'cc_validation.php');

    $cc_validation = new cc_validation();
    $result = $cc_validation->validate($_POST[$this->code.'_cc_number'], $_POST[$this->code.'_cc_expires_month'], $_POST[$this->code.'_cc_expires_year'], $_POST[$this->code.'_cc_cvv']);
    $error = '';
    switch ($result) {
      case -1:
      $error = sprintf(TEXT_CCVAL_ERROR_UNKNOWN_CARD, substr($cc_validation->cc_number, 0, 4));
      break;
      case -2:
      case -3:
      case -4:
      $error = TEXT_CCVAL_ERROR_INVALID_DATE;
      break;
      case false:
      $error = TEXT_CCVAL_ERROR_INVALID_NUMBER;
      break;
    }

    if ( ($result == false) || ($result < 1) ) {
      $messageStack->add_session('checkout_payment', $error . '<!-- ['.$this->code.'] -->', 'error');
      zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false));
    }

    $this->cc_card_type = $cc_validation->cc_type;
    $this->cc_card_number = $cc_validation->cc_number;
    $this->cc_expiry_month = $cc_validation->cc_expiry_month;
    $this->cc_expiry_year = $cc_validation->cc_expiry_year;
  }
  /**
   * Display Credit Card Information on the Checkout Confirmation Page for visual confirmation only
   *
   * @return array
   */
  function confirmation() {
    $confirmation = array('fields' => array(array('title' => MODULE_PAYMENT_ELAVON_CONVERGE_TEXT_CREDIT_CARD_OWNER,
                                                  'field' => zen_output_string_protected($_POST[$this->code . '_cc_owner'])),
                                            array('title' => MODULE_PAYMENT_ELAVON_CONVERGE_TEXT_CREDIT_CARD_NUMBER,
                                                  'field' => zen_output_string_protected(substr($this->cc_card_number, 0, 4) . str_repeat('X', (strlen($this->cc_card_number) - 8)) . substr($this->cc_card_number, -4))),
                                            array('title' => MODULE_PAYMENT_ELAVON_CONVERGE_TEXT_CREDIT_CARD_EXPIRES,
                                                  'field' => strftime('%B, %Y', mktime(0,0,0,$_POST[$this->code . '_cc_expires_month'], 1, '20' . $_POST[$this->code . '_cc_expires_year']))),
                                            ));
    return $confirmation;
  }
  /**
   * Build the data and actions to process when the "Submit" button is pressed on the order-confirmation screen.
   * This sends the data to the payment gateway for processing.
   * (These are hidden fields on the checkout confirmation page)
   *
   * @return string
   */
  function process_button() {
    $process_button_string .= zen_draw_hidden_field('cc_number', zen_output_string_protected($_POST[$this->code . '_cc_number']));
    $process_button_string .= zen_draw_hidden_field('cc_cvv', preg_replace('/[^0-9]/', '', $_POST[$this->code . '_cc_cvv']));
    $process_button_string .= zen_draw_hidden_field('cc_expires', sprintf('%02d', (int)$_POST[$this->code . '_cc_expires_month']) . (int)$_POST[$this->code . '_cc_expires_year']);
    $process_button_string .= zen_draw_hidden_field('cc_owner', zen_output_string_protected($_POST[$this->code . '_cc_owner']));
    return $process_button_string;
  }
  /**
   * redraw button using ajax, for secure processing
   * @return array
   */
  function process_button_ajax() {
    $processButton = array('ccFields'=>array('cc_number'=>$this->code . '_cc_number', 'cc_owner'=>$this->code . '_cc_owner', 'cc_cvv'=>$this->code . '_cc_cvv', 'cc_expires'=>array('name'=>'concatExpiresFields', 'args'=>"['" .$this->code . '_cc_expires_month' . "','" . $this->code . '_cc_expires_year' . "']"), 'cc_expires_month'=>$this->code . '_cc_expires_month', 'cc_expires_year'=>$this->code . '_cc_expires_year', 'cc_type' => $this->cc_card_type), 'extraFields'=>array(zen_session_name()=>zen_session_id()));
        return $processButton;
  }
  /**
   * Store the CC info to the order and process any results that come back from the payment gateway
   *
   */
  function before_process() {
    global $messageStack, $order, $order_totals;
    $order->info['cc_owner'] = strip_tags($_POST['cc_owner']);
    if (!strpos($order->info['cc_number'], 'XX')) {
      $_POST['cc_number'] = preg_replace('/[^0-9]/', '', $_POST['cc_number']);
      $order->info['cc_number']  = substr($_POST['cc_number'], 0, 6) . str_pad(substr($_POST['cc_number'], -4), strlen($_POST['cc_number'])-10, "X", STR_PAD_LEFT);
    }
    $order->info['cc_expires'] = '';
    $order->info['cc_cvv']     = '***';

    $submit_data = array(
        'ssl_card_number' => preg_replace('/[^0-9]/', '', $_POST['cc_number']),
        'ssl_exp_date' => preg_replace('/[^0-9]/', '', $_POST['cc_expires']), // MMYY format
        'ssl_cvv2cvc2' => preg_replace('/[^0-9]/', '', $_POST['cc_cvv']),
        'ssl_cvv2cvc2_indicator' => !empty($_POST['cc_cvv']), // indicates that we are passing a CVV value

        'ssl_amount' => round($order->info['total'], 2),
        'ssl_transaction_type' => MODULE_PAYMENT_ELAVON_CONVERGE_AUTHORIZATION_TYPE == 'Authorize' ? 'ccauthonly': 'ccsale',
        'ssl_invoice_number' => $this->get_next_order_id(),

        'ssl_show_form' => 'false', // collect card data onsite
        'ssl_result_format' => 'ascii', // we can only parse key-value pairs, not an HTML response
        'ssl_get_token' => 'N',
        'ssl_add_token' => 'N',

        'ssl_company' => $order->billing['company'],
        'ssl_first_name' => $order->billing['firstname'],
        'ssl_last_name' => $order->billing['lastname'],
        'ssl_avs_address' => $order->billing['street_address'],
        'ssl_city' => $order->billing['city'],
        'ssl_state' => $order->billing['state'],
        'ssl_avs_zip' => $order->billing['postcode'],
        'ssl_country' => $order->billing['country']['iso_code_3'],
        'ssl_phone' => $order->customer['telephone'],
        'ssl_email' => $order->customer['email_address'],
        'ssl_ship_to_company' => $order->delivery['company'],
        'ssl_ship_to_first_name' => $order->delivery['firstname'],
        'ssl_ship_to_last_name' => $order->delivery['lastname'],
        'ssl_ship_to_address1' => $order->delivery['street_address'],
        'ssl_ship_to_address2' => $order->delivery['suburb'],
        'ssl_ship_to_city' => $order->delivery['city'],
        'ssl_ship_to_state' => $order->delivery['state'],
        'ssl_ship_to_zip' => $order->delivery['postcode'],
        'ssl_ship_to_country' => $order->delivery['country']['iso_code_3'],
        'ssl_ship_to_phone' => $order->customer['telephone'],
        'ssl_cardholder_ip' => zen_get_ip_address(),
        'ssl_description' => 'Website Purchase from ' . str_replace('"',"'", STORE_NAME),
    );
    if (MODULE_PAYMENT_ELAVON_CONVERGE_TESTMODE == 'Test') $submit_data['ssl_test_mode'] = 'true';

    // force conversion to supported currencies
    $exchange_factor = 1;
    if ($order->info['currency'] != $this->gateway_currency) {
      global $currencies;
      $exchange_factor = $currencies->get_value($this->gateway_currency);
      $submit_data['ssl_amount'] = round($order->info['total'] * $exchange_factor, 2);
      if (isset($submit_data['ssl_salestax'])) $submit_data['ssl_salestax'] = round($submit_data['ssl_salestax'] * $exchange_factor, 2);
      $submit_data['ssl_description'] .= ' (Converted from: ' . round($order->info['total'] * $order->info['currency_value'], 2) . ' ' . $order->info['currency'] . ')';
    }

    // Submit the payment to Converge
    $response = $this->sendRequest($submit_data);
    $this->response = $this->parseResponseIntoPairs($response);


// RESPONSES:
// ssl_result // 0 = Approved
// ssl_result_message // APPROVAL or PARTIAL
// errorCode //
// errorMessage
// errorName
// ssl_approval_code // authcode for comments
// ssl_txn_id // unique identifier (store this in HIDDEN comments)
// ssl_txn_time // add to comments
// ssl_amount // store in comments
// ssl_card_number // masked value for reference
// ssl_card_short_description // card type like VISA MC
// ssl_avs_response // AVS code
// ssl_cvv2_response // CVV code
// ssl_token
// ssl_token_response



    $this->notify('MODULE_PAYMENT_ELAVON_CONVERGE_POSTSUBMIT_HOOK', $this->response);
    $this->_debugActions($this->response, 'Response-Data');

    // if in 'echo' mode, dump the returned data to the browser and stop execution
    if (MODULE_PAYMENT_ELAVON_CONVERGE_DEBUGGING == 'echo') {
      echo 'Returned Response Codes:<br /><pre>' . print_r($this->response, true) . '</pre><br />';
      die('Press the BACK button in your browser to return to the previous page.');
    }

    // SUCCESS
    if ($this->response['ssl_result'] == '0') {
      $order->info['cc_type'] = $this->response['ssl_card_short_description'];
      if (!strpos($order->info['cc_number'], 'X')) $order->info['cc_number'] = $this->response['ssl_card_number'];
      $this->auth_code = $this->response['ssl_approval_code'];
      $this->transaction_id = $this->response['ssl_txn_id'];
      $this->transaction_messages = $this->response['ssl_result_message'] . ' ';
      if (isset($this->response['ssl_avs_response']) && isset($this->avs_codes[$this->response['ssl_avs_response']])) $this->transaction_messages .= "\n" . 'AVS: ' . $this->avs_codes[$this->response['ssl_avs_response']];
      if (isset($this->response['ssl_cvv2_response']) && isset($this->cvv_codes[$this->response['ssl_cvv2_response']])) $this->transaction_messages .= "\n" . 'CVV: ' . $this->cvv_codes[$this->response['ssl_cvv2_response']];

      $_SESSION['payment_method_messages'] = 'Payment processed: $' . $this->response['ssl_amount'] . ' ' . $this->response['ssl_txn_time'] . ' ' . $this->response['ssl_card_short_description'] . ' ' . $this->response['ssl_card_number'];
      return true;
    }

    // DECLINE
    if (strstr($this->response['ssl_approval_code'], 'DECLINE')) {
      $messageStack->add_session('checkout_payment', $this->response['errorMessage'] . ' ... ' . MODULE_PAYMENT_ELAVON_CONVERGE_TEXT_DECLINED_MESSAGE, 'error');
      zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', '', true, false));
    }

    // ERROR:  If we get here, an error has been encountered, either with the module credentials as configured in the Admin, or in the Merchant Account, or with the communications from the server

    switch ($this->response['errorCode']) {
        // SERVER ERRORS
        case '3000': // not responding
        case '4017': // timeout
        case '4022': // Unavailable. Try again later.
        case '6038': // Unavailable. Try again later.

        // CONFIGURATION ERRORS
        case '4000': // Merchant ID was not supplied,
        case '4019': // User ID was not supplied,
        case '4013': // Merchant PIN not supplied
        case '4025': // Invalid credentials

        // MERCHANT ACCOUNT MISCONFIGURATION
        case '4002': // HTTP POST transactions not allowed for this account; Configure Converge account to allow.
        case '4014': // This Terminal ID not permitted
        case '4016': // Permission denied for this account

        // MERCHANT ACCOUNT FRAUD SETTINGS RESTRICTIONS IN PLACE
        case '4003': // Referrer invalid; fix account restriction
        case '4005': // email domain invalid; fix account restriction

        // DATA NOT ASSEMBLED OR TRANSMITTED PROPERLY
        case '4006': // CVV2 not supplied
        case '4007': // CVV2 not supplied
        case '4009': // A REQUIRED FIELD not supplied
        case '4010': // invalid transaction type
        case '4011': // receipt URL wrong
        case '5005': // field char limit exceeded
        case '5092': // invalid country code
        case '5000': // invalid card number
        case '5001': // invalid Exp date
        case '5002': // invalid amount
        case '5007': // invalid salestax
        case '5020': // invalid field (not valid for this trans type)
        case '5021': // invalid CVV2 (must be 3-4 digits)

        // TOKENIZATION   (NOT USED PRESENTLY)
        case '5085': // invalid token
        case '5086': // cannot provide both card and token

        // CURRENCY MISCONFIGURATION
        case '5087': // invalid currency for this terminal; must be set up for multicurrency
        case '5088': // currency not valid for this card type
        case '5089': // invalid currency ISO

        // DECLINED CARDS
        case '6002': // declined: invalid card
        case '6003': // declined: pick up card
        case '6004': // declined: amount error
        case '6005': // declined: appl type error
        case '6006': // declined
        case '6007': // declined: help
        case '6008': // declined: req exceeds bal
        case '6009': // declined: expired card
        case '6020': // declined: call auth center
        case '6021': // declined: call ref
        case '6022': // declined: CVV2
        case '6023': // declined: PLEASE RETRY XXXXXX

    }
    $messageStack->add_session('checkout_payment', MODULE_PAYMENT_ELAVON_CONVERGE_TEXT_ERROR_MESSAGE . ' ' . $this->response['errorCode'] . ' ' . $this->response['errorMessage'], 'error');
    zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', '', true, false));
  }

  /**
   * Add receipt and transaction id to order-status-history (order comments)
   *
   * @return boolean
   */
  function after_process() {
    global $insert_id, $db, $order, $currencies;
    $this->notify('MODULE_PAYMENT_ELAVON_CONVERGE_POSTPROCESS_HOOK');
    $sql = "insert into " . TABLE_ORDERS_STATUS_HISTORY . " (comments, orders_id, orders_status_id, customer_notified, date_added) values (:orderComments, :orderID, :orderStatus, 1, now() )";
    $sql = $db->bindVars($sql, ':orderComments', $_SESSION['payment_method_messages'], 'string');
    $sql = $db->bindVars($sql, ':orderID', $insert_id, 'integer');
    $sql = $db->bindVars($sql, ':orderStatus', $this->order_status, 'integer');
    $db->Execute($sql);

    $sql = "insert into " . TABLE_ORDERS_STATUS_HISTORY . " (comments, orders_id, orders_status_id, customer_notified, date_added) values (:orderComments, :orderID, :orderStatus, -1, now() )";
    $currency_comment = '';
    if ($order->info['currency'] != $this->gateway_currency) {
      $currency_comment = ' (' . round($order->info['total'] * $currencies->get_value($this->gateway_currency), 2) . ' ' . $this->gateway_currency . ')';
    }
    $sql = $db->bindVars($sql, ':orderComments', 'Credit Card payment.  AUTH: ' . $this->auth_code . ' TransID: ' . $this->transaction_id . ' ' . $currency_comment . "\n" . $this->transaction_messages, 'string');
    $sql = $db->bindVars($sql, ':orderID', $insert_id, 'integer');
    $sql = $db->bindVars($sql, ':orderStatus', $this->order_status, 'integer');
    $db->Execute($sql);
    return false;
  }
  /**
   * Check to see whether module is installed
   *
   * @return boolean
   */
  function check() {
    global $db;
    if (!isset($this->_check)) {
      $check_query = $db->Execute("select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key = 'MODULE_PAYMENT_ELAVON_CONVERGE_STATUS'");
      $this->_check = $check_query->RecordCount();
    }
    return $this->_check;
  }
  /**
   * Install the payment module and its configuration settings
   *
   */
  function install() {
    global $db, $messageStack;
    if (defined('MODULE_PAYMENT_ELAVON_CONVERGE_STATUS')) {
      $messageStack->add_session('Elavon Converge payment module already installed.', 'error');
      zen_redirect(zen_href_link(FILENAME_MODULES, 'set=payment&module=elavon_converge'));
      return 'failed';
    }
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable Elavon Converge Payment Module', 'MODULE_PAYMENT_ELAVON_CONVERGE_STATUS', 'True', 'Do you want to accept payments via Elavon Converge?', '6', '0', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Sort order of display.', 'MODULE_PAYMENT_ELAVON_CONVERGE_SORT_ORDER', '0', 'Sort order of displaying payment options to the customer. Lowest is displayed first.', '6', '0', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Set Order Status', 'MODULE_PAYMENT_ELAVON_CONVERGE_ORDER_STATUS_ID', '2', 'Set the status of orders made with this payment module to this value', '6', '0', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added) values ('Payment Zone', 'MODULE_PAYMENT_ELAVON_CONVERGE_ZONE', '0', 'If a zone is selected, only enable this payment method for that zone.', '6', '2', 'zen_get_zone_class_title', 'zen_cfg_pull_down_zone_classes(', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Merchant ID', 'MODULE_PAYMENT_ELAVON_CONVERGE_MERCHANTID', '', 'The Converge Merchant ID as provided by Elavon.', '6', '0', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added, use_function) values ('User ID', 'MODULE_PAYMENT_ELAVON_CONVERGE_USERID', '', 'Converge User ID as configured on Converge (case-sensitive)<br>(Click on Users, click Find/Edit to see your user names)', '6', '0', now(), 'zen_cfg_password_display')");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added, use_function) values ('PIN / Terminal PIN', 'MODULE_PAYMENT_ELAVON_CONVERGE_PIN', '', 'The Converge Terminal-PIN as generated within Converge (case-sensitive).<br>(Click on Users, click Find/Edit, click on the user, click on the Terminals button, and copy and paste the PIN from there to here.)', '6', '0', now(), 'zen_cfg_password_display')");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Transaction Mode', 'MODULE_PAYMENT_ELAVON_CONVERGE_TESTMODE', 'Test', 'Transaction mode used for processing orders.<br><strong>Live</strong>=Live processing with real account credentials<br><strong>Test</strong>=Simulations with real account credentials<br><strong>Sandbox</strong>=use special sandbox transaction key to do special testing of success/fail transaction responses (sandbox credentials available to developers, by contacting Elavon)', '6', '0', 'zen_cfg_select_option(array(\'Test\', \'Live\', \'Sandbox\'), ', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Authorization Type', 'MODULE_PAYMENT_ELAVON_CONVERGE_AUTHORIZATION_TYPE', 'Sale', 'Do you want submitted credit card transactions to be authorized only, or charged immediately?', '6', '0', 'zen_cfg_select_option(array(\'Authorize\', \'Sale\'), ', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Debug Mode', 'MODULE_PAYMENT_ELAVON_CONVERGE_DEBUGGING', 'Alerts Only', 'Would you like to enable debug mode?  A  detailed log of failed transactions may be emailed to the store owner.', '6', '0', 'zen_cfg_select_option(array(\'Off\', \'Alerts Only\', \'Log File\', \'Log and Email\'), ', now())");
    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Currency Supported', 'MODULE_PAYMENT_ELAVON_CONVERGE_CURRENCY', 'USD', 'Which currency is your Converge Account configured to accept?<br>(Purchases in any other currency will be pre-converted to this currency before submission using the exchange rates in your store admin.)', '6', '0', 'zen_cfg_select_option(array(\'USD\', \'CAD\'), ', now())");
  }
  /**
   * Remove the module and all its settings
   *
   */
  function remove() {
    global $db;
    $db->Execute("delete from " . TABLE_CONFIGURATION . " where configuration_key in ('" . implode("', '", $this->keys()) . "')");
  }
  /**
   * Internal list of configuration keys used for configuration of the module
   *
   * @return array
   */
  function keys() {
    return array('MODULE_PAYMENT_ELAVON_CONVERGE_STATUS',
            'MODULE_PAYMENT_ELAVON_CONVERGE_SORT_ORDER',
            'MODULE_PAYMENT_ELAVON_CONVERGE_ORDER_STATUS_ID',
            'MODULE_PAYMENT_ELAVON_CONVERGE_ZONE',
            'MODULE_PAYMENT_ELAVON_CONVERGE_MERCHANTID',
            'MODULE_PAYMENT_ELAVON_CONVERGE_USERID',
            'MODULE_PAYMENT_ELAVON_CONVERGE_PIN',
            'MODULE_PAYMENT_ELAVON_CONVERGE_TESTMODE',
            'MODULE_PAYMENT_ELAVON_CONVERGE_CURRENCY',
            'MODULE_PAYMENT_ELAVON_CONVERGE_AUTHORIZATION_TYPE',
            'MODULE_PAYMENT_ELAVON_CONVERGE_DEBUGGING');
  }

  protected function parseResponseIntoPairs($response)
  {
    $retVal = array();
    $lines = explode("\n", $response);
    if (count($lines) == 0) return $retVal;
    foreach($lines as $line) {
        $pair = explode('=', $line);
        $retVal[$pair[0]] = $pair[1];
    }
    return $retVal;
  }
  /**
   * Used to do any debug logging / tracking / storage as required.
   */
  protected function _debugActions($data, $mode, $order_time = '', $url = null) {
    if ($order_time == '') $order_time = date("F j, Y, g:i a");
    if ($url) $data['url'] = $url;
    if (isset($data['ssl_merchant_id'])) $data['ssl_merchant_id'] = '*****' . substr($data['ssl_merchant_id'], -3);
    if (isset($data['ssl_user_id'])) $data['ssl_user_id'] = '*****' . substr($data['ssl_user_id'], -3);
    if (isset($data['ssl_pin'])) $data['ssl_pin'] = '****' . substr($data['ssl_pin'], -2);
    if (isset($data['ssl_card_number'])) $data['ssl_card_number'] = '*******' . substr($data['ssl_card_number'], -4);
    if (isset($data['ssl_exp_date'])) $data['ssl_exp_date'] = '***' . substr($data['ssl_exp_date'], -1);
    if (isset($data['ssl_cvv2cvc2'])) $data['ssl_cvv2cvc2'] = '***' . substr($data['ssl_cvv2cvc2'], -1);

    $errorMessage = date('M-d-Y h:i:s') .
                    "\n=================================\n\n";
    if ($mode == 'Submit-Data') $errorMessage .=
                    'Sent to Converge Elavon VirtualMerchant gateway: ' . print_r($data, true) . "\n\n";
    if ($mode == 'Response-Data') $errorMessage .=
                    'Response Code: ' . $data['ssl_result'] . ".\nResponse Text: " . $data['ssl_result_message'] . "\n\n" .
                    ($data['ssl_result'] > 0 && $data['errorCode'] == 6003 ? ' NOTICE: Card should be picked up - possibly stolen ' : '') .
                    'Results Received back from Elavon Converge: ' . print_r($data, true) . "\n\n";
    // store log file if log mode enabled
    if (stristr(MODULE_PAYMENT_ELAVON_CONVERGE_DEBUGGING, 'Log') || strstr(MODULE_PAYMENT_ELAVON_CONVERGE_DEBUGGING, 'All')) {
      $key = ($data['ssl_txn_id'] != '' ? $data['ssl_txn_id'] . '_' : '') . time() . '_' . zen_create_random_value(4);
      $file = $this->_logDir . '/' . 'Converge_Debug_' . $key . '.log';
      $fp = @fopen($file, 'a');
      @fwrite($fp, $errorMessage);
      @fclose($fp);
    }
    // send email alerts only if in alert mode or if email specifically requested as logging mode
    if ((isset($data['ssl_result']) && $data['ssl_result'] != '0' && stristr(MODULE_PAYMENT_ELAVON_CONVERGE_DEBUGGING, 'Alerts')) || stristr(MODULE_PAYMENT_ELAVON_CONVERGE_DEBUGGING, 'Email')) {
      zen_mail(STORE_NAME, STORE_OWNER_EMAIL_ADDRESS, 'Elavon Converge Payment Alert ' . $data['ssl_invoice_number'] . ' ' . date('M-d-Y h:i:s') . ' ' . $data['ssl_txn_id'], $errorMessage, STORE_OWNER, STORE_OWNER_EMAIL_ADDRESS, array('EMAIL_MESSAGE_HTML'=>nl2br($errorMessage)), 'debug');
    }
  }

  /**
   * Calculate the next expected order ID
   */
  protected function get_next_order_id()
  {
    global $db;
    // Calculate the next expected order id
    $result = $db->Execute("select max(orders_id)+1 as orders_id from " . TABLE_ORDERS . " order by orders_id");
    $next_order_id = $result->fields['orders_id'];
    // add randomized suffix to order id to produce uniqueness ... since it's unwise to submit the same order-number twice to the gateway, and this order is not yet committed
    $next_order_id = (string)$next_order_id . '-' . zen_create_random_value(6, 'chars');
    return $next_order_id;
  }


  private function sendRequest($payload)
  {
    // $endpoint = 'https://www.myvirtualmerchant.com/VirtualMerchant/process.do';
    // if (MODULE_PAYMENT_ELAVON_CONVERGE_TESTMODE == 'Sandbox') $endpoint = 'https://demo.myvirtualmerchant.com/VirtualMerchantDemo/process.do';

    $endpoint = 'https://api.convergepay.com/VirtualMerchant/process.do';
    if (MODULE_PAYMENT_ELAVON_CONVERGE_TESTMODE == 'Sandbox') $endpoint = 'https://api.demo.convergepay.com/VirtualMerchantDemo/process.do';

    $payload = array_merge($payload, array(
            'ssl_merchant_id' => trim(MODULE_PAYMENT_ELAVON_CONVERGE_MERCHANTID),
            'ssl_user_id' => trim(MODULE_PAYMENT_ELAVON_CONVERGE_USERID),
            'ssl_pin' => trim(MODULE_PAYMENT_ELAVON_CONVERGE_PIN),
        ));

    $this->_debugActions($payload, 'Submit-Data', '', $endpoint);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $endpoint . '?' . http_build_query($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    $response = curl_exec($ch);

    $commErrNo = curl_errno($ch);
    if ($commErrNo == 35) {
      trigger_error('ALERT: Could not process Converge transaction via normal CURL communications. Your server is encountering connection problems using TLS 1.2 ... because your hosting company cannot autonegotiate a secure protocol with modern security protocols. We will try the transaction again, but this is resulting in a very long delay for your customers, and could result in them attempting duplicate purchases. Get your hosting company to update their TLS capabilities ASAP.', E_USER_NOTICE);
      // Reset CURL to TLS 1.2 using the defined value of 6 instead of CURL_SSLVERSION_TLSv1_2 since these outdated hosts also don't properly implement this constant either.
      curl_setopt($ch, CURLOPT_SSLVERSION, 6);
      // and attempt resubmit
      $response = curl_exec($ch);
    }

    if (false === $response) {
      $this->commError = curl_error($ch);
      $this->commErrNo = curl_errno($ch);
      trigger_error('Converge communications failure. ' . $this->commErrNo . ' - ' . $this->commError, E_USER_NOTICE);
    }
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $this->commInfo = curl_getinfo($ch);
    curl_close($ch);

    if (!in_array($httpcode, array(200,201,202))) {
      error_log($response);
    }

    return $response;
  }


  private function setAvsCvvMeanings() {
    $this->cvv_codes['M'] = 'CVV2/CVC2 Match - Indicates that the card is authentic. Complete the transaction if the authorization request was approved.';
    $this->cvv_codes['N'] = 'CVV2 / CVC2 No Match – May indicate a problem with the card. Contact the cardholder to verify the CVV2 code before completing the transaction, even if the authorization request was approved.';
    $this->cvv_codes['P'] = 'Not Processed - Indicates that the expiration date was not provided with the request, or that the card does not have a valid CVV2 code. If the expiration date was not included with the request, resubmit the request with the expiration date.';
    $this->cvv_codes['S'] = 'Merchant Has Indicated that CVV2 / CVC2 is not present on card - May indicate a problem with the card. Contact the cardholder to verify the CVV2 code before completing the transaction.';
    $this->cvv_codes['U'] = 'Issuer is not certified and/or has not provided visa encryption keys';
    $this->cvv_codes['I'] = 'CVV2 code is invalid or empty';

    $this->avs_codes['X'] = 'Exact match, 9 digit zip - Street Address, and 9 digit ZIP Code match';
    $this->avs_codes['Y'] = 'Exact match, 5 digit zip - Street Address, and 5 digit ZIP Code match';
    $this->avs_codes['A'] = 'Partial match - Street Address matches, ZIP Code does not';
    $this->avs_codes['W'] = 'Partial match - ZIP Code matches, Street Address does not';
    $this->avs_codes['Z'] = 'Partial match - 5 digit ZIP Code match only';
    $this->avs_codes['N'] = 'No match - No Address or ZIP Code match';
    $this->avs_codes['U'] = 'Unavailable - Address information is unavailable for that account number, or the card issuer does not support';
    $this->avs_codes['G'] = 'Service Not supported, non-US Issuer does not participate';
    $this->avs_codes['R'] = 'Retry - Issuer system unavailable, retry later';
    $this->avs_codes['E'] = 'Not a mail or phone order';
    $this->avs_codes['S'] = 'Service not supported';
    $this->avs_codes['Q'] = 'Bill to address did not pass edit checks/Card Association cannot verify the authentication of an address';
    $this->avs_codes['D'] = 'International street address and postal code match';
    $this->avs_codes['B'] = 'International street address match, postal code not verified due to incompatible formats';
    $this->avs_codes['C'] = 'International street address and postal code not verified due to incompatible formats';
    $this->avs_codes['P'] = 'International postal code match, street address not verified due to incompatible format';
    $this->avs_codes['1'] = 'Cardholder name matches';
    $this->avs_codes['2'] = 'Cardholder name, billing address, and postal code match';
    $this->avs_codes['3'] = 'Cardholder name and billing postal code match';
    $this->avs_codes['4'] = 'Cardholder name and billing address match';
    $this->avs_codes['5'] = 'Cardholder name incorrect, billing address and postal code match';
    $this->avs_codes['6'] = 'Cardholder name incorrect, billing postal code matches';
    $this->avs_codes['7'] = 'Cardholder name incorrect, billing address matches';
    $this->avs_codes['8'] = 'Cardholder name, billing address, and postal code are all incorrect';
    $this->avs_codes['F'] = 'Address and Postal Code match (UK only)';
    $this->avs_codes['I'] = 'Address information not verified for international transaction';
    $this->avs_codes['M'] = 'Address and Postal Code match';
  }
}

// for backward compatibility with older ZC versions before v152 which didn't have this function:
if (!function_exists('plugin_version_check_for_updates')) {
    function plugin_version_check_for_updates($plugin_file_id = 0, $version_string_to_compare = '', $strict_zc_version_compare = false)
    {
        if ($plugin_file_id == 0) return false;
        $new_version_available = false;
        $lookup_index = 0;
        $url1 = 'https://plugins.zen-cart.com/versioncheck/'.(int)$plugin_file_id;
        $url2 = 'https://www.zen-cart.com/versioncheck/'.(int)$plugin_file_id;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,$url1);
        curl_setopt($ch, CURLOPT_VERBOSE, 0);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 9);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 9);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Plugin Version Check [' . (int)$plugin_file_id . '] ' . HTTP_SERVER);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);

        if ($error > 0) {
            trigger_error('CURL error checking plugin versions: ' . $errno . ':' . $error . "\nTrying http instead.");
            curl_setopt($ch, CURLOPT_URL, str_replace('tps:', 'tp:', $url1));
            $response = curl_exec($ch);
            $error = curl_error($ch);
            $errno = curl_errno($ch);
        }
        if ($error > 0) {
            trigger_error('CURL error checking plugin versions: ' . $errno . ':' . $error . "\nTrying www instead.");
            curl_setopt($ch, CURLOPT_URL, str_replace('tps:', 'tp:', $url2));
            $response = curl_exec($ch);
            $error = curl_error($ch);
            $errno = curl_errno($ch);
        }
        curl_close($ch);
        if ($error > 0 || $response == '') {
            trigger_error('CURL error checking plugin versions: ' . $errno . ':' . $error . "\nTrying file_get_contents() instead.");
            $ctx = stream_context_create(array('http' => array('timeout' => 5)));
            $response = file_get_contents($url1, null, $ctx);
            if ($response === false) {
                trigger_error('file_get_contents() error checking plugin versions.' . "\nTrying http instead.");
                $response = file_get_contents(str_replace('tps:', 'tp:', $url1), null, $ctx);
            }
            if ($response === false) {
                trigger_error('file_get_contents() error checking plugin versions.' . "\nAborting.");
                return false;
            }
        }

        $data = json_decode($response, true);
        if (!$data || !is_array($data)) return false;
        // compare versions
        if (strcmp($data[$lookup_index]['latest_plugin_version'], $version_string_to_compare) > 0) $new_version_available = true;
        // check whether present ZC version is compatible with the latest available plugin version
        $zc_version = PROJECT_VERSION_MAJOR . '.' . preg_replace('/[^0-9.]/', '', PROJECT_VERSION_MINOR);
        if ($strict_zc_version_compare) $zc_version = PROJECT_VERSION_MAJOR . '.' . PROJECT_VERSION_MINOR;
        if (!in_array('v'. $zc_version, $data[$lookup_index]['zcversions'])) $new_version_available = false;
        return ($new_version_available) ? $data[$lookup_index] : false;
    }

}
