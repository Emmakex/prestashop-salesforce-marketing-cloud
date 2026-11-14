<?php
/** G3-08/G7 synthetic local cart recovery pre-send decision tests. */
require_once __DIR__ . '/review/G3GrowthCartSourcePresendReview.php';

function expectG3Cart($yes, $message)
{
    if (!$yes) {
        fwrite(STDERR, 'G3 Growth cart review: ' . $message . PHP_EOL);
        exit(1);
    }
}
function rejectG3Cart($record, $reason)
{
    try {
        G3GrowthCartSourcePresendReview::inspect($record);
    } catch (RuntimeException $e) {
        expectG3Cart($e->getMessage() === $reason, 'unexpected rejection');
        return;
    }
    expectG3Cart(false, 'invalid input was accepted');
}
function nonAuthorizingG3Cart($result)
{
    foreach ([
        'cart_stream_approved', 'growth_flow_certified_for_abh',
        'consent_certified_for_abh', 'recovery_link_creation_authorized',
        'data_360_ingestion_authorized', 'marketing_sends_authorized',
    ] as $key) {
        expectG3Cart(array_key_exists($key, $result) && $result[$key] === false,
            'illegal authorization ' . $key);
    }
    expectG3Cart($result['evidence_type'] === 'synthetic_assertions_not_live_checks',
        'synthetic state represented as production evidence');
}

$base = [
    'provider' => 'marketing_cloud_growth',
    'runtime_mode' => 'test',
    'installation_shop_scope' => 'verified',
    'cart_status' => 'abandoned',
    'snapshot_current' => true,
    'cart_has_products' => true,
    'abandonment_window' => 'elapsed',
    'native_order_state' => 'none',
    'connector_order_state' => 'none',
    'current_customer_state' => 'active',
    'contact_matches_snapshot' => true,
    'growth_consent_for_purpose' => 'verified',
    'growth_suppression' => 'clear',
    'frequency_cap' => 'available',
];
// A synthetically perfect case is only a SOURCE candidate, never a send.
$clear = G3GrowthCartSourcePresendReview::inspect($base);
expectG3Cart($clear['status'] === 'local_source_candidate_only' && $clear['reasons'] === [],
    'safe synthetic source candidate unexpectedly held');
nonAuthorizingG3Cart($clear);

$cases = [
    ['provider', 'marketing_cloud_engagement', 'provider_not_growth'],
    ['runtime_mode', 'production', 'runtime_not_test'],
    ['installation_shop_scope', 'cross_scope', 'scope_not_verified'],
    ['installation_shop_scope', 'unknown', 'scope_not_verified'],
    ['cart_status', 'converted', 'cart_not_abandoned'],
    ['cart_status', 'active', 'cart_not_abandoned'],
    ['snapshot_current', false, 'cart_snapshot_stale_or_unknown'],
    ['cart_has_products', false, 'cart_empty'],
    ['abandonment_window', 'not_elapsed', 'inactivity_window_not_verified'],
    ['abandonment_window', 'unknown', 'inactivity_window_not_verified'],
    ['native_order_state', 'found', 'native_order_found_or_unknown'],
    ['native_order_state', 'unknown', 'native_order_found_or_unknown'],
    ['connector_order_state', 'found', 'connector_order_found_or_unknown'],
    ['connector_order_state', 'unknown', 'connector_order_found_or_unknown'],
    ['current_customer_state', 'inactive', 'current_customer_not_active'],
    ['current_customer_state', 'deleted', 'current_customer_not_active'],
    ['current_customer_state', 'unknown', 'current_customer_not_active'],
    ['contact_matches_snapshot', false, 'customer_contact_changed_or_unknown'],
    ['growth_consent_for_purpose', 'denied', 'growth_purpose_consent_not_verified'],
    ['growth_consent_for_purpose', 'unknown', 'growth_purpose_consent_not_verified'],
    ['growth_suppression', 'suppressed', 'growth_suppression_not_clear'],
    ['growth_suppression', 'unknown', 'growth_suppression_not_clear'],
    ['frequency_cap', 'exceeded', 'frequency_cap_not_verified'],
    ['frequency_cap', 'unknown', 'frequency_cap_not_verified'],
];
foreach ($cases as $case) {
    $input = $base;
    $input[$case[0]] = $case[1];
    $result = G3GrowthCartSourcePresendReview::inspect($input);
    expectG3Cart($result['status'] === 'hold_for_review'
        && in_array($case[2], $result['reasons'], true), 'did not suppress ' . $case[2]);
    nonAuthorizingG3Cart($result);
}
$combined = $base;
$combined['native_order_state'] = 'unknown';
$combined['growth_suppression'] = 'suppressed';
$combined['snapshot_current'] = false;
$r = G3GrowthCartSourcePresendReview::inspect($combined);
expectG3Cart(count($r['reasons']) === 3, 'simultaneous suppression reasons lost');
nonAuthorizingG3Cart($r);

$extra = $base;
$extra['email'] = 'synthetic@example.invalid';
rejectG3Cart($extra, 'g3_growth_cart_evidence_shape_invalid');
$extra = $base;
unset($extra['growth_consent_for_purpose']);
rejectG3Cart($extra, 'g3_growth_cart_evidence_shape_invalid');
$invalid = $base;
$invalid['native_order_state'] = 'not_checked';
rejectG3Cart($invalid, 'g3_growth_cart_evidence_enum_invalid');
$invalid = $base;
$invalid['snapshot_current'] = 1;
rejectG3Cart($invalid, 'g3_growth_cart_evidence_boolean_invalid');

$src = file_get_contents(__DIR__ . '/review/G3GrowthCartSourcePresendReview.php');
foreach (['curl_', 'Db::', 'PDO', 'file_get_contents(', '->fire(',
    '->issueUrl(', '->enqueue(', '/interaction/v1/events', '/api/v1/ingest/'] as $fragment) {
    expectG3Cart(strpos($src, $fragment) === false,
        'candidate module unexpectedly contains an outbound side effect');
}
echo "G3 Growth cart source presend: 24 negative scenarios, zero sends or writes.\n";
