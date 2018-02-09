<?php
namespace Scanpay;
class Scanpay {
    protected $ch;
    protected $headers;
    protected $apikey;
    public function __construct($apikey = '') {
        // Check if libcurl is enabled
        if (!function_exists('curl_init')) { die("ERROR: Please enable php-curl\n"); }
        // Public cURL handle (we want to reuse connections)
        $this->ch = curl_init();
        $this->headers = [
            'Authorization' => 'Basic ' . base64_encode($apikey),
            'X-SDK' => 'PHP-1.3.1/'. PHP_VERSION,
            'X-Shop-Plugin' => 'prestashop/' . SCANPAY_VERSION,
            'Content-Type' => 'application/json',
            'Expect' => '', // Prevent 'Expect: 100-continue' on POSTs >1024b.
        ];
        $this->apikey = $apikey;
    }
    protected function httpHeaders($o=[]) {
        $ret = [];
        foreach($this->headers as $key => &$val) {
            $ret[strtolower($key)] = $key . ': ' . $val;
        }
        if (isset($o['headers'])) {
            foreach($o['headers'] as $key => &$val) {
                $ret[strtolower($key)] = $key . ': ' . $val;
            }
        }
        // Redefine API Key (DEPRECATED!!!)
        if (isset($o['auth'])) {
            $ret['authorization'] = 'Authorization: Basic ' . base64_encode($o['auth']);
        }
        return array_values($ret);
    }
    protected function request($path, $opts=[], $data=null) {
        $hostname = (isset($opts['hostname'])) ? $opts['hostname'] : 'api.scanpay.dk';
        $curlopts = [
            CURLOPT_URL => 'https://' . $hostname . $path,
            CURLOPT_HTTPHEADER => $this->httpHeaders($opts),
            CURLOPT_CUSTOMREQUEST => ($data === null) ? 'GET' : 'POST',
            CURLOPT_POSTFIELDS => ($data === null) ? null : json_encode($data, JSON_UNESCAPED_SLASHES),
            CURLOPT_VERBOSE => isset($opts['debug']) ? $opts['debug'] : 0,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USE_SSL => CURLUSESSL_ALL,
            CURLOPT_SSLVERSION => 6, // TLSv1.2
        ];
        // Let the merchant override $curlopts.
        if (isset($opts['curl'])) {
            foreach($opts['curl'] as $key => &$val) { $curlopts[$key] = $val; }
        }
        curl_setopt_array($this->ch, $curlopts);
        $result = curl_exec($this->ch);
        if (!$result) {
            throw new \Exception(curl_strerror(curl_errno($this->ch)));
        }
        $statusCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
        if ($statusCode !== 200) {
            throw new \Exception(explode("\n", $result)[0]);
        }
        // Decode the json response (@: surpress warnings)
        if (!$resobj = @json_decode($result, true)) {
            throw new \Exception('Invalid response from server');
        }
        return $resobj;
    }
    // newURL: Create a new payment link
    public function newURL($data, $opts=[]) {
        $o = $this->request('/v1/new', $opts, $data);
        if (isset($o['url']) && filter_var($o['url'], FILTER_VALIDATE_URL)) {
            return $o['url'];
        }
        throw new \Exception('Invalid response from server');
    }
    // seq: Get array of changes since the reqested seqnum
    public function seq($seqnum, $opts=[]) {
        if (!is_numeric($seqnum)) {
            throw new \Exception('seq argument must be an integer');
        }
        $o = $this->request('/v1/seq/' . $seqnum, $opts);
        if (isset($o['seq']) && is_int($o['seq'])
                && isset($o['changes']) && is_array($o['changes'])) {
            return $o;
        }
        throw new \Exception('Invalid response from server');
    }
    // maxSeq. (DEPRECATED!!!)
    public function maxSeq($opts=[]) {
        $o = $this->request('/v1/seq', $opts);
        if (isset($o['seq']) && is_int($o['seq'])) {
            return $o['seq'];
        }
        throw new \Exception('Invalid response from server');
    }
    // handlePing: Convert data to JSON and validate integrity
    public function handlePing($opts=[]) {
        ignore_user_abort(true);
        if (isset($opts['signature'])) {
            $signature = $opts['signature'];
        } else if (isset($_SERVER['HTTP_X_SIGNATURE'])) {
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
        if (isset($obj['seq']) && is_int($obj['seq']) &&
            isset($obj['shopid']) && is_int($obj['shopid'])) {
            return $obj;
        }
        throw new \Exception('missing fields in Scanpay response');
    }
}
?>