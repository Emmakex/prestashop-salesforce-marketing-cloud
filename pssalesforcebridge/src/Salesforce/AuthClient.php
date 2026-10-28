<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Salesforce;

use RuntimeException;

class AuthClient
{
    private $authBaseUri;
    private $clientId;
    private $clientSecret;
    private $accountId;
    private $timeout;

    public function __construct($authBaseUri, $clientId, $clientSecret, $accountId = '', $timeout = 10)
    {
        $this->authBaseUri = rtrim((string) $authBaseUri, '/');
        $this->clientId = (string) $clientId;
        $this->clientSecret = (string) $clientSecret;
        $this->accountId = (string) $accountId;
        $this->timeout = (int) $timeout;
    }

    public function requestAccessToken()
    {
        if ($this->authBaseUri === '' || $this->clientId === '' || $this->clientSecret === '') {
            throw new RuntimeException('Salesforce authentication configuration is incomplete.');
        }

        $payload = [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ];

        if ($this->accountId !== '') {
            $payload['account_id'] = $this->accountId;
        }

        $ch = curl_init($this->authBaseUri . '/v2/token');
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
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
            throw new RuntimeException('Salesforce connection error: ' . $curlError);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Salesforce returned an invalid JSON response.');
        }

        if ($status < 200 || $status >= 300) {
            $message = isset($decoded['error_description']) ? $decoded['error_description'] : (isset($decoded['message']) ? $decoded['message'] : 'HTTP ' . $status);
            throw new RuntimeException('Salesforce authentication rejected the request: ' . $message);
        }

        if (empty($decoded['access_token'])) {
            throw new RuntimeException('Salesforce response does not contain access_token.');
        }

        return $decoded;
    }
}
