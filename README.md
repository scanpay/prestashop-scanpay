# Scanpay for PrestaShop
We have developed an official payment module for [PrestaShop](https://www.prestashop.com/) version 1.7 and higher. The module allows you to accept payments in your PrestaShop store via our [API](https://docs.scanpay.dk/). We support and maintain the module, but we encourage you to help us improve the module. Feedback, bug reports and code contributions are much appreciated.

You can always e-mail us at [help@scanpay.dk](mailto:help@scanpay.dk) or chat with us on IRC at libera.chat #scanpay ([webchat](https://web.libera.chat/#scanpay)).

**Note:** We have a module for Thirty Bees which will also work for PrestaShop 1.6 ([link](https://github.com/scanpay/thirty-bees-scanpay)).

#### Requirements
- PHP >= 5.7
- php-curl
- PrestaShop >= 1.7

## Installation

1. Download the latest *prestashop-scanpay* zip file [here](https://github.com/scanpay/prestashop-scanpay/releases).
2. Enter the PrestaShop admin and navigate to `Modules`.
3. Click the *"upload a module"* button and select the zip file you just downloaded.

### Configuration

Before you begin, you need to generate an API key in our dashboard ([here](https://dashboard.scanpay.dk/settings/api)). Always keep your API key private and secure.

1. Enter the admin, navigate to `Modules` and press the *'Installed modules'* tab.
2. Find *"Scanpay"* and press *"configure"*.
3. Insert your API key in the *"API-key"* field.
4. Copy the contents of the *"Ping URL"* field into the corresponding field in our dashboard ([here](https://dashboard.scanpay.dk/settings/api)). After saving, press the *"Test Ping"* button.
5. Verify that the previously yellow box below Ping URL has turned green and says *"Ok!"*.
6. Navigate to `Payment > Preferences` in the left sidebar. Scroll down to *"Country Restrictions"* and enable Scanpay for the countries you see fit, then press *"save"*. Now scroll down to *"Carrier Restrictions"* and enable Scanpay for all carriers and press *"save"*.
7. You have now completed the installation and configuration of our PrestaShop module. We recommend performing a test order to ensure that everything is working as intended.
