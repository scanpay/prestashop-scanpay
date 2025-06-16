<?php
/**
 * @author    Scanpay <contact@scanpay.dk>
 * @copyright Scanpay ApS. All rights reserved.
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/../../classes/libscanpay.php';

class ScanpayPingModuleFrontController extends ModuleFrontController
{
    private $DB;
    private $tSeq = _DB_PREFIX_ . 'scanpay_seq';
    private $tCart = _DB_PREFIX_ . 'scanpay_carts';

    public function __construct()
    {
        parent::__construct();
        $this->DB = Db::getInstance();
        $this->ajax = true;
    }

    private function currencyFloat(string $str): float
    {
        $num = explode(' ', $str)[0];
        if (!is_numeric($num)) {
            throw new Exception('Invalid money value received from Scanpay: ' . $str);
        }
        return (float) $num;
    }

    private function insertMeta(int $cartid, int $orderid, array $c): void
    {
        $trnid = (int) $c['id'];
        $rev = (int) $c['rev'];
        $nacts = count($c['acts']);
        $authorized = $this->currencyFloat($c['totals']['authorized']);
        $captured = $this->currencyFloat($c['totals']['captured']);
        $refunded = $this->currencyFloat($c['totals']['refunded']);
        $voided = $this->currencyFloat($c['totals']['voided']);

        $insert = $this->DB->execute(
            "INSERT INTO $this->tCart
            SET cartid = $cartid, trnid = $trnid, orderid = $orderid, rev = $rev, nacts = $nacts, authorized = $authorized,
                captured = $captured, refunded = $refunded, voided = $voided"
        );
        if (!$insert) {
            throw new Exception("could not save payment data to order #$orderid");
        }
    }

    private function updateMeta(int $cartid, int $orderid, array $c): void
    {
        $rev = (int) $c['rev'];
        $nacts = count($c['acts']);
        $captured = $this->currencyFloat($c['totals']['captured']);
        $refunded = $this->currencyFloat($c['totals']['refunded']);
        $voided = $this->currencyFloat($c['totals']['voided']);

        $update = $this->DB->execute(
            "UPDATE $this->tCart
            SET rev = $rev, nacts = $nacts, captured = $captured, refunded = $refunded, voided = $voided
            WHERE cartid = $cartid"
        );
        if (!$update) {
            throw new Exception("could not save payment data to order #$orderid");
        }
    }

    private function addPaymentDetails(int $oid, array $c): void
    {
        $order = new Order($oid);
        $payments = OrderPayment::getByOrderReference($order->reference);
        if (empty($payments)) {
            return;
        }
        $payment = $payments[0];
        if ($payment->payment_method !== 'scanpay' || (int) $payment->transaction_id !== (int) $c['id']) {
            return;
        }
        $method = $c['method'][$c['method']['type']];
        if (isset($method['last4'])) {
            $payment->card_number = $method['last4'];
        }
        if (isset($method['brand'])) {
            $brand = $method['brand'];
            if ($brand === 'visadankort') {
                $brand = 'Visa/Dankort';
            }
            $payment->card_brand = ucfirst($brand);
        }
        if (isset($method['exp'])) {
            // Convert Unix timestamp to 'YYYY-MM'
            $payment->card_expiration = date('Y-m', $method['exp']);
        }
        $payment->update();
    }

    private function sync(int $shopid, int $seq, int $pingSeq): void
    {
        $scanpay = new Scanpay();
        $client = new ScanpayClient(Configuration::get('SCANPAY_APIKEY'));

        while ($pingSeq > $seq) {
            $res = $client->seq($seq);
            if (!$res['changes'] || $res['seq'] <= $seq) {
                return;
            }
            foreach ($res['changes'] as $c) {
                if (isset($c['error']) || $c['type'] !== 'transaction') {
                    continue;
                }

                // Check if order id is a cart id
                if (str_starts_with($c['orderid'], 'cart_')) {
                    $num = substr($c['orderid'], 5);
                    if (!ctype_digit($num)) {
                        PrestaShopLogger::addLog("Could not parse cart id from Scanpay order {$c['orderid']} (trnid={$c['id']})", 2);
                        continue;
                    }
                    $cartid = (int) $num;
                    $row = $this->DB->getRow("SELECT * FROM $this->tCart WHERE cartid = $cartid");
                    if ($row) {
                        if ((int) $row['rev'] >= (int) $c['rev']) {
                            continue;
                        }
                        $orderid = (int) $row['orderid'];
                        $this->updateMeta($cartid, $orderid, $c);
                    } elseif (Order::getIdByCartId($cartid) === false) {
                        $cart = new Cart($cartid);
                        $authorized = self::currencyFloat($c['totals']['authorized']);
                        $status = $scanpay->validateOrder(
                            $cartid,                    // Cart ID
                            _PS_OS_PAYMENT_,            // Order state (payment accepted)
                            $authorized,                // Authorized amount
                            'scanpay',                  // Payment method title
                            null,                       // Optional message (none)
                            [
                                'transaction_id' => (int) $c['id'],
                            ],
                            null,                       // Currency (default)
                            false,                      // $dont_touch_amount
                            $cart->secure_key           // Secure key for the cart
                        );
                        if (!$status) {
                            PrestaShopLogger::addLog("failed to validate order (trnid={$c['id']})", 3);
                            continue;
                        }
                        $orderid = (int) Order::getIdByCartId($cartid);
                        $this->addPaymentDetails($orderid, $c);
                        $this->insertMeta($cartid, $orderid, $c);
                    }
                }
            }
            // Update seq in the database
            $seq = (int) $res['seq'];
            $sql = "UPDATE $this->tSeq SET seq = $seq, mtime = " . time() . " WHERE shopid = $shopid";
            if (!$this->DB->execute($sql, false)) {
                throw new Exception('failed to update mtime');
            }
        }
    }

    public function postProcess(): void
    {
        if (!isset($_SERVER['HTTP_X_SIGNATURE'])) {
            exit;
        }
        $signature = $_SERVER['HTTP_X_SIGNATURE'];
        $apikey = Configuration::get('SCANPAY_APIKEY') ?: '';
        $shopid = (int) explode(':', $apikey)[0];
        if (!$shopid) {
            exit;
        }
        $body = file_get_contents('php://input', false, null, 0, 512);
        if (!hash_equals(base64_encode(hash_hmac('sha256', $body, $apikey, true)), $signature)) {
            exit;
        }
        try {
            ignore_user_abort(true);
            set_time_limit(0);

            $ping = json_decode($body, true);
            $row = $this->DB->getRow("SELECT seq FROM $this->tSeq WHERE shopid = $shopid", false);
            if (!$row) {
                throw new Exception('failed to load seq');
            }
            $seq = (int) $row['seq'];
            if ($ping['seq'] == $seq) {
                // No new events. Update mtime and return success
                $sql = "UPDATE $this->tSeq SET mtime = " . time() . " WHERE shopid = $shopid";
                if (!$this->DB->execute($sql, false)) {
                    throw new Exception('failed to update mtime');
                }
            } else {
                $this->sync($shopid, $seq, $ping['seq']);
            }
        } catch (Exception $e) {
            PrestaShopLogger::addLog('Encountered error while updating: ' . $e->getMessage(), 3);
            exit(json_encode(['error' => 'failed to update orders']));
        }
        exit(json_encode(['success' => true]));
    }
}
