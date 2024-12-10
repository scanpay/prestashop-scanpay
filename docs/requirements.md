# Requirements

This document outlines the requirements and dependencies necessary for the successful installation and operation of our PrestaShop module.

Features marked with ~~strikethrough~~ are managed using polyfills or other mitigations.

## PrestaShop compatibility table

| PrestaShop | Version |
| :--------- | :-----: |

## PHP compatibility table

PrestaShop 8 requires PHP 7.2.5, while PrestaShop 9.0 requires PHP 8.1.

| PHP Features                | Version |
| :-------------------------- | :-----: |
| hash_equals                 |   5.6   |
| curl_strerror               |   5.5   |
| Array, short syntax         |   5.4   |
| Namespaces                  |  5.3.0  |
| json_decode                 |  5.2.0  |
| curl_setopt_array           |  5.1.3  |
| hash_hmac                   |  5.1.2  |
| Exception class             |  5.1.0  |
| Default function parameters |  5.0.0  |

## libcurl compatibility table

We previously used `CURLOPT_DNS_SHUFFLE_ADDRESSES` (7.60.0), but this is not a requirement at the moment.

| libcurl               | Version |
| :-------------------- | :-----: |
| CURLOPT_TCP_KEEPALIVE | 7.25.0  |
