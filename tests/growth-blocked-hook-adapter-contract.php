<?php
/** Real dormant Growth hook adapter -> local blocked outbox, no shop/SQL. */
$root = dirname(__DIR__) . '/pssalesforcebridge/src/';
foreach ([
    'Compatibility/CustomerHookAdapter.php',
    'Compatibility/OrderHookAdapter.php',
    'Provider/ExplicitProviderRouter.php',
    'DataCloud/LiveEventUtcObservation.php',
    'DataCloud/StagedAtomicUtcAcceptance.php',
    'Growth/BlockedOutboxCoordinator.php',
    'Growth/GrowthBlockedHookAdapter.php',
] as $path) {
    require_once $root . $path;
}

use Emmakex\PrestaShopSalesforceMarketingCloud\Provider\ExplicitProviderRouter as Router;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthBlockedHookAdapter as Hook;

function blockedHookAssert($ok, $reason)
{
    if (!$ok) {
        fwrite(STDERR, 'Growth hook adapter: ' . $reason . PHP_EOL);
        exit(1);
    }
}

function blockedHookReject($fn, $expected)
{
    try {
        call_user_func($fn);
    } catch (RuntimeException $e) {
        blockedHookAssert($e->getMessage() === $expected,
            'wrong_denial_' . $expected . '_' . $e->getMessage());
        return;
    }
    blockedHookAssert(false, 'invalid_accepted_' . $expected);
}

$storeCalls = 0;
$sequences = 0;
$rows = [];
$store = function (array $identity, $factory) use (&$storeCalls, &$sequences, &$rows) {
    ++$storeCalls;
    $key = $identity['source_namespace'] . ':' . $identity['dedupe_fingerprint'];
    if (isset($rows[$key])) {
        return ['row' => $rows[$key], 'inserted' => false];
    }
    $row = $factory();
    $row['accepted_sequence'] = ++$sequences;
    $rows[$key] = $row;
    return ['row' => $row, 'inserted' => true];
};
$router = new Router('8.2.8', 'ABH', Router::GROWTH,
    'growth_ci_hooks_a', 'test');
$otherShop = new Router('8.2.8', 'OTHER', Router::GROWTH,
    'growth_ci_hooks_b', 'test');

$privateCustomer = (object) [
    'id' => '42', 'id_shop' => '1',
    'email' => 'never-include@example.invalid',
    'firstname' => 'PRIVATE_GIVEN', 'lastname' => 'PRIVATE_FAMILY',
    'newsletter' => 1, 'optin' => 1,
    'date_upd' => '2026-11-14 01:30:00',
];
$order = (object) [
    'id' => 42, 'id_shop' => 1, 'id_customer' => 12,
    'id_cart' => 72, 'total_paid' => '104.00',
    'reference' => 'PRIVATE_ORDER_REF',
    'date_upd' => '2026-11-14 01:30:00',
];
$ha = str_repeat('a', 40);
$hb = str_repeat('b', 40);
$first = Hook::capture($router, 'actionCustomerAccountAdd',
    ['newCustomer' => $privateCustomer, 'credential' => 'PRIVATE_SECRET'],
    'customer:1:42:' . $ha, $store);
$retry = Hook::capture($router, 'actionCustomerAccountUpdate',
    ['customer' => $privateCustomer],
    'customer:1:42:' . $ha, $store);
$update = Hook::capture($router, 'actionCustomerAccountUpdate',
    ['customer' => $privateCustomer],
    'customer:1:42:' . $hb, $store);
$createdOrder = Hook::capture($router, 'actionValidateOrder',
    ['order' => $order, 'customer' => $privateCustomer],
    'order:1:42:' . $ha, $store);
$otherNamespace = Hook::capture($otherShop, 'actionCustomerAccountAdd',
    ['object' => $privateCustomer], 'customer:1:42:' . $ha, $store);
$otherShopCustomer = clone $privateCustomer;
$otherShopCustomer->id_shop = 2;
$otherShopEvent = Hook::capture($router, 'actionCustomerAccountAdd',
    ['customer' => $otherShopCustomer],
    'customer:2:42:' . $ha, $store);

blockedHookAssert($storeCalls === 6 && $sequences === 5
    && count($rows) === 5, 'dedupe_namespace_or_shop_collision');
blockedHookAssert($first['inserted'] === true
    && $retry['inserted'] === false
    && $retry['accepted_sequence'] === $first['accepted_sequence']
    && $retry['captured_at_reused'] === true
    && $update['inserted'] === true
    && $createdOrder['inserted'] === true
    && $otherNamespace['inserted'] === true
    && $otherShopEvent['inserted'] === true, 'idempotent_capture_or_scope');
$events = [];
foreach ($rows as $row) {
    $events[] = $row['original_event_type'];
}
sort($events);
blockedHookAssert($events === [
    'CUSTOMER_CREATED', 'CUSTOMER_CREATED', 'CUSTOMER_CREATED',
    'CUSTOMER_UPDATED', 'ORDER_CREATED'
], 'wrong_hook_event_type');

