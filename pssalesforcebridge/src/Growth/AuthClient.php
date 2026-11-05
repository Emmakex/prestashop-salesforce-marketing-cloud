<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use RuntimeException;

/** Marketing Cloud Growth / Salesforce Core OAuth. NOT Engagement /v2/token. */
class AuthClient
{
    private $orgUrl;
    private $clientId;
    private $clientSecret;

    public function __construct($orgUrl, $clientId, $clientSecret)
    {
        $parts = parse_url(trim((string) $orgUrl));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || strtolower($parts['scheme']) !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')
            || !preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)*\.my\.salesforce\.com$/Di', $parts['host'])) {
            throw new RuntimeException('growth_oauth_invalid_org_url');
        }
        $this->orgUrl = 'https://' . strtolower($parts['host']);
        $this->clientId = trim((string) $clientId);
        $this->clientSecret = (string) $clientSecret;
    }

    public function requestAccessToken($transport = null)
    {
        if ($this->clientId === '' || $this->clientSecret === '') {
            throw new RuntimeException('growth_oauth_missing_credentials');
        }
        $endpoint = $this->orgUrl . '/services/oauth2/token';
        $fields = [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ];
        $result = $transport === null ? $this->postForm($endpoint, $fields) : call_user_func($transport, $endpoint, $fields);
        if (!is_array($result) || !isset($result['status'], $result['body'])) {
            throw new RuntimeException('growth_oauth_invalid_transport');
        }
        $status = (int) $result['status'];
        if ($status !== 200) {
            // Only retain a fixed OAuth error identifier, never the raw response.
            // The Salesforce response may contain credentials, identities or private tenant details.
            $diagnostic = 'growth_oauth_http_' . $status;
            $errorResponse = json_decode((string) $result['body'], true);
            $knownErrors = [
                'invalid_client',
                'invalid_client_id',
                'invalid_grant',
                'invalid_request',
                'invalid_scope',
                'invalid_app_access',
                'unauthorized_client',
                'unsupported_grant_type',
                'access_denied',
                'server_error',
            ];
            if (is_array($errorResponse) && isset($errorResponse['error'])
                && is_string($errorResponse['error'])
                && in_array($errorResponse['error'], $knownErrors, true)) {
                $diagnostic .= '_' . $errorResponse['error'];
            }
            throw new RuntimeException($diagnostic);
        }
        $token = json_decode((string) $result['body'], true);
        if (!is_array($token) || !isset($token['access_token']) || !is_string($token['access_token']) || $token['access_token'] === '') {
            throw new RuntimeException('growth_oauth_invalid_response');
        }
        if (empty($token['instance_url']) || !is_string($token['instance_url'])) {
            throw new RuntimeException('growth_oauth_missing_instance_url');
        }
        $instance = parse_url($token['instance_url']);
        if (!is_array($instance) || !isset($instance['scheme'], $instance['host'])
            || strtolower($instance['scheme']) !== 'https'
            || !preg_match('/(^|\.)salesforce\.com$/Di', $instance['host'])) {
            throw new RuntimeException('growth_oauth_invalid_instance_url');
        }
        return $token;
    }

    private function postForm($url, array $fields)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('growth_oauth_curl_unavailable');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('growth_oauth_transport_error');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException('growth_oauth_transport_error');
        }
        return ['status' => $status, 'body' => $body];
    }
}
