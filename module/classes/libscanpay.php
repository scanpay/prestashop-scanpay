<?php
/**
 * @author    Scanpay <contact@scanpay.dk>
 * @copyright Scanpay ApS. All rights reserved.
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace Scanpay;

if (!defined('_PS_VERSION_')) {
    exit;
}

class IdempotentResponseException extends \Exception
{
}

class Scanpay
{
    protected $ch;
    protected $headers;
    protected $apikey;
    protected $idemstatus;
    protected $useidem;
    protected $opts;

    public function __construct($apikey = '', $opts = [])
    {
        // Check if libcurl is enabled
        if (!function_exists('curl_init')) {
            exit("ERROR: Please enable php-curl\n");
        }

        // Public cURL handle (reuse handle)
        $this->ch = curl_init();
        $this->headers = [
            'authorization' => 'Authorization: Basic ' . base64_encode($apikey),
            'x-sdk' => 'X-SDK: PHP-1.5.3/' . PHP_VERSION,
            'content-type' => 'Content-Type: application/json',
            'expect' => 'Expect: ',
        ];
        /* The 'Expect' header will disable libcurl's expect-logic,
            which will save us a HTTP roundtrip on POSTs >1024b. */
        $this->apikey = $apikey;
        $this->opts = $opts;
    }

    /* Create indexed array from associative array ($this->headers).
        Let the merchant overwrite the headers. */
    protected function httpHeaders($oldHeaders, $o = [])
    {
        $ret = $oldHeaders; /* copy array literal */
        if (isset($o['headers'])) {
            foreach ($o['headers'] as $key => &$val) {
                $ret[strtolower($key)] = $key . ': ' . $val;
            }
        }
        if (isset($ret['idempotency-key'])) {
            $this->useidem = true;
        }

        return $ret;
    }

    protected function handleHeaders($curl, $hdr)
    {
        $arr = explode(':', $hdr, 2);
        if (count($arr) === 2 && strtolower(trim($arr[0])) === 'idempotency-status') {
            $this->idemstatus = strtoupper(trim($arr[1]));
        }

        return strlen($hdr);
    }

    protected function request($path, $opts = [], $data = null)
    {
        /* Merge headers */
        $headers = $this->httpHeaders($this->headers, $this->opts);
        $headers = array_values($this->httpHeaders($headers, $opts));
        /* Merge other options */
        $opts = array_merge($this->opts, $opts);
        $hostname = (isset($opts['hostname'])) ? $opts['hostname'] : 'api.scanpay.dk';
        $this->useidem = false;
        $this->idemstatus = null;
        $curlopts = [
            CURLOPT_URL => 'https://' . $hostname . $path,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => ($data === null) ? 'GET' : 'POST',
            CURLOPT_VERBOSE => isset($opts['debug']) ? $opts['debug'] : 0,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USE_SSL => CURLUSESSL_ALL,
            CURLOPT_SSLVERSION => 6,
        ];
        if ($data !== null) {
            $curlopts[CURLOPT_POSTFIELDS] = json_encode($data, JSON_UNESCAPED_SLASHES);
            if ($curlopts[CURLOPT_POSTFIELDS] === false) {
                throw new \Exception('Failed to JSON encode request to Scanpay: ' . json_last_error_msg());
            }
        }
        if ($this->useidem) {
            $curlopts[CURLOPT_HEADERFUNCTION] = [$this, 'handleHeaders'];
        }
        // Let the merchant override $curlopts.
        if (isset($opts['curl'])) {
            foreach ($opts['curl'] as $key => &$val) {
                $curlopts[$key] = $val;
            }
        }
        curl_setopt_array($this->ch, $curlopts);

        $result = curl_exec($this->ch);
        if (!$result) {
            throw new \Exception(curl_strerror(curl_errno($this->ch)));
        }

        $statusCode = curl_getinfo($this->ch, CURLINFO_RESPONSE_CODE);

        /* Handle idempotency status */
        if ($this->useidem) {
            $err = null;
            switch ($this->idemstatus) {
                case 'OK':
                    break;
                case 'ERROR':
                    $err = 'Server failed to provide idempotency';
                    break;
                case null:
                    $err = 'Idempotency status response header missing';
                    break;
                default:
                    $err = 'Server returned unknown idempotency status ' . $this->idemstatus;
                    break;
            }
            if (!is_null($err)) {
                throw new \Exception($err . ". Scanpay returned $statusCode - " . explode("\n", $result)[0]);
            }
        }
        if ($statusCode !== 200) {
            throw new IdempotentResponseException('Scanpay returned "' . explode("\n", $result)[0] . '"');
        }
        // Decode the json response (@: suppress warnings)
        if (!is_array($resobj = @json_decode($result, true))) {
            throw new IdempotentResponseException('Invalid JSON response from server');
        }

        return $resobj;
    }

    // newURL: Create a new payment link
    public function newURL($data, $opts = [])
    {
        $o = $this->request('/v1/new', $opts, $data);
        if (isset($o['url']) && filter_var($o['url'], FILTER_VALIDATE_URL)) {
            return $o['url'];
        }
        throw new \Exception('Invalid response from server');
    }

    // seq: Get array of changes since the reqested seqnum
    public function seq($seqnum, $opts = [])
    {
        if (!is_numeric($seqnum)) {
            throw new \Exception('seq argument must be an integer');
        }
        $o = $this->request('/v1/seq/' . $seqnum, $opts);
        if (
            isset($o['seq']) && is_int($o['seq'])
            && isset($o['changes']) && is_array($o['changes'])
        ) {
            return $o;
        }
        throw new \Exception('Invalid response from server');
    }

    // handlePing: Convert data to JSON and validate integrity
    public function handlePing($opts = [])
    {
        ignore_user_abort(true);

        if (isset($opts['signature'])) {
            $signature = $opts['signature'];
        } elseif (isset($_SERVER['HTTP_X_SIGNATURE'])) {
            $signature = $_SERVER['HTTP_X_SIGNATURE'];
        } else {
            throw new \Exception('missing ping signature');
        }

        $body = isset($opts['body']) ? $opts['body'] : file_get_contents('php://input');
        if ($body === false) {
            throw new \Exception('unable to get ping body');
        }

        $checksum = base64_encode(hash_hmac('sha256', $body, $this->apikey, true));

        if (!hash_equals($checksum, $signature)) {
            throw new \Exception('invalid ping signature');
        }

        $obj = @json_decode($body, true);
        if ($obj === null) {
            throw new \Exception('invalid json from Scanpay server');
        }

        if (
            isset($obj['seq']) && is_int($obj['seq'])
            && isset($obj['shopid']) && is_int($obj['shopid'])
        ) {
            return $obj;
        }
        throw new \Exception('missing fields in Scanpay response');
    }

    public function capture($trnid, $data, $opts = [])
    {
        return $this->request("/v1/transactions/$trnid/capture", $opts, $data);
    }

    public function generateIdempotencyKey()
    {
        return rtrim(base64_encode(random_bytes(32)), '=');
    }

    public function charge($subid, $data, $opts = [])
    {
        $o = $this->request("/v1/subscribers/$subid/charge", $opts, $data);
        if (
            isset($o['type']) && $o['type'] === 'charge' && isset($o['id']) && is_int($o['id'])
            && isset($o['totals']) && isset($o['totals']['authorized'])
        ) {
            return $o;
        }
        throw new \Exception('Invalid response from server');
    }

    public function renew($subid, $data, $opts = [])
    {
        $o = $this->request("/v1/subscribers/$subid/renew", $opts, $data);
        if (isset($o['url']) && filter_var($o['url'], FILTER_VALIDATE_URL)) {
            return $o['url'];
        }
        throw new \Exception('Invalid response from server');
    }
}
