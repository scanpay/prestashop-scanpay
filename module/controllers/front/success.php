<?php
/**
 * @author    Scanpay <contact@scanpay.dk>
 * @copyright Scanpay ApS. All rights reserved.
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class ScanpaySuccessModuleFrontController extends ModuleFrontController
{
    public function __construct()
    {
        parent::__construct();
        unset($this->context->cookie->id_cart);
    }

    public function postProcess(): void
    {
        $cartid = (int) Tools::getValue('cartid');
        if (!$cartid) {
            exit('missing cart');
        }
        $cart = new Cart($cartid);
        $key = Tools::getValue('key');
        if (!$cart || $key !== $cart->secure_key) {
            exit('cart not found');
        }
        $DB = Db::getInstance();
        $table = _DB_PREFIX_ . 'scanpay_carts';
        $row = null;

        for ($i = 0; $i < 10; ++$i) {
            usleep(500000 + $i * 100000);
            $row = $DB->getRow("SELECT orderid FROM $table WHERE cartid = $cartid", false);
            if ($row !== false) {
                break;
            }
        }
        $oid = ($row !== false) ? (int) $row['orderid'] : (int) Order::getIdByCartId($cartid);
        if (!$oid) {
            PrestaShopLogger::addLog("Scanpay success.php: could not resolve orderid for cartid=$cartid");
            exit('order not found');
        }
        $scanpay = new Scanpay();
        $data = [
            'controller' => 'order-confirmation',
            'id_cart' => $cartid,
            'id_module' => $scanpay->id,
            'id_order' => $oid,
            'key' => $key,
        ];
        Tools::redirect(__PS_BASE_URI__ . 'index.php?' . http_build_query($data));
    }
}
