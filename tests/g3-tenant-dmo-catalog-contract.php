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
                ['name' => 'ssot__BillToContactId__c'],
                ['name' => 'ssot__BillToAccountId__c']],
        ],
        'ssot__Account__dlm' => [
            'name' => 'ssot__Account__dlm', 'category' => 'PROFILE',
            'dataSpaceName' => 'default',
            'fields' => [
                ['name' => 'ssot__Id__c', 'isPrimaryKey' => true],
                ['name' => 'ssot__IndividualId__c'],
                ['name' => 'confidential_field__c']
            ],
        ],
        'individual_relationships' => ['relationships' => []],
        'account_relationships' => ['relationships' => [
            ['sourceEntity' => ['name' => 'ssot__Account__dlm'],
             'targetEntity' => ['name' => 'ssot__Individual__dlm'],
             'sourceField' => ['name' => 'ssot__IndividualId__c'],
             'status' => 'ACTIVE',
             'confidential_attribute' => 'never-export-this']
        ]],
        'relationships' => ['relationships' => [
            ['sourceEntity' => ['name' => 'ssot__SalesOrder__dlm'],
             'targetEntity' => ['name' => 'ssot__Individual__dlm'],
             'sourceField' => ['name' => 'ssot__SoldToCustomerId__c'],
             'status' => 'ACTIVE',
             'secret' => 'no-print-tenant-secret'],
            ['sourceEntity' => ['name' => 'ssot__SalesOrder__dlm'],
             'targetEntity' => ['name' => 'ssot__Account__dlm'],
             'sourceField' => ['name' => 'ssot__BillToAccountId__c'],
             'status' => 'ACTIVE']
        ]]
    ];
}
function fakeGet($fixtures, &$urls, $relationStatus = 200)
{
    return function ($url, $token) use ($fixtures, &$urls, $relationStatus) {
        assertDmo($token === 'synthetic-token', 'bearer_mismatch');
        $prefix = 'https://example.my.salesforce.com/services/data/v68.0/ssot/data-model-objects/';
        assertDmo(strpos($url, $prefix) === 0, 'unexpected_host_or_path');
        $suffix = substr($url, strlen($prefix));
        $parts = explode('?', $suffix, 2);
        $name = $parts[0];
        if (strpos($name, '/relationships') !== false) {
            assertDmo(isset($parts[1])
                && preg_match('/^limit=100(?:&offset=[1-9][0-9]*)?$/D',
                    $parts[1]) === 1, 'unsafe_pagination_query');
            parse_str($parts[1], $params);
            $offset = isset($params['offset']) ? (int) $params['offset'] : 0;
            assertDmo($offset % 100 === 0 && $offset <= 400,
                'unbounded_offset');
        } else {
            assertDmo(count($parts) === 1, 'object_get_has_query');
            $offset = 0;
        }
        assertDmo(in_array($name, ['ssot__Individual__dlm', 'ssot__SalesOrder__dlm',
            'ssot__SalesOrder__dlm/relationships',
            'ssot__Individual__dlm/relationships',
            'ssot__Account__dlm', 'ssot__Account__dlm/relationships'], true), 'not_fixed_get');
        $urls[] = $url;
        $fixture = $name === 'ssot__Individual__dlm/relationships'
            ? 'individual_relationships'
            : ($name === 'ssot__Account__dlm/relationships'
                ? 'account_relationships'
                : (strpos($name, '/relationships') !== false
                    ? 'relationships' : $name));
        $record = $fixtures[$fixture];
        if (strpos($name, '/relationships') !== false && $relationStatus === 200) {
            $items = $record['relationships'];
            $record['relationships'] = array_slice($items, $offset, 100);
            $record['totalSize'] = count($items);
        }
        return ['status' => strpos($name, '/relationships') !== false
            ? $relationStatus : 200,
            'body' => json_encode($record)];
    };
}
$probe = new G3TenantDmoCatalogProbe(
    'https://example.my.salesforce.com', 'synthetic-token');
