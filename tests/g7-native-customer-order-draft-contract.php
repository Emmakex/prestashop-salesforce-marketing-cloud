<?php
/**
 * G7: Real PrestaShop-shaped native object projection against the EXISTING
 * G4 v0.4 golden fixture. Synthetic objects only, no API/DB or file output.
 */
ob_start();
require __DIR__.'/g4-pst-typed-payload-fixture.php';
$fixture = json_decode(ob_get_clean(),true);
require_once dirname(__DIR__)
    . '/pssalesforcebridge/src/DataCloud/GrowthNativeCustomerOrderDraft.php';
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\GrowthNativeCustomerOrderDraft as Draft;

function g7DraftAssert($condition,$reason) {
    if (!$condition) {
        throw new RuntimeException('g7_native_draft_test_'.$reason);
    }
}
function g7DraftReject($fn,$code) {
    try {
        $fn();
    } catch (RuntimeException $e) {
        g7DraftAssert($e->getMessage()===$code,'wrong_error_'.$e->getMessage());
        return;
    }
    g7DraftAssert(false,'unsafe_accepted_'.$code);
}
$customer=(object)[
    'id'=>44,'id_shop'=>2,
    'email'=>'synthetic@fixture.example.invalid',
    'firstname'=>'Synthetic','lastname'=>'Fixture',
    'newsletter'=>1,'optin'=>0,'active'=>1,
    'deleted'=>0,'is_guest'=>0,'date_upd'=>'2026-11-10 14:00:00'
];
$order=(object)[
    'id'=>44,'id_shop'=>2,'id_customer'=>44,'id_cart'=>91,
    'id_currency'=>5,'total_paid'=>'42.40','current_state'=>2,
    'date_upd'=>'2026-07-10 14:00:00'
];
$currency=(object)['id'=>5,'iso_code'=>'EUR'];
$ns='growth_ci_abhtwo';
$draft=Draft::buildPair($ns,2,$customer,$order,$currency);
g7DraftAssert($draft['status']==='native_local_draft_only'
    && $draft['schema']==='PST_v0.4_customer_order'
    && json_decode($draft['customer_json'],true)===$fixture['PSCustomer']
    && json_decode($draft['order_json'],true)===$fixture['PSOrder'],
    'not_equivalent_to_existing_g4_golden');
foreach([
    'source_utc_provenance_certified','marketing_consent_certified',
    'payment_state_certified','namespace_globally_certified',
    'data_cloud_ingestion_authorized','marketing_sends_authorized',
    'outbound_worker_authorized'
] as $flag) {
    g7DraftAssert($draft[$flag]===false,'unsafe_'.$flag);
}
g7DraftAssert(count(json_decode($draft['customer_json'],true)['data'][0])===11
    && count(json_decode($draft['order_json'],true)['data'][0])===11,
    'schema_field_count');
$decodedOrder=json_decode($draft['order_json'],true)['data'][0];
$decodedCustomer=json_decode($draft['customer_json'],true)['data'][0];
g7DraftAssert($decodedOrder['customer_source_key']===$decodedCustomer['source_key']
    && $decodedOrder['paid_flag']===false
    && $decodedOrder['cart_source_key']==='growth_ci_abhtwo:2:cart:91'
    && $decodedCustomer['newsletter_flag']===true
    && $draft['marketing_consent_certified']===false,
    'unsafe_relationship_or_consent_inference');
