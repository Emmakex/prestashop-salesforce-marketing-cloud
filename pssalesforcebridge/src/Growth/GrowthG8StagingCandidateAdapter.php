<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use RuntimeException;

/**
 * Pure, private adapter from the EXISTING G3/G7 ledger scan to G8 candidates.
 *
 * The source scan is already restricted to the current blocked revision;
 * these checks defend against a malformed/bypassed scan before any future
 * owner-approved coordinator can see it. Never log or return the refs in
 * Back Office / cron JSON: only count them after this method returns.
 *
 * No SQL, no HTTP, no READY writer, no reserve, no campaign sender.
 */
final class GrowthG8StagingCandidateAdapter
{
    const MAX_TICK = 10;

    public static function adapt(array $batch, $shop, $afterSequence, $limit)
    {
        if (!is_int($shop) || $shop<1
            || !is_int($afterSequence) || $afterSequence<0
            || !is_int($limit) || $limit<1 || $limit>self::MAX_TICK
            || !isset($batch['status'],$batch['candidate_refs_internal'],
                $batch['next_cursor'],$batch['has_more'])
            || $batch['status']!=='bounded_review_candidates_only'
            || !is_array($batch['candidate_refs_internal'])
            || !is_int($batch['next_cursor'])
            || $batch['next_cursor']<$afterSequence
            || !is_bool($batch['has_more'])
            || count($batch['candidate_refs_internal'])>$limit
            || !array_key_exists('can_set_ready',$batch)
            || !array_key_exists('can_claim',$batch)
            || !array_key_exists('http_request_performed',$batch)
            || !array_key_exists('data_cloud_ingestion_authorized',$batch)
            || !array_key_exists('marketing_sends_authorized',$batch)
            || $batch['can_set_ready']!==false
            || $batch['can_claim']!==false
            || $batch['http_request_performed']!==false
            || $batch['data_cloud_ingestion_authorized']!==false
            || $batch['marketing_sends_authorized']!==false) {
            throw new RuntimeException('g8_stage_adapter_batch_unverified');
        }

        $after=$afterSequence;
        $seen=[];
        $candidates=[];
        foreach ($batch['candidate_refs_internal'] as $ref) {
            if (!is_array($ref) || count($ref)!==4
                || !isset($ref['shop_id'],$ref['entity_type'],
                    $ref['entity_id'],$ref['accepted_sequence'])
                || $ref['shop_id']!==$shop
                || !in_array($ref['entity_type'],['customer','order'],true)
                || !is_int($ref['entity_id']) || $ref['entity_id']<1
                || !is_int($ref['accepted_sequence'])
                || $ref['accepted_sequence']<=$after) {
                throw new RuntimeException('g8_stage_adapter_reference_unverified');
            }
            $key=$ref['entity_type'].':'.$ref['entity_id'];
            if (isset($seen[$key])) {
                throw new RuntimeException('g8_stage_adapter_duplicate_entity');
            }
            $seen[$key]=true;
            $after=$ref['accepted_sequence'];
            $candidates[]=[
                'shop'=>$shop,
                'type'=>$ref['entity_type']==='customer'?'PSCustomer':'PSOrder',
                'id'=>$ref['entity_id'],
                'sequence'=>$after,
            ];
        }
        if ($after!==$batch['next_cursor']
            || ($candidates===[] && $batch['has_more'])) {
            throw new RuntimeException('g8_stage_adapter_cursor_unverified');
        }
        // PRIVATE. This is a candidate list, NOT an authorization to
        // invoke the one-shot coordinator or change G3 delivery_state.
        return $candidates;
    }

    private function __construct() {}
}
