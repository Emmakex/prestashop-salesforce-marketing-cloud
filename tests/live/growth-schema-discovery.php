<?php
/** Manual, metadata-only Growth schema inspection; does not read/write Salesforce records. */
$root = dirname(dirname(__DIR__));
require_once $root . '/pssalesforcebridge/src/Growth/AuthClient.php';
require_once $root . '/pssalesforcebridge/src/Growth/DiscoveryClient.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\AuthClient;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\DiscoveryClient;

$path = getenv('SF_GROWTH_EVIDENCE_PATH');
if (!is_string($path) || $path === '') {
    $path = 'growth-schema-discovery.json';
}
$evidence = [
    'schema_version' => 1,
    'provider' => 'marketing_cloud_growth',
    'stage' => 'read_only_schema',
    'status' => 'failed',
    'commit_sha' => (string) (getenv('GITHUB_SHA') ?: ''),
    'run_id' => (string) (getenv('GITHUB_RUN_ID') ?: ''),
    'credential_values_recorded' => false,
    'salesforce_record_reads' => false,
    'salesforce_data_writes' => false,
];
try {
    $auth = new AuthClient(
        (string) getenv('SF_GROWTH_ORG_URL'),
        (string) getenv('SF_GROWTH_CLIENT_ID'),
        (string) getenv('SF_GROWTH_CLIENT_SECRET')
    );
    $token = $auth->requestAccessToken();
    $client = new DiscoveryClient($token['instance_url'], $token['access_token']);
    $schema = $client->inspectSchema();
    unset($auth, $token, $client);
    $evidence = array_merge($evidence, $schema);
    $evidence['status'] = 'success';
} catch (\Throwable $error) {
    $code = (string) $error->getMessage();
    if (!preg_match('/^growth_(?:oauth|discovery)_[a-z0-9_]{1,80}$/D', $code)) {
        $code = 'growth_discovery_unknown_failure';
    }
    $evidence['error_code'] = $code;
    fwrite(STDERR, "Growth schema discovery failed: " . $code . "\n");
}
$evidence['finished_at_utc'] = gmdate('c');
$json = json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (!is_string($json) || file_put_contents($path, $json . "\n") === false) {
    fwrite(STDERR, "Could not write sanitized Growth schema evidence.\n");
    exit(2);
}
if ($evidence['status'] !== 'success') {
    exit(1);
}
fwrite(STDOUT, "Growth schema metadata discovery passed; no Salesforce record reads or writes.\n");
