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
require_once dirname(__FILE__) . '/../../classes/spdb.php';

class ScanpayNewurlModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $scanpay = new Scanpay();
        $cart = $this->context->cart;
        if (!Validate::isLoadedObject($cart)) {
            $scanpay->log('Invalid cart', 1, 'Cart', $cart->id);
            exit($scanpay->l('Invalid cart'));
        }

        $apikey = Configuration::get('SCANPAY_APIKEY');
        $shopid = $scanpay->extractshopid($apikey);
        if (!$shopid) {
            $scanpay->log('invalid Scanpay API-key scheme');
            exit($scanpay->l('Internal server error, please contact the shop.'));
        }

        $cl = new Scanpay\Scanpay($apikey, [
            'headers' => [
                'X-Shop-Plugin' => 'prestashop/' . _PS_VERSION_ . '/{{ VERSION }}',
            ],
        ]);
        $bill = new Address((int) $cart->id_address_invoice);
        $ship = new Address((int) $cart->id_address_delivery);
        $successurl = $this->context->link->getModuleLink($scanpay->name, 'success', [
            'cartid' => $cart->id,
            'key' => $cart->secure_key,
        ], true);

        $data = [
            'orderid' => 'cart_' . $cart->id,
            'language' => Configuration::get('SCANPAY_LANGUAGE'),
            'successurl' => $successurl,
            'autocapture' => (bool) Configuration::get('SCANPAY_AUTOCAPTURE'),
            'billing' => array_filter([
                'name' => $bill->firstname . ' ' . $bill->lastname,
                'email' => $this->context->customer->email,
                'phone' => preg_replace('/\s+/', '', $bill->phone),
                'address' => array_filter([$bill->address1, $bill->address2]),
                'city' => $bill->city,
                'zip' => $bill->postcode,
                'country' => strtolower((new Country($bill->id_country))->iso_code),
                'state' => $bill->id_state ? strtolower((new Country($bill->id_state))->iso_code) : '',
                'company' => $bill->company,
                'vatin' => $bill->vat_number,
                'gln' => '',
            ]),
            'shipping' => array_filter([
                'name' => $ship->firstname . ' ' . $ship->lastname,
                'phone' => preg_replace('/\s+/', '', $ship->phone),
                'address' => array_filter([$ship->address1, $ship->address2]),
                'city' => $ship->city,
                'zip' => $ship->postcode,
                'country' => strtolower((new Country($ship->id_country))->iso_code),
                'state' => $ship->id_state ? strtolower((new Country($ship->id_state))->iso_code) : '',
                'company' => $ship->company,
            ]),
        ];

        /* Add all items from the cart to the order */
        $items = [];
        foreach ($cart->getProducts() as $product) {
            $linetotal = $cart->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING, [$product]);
            $items[] = [
                'name' => $product['name'],
                'quantity' => intval($product['cart_quantity']),
                'total' => $linetotal,
                'sku' => strval($product['id_product']),
            ];
        }

        /* Add shipping costs if applicable */
        $shipcosts = $cart->getOrderTotal(true, Cart::ONLY_SHIPPING);
        if ($shipcosts > 0) {
            $items[] = [
                'name' => $scanpay->l('Shipping'),
                'quantity' => 1,
                'total' => $shipcosts,
            ];
        }
        /* Add gift wrap costs if applicable */
        $giftwrapcosts = $cart->getOrderTotal(true, Cart::ONLY_WRAPPING);
        if ($giftwrapcosts > 0) {
            $items[] = [
                'name' => $scanpay->l('Gift Wrap'),
                'quantity' => 1,
                'total' => $giftwrapcosts,
            ];
        }

        /* Calculate grand total and round item totals */
        $grandtotal = 0;
        foreach ($items as $i => $item) {
            $items[$i]['total'] = round($items[$i]['total'], 2);
            $grandtotal += $items[$i]['total'];
        }

        /* Better round some more due to devious floats */
        $grandtotal = round($grandtotal, 2);
        $ordertotal = (float) $cart->getOrderTotal(true, Cart::BOTH);

        /* If the calculated grand total differs from the order total, compensate
           by adding / subtracting amounts from items. Also convert to string
           before comparing since float compare often will not yield the right result. */
        if ($grandtotal . '' !== $ordertotal . '') {
            $totdiff = round($ordertotal - $grandtotal, 2);

            foreach ($items as $i => $item) {
                /* We bound the minimum item total at 0, by bounding
                   the difference at minus the current item total */
                $d = max($totdiff, -$items[$i]['total']);
                $items[$i]['total'] += $d;
                $totdiff = round($totdiff - $d, 2);
            }
        }

        /* Add currencies to item totals */
        $currency = new Currency((int) $cart->id_currency);
        foreach ($items as $i => $item) {
            $items[$i]['total'] .= ' ' . $currency->iso_code;
        }

        /* Insert the items into the sent data */
        $data['items'] = $items;

        $m = Tools::getValue('paymentmethod');

        /* Create a peyment URL */
        try {
            $paymenturl = $cl->newURL($data);
        } catch (Exception $e) {
            $scanpay->log('failed to get Scanpay payment URL: ' . $e->getMessage());
            exit($scanpay->l('Internal server error, please contact the shop.'));
        }

        $cartid = (int) $cart->id;
        if (!SPDB_Carts::insert($cartid, $shopid)) {
            $scanpay->log('A customer attempted to pay cart #' . $cartid . ' again.');
            exit($scanpay->l('Cart has already been paid.'));
        }

        /* Create the payment method query and redirect the customer to payment */
        $q = ($m) ? ('?go=' . $m) : '';
        Tools::redirect($paymenturl . $q);
    }
}
