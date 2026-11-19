<?php
/**
 * G7 native source/ledger + G6 CSC join. An injected opt-in is never
 * authorization for Growth ingestion, contact or marketing sends.
 */
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG7CscPreDispatchReview.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG7CscPreDispatchReview as Review;

function g7CscAssert($ok,$why) {
    if (!$ok)throw new RuntimeException('g7_csc_join_contract_'.$why);
}
$reflect=new ReflectionClass(
    'Emmakex\\PrestaShopSalesforceMarketingCloud\\Growth\\GrowthG7CscPreDispatchReview'
);
g7CscAssert($reflect->hasMethod('inspectLatest')
    && $reflect->getMethod('inspectLatest')->isPublic()
    && $reflect->getMethod('current')->isPrivate()
    && $reflect->getMethod('stableLocal')->isPrivate(),
    'exposure_invalid');
$source=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG7CscPreDispatchReview.php');
$module=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/pssalesforcebridge.php');
$cron=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/controllers/front/cron.php');
g7CscAssert(strpos($module,'GrowthG7CscPreDispatchReview')===false
    && strpos($cron,'GrowthG7CscPreDispatchReview')===false,
    'unapproved_live_wiring');
foreach ([
    'GrowthG7NativePreflight::inspectLatest',
    'GrowthCommunicationConsentHoldGate::inspect',
    "executeS(",
    'hash_equals(',
    'native_changed_during_consent_hold',
    'csc_denied_or_unverified_hold',
    'csc_candidate_requires_sendtime_fence',
    "'marketing_sends_authorized'=>false",
    "'data_cloud_ingestion_authorized'=>false",
    "'http_request_performed'=>false",
    "'new_queue_created'=>false",
    "'final_dispatch_fence_certified'=>false"
] as $needle) {
    g7CscAssert(strpos($source,$needle)!==false,
        'missing_guard_contract');
}
foreach ([
    'curl_', 'file_get_contents(', '->execute(', '->claim(',
    '->cancel(', '->post(', 'Mail::send(', 'Db::getInstance(',
    'file_put_contents(', '->updateValue(', "delivery_state='ready'",
    'actionValidateOrder'
] as $forbidden) {
    g7CscAssert(strpos($source,$forbidden)===false,
        'unexpected_write_send_or_hook');
}
$invalid=false;
try {
    Review::inspectLatest(
        new stdClass(),'9.1.5','ABH','growth_g7_stage_ci','ci_',
        1,'cart',
        function(){return null;},function(){return null;},
        function(){return null;},function(){return [];},
        'marketing_email_test','2026-11-19T10:00:00Z',
        function(){return [];},'https://credencial.c360a.salesforce.com',
        'ABH_PrestaShop_Data_Cloud'
    );
} catch(RuntimeException $e) {
    $invalid=$e->getMessage()==='g7_csc_presend_input_invalid';
}
g7CscAssert($invalid,'unsupported_cart_type_was_accepted');
echo "G7 G3/CSC join contract PASS: source-bound, read-only, no send or ready writer.\n";