$bad=clone $order; $bad->id_shop=3;
g7DraftReject(function()use($ns,$customer,$bad,$currency) {
    Draft::buildPair($ns,2,$customer,$bad,$currency);
},'g7_native_draft_cross_scope_or_orphan');
$bad=clone $order; $bad->id_customer=45;
g7DraftReject(function()use($ns,$customer,$bad,$currency) {
    Draft::buildPair($ns,2,$customer,$bad,$currency);
},'g7_native_draft_cross_scope_or_orphan');
$bad=clone $currency; $bad->id=7;
g7DraftReject(function()use($ns,$customer,$order,$bad) {
    Draft::buildPair($ns,2,$customer,$order,$bad);
},'g7_native_draft_cross_scope_or_orphan');
$bad=clone $customer; $bad->is_guest=1;
g7DraftReject(function()use($ns,$bad,$order,$currency) {
    Draft::buildPair($ns,2,$bad,$order,$currency);
},'g7_native_draft_customer_excluded');
$bad=clone $customer; $bad->deleted=1;
g7DraftReject(function()use($ns,$bad,$order,$currency) {
    Draft::buildPair($ns,2,$bad,$order,$currency);
},'g7_native_draft_customer_excluded');
$bad=clone $customer; $bad->newsletter='yes';
g7DraftReject(function()use($ns,$bad,$order,$currency) {
    Draft::buildPair($ns,2,$bad,$order,$currency);
},'g7_native_draft_customer_flag_invalid');
$bad=clone $customer; $bad->email='bad@@';
g7DraftReject(function()use($ns,$bad,$order,$currency) {
    Draft::buildPair($ns,2,$bad,$order,$currency);
},'g4_pst_invalid_email');
$bad=clone $order; $bad->date_upd='2026-02-30 10:00:00';
g7DraftReject(function()use($ns,$customer,$bad,$currency) {
    Draft::buildPair($ns,2,$customer,$bad,$currency);
},'g4_pst_invalid_local_date');
$bad=clone $order; $bad->total_paid=-1;
g7DraftReject(function()use($ns,$customer,$bad,$currency) {
    Draft::buildPair($ns,2,$customer,$bad,$currency);
},'g7_native_draft_total_unverified');
$bad=clone $order; $bad->current_state='INVALID';
g7DraftReject(function()use($ns,$customer,$bad,$currency) {
    Draft::buildPair($ns,2,$customer,$bad,$currency);
},'g7_native_draft_cross_scope_or_orphan');
$bad=clone $customer; unset($bad->id_shop);
g7DraftReject(function()use($ns,$bad,$order,$currency) {
    Draft::buildPair($ns,2,$bad,$order,$currency);
},'g7_native_draft_customer_fields_missing');
g7DraftReject(function()use($ns,$customer,$order,$currency) {
    Draft::buildPair($ns,2,$customer,$order,$currency,'marketing_cloud_growth','production');
},'g7_native_draft_test_growth_only');
g7DraftReject(function()use($customer,$order,$currency) {
    Draft::buildPair('other namespace',2,$customer,$order,$currency);
},'g7_native_draft_namespace_or_shop_invalid');

// Empty cart FK is permissible for an order, but it does not create a
// PSCart ingestion stream or a recovery event.
$noCart=clone $order; $noCart->id_cart=0;
$noCartDraft=Draft::buildPair($ns,2,$customer,$noCart,$currency);
g7DraftAssert(json_decode($noCartDraft['order_json'],true)['data'][0]
    ['cart_source_key']==='','empty_cart_fk_not_preserved');

// Tests and runtime never wire this pure projector into handlers or transport.
$module=file_get_contents(dirname(__DIR__).'/pssalesforcebridge/pssalesforcebridge.php');
$source=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/src/DataCloud/GrowthNativeCustomerOrderDraft.php');
g7DraftAssert(strpos($module,'GrowthNativeCustomerOrderDraft')===false,
    'accidental_runtime_hook');
foreach(['curl_','file_get_contents(', 'Db::', 'Configuration::',
    '->execute(', '->insert(', '/api/v1/ingest/'] as $forbidden) {
    g7DraftAssert(strpos($source,$forbidden)===false,
        'unexpected_io_'.$forbidden);
}
echo "G7 native customer/order v0.4 draft PASS: real object shapes, golden bytes, cross-shop safety, no transport.\n";
