<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\GrowthCapturedEventPstPreview;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\GrowthStreamingIngestionPlan;
use RuntimeException;

/**
 * G7 execution-order rehearsal: current G3 outbox+ledger -> fresh native
 * source -> atomic lease in THE SAME G3 outbox -> second fresh read -> result.
 *
 * STRICTLY CI: the typed lease constructor rejects every DB except g3_ci,
 * and this class has no HTTP client, token, payload persistence or cron.
 * Source callback and HTTP status are injected synthetic fixtures.
 *
 * A valid source/lease NEVER grants Data Cloud or marketing-send permission.
 * No live dispatcher can use the original G3 blocked-only schema today.
 */
final class GrowthFreshSourceBlockedRehearsal
{
    public static function simulate(
        GrowthOutboxDeliveryLease $lease,
        $namespace,$shop,$type,$id,$sequence,$token,
        $origin,$sourceName,$readCurrent,
        $responseReceived,$httpStatus
    ) {
        if (!is_callable($readCurrent) || !is_bool($responseReceived)
            || ($responseReceived
                && (!is_int($httpStatus) || $httpStatus<100 || $httpStatus>599))
            || (!$responseReceived && $httpStatus!==null)) {
            throw new RuntimeException('g7_fresh_rehearsal_input_invalid');
        }

        // First read must be current, in-scope and safe BEFORE claiming.
        // No PII, URL or source fingerprint escapes this boundary.
        try {
            $first=call_user_func($readCurrent);
            $before=self::inspectSnapshot(
                $first,$namespace,$shop,$type,$id,$sequence,$origin,$sourceName
            );
        } catch (\Throwable $e) {
            return self::report('source_hold_before_claim',false,false,null);
        }

        // This atomic CAS is usable ONLY on disposable g3_ci and only when
        // fixture explicitly sets delivery_state=ready. A real shop still
        // stores status=blocked and has no delivery columns.
        if (!$lease->claim($namespace,$shop,$type,$id,$sequence,$token,60)) {
            return self::report('claim_denied',false,false,null);
        }

        // Deliberately re-fetch after claim. Detect source changes and new
        // ledger versions. A future sender additionally needs an atomic
        // fence immediately before remote I/O; this is NOT one.
        try {
            $second=call_user_func($readCurrent);
            $after=self::inspectSnapshot(
                $second,$namespace,$shop,$type,$id,$sequence,$origin,$sourceName
            );
            $same=hash_equals($before,$after);
        } catch (\Throwable $e) {
            $same=false;
        } finally {
            unset($first,$second,$before,$after);
        }
        if (!$same) {
            // Never release the row to READY if it changed or became
            // ineligible. Cancellation can't retract a hypothetical POST
            // already in flight; this rehearsal performs no POST.
            $cancelled=$lease->cancel(
                $namespace,$shop,$type,$id,$sequence
            );
            return self::report(
                $cancelled?'source_changed_cancelled':'source_changed_review',
                true,true,null
            );
        }

        $classification=GrowthStreamingIngestionPlan::classify(
            $httpStatus,$responseReceived
        );
        if (!$responseReceived) {
            $marked=$lease->markUnknownUnconfirmed(
                $namespace,$shop,$type,$id,$sequence,$token
            );
            $state='reconcile';
        } elseif ($httpStatus===202) {
            $marked=$lease->markAcceptedUnconfirmed(
                $namespace,$shop,$type,$id,$sequence,$token
            );
            $state='reconcile';
        } else {
            $marked=$lease->holdClaim(
                $namespace,$shop,$type,$id,$sequence,$token,$httpStatus
            );
            $state='held';
        }
        return self::report(
            $marked?$state:'lease_finalization_review',
            true,true,$classification['outcome_state']
        );
    }

