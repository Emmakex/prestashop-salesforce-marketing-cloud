<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use RuntimeException;
use Throwable;

/**
 * G7/G8 automatic ingestion coordinator -- bounded, one-attempt semantics.
 *
 * NOT a campaign sender, not cron-connected, not a permission grant.
 * Existing G3 outbox/ledger adapters must supply an atomic CAS reserve, a
 * scoped Data Cloud DLO absence check (to avoid replaying BC manual smokes), and
 * durable reconcile/hold receipts before any production invocation.
 *
 * A 202 is "reconcile", never "delivered". No retry of an uncertain outcome.
 * Customer DLO verification must precede PSOrder. No PII is returned.
 */
final class GrowthAutomaticOneShotCoordinator
{
    const OWNER_GATE='OWNER_APPROVED_BOUNDED_AUTOMATIC_DATA_CLOUD_INGESTION';
    const MAX_TICK=10;

    public static function tick(
        array $scope, array $candidates,
        $loadFresh, $locallyEligible, $recordDloStatus,
        $customerDloVerified,
        $reserveOnce, $postOnce, $persistOutcome
    ) {
        $shop=isset($scope['shop'])?$scope['shop']:null;
        $merchant=isset($scope['merchant'])?$scope['merchant']:null;
        $namespace=isset($scope['namespace'])?$scope['namespace']:null;
        if (!is_int($shop) || $shop<1
            || !GrowthShopSourceBinding::isMerchant($merchant)
            || !GrowthShopSourceBinding::isNamespace($namespace)
            || !isset($scope['mode'],$scope['capture'],$scope['owner_gate'])
            || $scope['mode']!=='test' || $scope['capture']!=='off'
            || $scope['owner_gate']!==self::OWNER_GATE
            || (string)\Configuration::get('PS_GROWTH_MERCHANT')!==$merchant
            || (string)\Configuration::get('PS_GROWTH_NAMESPACE')!==$namespace
            || (string)\Configuration::get('PS_SFMC_MODE')!=='test'
            || (string)\Configuration::get('PS_GROWTH_LOCAL_CAPTURE')!=='off'
            || count($candidates)>self::MAX_TICK
            || !is_callable($loadFresh) || !is_callable($locallyEligible)
            || !is_callable($recordDloStatus)
            || !is_callable($customerDloVerified)
            || !is_callable($reserveOnce) || !is_callable($postOnce)
            || !is_callable($persistOutcome)) {
            throw new RuntimeException('g8_auto_tick_not_authorized');
        }
        // Fail closed before processing anything when source identity drifted.
        $source=GrowthShopSourceBinding::sourceForSend(
            $shop,$merchant,$namespace
        );

        $summary=[
            'status'=>'bounded_tick_completed_unconfirmed',
            'examined'=>0,'reserved'=>0,'accepted_202_unconfirmed'=>0,
            'pending_reconciliation'=>0,'held'=>0,'skipped'=>0,
            'records_verified_in_dlo'=>0,
            'marketing_sends_authorized'=>false,
            'automatic_retry_authorized'=>false,
        ];
        $seen=[];
        foreach ($candidates as $candidate) {
            $summary['examined']++;
            $reserved=false;
            $state='reconcile';
            $httpStatus=null;
            $transportStarted=false;
            try {
                self::candidate($candidate,$shop);
                $key=$candidate['type'].':'.$candidate['id'].':'
                    .$candidate['sequence'];
                if (isset($seen[$key])) {
                    $summary['skipped']++;
                    continue;
                }
                $seen[$key]=true;
                $first=call_user_func($loadFresh,$candidate);
                self::snapshot($first,$candidate,$namespace,$shop);
                if (call_user_func($locallyEligible,$first)!==true
                    || call_user_func($recordDloStatus,$candidate,$first)
                        !=='absent_confirmed'
                    || !self::parentVerified(
                        $candidate,$first,$shop,$namespace,
                        $customerDloVerified
                    )) {
                    $summary['skipped']++;
                    continue;
                }
                // Caller must atomically persist a claim against the SAME
                // current G3 entity ledger. A previous or ambiguous claim
                // means NO POST, including after a timeout.
                $receipt=call_user_func(
                    $reserveOnce,$candidate,$first['fingerprint']
                );
                if ($receipt!=='reserved_once') {
                    $summary['skipped']++;
                    continue;
                }
                $reserved=true;
                $summary['reserved']++;
                // Final fresh source and local exclusion pass AFTER reserve.
                // Never reuse stale payload from before obtaining the lease.
                $fresh=call_user_func($loadFresh,$candidate);
                self::snapshot($fresh,$candidate,$namespace,$shop);
                if (!hash_equals(
                        $first['fingerprint'],$fresh['fingerprint']
                    )
                    || (string)\Configuration::get('PS_GROWTH_MERCHANT')
                        !==$merchant
                    || (string)\Configuration::get('PS_GROWTH_NAMESPACE')
                        !==$namespace
                    || (string)\Configuration::get('PS_SFMC_MODE')!=='test'
                    || (string)\Configuration::get('PS_GROWTH_LOCAL_CAPTURE')
                        !=='off'
                    || GrowthShopSourceBinding::sourceForSend(
                        $shop,$merchant,$namespace
                    )!==$source
                    || call_user_func($locallyEligible,$fresh)!==true
                    || call_user_func($recordDloStatus,$candidate,$fresh)
                        !=='absent_confirmed'
                    || !self::parentVerified(
                        $candidate,$fresh,$shop,$namespace,
                        $customerDloVerified
                    )) {
                    $state='held';
                } else {
                    // The transport adapter must itself recheck the lease
                    // immediately before posting. This class cannot make
                    // a network request without the injected callback.
                    // This flag is set immediately BEFORE calling any
                    // transport: exceptions/timeouts are uncertain, never
                    // treated as a known local-only HOLD.
                    $transportStarted=true;
                    $result=call_user_func(
                        $postOnce,$source,$candidate['type'],
                        $fresh['payload_private'],$candidate
                    );
                    $httpStatus=is_array($result)
                        && isset($result['http_status'])
                        && is_int($result['http_status'])
                        && $result['http_status']>=100
                        && $result['http_status']<=599
                        ?$result['http_status']:null;
                    if ($httpStatus===202) {
                        $state='reconcile';
                        if (isset($result['outcome'])
                            && $result['outcome']
                                ==='accepted_asynchronously_unconfirmed') {
                            $summary['accepted_202_unconfirmed']++;
                        }
                    } elseif ($httpStatus!==null) {
                        // A known non-202 response needs a durable held
                        // receipt with its exact HTTP status.
                        $state='held';
                    } else {
                        // Missing/invalid transport response is UNKNOWN.
                        $state='reconcile';
                    }
                }
            } catch (Throwable $error) {
                // After reservation, a thrown transport/HTTP result is
                // unknown, never a reason to retry automatically.
                $state='reconcile';
                if (!$reserved) {
                    $summary['skipped']++;
                }
            } finally {
                if ($reserved) {
                    try {
                        $saved=call_user_func(
                            $persistOutcome,$candidate,$state,
                            $httpStatus,$transportStarted
                        );
                        if ($saved!==true) {
                            $summary['status']='reconciliation_required';
                        }
                    } catch (Throwable $ignored) {
                        $summary['status']='reconciliation_required';
                    }
                    if ($state==='reconcile') {
                        $summary['pending_reconciliation']++;
                    } else {
                        $summary['held']++;
                    }
                }
            }
        }
        // Note: accepted 202 is counted as pending reconciliation, never
        // summary NEVER presents accepted HTTP as DLO-verified delivery.
        return $summary;
    }

