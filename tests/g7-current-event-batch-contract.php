<?php
/** G7 same-outbox all-current-entity selector, no egress/worker. */
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG7CurrentEventBatch.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG7CurrentEventBatch as Batch;

function g7BatchAssert($yes,$reason) {
    if (!$yes)throw new RuntimeException('g7_batch_contract_'.$reason);
}
$source=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG7CurrentEventBatch.php');
$preflight=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG7NativePreflight.php');
$module=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/pssalesforcebridge.php');
$cron=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/controllers/front/cron.php');
g7BatchAssert(Batch::MAX_BATCH===50
    && method_exists(Batch::class,'scan')
    && method_exists(Batch::class,'summarize')
    && strpos($module,'GrowthG7CurrentEventBatch')===false
    && strpos($cron,'GrowthG7CurrentEventBatch')===false
    && strpos($module,'inspectSelected(')===false
    && strpos($cron,'inspectSelected(')===false,
    'runtime_wiring_or_bound_invalid');
foreach([
    'new GrowthPrestaShopDeliveryLease',
    'INNER JOIN',
    'l.latest_accepted_sequence=o.accepted_sequence',
    "o.delivery_state='blocked'",
    'o.delivery_attempts=0',
    'o.lease_token_hash IS NULL',
    'o.lease_until_epoch IS NULL',
    'o.delivery_last_http IS NULL',
    'o.accepted_sequence>',
    'ORDER BY o.accepted_sequence ASC',
    'LIMIT $limitSql',
    'candidate_refs_internal',
    "'data_cloud_ingestion_authorized'=>false",
    "'marketing_sends_authorized'=>false"
] as $required) {
    g7BatchAssert(strpos($source,$required)!==false,
        'current_outbox_guard_missing');
}
g7BatchAssert(strpos($preflight,'public static function inspectSelected(')!==false
    && strpos($preflight,'$requestedSequence')!==false
    && strpos($preflight,'not_current_blocked_hold')!==false
    && strpos($preflight,'$lease->inspect(')!==false,
    'exact_preflight_not_available');
foreach (['curl_','file_get_contents(','file_put_contents(','->execute(',
    '->claim(','->cancel(','Mail::send(','->post(',
    "delivery_state='ready'",'Tools::redirect('] as $forbidden) {
    g7BatchAssert(strpos($source,$forbidden)===false,
        'forbidden_work_or_sender');
}
$bad=false;
try {
    Batch::scan(new stdClass(),'9.1.5','ABH','growth_g7_stage_ci',
        'ci_',1,0,51);
} catch (RuntimeException $e) {
    $bad=$e->getMessage()==='g7_batch_input_invalid';
}
g7BatchAssert($bad,'unbounded_limit_not_rejected');
$bad=false;
try {
    Batch::scan(new stdClass(),'9.1.5','ABH','growth_g7_stage_ci',
        'ci_',1,-1,2);
} catch (RuntimeException $e) {
    $bad=$e->getMessage()==='g7_batch_input_invalid';
}
g7BatchAssert($bad,'negative_cursor_not_rejected');
$summary=Batch::summarize([
    'status'=>'bounded_review_candidates_only',
    'has_more'=>true,
    'candidate_refs_internal'=>[
        ['shop_id'=>1,'entity_type'=>'customer','entity_id'=>999,
            'accepted_sequence'=>42]
    ]
]);
g7BatchAssert($summary['candidate_count']===1
    && $summary['more_pages_present']
    && !array_key_exists('candidate_refs_internal',$summary)
    && !array_key_exists('next_cursor',$summary)
    && strpos(json_encode($summary),'999')===false
    && !$summary['marketing_sends_authorized'],
    'privacy_summary_failed');
echo "G7 current event batch contract PASS: paged G3 latest ledger, no egress.\n";
