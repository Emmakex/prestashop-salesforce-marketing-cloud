<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud;

use RuntimeException;
use Throwable;

/**
 * G7: real Data Cloud streaming POST boundary, inert unless an independent
 * application-level processing authorization callback approves this exact
 * namespace/shop/object at call time.
 *
 * This is a transport primitive, NOT an authorization issuer or a scheduler.
 * Do not wire to hooks, cron or BO before end-to-end source/ledger/final
 * consent and DLO/DMO reconciliation gates have been implemented.
 * No tokens, PII, SQL, endpoint names or response bodies leave this method.
 */
final class GrowthDataCloudUpsertTransport
{
    /**
     * @param callable $checkAuthorization function($namespace,$shop,$object): bool
     *   Must perform real persisted authorization and freshness checks.
     * @param callable|null $sendHttp function($url,$body,$token): array
     *   Optional offline/test transport returning status + response_received.
     */
    public static function sendIfAuthorized(
        $origin,$sourceName,$object,array $records,$namespace,$shop,
        $dataCloudToken,$checkAuthorization,$sendHttp=null
    ) {
        if (!is_callable($checkAuthorization)
            || ($sendHttp!==null && !is_callable($sendHttp))) {
            throw new RuntimeException('g7_transport_gate_required');
        }
        if (!is_string($dataCloudToken) || $dataCloudToken===''
            || strlen($dataCloudToken)>8192
            || preg_match('/[\x00-\x20\x7f]/',$dataCloudToken)) {
            throw new RuntimeException('g7_transport_invalid_token');
        }

        // The gate must be independent of PrestaShop newsletter and of a
        // mere G6 synthetic CSC opt-in. It must check current ownership and
        // a documented personal-data processing permission.
        try {
            $permitted=call_user_func($checkAuthorization,$namespace,$shop,$object);
        } catch (Throwable $ignored) {
            throw new RuntimeException('g7_transport_not_authorized');
        }
        if ($permitted!==true) {
            throw new RuntimeException('g7_transport_not_authorized');
        }

        // Recompute URL + exact PST payload with the existing vetted builder.
        // No caller-provided raw URL/path or pre-encoded JSON is accepted.
        $plan=GrowthStreamingIngestionPlan::prepare(
            $origin,$sourceName,$object,$records,$namespace,$shop,
            'marketing_cloud_growth','test'
        );
        if ($plan['status']!=='blocked_transport_plan_only'
            || $plan['dispatch_authorized']!==false
            || $plan['marketing_sends_authorized']!==false
            || $plan['count']<1) {
            throw new RuntimeException('g7_transport_plan_invalid');
        }

        // This primitive only transmits after the separate approval callback.
        // 202 is asynchronous acceptance, NEVER delivery confirmation.
        // Any uncertainty is returned for the durable G3 reconciliation
        // controller; no hidden retry, no follow-up request, no logging.
        try {
            $result=$sendHttp===null
                ? self::post($plan['url'],$plan['body'],$dataCloudToken)
                : call_user_func($sendHttp,$plan['url'],$plan['body'],$dataCloudToken);
            if (!is_array($result)
                || !isset($result['response_received'])
                || !is_bool($result['response_received'])
                || !array_key_exists('status',$result)
                || !is_int($result['status'])) {
                $result=['response_received'=>false,'status'=>0];
            }
        } catch (Throwable $ignored) {
            $result=['response_received'=>false,'status'=>0];
        }
        $outcome=GrowthStreamingIngestionPlan::classify(
            $result['status'],$result['response_received']
        );
        // Exact HTTP status for G3 durable held/reconcile receipts ONLY.
        // A timeout or unverified response must never become a known HTTP.
        $safeHttp=$result['response_received']===true
            && $result['status']>=100 && $result['status']<=599
            ?$result['status']:null;
        unset($plan,$result);
        return [
            'status'=>'ingestion_transport_result_requires_reconciliation',
            'outcome_state'=>$outcome['outcome_state'],
            'http_status'=>$safeHttp,
            'accepted_asynchronously_unconfirmed'=>
                $outcome['request_accepted_asynchronously'],
            'reconciliation_required'=>true,
            'record_persisted_certified'=>false,
            'record_mapped_to_dmo_certified'=>false,
            'automatic_retry_authorized'=>false,
            'marketing_sends_authorized'=>false,
            'http_attempted'=>true,
        ];
    }

    /** Real HTTPS transport; discard the entire response body. */
    private static function post($url,$body,$token)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('g7_transport_curl_unavailable');
        }
        $handle=curl_init($url);
        if ($handle===false) {
            throw new RuntimeException('g7_transport_curl_init_failed');
        }
        try {
            $settings=[
                CURLOPT_POST=>true,
                CURLOPT_POSTFIELDS=>$body,
                CURLOPT_RETURNTRANSFER=>false,
                CURLOPT_HTTPHEADER=>[
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'Authorization: Bearer '.$token
                ],
                CURLOPT_CONNECTTIMEOUT=>10,
                CURLOPT_TIMEOUT=>20,
                CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_SSL_VERIFYPEER=>true,
                CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_WRITEFUNCTION=>function($handle,$bytes) {
                    return strlen($bytes);
                },
            ];
            if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
                $settings[CURLOPT_PROTOCOLS]=CURLPROTO_HTTPS;
            }
            curl_setopt_array($handle,$settings);
            $ok=curl_exec($handle);
            $status=(int)curl_getinfo($handle,CURLINFO_HTTP_CODE);
            return [
                'status'=>$status,
                'response_received'=>$ok!==false && $status>=100
            ];
        } finally {
            curl_close($handle);
        }
    }

    private function __construct() {}
}
