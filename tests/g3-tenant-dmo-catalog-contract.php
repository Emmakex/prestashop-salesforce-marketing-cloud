<?php
/** Synthetic-only G3 tenant DMO GET metadata contract. PHP 7.1+. */
require_once __DIR__ . '/review/G3TenantDmoCatalogProbe.php';

function assertDmo($ok, $code)
{
    if (!$ok) { fwrite(STDERR, "G3 DMO contract: " . $code . "\n"); exit(1); }
}
function rejectDmo($callback, $code)
{
    try { call_user_func($callback); }
    catch (RuntimeException $e) {
        assertDmo($e->getMessage() === $code, 'wrong_' . $code);
        return;
    }
    assertDmo(false, 'unsafe_accepted_' . $code);
}
function fixtures()
{
    return [
        'ssot__Individual__dlm' => [
            'name' => 'ssot__Individual__dlm', 'category' => 'PROFILE',
            'dataSpaceName' => 'default',
            'fields' => [['name' => 'ssot__Id__c', 'isPrimaryKey' => true],
                ['name' => 'custom_private_field__c']]
        ],
        'ssot__SalesOrder__dlm' => [
            'name' => 'ssot__SalesOrder__dlm', 'category' => 'OTHER',
            'dataSpaceName' => 'default',
            'fields' => [['name' => 'ssot__Id__c', 'isPrimaryKey' => true],
                ['name' => 'ssot__SoldToCustomerId__c'],
                ['name' => 'ssot__BillToContactId__c']],
        ],
        'individual_relationships' => ['relationships' => []],
        'relationships' => ['relationships' => [
            ['sourceEntity' => ['name' => 'ssot__SalesOrder__dlm'],
             'targetEntity' => ['name' => 'ssot__Individual__dlm'],
             'sourceField' => ['name' => 'ssot__SoldToCustomerId__c'],
             'status' => 'ACTIVE',
             'secret' => 'no-print-tenant-secret'],
            ['sourceEntity' => ['name' => 'ssot__SalesOrder__dlm'],
             'targetEntity' => ['name' => 'ssot__Account__dlm'],
             'sourceField' => ['name' => 'ssot__BillToContactId__c']]
        ]]
    ];
}
function fakeGet($fixtures, &$urls, $relationStatus = 200)
{
    return function ($url, $token) use ($fixtures, &$urls, $relationStatus) {
        assertDmo($token === 'synthetic-token', 'bearer_mismatch');
        $prefix = 'https://example.my.salesforce.com/services/data/v68.0/ssot/data-model-objects/';
        assertDmo(strpos($url, $prefix) === 0, 'unexpected_host_or_path');
        $name = substr($url, strlen($prefix));
        assertDmo(in_array($name, ['ssot__Individual__dlm', 'ssot__SalesOrder__dlm',
            'ssot__SalesOrder__dlm/relationships',
            'ssot__Individual__dlm/relationships'], true), 'not_fixed_get');
        $urls[] = $url;
        $fixture = $name === 'ssot__Individual__dlm/relationships'
            ? 'individual_relationships'
            : (strpos($name, '/relationships') !== false
                ? 'relationships' : $name);
        return ['status' => strpos($name, '/relationships') !== false
            ? $relationStatus : 200,
            'body' => json_encode($fixtures[$fixture])];
    };
}
$probe = new G3TenantDmoCatalogProbe(
    'https://example.my.salesforce.com', 'synthetic-token');
$fixture = fixtures();
$urls = [];
$result = $probe->inspect(fakeGet($fixture, $urls));
assertDmo(count($urls) === 4, 'expected_exactly_4_gets');
assertDmo($urls[0] === 'https://example.my.salesforce.com/services/data/v68.0/ssot/data-model-objects/ssot__Individual__dlm', 'individual_path');
assertDmo($urls[1] === 'https://example.my.salesforce.com/services/data/v68.0/ssot/data-model-objects/ssot__SalesOrder__dlm', 'sales_order_path');
assertDmo($urls[2] === $urls[1] . '/relationships', 'relations_path');
assertDmo($urls[3] === $urls[0] . '/relationships', 'individual_relations_path');
assertDmo($result['objects']['Individual']['id_field_is_primary_key'],
    'individual_pk');
assertDmo($result['objects']['SalesOrder']['category_matches_proposal']
    && $result['objects']['SalesOrder']['observed_category'] === 'OTHER',
    'sales_order_category');
assertDmo($result['relationship']['sold_to_customer_relation_seen']
    && $result['relationship']['active_candidate_relation_seen']
    && $result['relationship']['sales_order_relationship_count'] === 2
    && $result['relationship']['individual_relationship_count'] === 0,
    'exact_relationship');
assertDmo(!$result['relationship']['bill_to_contact_relation_seen'],
    'wrong_relation_target');
foreach (['tenant_mapping_approved', 'identity_resolution_authorized',
    'schema_upload_authorized', 'data_360_ingestion_authorized',
    'marketing_sends_authorized', 'salesforce_record_reads',
    'salesforce_data_writes'] as $key) {
    assertDmo($result[$key] === false, 'unauthorized_' . $key);
}
$encoded = json_encode($result);
foreach (['no-print-tenant-secret', 'custom_private_field__c',
    'synthetic-token', 'example.my.salesforce.com'] as $secret) {
    assertDmo(strpos($encoded, $secret) === false, 'unfiltered_evidence');
}

