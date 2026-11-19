<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use RuntimeException;
use Throwable;

/**
 * G7 read-only join of the EXISTING native/ledger preflight and G6
 * Communication Subscription Consent (CSC) evidence. NOT a sender.
 *
 * All success-shaped signals remain HOLD: a caller-injected CSC reader is
 * NOT a certified Salesforce connector, and a preflight cannot close the
 * race with an eventual HTTP POST. No ready state, lease claim, SQL write,
 * token, queue, campaign, email or outbound request is added.
 */
final class GrowthG7CscPreDispatchReview
{
    public static function inspectLatest(
        $db,$version,$merchant,$namespace,$prefix,$shop,$type,
        $loadCustomer,$loadOrder,$loadCurrency,$selectLocalRows,
        $subscriptionId,$asOfUtc,$readCsc,$origin,$sourceName
    ) {
        if (!is_int($shop) || $shop<1
            || !in_array($type,['customer','order'],true)
            || !is_callable($loadCustomer)
            || !is_callable($loadOrder)
            || !is_callable($loadCurrency)
            || !is_callable($selectLocalRows)
            || !is_callable($readCsc)) {
            throw new RuntimeException('g7_csc_presend_input_invalid');
        }
        try {
            $first=GrowthG7NativePreflight::inspectLatest(
                $db,$version,$merchant,$namespace,$prefix,$shop,$type,
                $loadCustomer,$loadOrder,$loadCurrency,$selectLocalRows,
                $origin,$sourceName
            );
            if (!self::stableLocal($first)) {
                return self::hold('local_or_source_hold','not_checked',false);
            }

            // A shop-local event's customer must be derived, never accepted
            // as a separately supplied, potentially unrelated customer ID.
            $before=self::current($db,$version,$merchant,$namespace,$prefix,
                $shop,$type,$loadCustomer,$loadOrder);
            $csc=GrowthCommunicationConsentHoldGate::inspect(
                $shop,$before['customer_id'],$namespace,$subscriptionId,
                $asOfUtc,$selectLocalRows,$prefix,$readCsc
            );

            // A recent CSC opt_in cannot override a local negative, and
            // neither a forged injected callback nor a 300s observation
            // proves a future send-time consent state.
            $second=GrowthG7NativePreflight::inspectLatest(
                $db,$version,$merchant,$namespace,$prefix,$shop,$type,
                $loadCustomer,$loadOrder,$loadCurrency,$selectLocalRows,
                $origin,$sourceName
            );
            if (!self::stableLocal($second)) {
                return self::hold('source_changed_or_suppressed_hold',
                    'unverified',false);
            }
            $after=self::current($db,$version,$merchant,$namespace,$prefix,
                $shop,$type,$loadCustomer,$loadOrder);
            if ($before['sequence']!==$after['sequence']
                || $before['entity_id']!==$after['entity_id']
                || $before['customer_id']!==$after['customer_id']
                || !hash_equals($before['native_digest'],$after['native_digest'])) {
                return self::hold('native_changed_during_consent_hold',
                    'unverified',false);
            }

            if ($csc['status']!=='review_candidate_only'
                || $csc['csc_source_state']
                    !=='opt_in_observed_for_requested_subscription'
                || $csc['marketing_sends_authorized']!==false
                || $csc['salesforce_send_time_consent_certified']!==false) {
                return self::hold('csc_denied_or_unverified_hold',
                    isset($csc['csc_source_state'])
                        && $csc['csc_source_state']==='opt_out'
                            ?'opt_out':'unverified',true);
            }

            return self::hold('csc_candidate_requires_sendtime_fence',
                'recent_opt_in_observed_not_certified',true);
        } catch (Throwable $e) {
            // Never surface raw SQL, PII, URLs, IDs, subscriptions, hashes,
            // token strings, exception text or external callback payloads.
            return self::hold('source_or_csc_unverified_hold','unverified',false);
        }
    }

    private static function stableLocal($report)
    {
        return is_array($report)
            && isset($report['status'],$report['reason'])
            && $report['status']==='hold_for_review'
            && $report['reason']==='local_consent_unverified_hold'
            && isset($report['native_source_double_read_stable'])
            && $report['native_source_double_read_stable']===true
            && $report['local_customer_source_state']
                ==='no_local_negative_observed'
            && $report['marketing_sends_authorized']===false
            && $report['data_cloud_ingestion_authorized']===false;
    }

