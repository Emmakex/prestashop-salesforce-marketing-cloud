<?php
/**
 * G7 live-shaped streaming plan and asymmetrical 202 outcome, offline only.
 * No API, live token, source customer, DB, hook, cron or outbox mutation.
 */
ob_start();
require __DIR__ . '/g4-pst-typed-payload-fixture.php';
$fixture = json_decode(ob_get_clean(), true);

require_once dirname(__DIR__)
    . '/pssalesforcebridge/src/DataCloud/GrowthStreamingIngestionPlan.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\GrowthStreamingIngestionPlan as Plan;

function g7PlanAssert($check, $reason)
{
    if (!$check) {
        throw new RuntimeException('g7_streaming_contract_' . $reason);
    }
}
function g7PlanReject($call, $code)
{
    try {
        $call();
    } catch (RuntimeException $e) {
        g7PlanAssert($e->getMessage() === $code,
            'wrong_error_' . $e->getMessage());
        return;
    }
    g7PlanAssert(false, 'invalid_accepted_' . $code);
}

$origin = 'https://synthetic.c360a.salesforce.com';
$source = 'ABH_PrestaShop_Data_Cloud';
$ns = 'growth_ci_abhtwo';
foreach (['PSCustomer','PSOrder'] as $object) {
    $original = $fixture[$object];
    $packet = Plan::prepare(
        $origin, $source, $object, $original['data'],
        $ns, 2, 'marketing_cloud_growth', 'test'
    );
    g7PlanAssert($packet['status'] === 'blocked_transport_plan_only'
        && $packet['method'] === 'POST'
        && $packet['url'] === $origin . '/api/v1/ingest/sources/'
            . $source . '/' . $object
        && json_decode($packet['body'],true) === $original
        && $packet['count'] === 1
        && $packet['async_acceptance_http_status'] === 202,
        'incorrect_route_or_golden');
    g7PlanAssert(substr($packet['url'],-13) !== '/actions/test',
        'test_endpoint_mixed_with_upsert');
    foreach([
        'source_timestamp_verified','merchant_namespace_approved',
        'g5_synthetic_validation_certified','growth_native_consent_certified',
        'persisted_in_data_cloud_certified','dispatch_authorized',
        'marketing_sends_authorized',
    ] as $field) {
        g7PlanAssert($packet[$field] === false, 'unsafe_'.$field);
    }
}
$customer=$fixture['PSCustomer']['data'][0];
$order=$fixture['PSOrder']['data'][0];

g7PlanReject(function()use($origin,$source,$customer,$ns) {
    Plan::prepare($origin,$source,'PSCustomer',[$customer],$ns,2,
        'marketing_cloud_growth','production');
}, 'g7_streaming_growth_test_only');
g7PlanReject(function()use($origin,$source,$customer,$ns) {
    Plan::prepare($origin,$source,'PSCustomer',[$customer],$ns,2,
        'other','test');
}, 'g7_streaming_growth_test_only');
g7PlanReject(function()use($origin,$source,$customer,$ns) {
    Plan::prepare($origin,$source,'PSCart',[$customer],$ns,2,
        'marketing_cloud_growth','test');
}, 'g7_streaming_object_not_approved');
g7PlanReject(function()use($origin,$source,$customer,$ns) {
    Plan::prepare($origin,$source,'PSCustomer',[$customer],$ns,3,
        'marketing_cloud_growth','test');
}, 'g7_streaming_cross_shop_or_namespace');
g7PlanReject(function()use($origin,$source,$customer) {
    Plan::prepare($origin,$source,'PSCustomer',[$customer],'other_ci_shop',2,
        'marketing_cloud_growth','test');
}, 'g7_streaming_cross_shop_or_namespace');
g7PlanReject(function()use($origin,$source,$customer,$ns) {
    Plan::prepare($origin,$source,'PSCustomer',[], $ns,2,
        'marketing_cloud_growth','test');
}, 'g7_streaming_batch_size_invalid');
g7PlanReject(function()use($origin,$source,$customer,$ns) {
    Plan::prepare($origin,$source,'PSCustomer',array_fill(0,51,$customer),$ns,2,
        'marketing_cloud_growth','test');
}, 'g7_streaming_batch_size_invalid');
g7PlanReject(function()use($origin,$source,$customer,$ns) {
    Plan::prepare($origin,$source,'PSCustomer',[$customer,$customer],$ns,2,
        'marketing_cloud_growth','test');
}, 'g4_pst_duplicate_typed_pk');
g7PlanReject(function()use($origin,$source,$customer,$ns) {
    $bad=$customer; $bad['source_key']='growth_ci_abhtwo:2:order:44';
    Plan::prepare($origin,$source,'PSCustomer',[$bad],$ns,2,
        'marketing_cloud_growth','test');
}, 'g4_pst_invalid_typed_pk');
g7PlanReject(function()use($origin,$source,$customer,$ns) {
    $bad=$customer; $bad['private_phone']='private';
    Plan::prepare($origin,$source,'PSCustomer',[$bad],$ns,2,
        'marketing_cloud_growth','test');
}, 'g4_pst_field_inventory_invalid');
g7PlanReject(function()use($origin,$source,$order,$ns) {
    $bad=$order; $bad['customer_source_key']='other:2:customer:44';
    Plan::prepare($origin,$source,'PSOrder',[$bad],$ns,2,
        'marketing_cloud_growth','test');
}, 'g4_pst_cross_scope_fk');
g7PlanReject(function()use($origin,$source,$customer,$ns) {
    Plan::prepare('https://example.com',$source,'PSCustomer',[$customer],$ns,2,
        'marketing_cloud_growth','test');
}, 'g7_streaming_origin_invalid');
g7PlanReject(function()use($origin,$source,$customer,$ns) {
    Plan::prepare($origin.'/actions/test',$source,'PSCustomer',[$customer],$ns,2,
        'marketing_cloud_growth','test');
}, 'g7_streaming_origin_invalid');
g7PlanReject(function()use($origin,$source,$customer,$ns) {
    Plan::prepare($origin,'Bad/Source','PSCustomer',[$customer],$ns,2,
        'marketing_cloud_growth','test');
}, 'g7_streaming_connector_invalid');

