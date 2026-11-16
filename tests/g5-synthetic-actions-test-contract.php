<?php
/** G5 synthetic-only /actions/test contract (no network, no actual Salesforce). */
ob_start();
require_once __DIR__ . '/g4-pst-typed-payload-fixture.php';
$raw = ob_get_clean();
require_once dirname(__DIR__) . '/tests/review/G5SyntheticValidationOnly.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Review\G5SyntheticValidationOnly as Validator;

function g5Assert($ok, $code)
{
    if (!$ok) {
        fwrite(STDERR, 'G5 FAIL ' . $code . PHP_EOL);
        exit(1);
    }
}
function g5Reject($call, $expected)
{
    try {
        $call();
    } catch (\RuntimeException $e) {
        g5Assert($e->getMessage() === $expected,
            'unexpected_error_' . $e->getMessage());
        return;
    }
    g5Assert(false, 'unsafe_input_accepted_' . $expected);
}

$input = json_decode($raw, true);
g5Assert(is_array($input) && isset($input['PSCustomer'], $input['PSOrder']),
    'g4_fixture_not_loaded');
$objectPayloads = [
    'PSCustomer' => json_encode($input['PSCustomer'],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'PSOrder' => json_encode($input['PSOrder'],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
];
$origin = 'https://tenant.c360a.salesforce.com';
$token = str_repeat('synthetic_token_', 2);
putenv('G5_PST_VALIDATION_MODE');
g5Reject(function () use ($origin, $objectPayloads) {
    Validator::prepare($origin, 'PSCustomer', $objectPayloads['PSCustomer']);
}, 'g5_actions_test_explicit_mode_required');
putenv('G5_PST_VALIDATION_MODE=yes_synthetic_actions_test_only');
$count = 0;
$transport = function ($url, $body, $gotToken) use (&$count, $token) {
    ++$count;
    g5Assert($gotToken === $token, 'transport_token_mismatch');
    g5Assert(strpos($url, 'https://tenant.c360a.salesforce.com/api/v1/ingest/sources/'
            . 'ABH_PrestaShop_Data_Cloud/') === 0, 'wrong_connector');
    g5Assert(substr($url, -13) === '/actions/test', 'outbound_endpoint_is_not_test');
    g5Assert(substr_count($url, '/actions/test') === 1, 'ambiguous_test_endpoint');
    $decoded = json_decode($body, true);
    g5Assert(isset($decoded['data']) && count($decoded['data']) === 1,
        'not_one_fixture_record');
    return ['status' => 200, 'body' => '{"isValid":true}'];
};
foreach ($objectPayloads as $object => $payload) {
    $p = Validator::prepare($origin, $object, $payload);
    g5Assert(strpos($p['url'], '/' . $object . '/actions/test') !== false,
        'missing_exact_object');
    $report = Validator::validate($origin, $token, $object, $payload, $transport);
    g5Assert($report['status'] === 'validated_schema_only'
        && $report['record_count'] === 1
        && $report['salesforce_records_persisted'] === false
        && $report['ingestion_attempted'] === false
        && !isset($report['url']) && !isset($report['payload'])
        && !isset($report['token']), 'unsafe_report');
}
g5Assert($count === 2, 'unexpected_transport_calls');
g5Reject(function () use ($origin, $objectPayloads) {
    Validator::prepare($origin, 'PSCart', $objectPayloads['PSCustomer']);
}, 'g5_actions_test_object_forbidden');
foreach ([
    'http://tenant.c360a.salesforce.com',
    'https://example.com',
    'https://tenant.c360a.salesforce.com/api/v1/ingest/sources/X',
    'https://user:pass@tenant.c360a.salesforce.com',
    'https://tenant.c360a.salesforce.com:444',
    'https://tenant.c360a.salesforce.com/?x=1'
] as $host) {
    g5Reject(function () use ($host, $objectPayloads) {
        Validator::prepare($host, 'PSCustomer', $objectPayloads['PSCustomer']);
    }, 'g5_actions_test_invalid_cloud_origin');
}
$bad = $input['PSCustomer'];
$bad['data'][0]['email'] = 'real-user@example.com';
g5Reject(function () use ($origin, $bad) {
    Validator::prepare($origin, 'PSCustomer', json_encode($bad));
}, 'g5_actions_test_not_synthetic_customer');
$bad = $input['PSCustomer'];
$bad['data'][0]['shop_id'] = 3;
g5Reject(function () use ($origin, $bad) {
    Validator::prepare($origin, 'PSCustomer',
        json_encode($bad, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}, 'g4_pst_invalid_typed_pk');
$bad = $input['PSOrder'];
$bad['data'][0]['customer_source_key'] = 'growth_ci_abhtwo:2:customer:45';
g5Reject(function () use ($origin, $bad) {
    Validator::prepare($origin, 'PSOrder',
        json_encode($bad, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}, 'g5_actions_test_not_synthetic_order');
$bad = $input['PSOrder'];
$bad['data'][0]['paid_flag'] = true;
g5Reject(function () use ($origin, $bad) {
    Validator::prepare($origin, 'PSOrder',
        json_encode($bad, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}, 'g4_pst_paid_state_unverified');

g5Reject(function () use ($origin, $objectPayloads, $token) {
    Validator::validate($origin, $token, 'PSCustomer', $objectPayloads['PSCustomer'],
        function () { return ['status' => 202, 'body' => '{"accepted":true}']; });
}, 'g5_actions_test_http_202');
g5Reject(function () use ($origin, $objectPayloads, $token) {
    Validator::validate($origin, $token, 'PSCustomer', $objectPayloads['PSCustomer'],
        function () { return ['status' => 400, 'body' => '{"value":"PRIVATE_DATA"}']; });
}, 'g5_actions_test_http_400');
g5Reject(function () use ($origin, $objectPayloads, $token) {
    Validator::validate($origin, $token, 'PSCustomer', $objectPayloads['PSCustomer'],
        function () { return ['status' => 200, 'body' => '{"validationReport":{"errors":["PRIVATE"]}}']; });
}, 'g5_actions_test_validation_report_needs_review');
g5Reject(function () use ($origin, $objectPayloads, $token) {
    Validator::validate($origin, $token, 'PSCustomer', $objectPayloads['PSCustomer'],
        function () { return ['status' => 200, 'body' => 'not-json']; });
}, 'g5_actions_test_invalid_json_response');
g5Reject(function () use ($origin, $objectPayloads) {
    Validator::validate($origin, 'x', 'PSCustomer', $objectPayloads['PSCustomer'],
        function () { throw new \RuntimeException('transport_called_without_token'); });
}, 'g5_actions_test_missing_or_invalid_token');

$source = file_get_contents(dirname(__DIR__)
    . '/tests/review/G5SyntheticValidationOnly.php');
g5Assert(strpos($source, "'/actions/test'") !== false
    && strpos($source, "'/api/v1/ingest/sources/'") !== false,
    'endpoint_not_pinned');
$module = file_get_contents(dirname(__DIR__) . '/pssalesforcebridge/pssalesforcebridge.php');
g5Assert(strpos($module, 'G5SyntheticValidationOnly') === false,
    'review_transport_wired_to_prestashop');
echo "G5 PST synthetic /actions/test contract PASS: 2 objects, mock transport, no ingestion.\n";
