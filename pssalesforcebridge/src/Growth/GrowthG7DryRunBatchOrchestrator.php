<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use RuntimeException;
use Throwable;

/**
 * One bounded, read-only G7 batch review across current G3 entities.
 *
 * Uses the SAME existing G3 outbox/ledger, native source/CSC readers, and
 * no additional persistence. Never sets READY, claims, sends, retries,
 * logs PII, uploads PST or certifies permission to contact a customer.
 *
 * Result next_cursor_internal is a PRIVATE processing reference; callers
 * must display ONLY summarize(). This is not wired to cron or BO.
 */
final class GrowthG7DryRunBatchOrchestrator
{
    const MAX_PAGES = 5;

    public static function reviewWindow(
        $db,$version,$merchant,$namespace,$prefix,$shop,
        $afterSequence,$pageSize,$maxPages,
        $loadCustomer,$loadOrder,$loadCurrency,$selectLocalRows,
        $subscriptionId,$asOfUtc,$readCsc,$origin,$sourceName
    ) {
        if (!is_int($shop) || $shop<1
            || !is_int($afterSequence) || $afterSequence<0
            || !is_int($pageSize) || $pageSize<1
            || $pageSize>GrowthG7CurrentEventBatch::MAX_BATCH
            || !is_int($maxPages) || $maxPages<1
            || $maxPages>self::MAX_PAGES
            || !is_callable($loadCustomer) || !is_callable($loadOrder)
            || !is_callable($loadCurrency) || !is_callable($selectLocalRows)
            || !is_callable($readCsc)) {
            throw new RuntimeException('g7_dry_run_window_invalid');
        }

        $counts=self::emptyCounts();
        $reviewed=0;
        $cursor=$afterSequence;
        $more=false;
        try {
            for ($page=0;$page<$maxPages;$page++) {
                $batch=GrowthG7CurrentEventBatch::scan(
                    $db,$version,$merchant,$namespace,$prefix,
                    $shop,$cursor,$pageSize
                );
                if (!isset($batch['status'],$batch['candidate_refs_internal'],
                    $batch['has_more'],$batch['next_cursor'])
                    || $batch['status']!=='bounded_review_candidates_only'
                    || !is_array($batch['candidate_refs_internal'])
                    || count($batch['candidate_refs_internal'])>$pageSize
                    || !is_bool($batch['has_more'])
                    || !is_int($batch['next_cursor'])
                    || $batch['next_cursor']<$cursor
                    || $batch['data_cloud_ingestion_authorized']!==false
                    || $batch['marketing_sends_authorized']!==false) {
                    throw new RuntimeException('g7_dry_run_page_unverified');
                }
                $refs=$batch['candidate_refs_internal'];
                $previous=$cursor;
                foreach ($refs as $ref) {
                    if (!is_array($ref) || count($ref)!==4
                        || !isset($ref['shop_id'],$ref['entity_type'],
                            $ref['entity_id'],$ref['accepted_sequence'])
                        || $ref['shop_id']!==$shop
                        || !in_array($ref['entity_type'],['customer','order'],true)
                        || !is_int($ref['entity_id']) || $ref['entity_id']<1
                        || !is_int($ref['accepted_sequence'])
                        || $ref['accepted_sequence']<=$previous) {
                        throw new RuntimeException('g7_dry_run_ref_unverified');
                    }
                    $previous=$ref['accepted_sequence'];
                    $report=GrowthG7CscPreDispatchReview::inspectSelected(
                        $db,$version,$merchant,$namespace,$prefix,
                        $shop,$ref['entity_type'],$ref['entity_id'],
                        $ref['accepted_sequence'],
                        $loadCustomer,$loadOrder,$loadCurrency,$selectLocalRows,
                        $subscriptionId,$asOfUtc,$readCsc,$origin,$sourceName
                    );
                    if (!is_array($report)
                        || !isset($report['status'],$report['reason'])
                        || $report['status']!=='hold_for_review'
                        || $report['marketing_sends_authorized']!==false
                        || $report['data_cloud_ingestion_authorized']!==false
                        || $report['http_request_performed']!==false
                        || $report['final_dispatch_fence_certified']!==false) {
                        throw new RuntimeException('g7_dry_run_review_unsafe');
                    }
                    // The only exposed reason categories are fixed constants.
                    // Unknown reasons are counted as HOLD, never as success.
                    $reason=$report['reason'];
                    if ($reason==='csc_candidate_requires_sendtime_fence') {
                        $counts['csc_candidate_not_authorized']++;
                    } elseif (in_array($reason,[
                        'local_or_source_hold','source_changed_or_suppressed_hold',
                        'native_changed_during_consent_hold'
                    ],true)) {
                        $counts['local_or_source_hold']++;
                    } elseif ($reason==='csc_denied_or_unverified_hold') {
                        $counts['csc_denied_or_unverified_hold']++;
                    } else {
                        $counts['other_unverified_hold']++;
                    }
                    $reviewed++;
                }

                $cursor=$batch['next_cursor'];
                $more=$batch['has_more'];
                if (($refs===[] && $more)
                    || ($refs!==[] && $cursor!==$previous)
                    || ($refs===[] && $cursor!==$previous)) {
                    throw new RuntimeException('g7_dry_run_cursor_drift');
                }
                if (!$more)break;
            }

            return [
                'status'=>'batch_review_only_no_sends',
                'reviewed_count'=>$reviewed,
                'reason_counts'=>$counts,
                'more_pages_present'=>$more,
                // Internal resume pointer: NEVER log this array directly.
                'next_cursor_internal'=>$cursor,
                'identifiers_exposed_in_summary'=>false,
                'final_dispatch_fence_certified'=>false,
                'send_time_consent_certified'=>false,
                'data_cloud_ingestion_authorized'=>false,
                'marketing_sends_authorized'=>false,
                'http_request_performed'=>false,
                'new_queue_created'=>false
            ];
        } catch (Throwable $error) {
            // Partially evaluated batches are NOT treated as completed.
            // Never return raw errors, source keys or cursor on failure.
            return [
                'status'=>'batch_review_failed_closed',
                'reviewed_count'=>null,
                'reason_counts'=>null,
                'more_pages_present'=>null,
                'next_cursor_internal'=>null,
                'identifiers_exposed_in_summary'=>false,
                'final_dispatch_fence_certified'=>false,
                'send_time_consent_certified'=>false,
                'data_cloud_ingestion_authorized'=>false,
                'marketing_sends_authorized'=>false,
                'http_request_performed'=>false,
                'new_queue_created'=>false
            ];
        }
    }

