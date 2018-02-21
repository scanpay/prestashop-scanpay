<?php

require_once(dirname(__FILE__) . '/libscanpay.php');
require_once(dirname(__FILE__) . '/spdb.php');

class SPOrderUpdater
{

    private static function getcurnum($str)
    {
        $num = explode(' ', $str)[0];
        $parts = explode('.', $num);
        $n = count($parts);
        if ($n !== 1 && $n !== 2) {
            throw new \Exception('invalid money value received from Scanpay ' . $str);
        }
        foreach ($parts as $p) {
            for ($i = 0; $i < strlen($p); $i++) {
                if ($p[$i] < '0' || $p[$i] > '9') {
                    throw new \Exception('invalid money value received from Scanpay ' . $str);
                }
            }
        }
        return $num;
    }

	static function update($shopid, $myseq, $updatemtime = true)
	{
        $scanpay = new Scanpay();
		$cl = new Scanpay\Scanpay(Configuration::get('SCANPAY_APIKEY'));
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
                $orderid = $change['orderid'];

                /* Extract the cartid from the order id */
                $arr = explode('_', $orderid);
                if (count($arr) !== 2 || $arr[0] !== 'cart' || !filter_var($arr[1], FILTER_VALIDATE_INT)) {
                    $scanpay->log('Could not parse cart id from scanpay order ' .
                        $orderid . ' (trnid=' . $change['id'] . ')');
                    continue;
                }
                $cartid = (int)$arr[1];

                /* Load the cart entry created upon payment link generation */
                $row = SPDB_Carts::load($cartid);
                if (!$row) {
                    $scanpay->log("no matching cart #$cartid (trnid=$change[id])");
                    continue;
                }
                if ((int)$row['shopid'] !== $shopid) {
                    $scanpay->log("seq shopid does not match stored shopid for cart #$cartid (trnid=$change[id])");
                    continue;
                }
                if ((int)$row['rev'] >= (int)$change['rev']) {
                    continue;
                }

                $authorized = self::getcurnum($change['totals']['authorized']);

                /* Get the prestashop orderid from the cartid */
                $psorderid = method_exists('Order', 'getIdByCartId') ? Order::getIdByCartId($cartid) : Order::getOrderByCartId($cartid);

                /* Create a new order, if one has not been assigned to the cart yet */
                if ($psorderid === false) {
                    $title = 'Scanpay';
                    $auth = $change['totals']['authorized'];
                    $amount = floatval(explode(' ', $auth)[0]);
                    $extra_vars = [
                        'transaction_id' => $change['id'],
                    ];
                    $cart = new Cart($cartid);
                    $extra = [ 'transaction_id' => (int)$change['id'] ];
                    if (!$scanpay->validateOrder($cartid, _PS_OS_PAYMENT_, (float)$authorized, $title, null, $extra, null, false, $cart->secure_key)) {
                        $scanpay->log('failed to validate order (trnid=' . $change['id'] . ')');
                        continue;
                    }
                    $psorderid = Order::getIdByCartId($cartid);
                }

                /* Register order data */
                SPDB_Carts::update($cartid, $shopid, $change);
            }

            $myseq = (int)$res['seq'];

            /* Save the new seq */
            $updated = SPDB_Seq::save($shopid, $myseq, $updatemtime);
            if (!$updated) {
                return;
            }
        }
	}
}