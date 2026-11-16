<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Review;

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\GrowthTypedPstPayloadBuilder;
use RuntimeException;

/**
 * G5 manual synthetic schema validation at Salesforce /actions/test ONLY.
 *
 * Intentionally lives outside the PrestaShop installation package.
 * The only HTTP code path ends in /actions/test, which Salesforce documents
 * as synchronous validation WITHOUT persisting records downstream.
 * No general ingest, delete, campaign, queue or arbitrary endpoint support.
 */
final class G5SyntheticValidationOnly
{
    const SOURCE = 'ABH_PrestaShop_Data_Cloud';
    const MODE = 'yes_synthetic_actions_test_only';
    const NAMESPACE_VALUE = 'growth_ci_abhtwo';

    public static function prepare($cloudOrigin, $object, $json)
    {
        if (PHP_SAPI !== 'cli' || getenv('G5_PST_VALIDATION_MODE') !== self::MODE) {
            throw new RuntimeException('g5_actions_test_explicit_mode_required');
        }
        if ($object !== 'PSCustomer' && $object !== 'PSOrder') {
            throw new RuntimeException('g5_actions_test_object_forbidden');
        }
        if (!is_string($cloudOrigin) || !is_string($json)
            || strlen($json) === 0 || strlen($json) > 204800) {
            throw new RuntimeException('g5_actions_test_invalid_request');
        }
        $parts = parse_url($cloudOrigin);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || strtolower($parts['scheme']) !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/'
            || isset($parts['query']) || isset($parts['fragment'])
            || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+c360a\.salesforce\.com$/Di',
                $parts['host'])) {
            throw new RuntimeException('g5_actions_test_invalid_cloud_origin');
        }
        $body = json_decode($json, true);
        if (!is_array($body) || array_keys($body) !== ['data']
            || !is_array($body['data']) || count($body['data']) !== 1
            || !isset($body['data'][0]) || !is_array($body['data'][0])) {
            throw new RuntimeException('g5_actions_test_single_record_only');
        }
        $row = $body['data'][0];

        // Reuse G4's complete source schema + typed PK/FK validation.
        $canonical = GrowthTypedPstPayloadBuilder::encode(
            $object, [$row], 'marketing_cloud_growth', 'test'
        );
        if ($canonical !== $json) {
            throw new RuntimeException('g5_actions_test_noncanonical_payload');
        }
        if ($row['source_namespace'] !== self::NAMESPACE_VALUE
            || $row['shop_id'] !== 2
            || $row[$object === 'PSCustomer' ? 'customer_id' : 'order_id'] !== 44) {
            throw new RuntimeException('g5_actions_test_fixture_identity_required');
        }
        $customerKey = self::NAMESPACE_VALUE . ':2:customer:44';
        if ($object === 'PSCustomer') {
            if ($row['source_key'] !== $customerKey
                || $row['email'] !== 'synthetic@fixture.example.invalid'
                || $row['first_name'] !== 'Synthetic'
                || $row['last_name'] !== 'Fixture'
                || $row['partner_optin_flag'] !== false) {
                throw new RuntimeException('g5_actions_test_not_synthetic_customer');
            }
        } elseif ($row['source_key'] !== self::NAMESPACE_VALUE . ':2:order:44'
            || $row['customer_source_key'] !== $customerKey
            || $row['cart_source_key'] !== self::NAMESPACE_VALUE . ':2:cart:91'
            || $row['paid_flag'] !== false || $row['currency'] !== 'EUR'
            || $row['total_paid'] != 42.4) {
            throw new RuntimeException('g5_actions_test_not_synthetic_order');
        }

        return [
            'url' => 'https://' . strtolower($parts['host']) . '/api/v1/ingest/sources/'
                . self::SOURCE . '/' . $object . '/actions/test',
            'body' => $canonical,
            'object' => $object,
            'record_count' => 1,
        ];
    }

    public static function validate($cloudOrigin, $accessToken, $object, $json, $transport = null)
    {
        // Plan before ANY request, even when using a mock transport.
        $request = self::prepare($cloudOrigin, $object, $json);
        if (!is_string($accessToken) || strlen($accessToken) < 12
            || strlen($accessToken) > 8192
            || preg_match('/[\x00-\x20\x7f]/', $accessToken)) {
            throw new RuntimeException('g5_actions_test_missing_or_invalid_token');
        }
        $result = $transport === null
            ? self::postTestOnly($request['url'], $request['body'], $accessToken)
            : call_user_func($transport, $request['url'], $request['body'], $accessToken);
        if (!is_array($result) || !isset($result['status'], $result['body'])
            || !is_int($result['status']) || !is_string($result['body'])
            || strlen($result['body']) > 32768) {
            throw new RuntimeException('g5_actions_test_invalid_transport_response');
        }
        // 202 is the *real ingestion* acceptance status, never a validation success.
        if ($result['status'] !== 200) {
            throw new RuntimeException('g5_actions_test_http_' . $result['status']);
        }
        $response = json_decode($result['body'], true);
        if (!is_array($response) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('g5_actions_test_invalid_json_response');
        }
        // Salesforce may include row-level validation reports. Do not treat a
        // 200 transport status as success if a report explicitly rejects rows.
        if (isset($response['isValid']) && $response['isValid'] !== true
            || isset($response['valid']) && $response['valid'] !== true
            || isset($response['accepted']) && $response['accepted'] === false
            || isset($response['errorCount']) && (int) $response['errorCount'] > 0
            || isset($response['errors']) && !empty($response['errors'])
            || isset($response['validationReport'])
                && !empty($response['validationReport'])) {
            throw new RuntimeException('g5_actions_test_validation_report_needs_review');
        }

        // Expose only immutable allowlisted counts; never token, host, email,
        // row, raw Salesforce response, tenant org ID or validation details.
        return [
            'object' => $object,
            'status' => 'validated_schema_only',
            'http_status' => 200,
            'record_count' => 1,
            'endpoint_kind' => 'salesforce_actions_test_no_persistence',
            'salesforce_records_persisted' => false,
            'ingestion_attempted' => false,
            'marketing_sends_authorized' => false,
        ];
    }

    private static function postTestOnly($url, $body, $token)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('g5_actions_test_curl_unavailable');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('g5_actions_test_transport_error');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
                'Content-Type: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($response)) {
            throw new RuntimeException('g5_actions_test_transport_error');
        }
        return ['status' => $status, 'body' => $response];
    }

    private function __construct()
    {
    }
}
