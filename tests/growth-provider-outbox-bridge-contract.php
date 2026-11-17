<?php
/** Real-module Growth selector->atomic outbox contract (synthetic, no DB). */
require_once dirname(__DIR__) . '/pssalesforcebridge/src/Provider/ExplicitProviderRouter.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/LiveEventUtcObservation.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/StagedAtomicUtcAcceptance.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/Growth/BlockedOutboxCoordinator.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Provider\ExplicitProviderRouter;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\BlockedOutboxCoordinator;

function outboxBridgeAssert($good, $reason)
{
    if (!$good) {
        fwrite(STDERR, "Growth bridge contract failed: " . $reason . PHP_EOL);
        exit(1);
    }
}
function outboxBridgeReject($callback, $expected)
{
    try {
        call_user_func($callback);
    } catch (RuntimeException $e) {
        outboxBridgeAssert($e->getMessage() === $expected, 'wrong_error_' . $expected);
        return;
    }
    outboxBridgeAssert(false, 'unsafe_accepted_' . $expected);
}
function outboxBridgeRouter($namespace, $merchant = 'ABH', $provider = 'marketing_cloud_growth')
{
    return new ExplicitProviderRouter('8.2.8', $merchant, $provider, $namespace, 'test');
}
$rows = [];
$seq = 0;
$factoryCalls = 0;
$store = function (array $identity, $factory) use (&$rows, &$seq, &$factoryCalls) {
    $key = $identity['source_namespace'] . ':' . $identity['dedupe_fingerprint'];
    if (isset($rows[$key])) {
        return ['row' => $rows[$key], 'inserted' => false];
    }
    ++$factoryCalls;
    $row = $factory();
    $row['accepted_sequence'] = ++$seq;
    $rows[$key] = $row;
    return ['row' => $row, 'inserted' => true];
};
$shaA = str_repeat('a', 40);
$shaB = str_repeat('b', 40);
$base = outboxBridgeRouter('growth_ci_shop_a');
$results = [];
$results[] = BlockedOutboxCoordinator::capture(
    $base, 1, 'customer', 71, 'CUSTOMER_CREATED',
    'customer:1:71:' . $shaA, $store
);
$results[] = BlockedOutboxCoordinator::capture(
    $base, 1, 'customer', 71, 'CUSTOMER_UPDATED',
    'customer:1:71:' . $shaA, $store
);
$results[] = BlockedOutboxCoordinator::capture(
    $base, 1, 'customer', 71, 'CUSTOMER_UPDATED',
    'customer:1:71:' . $shaB, $store
);
$results[] = BlockedOutboxCoordinator::capture(
    $base, 1, 'order', 71, 'ORDER_PAID',
    'order:1:71:' . $shaA, $store
);
$results[] = BlockedOutboxCoordinator::capture(
    $base, 2, 'customer', 71, 'CUSTOMER_CREATED',
    'customer:2:71:' . $shaA, $store
);
$results[] = BlockedOutboxCoordinator::capture(
    outboxBridgeRouter('growth_ci_shop_b'), 1, 'customer', 71, 'CUSTOMER_CREATED',
    'customer:1:71:' . $shaA, $store
);
outboxBridgeAssert($results[0]['inserted'] === true
    && $results[0]['accepted_sequence'] === 1
    && $results[1]['inserted'] === false
    && $results[1]['accepted_sequence'] === 1
    && $results[1]['captured_at_reused'] === true
    && $results[1]['captured_at_utc'] === $results[0]['captured_at_utc'],
    'retry_retimestamp_or_duplicate');
outboxBridgeAssert($results[2]['accepted_sequence'] === 2
    && $results[3]['accepted_sequence'] === 3
    && $results[4]['accepted_sequence'] === 4
    && $results[5]['accepted_sequence'] === 5
    && count($rows) === 5 && $factoryCalls === 5, 'tenant_shop_entity_collision');
