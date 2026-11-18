<?php
/**
 * G7 G3 outbox + latest entity ledger -> native PST v0.4 -> Ingestion API
 * shape preview. Synthetic-only, no database, no network or PII in report.
 */
ob_start();
require __DIR__.'/g4-pst-typed-payload-fixture.php';
$golden=json_decode(ob_get_clean(),true);
require_once dirname(__DIR__).'/pssalesforcebridge/src/DataCloud/GrowthNativeCustomerOrderDraft.php';
require_once dirname(__DIR__).'/pssalesforcebridge/src/DataCloud/GrowthStreamingIngestionPlan.php';
require_once dirname(__DIR__).'/pssalesforcebridge/src/DataCloud/GrowthCapturedEventPstPreview.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\GrowthCapturedEventPstPreview as G7;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\GrowthNativeCustomerOrderDraft as Draft;

function g7PreviewAssert($ok,$code) {
    if (!$ok) throw new RuntimeException('g7_pst_preview_test_'.$code);
}
function g7PreviewReject($f,$expected) {
    try { $f(); }
    catch (RuntimeException $e) {
        g7PreviewAssert($e->getMessage()===$expected,
            'unexpected_error_'.$e->getMessage());
        return;
    }
    g7PreviewAssert(false,'unsafe_accepted_'.$expected);
}
$customer=(object)[
    'id'=>44,'id_shop'=>2,'email'=>'DO_NOT_LEAK@example.invalid',
    'firstname'=>'PRIVATE_PERSON','lastname'=>'PRIVATE_SURNAME',
    'newsletter'=>1,'optin'=>0,'active'=>1,'deleted'=>0,'is_guest'=>0,
    'date_upd'=>'2026-11-10 14:00:00'
];
$order=(object)[
    'id'=>44,'id_shop'=>2,'id_customer'=>44,'id_cart'=>91,
    'id_currency'=>5,'total_paid'=>'42.40','current_state'=>2,
    'date_upd'=>'2026-07-10 14:00:00'
];
$currency=(object)['id'=>5,'iso_code'=>'EUR'];
$ns='growth_ci_abhtwo';
$origin='https://credencial.c360a.salesforce.com';
$source='ABH_PrestaShop_Data_Cloud';
$evt=[
    'source_namespace'=>$ns,'shop_id'=>'2','entity_type'=>'customer',
    'entity_id'=>'44','status'=>'blocked',
    'accepted_sequence'=>'10','original_event_type'=>'CUSTOMER_CREATED'
];
$ledger=[
    'source_namespace'=>$ns,'shop_id'=>'2','entity_type'=>'customer',
    'entity_id'=>'44','status'=>'blocked',
    'latest_accepted_sequence'=>'10'
];
// Customer-only source must work even without a fabricated order.
$customerDraft=Draft::buildCustomer($ns,2,$customer);
g7PreviewAssert($customerDraft['status']==='native_local_draft_only'
    && $customerDraft['data_cloud_ingestion_authorized']===false
    && count(json_decode($customerDraft['customer_json'],true)['data'][0])===11,
    'customer_only_draft');
$report=G7::inspect(
    $evt,$ledger,$ns,2,$customer,null,null,$origin,$source
);
g7PreviewAssert($report['status']==='blocked_pst_shape_preview_only'
    && $report['event_source_type']==='customer'
    && $report['objects_prepared_in_memory']===1
    && $report['records_prepared_in_memory']===1
    && $report['outbox_latest_ledger_verified']===true
    && $report['typed_pst_v04_shape_verified']===true,
    'customer_event_not_projected');
$updated=$evt; $updated['original_event_type']='CUSTOMER_UPDATED';
$repUpdated=G7::inspect(
    $updated,$ledger,$ns,2,$customer,null,null,$origin,$source
);
g7PreviewAssert($repUpdated['event_source_type']==='customer',
    'update_not_supported');

$evtOrder=$evt;
$evtOrder['entity_type']='order';
$evtOrder['original_event_type']='ORDER_CREATED';
$evtOrder['accepted_sequence']='11';
$ledgerOrder=$ledger;
$ledgerOrder['entity_type']='order';
$ledgerOrder['latest_accepted_sequence']='11';
$repOrder=G7::inspect(
    $evtOrder,$ledgerOrder,$ns,2,$customer,$order,$currency,$origin,$source
);
g7PreviewAssert($repOrder['event_source_type']==='order'
    && $repOrder['objects_prepared_in_memory']===2
    && $repOrder['records_prepared_in_memory']===2,
    'order_event_not_projected');
