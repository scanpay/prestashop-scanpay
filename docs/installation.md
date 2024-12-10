# Installation guide

## Install module

1. Download the latest _prestashop-scanpay_ zip file [here](https://github.com/scanpay/prestashop-scanpay/releases).
2. Enter the PrestaShop admin and navigate to `Modules`.
3. Click the _"upload a module"_ button and select the zip file you just downloaded.

### Configuration

Before you begin, you need to generate an API key in our dashboard ([here](https://dashboard.scanpay.dk/settings/api)). Always keep your API key private and secure.

1. Enter the admin, navigate to `Modules` and press the _'Installed modules'_ tab.
2. Find _"Scanpay"_ and press _"configure"_.
3. Insert your API key in the _"API-key"_ field.
4. Copy the contents of the _"Ping URL"_ field into the corresponding field in our dashboard ([here](https://dashboard.scanpay.dk/settings/api)). After saving, press the _"Test Ping"_ button.
5. Verify that the previously yellow box below Ping URL has turned green and says _"Ok!"_.
6. Navigate to `Payment > Preferences` in the left sidebar. Scroll down to _"Country Restrictions"_ and enable Scanpay for the countries you see fit, then press _"save"_. Now scroll down to _"Carrier Restrictions"_ and enable Scanpay for all carriers and press _"save"_.
7. You have now completed the installation and configuration of our PrestaShop module. We recommend performing a test order to ensure that everything is working as intended.

## Update module

> [!NOTE]
> Sometimes, you may need to uninstall the extension before updating. If this is the case, please follow the uninstall steps before updating.

1. Info coming soon ...

## Uninstall module

1. Info coming soon ...
