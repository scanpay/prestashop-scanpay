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

require_once dirname(__FILE__) . '/classes/spdb.php';

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
        $this->displayName = $this->l('Scanpay');
        $this->description = $this->l('Accept payments using the Scanpay payment gateway');
        $this->confirmUninstall = $this->l('Are you sure?');
    }

    public function install()
    {
        if (!SPDB_Seq::mktable()) {
            return false;
        }
        if (!SPDB_Carts::mktable()) {
            return false;
        }
        if (Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_ALL);
        }

        return parent::install()
            && $this->registerHook('displayPaymentReturn')
            && $this->registerHook('paymentOptions')
            && $this->registerHook('actionOrderStatusPostUpdate');
    }

    public function uninstall()
    {
        return parent::uninstall();
    }

    /**
     *  Log an ERROR message to the PrestaShop log
     */
    public function log($msg)
    {
        PrestaShopLogger::addLog(
            $msg,
            3, // severity: 3 = error
            null, // error code
            null, // object type
            null // object id
        );
    }

    /* Extract the shopid from an apikey */
    public function extractshopid($apikey)
    {
        $shopid = explode(':', $apikey)[0];
        if (!ctype_digit($shopid)) {
            return false;
        }

        return (int) $shopid;
    }

    /* Create array of payment options to be shown in checkout */
    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return;
        }

        $payopts = [];
        $title = Configuration::get('SCANPAY_TITLE') ?: 'Credit/Debit Card';
        $payopts[] = (new PaymentOption())->setCallToActionText($title)
            ->setAction($this->context->link->getModuleLink($this->name, 'newurl', [], true));

        if (Configuration::get('SCANPAY_MOBILEPAY')) {
            $payopts[] = (new PaymentOption())->setCallToActionText($this->l('MobilePay'))
                ->setAction($this->context->link->getModuleLink($this->name, 'newurl', ['paymentmethod' => 'mobilepay'], true));
        }

        return $payopts;
    }

    /* Handle the order confirmation page (post-payment) */
    public function hookDisplayPaymentReturn($params)
    {
        if (!isset($params['order']) || ($params['order']->module != $this->name)) {
            return false;
        }
        $order = $params['order'];
        if (Validate::isLoadedObject($order) && isset($order->valid)) {
            $this->smarty->assign([
                'id_order' => $order->id,
                'valid' => $order->valid,
            ]);
        }
        if (isset($order->reference) && !empty($order->reference)) {
            $this->smarty->assign('reference', $order->reference);
        }
        $this->smarty->assign([
            'shop_name' => $this->context->shop->name,
            'reference' => $order->reference,
            'contact_url' => $this->context->link->getPageLink('contact', true),
        ]);

        return $this->fetch('module:scanpay/views/templates/hook/payment_return.tpl');
    }

    /* Order status change hook */
    public function hookActionOrderStatusPostUpdate($params)
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
            $spdata = SPDB_Carts::load($order->id_cart);
            if (!$spdata) {
                $this->context->controller->errors[] = Tools::displayError($this->l('Failed to load scanpay transaction data'));

                return;
            }
            /* Already captured */
            if ((float) $spdata['captured'] > 0) {
                $this->context->controller->informations[] = $this->l('Order is already captured');

                return;
            }
            $currency = new Currency((int) $order->id_currency);
            $capturedata = [
                'total' => "{$order->total_paid} {$currency->iso_code}",
                'index' => $spdata['nacts'],
            ];
            require_once dirname(__FILE__) . '/classes/libscanpay.php';
            $cl = new Scanpay\Scanpay(Configuration::get('SCANPAY_APIKEY'));
            try {
                $cl->capture($spdata['trnid'], $capturedata);
                $this->context->controller->confirmations[] = $this->l('Order was successfully captured');
            } catch (Exception $e) {
                $this->context->controller->errors[] = Tools::displayError($this->l('Order capture failed: ') . $e->getMessage());
                $this->log('capture failed: ' . $e->getMessage());

                return;
            }
        }
    }

    public function fmtDeltaTime($dt)
    {
        $minute = 60;
        $hour = $minute * 60;
        $day = $hour * 24;
        if ($dt <= 1) {
            return '1 second ago';
        } elseif ($dt < $minute) {
            return (string) $dt . ' seconds ago';
        } elseif ($dt < $minute + 30) {
            return '1 minute ago';
        } elseif ($dt < $hour) {
            return (string) round((float) $dt / $minute) . ' minutes ago';
        } elseif ($dt < $hour + 30 * $minute) {
            return '1 hour ago';
        } elseif ($dt < $day) {
            return (string) round((float) $dt / $hour) . ' hours ago';
        } elseif ($dt < $day + 12 * $hour) {
            return '1 day ago';
        } else {
            return (string) round((float) $dt / $day) . ' days ago';
        }
    }

    public function getPingUrlStatus($mtime)
    {
        $t = time();
        if ($mtime > $t) {
            error_log('last modified time is in the future');

            return;
        }
        if ($t < $mtime + 900) {
            return 'ok';
        } elseif ($t < $mtime + 3600) {
            return 'warning';
        } elseif ($mtime > 0) {
            return 'error';
        } else {
            return 'never--pinged';
        }
    }

    /* Configuration handling (Settings) */
    public function getContent()
    {
        $captureOnStatus = Configuration::get('SCANPAY_CAPTURE_ON_ORDER_STATUS');
        $settings = [
            'SCANPAY_TITLE' => Configuration::get('SCANPAY_TITLE') ?? 'Credit/Debit Card',
            'SCANPAY_APIKEY' => Configuration::get('SCANPAY_APIKEY'),
            'SCANPAY_LANGUAGE' => Configuration::get('SCANPAY_LANGUAGE'),
            'SCANPAY_AUTOCAPTURE' => Configuration::get('SCANPAY_AUTOCAPTURE'),
            'SCANPAY_CAPTURE_ON_ORDER_STATUS[]' => empty($captureOnStatus) ? [] : explode(',', $captureOnStatus),
            'SCANPAY_MOBILEPAY' => Configuration::get('SCANPAY_MOBILEPAY'),
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

        /* Create Ping URL graphic */
        $shopid = $this->extractshopid($settings['SCANPAY_APIKEY']);
        $lastpingtime = ($shopid) ? SPDB_Seq::load($shopid)['mtime'] : 0;
        $pingurl = $this->context->link->getModuleLink($this->name, 'ping', [], true);
        $pingclass = 'scanpay--pingurl--' . $this->getPingUrlStatus($lastpingtime);
        $pingdt_desc = $this->fmtDeltaTime(time() - $lastpingtime);

        // Assign variables to the Smarty context
        $this->context->smarty->assign([
            'pingclass' => $pingclass,
            'pingdt_desc' => $pingdt_desc,
            'pingurl' => $pingurl,
        ]);
        $pingUrlContent = $this->context->smarty->fetch($this->local_path . 'views/templates/admin/pingurl.tpl');

        $this->context->controller->addCSS($this->local_path . 'views/css/settings.css');
        $this->context->controller->addJS($this->local_path . 'views/js/settings.js');

        $captureOnOrderStatusContent = $this->context->smarty->fetch($this->local_path . 'views/templates/admin/captureonorderstatus.tpl');
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
                    'desc' => $this->l('This controls the title which the user sees during checkout'),
                    'required' => true,
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('API-key'),
                    'name' => 'SCANPAY_APIKEY',
                    'desc' => $this->l('Copy your API key from the Scanpay dashboard'),
                    'required' => true,
                ],
                [
                    'type' => 'html',
                    'label' => $this->l('Ping URL'),
                    'name' => 'pingurl',
                    'desc' => $this->l('This is the URL Scanpay can use to notify PrestaShop of changes in transaction status.'),
                    'id' => 'scanpay--pingurl--input',
                    'readonly' => true,
                    'html_content' => $pingUrlContent,
                ],
                [
                    'type' => 'select',
                    'label' => $this->l('Language'),
                    'name' => 'SCANPAY_LANGUAGE',
                    'desc' => $this->l('Set the payment window language. \'Automatic\' allows Scanpay to choose a language based on the browser of the customer.'),
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
                    'label' => $this->l('Auto-capture'),
                    'name' => 'SCANPAY_AUTOCAPTURE',
                    'desc' => $this->l('Automatically capture transactions upon authorization. Only enable this if you sell services or immaterial goods.'),
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
                    'desc' => $this->l('Automatically capture orders when order status changes to one of the statuses selected above.'),
                    'html_content' => $captureOnOrderStatusContent,
                ],
                [
                    'type' => 'switch',
                    'label' => $this->l('MobilePay'),
                    'name' => 'SCANPAY_MOBILEPAY',
                    'desc' => $this->l('Enable MobilePay in checkout. You must also enable MobilePay in the Scanpay dashboard.'),
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
