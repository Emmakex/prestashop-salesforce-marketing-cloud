<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use Throwable;

/**
 * G8 safety evidence for current G3 candidates: native lease + G7 CSC review
 * + tenant-scoped Query V2 presence. Never authorizes delivery.
 *
 * The injected CSC reviewer MUST be the exact G7 inspectSelected read-only
 * call, and the DLO callback MUST be backed by the tenant-resolved G8 reader.
 * Those two dependencies have no send-time authorization semantics.
 *
 * Only sanitized aggregate counts escape; no refs/IDs/keys/consent rows.
 * No SQL writes, CAS claims, READY transitions, OAuth, POST ingestion,
 * campaign send, additional queue or cursor persistence occurs here.
 */
final class GrowthG8StagingSafetyReview
{
    const MAX_TICK=10;

    public static function inspect(
        array $scope,array $candidates,$lease,$reviewCsc,$readDlo,
        $orderCustomerId
    ) {
        $shop=isset($scope['shop'])?$scope['shop']:null;
        $merchant=isset($scope['merchant'])?$scope['merchant']:null;
        $namespace=isset($scope['namespace'])?$scope['namespace']:null;
        if (!is_int($shop) || $shop<1
            || !GrowthShopSourceBinding::isMerchant($merchant)
            || !GrowthShopSourceBinding::isNamespace($namespace)
            || preg_match('/(?:^|_)(?:stage|staging)(?:_|$)/D',$namespace)!==1
            || !isset($scope['mode'],$scope['capture'])
            || $scope['mode']!=='test' || $scope['capture']!=='off'
            || !class_exists('Configuration')
            || (string)\Configuration::get('PS_GROWTH_MERCHANT')!==$merchant
            || (string)\Configuration::get('PS_GROWTH_NAMESPACE')!==$namespace
            || (string)\Configuration::get('PS_SFMC_MODE')!=='test'
            || (string)\Configuration::get('PS_GROWTH_LOCAL_CAPTURE')!=='off'
            || !is_object($lease) || !is_callable([$lease,'inspect'])
            || !is_callable($reviewCsc) || !is_callable($readDlo)
            || !is_callable($orderCustomerId)
            || count($candidates)>self::MAX_TICK) {
            return self::report('blocked_by_configuration',null);
        }
        try {
            $bound=GrowthShopSourceBinding::inspect(
                $shop,$merchant,$namespace
            );
            if (!isset($bound['ready']) || $bound['ready']!==true) {
                return self::report('blocked_source_binding',null);
            }
            $counts=[
                'examined'=>0,
                'current_lease_blocked'=>0,
                'consent_not_certified'=>0,
                'remote_already_present'=>0,
                'remote_unknown'=>0,
                'remote_absent_review_only'=>0,
                'parent_not_verified'=>0
            ];
            // Validate the ENTIRE candidate set before calling even a
            // read-only external callback. A bad second ref must not cause
            // a partial read of the first ref.
            $preSeen=[];$preLast=0;
            foreach ($candidates as $candidate) {
                if (!is_array($candidate) || count($candidate)!==4
                    || !isset(
                        $candidate['shop'],$candidate['type'],
                        $candidate['id'],$candidate['sequence']
                    )
                    || $candidate['shop']!==$shop
                    || !in_array(
                        $candidate['type'],['PSCustomer','PSOrder'],true
                    )
                    || !is_int($candidate['id'])
                    || $candidate['id']<1
                    || !is_int($candidate['sequence'])
                    || $candidate['sequence']<1
                    || $candidate['sequence']<=$preLast) {
                    return self::report('staging_review_failed_closed',null);
                }
                $preKey=$candidate['type'].':'.$candidate['id'];
                if (isset($preSeen[$preKey])) {
                    return self::report('staging_review_failed_closed',null);
                }
                $preSeen[$preKey]=true;
                $preLast=$candidate['sequence'];
            }
            $seen=[];$last=0;
            foreach ($candidates as $c) {
                if (!is_array($c) || count($c)!==4
                    || !isset($c['shop'],$c['type'],$c['id'],$c['sequence'])
                    || $c['shop']!==$shop
                    || !in_array($c['type'],['PSCustomer','PSOrder'],true)
                    || !is_int($c['id']) || $c['id']<1
                    || !is_int($c['sequence']) || $c['sequence']<1
                    || $c['sequence']<=$last) {
                    return self::report('staging_review_failed_closed',null);
                }
                $entityKey=$c['type'].':'.$c['id'];
                if (isset($seen[$entityKey])) {
                    return self::report('staging_review_failed_closed',null);
                }
                $seen[$entityKey]=true;
                $last=$c['sequence'];
                $counts['examined']++;
                $type=$c['type']==='PSCustomer'?'customer':'order';
                $claim=$lease->inspect($shop,$type,$c['id'],$c['sequence']);
                if (!self::blockedUnattempted($claim)) {
                    $counts['current_lease_blocked']++;
                    continue;
                }
                // G7 explicitly returns HOLD even for observed opt-in.
                // It is NOT an approved send-time CSC connector or fence.
                $csc=call_user_func($reviewCsc,$c);
                if (!self::reviewCandidate($csc)) {
                    $counts['consent_not_certified']++;
                    continue;
                }

                // Presence for this exact tenant/namespace/shop only.
                $state=call_user_func(
                    $readDlo,$shop,$namespace,$c['type'],$c['id']
                );
                if ($state==='present') {
                    $counts['remote_already_present']++;
                } elseif ($state!=='absent_confirmed') {
                    $counts['remote_unknown']++;
                } elseif ($c['type']==='PSOrder') {
                    $parent=call_user_func($orderCustomerId,$c);
                    if (!is_int($parent) || $parent<1
                        || call_user_func(
                            $readDlo,$shop,$namespace,'PSCustomer',$parent
                        )!=='present') {
                        $counts['parent_not_verified']++;
                    } else {
                        $counts['remote_absent_review_only']++;
                    }
                } else {
                    $counts['remote_absent_review_only']++;
                }
            }
            // Even perfect checks in this review are NOT permission to
            // claim/send. G6's independent send-time fence is still absent.
            return self::report('staging_review_only_no_sends',$counts);
        } catch (Throwable $ignored) {
            return self::report('staging_review_failed_closed',null);
        }
    }

