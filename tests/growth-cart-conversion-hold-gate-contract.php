<?php
/** G3/G7 Growth cart conversion hold: TWO real-shaped local reads, no send. */
require_once dirname(__DIR__) . '/pssalesforcebridge/src/Growth/LocalOrderPresenceReader.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/Growth/GrowthCartConversionHoldGate.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthCartConversionHoldGate as Hold;

function cartHoldAssert($condition, $why)
{
    if (!$condition) {
        fwrite(STDERR, 'Growth cart hold FAIL: ' . $why . PHP_EOL);
        exit(1);
    }
}
function cartHoldReject($callback, $code)
{
    try {
        $callback();
    } catch (RuntimeException $e) {
        cartHoldAssert($e->getMessage() === $code,
            'wrong rejection: ' . $e->getMessage() . ' instead of ' . $code);
        return;
    }
    cartHoldAssert(false, 'unsafe accepted: ' . $code);
}
function cartHoldSignals()
{
    return [
        'provider' => 'marketing_cloud_growth',
        'mode' => 'test',
        'installation_shop_scope' => 'verified',
        'cart_status' => 'abandoned',
        'snapshot_current' => true,
        'cart_has_products' => true,
        'abandonment_window' => 'elapsed',
        'current_customer_state' => 'active',
        'contact_matches_snapshot' => true,
        'growth_consent_for_purpose' => 'verified',
        'growth_suppression' => 'clear',
        'frequency_cap' => 'available',
    ];
}
function cartHoldRun($passes, $signals = null)
{
    $calls = 0;
    $queries = [];
    $select = function ($sql) use ($passes, &$calls, &$queries) {
        $round = (int) floor($calls / 2);
        $which = $calls % 2;
        ++$calls;
        $queries[] = $sql;
        cartHoldAssert(strpos($sql, 'SELECT `id_shop`, `id_cart`, `id_order` FROM `ps_ci_') === 0
            && strpos($sql, ' WHERE `id_shop` = 2 AND `id_cart` = 93 ') !== false
            && substr($sql, -31) === 'ORDER BY `id_order` ASC LIMIT 1',
            'unscoped or unsafe SELECT');
        cartHoldAssert(($which === 0 && strpos($sql, '`ps_ci_orders`') !== false)
            || ($which === 1 && strpos($sql, '`ps_ci_sfmc_order_sync`') !== false),
            'source order changed');
        return $passes[$round][$which];
    };
    $result = Hold::inspect(2, 93, $select, 'ps_ci_',
        $signals === null ? cartHoldSignals() : $signals);
    cartHoldAssert($calls === 4 && count($queries) === 4,
        'missing independent conversion recheck');
    foreach ([
        'source_assertions_certified_for_bc',
        'installation_namespace_globally_certified',
        'race_with_future_dispatch_closed',
        'consent_certified_for_bc', 'cart_stream_approved',
        'growth_flow_authorized', 'recovery_link_creation_authorized',
        'data_cloud_ingestion_authorized', 'marketing_sends_authorized',
    ] as $flag) {
        cartHoldAssert(array_key_exists($flag, $result) && $result[$flag] === false,
            'unexpected permission: ' . $flag);
    }
    cartHoldAssert(!isset($result['email'], $result['cart_id'], $result['id_order']),
        'personal or source identity returned');
    cartHoldAssert(strpos(json_encode($result), 'synthetic@fixture.example.invalid') === false,
        'leaked address');
    return $result;
}

$row = ['id_shop' => '2', 'id_cart' => '93', 'id_order' => '777'];
$clear = cartHoldRun([[[], []], [[], []]]);
cartHoldAssert($clear['status'] === 'local_cart_candidate_review_only'
    && $clear['reasons'] === []
    && $clear['purchase_found_locally'] === false
    && $clear['purchase_state_unknown'] === false
    && $clear['native_order_states'] === ['none', 'none']
    && $clear['connector_order_states'] === ['none', 'none'],
    'two empty rounds were not provisional-only');

foreach ([
    [[[$row], []], [[], []]],
    [[[], [$row]], [[], []]],
    [[[], []], [[$row], []]],
    [[[], []], [[], [$row]]],
    [[false, []], [[], [$row]]],
] as $case) {
    $r = cartHoldRun($case);
    cartHoldAssert($r['status'] === 'hold_for_review'
        && $r['purchase_found_locally'] === true
        && in_array('purchase_found_in_local_source', $r['reasons'], true),
        'converted cart incorrectly treated as abandoned');
}
foreach ([
    [[false, []], [[], []]],
    [[[], false], [[], []]],
    [[[], []], [false, []]],
    [[[], []], [[], false]],
    [[[], []], [[], [['id_shop'=>3, 'id_cart'=>93, 'id_order'=>777]]]],
    [[[], []], [[], [['id_shop'=>2, 'id_cart'=>93, 'id_order'=>777,
                       'email'=>'PRIVATE_ADDRESS']]]],
] as $case) {
    $r = cartHoldRun($case);
    cartHoldAssert($r['status'] === 'hold_for_review'
        && $r['purchase_state_unknown'] === true
        && in_array('purchase_state_unverified', $r['reasons'], true),
        'missing/corrupt/cross-scope source silently accepted');
    cartHoldAssert(strpos(json_encode($r), 'PRIVATE_ADDRESS') === false,
        'query data leaked into report');
}

