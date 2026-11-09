<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud;

use RuntimeException;

/**
 * Data Cloud/Ingestion API OAuth token exchange only.
 * No record reading, uploading, Data Stream writes or marketing sends.
 * Credentials are managed by a separate integration identity.
 */
final class TokenExchangeClient
{
    /**
     * Exchange the Salesforce Core OAuth access token for a Data Cloud token.
     * Optional transport callable: function (string $url, array $fields): array
     * Returns ['status' => int, 'body' => string].
     */
    public function exchange($coreInstanceUrl, $coreAccessToken, $transport = null)
    {
        $coreUrl = self::validatedOrigin($coreInstanceUrl, 'core');
        if (!is_string($coreAccessToken) || $coreAccessToken === '') {
            throw new RuntimeException('data_cloud_exchange_missing_subject_token');
        }

        // Salesforce documents this as a form POST to /services/a360/token.
        $fields = [
            'grant_type' => 'urn:salesforce:grant-type:external:cdp',
            'subject_token' => $coreAccessToken,
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
        ];
        $endpoint = $coreUrl . '/services/a360/token';
        $response = $transport === null
            ? $this->postForm($endpoint, $fields)
            : call_user_func($transport, $endpoint, $fields);

        if (!is_array($response) || !isset($response['status'], $response['body'])
            || !is_string($response['body'])) {
            throw new RuntimeException('data_cloud_exchange_invalid_transport');
        }

        $status = (int) $response['status'];
        if ($status !== 200) {
            // The body may contain token/identity details: never expose it.
            $diagnostic = 'data_cloud_exchange_http_' . $status;
            $decodedError = json_decode($response['body'], true);
            $allowlist = [
                'invalid_subject_token',
                'invalid_client',
                'invalid_grant',
                'invalid_scope',
                'invalid_request',
                'unauthorized_client',
                'access_denied',
            ];
            if (is_array($decodedError) && isset($decodedError['error'])
                && is_string($decodedError['error'])
                && in_array($decodedError['error'], $allowlist, true)) {
                $diagnostic .= '_' . $decodedError['error'];
            }
            throw new RuntimeException($diagnostic);
        }
        if (strlen($response['body']) > 32768) {
            throw new RuntimeException('data_cloud_exchange_response_too_large');
        }
        $token = json_decode($response['body'], true);
        if (!is_array($token)
            || !isset($token['access_token'], $token['instance_url'])
            || !is_string($token['access_token']) || $token['access_token'] === ''
            || !is_string($token['instance_url'])
            || (isset($token['token_type'])
                && (!is_string($token['token_type']) || strcasecmp($token['token_type'], 'Bearer') !== 0))) {
            throw new RuntimeException('data_cloud_exchange_invalid_response');
        }
        $cloudOrigin = self::validatedOrigin($token['instance_url'], 'cloud');

        // Return only the two values needed for a future isolated Ingestion API client.
        // Never persist or log them as GitHub artifact/workflow output.
        return [
            'access_token' => $token['access_token'],
            'instance_url' => $cloudOrigin,
        ];
    }

    private static function validatedOrigin($url, $kind)
    {
        if (!is_string($url) || $url === '') {
            throw new RuntimeException('data_cloud_exchange_invalid_' . $kind . '_url');
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || strtolower($parts['scheme']) !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')) {
            throw new RuntimeException('data_cloud_exchange_invalid_' . $kind . '_url');
        }
        $host = strtolower($parts['host']);
        if ($kind === 'core') {
            // Intentionally restrict to the observed ABH My Domain identity.
            $valid = preg_match('/^[a-z0-9-]+\.my\.salesforce\.com$/D', $host) === 1;
        } else {
            // Multiple well-formed labels under Salesforce-controlled c360a domain
            // are allowed; never accept unrelated HTTPS hosts.
            $valid = preg_match(
                '/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+c360a\.salesforce\.com$/D',
                $host
            ) === 1;
        }
        if (!$valid) {
            if ($kind === 'cloud') {
                // Non-identifying diagnostics only. Never log raw URL or tenant ID.
                if (preg_match('/(^|\.)c360a\.salesforce\.com$/D', $host)) {
                    throw new RuntimeException('data_cloud_exchange_invalid_cloud_url_label');
                }
                if (preg_match('/(^|\.)salesforce\.com$/D', $host)) {
                    throw new RuntimeException('data_cloud_exchange_invalid_cloud_url_salesforce_other');
                }
                if (preg_match('/(^|\.)sfdc\.cl$/D', $host)) {
                    throw new RuntimeException('data_cloud_exchange_invalid_cloud_url_sfdc');
                }
                throw new RuntimeException('data_cloud_exchange_invalid_cloud_url_external');
            }
            throw new RuntimeException('data_cloud_exchange_invalid_' . $kind . '_url');
        }
        return 'https://' . $host;
    }

    private function postForm($url, array $fields)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('data_cloud_exchange_curl_unavailable');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('data_cloud_exchange_transport_error');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body)) {
            throw new RuntimeException('data_cloud_exchange_transport_error');
        }
        return ['status' => $status, 'body' => $body];
    }
}