$fixture = fixtures();
$urls = [];
$result = $probe->inspect(fakeGet($fixture, $urls));
assertDmo(count($urls) === 6, 'expected_exactly_6_gets');
assertDmo($urls[0] === 'https://example.my.salesforce.com/services/data/v68.0/ssot/data-model-objects/ssot__Individual__dlm', 'individual_path');
assertDmo($urls[1] === 'https://example.my.salesforce.com/services/data/v68.0/ssot/data-model-objects/ssot__SalesOrder__dlm', 'sales_order_path');
assertDmo($urls[2] === 'https://example.my.salesforce.com/services/data/v68.0/ssot/data-model-objects/ssot__Account__dlm', 'account_path');
assertDmo($urls[3] === $urls[1] . '/relationships?limit=100', 'relations_path');
assertDmo($urls[4] === $urls[0] . '/relationships?limit=100', 'individual_relations_path');
assertDmo($urls[5] === $urls[2] . '/relationships?limit=100', 'account_relations_path');
assertDmo($result['objects']['Individual']['id_field_is_primary_key'],
    'individual_pk');
assertDmo($result['objects']['SalesOrder']['category_matches_proposal']
    && $result['objects']['SalesOrder']['observed_category'] === 'OTHER',
    'sales_order_category');
assertDmo($result['objects']['Account']['metadata_readable']
    && $result['objects']['Account']['id_field_is_primary_key']
    && $result['objects']['Account']['candidate_fields_present']['ssot__IndividualId__c']
    && $result['objects']['SalesOrder']['candidate_fields_present']['ssot__BillToAccountId__c'],
    'missing_account_bridge_fields');
assertDmo($result['relationship']['sold_to_customer_relation_seen']
    && $result['relationship']['active_candidate_relation_seen']
    && $result['relationship']['sales_order_relationship_count'] === 2
    && $result['relationship']['individual_relationship_count'] === 0
    && $result['relationship']['account_relationship_count'] === 1
    && $result['relationship']['sales_order_to_account_bill_to_relation_seen']
    && $result['relationship']['account_to_individual_relation_seen']
    && $result['relationship']['active_sales_order_to_account_bill_to_relation_seen']
    && $result['relationship']['active_account_to_individual_relation_seen']
    && !$result['relationship']['account_bridge_verified_by_metadata_only']
    && $result['relationship']['sales_order_relationship_scan_complete']
    && $result['relationship']['individual_relationship_scan_complete']
    && $result['relationship']['account_relationship_scan_complete'],
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
    'confidential_field__c', 'never-export-this',
    'synthetic-token', 'example.my.salesforce.com'] as $secret) {
    assertDmo(strpos($encoded, $secret) === false, 'unfiltered_evidence');
}

$relUrls = [];
$partial = $probe->inspect(fakeGet($fixture, $relUrls, 403));
assertDmo(!$partial['relationship']['metadata_accessible']
    && !$partial['relationship']['individual_relationship_metadata_accessible']
    && $partial['relationship']['sales_order_relationship_count'] === null
    && $partial['relationship']['account_relationship_count'] === null
    && !$partial['relationship']['sales_order_relationship_scan_complete']
    && !$partial['relationship']['sales_order_to_account_bill_to_relation_seen']
    && !$partial['relationship']['account_to_individual_relation_seen']
    && !$partial['relationship']['sales_order_to_individual_candidate_present'],
    '403_relation_falsely_verified');
$wrong = fixtures();
$wrong['ssot__SalesOrder__dlm']['category'] = 'ENGAGEMENT';
$wrongUrls = [];
$wrongResult = $probe->inspect(fakeGet($wrong, $wrongUrls));
assertDmo(!$wrongResult['objects']['SalesOrder']['category_matches_proposal']
    && $wrongResult['objects']['SalesOrder']['observed_category'] === 'ENGAGEMENT',
    'unexpected_category_approved');
// Salesforce documents that some standard DMOs are UNASSIGNED until
// mapped; this is observation only, not category/stream approval.
$unassigned = fixtures();
$unassigned['ssot__SalesOrder__dlm']['category'] = 'UNASSIGNED';
$unassignedUrls = [];
$unassignedResult = $probe->inspect(fakeGet($unassigned, $unassignedUrls));
assertDmo($unassignedResult['objects']['SalesOrder']['observed_category'] === 'UNASSIGNED'
    && !$unassignedResult['objects']['SalesOrder']['category_matches_proposal']
    && !$unassignedResult['tenant_mapping_approved'],
    'standard_unassigned_category_not_reported');

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

