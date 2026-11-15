<?php
/**
 * Synthetic G4 v0.4 PST envelopes from the actual Growth-only router, mapper,
 * gated v0.2->v0.4 projection, and typed payload builder.
 * CLI only: no customer data, no IO beyond stdout, no Salesforce calls.
 */
if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('g4_pst_fixture_cli_only');
}
require_once dirname(__DIR__) . '/pssalesforcebridge/src/Provider/ExplicitProviderRouter.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/IngestionRecordMapper.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/IngestionPayloadBuilder.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/Growth/GrowthPstSyntheticPreview.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/GrowthTypedIdentityCandidate.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/GrowthTypedPstPayloadBuilder.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Provider\ExplicitProviderRouter as Router;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthPstSyntheticPreview as Preview;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\GrowthTypedIdentityCandidate as Candidate;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\GrowthTypedPstPayloadBuilder as TypedBuilder;

$version = getenv('G4_PST_FIXTURE_PS_VERSION') ?: '8.2.8';
if (!in_array($version, ['1.7.8.11', '8.2.8', '9.1.5'], true)) {
    throw new RuntimeException('g4_pst_fixture_version_not_allowed');
}

$router = new Router($version, 'ABH', Router::GROWTH, 'growth_ci_abhtwo', 'test');
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
putenv('G3_PST_SYNTHETIC_PREVIEW=yes_disposable_ci_only');
$baseline = Preview::build(
    $router,
    'actionCustomerAccountAdd',
    ['newCustomer' => $customer],
    'actionValidateOrder',
    ['order' => $order, 'currency' => (object) ['iso_code' => 'EUR']]
);
$typed = Candidate::project($baseline);
if ($typed['status'] !== 'blocked_typed_identity_review'
    || $typed['schema_upload_authorized'] !== false
    || $typed['data_cloud_ingestion_authorized'] !== false
    || $typed['marketing_sends_authorized'] !== false) {
    throw new RuntimeException('g4_pst_fixture_unsafe_candidate');
}
$output = [];
foreach (['PSCustomer' => 'customer_payload', 'PSOrder' => 'order_payload'] as $object => $key) {
    $raw = json_decode($typed[$key], true);
    if (!is_array($raw) || !isset($raw['data'][0])) {
        throw new RuntimeException('g4_pst_fixture_missing_payload');
    }
    $json = TypedBuilder::encode($object, [$raw['data'][0]], Router::GROWTH, 'test');
    $output[$object] = json_decode($json, true);
}
echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
