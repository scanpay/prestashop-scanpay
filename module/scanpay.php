<?php
/**
 * @author    Scanpay <contact@scanpay.dk>
 * @copyright Scanpay ApS. All rights reserved.
 * @license   https://opensource.org/licenses/MIT MIT License
 */

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Scanpay extends PaymentModule
{
    protected $_html = '';
    protected $_postErrors = [];

    public $is_eu_compatible;

    public function __construct()
    {
        $this->name = 'scanpay';
        $this->tab = 'payments_gateways';
        $this->version = '{{ VERSION }}';
        $this->ps_versions_compliancy = ['min' => '1.7', 'max' => _PS_VERSION_];
        $this->author = 'Scanpay ApS';
        $this->need_instance = 0;
        $this->is_eu_compatible = 1;
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = 'Scanpay';
        $this->description = $this->l('Accept payments using the Scanpay payment gateway');
    }

    public function install(): bool
    {
        $DB = Db::getInstance();
        $DB->execute(
            'CREATE TABLE IF NOT EXISTS ' . _DB_PREFIX_ . 'scanpay_seq' . ' (
            `shopid` BIGINT UNSIGNED NOT NULL PRIMARY KEY UNIQUE,
            `seq`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `mtime`  BIGINT UNSIGNED NOT NULL DEFAULT 0
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;'
        );

        $DB->execute(
            'CREATE TABLE IF NOT EXISTS ' . _DB_PREFIX_ . 'scanpay_carts' . ' (
            `cartid`     BIGINT UNSIGNED NOT NULL PRIMARY KEY UNIQUE,
            `shopid`     BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `trnid`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `orderid`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `rev`        BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `nacts`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `authorized` decimal(20,6) NOT NULL DEFAULT "0.00",
            `captured`   decimal(20,6) NOT NULL DEFAULT "0.00",
            `refunded`   decimal(20,6) NOT NULL DEFAULT "0.00",
            `voided`     decimal(20,6) NOT NULL DEFAULT "0.00"
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;'
        );
        if (Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_ALL);
        }
        return parent::install()
            && $this->registerHook('displayPaymentReturn')
            && $this->registerHook('paymentOptions')
            && $this->registerHook('actionOrderStatusPostUpdate');
    }

    public function uninstall(): bool
    {
        return parent::uninstall();
    }

    /* Create array of payment options to be shown in checkout */
    public function hookPaymentOptions(array $params): array
    {
        if (!$this->active) {
            return [];
        }

        $payopts = [];
        $title = Configuration::get('SCANPAY_TITLE');
        $payopts[] = (new PaymentOption())->setCallToActionText($title)
            ->setAction($this->context->link->getModuleLink($this->name, 'newurl', [], true));

        if (Configuration::get('SCANPAY_MOBILEPAY')) {
            $payopts[] = (new PaymentOption())->setCallToActionText('MobilePay')
                ->setAction($this->context->link->getModuleLink($this->name, 'newurl', ['paymentmethod' => 'mobilepay'], true));
        }
        if (Configuration::get('SCANPAY_APPLEPAY')) {
            $payopts[] = (new PaymentOption())->setCallToActionText('ApplePay')
                ->setAction($this->context->link->getModuleLink($this->name, 'newurl', ['paymentmethod' => 'applepay'], true));
        }

        return $payopts;
    }

    /**
     * Show an infobox on the order confirmation page.
     * @param array $params Parameters passed to the hook
     * @return string Rendered template content
     */
    public function hookDisplayPaymentReturn(array $params): string
    {
        $order = $params['order'];
        if (empty($order) || Validate::isLoadedObject($order) === false || $order->module !== $this->name) {
            return '';
        }
        $payment = $order->getOrderPaymentCollection()->getFirst();
        if (!$payment) {
            return '';
        }
        $currency = new Currency($order->id_currency);
        $auth = Context::getContext()->getCurrentLocale()->formatPrice($order->total_paid, $currency->iso_code);

        $this->context->smarty->assign([
            'trnid' => '#' . $payment->transaction_id,
            'auth' => '<b>' . $auth . '</b>',
            'last4' => '<em>' . $payment->card_number . '</em>',
            'brand' => $payment->card_brand,
        ]);
        return $this->context->smarty->fetch('module:scanpay/views/templates/hook/displayPaymentReturn.tpl');
    }

    /* Order status change hook */
    public function hookActionOrderStatusPostUpdate(array $params): void
    {
        $states = Configuration::get('SCANPAY_CAPTURE_ON_ORDER_STATUS');

        if (!empty($states)) {
            $order = new Order($params['id_order']);
            $states = explode(',', $states);
            $doCapture = false;
            foreach ($states as $state) {
                if ($params['newOrderStatus']->id === (int) $state) {
                    $doCapture = true;
                    break;
                }
            }
            if (!$doCapture) {
                return;
            }

            $DB = Db::getInstance();
            $table = _DB_PREFIX_ . 'scanpay_carts';
            $meta = $DB->getRow("SELECT * FROM $table WHERE cartid = $order->id_cart");
            if (!$meta) {
                throw new Exception($this->l('Failed to load scanpay transaction data'));
            }
            if ((float) $meta['captured'] > 0) {
                $this->context->controller->informations[] = $this->l('Order is already captured');

                return;
            }
            $currency = new Currency((int) $order->id_currency);
            $capturedata = [
                'total' => "{$order->total_paid} {$currency->iso_code}",
                'index' => $meta['nacts'],
            ];

            require_once dirname(__FILE__) . '/classes/libscanpay.php';
            $client = new ScanpayClient(Configuration::get('SCANPAY_APIKEY'));
            try {
                $client->capture($meta['trnid'], $capturedata);
                $this->context->controller->confirmations[] = $this->l('Order was successfully captured');
            } catch (Exception $e) {
                throw new Exception($this->l('Order capture failed: ') . $e->getMessage());
            }
        }
    }

    private function fmtDeltaTime(int $dt): string
    {
        if ($dt <= 1) {
            return '1 second ago';
        } elseif ($dt < 120) {
            return (string) $dt . ' seconds ago';
        } elseif ($dt < 3600) {
            return round($dt / 60) . ' minutes ago';
        }
        return round($dt / 3600) . ' hours ago';
    }

    private function getPingUrlStatus(int $dt): string
    {
        if ($dt === null) {
            return 'scanpay--pingurl--never--pinged';
        }
        if ($dt < 0) {
            PrestaShopLogger::addLog('last modified time is in the future', 3);
            return 'scanpay--pingurl--error';
        } elseif ($dt < 900) {
            return 'scanpay--pingurl--ok';
        } elseif ($dt < 3600) {
            return 'scanpay--pingurl--warning';
        } else {
            return 'scanpay--pingurl--error';
        }
    }

    private function getMtime(int $shopid): ?int
    {
        $DB = Db::getInstance();
        $table = _DB_PREFIX_ . 'scanpay_seq';
        $row = $DB->getRow("SELECT mtime FROM $table WHERE shopid = $shopid", false);
        if (!$row || $row['mtime'] == 0) {
            return null;
        }
        return time() - $row['mtime'];
    }

    /* Configuration handling (Settings) */
    public function getContent(): string
    {
        $captureOnStatus = Configuration::get('SCANPAY_CAPTURE_ON_ORDER_STATUS');
        $settings = [
            'SCANPAY_TITLE' => Configuration::get('SCANPAY_TITLE') ?? 'Credit/Debit Card',
            'SCANPAY_APIKEY' => Configuration::get('SCANPAY_APIKEY'),
            'SCANPAY_LANGUAGE' => Configuration::get('SCANPAY_LANGUAGE'),
            'SCANPAY_AUTOCAPTURE' => Configuration::get('SCANPAY_AUTOCAPTURE'),
            'SCANPAY_CAPTURE_ON_ORDER_STATUS[]' => empty($captureOnStatus) ? [] : explode(',', $captureOnStatus),
            'SCANPAY_MOBILEPAY' => Configuration::get('SCANPAY_MOBILEPAY'),
            'SCANPAY_APPLEPAY' => Configuration::get('SCANPAY_APPLEPAY'),
        ];

        // Update configuration if config is submitted (POST)
        if (Tools::isSubmit('submit' . $this->name)) {
            $captureOnStatus = Tools::getValue('SCANPAY_CAPTURE_ON_ORDER_STATUS');
            $settings = [
                'SCANPAY_TITLE' => (string) Tools::getValue('SCANPAY_TITLE'),
                'SCANPAY_APIKEY' => (string) Tools::getValue('SCANPAY_APIKEY'),
                'SCANPAY_LANGUAGE' => (string) Tools::getValue('SCANPAY_LANGUAGE'),
                'SCANPAY_AUTOCAPTURE' => (int) Tools::getValue('SCANPAY_AUTOCAPTURE'),
                'SCANPAY_CAPTURE_ON_ORDER_STATUS[]' => empty($captureOnStatus) ? [] : $captureOnStatus,
                'SCANPAY_MOBILEPAY' => (int) Tools::getValue('SCANPAY_MOBILEPAY'),
                'SCANPAY_APPLEPAY' => (int) Tools::getValue('SCANPAY_APPLEPAY'),
            ];
            foreach ($settings as $key => $value) {
                if (substr($key, -2) === '[]') {
                    $key = substr($key, 0, -2);
                    $value = implode(',', $value);
                }
                Configuration::updateValue($key, $value);
            }
        }

        // Setup the configuration form
        $helper = new HelperForm();
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submit' . $this->name;
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        // Create Ping URL graphic
        $apikey = Configuration::get('SCANPAY_APIKEY') ?: '';
        $shopid = (int) explode(':', $apikey)[0];
        $pingDtime = $this->getMtime($shopid);

        // Assign variables to the Smarty context
        $this->context->smarty->assign([
            'pingclass' => $this->getPingUrlStatus($pingDtime),
            'pingdt_desc' => $this->fmtDeltaTime($pingDtime),
            'pingurl' => $this->context->link->getModuleLink($this->name, 'ping', [], true),
        ]);
        $pingUrlContent = $this->context->smarty->fetch($this->local_path . 'views/templates/admin/pingurl.tpl');

        $this->context->controller->addCSS($this->local_path . 'views/css/settings.css');
        $this->context->controller->addJS($this->local_path . 'views/js/settings.js');

        $captureOnOrderStatusContent = $this->context->smarty->fetch($this->local_path . 'views/templates/admin/captureonorderstatus.tpl');
        $captureOnOrderStatusList = [];
        foreach (OrderState::getOrderStates($this->context->language->id) as $status) {
            $captureOnOrderStatusList[] = ['status' => $status['id_order_state'], 'name' => $status['name']];
        }

        /* Define the configuration form inputs */
        $formdata[0]['form'] = [
            'legend' => [
                'title' => $this->l('Settings'),
            ],
            'input' => [
                [
                    'type' => 'text',
                    'label' => $this->l('Title'),
                    'name' => 'SCANPAY_TITLE',
                    'desc' => $this->l('Sets the title displayed to the user during checkout.'),
                    'required' => true,
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('API-key'),
                    'name' => 'SCANPAY_APIKEY',
                    'desc' => $this->l('Copy your API key from the Scanpay dashboard.'),
                    'required' => true,
                ],
                [
                    'type' => 'html',
                    'label' => 'Ping URL',
                    'name' => 'pingurl',
                    'desc' => $this->l('Copy this URL to the Scanpay dashboard to enable data synchronization.'),
                    'id' => 'scanpay--pingurl--input',
                    'readonly' => true,
                    'html_content' => $pingUrlContent,
                ],
                [
                    'type' => 'select',
                    'label' => $this->l('Language'),
                    'name' => 'SCANPAY_LANGUAGE',
                    'desc' => $this->l('Choose the payment window language. Select \'Automatic\' to let Scanpay match the language to the customer\'s browser settings.'),
                    'options' => [
                        'id' => 'language',
                        'name' => 'name',
                        'query' => [
                            [
                                'language' => '',
                                'name' => $this->l('Automatic'),
                            ],
                            [
                                'language' => 'da',
                                'name' => $this->l('Danish'),
                            ],
                            [
                                'language' => 'en',
                                'name' => $this->l('English'),
                            ],
                        ],
                    ],
                ],
                [
                    'type' => 'switch',
                    'label' => 'Auto-capture',
                    'name' => 'SCANPAY_AUTOCAPTURE',
                    'desc' => $this->l('Enable automatic capture of transactions upon authorization. Use this only if you sell services or digital goods.'),
                    'is_bool' => true,
                    'values' => [
                        [
                            'id' => 'on',
                            'value' => 1,
                            'name' => $this->l('Enabled'),
                        ],
                        [
                            'id' => 'off',
                            'value' => 0,
                            'name' => $this->l('Disabled'),
                        ],
                    ],
                ],
                [
                    'type' => 'select',
                    'label' => '',
                    'name' => 'SCANPAY_CAPTURE_ON_ORDER_STATUS[]',
                    'multiple' => true,
                    'options' => [
                        'query' => $captureOnOrderStatusList,
                        'id' => 'status',
                        'name' => 'name',
                    ],
                    'class' => 'scanpay--hide',
                ],
                [
                    'type' => 'html',
                    'label' => $this->l('Capture on order status'),
                    'name' => 'SCANPAY_CAPTURE_ON_ORDER_STATUS_DUMMY',
                    'desc' => $this->l('Automatically capture payments when the order status changes to one of the selected statuses above.'),
                    'html_content' => $captureOnOrderStatusContent,
                ],
                [
                    'type' => 'switch',
                    'label' => 'MobilePay',
                    'name' => 'SCANPAY_MOBILEPAY',
                    'desc' => $this->l('Enable MobilePay as a payment option in checkout. Make sure MobilePay is also activated in your Scanpay Dashboard.'),
                    'is_bool' => true,
                    'values' => [
                        [
                            'id' => 'on',
                            'value' => 1,
                            'name' => $this->l('Enabled'),
                        ],
                        [
                            'id' => 'off',
                            'value' => 0,
                            'name' => $this->l('Disabled'),
                        ],
                    ],
                ],
                [
                    'type' => 'switch',
                    'label' => 'ApplePay',
                    'name' => 'SCANPAY_APPLEPAY',
                    'desc' => $this->l('Enable ApplePay as a payment option in checkout. Make sure ApplePay is also activated in your Scanpay Dashboard.'),
                    'is_bool' => true,
                    'values' => [
                        [
                            'id' => 'on',
                            'value' => 1,
                            'name' => $this->l('Enabled'),
                        ],
                        [
                            'id' => 'off',
                            'value' => 0,
                            'name' => $this->l('Disabled'),
                        ],
                    ],
                ],
            ],
            'submit' => [
                'title' => $this->l('Save'),
                'class' => 'btn btn-default pull-right',
            ],
        ];

        foreach ($settings as $key => $value) {
            $helper->fields_value[$key] = $value;
        }

        return $helper->generateForm($formdata);
    }
}
