<?php
/**
 * @author    Scanpay <contact@scanpay.dk>
 * @copyright Scanpay ApS. All rights reserved.
 * @license   https://opensource.org/licenses/MIT MIT License
 * @version   2.3.0 (2024-12-16)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class ScanpayClient
{
    private CurlHandle $ch;
    private array $headers;

    public function __construct(string $apikey)
    {
        $this->ch = curl_init();
        $this->headers = [
            'Authorization: Basic ' . base64_encode($apikey),
            'X-Shop-Plugin: PS-{{ VERSION }}/' . _PS_VERSION_ . '; PHP-' . PHP_VERSION,
            'Content-Type: application/json',
            'Expect: ',
        ];
        /*
            The 'Expect' header will disable libcurl's expect-logic,
            which will save us a HTTP roundtrip on POSTs >1024b.
            This is only relevant for HTTP 1.1.
        */
    }

    private function request(string $path, ?array $opts, ?array $data): array
    {
        $headers = $this->headers;
        if (isset($opts['headers'])) {
            foreach ($opts['headers'] as $key => $val) {
                $headers[] = $key . ': ' . $val;
            }
        }
        $curlOpts = [
            CURLOPT_URL => 'https://api.scanpay.dk' . $path,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_DNS_CACHE_TIMEOUT => 180,
            CURLOPT_HTTPHEADER => $headers,
        ];

        if (isset($data)) {
            $curlOpts[CURLOPT_POSTFIELDS] = json_encode($data, JSON_UNESCAPED_SLASHES);
            if ($curlOpts[CURLOPT_POSTFIELDS] === false) {
                throw new \Exception('Failed to JSON encode request to Scanpay: ' . json_last_error_msg());
            }
        }

        curl_reset($this->ch);
        curl_setopt_array($this->ch, $curlOpts);
        $res = curl_exec($this->ch);
        if ($res === false) {
            throw new \Exception(curl_strerror(curl_errno($this->ch)));
        }

        $code = (int) curl_getinfo($this->ch, CURLINFO_RESPONSE_CODE);
        if ($code !== 200) {
            if (substr_count($res, "\n") !== 1 || strlen($res) > 512) {
                $result = 'server error';
            }
            throw new \Exception($code . ' ' . $result);
        }

        $json = json_decode($res, true);
        if (!is_array($json)) {
            throw new \Exception('Invalid JSON response from server');
        }

        return $json;
    }

    /**
     * Create a new Scanpay payment link.
     * @return string
     */
    public function newURL(array $data): string
    {
        $opts = ['headers' => ['X-Cardholder-IP' => $_SERVER['REMOTE_ADDR'] ?? '']];
        $o = $this->request('/v1/new', $opts, $data);
        if (isset($o['url']) && filter_var($o['url'], FILTER_VALIDATE_URL)) {
            return $o['url'];
        }
        throw new \Exception('Invalid response from server');
    }

    /**
     * Get array of changes since the reqested sequence number.
     * @return array
     */
    public function seq(int $num): array
    {
        $o = $this->request('/v1/seq/' . $num, null, null);
        if (isset($o['seq'], $o['changes']) && is_int($o['seq']) && is_array($o['changes'])) {
            $empty = empty($o['changes']);
            if (($empty && $o['seq'] <= $num) || (!$empty && $o['seq'] > $num)) {
                return $o;
            }
        }
        throw new \Exception('Invalid seq from server');
    }

    /**
     * Capture a transaction.
     * @return array
     */
    public function capture(int $trnid, array $data): array
    {
        return $this->request("/v1/transactions/$trnid/capture", null, $data);
    }
}