    /**
     * One read-only scoped current event and native customer/order snapshot.
     * Only its keyed, non-reversible digest survives in PHP memory.
     */
    private static function current(
        $db,$version,$merchant,$namespace,$prefix,$shop,$type,
        $loadCustomer,$loadOrder
    ) {
        $plan=GrowthOutboxSchemaPlan::preview(
            $version,$merchant,'marketing_cloud_growth',$namespace,
            'test',$prefix
        );
        $table=self::q($plan['tables']['outbox']);
        $rows=$db->executeS(
            'SELECT accepted_sequence,source_namespace,shop_id,entity_type,'
            . 'entity_id,original_event_type,status,delivery_state FROM '.$table
            . " WHERE source_namespace='$namespace' AND shop_id=$shop"
            . " AND entity_type='$type' AND status='blocked'"
            . ' ORDER BY accepted_sequence DESC LIMIT 1',
            true,false
        );
        if (!is_array($rows) || count($rows)!==1) {
            throw new RuntimeException('g7_csc_event_unverified');
        }
        $row=$rows[0];
        $id=self::id($row['entity_id']);
        $sequence=self::id($row['accepted_sequence']);
        if ($id===null || $sequence===null
            || $row['delivery_state']!=='blocked'
            || $row['source_namespace']!==$namespace
            || self::id($row['shop_id'])!==$shop
            || $row['entity_type']!==$type
            || !in_array($row['original_event_type'],$type==='customer'
                ?['CUSTOMER_CREATED','CUSTOMER_UPDATED']:['ORDER_CREATED'],
                true)) {
            throw new RuntimeException('g7_csc_event_scope_unverified');
        }

        $order=null;
        if ($type==='order') {
            $order=call_user_func($loadOrder,$id);
            if (!is_object($order)
                || self::id(isset($order->id)?$order->id:null)!==$id
                || self::id(isset($order->id_shop)?$order->id_shop:null)!==$shop) {
                throw new RuntimeException('g7_csc_order_scope_unverified');
            }
            $customerId=self::id(
                isset($order->id_customer)?$order->id_customer:null
            );
        } else {
            $customerId=$id;
        }
        if ($customerId===null) {
            throw new RuntimeException('g7_csc_customer_relation_invalid');
        }
        $customer=call_user_func($loadCustomer,$customerId);
        if (!is_object($customer)
            || self::id(isset($customer->id)?$customer->id:null)!==$customerId
            || self::id(isset($customer->id_shop)?$customer->id_shop:null)!==$shop) {
            throw new RuntimeException('g7_csc_customer_scope_unverified');
        }
        $digest=hash('sha256',serialize([
            'row'=>$row,'customer'=>get_object_vars($customer),
            'order'=>$order===null?null:get_object_vars($order)
        ]));
        unset($customer,$order,$row);
        return [
            'entity_id'=>$id,
            'customer_id'=>$customerId,
            'sequence'=>$sequence,
            'native_digest'=>$digest
        ];
    }

    private static function id($value)
    {
        if (is_int($value))return $value>0?$value:null;
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D',$value)) {
            $n=(int)$value;
            return $n>0 && (string)$n===$value?$n:null;
        }
        return null;
    }

    private static function q($table)
    {
        if (!is_string($table)
            || !preg_match('/^[A-Za-z0-9_]{1,64}$/D',$table)) {
            throw new RuntimeException('g7_csc_table_invalid');
        }
        return chr(96).$table.chr(96);
    }

    private static function hold($reason,$cscState,$rechecked)
    {
        return [
            'status'=>'hold_for_review',
            'reason'=>$reason,
            'csc_source_state'=>$cscState,
            'source_and_consent_rechecked'=>$rechecked,
            'csc_reader_certified_for_merchant'=>false,
            'send_time_consent_certified'=>false,
            'final_dispatch_fence_certified'=>false,
            'customer_contact_authorized'=>false,
            'data_cloud_ingestion_authorized'=>false,
            'outbound_worker_authorized'=>false,
            'automatic_retry_authorized'=>false,
            'marketing_sends_authorized'=>false,
            'http_request_performed'=>false,
            'new_queue_created'=>false,
        ];
    }

    private function __construct() {}
}
