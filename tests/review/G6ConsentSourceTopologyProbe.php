<?php
/**
 * G6: read-only topology of an existing Salesforce Marketing Cloud Growth
 * consent configuration. This is a REVIEW TOOL, never an installable module.
 *
 * Two fixed Data Cloud Connect REST GETs: CSC DMO mapping metadata and
 * first page (200) of data stream configuration. NO consent records,
 * contacts, tokens, raw responses or unknown object names in output.
 *
 * A visible stream or mapping is NOT certification of consent validity,
 * deliverability, supported ingestion or send-time consent behavior.
 */
final class G6ConsentSourceTopologyProbe
{
    const CSC_DMO = 'ssot__CommunicationSubscriptionConsent__dlm';
    const API = '/services/data/v68.0/ssot/';
    const MAX_HTTP_BODY = 524288;
    const STREAM_PAGE_LIMIT = 200;
    const MAPPING_LIMIT = 500;

    public static function inspect($coreOrigin, $coreToken, $transport = null)
    {
        if (!is_string($coreOrigin)) {
            throw new RuntimeException('g6_topology_invalid_origin');
        }
        $u = parse_url($coreOrigin);
        if (!is_array($u) || !isset($u['scheme'],$u['host'])
            || strtolower($u['scheme']) !== 'https'
            || isset($u['user']) || isset($u['pass']) || isset($u['port'])
            || isset($u['query']) || isset($u['fragment'])
            || (isset($u['path']) && $u['path'] !== '' && $u['path'] !== '/')
            || !preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)*\.salesforce\.com$/Di',
                $u['host'])) {
            throw new RuntimeException('g6_topology_invalid_origin');
        }
        if (!is_string($coreToken) || strlen($coreToken) < 12
            || strlen($coreToken) > 8192
            || preg_match('/[\x00-\x20\x7f]/', $coreToken)) {
            throw new RuntimeException('g6_topology_invalid_token');
        }
        $fetch = $transport === null ? [__CLASS__, 'getSafe'] : $transport;
        if (!is_callable($fetch)) {
            throw new RuntimeException('g6_topology_invalid_transport');
        }
        $origin = 'https://' . strtolower($u['host']) . self::API;
        $mappingPath = 'data-model-object-mappings?dmoDeveloperName='
            . self::CSC_DMO . '&dataspace=default';
        $streamPath = 'data-streams?limit=200&offset=0&orderBy=DeveloperName';

        $mapping = self::httpReply(
            call_user_func($fetch, $origin . $mappingPath, $coreToken)
        );
        $streams = self::httpReply(
            call_user_func($fetch, $origin . $streamPath, $coreToken)
        );
        return [
            'stage' => 'g6_consent_topology_metadata_get_only',
            'dataspace' => 'default',
            'csc_dmo_schema_observed_previous_run' => true,
            'growth_marketing_email_subscription_observed_ui' => true,
            'csc_mapping' => self::mappingReport($mapping),
            'consent_related_streams' => self::streamReport($streams),
            'full_tenant_mapping_catalog_certified' => false,
            'full_tenant_stream_inventory_certified' => false,
            'communication_subscription_technical_id_verified' => false,
            'communication_subscription_consent_source_certified' => false,
            'send_time_consent_cache_certified' => false,
            'individual_consent_records_read' => false,
            'customer_data_read' => false,
            'data_cloud_ingestion_attempted' => false,
            'salesforce_writes_performed' => false,
            'marketing_sends_authorized' => false,
        ];
    }

    private static function mappingReport($response)
    {
        $base = [
            'state' => 'unverified',
            'http_status' => self::safeStatus($response['status']),
            'visible_mapping_count' => null,
            'active_mapping_count' => null,
            'all_active_details_bounded' => false,
            'absence_certified' => false,
            'sending_approved' => false,
        ];
        if ($response['status'] !== 200) {
            $base['state'] = 'mapping_metadata_http_unverified';
            return $base;
        }
        $data = json_decode($response['body'],true);
        if (!is_array($data)
            || !isset($data['objectSourceTargetMaps'])
            || !is_array($data['objectSourceTargetMaps'])
            || count($data['objectSourceTargetMaps']) > self::MAPPING_LIMIT) {
            $base['state'] = 'mapping_metadata_shape_unverified';
            return $base;
        }
        $active = 0;
        foreach ($data['objectSourceTargetMaps'] as $row) {
            if (!is_array($row)
                || !isset($row['targetEntityDeveloperName'],$row['status'])
                || $row['targetEntityDeveloperName'] !== self::CSC_DMO
                || !is_string($row['status'])) {
                $base['state'] = 'mapping_metadata_shape_unverified';
                return $base;
            }
            if ($row['status'] === 'ACTIVE') {
                ++$active;
            }
        }
        $base['state'] = 'mapping_metadata_observed_review_only';
        $base['visible_mapping_count'] = count($data['objectSourceTargetMaps']);
        $base['active_mapping_count'] = $active;
        $base['all_active_details_bounded'] = true;
        return $base;
    }

    private static function streamReport($response)
    {
        $found = [
            'messaging_consent_v2_stream' => false,
            'consent_audit_trail_v2_stream' => false,
            'crm_subscription_stream' => false,
            'crm_subscription_channel_stream' => false
        ];
        $base = [
            'state' => 'unverified',
            'http_status' => self::safeStatus($response['status']),
            'page_limit' => self::STREAM_PAGE_LIMIT,
            'first_page_count' => null,
            'first_page_only' => true,
            'expected_names_seen_in_first_page' => $found,
            'missing_streams_certified' => false,
            'source_kit_installed_certified' => false,
        ];
        if ($response['status'] !== 200) {
            $base['state'] = 'stream_inventory_http_unverified';
            return $base;
        }
        $data = json_decode($response['body'],true);
        if (!is_array($data) || !isset($data['dataStreams'])
            || !is_array($data['dataStreams'])
            || count($data['dataStreams']) > self::STREAM_PAGE_LIMIT) {
            $base['state'] = 'stream_inventory_shape_unverified';
            return $base;
        }
        foreach ($data['dataStreams'] as $stream) {
            if (!is_array($stream)) {
                $base['state'] = 'stream_inventory_shape_unverified';
                return $base;
            }
            // Salesforce stream metadata can expose a display "name" or a
            // developerName; never emit arbitrary string values.
            $names = [];
            foreach (['name', 'developerName', 'label'] as $field) {
                if (isset($stream[$field]) && is_string($stream[$field])
                    && strlen($stream[$field]) <= 255) {
                    $names[] = $stream[$field];
                }
            }
            foreach ($names as $value) {
                if ($value === 'MessagingConsentV2-MessagingConsent') {
                    $found['messaging_consent_v2_stream'] = true;
                }
                if ($value === 'ConsentAuditTrailV2-ConsentAuditTrail') {
                    $found['consent_audit_trail_v2_stream'] = true;
                }
                if (preg_match('/^CommSubscription_[a-zA-Z0-9]{8,25}$/D',$value)) {
                    $found['crm_subscription_stream'] = true;
                }
                if (preg_match('/^CommSubscriptionChannelType_[a-zA-Z0-9]{8,25}$/D',$value)) {
                    $found['crm_subscription_channel_stream'] = true;
                }
            }
        }
        $base['state'] = 'first_page_observed_review_only';
        $base['first_page_count'] = count($data['dataStreams']);
        $base['expected_names_seen_in_first_page'] = $found;
        return $base;
    }

    private static function safeStatus($status)
    {
        return in_array($status,[200,400,401,403,404,429,500,503],true)
            ? $status : null;
    }

    private static function httpReply($result)
    {
        if (!is_array($result) || !isset($result['status'],$result['body'])
            || !is_int($result['status']) || !is_string($result['body'])
            || strlen($result['body']) > self::MAX_HTTP_BODY) {
            throw new RuntimeException('g6_topology_invalid_http_response');
        }
        return $result;
    }

    public static function getSafe($url,$token)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('g6_topology_curl_unavailable');
        }
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('g6_topology_curl_unavailable');
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
        $status = (int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
        curl_close($curl);
        if (!is_string($body)) {
            throw new RuntimeException('g6_topology_http_read_failed');
        }
        return ['status'=>$status,'body'=>$body];
    }

    private function __construct() {}
}