    /** Only this public projection may be used for dashboards/logging. */
    public static function summarize(array $result)
    {
        if (!isset($result['status'])
            || !in_array($result['status'],[
                'batch_review_only_no_sends','batch_review_failed_closed'
            ],true)) {
            throw new RuntimeException('g7_dry_run_summary_invalid');
        }
        if ($result['status']==='batch_review_failed_closed') {
            return [
                'status'=>'batch_review_failed_closed',
                'reviewed_count'=>null,
                'reason_counts'=>null,
                'more_pages_present'=>null,
                'delivery_authorized'=>false
            ];
        }
        if (!isset($result['reviewed_count'],$result['reason_counts'],
            $result['more_pages_present'])
            || !is_int($result['reviewed_count'])
            || $result['reviewed_count']<0
            || !is_bool($result['more_pages_present'])
            || !is_array($result['reason_counts'])
            || array_keys($result['reason_counts'])
                !==array_keys(self::emptyCounts())) {
            throw new RuntimeException('g7_dry_run_summary_shape_invalid');
        }
        $total=0;
        foreach ($result['reason_counts'] as $value) {
            if (!is_int($value) || $value<0) {
                throw new RuntimeException('g7_dry_run_summary_count_invalid');
            }
            $total+=$value;
        }
        if ($total!==$result['reviewed_count']) {
            throw new RuntimeException('g7_dry_run_summary_total_invalid');
        }
        return [
            'status'=>'batch_review_only_no_sends',
            'reviewed_count'=>$result['reviewed_count'],
            'reason_counts'=>$result['reason_counts'],
            'more_pages_present'=>$result['more_pages_present'],
            'delivery_authorized'=>false
        ];
    }

    private static function emptyCounts()
    {
        return [
            'csc_candidate_not_authorized'=>0,
            'local_or_source_hold'=>0,
            'csc_denied_or_unverified_hold'=>0,
            'other_unverified_hold'=>0
        ];
    }
    private function __construct() {}
}
