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
require_once dirname(__FILE__) . '/../../classes/orderupdater.php';

class ScanpayPingModuleFrontController extends ModuleFrontController
{
    public function __construct($res = [])
    {
        parent::__construct($res);
        $this->ajax = true;
    }

    public function postProcess()
    {
        $apikey = Configuration::get('SCANPAY_APIKEY');
        $scanpay = new Scanpay();
        $shopid = $scanpay->extractshopid($apikey);
        if (!$shopid) {
            PrestaShopLogger::addLog('invalid Scanpay API-key scheme', 3);
            echo json_encode(['error' => 'invalid Scanpay API-key scheme']);

            return;
        }

        $body = Tools::file_get_contents('php://input');
        $cl = new Scanpay\Scanpay($apikey);
        try {
            $ping = $cl->handlePing(['body' => $body]);
        } catch (Exception $e) {
            PrestaShopLogger::addLog('invalid ping: ' . $e->getMessage(), 1);
            echo json_encode(['error' => $e->getMessage()]);

            return;
        }
        if ($ping['shopid'] !== $shopid) {
            echo json_encode(['error' => 'invalid ping shopid']);

            return;
        }

        /* Load the current seq for the shop */
        $seqobj = SPDB_Seq::load($shopid);
        $myseq = (int) $seqobj['seq'];
        if ((int) $ping['seq'] <= $myseq) {
            SPDB_Seq::updatemtime($shopid);
            echo json_encode(['success' => true]);

            return;
        }

        try {
            SPOrderUpdater::update($shopid, $myseq);
        } catch (Exception $e) {
            PrestaShopLogger::addLog('Encountered error while updating: ' . $e, 3);
            echo json_encode(['error' => 'failed to update orders']);

            return;
        }
        echo json_encode(['success' => true]);
    }
}
