<?php
/**
 * G6 review-only Core configuration. One fixed, bounded SOQL GET.
 * A linked channel record is NOT proof of its channel type, nor of consent.
 * No contact records, outbound activity, persistent IDs, hooks or cron.
 */
final class G6MarketingEmailCoreBindingReview
{
    const SOQL = "SELECT Id, CommunicationSubscriptionId, CommunicationSubscription.Name "
        . "FROM CommSubscriptionChannelType "
        . "WHERE CommunicationSubscription.Name = 'Marketing' LIMIT 2";
    const API = '/services/data/v68.0/query/?q=';
    const MAX_BYTES = 262144;

    public static function inspect($origin, $token, $transport = null)
    {
        $u = is_string($origin) ? parse_url($origin) : false;
        if (!is_array($u) || !isset($u['scheme'],$u['host'])
            || strtolower($u['scheme']) !== 'https'
            || isset($u['user']) || isset($u['pass'])
            || isset($u['port']) || isset($u['query']) || isset($u['fragment'])
            || (isset($u['path']) && $u['path'] !== '' && $u['path'] !== '/')
            || !preg_match('/^[a-z0-9-]+(?:[.][a-z0-9-]+)*[.]salesforce[.]com$/Di',
                $u['host'])) {
            throw new RuntimeException('g6_binding_invalid_core_origin');
        }
        if (!is_string($token) || strlen($token)<12 || strlen($token)>8192
            || preg_match('/[[:space:]]/', $token)) {
            throw new RuntimeException('g6_binding_invalid_token');
        }
        $get = $transport === null ? [__CLASS__,'getSafe'] : $transport;
        if (!is_callable($get)) {
            throw new RuntimeException('g6_binding_invalid_transport');
        }
        try {
            $response = call_user_func($get,
                'https://'.strtolower($u['host']).self::API
                    .rawurlencode(self::SOQL), $token);
        } catch (Throwable $e) {
            return self::report('core_config_transport_unverified',null,false);
        }
        if (!is_array($response) || !isset($response['status'],$response['body'])
            || !is_int($response['status']) || !is_string($response['body'])
            || strlen($response['body'])>self::MAX_BYTES) {
            return self::report('core_config_transport_unverified',null,false);
        }
        if ($response['status']!==200) {
            $http = in_array($response['status'],
                [400,401,403,404,429,500,503],true)
                ? $response['status'] : null;
            return self::report('core_config_http_unverified',$http,false);
        }
        $data = json_decode($response['body'],true);
        if (!is_array($data) || !isset($data['totalSize'],$data['done'],$data['records'])
            || !is_int($data['totalSize']) || !is_bool($data['done'])
            || $data['done']!==true || !is_array($data['records'])
            || count($data['records'])>2
            || $data['totalSize']!==count($data['records'])) {
            return self::report('core_config_result_unverified',200,false);
        }
        if (!$data['records']) {
            return self::report('core_link_not_observed_review_only',200,false);
        }
        if (count($data['records'])!==1) {
            return self::report('core_link_ambiguous_review_only',200,false);
        }
        $record=$data['records'][0];
        if (!is_array($record) || !isset($record['Id'])
            || !isset($record['CommunicationSubscriptionId'])
            || !is_string($record['Id'])
            || !is_string($record['CommunicationSubscriptionId'])
            || !preg_match('/^[A-Za-z0-9]{15}([A-Za-z0-9]{3})?$/D',
                $record['Id'])
            || !preg_match('/^[A-Za-z0-9]{15}([A-Za-z0-9]{3})?$/D',
                $record['CommunicationSubscriptionId'])
            || !isset($record['CommunicationSubscription']['Name'])
            || $record['CommunicationSubscription']['Name']!=='Marketing') {
            return self::report('core_config_result_unverified',200,false);
        }
        return self::report('marketing_core_channel_link_observed_review_only',
            200,true);
    }

    private static function report($state,$http,$seen)
    {
        return [
            'stage'=>'g6_core_marketing_channel_link_config_only',
            'status'=>'hold_for_review',
            'binding_state'=>$state,
            'http_status'=>$http,
            'single_marketing_channel_link_observed'=>$seen,
            'email_channel_type_technically_verified'=>false,
            'business_unit_scope_certified'=>false,
            'customer_consent_records_read'=>false,
            'csc_effective_consent_certified'=>false,
            'ids_or_auth_exported'=>false,
            'marketing_sends_authorized'=>false,
            'data_cloud_ingestion_authorized'=>false,
        ];
    }

    public static function getSafe($url,$token)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('g6_binding_curl_unavailable');
        }
        $curl=curl_init($url);
        if ($curl===false) {
            throw new RuntimeException('g6_binding_curl_unavailable');
        }
        curl_setopt_array($curl,[
            CURLOPT_HTTPGET=>true,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Accept: application/json'],
            CURLOPT_TIMEOUT=>20,
            CURLOPT_CONNECTTIMEOUT=>10,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS
        ]);
        $body=curl_exec($curl);
        $status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
        curl_close($curl);
        if (!is_string($body)) {
            throw new RuntimeException('g6_binding_http_read_unverified');
        }
        return ['status'=>$status,'body'=>$body];
    }

    private function __construct() {}
}
