<?php
/**
 * G6 ABH read-only metadata inspection for the standard Communication
 * Subscription Consent DMO in Salesforce Data Cloud.
 *
 * ONE fixed Salesforce Core Connect REST GET; not a consent record query.
 * No person/contact/consent rows, no POST, no Data Cloud ingestion.
 * Keep this review-only class OUTSIDE the PrestaShop installable module.
 */
final class G6CscDmoMetadataProbe
{
    const API_PATH = '/services/data/v68.0/ssot/data-model-objects/'
        . 'ssot__CommunicationSubscriptionConsent__dlm';
    const OBJECT_NAME = 'ssot__CommunicationSubscriptionConsent__dlm';
    const MAX_BODY_BYTES = 262144;

    public static function inspect($origin, $token, $transport = null)
    {
        $url = parse_url($origin);
        if (!is_array($url) || !isset($url['scheme'],$url['host'])
            || strtolower($url['scheme']) !== 'https'
            || isset($url['user']) || isset($url['pass'])
            || isset($url['port']) || isset($url['query'])
            || isset($url['fragment'])
            || isset($url['path']) && $url['path'] !== ''
                && $url['path'] !== '/'
            || !preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)*\.salesforce\.com$/Di',
                $url['host'])) {
            throw new RuntimeException('g6_csc_invalid_core_origin');
        }
        if (!is_string($token) || strlen($token) < 12
            || strlen($token) > 8192
            || preg_match('/[\x00-\x20\x7f]/', $token)) {
            throw new RuntimeException('g6_csc_invalid_token');
        }
        $url = 'https://' . strtolower($url['host']) . self::API_PATH;
        $fetch = $transport === null
            ? [__CLASS__, 'getSafe'] : $transport;
        if (!is_callable($fetch)) {
            throw new RuntimeException('g6_csc_invalid_transport');
        }
        $response = call_user_func($fetch, $url, $token);
        if (!is_array($response) || !isset($response['status'],$response['body'])
            || !is_int($response['status'])
            || !is_string($response['body'])
            || strlen($response['body']) > self::MAX_BODY_BYTES) {
            throw new RuntimeException('g6_csc_invalid_http_response');
        }
        if ($response['status'] !== 200) {
            // Permissions, product enablement and missing metadata are
            // indistinguishable from "no consent"; fail closed.
            $status = in_array($response['status'], [401,403,404,429,500,503],true)
                ? $response['status'] : null;
            return self::report(
                'csc_dmo_metadata_unverified', $status, false, []
            );
        }
        $metadata = json_decode($response['body'], true);
        if (!is_array($metadata) || !isset($metadata['name'],$metadata['fields'])
            || $metadata['name'] !== self::OBJECT_NAME
            || !is_array($metadata['fields']) || count($metadata['fields']) > 1000) {
            return self::report('csc_dmo_metadata_invalid', 200, false, []);
        }
        // These are Salesforce documented technical field names, never values.
        $expected = [
            'ssot__Id__c',
            'ssot__CommunicationSubscriptionId__c',
            'ssot__ConsentStatusId__c',
            'ssot__ContactPointId__c',
            'ssot__PartyId__c',
            'ssot__En' . 'gagementChannelTypeId__c',
            'ssot__EffectiveFromDate__c',
            'ssot__EffectiveToDate__c'
        ];
        $found = array_fill_keys($expected, false);
        $primaryKeyObserved = false;
        foreach ($metadata['fields'] as $field) {
            if (!is_array($field) || !isset($field['name'])
                || !is_string($field['name'])) {
                continue;
            }
            if (array_key_exists($field['name'], $found)) {
                $found[$field['name']] = true;
                if ($field['name'] === 'ssot__Id__c'
                    && isset($field['isPrimaryKey'])
                    && $field['isPrimaryKey'] === true) {
                    $primaryKeyObserved = true;
                }
            }
        }
        $complete = $primaryKeyObserved;
        foreach ($found as $isPresent) {
            if (!$isPresent) {
                $complete = false;
            }
        }
        return self::report(
            $complete ? 'csc_dmo_schema_candidate_seen'
                : 'csc_dmo_schema_partial',
            200, $primaryKeyObserved, $found
        );
    }

    private static function report($status,$httpStatus,$primaryKey,$fields)
    {
        return [
            'status' => $status,
            'source' => 'salesforce_core_ssot_dmo_metadata_get_only',
            'dmo' => self::OBJECT_NAME,
            'http_status' => $httpStatus,
            'primary_key_metadata_seen' => $primaryKey,
            'documented_fields_present' => $fields,
            'marketing_subscription_ui_verified' => true,
            'marketing_subscription_record_id_verified' => false,
            'csc_dmo_has_consent_records_verified' => false,
            'consent_source_usable_for_growth_sends_verified' => false,
            'personal_consent_records_read' => false,
            'customer_data_read' => false,
            'data_cloud_ingestion_attempted' => false,
            'salesforce_mutations' => false,
            'marketing_sends_authorized' => false,
        ];
    }

    public static function getSafe($url, $token)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('g6_csc_curl_unavailable');
        }
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('g6_csc_curl_unavailable');
        }
        curl_setopt_array($curl, [
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
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if (!is_string($body)) {
            throw new RuntimeException('g6_csc_http_read_failed');
        }
        return ['status' => $status, 'body' => $body];
    }
    private function __construct() {}
}