// A missing/forbidden Account DMO cannot be treated as a verified bridge.
// The two original DMOs remain queryable and are not silently merged.
$missingAccount = $probe->inspect(function ($url, $token) use ($fixture) {
    $prefix = 'https://example.my.salesforce.com/services/data/v68.0/ssot/data-model-objects/';
    $name = explode('?', substr($url, strlen($prefix)), 2)[0];
    if ($name === 'ssot__Account__dlm'
        || $name === 'ssot__Account__dlm/relationships') {
        return ['status' => 404, 'body' => '{"private":"no-log"}'];
    }
    $key = $name === 'ssot__Individual__dlm/relationships'
        ? 'individual_relationships'
        : (strpos($name, '/relationships') !== false
            ? 'relationships' : $name);
    $record = $fixture[$key];
    if (isset($record['relationships'])) {
        $record['totalSize'] = count($record['relationships']);
    }
    return ['status' => 200, 'body' => json_encode($record)];
});
assertDmo(!$missingAccount['objects']['Account']['metadata_readable']
    && !$missingAccount['relationship']['account_relationship_metadata_accessible']
    && !$missingAccount['relationship']['account_bridge_verified_by_metadata_only'],
    'optional_account_not_fail_closed');
// Explicit pagination is essential: Salesforce defaults to 20 relations.
// An edge on a later page must be detected without any dynamic URL follow.
$many = fixtures();
$rows = [];
for ($i = 0; $i < 100; ++$i) {
    $rows[] = [
        'id' => 'synthetic_unrelated_' . $i,
        'sourceEntity' => ['name' => 'ssot__SalesOrder__dlm'],
        'targetEntity' => ['name' => 'ssot__Another__dlm'],
    ];
}
$rows[] = [
    'id' => 'synthetic_link_page_two',
    'sourceEntity' => ['name' => 'ssot__SalesOrder__dlm'],
    'targetEntity' => ['name' => 'ssot__Account__dlm'],
    'sourceField' => ['name' => 'ssot__BillToAccountId__c'],
    'status' => 'ACTIVE',
];
$many['relationships']['relationships'] = $rows;
$paginatedUrls = [];
$afterPage2 = $probe->inspect(fakeGet($many, $paginatedUrls));
assertDmo(count($paginatedUrls) === 7
    && strpos($paginatedUrls[4], '?limit=100&offset=100') !== false
    && $afterPage2['relationship']['sales_order_relationship_count'] === 101
    && $afterPage2['relationship']['sales_order_relationship_scan_complete']
    && $afterPage2['relationship']['sales_order_to_account_bill_to_relation_seen'],
    'second_page_edge_was_missed');

// When the bounded scan reaches its cap, NO ABSENCE can be certified.
$over = fixtures();
$rows = [];
for ($i = 0; $i < 501; ++$i) {
    $rows[] = [
        'id' => 'synthetic_unrelated_' . $i,
        'sourceEntity' => ['name' => 'ssot__SalesOrder__dlm'],
        'targetEntity' => ['name' => 'ssot__Another__dlm'],
    ];
}
$over['relationships']['relationships'] = $rows;
$limitedUrls = [];
$limited = $probe->inspect(fakeGet($over, $limitedUrls));
assertDmo($limited['relationship']['sales_order_relationship_count'] === 500
    && !$limited['relationship']['sales_order_relationship_scan_complete']
    && !$limited['relationship']['sales_order_to_account_bill_to_relation_seen']
    && !$limited['relationship']['account_bridge_verified_by_metadata_only'],
    'bounded_pagination_falsely_certified_absence');

// Repeated/ignored offsets must not create a false complete relationship list.
$repeated = fixtures();
$repeated['relationships']['relationships'] = array_slice($rows, 0, 100);
$duplicateUrls = [];
$dup = $probe->inspect(function ($url, $token) use ($repeated, &$duplicateUrls) {
    $duplicateUrls[] = $url;
    $name = explode('?', substr($url, strlen(
        'https://example.my.salesforce.com/services/data/v68.0/ssot/data-model-objects/'
    )), 2)[0];
    $key = $name === 'ssot__Individual__dlm/relationships'
        ? 'individual_relationships' : ($name === 'ssot__Account__dlm/relationships'
            ? 'account_relationships' : (strpos($name, '/relationships') !== false
                ? 'relationships' : $name));
    $record = $repeated[$key];
    if (isset($record['relationships'])) {
        $record['totalSize'] = $key === 'relationships'
            ? 101 : count($record['relationships']);
    }
    return ['status' => 200, 'body' => json_encode($record)];
});
assertDmo($dup['relationship']['sales_order_relationship_count'] === 100
    && !$dup['relationship']['sales_order_relationship_scan_complete'],
    'repeated_pages_must_be_inconclusive');

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
echo "G3 DMO metadata-only contract: three DMO GETs plus bounded paginated relationships, never infer missing links from first page.\n";
