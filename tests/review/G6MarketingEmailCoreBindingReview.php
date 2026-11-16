<?php
/**
 * G6 review-only Salesforce Core binding: Marketing subscription -> Email.
 * Exactly ONE fixed, bounded SOQL GET for configuration records. NO person,
 * contact, consent row, write, ingestion, PSCart or marketing send.
 *
 * Never export Salesforce IDs or arbitrary org fields. This does NOT prove
 * whether any recipient has opted into Marketing Cloud Growth messages.
 */
final class G6MarketingEmailCoreBindingReview
{
    const SOQL = "SELECT CommunicationSubscriptionId, CommunicationSubscription.Name, "
        . "EngagementChannelType.ContactPointType FROM CommSubscriptionChannelType "
        . "WHERE CommunicationSubscription.Name = 'Marketing' "
        . "AND EngagementChannelType.ContactPointType = 'Email' LIMIT 2";
    const API = '/services/data/v68.0/query/?q=';
    const MAX_BYTES = 262144;

    public static function inspect($origin, $token, $transport = null)
    {
        $parsed = is_string($origin) ? parse_url($origin) : false;
        if (!is_array($parsed) || !isset($parsed['scheme'], $parsed['host'])
            || strtolower($parsed['scheme']) !== 'https'
            || isset($parsed['user']) || isset($parsed['pass'])
            || isset($parsed['port']) || isset($parsed['query'])
            || isset($parsed['fragment'])
            || (isset($parsed['path']) && $parsed['path'] !== ''
                && $parsed['path'] !== '/')
            || !preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)*\.salesforce\.com$/Di',
                $parsed['host'])) {
            throw new RuntimeException('g6_binding_invalid_core_origin');
        }
        if (!is_string($token) || strlen($token) < 12 || strlen($token) > 8192
            || preg_match('/[\x00-\x20\x7f]/', $token)) {
            throw new RuntimeException('g6_binding_invalid_token');
        }
        $fetch = $transport === null ? [__CLASS__, 'getSafe'] : $transport;
        if (!is_callable($fetch)) {
            throw new RuntimeException('g6_binding_invalid_transport');
        }
        $url = 'https://' . strtolower($parsed['host'])
            . self::API . rawurlencode(self::SOQL);
        try {
            $response = call_user_func($fetch, $url, $token);
        } catch (Throwable $error) {
            return self::report('core_config_transport_unverified', null, false);
        }
        if (!is_array($response) || !isset($response['status'], $response['body'])
            || !is_int($response['status']) || !is_string($response['body'])
            || strlen($response['body']) > self::MAX_BYTES) {
            return self::report('core_config_transport_unverified', null, false);
        }
        if ($response['status'] !== 200) {
            $safe = in_array($response['status'],
                [400,401,403,404,429,500,503], true)
                ? $response['status'] : null;
            return self::report('core_config_http_unverified', $safe, false);
        }
        $json = json_decode($response['body'], true);
        if (!is_array($json)
            || !isset($json['totalSize'], $json['done'], $json['records'])
            || !is_int($json['totalSize']) || $json['totalSize'] < 0
            || !is_bool($json['done']) || !is_array($json['records'])
            || count($json['records']) > 2 || $json['done'] !== true
            || $json['totalSize'] !== count($json['records'])) {
            return self::report('core_config_result_unverified', 200, false);
        }
        if (!$json['records']) {
            // A zero-result query is not evidence that Growth consent is
            // absent; permissions, scope and UI config remain independent.
            return self::report('core_binding_not_observed_review_only', 200, false);
        }
        if (count($json['records']) !== 1) {
            return self::report('core_binding_ambiguous_review_only', 200, false);
        }
        $record = $json['records'][0];
        if (!is_array($record)
            || !isset($record['CommunicationSubscriptionId'])
            || !is_string($record['CommunicationSubscriptionId'])
            || !preg_match('/^[A-Za-z0-9]{15}(?:[A-Za-z0-9]{3})?$/D',
                $record['CommunicationSubscriptionId'])
            || !isset($record['CommunicationSubscription']['Name'])
            || $record['CommunicationSubscription']['Name'] !== 'Marketing'
            || !isset($record['EngagementChannelType']['ContactPointType'])
            || $record['EngagementChannelType']['ContactPointType'] !== 'Email') {
            return self::report('core_config_result_unverified', 200, false);
        }
        return self::report('marketing_email_core_binding_observed_review_only',
            200, true);
    }

    private static function report($state, $status, $observed)
    {
        return [
            'stage' => 'g6_marketing_email_core_binding_configuration_only',
            'status' => 'hold_for_review',
            'binding_state' => $state,
            'http_status' => $status,
            'single_marketing_email_link_observed' => $observed,
            'salesforce_subscription_id_exported' => false,
            'salesforce_channel_id_exported' => false,
            'consumer_consent_records_read' => false,
            'csc_effective_consent_certified' => false,
            'business_unit_scope_certified' => false,
            'recipient_consent_read_authorized' => false,
            'data_cloud_ingestion_authorized' => false,
            'marketing_sends_authorized' => false,
        ];
    }

    public static function getSafe($url, $token)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('g6_binding_curl_unavailable');
        }
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('g6_binding_curl_unavailable');
        }
        curl_setopt_array($curl, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
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
            throw new RuntimeException('g6_binding_http_read_unverified');
        }
        return ['status' => $status, 'body' => $body];
    }

    private function __construct() {}
}
