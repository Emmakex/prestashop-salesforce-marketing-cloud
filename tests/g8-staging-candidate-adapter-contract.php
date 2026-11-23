<?php
/**
 * G8 staging G3/G7 -> coordinator candidate translation, purely synthetic.
 * Contract runs on PHP 7.1 / 8.5; no Salesforce, database or personal data.
 */
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG8StagingCandidateAdapter.php';
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG8StagingCandidateAdapter as Adapter;

function g8AdaptAssert($ok,$message) {
    if (!$ok)throw new RuntimeException('g8_stage_adapter_contract_'.$message);
}
function g8AdaptReject($batch,$shop,$after,$limit,$message) {
    $denied=false;
    try { Adapter::adapt($batch,$shop,$after,$limit); }
    catch (RuntimeException $e) {
        $denied=strpos($e->getMessage(),'g8_stage_adapter_')===0;
    }
    g8AdaptAssert($denied,$message);
}
$batch=[
    'status'=>'bounded_review_candidates_only',
    'candidate_refs_internal'=>[
        ['shop_id'=>2,'entity_type'=>'customer','entity_id'=>41,
            'accepted_sequence'=>7],
        ['shop_id'=>2,'entity_type'=>'order','entity_id'=>81,
            'accepted_sequence'=>10],
    ],
    'next_cursor'=>10,'has_more'=>false,
    'can_set_ready'=>false,'can_claim'=>false,
    'http_request_performed'=>false,
    'data_cloud_ingestion_authorized'=>false,
    'marketing_sends_authorized'=>false,
];
$adapted=Adapter::adapt($batch,2,0,10);
g8AdaptAssert($adapted===[
    ['shop'=>2,'type'=>'PSCustomer','id'=>41,'sequence'=>7],
    ['shop'=>2,'type'=>'PSOrder','id'=>81,'sequence'=>10],
], 'exact_candidate_shape');
g8AdaptAssert(count($adapted)===2
    && count($adapted[0])===4 && count($adapted[1])===4,
    'private_shape_leak');
$empty=$batch;
$empty['candidate_refs_internal']=[];
$empty['next_cursor']=33;
g8AdaptAssert(Adapter::adapt($empty,2,33,10)===[],
    'empty_window_is_not_rejected');
$invalid=$empty;$invalid['has_more']=true;
g8AdaptReject($invalid,2,33,10,'empty_with_more');
$invalid=$batch;$invalid['candidate_refs_internal'][0]['shop_id']=1;
g8AdaptReject($invalid,2,0,10,'cross_shop');
$invalid=$batch;$invalid['candidate_refs_internal'][0]['entity_type']='cart';
g8AdaptReject($invalid,2,0,10,'unsupported_object');
$invalid=$batch;$invalid['candidate_refs_internal'][0]['entity_id']='41';
g8AdaptReject($invalid,2,0,10,'non_integer_id');
$invalid=$batch;$invalid['candidate_refs_internal'][0]['secret']='do-not-expose';
g8AdaptReject($invalid,2,0,10,'extra_ref_fields');
$invalid=$batch;$invalid['candidate_refs_internal'][1]['accepted_sequence']=7;
g8AdaptReject($invalid,2,0,10,'out_of_order');
$invalid=$batch;$invalid['candidate_refs_internal'][1]['entity_type']='customer';
$invalid['candidate_refs_internal'][1]['entity_id']=41;
g8AdaptReject($invalid,2,0,10,'duplicate_entity');
$invalid=$batch;$invalid['next_cursor']=11;
g8AdaptReject($invalid,2,0,10,'cursor_inconsistent');
$invalid=$batch;$invalid['can_claim']=true;
g8AdaptReject($invalid,2,0,10,'claim_authorization_injected');
$invalid=$batch;$invalid['can_set_ready']=true;
g8AdaptReject($invalid,2,0,10,'ready_authorization_injected');
$invalid=$batch;$invalid['http_request_performed']=true;
g8AdaptReject($invalid,2,0,10,'http_work_injected');
$invalid=$batch;$invalid['data_cloud_ingestion_authorized']=true;
g8AdaptReject($invalid,2,0,10,'ingestion_authorization_injected');
$invalid=$batch;$invalid['marketing_sends_authorized']=true;
g8AdaptReject($invalid,2,0,10,'marketing_authorization_injected');
g8AdaptReject($batch,2,0,1,'over_limit');
g8AdaptReject($batch,2,-1,10,'negative_cursor');
g8AdaptReject($batch,2,0,11,'over_max_tick');
$source=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG8StagingCandidateAdapter.php');
$cron=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG8CronReadOnly.php');
$module=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/pssalesforcebridge.php');
g8AdaptAssert(strpos($cron,'GrowthG8StagingCandidateAdapter::adapt(')!==false
    && strpos($module,"GrowthG8StagingCandidateAdapter.php")!==false,
    'protected_staging_cron_not_using_adapter');
foreach (['curl_','->execute(', 'Db::getInstance(', '->claim(',
    '->post(', 'Mail::send(', "delivery_state='ready'",
    'GrowthAutomaticOneShotCoordinator::tick('] as $forbidden) {
    g8AdaptAssert(strpos($source,$forbidden)===false,
        'unexpected_outbound_or_mutation');
}
echo "G8 G3 candidate adapter PASS: strict mapping, bounded staging scope, no writes or sends.\n";
