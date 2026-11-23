<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use Throwable;

/**
 * Bounded G8 staging worker over the SAME original G3 tables.
 *
 * No new queue, campaign, retry scheduler or legacy provider. Requires a
 * separately authenticated app-level approval and six trusted callbacks.
 * Production cron must never expose these callbacks or event references.
 *
 * The real module builds the callbacks using fresh native objects, Data
 * Cloud metadata/query, independent local policy, and vetted transport.
 */
final class GrowthG8StagingWorker
{
    const MAX_BATCH=10;
    const APPROVAL='OWNER_APPROVES_STAGING_G8_INGESTION';

    public static function run(
        $db,$version,array $scope,$prefix,array $callbacks
    ) {
        if (!self::allowed($scope,$callbacks)
            || !is_object($db) || !is_callable([$db,'executeS'])
            || !is_string($version) || $version===''
            || !is_string($prefix)
            || preg_match('/^[A-Za-z0-9_]{1,32}$/D',$prefix)!==1) {
            return self::report('staging_worker_blocked',null);
        }
        $phase='source_binding';
        try {
            $shop=$scope['shop'];
            $merchant=$scope['merchant'];
            $namespace=$scope['namespace'];
            $source=GrowthShopSourceBinding::sourceForSend(
                $shop,$merchant,$namespace
            );
            $phase='native_lease';
            // Check the extended schema before any outbound operation.
            $lease=new GrowthPrestaShopDeliveryLease(
                $db,$version,$merchant,$namespace,$prefix
            );
            $phase='g3_scan';
            $review=GrowthG7CurrentEventBatch::scan(
                $db,$version,$merchant,$namespace,$prefix,
                $shop,0,self::MAX_BATCH
            );
            $phase='candidate_adapter';
            $candidates=GrowthG8StagingCandidateAdapter::adapt(
                $review,$shop,0,self::MAX_BATCH
            );
            $phase='ready_promotion';
            $promoted=0;$blocked=0;
            foreach ($candidates as $candidate) {
                // This value is never logged or returned.
                $fresh=self::loadChecked(
                    $callbacks['loadFresh'],$candidate,$namespace,$shop
                );
                if ($fresh===null) {
                    $blocked++;
                    continue;
                }
                $eligible=function($c)use($callbacks,$candidate,$fresh) {
                    return $c===$candidate
                        && self::isEligible($callbacks,$candidate,$fresh)
                        && self::contact(
                            $callbacks,$candidate,$fresh,'before_claim'
                        );
                };
                $absent=function($c)use($callbacks,$candidate,$fresh) {
                    if ($c!==$candidate)return 'unknown';
                    return call_user_func(
                        $callbacks['recordDloStatus'],$candidate,$fresh
                    );
                };
                $parent=function($c)use($callbacks,$fresh,$candidate,$shop,$namespace) {
                    if ($c!==$candidate)return false;
                    if ($candidate['type']==='PSCustomer')return true;
                    return call_user_func(
                        $callbacks['parentDloVerified'],
                        $shop,$namespace,$fresh['customer_id']
                    )===true;
                };
                $ready=GrowthG8StagingReadyGate::promote(
                    $db,$version,$scope,$prefix,$candidate,
                    $eligible,$absent,$parent
                );
                if ($ready['status']==='ready_for_one_cas_only')$promoted++;
                else $blocked++;
            }

            $phase='ready_scan';
            $window=GrowthG8StagingReadyGate::scanReady(
                $db,$version,$scope,$prefix,0,self::MAX_BATCH
            );
            if ($window['status']!=='ready_candidates_private') {
                return self::report('staging_worker_unverified',null);
            }
            $ready=$window['candidates_private'];
            if ($ready===[]) {
                return self::report('staging_tick_no_ready',[
                    'reviewed'=>count($candidates),
                    'promoted'=>$promoted,'blocked'=>$blocked,
                    'examined'=>0,'reserved'=>0,
                    'accepted_202_unconfirmed'=>0,
                    'pending_reconciliation'=>0,'held'=>0,
                    'skipped'=>0
                ]);
            }
            $bridge=new GrowthG8DurableReceiptBridge($lease,$scope);
            $load=function($candidate)use($callbacks,$namespace,$shop) {
                $fresh=self::loadChecked(
                    $callbacks['loadFresh'],$candidate,$namespace,$shop
                );
                if ($fresh===null) {
                    throw new \RuntimeException('g8_fresh_unverified');
                }
                return $fresh;
            };
            $local=function($fresh)use($callbacks) {
                return call_user_func(
                    $callbacks['locallyEligible'],$fresh
                )===true;
            };
            $presence=function($candidate,$fresh)use($callbacks) {
                return call_user_func(
                    $callbacks['recordDloStatus'],$candidate,$fresh
                );
            };
            $parent=function($shop,$namespace,$customerId)use($callbacks) {
                return call_user_func(
                    $callbacks['parentDloVerified'],
                    $shop,$namespace,$customerId
                )===true;
            };
            $reserve=function($c,$fingerprint)use($bridge) {
                return $bridge->reserveOnce($c,$fingerprint);
            };
            $post=function($actualSource,$type,$payload,$candidate)
                use($source,$bridge,$callbacks,$load,$namespace,$shop) {
                if ($actualSource!==$source) {
                    throw new \RuntimeException('g8_source_changed');
                }
                // Third fresh read inside transport, not just the
                // coordinator's two reads before its send callback.
                $last=$load($candidate);
                if ($last['payload_private']!==$payload
                    || !$bridge->claimedForPost(
                        $candidate,$last['fingerprint']
                    )
                    || !self::isEligible($callbacks,$candidate,$last)
                    || !self::contact(
                        $callbacks,$candidate,$last,'before_transport'
                    )
                    || call_user_func(
                        $callbacks['recordDloStatus'],$candidate,$last
                    )!=='absent_confirmed') {
                    throw new \RuntimeException('g8_final_fence_denied');
                }
                if ($type==='PSOrder'
                    && call_user_func(
                        $callbacks['parentDloVerified'],
                        $shop,$namespace,$last['customer_id']
                    )!==true) {
                    throw new \RuntimeException('g8_parent_changed');
                }
                return call_user_func(
                    $callbacks['postOnce'],$source,$type,
                    $payload,$candidate,
                    function()use($bridge,$candidate,$last,$callbacks) {
                        return $bridge->claimedForPost(
                                $candidate,$last['fingerprint']
                            )
                            && self::contact(
                                $callbacks,$candidate,$last,'before_transport'
                            );
                    }
                );
            };
            $persist=function($c,$state,$http,$started)use($bridge) {
                return $bridge->persistOutcome($c,$state,$http,$started);
            };
            $contact=function($c,$id,$phase)use($callbacks) {
                return call_user_func(
                    $callbacks['contactEligibility'],$c,$id,$phase
                )===true;
            };
            $phase='cas_tick';
            $outcome=GrowthAutomaticOneShotCoordinator::tick(
                $scope,$ready,$load,$local,$presence,$parent,
                $reserve,$post,$persist,$contact
            );
            return self::report(
                $outcome['status']==='reconciliation_required'
                    ?'staging_reconciliation_required'
                    :'staging_tick_completed_unconfirmed',
                [
                    'reviewed'=>count($candidates),
                    'promoted'=>$promoted,'blocked'=>$blocked,
                    'examined'=>$outcome['examined'],
                    'reserved'=>$outcome['reserved'],
                    'accepted_202_unconfirmed'=>$outcome['accepted_202_unconfirmed'],
                    'pending_reconciliation'=>$outcome['pending_reconciliation'],
                    'held'=>$outcome['held'],'skipped'=>$outcome['skipped']
                ]
            );
        } catch (Throwable $ignored) {
            return self::report('staging_worker_failed_closed',null,$phase);
        }
    }