foreach ($results as $r) {
    outboxBridgeAssert($r['status'] === 'blocked_local_only'
        && $r['provider'] === 'marketing_cloud_growth'
        && $r['evidence_type'] === 'local_atomic_growth_outbox_candidate',
        'unapproved_capture_mode');
    foreach (['source_revision_verified', 'namespace_global_uniqueness_certified',
        'pscart_schema_approved', 'data_360_ingestion_authorized',
        'growth_flow_authorized', 'marketing_sends_authorized',
        'outbound_worker_authorized'] as $key) {
        outboxBridgeAssert(isset($r[$key]) && $r[$key] === false,
            'outbound_flag_' . $key);
    }
    outboxBridgeAssert(!isset($r['row'], $r['source_entity_key'], $r['dedupe_fingerprint'])
        && strpos(json_encode($r), '71') === false,
        'outbox_pii_or_source_identity_exposed');
}

// No injection of an RetiredProvider merchant, cart, malformed dedupe,
// unknown source event, or broken store can ever persist.
$calls = 0;
$spy = function () use (&$calls) { ++$calls; return []; };
outboxBridgeReject(function () {
    outboxBridgeRouter('other_ci_shop', 'OTHER', 'unsupported_external_product');
}, 'growth_router_growth_only');
outboxBridgeReject(function () use ($base, $spy, $shaA) {
    BlockedOutboxCoordinator::capture(
        $base, 1, 'cart', 71, 'CART_ABANDONED', 'cart:1:71:' . $shaA, $spy);
}, 'growth_outbox_cart_schema_unapproved');
outboxBridgeReject(function () use ($base, $spy, $shaA) {
    BlockedOutboxCoordinator::capture(
        $base, 1, 'customer', 71, 'ORDER_PAID', 'customer:1:71:' . $shaA, $spy);
}, 'g3_event_type_invalid');
outboxBridgeReject(function () use ($base, $spy) {
    BlockedOutboxCoordinator::capture(
        $base, 1, 'customer', 71, 'CUSTOMER_CREATED',
        'customer:1:71:nonhash', $spy);
}, 'g3_legacy_dedupe_key_invalid');
outboxBridgeReject(function () use ($base, $spy, $shaA) {
    BlockedOutboxCoordinator::capture(
        $base, 2, 'customer', 71, 'CUSTOMER_CREATED',
        'customer:1:71:' . $shaA, $spy);
}, 'g3_legacy_dedupe_key_invalid');
outboxBridgeReject(function () use ($base, $shaA) {
    BlockedOutboxCoordinator::capture(
        $base, 1, 'customer', 71, 'CUSTOMER_CREATED',
        'customer:1:71:' . $shaA, null);
}, 'growth_outbox_atomic_store_required');
outboxBridgeAssert($calls === 0, 'invalid_data_reached_store');

// Protect against forged/malformed storage results, even from a callback.
$malicious = function ($identity, $factory) {
    $row = $factory();
    $row['accepted_sequence'] = 1;
    $row['email'] = 'synthetic@example.invalid';
    return ['row' => $row, 'inserted' => true];
};
outboxBridgeReject(function () use ($base, $shaA, $malicious) {
    BlockedOutboxCoordinator::capture(
        $base, 1, 'customer', 71, 'CUSTOMER_CREATED',
        'customer:1:71:' . $shaA, $malicious);
}, 'g3_store_response_fields_invalid');
foreach ([
    'curl_', 'Db::', 'Configuration::', '->execute(',
    '->enqueue(', '->insert(', '->update(', 'requestAccessToken(',
    '/interaction/v1/events', '/api/v1/ingest/'
] as $bad) {
    $code = file_get_contents(dirname(__DIR__)
        . '/pssalesforcebridge/src/Growth/BlockedOutboxCoordinator.php');
    outboxBridgeAssert(strpos($code, $bad) === false, 'outbound_or_sql_in_bridge_' . $bad);
}
$module = file_get_contents(dirname(__DIR__) . '/pssalesforcebridge/pssalesforcebridge.php');
outboxBridgeAssert(strpos($module, 'GrowthStagingHookCapture::captureFromShop') !== false
    && strpos($module, "CONFIG_LOCAL_CAPTURE") !== false,
    'module_must_default_to_capture_off');
echo "Growth provider->atomic outbox: offline idempotency and fail-closed contracts PASS.\n";
