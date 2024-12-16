<?php
/**
 * @author    Scanpay <contact@scanpay.dk>
 * @copyright Scanpay ApS. All rights reserved.
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/libscanpay.php';
require_once dirname(__FILE__) . '/spdb.php';

class SPOrderUpdater
{
    private static function currencyFloat($str)
    {
        $num = explode(' ', $str)[0];
        if (!is_numeric($num)) {
            throw new Exception('Invalid money value received from Scanpay: ' . $str);
        }
        return (float) $num;
    }

    public static function update($shopid, $myseq, $updatemtime = true)
    {
        $scanpay = new Scanpay();
        $cl = new ScanpayClient(Configuration::get('SCANPAY_APIKEY'));
        /* Run the synchronization process */
        while (1) {
            /* Perform a Scanpay Seq request */
            $res = $cl->seq($myseq);

            /* Validate that the response seq is higher than the current seq */
            if ($res['seq'] <= $myseq) {
                return;
            }

            /* Loop through all the changes */
            foreach ($res['changes'] as $change) {
                if ($change['type'] !== 'transaction') {
                    continue;
                }
                $orderid = $change['orderid'];

                /* Extract the cartid from the order id */
                $arr = explode('_', $orderid);
                if (count($arr) !== 2 || $arr[0] !== 'cart' || !filter_var($arr[1], FILTER_VALIDATE_INT)) {
                    PrestaShopLogger::addLog("Could not parse cart id from scanpay order $orderid (trnid=" . $change['id'] . ')', 3);
                    continue;
                }
                $cartid = (int) $arr[1];

                /* Load the cart entry created upon payment link generation */
                $row = SPDB_Carts::load($cartid);
                if (!$row) {
                    PrestaShopLogger::addLog("no matching cart #$cartid (trnid=" . $change['id'] . ')', 3);
                    continue;
                }
                if ((int) $row['shopid'] !== $shopid) {
                    PrestaShopLogger::addLog("seq shopid does not match stored shopid for cart #$cartid (trnid=" . $change['id'] . ')', 3);
                    continue;
                }
                if ((int) $row['rev'] >= (int) $change['rev']) {
                    continue;
                }

                $authorized = self::currencyFloat($change['totals']['authorized']);

                /* Get the prestashop orderid from the cartid */
                $psorderid = method_exists('Order', 'getIdByCartId') ? Order::getIdByCartId($cartid) : Order::getOrderByCartId($cartid);

                /* Create a new order, if one has not been assigned to the cart yet */
                if ($psorderid === false) {
                    $title = 'Scanpay';
                    $cart = new Cart($cartid);
                    $extra = ['transaction_id' => (int) $change['id']];
                    if (!$scanpay->validateOrder($cartid, _PS_OS_PAYMENT_, (float) $authorized, $title, null, $extra, null, false, $cart->secure_key)) {
                        PrestaShopLogger::addLog('failed to validate order (trnid=' . $change['id'] . ')', 3);
                        continue;
                    }
                }

                /* Register order data */
                SPDB_Carts::update($cartid, $shopid, $change);
            }

            $myseq = (int) $res['seq'];

            /* Save the new seq */
            $updated = SPDB_Seq::save($shopid, $myseq, $updatemtime);
            if (!$updated) {
                return;
            }
        }
    }
}