    private static function blockedUnattempted($r)
    {
        return is_array($r)
            && isset($r['status'],$r['state'],$r['attempts'],
                $r['ledger_current'],$r['lease_active'])
            && $r['status']==='staging_local_lease_only'
            && $r['state']==='blocked'
            && $r['attempts']===0
            && $r['ledger_current']===true
            && $r['lease_active']===false
            && array_key_exists('last_http',$r)
            && $r['last_http']===null
            && isset($r['data_cloud_ingestion_authorized'],
                $r['outbound_worker_authorized'],$r['marketing_sends_authorized'])
            && $r['data_cloud_ingestion_authorized']===false
            && $r['outbound_worker_authorized']===false
            && $r['marketing_sends_authorized']===false;
    }

    private static function reviewCandidate($r)
    {
        return is_array($r)
            && isset($r['status'],$r['reason'],$r['csc_source_state'],
                $r['source_and_consent_rechecked'],
                $r['send_time_consent_certified'],
                $r['final_dispatch_fence_certified'],
                $r['data_cloud_ingestion_authorized'],
                $r['marketing_sends_authorized'])
            && $r['status']==='hold_for_review'
            && $r['reason']==='csc_candidate_requires_sendtime_fence'
            && $r['csc_source_state']
                ==='recent_opt_in_observed_not_certified'
            && $r['source_and_consent_rechecked']===true
            && $r['send_time_consent_certified']===false
            && $r['final_dispatch_fence_certified']===false
            && $r['data_cloud_ingestion_authorized']===false
            && $r['marketing_sends_authorized']===false;
    }

    private static function report($status,$counts)
    {
        return [
            'status'=>$status,
            'counts'=>$counts,
            'read_only'=>true,
            'cursor_exposed'=>false,
            'record_identifiers_exposed'=>false,
            'send_time_consent_certified'=>false,
            'lease_claimed'=>false,
            'ready_transition_performed'=>false,
            'data_cloud_ingestion_authorized'=>false,
            'marketing_sends_authorized'=>false,
            'automatic_retry_authorized'=>false,
            'new_queue_created'=>false
        ];
    }

    private function __construct(){}
}
