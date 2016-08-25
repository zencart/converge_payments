# Elavon Converge Payment module, for Zen Cart

## Features

This module hosts credit card payment fields directly on your store, and securely transmits them to the Converge payment gateway operated by Elavon.

You can login to your Converge account at https://www.myvirtualmerchant.com/VirtualMerchant/login.do

Or visit https://www.elavon.com for more information.

## Requirements

1. You need a Converge account, with the "Enable HTTPS Transaction option" enabled.
2. Your server should be running with an SSL Certificate so that customer-submitted data is encrypted, since this module accepts credit card numbers directly on your site (it transmits the card number from the customer's browser to your server, and then your server transmits it to Converge for validation and processing. It never sends the card down to your browser, but customers will transmit their card data, so it should be secured with SSL.)
3. This module is made for Zen Cart v1.5.5 but should work with v1.5.4.

## Installation

### PHP Files
Currently no core files are changed, so you can just upload the files in `/includes/` into the relevant `/includes/` directories and subdirectories within your store.

**Note: You should not copy the README.md, LICENSE or changelog.txt files to your live server.**
 
## Admin module configuration
When installing the payment module from your Admin->Modules->Payment screen, fill in your Merchant ID, User ID, and Terminal PIN by copying them from your Converge/VirtualMerchant account.

- Merchant ID = your merchant login ID
- User ID = the name of the user you've configured in your Converge account (click Users->Find/Edit to see your users)
- PIN = Terminal PIN (Click Users->Find/Edit, click on the User, then on the Terminals button. There you'll see the terminal PIN.)