$accepted=Plan::classify(202,true);
g7PlanAssert($accepted['outcome_state'] === 'accepted_asynchronously_unconfirmed'
    && $accepted['request_accepted_asynchronously'] === true
    && !$accepted['record_persisted_certified']
    && !$accepted['automatic_retry_authorized'],
    '202_marked_delivered_or_retryable');
foreach([429,500,502,503,504] as $status) {
    $out=Plan::classify($status,true);
    g7PlanAssert($out['outcome_state']==='backoff_and_reconcile_before_retry'
        && $out['requires_manual_or_source_reconciliation']===true,
        'transient_status_not_reconciled');
}
foreach([400,401,403,404,409,422,200,201,204,301,302,418] as $status) {
    $out=Plan::classify($status,true);
    g7PlanAssert($out['outcome_state'] !== 'accepted_asynchronously_unconfirmed'
        && !$out['request_accepted_asynchronously'],
        'wrong_ingestion_status_passed');
}
$timeout=Plan::classify(0,false);
g7PlanAssert($timeout['outcome_state']==='unknown_after_transport'
    && $timeout['requires_manual_or_source_reconciliation'],
    'timeout_race_not_quarantined');
foreach([202,429,500,400,401,200] as $status) {
    $state=Plan::classify($status,true);
    g7PlanAssert($state['status']==='hold_for_review'
        && $state['record_persisted_certified']===false
        && $state['record_mapped_to_dmo_certified']===false
        && $state['marketing_consent_certified']===false
        && $state['automatic_retry_authorized']===false
        && $state['outbox_claim_release_authorized']===false
        && $state['marketing_sends_authorized']===false,
        'unsafe_outcome_'.$status);
    g7PlanAssert(!isset($state['response_body'],$state['access_token'],
        $state['email'],$state['source_key']), 'source_leak');
}
g7PlanReject(function(){Plan::classify(202,'true');},
    'g7_streaming_outcome_input_invalid');
$sourceCode=file_get_contents(dirname(__DIR__)
    . '/pssalesforcebridge/src/DataCloud/GrowthStreamingIngestionPlan.php');
$moduleCode=file_get_contents(dirname(__DIR__)
    . '/pssalesforcebridge/pssalesforcebridge.php');
$cronCode=file_get_contents(dirname(__DIR__)
    . '/pssalesforcebridge/controllers/front/cron.php');
$hookSection=substr($moduleCode,
    strpos($moduleCode,'public function hookActionCustomerAccountAdd'),
    strpos($moduleCode,'public function getContent')
        -strpos($moduleCode,'public function hookActionCustomerAccountAdd'));
g7PlanAssert(strpos($hookSection,'GrowthStreamingIngestionPlan')===false
    && strpos($cronCode,'GrowthStreamingIngestionPlan')===false
    && strpos($moduleCode,'runManualOneRecord')!==false,
    'streaming_boundary_wired_to_unapproved_runtime');
foreach(['curl_','file_get_contents(', 'Db::',
    '->execute(', '->insert(', 'file_put_contents('] as $io) {
    g7PlanAssert(strpos($sourceCode,$io)===false,
        'streaming_boundary_performs_io_'.$io);
}
echo "G7 streaming boundary PASS: 2 typed objects, exact routes, 202 pending, retry reconciliation, no IO.\n";
