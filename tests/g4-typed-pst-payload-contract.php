<?php
/** Pure G4 typed PST batch contract; synthetic data only. */
ob_start();
require __DIR__ . '/g4-pst-typed-payload-fixture.php';
$fixtureJson = ob_get_clean();
$fixture = json_decode($fixtureJson, true);
if (!is_array($fixture) || !isset($fixture['PSCustomer']['data'][0],
    $fixture['PSOrder']['data'][0])) {
    throw new RuntimeException('g4_fixture_invalid');
}
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\GrowthTypedPstPayloadBuilder as Builder;

function g4Assert($condition, $reason)
{
    if (!$condition) {
        fwrite(STDERR, 'G4 typed PST FAIL: ' . $reason . PHP_EOL);
        exit(1);
    }
}

function g4Reject($fn, $code)
{
    try {
        $fn();
    } catch (RuntimeException $e) {
        g4Assert($e->getMessage() === $code,
            'unexpected_exception_' . $e->getMessage());
        return;
    }
    g4Assert(false, 'unsafe_input_accepted_' . $code);
}

$customer = $fixture['PSCustomer']['data'][0];
$order = $fixture['PSOrder']['data'][0];
g4Assert($customer['source_key'] === 'growth_ci_abhtwo:2:customer:44'
    && $order['source_key'] === 'growth_ci_abhtwo:2:order:44'
    && $order['customer_source_key'] === $customer['source_key']
    && $order['cart_source_key'] === 'growth_ci_abhtwo:2:cart:91',
    'typed_keys_or_fk_incorrect');
g4Assert($customer['source_key'] !== $order['source_key'],
    'customer_order_collision');
g4Assert(count($customer) + count($order) === 22
    && !isset($customer['marketing_consent'])
    && !isset($order['recovery_url'])
    && $order['paid_flag'] === false, 'unsafe_v04_contract');
foreach (['PSCustomer' => $customer, 'PSOrder' => $order] as $name => $row) {
    $payload = Builder::encode($name, [$row], 'marketing_cloud_growth', 'test');
    g4Assert(json_decode($payload, true) === $fixture[$name], 'payload_mismatch_' . $name);
}

g4Reject(function () use ($customer) {
    Builder::encode('PSCustomer', [$customer], 'marketing_cloud_growth', 'production');
}, 'g4_pst_test_growth_only');
g4Reject(function () use ($customer) {
    Builder::encode('PSCustomer', [$customer], 'unsupported', 'test');
}, 'g4_pst_test_growth_only');
g4Reject(function () use ($customer) {
    Builder::encode('PSCart', [$customer], 'marketing_cloud_growth', 'test');
}, 'g4_pst_unsupported_object');
g4Reject(function () {
    Builder::encode('PSCustomer', [], 'marketing_cloud_growth', 'test');
}, 'g4_pst_batch_count_invalid');

$bad = $customer;
$bad['phone'] = 'PRIVATE_PHONE';
g4Reject(function () use ($bad) {
    Builder::encode('PSCustomer', [$bad], 'marketing_cloud_growth', 'test');
}, 'g4_pst_field_inventory_invalid');
$bad = $customer;
$bad['shop_id'] = '2';
g4Reject(function () use ($bad) {
    Builder::encode('PSCustomer', [$bad], 'marketing_cloud_growth', 'test');
}, 'g4_pst_invalid_value_type');
$bad = $customer;
$bad['source_namespace'] = 'invalid namespace';
g4Reject(function () use ($bad) {
    Builder::encode('PSCustomer', [$bad], 'marketing_cloud_growth', 'test');
}, 'g4_pst_invalid_scope');
$bad = $customer;
$bad['source_key'] = 'growth_ci_abhtwo:ps_2_44';
g4Reject(function () use ($bad) {
    Builder::encode('PSCustomer', [$bad], 'marketing_cloud_growth', 'test');
}, 'g4_pst_invalid_typed_pk');
$bad = $customer;
$bad['source_key'] = 'growth_ci_abhtwo:3:customer:44';
g4Reject(function () use ($bad) {
    Builder::encode('PSCustomer', [$bad], 'marketing_cloud_growth', 'test');
}, 'g4_pst_invalid_typed_pk');
g4Reject(function () use ($customer) {
    Builder::encode('PSCustomer', [$customer, $customer], 'marketing_cloud_growth', 'test');
}, 'g4_pst_duplicate_typed_pk');
$bad = $customer;
$bad['email'] = 'not-an-email';
g4Reject(function () use ($bad) {
    Builder::encode('PSCustomer', [$bad], 'marketing_cloud_growth', 'test');
}, 'g4_pst_invalid_email');
$bad = $customer;
$bad['source_updated_local'] = '2026-02-31 14:00:00';
g4Reject(function () use ($bad) {
    Builder::encode('PSCustomer', [$bad], 'marketing_cloud_growth', 'test');
}, 'g4_pst_invalid_local_date');

$bad = $order;
$bad['customer_source_key'] = 'growth_ci_abhtwo:3:customer:44';
g4Reject(function () use ($bad) {
    Builder::encode('PSOrder', [$bad], 'marketing_cloud_growth', 'test');
}, 'g4_pst_cross_scope_fk');
$bad = $order;
$bad['cart_source_key'] = 'growth_ci_abhtwo:2:customer:91';
g4Reject(function () use ($bad) {
    Builder::encode('PSOrder', [$bad], 'marketing_cloud_growth', 'test');
}, 'g4_pst_cross_scope_fk');
$bad = $order;
$bad['currency'] = 'eur';
g4Reject(function () use ($bad) {
    Builder::encode('PSOrder', [$bad], 'marketing_cloud_growth', 'test');
}, 'g4_pst_invalid_order_values');
$bad = $order;
$bad['paid_flag'] = true;
g4Reject(function () use ($bad) {
    Builder::encode('PSOrder', [$bad], 'marketing_cloud_growth', 'test');
}, 'g4_pst_paid_state_unverified');
$bad = $order;
$bad['total_paid'] = -1.0;
g4Reject(function () use ($bad) {
    Builder::encode('PSOrder', [$bad], 'marketing_cloud_growth', 'test');
}, 'g4_pst_invalid_order_values');

$module = file_get_contents(dirname(__DIR__) . '/pssalesforcebridge/pssalesforcebridge.php');
$source = file_get_contents(dirname(__DIR__)
    . '/pssalesforcebridge/src/DataCloud/GrowthTypedPstPayloadBuilder.php');
g4Assert(strpos($module, 'GrowthTypedPstPayloadBuilder') === false,
    'future_builder_wired_to_live_hooks');
foreach (['curl_', 'file_get_contents(', 'Db::', 'Configuration::',
    '->execute(', '->insert(', '/api/v1/ingest/'] as $operation) {
    g4Assert(strpos($source, $operation) === false,
        'builder_performs_io_' . $operation);
}
echo "G4 typed PST payload contract PASS: v0.4 typed FK/PK, negative cases, blocked Test only.\n";