    private static function inspectSnapshot(
        $snapshot,$namespace,$shop,$type,$id,$sequence,$origin,$sourceName
    ) {
        if (!is_array($snapshot)
            || !isset($snapshot['event'],$snapshot['ledger'],$snapshot['customer'])
            || !is_array($snapshot['event'])
            || !is_array($snapshot['ledger'])
            || !is_object($snapshot['customer'])
            || !array_key_exists('order',$snapshot)
            || !array_key_exists('currency',$snapshot)
            || !is_string($namespace)
            || !is_int($shop) || !is_int($id) || !is_int($sequence)
            || !in_array($type,['customer','order'],true)) {
            throw new RuntimeException('g7_fresh_source_invalid');
        }
        $event=$snapshot['event'];
        if (!isset($event['entity_type'],$event['entity_id'],
                $event['accepted_sequence'])
            || $event['entity_type']!==$type
            || self::positive($event['entity_id'])!==$id
            || self::positive($event['accepted_sequence'])!==$sequence) {
            throw new RuntimeException('g7_fresh_event_not_expected');
        }

        // Local negative flags can HOLD but not grant affirmative consent.
        $c=get_object_vars($snapshot['customer']);
        foreach (['active','deleted','is_guest','newsletter'] as $flag) {
            if (!isset($c[$flag])
                || !in_array($c[$flag],[0,1,'0','1',false,true],true)) {
                throw new RuntimeException('g7_fresh_customer_flag_invalid');
            }
        }
        if (!self::flag($c['active']) || self::flag($c['deleted'])
            || self::flag($c['is_guest']) || !self::flag($c['newsletter'])) {
            throw new RuntimeException('g7_fresh_local_suppression');
        }

        $preview=GrowthCapturedEventPstPreview::inspect(
            $event,$snapshot['ledger'],$namespace,$shop,
            $snapshot['customer'],$snapshot['order'],$snapshot['currency'],
            $origin,$sourceName
        );
        if ($preview['status']!=='blocked_pst_shape_preview_only'
            || $preview['outbound_worker_authorized']!==false
            || $preview['marketing_sends_authorized']!==false
            || $preview['http_request_performed']!==false) {
            throw new RuntimeException('g7_fresh_preview_invalid');
        }

        // Deterministic PII-bearing comparison exists ONLY inside memory.
        // Source can change again after this check. Do not log or return it.
        $source=[
            'event'=>$event,
            'ledger'=>$snapshot['ledger'],
            'customer'=>get_object_vars($snapshot['customer']),
            'order'=>is_object($snapshot['order'])
                ?get_object_vars($snapshot['order']):null,
            'currency'=>is_object($snapshot['currency'])
                ?get_object_vars($snapshot['currency']):null
        ];
        $fingerprint=hash('sha256',serialize($source));
        unset($source,$preview,$c);
        return $fingerprint;
    }

    private static function positive($value)
    {
        if (is_int($value))return $value>0?$value:null;
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D',$value)) {
            $x=(int)$value;
            return $x>0 && (string)$x===$value?$x:null;
        }
        return null;
    }

    private static function flag($x)
    {
        return in_array($x,[true,1,'1'],true);
    }

    /** No shop, IDs, personal data, token, payload, URL, or hash. */
    private static function report($state,$claimed,$rechecked,$outcome)
    {
        return [
            'status'=>'sandbox_fresh_source_simulation_only',
            'state'=>$state,
            'lease_claimed'=>$claimed,
            'fresh_source_checked_after_claim'=>$rechecked,
            'simulated_outcome_state'=>$outcome,
            'http_request_performed'=>false,
            'record_persisted_certified'=>false,
            'source_utc_provenance_certified'=>false,
            'source_race_until_remote_io_closed'=>false,
            'growth_consent_certified'=>false,
            'outbound_worker_authorized'=>false,
            'automatic_retry_authorized'=>false,
            'marketing_sends_authorized'=>false,
            'data_cloud_ingestion_authorized'=>false,
        ];
    }

    private function __construct() {}
}