    private static function allowed($s,$cb)
    {
        if (!isset($s['shop'],$s['merchant'],$s['namespace'],
            $s['mode'],$s['capture'],$s['owner_gate'])
            || !is_int($s['shop']) || $s['shop']<1
            || !GrowthShopSourceBinding::isMerchant($s['merchant'])
            || !GrowthShopSourceBinding::isNamespace($s['namespace'])
            || preg_match('/(?:^|_)(?:stage|staging)(?:_|$)/D',
                $s['namespace'])!==1
            || $s['mode']!=='test' || $s['capture']!=='off'
            || $s['owner_gate']!==GrowthAutomaticOneShotCoordinator::OWNER_GATE
            || !class_exists('Configuration')
            || (string)\Configuration::get('PS_GROWTH_G8_STAGING_APPROVAL')
                !==self::APPROVAL
            || (string)\Configuration::get('PS_SFMC_MODE')!=='test'
            || (string)\Configuration::get('PS_GROWTH_LOCAL_CAPTURE')!=='off'
            || (string)\Configuration::get('PS_GROWTH_MERCHANT')
                !==$s['merchant']
            || (string)\Configuration::get('PS_GROWTH_NAMESPACE')
                !==$s['namespace'])return false;
        foreach ([
            'loadFresh','locallyEligible','contactEligibility',
            'recordDloStatus','parentDloVerified','postOnce'
        ] as $name) {
            if (!isset($cb[$name]) || !is_callable($cb[$name]))return false;
        }
        return count($cb)===6;
    }

