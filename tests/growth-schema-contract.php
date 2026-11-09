<?php

require_once dirname(__DIR__) . '/pssalesforcebridge/src/Growth/DiscoveryClient.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\DiscoveryClient;

function schema_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$paths = [];
$client = new DiscoveryClient('https://alfabh.my.salesforce.com', 'fixture-token');
$result = $client->inspectSchema(function ($url, $token) use (&$paths) {
    schema_assert($token === 'fixture-token', 'Wrong token passed to mock.');
    schema_assert(strpos($url, 'https://alfabh.my.salesforce.com/') === 0, 'Host changed.');
    $path = substr($url, strlen('https://alfabh.my.salesforce.com'));
    $paths[] = $path;
    if ($path === '/services/data/') {
        return ['status' => 200, 'body' => '[{"url":"/services/data/v68.0"},{"url":"https://unsafe.example/v999"}]'];
    }
    if ($path === '/services/data/v68.0/limits') {
        return ['status' => 200, 'body' => '{"DailyApiRequests":{"Remaining":500}}'];
    }
    if ($path === '/services/data/v68.0/sobjects') {
        return ['status' => 200, 'body' => '{"sobjects":[{"name":"Contact"},{"name":"Order"},{"name":"Secret_Custom__c"}]}'];
    }
    if ($path === '/services/data/v68.0/sobjects/Contact/describe') {
        return ['status' => 200, 'body' => json_encode([
            'name' => 'Contact', 'createable' => true, 'updateable' => false, 'queryable' => true,
            'fields' => [
                ['name' => 'Email', 'createable' => true, 'updateable' => false, 'externalId' => false, 'description' => 'private customer email alice@example.org'],
                ['name' => 'LastName', 'createable' => true, 'updateable' => true, 'externalId' => false],
                ['name' => 'Partner_Internal_Key__c', 'createable' => true, 'updateable' => true, 'externalId' => true, 'description' => 'secret private config'],
            ],
        ])];
    }
    if ($path === '/services/data/v68.0/sobjects/Order/describe') {
        return ['status' => 200, 'body' => json_encode([
            'name' => 'Order', 'createable' => false, 'updateable' => true, 'queryable' => false,
            'fields' => [
                ['name' => 'OrderNumber', 'createable' => false, 'updateable' => false, 'externalId' => false],
                ['name' => 'TotalAmount', 'createable' => false, 'updateable' => false, 'externalId' => false],
            ],
        ])];
    }
    return ['status' => 500, 'body' => '{"message":"private"}'];
});

schema_assert($paths === [
    '/services/data/',
    '/services/data/v68.0/limits',
    '/services/data/v68.0/sobjects',
    '/services/data/v68.0/sobjects/Contact/describe',
    '/services/data/v68.0/sobjects/Order/describe',
], 'Only allowlisted GET metadata URLs are permitted.');
schema_assert($result['rest_api_version'] === 'v68.0', 'Wrong API version.');
schema_assert($result['objects']['Contact']['createable'] === true, 'Contact createable missing.');
schema_assert($result['objects']['Contact']['updateable'] === false, 'Contact updateable must be false.');
schema_assert($result['objects']['Contact']['any_external_id_metadata'] === true, 'External ID metadata indicator missing.');
schema_assert($result['objects']['Contact']['candidate_fields']['Email']['createable'] === true, 'Email createable missing.');
schema_assert($result['objects']['Contact']['candidate_fields']['Email']['updateable'] === false, 'Email updateable must be false.');
schema_assert($result['objects']['Contact']['candidate_fields']['FirstName']['present'] === false, 'Absent fields must fail closed.');
schema_assert($result['objects']['Lead'] === ['visible' => false, 'described' => false], 'Invisible objects should not be described.');
schema_assert($result['objects']['Order']['queryable'] === false, 'Queryable must not be assumed.');
schema_assert($result['data_360_entitlement'] === 'not_evaluated', 'Data 360 cannot be inferred.');
schema_assert($result['salesforce_data_writes'] === false && $result['salesforce_record_reads'] === false, 'Record safety failed.');
$json = json_encode($result);
schema_assert(strpos($json, 'Partner_Internal_Key__c') === false, 'Custom names must never enter evidence.');
schema_assert(strpos($json, 'alice@example.org') === false && strpos($json, 'secret private config') === false, 'No raw response fields or values in evidence.');

$forbidden = function ($url, $token) {
    $path = substr($url, strlen('https://alfabh.my.salesforce.com'));
    if ($path === '/services/data/') {
        return ['status' => 200, 'body' => '[{"url":"/services/data/v68.0"}]'];
    }
    if ($path === '/services/data/v68.0/limits') {
        return ['status' => 200, 'body' => '{}'];
    }
    if ($path === '/services/data/v68.0/sobjects') {
        return ['status' => 200, 'body' => '{"sobjects":[{"name":"Contact"}]}'];
    }
    return ['status' => 403, 'body' => '{"message":"secret tenant identity"}'];
};
try {
    $client->inspectSchema($forbidden);
    schema_assert(false, 'Unauthorized schema response must fail.');
} catch (RuntimeException $expected) {
    schema_assert($expected->getMessage() === 'growth_discovery_http_403', 'Non-allowlisted failure leaked.');
}

$root = dirname(__DIR__);
$workflow = file_get_contents($root . '/.github/workflows/growth-schema-discovery.yml');
$harness = file_get_contents($root . '/tests/live/growth-schema-discovery.php');
$clientSource = file_get_contents($root . '/pssalesforcebridge/src/Growth/DiscoveryClient.php');
schema_assert(is_string($workflow) && is_string($harness) && is_string($clientSource), 'Missing schema workflow files.');
schema_assert(strpos($workflow, 'workflow_dispatch:') !== false, 'Workflow must be manual only.');
schema_assert(!preg_match('/^  (push|pull_request|schedule):/m', $workflow), 'No automatic Salesforce metadata access.');
schema_assert(strpos($workflow, 'environment: salesforce-growth-certification') !== false, 'Must reuse the protected Growth environment.');
schema_assert(strpos($workflow, 'secrets.SF_GROWTH_CLIENT_SECRET') !== false, 'Client secret must come from protected environment.');
schema_assert(strpos($workflow, 'github.sha') !== false, 'Missing exact head checkout.');
schema_assert(strpos($clientSource, 'CURLOPT_HTTPGET => true') !== false, 'Discovery must use GET transport.');
schema_assert(strpos($clientSource, 'CURLOPT_FOLLOWLOCATION => false') !== false, 'Redirects must be disabled.');
schema_assert(strpos($harness, 'credential_values_recorded') !== false, 'Evidence boundary missing.');
schema_assert(strpos($harness, 'preg_match(') !== false, 'Failure diagnostics must be sanitized.');
schema_assert(strpos($harness, 'new AuthClient(') !== false, 'Must reuse certified Growth OAuth.');
fwrite(STDOUT, "Growth sObject Describe read-only contract passed.\n");
