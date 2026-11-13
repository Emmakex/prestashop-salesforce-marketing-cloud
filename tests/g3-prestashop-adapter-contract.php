<?php
/** Offline, PHP 7.1-compatible PrestaShop family/provider gate: no database. */
require_once __DIR__ . '/integration/G3PrestaShopAdapterSandbox.php';

function g3_adapter_check($pass, $reason)
{
    if (!$pass) {
        fwrite(STDERR, 'G3 adapter preflight failed: ' . $reason . "\n");
        exit(1);
    }
}
function g3_adapter_reject($callback, $expected)
{
    try {
        call_user_func($callback);
    } catch (RuntimeException $e) {
        g3_adapter_check($e->getMessage() === $expected, $expected);
        return;
    }
    g3_adapter_check(false, 'unsafe_configuration_accepted_' . $expected);
}

foreach (['1.7.8.11' => '1.7.8', '8.2.8' => '8', '9.1.5' => '9'] as $version => $family) {
    $plan = G3PrestaShopAdapterSandbox::preflight(
        $version, 'ABH', 'marketing_cloud_growth', 'growth_ci_abhtest', 'test'
    );
    g3_adapter_check($plan['prestashop_family'] === $family
        && $plan['growth_schema_install_supported'] === true
        && $plan['runtime_certified'] === false
        && $plan['status'] === 'blocked'
        && $plan['schema_publication_authorized'] === false
        && $plan['data_360_ingestion_authorized'] === false
        && $plan['marketing_sends_authorized'] === false
        && $plan['outbound_worker_authorized'] === false, 'family_' . $family);
}
foreach (['1.7.7.9', '1.7.9.0', '7.9.9', '10.0.0', '9.1', '9.1.5-alpha', '9.1.5 OR 1=1'] as $invalid) {
    g3_adapter_reject(function () use ($invalid) {
        G3PrestaShopAdapterSandbox::preflight(
            $invalid, 'ABH', 'marketing_cloud_growth', 'growth_ci_abhtest', 'test'
        );
    }, 'g3_adapter_unsupported_ps_version');
}
g3_adapter_reject(function () {
    G3PrestaShopAdapterSandbox::preflight('8.2.8', 'ABH', '', 'growth_ci_abhtest', 'test');
}, 'g3_adapter_explicit_provider_required');
g3_adapter_reject(function () {
    G3PrestaShopAdapterSandbox::preflight('8.2.8', 'ABH', 'marketing_cloud_engagement',
        'growth_ci_abhtest', 'test');
}, 'g3_adapter_abh_growth_only');
g3_adapter_reject(function () {
    G3PrestaShopAdapterSandbox::preflight('8.2.8', 'ABH', 'marketing_cloud_growth',
        'growth_ci_abhtest', 'production');
}, 'g3_adapter_test_only');
g3_adapter_reject(function () {
    G3PrestaShopAdapterSandbox::preflight('8.2.8', 'ABH', 'marketing_cloud_growth',
        'ps_1', 'test');
}, 'g3_adapter_namespace_required');
g3_adapter_reject(function () {
    G3PrestaShopAdapterSandbox::preflight('8.2.8', '??', 'marketing_cloud_growth',
        'growth_ci_abhtest', 'test');
}, 'g3_adapter_merchant_required');

$legacy = G3PrestaShopAdapterSandbox::preflight(
    '8.2.8', 'OTHER_MERCHANT', 'marketing_cloud_engagement', 'growth_ci_other', 'test'
);
g3_adapter_check($legacy['growth_schema_install_supported'] === false
    && $legacy['outbound_worker_authorized'] === false
    && $legacy['marketing_sends_authorized'] === false, 'engagement_must_remain_separate');

$src = file_get_contents(__DIR__ . '/integration/G3PrestaShopAdapterSandbox.php');
g3_adapter_check(strpos($src, 'curl_') === false
    && strpos($src, '/interaction/v1/events') === false
    && strpos($src, 'requestAccessToken(') === false
    && strpos($src, 'pssalesforcebridge.php') === false, 'candidate_must_not_send_or_wire_module');
fwrite(STDOUT, "G3 PrestaShop 1.7/8/9 provider preflight PASS (offline; no send).\n");