    private static function loadChecked($reader,$candidate,$ns,$shop)
    {
        try {
            $fresh=call_user_func($reader,$candidate);
            if (!is_array($fresh)
                || !isset($fresh['shop'],$fresh['type'],$fresh['id'],
                    $fresh['sequence'],$fresh['fingerprint'],
                    $fresh['payload_private'],$fresh['customer_id'])
                || $fresh['shop']!==$shop
                || $fresh['type']!==$candidate['type']
                || $fresh['id']!==$candidate['id']
                || $fresh['sequence']!==$candidate['sequence']
                || !is_int($fresh['customer_id'])
                || $fresh['customer_id']<1
                || !is_string($fresh['fingerprint'])
                || preg_match('/^[a-f0-9]{64}$/D',
                    $fresh['fingerprint'])!==1
                || !is_array($fresh['payload_private']))return null;
            $payload=$fresh['payload_private'];
            $expected=$ns.':'.$shop.':'
                .($candidate['type']==='PSCustomer'?'customer':'order')
                .':'.$candidate['id'];
            if (!isset(
                $payload['source_key'],$payload['source_namespace'],
                $payload['shop_id']
            ) || $payload['source_key']!==$expected
                || $payload['source_namespace']!==$ns
                || $payload['shop_id']!==$shop
                || ($candidate['type']==='PSCustomer'
                    && ($fresh['customer_id']!==$candidate['id']
                        || !isset($payload['customer_id'])
                        || $payload['customer_id']!==$candidate['id']))
                || ($candidate['type']==='PSOrder'
                    && (!isset($payload['customer_source_key'])
                        || $payload['customer_source_key']
                            !==$ns.':'.$shop.':customer:'
                                .$fresh['customer_id'])))return null;
            return $fresh;
        } catch (Throwable $ignored) {
            return null;
        }
    }

    private static function isEligible($callbacks,$candidate,$fresh)
    {
        return call_user_func(
            $callbacks['locallyEligible'],$fresh
        )===true;
    }

    private static function contact($callbacks,$candidate,$fresh,$phase)
    {
        return call_user_func(
            $callbacks['contactEligibility'],
            $candidate,$fresh['customer_id'],$phase
        )===true;
    }

    private static function report($status,$counts,$phase=null)
    {
        $safe=[
            'source_binding','native_lease','g3_scan',
            'candidate_adapter','ready_promotion','ready_scan','cas_tick'
        ];
        $result=[
            'ok'=>$counts!==null,
            'status'=>$status,'counts'=>$counts,
            'marketing_sends_authorized'=>false,
            'records_delivered_certified'=>false,
            'automatic_retry_authorized'=>false,
            'record_identifiers_exposed'=>false,
            'credentials_exposed'=>false
        ];
        if ($phase!==null && in_array($phase,$safe,true)) {
            $result['failure_stage']=$phase;
        }
        return $result;
    }

    private function __construct(){}
}
