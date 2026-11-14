<?php
/** CI-only synthetic customer/order -> frozen PST v0.2 review contract. */
require_once dirname(__DIR__) . '/pssalesforcebridge/src/Provider/ExplicitProviderRouter.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/IngestionRecordMapper.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/IngestionPayloadBuilder.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/Growth/GrowthPstSyntheticPreview.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Provider\ExplicitProviderRouter as Router;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\IngestionPayloadBuilder as Builder;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthPstSyntheticPreview as Preview;

function pstPreviewAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "Growth PST synthetic FAIL: " . $message . PHP_EOL);
        exit(1);
    }
}
function pstPreviewReject($fn, $reason)
{
    try {
        $fn();
    } catch (RuntimeException $e) {
        pstPreviewAssert($e->getMessage() === $reason,
            'unexpected_failure_code_' . $e->getMessage());
        return;
    }
    pstPreviewAssert(false, 'accepted_unsafe_fixture_' . $reason);
}

$router = new Router('8.2.8', 'ABH', Router::GROWTH, 'growth_ci_abhtwo', 'test');
$customer = (object) [
    'id' => 44, 'id_shop' => 2,
    'email' => 'synthetic@fixture.example.invalid',
    'firstname' => 'Synthetic', 'lastname' => 'Fixture',
    'newsletter' => 1, 'optin' => 0, 'active' => 1,
    'date_upd' => '2026-11-10 14:00:00',
    '__growth_synthetic_fixture' => Preview::FIXTURE_MARKER,
];
$order = (object) [
    'id' => 44, 'id_shop' => 2, 'id_customer' => 44, 'id_cart' => 91,
    'total_paid' => '42.40', 'current_state' => 2,
    'date_upd' => '2026-07-10 14:00:00',
    'reference' => 'SYNTHETIC_ORDER',
    '__growth_synthetic_fixture' => Preview::FIXTURE_MARKER,
];
$cParams = ['newCustomer' => $customer];
$oParams = ['order' => $order, 'currency' => (object) ['iso_code' => 'EUR']];

putenv('G3_PST_SYNTHETIC_PREVIEW');
pstPreviewReject(function () use ($router, $cParams, $oParams) {
    Preview::build($router, 'actionCustomerAccountAdd', $cParams,
        'actionValidateOrder', $oParams);
}, 'growth_pst_preview_ci_only');
putenv('G3_PST_SYNTHETIC_PREVIEW=yes_disposable_ci_only');
$preview = Preview::build($router, 'actionCustomerAccountAdd', $cParams,
    'actionValidateOrder', $oParams);
pstPreviewAssert($preview['status'] === 'blocked_synthetic_review'
    && $preview['schema_candidate'] === 'PST_v0.2_review_only'
    && $preview['provider'] === Router::GROWTH,
    'wrong_pipeline_or_product');
foreach (['identity_contract_approved', 'source_updated_utc_verified',
    'order_paid_semantics_approved', 'marketing_consent_approved',
    'schema_upload_authorized', 'data_cloud_ingestion_authorized',
    'marketing_sends_authorized'] as $denied) {
    pstPreviewAssert($preview[$denied] === false, 'unsafe_authorization_' . $denied);
}
$c = json_decode($preview['customer_payload'], true);
$o = json_decode($preview['order_payload'], true);
pstPreviewAssert(is_array($c) && is_array($o)
    && array_keys($c) === ['data'] && array_keys($o) === ['data']
    && count($c['data']) === 1 && count($o['data']) === 1,
    'not_pst_envelopes');
$c = $c['data'][0]; $o = $o['data'][0];
$expected = Builder::fields();
pstPreviewAssert(array_keys($c) === array_keys($expected['PSCustomer'])
    && array_keys($o) === array_keys($expected['PSOrder']),
    'not_frozen_v02_field_contract');
pstPreviewAssert($c['source_key'] === 'growth_ci_abhtwo:ps_2_44'
    && $o['source_key'] === 'growth_ci_abhtwo:ps_2_44'
    && $o['customer_source_key'] === $c['source_key'],
    'unmapped_customer_order_relation');
pstPreviewAssert($preview['cross_object_key_collision'] === true
    && $preview['customer_router_key'] === 'growth_ci_abhtwo:2:customer:44'
    && $preview['order_router_key'] === 'growth_ci_abhtwo:2:order:44'
    && $preview['customer_router_key'] !== $preview['order_router_key'],
    'g3_identity_key_conflict_not_detected');
pstPreviewAssert($c['newsletter_flag'] === true && $c['partner_optin_flag'] === false
    && $o['paid_flag'] === false && $o['total_paid'] === 42.4
    && $o['currency'] === 'EUR' && $o['cart_source_key'] === 'growth_ci_abhtwo:ps_2_91'
    && $c['source_updated_local'] === '2026-11-10 14:00:00'
    && !isset($c['source_updated_utc']),
    'unapproved_consent_paid_or_utc_inference');
pstPreviewAssert(!isset($c['marketing_consent'])
    && !isset($o['recovery_url']), 'campaign_field_added');

$cRetry = ['customer' => clone $customer];
$retry = Preview::build($router, 'actionCustomerAccountUpdate', $cRetry,
    'actionValidateOrder', $oParams);
