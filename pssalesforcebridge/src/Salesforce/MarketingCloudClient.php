<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Salesforce;

use RuntimeException;

class MarketingCloudClient
{
    private $restBaseUri;
    private $timeout;

    public function __construct($restBaseUri, $timeout = 15)
    {
        $this->restBaseUri = rtrim((string) $restBaseUri, '/');
        $this->timeout = (int) $timeout;
    }

    public function post($path, array $payload, $accessToken)
    {
        if ($this->restBaseUri === '' || $accessToken === '') {
            throw new RuntimeException('Marketing Cloud REST client is not configured.');
        }

        $ch = curl_init($this->restBaseUri . '/' . ltrim((string) $path, '/'));
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Marketing Cloud connection error: ' . $curlError);
        }

        $decoded = $body === '' ? [] : json_decode($body, true);
        if ($body !== '' && !is_array($decoded)) {
            throw new RuntimeException('Marketing Cloud returned an invalid JSON response.');
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Marketing Cloud API returned HTTP ' . $status . '.');
        }

        return ['status' => $status, 'body' => $decoded];
    }
}
