<?php
/**
 * @author    Scanpay <contact@scanpay.dk>
 * @copyright Scanpay ApS. All rights reserved.
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/../../classes/spdb.php';
require_once dirname(__FILE__) . '/../../classes/orderupdater.php';

class ScanpaySuccessModuleFrontController extends ModuleFrontController
{
    public function __construct()
    {
        parent::__construct();
        unset($this->context->cookie->id_cart);
    }

    public function postProcess()
    {
        $cartid = Tools::getValue('cartid');
        $key = Tools::getValue('key');
        if (!$cartid) {
            exit('missing cart');
        }
        $cart = new Cart((int) $cartid);
        if (!$cart || $key !== $cart->secure_key) {
            exit('cart not found');
        }

        $scanpay = new Scanpay();
        for ($i = 0; $i < 6; ++$i) {
            if ($i === 5) {
                PrestaShopLogger::addLog('Could not find order in system. Verify that your Scanpay Ping URL is correctly set in the Scanpay Dashboard.', 3);
                $shopid = (int) explode(':', Configuration::get('SCANPAY_APIKEY'))[0];
                $myseq = (int) SPDB_Seq::load($shopid)['seq'];
                try {
                    SPOrderUpdater::update($shopid, $myseq, false);
                } catch (Exception $e) {
                    PrestaShopLogger::addLog('Order updater exception: ' . $e->getMessage(), 3);
                }
            }
            $data = SPDB_Carts::load($cartid);
            if (!$data) {
                exit('invalid cart');
            }
            if ((float) $data['authorized'] > 0) {
                break;
            }
            sleep(1);
        }
        if ($i === 6) {
            exit('Timeout while waiting for payment data. Please contact the shop.');
        }
        /* Reload cart */
        $cart = new Cart((int) $cartid);
        $data = [
            'controller' => 'order-confirmation',
            'id_cart' => (int) $cartid,
            'id_module' => $scanpay->id,
            'id_order' => (int) $data['orderid'],
            'key' => $key,
        ];
        Tools::redirect(__PS_BASE_URI__ . 'index.php?' . http_build_query($data));
    }
}