    private static function candidate($c,$shop)
    {
        if (!is_array($c) || count($c)!==4
            || !isset($c['shop'],$c['type'],$c['id'],$c['sequence'])
            || $c['shop']!==$shop
            || !in_array($c['type'],['PSCustomer','PSOrder'],true)
            || !is_int($c['id']) || $c['id']<1
            || !is_int($c['sequence']) || $c['sequence']<1) {
            throw new RuntimeException('g8_auto_candidate_invalid');
        }
    }

    private static function snapshot($s,$c,$namespace,$shop)
    {
        if (!is_array($s)
            || !isset($s['shop'],$s['type'],$s['id'],$s['sequence'],
                $s['fingerprint'],$s['payload_private'])
            || $s['shop']!==$shop || $s['type']!==$c['type']
            || $s['id']!==$c['id'] || $s['sequence']!==$c['sequence']
            || !is_string($s['fingerprint'])
            || preg_match('/^[a-f0-9]{64}$/D',$s['fingerprint'])!==1
            || !is_array($s['payload_private'])) {
            throw new RuntimeException('g8_auto_snapshot_invalid');
        }
        $p=$s['payload_private'];
        $expected=$namespace.':'.$shop.':'
            .($c['type']==='PSCustomer'?'customer':'order')
            .':'.$c['id'];
        if (!isset($p['source_key'],$p['source_namespace'],$p['shop_id'])
            || $p['source_key']!==$expected
            || $p['source_namespace']!==$namespace
            || $p['shop_id']!==$shop) {
            throw new RuntimeException('g8_auto_payload_scope_invalid');
        }
        if ($c['type']==='PSOrder') {
            if (!isset($s['customer_id'])
                || !is_int($s['customer_id'])
                || $s['customer_id']<1
                || !isset($p['customer_source_key'])
                || $p['customer_source_key']!==$namespace.':'.$shop
                    .':customer:'.$s['customer_id']) {
                throw new RuntimeException('g8_auto_order_parent_invalid');
            }
        } elseif (!isset($p['customer_id'])
            || $p['customer_id']!==$c['id']) {
            throw new RuntimeException('g8_auto_customer_invalid');
        }
    }

    private static function parentVerified(
        $candidate,$fresh,$shop,$namespace,$verify
    ) {
        if ($candidate['type']==='PSCustomer')return true;
        return call_user_func(
            $verify,$shop,$namespace,$fresh['customer_id']
        )===true;
    }
    private function __construct(){}
}
