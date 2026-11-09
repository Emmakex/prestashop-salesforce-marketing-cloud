<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use RuntimeException;

/**
 * Read-only Salesforce Core metadata probe for Marketing Cloud Growth.
 * Never fetches rows, credentials, marketing content or Data 360 records.
 */
final class DiscoveryClient
{
    private $instanceUrl;
    private $accessToken;

    public function __construct($instanceUrl, $accessToken)
    {
        $parts = parse_url(trim((string) $instanceUrl));
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || strtolower($parts['scheme']) !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')
            || !preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)*\.salesforce\.com$/Di', $parts['host'])) {
            throw new RuntimeException('growth_discovery_invalid_instance_url');
        }
        $this->instanceUrl = 'https://' . strtolower($parts['host']);
        $this->accessToken = (string) $accessToken;
        if ($this->accessToken === '') {
            throw new RuntimeException('growth_discovery_missing_token');
        }
    }

    /**
     * An injected transport receives the absolute URL and bearer token.
     * It must return array('status' => int, 'body' => JSON string).
     */
    public function inspect($transport = null)
    {
        $versions = $this->fetch('/services/data/', $transport);
        $latest = null;
        foreach ($versions as $entry) {
            if (!is_array($entry) || !isset($entry['url']) || !is_string($entry['url'])) {
                continue;
            }
            if (!preg_match('/^\/services\/data\/v([0-9]{2,3}\.[0-9]{1,2})$/D', $entry['url'], $matches)) {
                continue;
            }
            if ($latest === null || version_compare($matches[1], $latest, '>')) {
                $latest = $matches[1];
            }
        }
        if ($latest === null) {
            throw new RuntimeException('growth_discovery_no_supported_api_version');
        }

        // These two Salesforce REST endpoints return only usage and object metadata.
        $this->fetch('/services/data/v' . $latest . '/limits', $transport);
        $sobjects = $this->fetch('/services/data/v' . $latest . '/sobjects', $transport);
        if (!isset($sobjects['sobjects']) || !is_array($sobjects['sobjects'])) {
            throw new RuntimeException('growth_discovery_invalid_sobjects');
        }

        $visible = array_fill_keys([
            'Contact',
            'Lead',
            'Campaign',
            'Individual',
            'ContactPointEmail',
            'ContactPointTypeConsent',
            'Order',
        ], false);
        foreach ($sobjects['sobjects'] as $object) {
            if (is_array($object) && isset($object['name']) && is_string($object['name'])
                && array_key_exists($object['name'], $visible)) {
                $visible[$object['name']] = true;
            }
        }

        return [
            'rest_api_version' => 'v' . $latest,
            'rest_limits_accessible' => true,
            'sobjects_metadata_accessible' => true,
            'visible_standard_object_types' => $visible,
            'data_360_entitlement' => 'not_evaluated',
            'data_360_ingestion' => 'not_evaluated',
            'growth_flow_entry' => 'not_evaluated',
            'salesforce_record_reads' => false,
            'salesforce_data_writes' => false,
        ];
    }

    private function fetch($path, $transport)
    {
        $url = $this->instanceUrl . $path;
        $response = $transport === null
            ? $this->get($url)
            : call_user_func($transport, $url, $this->accessToken);
        if (!is_array($response) || !isset($response['status'], $response['body'])
            || !is_scalar($response['body'])) {
            throw new RuntimeException('growth_discovery_invalid_transport');
        }
        $status = (int) $response['status'];
        if ($status !== 200) {
            // Never include the Salesforce response body or URL in an exception.
            throw new RuntimeException('growth_discovery_http_' . $status);
        }
        $body = (string) $response['body'];
        if (strlen($body) > 8388608) {
            throw new RuntimeException('growth_discovery_response_too_large');
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new RuntimeException('growth_discovery_invalid_json');
        }
        return $json;
    }

    private function get($url)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('growth_discovery_curl_unavailable');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('growth_discovery_transport_error');
        }
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'Accept: application/json',
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
            throw new RuntimeException('growth_discovery_transport_error');
        }
        return ['status' => $status, 'body' => $body];
    }
}