$relUrls = [];
$partial = $probe->inspect(fakeGet($fixture, $relUrls, 403));
assertDmo(!$partial['relationship']['metadata_accessible']
    && !$partial['relationship']['individual_relationship_metadata_accessible']
    && $partial['relationship']['sales_order_relationship_count'] === null
    && !$partial['relationship']['sales_order_to_individual_candidate_present'],
    '403_relation_falsely_verified');
$wrong = fixtures();
$wrong['ssot__SalesOrder__dlm']['category'] = 'ENGAGEMENT';
$wrongUrls = [];
$wrongResult = $probe->inspect(fakeGet($wrong, $wrongUrls));
assertDmo(!$wrongResult['objects']['SalesOrder']['category_matches_proposal']
    && $wrongResult['objects']['SalesOrder']['observed_category'] === 'ENGAGEMENT',
    'unexpected_category_approved');
// Untrusted/custom categories are not exported verbatim.
$unknown = fixtures();
$unknown['ssot__SalesOrder__dlm']['category'] = 'tenant_confidential_category';
$unknownUrls = [];
$unknownResult = $probe->inspect(fakeGet($unknown, $unknownUrls));
assertDmo($unknownResult['objects']['SalesOrder']['observed_category'] === 'UNRECOGNIZED'
    && strpos(json_encode($unknownResult), 'tenant_confidential_category') === false,
    'unrecognized_category_was_leaked');
// The reverse endpoint can identify fields belonging to Sales Order while
// exposing that object as targetEntity, never as sourceEntity.
$reverse = fixtures();
$reverse['relationships']['relationships'] = [];
$reverse['individual_relationships']['relationships'] = [[
    'sourceEntity' => ['name' => 'ssot__Individual__dlm'],
    'targetEntity' => ['name' => 'ssot__SalesOrder__dlm'],
    'targetField' => ['name' => 'ssot__BillToContactId__c'],
    'status' => 'INACTIVE'
]];
$reverseUrls = [];
$reverseResult = $probe->inspect(fakeGet($reverse, $reverseUrls));
assertDmo($reverseResult['relationship']['any_relation_between_objects_seen']
    && $reverseResult['relationship']['bill_to_contact_relation_seen']
    && !$reverseResult['relationship']['active_candidate_relation_seen'],
    'reverse_relationship_not_reported');

rejectDmo(function () use ($probe) {
    $probe->inspect(function () {
        return ['status' => 403, 'body' => '{"access_token":"secret"}'];
    });
}, 'g3_dmo_http_403');
rejectDmo(function () use ($probe) {
    $probe->inspect(function () {
        return ['status' => 200, 'body' => '{"name":"wrong","fields":[]}'];
    });
}, 'g3_dmo_invalid_object_metadata');
rejectDmo(function () use ($probe) {
    $probe->inspect(function () {
        return ['status' => 200, 'body' => str_repeat('x', 1048577)];
    });
}, 'g3_dmo_response_too_large');
rejectDmo(function () use ($probe) {
    $probe->inspect('non_callable');
}, 'g3_dmo_invalid_transport');
foreach ([
    'http://example.my.salesforce.com',
    'https://user@example.my.salesforce.com',
    'https://example.my.salesforce.com/path',
    'https://example.my.salesforce.com:8443',
    'https://example.my.salesforce.com.attacker.invalid',
    'https://attacker.invalid'
] as $url) {
    rejectDmo(function () use ($url) {
        new G3TenantDmoCatalogProbe($url, 'synthetic-token');
    }, 'g3_dmo_invalid_org_origin');
}
rejectDmo(function () {
    new G3TenantDmoCatalogProbe('https://example.my.salesforce.com', '');
}, 'g3_dmo_missing_or_invalid_token');

$src = file_get_contents(__DIR__ . '/review/G3TenantDmoCatalogProbe.php');
foreach (['CURLOPT_HTTPGET => true', 'CURLOPT_FOLLOWLOCATION => false',
    'CURLOPT_SSL_VERIFYPEER => true'] as $setting) {
    assertDmo(strpos($src, $setting) !== false, 'missing_get_safety_setting');
}
foreach (['CURLOPT_POST', 'CURLOPT_CUSTOMREQUEST', '/api/v1/ingest/',
    '/interaction/v1/events', '/query', '/sobjects/', 'file_put_contents('] as $forbidden) {
    assertDmo(strpos($src, $forbidden) === false, 'unsafe_capability_in_reader');
}
$workflow = file_get_contents(dirname(__DIR__)
    . '/.github/workflows/g3-dmo-catalog-readonly.yml');
foreach (['branches: [main]', 'environment: salesforce-growth-certification',
    'contents: read', 'ref: ${{ github.sha }}',
    'persist-credentials: false', 'G3_TENANT_DMO_READ_ONLY: yes_metadata_only'] as $guard) {
    assertDmo(strpos($workflow, $guard) !== false, 'missing_workflow_guard');
}
assertDmo(strpos($workflow, 'pull_request:') === false,
    'secrets_exposed_to_pr');
echo "G3 DMO metadata GET-only contract: four fixed GETs, category enum and both relation directions; no approvals.\n";
