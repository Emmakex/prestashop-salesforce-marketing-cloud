<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud;

use RuntimeException;

/**
 * G7 handoff boundary: one current G3 outbox+ledger event -> native PST v0.4
 * shapes -> real Ingestion API request plan; ALL done in memory.
 *
 * No DB connection, HTTP, token, queue, scheduler, log, consent import or
 * response body. The PST JSON and planned HTTP body contain private source
 * data but are intentionally discarded before returning a redacted report.
 * A clean preview is NEVER permission to send any record.
 */
final class GrowthCapturedEventPstPreview
{
    public static function inspect(
        array $event, array $ledger, $namespace, $shopId,
        $customer, $order, $currency, $origin, $sourceName,
        $provider='marketing_cloud_growth', $mode='test'
    ) {
        if ($provider!=='marketing_cloud_growth' || $mode!=='test') {
            throw new RuntimeException('g7_pst_preview_test_growth_only');
        }
        if (!is_string($namespace)
            || !preg_match('/^[a-z][a-z0-9_]{5,31}$/D',$namespace)
            || !is_int($shopId) || $shopId<1) {
            throw new RuntimeException('g7_pst_preview_scope_invalid');
        }

        // These two arrays come from the existing G3 tables. Never trust a
        // raw event without its latest ledger sequence; cancelled, stale,
        // unknown and foreign-shop events cannot become export candidates.
        $fields=[
            'source_namespace','shop_id','entity_type','entity_id','status'
        ];
        foreach ($fields as $field) {
            if (!array_key_exists($field,$event)
                || !array_key_exists($field,$ledger)
                || $event[$field]!==$ledger[$field]) {
                throw new RuntimeException('g7_pst_preview_outbox_ledger_mismatch');
            }
        }
        $seq=self::positiveInt(isset($event['accepted_sequence'])
            ?$event['accepted_sequence']:null);
        $latest=self::positiveInt(isset($ledger['latest_accepted_sequence'])
            ?$ledger['latest_accepted_sequence']:null);
        $id=self::positiveInt($event['entity_id']);
        $shop=self::positiveInt($event['shop_id']);
        $type=$event['entity_type'];
        if ($seq===null || $latest!==$seq || $id===null
            || $shop!==$shopId || $event['source_namespace']!==$namespace
            || $event['status']!=='blocked'
            || !in_array($type,['customer','order'],true)) {
            throw new RuntimeException('g7_pst_preview_not_current_blocked_source');
        }
        $eventType=isset($event['original_event_type'])
            ?$event['original_event_type']:null;
        if (($type==='customer'
                && !in_array($eventType,
                    ['CUSTOMER_CREATED','CUSTOMER_UPDATED'],true))
            || ($type==='order' && $eventType!=='ORDER_CREATED')) {
            throw new RuntimeException('g7_pst_preview_event_not_supported');
        }
        if (!is_object($customer)
            || self::positiveInt(isset($customer->id)?$customer->id:null)
                !==($type==='customer'?$id:
                    self::positiveInt(isset($order->id_customer)
                        ?$order->id_customer:null))
            || self::positiveInt(isset($customer->id_shop)
                ?$customer->id_shop:null)!==$shopId) {
            throw new RuntimeException('g7_pst_preview_source_identity_invalid');
        }

        if ($type==='customer') {
            if ($order!==null || $currency!==null) {
                throw new RuntimeException('g7_pst_preview_unexpected_order');
            }
            $draft=GrowthNativeCustomerOrderDraft::buildCustomer(
                $namespace,$shopId,$customer,$provider,$mode
            );
            $records=self::records($draft['customer_json']);
            $plan=GrowthStreamingIngestionPlan::prepare(
                $origin,$sourceName,'PSCustomer',$records,
                $namespace,$shopId,$provider,$mode
            );
            $count=$plan['count'];
            unset($draft,$records,$plan);
            return self::report('customer',$count,1);
        }

        if (!is_object($order) || !is_object($currency)
            || self::positiveInt(isset($order->id)?$order->id:null)!==$id
            || self::positiveInt(isset($order->id_shop)
                ?$order->id_shop:null)!==$shopId) {
            throw new RuntimeException('g7_pst_preview_order_identity_invalid');
        }
        $draft=GrowthNativeCustomerOrderDraft::buildPair(
            $namespace,$shopId,$customer,$order,$currency,$provider,$mode
        );
        $customerRecords=self::records($draft['customer_json']);
        $orderRecords=self::records($draft['order_json']);
        $pc=GrowthStreamingIngestionPlan::prepare(
            $origin,$sourceName,'PSCustomer',$customerRecords,
            $namespace,$shopId,$provider,$mode
        );
        $po=GrowthStreamingIngestionPlan::prepare(
            $origin,$sourceName,'PSOrder',$orderRecords,
            $namespace,$shopId,$provider,$mode
        );
        $count=$pc['count']+$po['count'];
        unset($draft,$customerRecords,$orderRecords,$pc,$po);
        return self::report('order',$count,2);
    }

    private static function records($json)
    {
        $decoded=json_decode($json,true);
        if (!is_array($decoded) || !isset($decoded['data'])
            || !is_array($decoded['data'])) {
            throw new RuntimeException('g7_pst_preview_invalid_draft');
        }
        return $decoded['data'];
    }

    private static function positiveInt($value)
    {
        if (is_int($value)) {
            return $value>0?$value:null;
        }
        if (is_string($value)
            && preg_match('/^[1-9][0-9]*$/D',$value)) {
            $i=(int)$value;
            return $i>0 && (string)$i===$value?$i:null;
        }
        return null;
    }

    /** No customer/order IDs, PII, body, token or URL in this report. */
    private static function report($type,$count,$objects)
    {
        return [
            'status'=>'blocked_pst_shape_preview_only',
            'event_source_type'=>$type,
            'objects_prepared_in_memory'=>$objects,
            'records_prepared_in_memory'=>$count,
            'outbox_latest_ledger_verified'=>true,
            'typed_pst_v04_shape_verified'=>true,
            'source_freshness_certified'=>false,
            'source_timestamp_utc_certified'=>false,
            'schema_ingestion_approved'=>false,
            'consent_growth_certified'=>false,
            'data_cloud_ingestion_authorized'=>false,
            'outbound_worker_authorized'=>false,
            'automatic_retry_authorized'=>false,
            'marketing_sends_authorized'=>false,
            'http_request_performed'=>false,
        ];
    }

    private function __construct() {}
}
