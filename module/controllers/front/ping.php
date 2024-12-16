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

class ScanpayPingModuleFrontController extends ModuleFrontController
{
    public function __construct($res = [])
    {
        parent::__construct($res);
        $this->ajax = true;
    }

    public function postProcess()
    {
        ignore_user_abort(true);
        $signature = $_SERVER['HTTP_X_SIGNATURE'];
        if (!isset($signature)) {
			return;
		}

        $apikey = Configuration::get('SCANPAY_APIKEY') ?: '';
        $shopid = (int) explode(':', $apikey)[0];
        if (!$shopid) {
            PrestaShopLogger::addLog('invalid Scanpay API-key', 3);
            return;
        }

        $body = file_get_contents( 'php://input', false, null, 0, 512 );
        if ( ! hash_equals( base64_encode( hash_hmac( 'sha256', $body, $apikey, true ) ), $signature ) ) {
			return;
		}
		$ping = json_decode( $body, true );
		if ( ! isset( $ping, $ping['seq'], $ping['shopid'] ) || ! is_int( $ping['seq'] ) ) {
			return;
		}

        if ($ping['shopid'] !== $shopid) {
            PrestaShopLogger::addLog('invalid Scanpay API-key', 3);
            return;
        }

        // Load the current seq for the shop
        $seqobj = SPDB_Seq::load($shopid);
        $myseq = (int) $seqobj['seq'];
        if ($ping['seq'] <= $myseq) {
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
