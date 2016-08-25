<?php
/**
 * Elavon Converge Payment Module
 *
 * @package languageDefines
 * @copyright Copyright 2003-2016 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: Author: DrByte  August 2016 $
 *
 * To create a USD sandbox account for testing, see: https://www.convergepay.com/converge-webapp/developer/#/converge/getting-started
 */

define('MODULE_PAYMENT_ELAVON_CONVERGE_TEXT_ADMIN_TITLE', 'Elavon Converge Payments');
define('MODULE_PAYMENT_ELAVON_CONVERGE_TEXT_CATALOG_TITLE', 'Credit Card');  // Payment option title as displayed to the customer

if (IS_ADMIN_FLAG === true) {
if (MODULE_PAYMENT_ELAVON_CONVERGE_STATUS == 'True') {
  define('MODULE_PAYMENT_ELAVON_CONVERGE_TEXT_DESCRIPTION', '<a target="_blank" href="https://' . (MODULE_PAYMENT_ELAVON_CONVERGE_TESTMODE == 'Sandbox' ? 'demo.myvirtualmerchant.com/VirtualMerchantDemo' : 'www.myvirtualmerchant.com/VirtualMerchant') . '/login.do">Converge Account Login</a>
  	<br><br>This module requires an account with the [Enable HTTPS Transaction] option enabled.');
} else {
  define('MODULE_PAYMENT_ELAVON_CONVERGE_TEXT_DESCRIPTION', 'Elavon payment processing uses the Converge gateway for handling payment transactions. Sign up at <a href="https://www.elavon.com" target="_blank">elavon.com</a>.
  	<br><br>This module requires an account with the [Enable HTTPS Transaction] option enabled.');
}
}
define('MODULE_PAYMENT_ELAVON_CONVERGE_TEXT_DECLINED_MESSAGE', 'The transaction could not be completed. Please try another card or contact your bank for more info.  ');
define('MODULE_PAYMENT_ELAVON_CONVERGE_TEXT_ERROR_MESSAGE', 'There has been an error processing the transaction. Please try again.  ');

define('MODULE_PAYMENT_ELAVON_CONVERGE_TEXT_CREDIT_CARD_OWNER', 'Card Owner: ');
define('MODULE_PAYMENT_ELAVON_CONVERGE_TEXT_CREDIT_CARD_NUMBER', 'Card Number: ');
define('MODULE_PAYMENT_ELAVON_CONVERGE_TEXT_CREDIT_CARD_EXPIRES', 'Expiry Date: ');
define('MODULE_PAYMENT_ELAVON_CONVERGE_TEXT_CVV', 'CVV Number: ');
