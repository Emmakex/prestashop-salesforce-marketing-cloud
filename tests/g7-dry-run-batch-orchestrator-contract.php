<?php
/** G7 unified G3 batch + exact CSC review: offline, no egress. */
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG7DryRunBatchOrchestrator.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG7DryRunBatchOrchestrator as DryRun;

function g7DryAssert($cond,$code) {
    if (!$cond)throw new RuntimeException('g7_dry_run_contract_'.$code);
}
$reflection=new ReflectionClass(
    'Emmakex\\PrestaShopSalesforceMarketingCloud\\Growth\\GrowthG7DryRunBatchOrchestrator'
);
g7DryAssert(DryRun::MAX_PAGES===5
    && $reflection->getMethod('reviewWindow')->isPublic()
    && $reflection->getMethod('summarize')->isPublic(),
    'public_method_boundary');
$source=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG7DryRunBatchOrchestrator.php');
$csc=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG7CscPreDispatchReview.php');
$module=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/pssalesforcebridge.php');
$cron=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/controllers/front/cron.php');
g7DryAssert(strpos($module,'GrowthG7DryRunBatchOrchestrator')===false
    && strpos($cron,'GrowthG7DryRunBatchOrchestrator')===false,
    'premature_live_wiring');
foreach ([
    'GrowthG7CurrentEventBatch::scan',
    'GrowthG7CscPreDispatchReview::inspectSelected',
    'batch_review_failed_closed',
    'candidate_refs_internal',
    'next_cursor_internal',
    'csc_candidate_not_authorized',
    "'data_cloud_ingestion_authorized'=>false",
    "'marketing_sends_authorized'=>false",
    "'http_request_performed'=>false"
] as $needle) {
    g7DryAssert(strpos($source,$needle)!==false,
        'missing_safety_gate');
}
g7DryAssert(strpos($csc,'public static function inspectSelected(')!==false
    && strpos($csc,'GrowthG7NativePreflight::inspectSelected')!==false
    && strpos($csc,'GrowthG7NativePreflight::inspectLatest')!==false
    && strpos($csc,'$requestedSequence')!==false,
    'exact_g3_csc_join_missing');
foreach ([
    'curl_', 'file_get_contents(', 'file_put_contents(',
    '->execute(', '->claim(', '->cancel(', '->post(',
    'Mail::send(', "delivery_state='ready'",
    'Db::getInstance(', 'Tools::redirect('
] as $forbidden) {
    g7DryAssert(strpos($source,$forbidden)===false,
        'outbound_or_write_in_dry_run');
}
foreach ([0,6] as $invalidPages) {
    $failed=false;
    try {
        DryRun::reviewWindow(new stdClass(),'9.1.5','ABH',
            'growth_g7_stage_ci','ci_',1,0,2,$invalidPages,
            function(){},function(){},function(){},function(){},
            'subscription','2026-11-19T10:00:00Z',function(){},
            'https://synthetic.c360a.salesforce.com','Synthetic');
    } catch (RuntimeException $e) {
        $failed=$e->getMessage()==='g7_dry_run_window_invalid';
    }
    g7DryAssert($failed,'unbounded_or_zero_pages_accepted');
}
$valid=[
    'status'=>'batch_review_only_no_sends',
    'reviewed_count'=>2,
    'reason_counts'=>[
        'csc_candidate_not_authorized'=>1,
        'local_or_source_hold'=>0,
        'csc_denied_or_unverified_hold'=>1,
        'other_unverified_hold'=>0
    ],
    'more_pages_present'=>true,
    'next_cursor_internal'=>987654321
];
$summary=DryRun::summarize($valid);
g7DryAssert($summary['reviewed_count']===2
    && $summary['more_pages_present']
    && !isset($summary['next_cursor_internal'])
    && strpos(json_encode($summary),'987654321')===false
    && !$summary['delivery_authorized'],
    'private_cursor_in_public_summary');
$invalid=$valid;
$invalid['reviewed_count']=3;
$denied=false;
try { DryRun::summarize($invalid); }
catch (RuntimeException $e) {
    $denied=$e->getMessage()==='g7_dry_run_summary_total_invalid';
}
g7DryAssert($denied,'unsafe_reason_total_accepted');
$error=DryRun::summarize(['status'=>'batch_review_failed_closed']);
g7DryAssert($error['reviewed_count']===null
    && $error['more_pages_present']===null
    && !$error['delivery_authorized'],
    'failure_leaked_partial_success');
echo "G7 unified dry-run orchestration PASS: exact G3 + CSC by page, no sender.\n";
