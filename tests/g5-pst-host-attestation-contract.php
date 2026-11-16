<?php
/** G5 OAuth-only official PST host attestation: zero network in this test. */
require_once dirname(__DIR__) . '/tests/review/G5PstEndpointAttestation.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Review\G5PstEndpointAttestation as Attest;

function checkG5Host($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "G5 host contract FAIL: " . $message . "\n");
        exit(1);
    }
}
function rejectG5Host($fn, $code)
{
    try {
        $fn();
    } catch (\RuntimeException $e) {
        checkG5Host($e->getMessage() === $code, 'unexpected_' . $e->getMessage());
        return;
    }
    checkG5Host(false, 'unsafe_input_accepted_' . $code);
}

$mock = 'https://tenant.c360a.salesforce.com';
$mockHash = hash('sha256', $mock);
checkG5Host(Attest::compareAgainst($mock, $mockHash) === true, 'match_failure');
// The canonical URL is lowercase; uppercase host must canonicalize identically.
checkG5Host(Attest::compareAgainst('https://TENANT.c360a.salesforce.com',
    $mockHash) === true, 'case_normalization');
checkG5Host(Attest::compareAgainst($mock, str_repeat('0', 64)) === false,
    'mismatch_wrongly_passed');

$report = Attest::attest($mock);
checkG5Host($report['tenant_host_matches_official_pst_yaml'] === false,
    'unexpected_hardcoded_tenant_match');
checkG5Host($report['test_paths_verified_offline'] === true,
    'offline_path_mismatch');
checkG5Host($report['salesforce_data_post_performed'] === false,
    'outbound_post_not_false');
foreach (['PSCustomer', 'PSOrder'] as $name) {
    $path = Attest::testPath($name);
    checkG5Host($path === '/api/v1/ingest/sources/ABH_PrestaShop_Data_Cloud/'
        . $name . '/actions/test', 'wrong_path_' . $name);
}
rejectG5Host(function () {
    Attest::testPath('PSCart');
}, 'g5_host_object_not_allowed');
rejectG5Host(function () use ($mock) {
    Attest::compareAgainst($mock, 'not-sha');
}, 'g5_host_invalid_fingerprint');

foreach ([
    'http://tenant.c360a.salesforce.com',
    'https://example.com',
    'https://tenant.c360a.salesforce.com/evil',
    'https://tenant.c360a.salesforce.com:444',
    'https://tenant.c360a.salesforce.com?secret=x',
    'https://user:pw@tenant.c360a.salesforce.com',
    'https://tenant.c360a.salesforce.com#fragment',
    'localhost',
    '',
] as $invalid) {
    rejectG5Host(function () use ($invalid) {
        Attest::attest($invalid);
    }, 'g5_host_invalid_origin');
}

$live = file_get_contents(dirname(__DIR__)
    . '/tests/live/g5-pst-host-diagnostic-only.php');
$workflow = file_get_contents(dirname(__DIR__)
    . '/.github/workflows/g5-pst-actions-test-only.yml');
checkG5Host(strpos($live, 'G5PstEndpointAttestation::attest') !== false,
    'missing_live_attestation');
checkG5Host(strpos($live, 'GrowthTypedPstPayloadBuilder') === false,
    'host_live_uses_real_payload');
checkG5Host(strpos($live, 'G5SyntheticValidationOnly') === false,
    'host_live_calls_actions_test');
checkG5Host(strpos($workflow, 'diagnose_host_no_payload') !== false,
    'host_diagnostic_is_not_manual');
checkG5Host(strpos($workflow, 'g5-pst-host-diagnostic-only.php') !== false,
    'wrong_live_runner');
checkG5Host(strpos($workflow, "yes_synthetic_actions_test_only") === false,
    'old_live_post_still_dispatchable');
checkG5Host(strpos($workflow, 'g5-pst-actions-test-only.php') === false,
    'old_actions_test_runner_still_dispatchable');
checkG5Host(strpos($workflow, 'salesforce-growth-certification') !== false,
    'reused_env_not_found');
echo "G5 host-only contract PASS: pinned digest, strict origin, old /actions/test workflow quarantined.\n";
