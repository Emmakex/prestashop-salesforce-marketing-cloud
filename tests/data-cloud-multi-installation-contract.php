<?php
/** Synthetic same-ID customers across websites; no production DB access. */
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/IngestionRecordMapper.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/SourceTimestampNormalizer.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/ShopTimezoneResolver.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/StagedUtcRecordMapper.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/IngestionPayloadBuilder.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/StagedUtcPayloadBuilder.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/StagedInstallationPipeline.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\StagedInstallationPipeline;

function site_ok($ok, $message) {
    if (!$ok) { fwrite(STDERR, $message . "\n"); exit(1); }
}
function site_reject($callback, $expected) {
    try {
        $callback();
        site_ok(false, 'Unsafe staged site operation accepted.');
    } catch (RuntimeException $error) {
        site_ok($error->getMessage() === $expected,
            'Unexpected site diagnostic: ' . $error->getMessage());
    }
}

$calls = [];
$zones = [2 => 'Europe/Madrid', 3 => 'America/Lima'];
$siteA = new StagedInstallationPipeline('site_alpha', function ($id) use (&$calls, $zones) {
    $calls[] = $id;
    return isset($zones[$id]) ? $zones[$id] : null;
});
$siteB = new StagedInstallationPipeline('site_beta', function ($id) {
    return $id === 2 ? 'UTC' : null;
});
$customer = [
    'shop_id' => 2, 'customer_id' => 17, 'contact_key' => 'ps_2_17',
    'email' => 'synthetic@example.invalid', 'firstname' => 'Only',
    'lastname' => 'Synthetic', 'newsletter' => false, 'optin' => false,
    'active' => true, 'date_upd' => '2026-11-10 14:00:00',
];
$a = $siteA->customer($customer);
$b = $siteB->customer($customer);
site_ok($a['source_key'] === 'site_alpha:ps_2_17', 'Wrong namespace A.');
site_ok($b['source_key'] === 'site_beta:ps_2_17', 'Wrong namespace B.');
site_ok($a['source_key'] !== $b['source_key'], 'Cross-site collision.');
site_ok($a['source_updated_utc'] === '2026-11-10T13:00:00.000Z',
    'Madrid timezone conversion incorrect.');
site_ok($b['source_updated_utc'] === '2026-11-10T14:00:00.000Z',
    'UTC timezone conversion incorrect.');

$customer['shop_id'] = 3;
$customer['contact_key'] = 'ps_3_17';
$customer['date_upd'] = '2026-07-10 14:00:00';
$c = $siteA->customer($customer);
site_ok($c['source_key'] === 'site_alpha:ps_3_17', 'Multi-shop key collision.');
site_ok($c['source_updated_utc'] === '2026-07-10T19:00:00.000Z',
    'Per-shop Lima timezone ignored.');

$order = [
    'shop_id' => 2, 'order_id' => 44, 'order_key' => 'ps_2_44',
    'customer_id' => 17, 'contact_key' => 'ps_2_17', 'cart_id' => 12,
    'currency' => 'EUR', 'total_paid' => 25.5, 'paid' => true,
    'state_id' => 2, 'date_upd' => '2026-07-10 14:00:00',
];
$ao = $siteA->order($order);
$bo = $siteB->order($order);
site_ok($ao['source_key'] === 'site_alpha:ps_2_44', 'Wrong order site A.');
site_ok($bo['source_key'] === 'site_beta:ps_2_44', 'Wrong order site B.');
site_ok($ao['customer_source_key'] === $a['source_key'],
    'Order A linked to another site.');
site_ok($bo['customer_source_key'] === $b['source_key'],
    'Order B linked to another site.');
$payload = json_decode($siteA->encode('PSOrder', [$ao]), true);
site_ok(isset($payload['data'][0]) && $payload['data'][0] === $ao,
    'Invalid staged v0.3 envelope.');
site_ok(count($payload['data'][0]) === 12, 'Expected twelve fields.');
site_ok($calls === [2, 3, 2], 'Shop timezone resolution order mismatch.');

site_reject(function () use ($siteA, $ao) {
    $bad = $ao;
    $bad['customer_source_key'] = 'site_beta:ps_2_17';
    $siteA->encode('PSOrder', [$bad]);
}, 'data_cloud_payload_cross_shop_relation');
site_reject(function () use ($siteA, $a) {
    $siteA->encode('PSCustomer', [$a, $a]);
}, 'data_cloud_payload_duplicate_identity');
site_reject(function () use ($siteB, $customer) {
    $siteB->customer($customer);
}, 'data_cloud_shop_timezone_not_configured');
site_reject(function () {
    new StagedInstallationPipeline('', function () { return 'UTC'; });
}, 'data_cloud_invalid_namespace');
site_reject(function () {
    new StagedInstallationPipeline('site_gamma', null);
}, 'data_cloud_site_timezone_reader_required');
site_reject(function () use ($siteA, $customer) {
    $bad = $customer;
    unset($bad['shop_id']);
    $siteA->customer($bad);
}, 'data_cloud_site_shop_id_missing');
site_reject(function () use ($siteA, $customer) {
    $bad = $customer;
    $bad['shop_id'] = '2 OR 1=1';
    $siteA->customer($bad);
}, 'data_cloud_site_invalid_shop_id');
site_reject(function () use ($siteA, $customer) {
    $bad = $customer;
    $bad['shop_id'] = 2;
    $bad['contact_key'] = 'ps_2_17';
    $bad['date_upd'] = '2026-10-25 02:30:00';
    $siteA->customer($bad);
}, 'data_cloud_timestamp_ambiguous_local_time');

$src = file_get_contents(dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/StagedInstallationPipeline.php');
site_ok(strpos($src, 'curl_') === false
    && strpos($src, 'file_get_contents(') === false
    && strpos($src, '/api/v1/ingest/') === false, 'Staging must remain offline.');
$ci = file_get_contents(dirname(__DIR__) . '/.github/workflows/ci.yml');
site_ok(strpos($ci, 'php tests/data-cloud-multi-installation-contract.php') !== false,
    'CI missing multi-site contract.');
fwrite(STDOUT, "Staged multi-site PrestaShop -> UTC -> JSON contract passed without HTTP.\n");
