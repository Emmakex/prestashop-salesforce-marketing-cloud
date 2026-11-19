<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use RuntimeException;

/**
 * G7 read-only keyset selection on the SAME existing G3 outbox and ledger.
 *
 * Internal candidate references must never be logged or rendered in BO.
 * No HTTP, PII select, SQL writes, new queue, ready transition or approval.
 * A result is a review candidate, NOT permission for ingestion or marketing.
 */
final class GrowthG7CurrentEventBatch
{
    const MAX_BATCH = 50;

    public static function scan(
        $db, $version, $merchant, $namespace, $prefix,
        $shopId, $afterSequence, $limit
    ) {
        if (!is_object($db) || !is_callable([$db,'executeS'])
            || !is_int($shopId) || $shopId<1
            || !is_int($afterSequence) || $afterSequence<0
            || !is_int($limit) || $limit<1 || $limit>self::MAX_BATCH) {
            throw new RuntimeException('g7_batch_input_invalid');
        }
        // Same Test/staging/extended-schema guard as the native lease.
        // A CI-only ready writer does NOT exist in this selector.
        new GrowthPrestaShopDeliveryLease(
            $db,$version,$merchant,$namespace,$prefix
        );
        $plan=GrowthOutboxSchemaPlan::preview(
            $version,$merchant,'marketing_cloud_growth',$namespace,
            'test',$prefix
        );
        $outbox=self::q($plan['tables']['outbox']);
        $ledger=self::q($plan['tables']['ledger']);
        $limitSql=$limit+1;
        $sql='SELECT o.accepted_sequence,o.entity_type,o.entity_id,'
            . 'o.original_event_type FROM '.$outbox.' o'
            . ' INNER JOIN '.$ledger.' l'
            . ' ON l.source_namespace=o.source_namespace'
            . ' AND l.shop_id=o.shop_id'
            . ' AND l.entity_type=o.entity_type'
            . ' AND l.entity_id=o.entity_id'
            . " WHERE o.source_namespace='$namespace'"
            . " AND o.shop_id=$shopId"
            . " AND o.accepted_sequence>$afterSequence"
            . " AND o.status='blocked' AND l.status='blocked'"
            . " AND o.delivery_state='blocked'"
            . ' AND o.delivery_attempts=0'
            . ' AND o.lease_token_hash IS NULL'
            . ' AND o.lease_until_epoch IS NULL'
            . ' AND o.delivery_last_http IS NULL'
            . ' AND l.latest_accepted_sequence=o.accepted_sequence'
            . " AND ((o.entity_type='customer' AND"
            . " o.original_event_type IN ('CUSTOMER_CREATED','CUSTOMER_UPDATED'))"
            . " OR (o.entity_type='order' AND"
            . " o.original_event_type='ORDER_CREATED'))"
            . " ORDER BY o.accepted_sequence ASC LIMIT $limitSql";
        $rows=$db->executeS($sql,true,false);
        if (!is_array($rows) || count($rows)>$limitSql) {
            throw new RuntimeException('g7_batch_source_unverified');
        }
        $hasMore=count($rows)>$limit;
        if ($hasMore)array_pop($rows);
        $next=$afterSequence;
        $candidates=[];
        foreach ($rows as $row) {
            if (!is_array($row) || count($row)!==4
                || !isset($row['accepted_sequence'],$row['entity_type'],
                    $row['entity_id'],$row['original_event_type'])
                || !in_array($row['entity_type'],['customer','order'],true)) {
                throw new RuntimeException('g7_batch_row_shape_invalid');
            }
            $sequence=self::id($row['accepted_sequence']);
            $id=self::id($row['entity_id']);
            $type=$row['entity_type'];
            $event=$row['original_event_type'];
            if ($sequence===null || $id===null || $sequence<=$next
                || !in_array($event,$type==='customer'
                    ?['CUSTOMER_CREATED','CUSTOMER_UPDATED']
                    :['ORDER_CREATED'],true)) {
                throw new RuntimeException('g7_batch_row_scope_invalid');
            }
            $next=$sequence;
            $candidates[]=[
                'shop_id'=>$shopId,'entity_type'=>$type,
                'entity_id'=>$id,'accepted_sequence'=>$sequence
            ];
        }
        return [
            // Only for a future authorized internal reader; DO NOT log.
            'candidate_refs_internal'=>$candidates,
            'next_cursor'=>$next,'has_more'=>$hasMore,
            'status'=>'bounded_review_candidates_only',
            'can_set_ready'=>false,
            'can_claim'=>false,
            'http_request_performed'=>false,
            'data_cloud_ingestion_authorized'=>false,
            'marketing_sends_authorized'=>false
        ];
    }

    /** Safe to display; deliberately excludes cursor and entity IDs. */
    public static function summarize(array $batch)
    {
        if (!isset($batch['candidate_refs_internal'],$batch['status'],
            $batch['has_more'])
            || !is_array($batch['candidate_refs_internal'])
            || $batch['status']!=='bounded_review_candidates_only'
            || !is_bool($batch['has_more'])) {
            throw new RuntimeException('g7_batch_summary_unverified');
        }
        return [
            'status'=>'review_only_no_sends',
            'candidate_count'=>count($batch['candidate_refs_internal']),
            'more_pages_present'=>$batch['has_more'],
            'identifiers_exposed'=>false,
            'data_cloud_ingestion_authorized'=>false,
            'marketing_sends_authorized'=>false
        ];
    }

    private static function id($v)
    {
        if (is_int($v))return $v>0?$v:null;
        if (is_string($v) && preg_match('/^[1-9][0-9]*$/D',$v)) {
            $n=(int)$v;
            return $n>0 && (string)$n===$v?$n:null;
        }
        return null;
    }

    private static function q($name)
    {
        if (!is_string($name)
            || !preg_match('/^[A-Za-z0-9_]{1,64}$/D',$name)) {
            throw new RuntimeException('g7_batch_identifier_invalid');
        }
        return chr(96).$name.chr(96);
    }
    private function __construct() {}
}