foreach ([
    ['installation_shop_scope', 'cross_scope', 'installation_scope_unverified'],
    ['cart_status', 'active', 'cart_not_abandoned'],
    ['cart_status', 'converted', 'cart_not_abandoned'],
    ['snapshot_current', false, 'cart_snapshot_unverified'],
    ['cart_has_products', false, 'cart_empty'],
    ['abandonment_window', 'unknown', 'abandonment_window_unverified'],
    ['current_customer_state', 'deleted', 'customer_state_unverified'],
    ['contact_matches_snapshot', false, 'contact_state_unverified'],
    ['growth_consent_for_purpose', 'denied', 'purpose_consent_unverified'],
    ['growth_suppression', 'suppressed', 'suppression_unverified'],
    ['frequency_cap', 'exceeded', 'frequency_unverified'],
] as $case) {
    $signals = cartHoldSignals();
    $signals[$case[0]] = $case[1];
    $r = cartHoldRun([[[], []], [[], []]], $signals);
    cartHoldAssert($r['status'] === 'hold_for_review'
        && in_array($case[2], $r['reasons'], true),
        'unsafe signal did not cause hold: ' . $case[0]);
}
$calls = 0;
$spy = function () use (&$calls) { ++$calls; return []; };
foreach ([
    ['email', 'synthetic@example.invalid'],
    ['source_namespace', 'unverified_tenant'],
] as $extra) {
    $signals = cartHoldSignals();
    $signals[$extra[0]] = $extra[1];
    cartHoldReject(function () use ($signals, $spy) {
        Hold::inspect(2, 93, $spy, 'ps_ci_', $signals);
    }, 'growth_cart_signal_shape_invalid');
}
$invalid = cartHoldSignals();
$invalid['provider'] = 'unsupported_external_product';
cartHoldReject(function () use ($invalid, $spy) {
    Hold::inspect(2, 93, $spy, 'ps_ci_', $invalid);
}, 'growth_cart_signal_value_invalid');
$invalid = cartHoldSignals();
$invalid['mode'] = 'production';
cartHoldReject(function () use ($invalid, $spy) {
    Hold::inspect(2, 93, $spy, 'ps_ci_', $invalid);
}, 'growth_cart_signal_value_invalid');
$invalid = cartHoldSignals();
$invalid['growth_consent_for_purpose'] = 1;
cartHoldReject(function () use ($invalid, $spy) {
    Hold::inspect(2, 93, $spy, 'ps_ci_', $invalid);
}, 'growth_cart_signal_value_invalid');
$invalid = cartHoldSignals();
$invalid['snapshot_current'] = 1;
cartHoldReject(function () use ($invalid, $spy) {
    Hold::inspect(2, 93, $spy, 'ps_ci_', $invalid);
}, 'growth_cart_signal_boolean_invalid');
cartHoldAssert($calls === 0, 'unverified evidence reached local SQL');

foreach ([0, -2, '2', true] as $shop) {
    cartHoldReject(function () use ($shop, $spy) {
        Hold::inspect($shop, 93, $spy, 'ps_ci_', cartHoldSignals());
    }, 'growth_order_scope_invalid');
}
$module = file_get_contents(dirname(__DIR__) . '/pssalesforcebridge/pssalesforcebridge.php');
$source = file_get_contents(dirname(__DIR__)
    . '/pssalesforcebridge/src/Growth/GrowthCartConversionHoldGate.php');
cartHoldAssert(strpos($module, 'GrowthCartConversionHoldGate') === false,
    'not-yet-approved gate was activated in module');
foreach (['curl_', 'file_get_contents(', 'Db::getInstance()->execute(', '->execute(',
    '->insert(', '->enqueue(', '->issueUrl(', '/api/v1/ingest/'] as $danger) {
    cartHoldAssert(strpos($source, $danger) === false,
        'unsafe side effect in gate: ' . $danger);
}
// The only permitted Db call is the explicit uncached read-only adapter.
// All actual decision logic remains injectable and side-effect-free.
cartHoldAssert(substr_count($source, 'Db::getInstance()->executeS($sql, true, false)') === 3,
    'native_adapter_must_use_fresh_read_only_select');
echo "Growth cart conversion double-check PASS: conversion/unknown always hold; no sends.\n";