pstPreviewAssert($retry['customer_payload'] === $preview['customer_payload'],
    'same_entity_non_deterministic');
foreach (['1.7.8.11', '8.2.8', '9.1.5'] as $version) {
    $routing = new Router($version, 'ABH', Router::GROWTH, 'growth_ci_abhtwo', 'test');
    $checked = Preview::build($routing, 'actionCustomerAccountAdd', $cParams,
        'actionValidateOrder', $oParams);
    pstPreviewAssert($checked['order_payload'] === $preview['order_payload'],
        'prestashop_version_mapping_drift_' . $version);
}

pstPreviewReject(function () use ($router, $cParams, $oParams) {
    Preview::build($router, 'actionCartSave', $cParams,
        'actionValidateOrder', $oParams);
}, 'growth_pst_preview_hooks_not_allowed');
pstPreviewReject(function () use ($router, $cParams, $oParams) {
    Preview::build($router, 'actionCustomerAccountAdd', $cParams,
        'actionOrderStatusPostUpdate', $oParams);
}, 'growth_pst_preview_hooks_not_allowed');
$bad = clone $customer; $bad->email = 'real@example.com';
pstPreviewReject(function () use ($router, $bad, $oParams) {
    Preview::build($router, 'actionCustomerAccountAdd', ['newCustomer' => $bad],
        'actionValidateOrder', $oParams);
}, 'growth_pst_preview_not_synthetic');
$bad = clone $customer; $bad->phone = 'SENSITIVE_PHONE';
pstPreviewReject(function () use ($router, $bad, $oParams) {
    Preview::build($router, 'actionCustomerAccountAdd', ['newCustomer' => $bad],
        'actionValidateOrder', $oParams);
}, 'growth_pst_preview_untrusted_source');
$bad = clone $customer; $bad->id_shop = '02';
pstPreviewReject(function () use ($router, $bad, $oParams) {
    Preview::build($router, 'actionCustomerAccountAdd', ['newCustomer' => $bad],
        'actionValidateOrder', $oParams);
}, 'growth_pst_preview_invalid_id');
$bad = clone $customer; $bad->newsletter = 'marketing_optin';
pstPreviewReject(function () use ($router, $bad, $oParams) {
    Preview::build($router, 'actionCustomerAccountAdd', ['newCustomer' => $bad],
        'actionValidateOrder', $oParams);
}, 'growth_pst_preview_invalid_flag');
$bad = clone $customer; $bad->date_upd = '2026-03-99 12:30:00';
pstPreviewReject(function () use ($router, $bad, $oParams) {
    Preview::build($router, 'actionCustomerAccountAdd', ['newCustomer' => $bad],
        'actionValidateOrder', $oParams);
}, 'growth_pst_preview_invalid_local_time');
$bad = clone $order; $bad->id_shop = 1;
pstPreviewReject(function () use ($router, $cParams, $bad, $oParams) {
    Preview::build($router, 'actionCustomerAccountAdd', $cParams,
        'actionValidateOrder', ['order' => $bad, 'currency' => $oParams['currency']]);
}, 'growth_pst_preview_cross_shop_or_orphan');
$bad = clone $order; $bad->id_customer = 45;
pstPreviewReject(function () use ($router, $cParams, $bad, $oParams) {
    Preview::build($router, 'actionCustomerAccountAdd', $cParams,
        'actionValidateOrder', ['order' => $bad, 'currency' => $oParams['currency']]);
}, 'growth_pst_preview_cross_shop_or_orphan');
$bad = clone $order; $bad->total_paid = 'Infinity';
pstPreviewReject(function () use ($router, $cParams, $bad, $oParams) {
    Preview::build($router, 'actionCustomerAccountAdd', $cParams,
        'actionValidateOrder', ['order' => $bad, 'currency' => $oParams['currency']]);
}, 'growth_pst_preview_invalid_amount');
$badCurrency = ['order' => $order, 'currency' => (object) ['iso_code' => 'eur']];
pstPreviewReject(function () use ($router, $cParams, $badCurrency) {
    Preview::build($router, 'actionCustomerAccountAdd', $cParams,
        'actionValidateOrder', $badCurrency);
}, 'growth_pst_preview_invalid_currency');
pstPreviewReject(function () use ($router, $cParams, $order) {
    Preview::build($router, 'actionCustomerAccountAdd', $cParams,
        'actionValidateOrder', ['order' => $order]);
}, 'growth_pst_preview_currency_required');
$unsafeSource = file_get_contents(dirname(__DIR__)
    . '/pssalesforcebridge/src/Growth/GrowthPstSyntheticPreview.php');
$moduleSource = file_get_contents(dirname(__DIR__)
    . '/pssalesforcebridge/pssalesforcebridge.php');
pstPreviewAssert(strpos($moduleSource, 'GrowthPstSyntheticPreview') === false,
    'synthetic_preview_wired_to_runtime');
foreach (['curl_', 'file_get_contents(', 'Db::', 'Configuration::',
    '->execute(', '->insert(', '->enqueue(', '/api/v1/ingest/'] as $badTransport) {
    pstPreviewAssert(strpos($unsafeSource, $badTransport) === false,
        'synthetic_preview_uses_io_' . $badTransport);
}
echo "Growth PST synthetic v0.2 preview PASS: 3 PS families, typed-key drift exposed, no API or customer data.\n";
