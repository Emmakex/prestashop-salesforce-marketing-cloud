<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Salesforce;

use RuntimeException;

final class RestClient
{
    private $restBaseUri;
    private $accessToken;
    private $timeout;

    public function __construct($restBaseUri, $accessToken, $timeout = 10)
    {
        $this->restBaseUri = rtrim((string) $restBaseUri, '/');
        $this->accessToken = (string) $accessToken;
        $this->timeout = (int) $timeout;
    }

    public function postJson($path, array $payload)
    {
        if ($this->restBaseUri === '' || $this->accessToken === '') {
            throw new RuntimeException('Salesforce REST configuration is incomplete.');
        }

        $encoded = json_encode($payload);
        if ($encoded === false) {
            throw new RuntimeException('Unable to encode Salesforce request payload.');
        }

        $ch = curl_init($this->restBaseUri . '/' . ltrim((string) $path, '/'));
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => $encoded,
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
            throw new RuntimeException('Salesforce REST connection error: ' . $curlError);
        }

        $decoded = $body === '' ? [] : json_decode($body, true);

        if ($status < 200 || $status >= 300) {
            $message = 'HTTP ' . $status;
            if (is_array($decoded)) {
                if (!empty($decoded['message'])) {
                    $message = (string) $decoded['message'];
                } elseif (!empty($decoded['error_description'])) {
                    $message = (string) $decoded['error_description'];
                }
            }

            throw new RuntimeException('Salesforce REST request failed: ' . $message);
        }

        if ($body !== '' && !is_array($decoded)) {
            throw new RuntimeException('Salesforce REST returned an invalid JSON response.');
        }

        return $decoded;
    }
}
