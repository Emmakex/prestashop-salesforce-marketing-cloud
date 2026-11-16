<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Review;

use RuntimeException;

/**
 * G6 bounded Salesforce Core subscription-configuration inventory ONLY.
 *
 * This inspects the Core sObject "CommSubscription" (NOT the Data Cloud CSC
 * DMO, not person-level consent). It cannot certify eligibility or a
 * Marketing Cloud Growth subscription without UI/Data Cloud verification.
 * GET-only, no customer IDs, contact points, email, private subscription
 * names, tokens, raw Salesforce bodies or record IDs in the output.
 */
final class G6CoreSubscriptionInventory
{
    const API = '/services/data/v68.0/';
    const MAX_ROWS = 20;

    public static function inspect($origin, $token, $transport = null)
    {
        if (!is_string($origin) || !is_string($token) || strlen($token) < 12
            || strlen($token) > 8192 || preg_match('/[\x00-\x20\x7f]/', $token)) {
            throw new RuntimeException('g6_subscription_invalid_auth');
        }
        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['scheme'],$parts['host'])
            || strtolower($parts['scheme']) !== 'https'
            || !preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)*\.my\.salesforce\.com$/Di', $parts['host'])
            || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
            throw new RuntimeException('g6_subscription_invalid_core_origin');
        }
        $root = 'https://' . strtolower($parts['host']) . self::API;
        $get = $transport === null ? [__CLASS__, 'getSafe'] : $transport;
        if (!is_callable($get)) {
            throw new RuntimeException('g6_subscription_invalid_transport');
        }
        // A single fixed GET describe, then a bounded SOQL GET if supported.
        $describe = self::reply(call_user_func(
            $get, $root . 'sobjects/CommSubscription/describe', $token
        ));
        if ($describe['status'] !== 200) {
            return self::unknown('core_subscription_describe_unavailable',
                $describe['status']);
        }
        $obj = json_decode($describe['body'],true);
        if (!is_array($obj) || !isset($obj['name'],$obj['fields'])
            || $obj['name'] !== 'CommSubscription'
            || !is_array($obj['fields'])
            || count($obj['fields']) > 500) {
            return self::unknown('core_subscription_describe_unverified', 200);
        }
        $allowed = [];
        foreach ($obj['fields'] as $field) {
            if (is_array($field) && isset($field['name'])
                && in_array($field['name'], ['Name','IsDefault'],true)) {
                $allowed[$field['name']] = true;
            }
        }
        if (!isset($allowed['Name'])) {
            return self::unknown('core_subscription_name_unavailable',200);
        }
        $canDefault = isset($allowed['IsDefault']);
        $soql = 'SELECT Name' . ($canDefault ? ', IsDefault' : '')
            . ' FROM CommSubscription LIMIT ' . self::MAX_ROWS;
        $queryUrl = $root . 'query/?q=' . rawurlencode($soql);
        $query = self::reply(call_user_func($get, $queryUrl, $token));
        if ($query['status'] !== 200) {
            return self::unknown('core_subscription_query_unavailable',$query['status']);
        }
        $body = json_decode($query['body'],true);
        if (!is_array($body) || !isset($body['records'])
            || !is_array($body['records'])
            || count($body['records']) > self::MAX_ROWS
            || !isset($body['totalSize']) || !is_int($body['totalSize'])
            || $body['totalSize'] < count($body['records'])
            || !isset($body['done']) || !is_bool($body['done'])) {
            return self::unknown('core_subscription_response_unverified',200);
        }
        $marketing = false;
        $defaultCount = 0;
        foreach ($body['records'] as $record) {
            if (!is_array($record) || !isset($record['Name'])
                || !is_string($record['Name'])
                || strlen($record['Name']) > 255
                || $canDefault && !isset($record['IsDefault'])) {
                return self::unknown('core_subscription_row_unverified',200);
            }
            if ($record['Name'] === 'Marketing') {
                $marketing = true;
            }
            if ($canDefault) {
                if (!is_bool($record['IsDefault'])) {
                    return self::unknown('core_subscription_row_unverified',200);
                }
                if ($record['IsDefault']) {
                    ++$defaultCount;
                }
            }
        }
        $complete = $body['done'] && $body['totalSize'] <= self::MAX_ROWS;
        return [
            'status' => $complete ? 'core_subscription_inventory_observed'
                : 'core_subscription_inventory_partial',
            'source' => 'salesforce_core_comm_subscription',
            'query_http_status' => 200,
            'core_record_count_in_first_page' => count($body['records']),
            'core_inventory_complete' => $complete,
            'marketing_name_seen_in_bounded_page' => $marketing,
            'core_default_field_available' => $canDefault,
            'core_default_count_in_bounded_page' => $canDefault ? $defaultCount : null,
            'growth_subscription_confirmed' => false,
            'data_cloud_csc_checked' => false,
            'individual_consent_records_read' => false,
            'customer_data_read' => false,
            'salesforce_mutations' => false,
            'marketing_sends_authorized' => false,
        ];
    }

    private static function unknown($code,$httpStatus)
    {
        return [
            'status' => $code,
            'source' => 'salesforce_core_comm_subscription',
            'query_http_status' => $httpStatus,
            'core_inventory_complete' => false,
            'marketing_name_seen_in_bounded_page' => null,
            'core_default_count_in_bounded_page' => null,
            'growth_subscription_confirmed' => false,
            'data_cloud_csc_checked' => false,
            'individual_consent_records_read' => false,
            'customer_data_read' => false,
            'salesforce_mutations' => false,
            'marketing_sends_authorized' => false,
        ];
    }

    private static function reply($response)
    {
        if (!is_array($response) || !isset($response['status'],$response['body'])
            || !is_int($response['status']) || !is_string($response['body'])
            || strlen($response['body']) > 524288) {
            throw new RuntimeException('g6_subscription_invalid_response');
        }
        return $response;
    }

    public static function getSafe($url, $token)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('g6_subscription_curl_unavailable');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('g6_subscription_curl_unavailable');
        }
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body)) {
            throw new RuntimeException('g6_subscription_transport_failed');
        }
        return ['status' => $status, 'body' => $body];
    }

    private function __construct()
    {
    }
}