foreach([$report,$repUpdated,$repOrder] as $r) {
    foreach([
        'source_freshness_certified','source_timestamp_utc_certified',
        'schema_ingestion_approved','consent_growth_certified',
        'data_cloud_ingestion_authorized','outbound_worker_authorized',
        'automatic_retry_authorized','marketing_sends_authorized',
        'http_request_performed'
    ] as $field) {
        g7PreviewAssert($r[$field]===false,'unsafe_flag_'.$field);
    }
    $serialized=json_encode($r);
    foreach([
        'DO_NOT_LEAK','PRIVATE_PERSON','PRIVATE_SURNAME','42.40',
        'credencial','ABH_PrestaShop','growth_ci_abhtwo',
        '44','api/v1/ingest','customer_json','order_json','body'
    ] as $secret) {
        g7PreviewAssert(strpos($serialized,$secret)===false,
            'private_data_leaked_'.$secret);
    }
}
$bad=$ledger;$bad['latest_accepted_sequence']='9';
g7PreviewReject(function()use($evt,$bad,$ns,$customer,$origin,$source){
    G7::inspect($evt,$bad,$ns,2,$customer,null,null,$origin,$source);
},'g7_pst_preview_not_current_blocked_source');
$bad=$ledger;$bad['shop_id']='1';
g7PreviewReject(function()use($evt,$bad,$ns,$customer,$origin,$source){
    G7::inspect($evt,$bad,$ns,2,$customer,null,null,$origin,$source);
},'g7_pst_preview_outbox_ledger_mismatch');
$bad=$evt;$bad['status']='ready';
$other=$ledger;$other['status']='ready';
g7PreviewReject(function()use($bad,$other,$ns,$customer,$origin,$source){
    G7::inspect($bad,$other,$ns,2,$customer,null,null,$origin,$source);
},'g7_pst_preview_not_current_blocked_source');
$bad=$evt;$bad['original_event_type']='CART_ABANDONED';
g7PreviewReject(function()use($bad,$ledger,$ns,$customer,$origin,$source){
    G7::inspect($bad,$ledger,$ns,2,$customer,null,null,$origin,$source);
},'g7_pst_preview_event_not_supported');
$bad=clone $customer;$bad->id_shop=3;
g7PreviewReject(function()use($evt,$ledger,$ns,$bad,$origin,$source){
    G7::inspect($evt,$ledger,$ns,2,$bad,null,null,$origin,$source);
},'g7_pst_preview_source_identity_invalid');
$bad=clone $customer;$bad->is_guest=1;
g7PreviewReject(function()use($evt,$ledger,$ns,$bad,$origin,$source){
    G7::inspect($evt,$ledger,$ns,2,$bad,null,null,$origin,$source);
},'g7_native_draft_customer_excluded');
$bad=clone $customer;$bad->newsletter='yes';
g7PreviewReject(function()use($evt,$ledger,$ns,$bad,$origin,$source){
    G7::inspect($evt,$ledger,$ns,2,$bad,null,null,$origin,$source);
},'g7_native_draft_customer_flag_invalid');
$bad=clone $order;$bad->id_shop=3;
g7PreviewReject(function()use($evtOrder,$ledgerOrder,$ns,$customer,$bad,$currency,$origin,$source){
    G7::inspect($evtOrder,$ledgerOrder,$ns,2,$customer,$bad,$currency,$origin,$source);
},'g7_pst_preview_order_identity_invalid');
g7PreviewReject(function()use($evt,$ledger,$ns,$customer,$origin,$source){
    G7::inspect($evt,$ledger,$ns,2,$customer,null,null,$origin,$source,
        'marketing_cloud_growth','production');
},'g7_pst_preview_test_growth_only');
g7PreviewReject(function()use($evt,$ledger,$ns,$customer,$source){
    G7::inspect($evt,$ledger,$ns,2,$customer,null,null,
        'https://example.org',$source);
},'g7_streaming_origin_invalid');

// Runtime and status routes must not load preview accidentally, even though
// building its PST shape would be locally possible.
$module=file_get_contents(dirname(__DIR__).'/pssalesforcebridge/pssalesforcebridge.php');
$cron=file_get_contents(dirname(__DIR__).'/pssalesforcebridge/controllers/front/cron.php');
g7PreviewAssert(strpos($module,'GrowthCapturedEventPstPreview')===false
    && strpos($cron,'GrowthCapturedEventPstPreview')===false,
    'accidental_sender_wiring');
$sourceCode=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/src/DataCloud/GrowthCapturedEventPstPreview.php');
foreach(['curl_exec(', 'file_get_contents(', 'Db::', 'Configuration::',
    '->execute(', '->insert(', 'file_put_contents('] as $forbidden) {
    g7PreviewAssert(strpos($sourceCode,$forbidden)===false,
        'unexpected_IO_'.$forbidden);
}
echo "G7 captured outbox to typed Data Cloud PST preview PASS: customer/order source, latest ledger, no PII report or sends.\n";