foreach ([$first, $retry, $update, $createdOrder,
          $otherNamespace, $otherShopEvent] as $result) {
    blockedHookAssert($result['status'] === 'blocked_local_only'
        && $result['provider'] === Router::GROWTH
        && $result['data_360_ingestion_authorized'] === false
        && $result['growth_flow_authorized'] === false
        && $result['marketing_sends_authorized'] === false
        && $result['outbound_worker_authorized'] === false
        && $result['pscart_schema_approved'] === false,
        'unapproved_pipeline_effect');
    $json = json_encode($result);
    foreach ([
        'never-include@example.invalid', 'PRIVATE_GIVEN',
        'PRIVATE_FAMILY', 'PRIVATE_ORDER_REF', 'PRIVATE_SECRET',
        '104.00', 'growth_ci_hooks_a', 'source_entity_key',
        'dedupe_fingerprint', 'customer_id', 'order_id'
    ] as $forbidden) {
        blockedHookAssert(strpos($json, $forbidden) === false,
            'private_value_leaked_' . $forbidden);
    }
}

$spyCalls = 0;
$spy = function () use (&$spyCalls) {
    ++$spyCalls;
    throw new RuntimeException('unsafe_store_reached');
};
$badHooks = [
    'actionOrderStatusPostUpdate',
    'actionCartSave', 'actionValidatePayment', 'actionValidateOrder ',
    'CUSTOMER_CREATED', '', null, 1,
];
foreach ($badHooks as $name) {
    blockedHookReject(function () use ($router, $name, $order, $ha, $spy) {
        Hook::capture($router, $name, ['order' => $order],
            'order:1:42:' . $ha, $spy);
    }, 'growth_hook_not_supported');
}
blockedHookReject(function () use ($router, $ha, $spy) {
    Hook::capture($router, 'actionCustomerAccountAdd', null,
        'customer:1:42:' . $ha, $spy);
}, 'growth_hook_params_invalid');
foreach ([
    ['newCustomer' => null],
    ['newCustomer' => 'not_an_object'],
    ['order' => (object)['id' => 42, 'id_shop' => 1]],
] as $params) {
    blockedHookReject(function () use ($router, $params, $ha, $spy) {
        Hook::capture($router, 'actionCustomerAccountAdd', $params,
            'customer:1:42:' . $ha, $spy);
    }, 'growth_hook_entity_missing');
}
$bad = [
    (object)['id' => 42],
    (object)['id_shop' => 1],
    (object)['id' => '00042', 'id_shop' => 1],
    (object)['id' => 42, 'id_shop' => 0],
    (object)['id' => 42, 'id_shop' => '00'],
    (object)['id' => 42, 'id_shop' => '1e0'],
    (object)['id' => 42, 'id_shop' => 1.0],
    (object)['id' => '-42', 'id_shop' => 1],
    (object)['id' => true, 'id_shop' => 1],
    (object)['id' => '922337203685477580800000', 'id_shop' => 1],
];
foreach ($bad as $source) {
    blockedHookReject(function () use ($router, $source, $ha, $spy) {
        Hook::capture($router, 'actionValidateOrder', ['order' => $source],
            'order:1:42:' . $ha, $spy);
    }, 'growth_hook_explicit_scope_required');
}
blockedHookReject(function () use ($router, $privateCustomer, $ha, $spy) {
    Hook::capture($router, 'actionCustomerAccountAdd',
        ['customer' => $privateCustomer],
        'customer:2:42:' . $ha, $spy);
}, 'g3_legacy_dedupe_key_invalid');
blockedHookReject(function () use ($router, $privateCustomer, $spy) {
    Hook::capture($router, 'actionCustomerAccountAdd',
        ['customer' => $privateCustomer],
        'customer:1:42:' . str_repeat('G', 40), $spy);
}, 'g3_legacy_dedupe_key_invalid');
$engagement = new Router('8.2.8', 'OTHER', Router::ENGAGEMENT_LEGACY,
    'growth_ci_other', 'test');
blockedHookReject(function () use ($engagement, $privateCustomer, $ha, $spy) {
    Hook::capture($engagement, 'actionCustomerAccountAdd',
        ['customer' => $privateCustomer], 'customer:1:42:' . $ha, $spy);
}, 'growth_outbox_provider_not_growth');

blockedHookAssert($spyCalls === 0, 'invalid_source_called_atomic_store');
$module = file_get_contents(dirname(__DIR__) . '/pssalesforcebridge/pssalesforcebridge.php');
blockedHookAssert(strpos($module, 'GrowthBlockedHookAdapter') === false,
    'growth_hooks_must_not_be_registered_in_legacy_module');
$source = file_get_contents($root . 'Growth/GrowthBlockedHookAdapter.php');
foreach ([
    'Db::', 'Configuration::', 'curl_', '/api/v1/ingest/',
    '/interaction/v1/events', '->enqueue(', '->execute(', '->insert('
] as $unsafe) {
    blockedHookAssert(strpos($source, $unsafe) === false,
        'adapter_has_unapproved_io_' . $unsafe);
}
echo "Growth hook->blocked outbox: exact event scope, dedupe, no PII or writes (PHP 7.1+).\n";
