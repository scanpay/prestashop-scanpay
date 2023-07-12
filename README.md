# Scanpay for PrestaShop

[![Latest Release](https://img.shields.io/github/v/release/scanpay/prestashop-scanpay?cacheSeconds=600)](https://github.com/scanpay/prestashop-scanpay/releases)
[![License](https://img.shields.io/github/license/scanpay/prestashop-scanpay?cacheSeconds=60000)](./LICENSE)

We have developed an official payment module for [PrestaShop](https://www.prestashop.com/) version 1.7 and higher. The module allows you to accept payments in your PrestaShop store. We support and maintain the module, but we hope you will help us improve it. Feedback, bug reports and code contributions are much appreciated.

If you have any questions, concerns or ideas, please do not hesitate to e-mail us at [support@scanpay.dk](mailto:support@scanpay.dk) or chat with the development team on our IRC server [`irc.scanpay.dev:6697`](https://chat.scanpay.dev).

**Note:** We have a module for Thirty Bees which will also work for PrestaShop 1.6 ([link](https://github.com/scanpay/thirty-bees-scanpay)).

## Requirements

- PrestaShop >= 1.7
- PHP version >= 5.7 with php-curl enabled. See [compatibility table](#compatibility-table).

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

## Compatibility table

| Feature                                   | Version |
| :---------------------------------------- | :-----: |
| hash_equals                               | 5.6     |
| curl_strerror                             | 5.5     |
| Array, short syntax                       | 5.4     |
| Namespaces                                | 5.3.0   |
| json_decode                               | 5.2.0   |
| curl_setopt_array                         | 5.1.3   |
| hash_hmac                                 | 5.1.2   |
| Exception class                           | 5.1.0   |
| Default function parameters               | 5.0.0   |

## License

Everything in this repository is licensed under the [MIT license](LICENSE).


