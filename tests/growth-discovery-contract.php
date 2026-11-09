<?php

require_once dirname(__DIR__) . '/pssalesforcebridge/src/Growth/DiscoveryClient.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\DiscoveryClient;

function growth_discovery_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$client = new DiscoveryClient('https://alfabh.my.salesforce.com/', 'fake-token');
$paths = [];
$result = $client->inspect(function ($url, $token) use (&$paths) {
    growth_discovery_assert($token === 'fake-token', 'Unexpected bearer token.');
    $path = str_replace('https://alfabh.my.salesforce.com', '', $url);
    $paths[] = $path;
    if ($path === '/services/data/') {
        return ['status' => 200, 'body' => json_encode([
            ['url' => '/services/data/v60.0'],
            ['url' => '/services/data/v63.0'],
            ['url' => 'https://evil.example.org/steal'],
        ])];
    }
    if ($path === '/services/data/v63.0/limits') {
        return ['status' => 200, 'body' => '{"DailyApiRequests":{"Max":1000,"Remaining":999}}'];
    }
    if ($path === '/services/data/v63.0/sobjects') {
        return ['status' => 200, 'body' => json_encode(['sobjects' => [
            ['name' => 'Contact'], ['name' => 'Lead'], ['name' => 'Campaign'],
            ['name' => 'Private_Custom__c'],
        ]])];
    }
    return ['status' => 500, 'body' => '{"secret":"never expose"}'];
});
growth_discovery_assert($paths === [
    '/services/data/', '/services/data/v63.0/limits', '/services/data/v63.0/sobjects'
], 'Unexpected or mutable Salesforce request.');
growth_discovery_assert($result['rest_api_version'] === 'v63.0', 'Wrong API version.');
growth_discovery_assert($result['visible_standard_object_types']['Contact'], 'Contact metadata not found.');
growth_discovery_assert(!$result['visible_standard_object_types']['ContactPointTypeConsent'], 'Unknown consent metadata must not be assumed.');
growth_discovery_assert($result['data_360_ingestion'] === 'not_evaluated', 'Data 360 cannot be inferred from REST.');
growth_discovery_assert($result['salesforce_data_writes'] === false && $result['salesforce_record_reads'] === false, 'Read-only boundary failed.');

foreach ([
    'http://alfabh.my.salesforce.com',
    'https://user:password@alfabh.my.salesforce.com',
    'https://alfabh.my.salesforce.com:8443',
    'https://alfabh.my.salesforce.com/path',
    'https://evil.example.org',
] as $invalidUrl) {
    try {
        new DiscoveryClient($invalidUrl, 'fake-token');
        growth_discovery_assert(false, 'Invalid instance URL accepted.');
    } catch (RuntimeException $expected) {
        growth_discovery_assert($expected->getMessage() === 'growth_discovery_invalid_instance_url', 'Unexpected URL diagnostic.');
    }
}

try {
    $client->inspect(function () {
        return ['status' => 403, 'body' => '{"message":"sensitive secret and customer identity"}'];
    });
    growth_discovery_assert(false, 'Unauthorized request accepted.');
} catch (RuntimeException $expected) {
    growth_discovery_assert($expected->getMessage() === 'growth_discovery_http_403', 'Response body leaked in diagnostic.');
}

$root = dirname(__DIR__);
$workflow = file_get_contents($root . '/.github/workflows/growth-readonly-discovery.yml');
$harness = file_get_contents($root . '/tests/live/growth-readonly-discovery.php');
$source = file_get_contents($root . '/pssalesforcebridge/src/Growth/DiscoveryClient.php');
growth_discovery_assert(is_string($workflow) && is_string($harness) && is_string($source), 'Missing discovery files.');
growth_discovery_assert(strpos($workflow, 'workflow_dispatch:') !== false, 'Discovery must be manually dispatched.');
growth_discovery_assert(!preg_match('/^  (push|pull_request|schedule):/m', $workflow), 'Discovery must not be automated.');
growth_discovery_assert(strpos($workflow, 'environment: salesforce-growth-certification') !== false, 'Must reuse isolated Growth environment.');
growth_discovery_assert(strpos($workflow, 'secrets.SF_GROWTH_CLIENT_SECRET') !== false, 'Secrets must remain in GitHub Environment.');
growth_discovery_assert(strpos($workflow, 'github.sha') !== false, 'Exact SHA not checked.');
growth_discovery_assert(strpos($harness, 'new AuthClient(') !== false, 'OAuth client not reused.');
growth_discovery_assert(strpos($source, 'CURLOPT_HTTPGET => true') !== false, 'Metadata transport must use GET.');
growth_discovery_assert(strpos($source, 'CURLOPT_FOLLOWLOCATION => false') !== false, 'Redirects must remain disabled.');
growth_discovery_assert(strpos($source, '/services/data/v') !== false, 'REST discovery endpoints missing.');
growth_discovery_assert(strpos($harness, 'credential_values_recorded') !== false, 'Evidence data boundary missing.');
growth_discovery_assert(strpos($harness, 'preg_match(') !== false, 'Sanitized diagnostics missing.');

fwrite(STDOUT, "Growth read-only discovery contract passed.\n");
