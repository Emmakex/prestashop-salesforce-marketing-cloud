<?php
/** G3 source-order tri-state contract: purely synthetic, PHP 7.1+. */
require_once __DIR__ . '/review/G3GrowthOrderEvidenceInterpreter.php';
require_once __DIR__ . '/review/G3GrowthCartSourcePresendReview.php';

function g3OrderAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, 'G3 order existence: ' . $message . PHP_EOL);
        exit(1);
    }
}
function g3OrderInspect($native, $ledger)
{
    $result = G3GrowthOrderEvidenceInterpreter::inspect(1, 33, $native, $ledger);
    foreach (['absence_certified_in_live_prestashop', 'growth_consent_certified',
        'data_360_ingestion_authorized', 'marketing_sends_authorized'] as $key) {
        g3OrderAssert(isset($result[$key]) && $result[$key] === false,
            'unauthorized downstream capability ' . $key);
    }
    return $result;
}
function g3OrderPresend($result)
{
    $evidence = [
        'provider' => 'marketing_cloud_growth',
        'runtime_mode' => 'test',
        'installation_shop_scope' => 'verified',
        'cart_status' => 'abandoned',
        'snapshot_current' => true,
        'cart_has_products' => true,
        'abandonment_window' => 'elapsed',
        'native_order_state' => $result['native_order_state'],
        'connector_order_state' => $result['connector_order_state'],
        'current_customer_state' => 'active',
        'contact_matches_snapshot' => true,
        'growth_consent_for_purpose' => 'verified',
        'growth_suppression' => 'clear',
        'frequency_cap' => 'available',
    ];
    return G3GrowthCartSourcePresendReview::inspect($evidence);
}
function g3OrderRejectScope($shop, $cart)
{
    try {
        G3GrowthOrderEvidenceInterpreter::inspect($shop, $cart, [], []);
    } catch (RuntimeException $e) {
        g3OrderAssert($e->getMessage() === 'g3_order_probe_scope_invalid',
            'unexpected scope rejection code');
        return;
    }
    g3OrderAssert(false, 'invalid scope accepted');
}

$row = ['id_shop' => '1', 'id_cart' => '33', 'id_order' => '77'];
$none = g3OrderInspect([], []);
g3OrderAssert($none['status'] === 'no_order_candidate_only',
    'two successful empty SELECTs should be source candidates only');
g3OrderAssert(g3OrderPresend($none)['status'] === 'local_source_candidate_only',
    'synthetic no-order evidence should only reach local candidate');

$foundNative = g3OrderInspect([$row], []);
$foundLedger = g3OrderInspect([], [$row]);
foreach ([$foundNative, $foundLedger, g3OrderInspect([$row], false)] as $result) {
    g3OrderAssert($result['status'] === 'converted_locally',
        'an existing order must suppress regardless of other source');
    g3OrderAssert(g3OrderPresend($result)['status'] === 'hold_for_review',
        'converted cart incorrectly allowed as Growth candidate');
}

$malformedRows = [
    false, null, 0, true, '0', 'not_a_result',
    [['id_order' => '77']],
    [['id_shop' => '2', 'id_cart' => '33', 'id_order' => '77']],
    [['id_shop' => '1', 'id_cart' => '34', 'id_order' => '77']],
    [['id_shop' => '1', 'id_cart' => '33', 'id_order' => '0']],
    [['id_shop' => '1', 'id_cart' => '33', 'id_order' => '-1']],
    [['id_shop' => '1', 'id_cart' => '33', 'id_order' => '1.0']],
    [['id_shop' => '1', 'id_cart' => '33', 'id_order' => '922337203685477580800000']],
    [['id_shop' => '1', 'id_cart' => '33', 'id_order' => '77', 'email' => 'synthetic@example.invalid']],
    [$row, $row],
    ['id_shop' => '1', 'id_cart' => '33', 'id_order' => '77'],
];
foreach ($malformedRows as $index => $raw) {
    foreach ([true, false] as $nativeSide) {
        $result = $nativeSide ? g3OrderInspect($raw, []) : g3OrderInspect([], $raw);
        g3OrderAssert($result['status'] === 'unknown_hold',
            'failed or malformed SELECT classified as absent at ' . $index);
        g3OrderAssert(g3OrderPresend($result)['status'] === 'hold_for_review',
            'unknown order result allowed to pass at ' . $index);
        g3OrderAssert(strpos(json_encode($result), '77') === false,
            'returned raw order identifier');
    }
}
foreach ([[0, 33], [-1, 33], [1, 0], [1, -1], ['1', 33], [1, '33']] as $ids) {
    g3OrderRejectScope($ids[0], $ids[1]);
}
$src = file_get_contents(__DIR__ . '/review/G3GrowthOrderEvidenceInterpreter.php');
foreach (['curl_', '->execute(', '->query(', '->enqueue(', '->fire(',
    'file_get_contents(', '/interaction/v1/events', '/api/v1/ingest/'] as $fragment) {
    g3OrderAssert(strpos($src, $fragment) === false,
        'tri-state interpreter contains outbound or database operation');
}
echo "G3 Growth tri-state order evidence: errors/false never mean no order; no sender.\n";
