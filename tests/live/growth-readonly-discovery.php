<?php
/** Manual metadata-only probe. No Salesforce row reads or writes. */
$root = dirname(dirname(__DIR__));
require_once $root . '/pssalesforcebridge/src/Growth/AuthClient.php';
require_once $root . '/pssalesforcebridge/src/Growth/DiscoveryClient.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\AuthClient;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\DiscoveryClient;

$path = getenv('SF_GROWTH_EVIDENCE_PATH');
if (!is_string($path) || $path === '') {
    $path = 'growth-readonly-discovery.json';
}
$evidence = [
    'schema_version' => 1,
    'provider' => 'marketing_cloud_growth',
    'stage' => 'read_only_metadata',
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
    $discovery = new DiscoveryClient($token['instance_url'], $token['access_token']);
    $result = $discovery->inspect();
    unset($token, $auth, $discovery);
    $evidence = array_merge($evidence, $result);
    $evidence['status'] = 'success';
} catch (\Throwable $error) {
    // Only fixed diagnostic identifiers are permitted in logs and artifacts.
    $code = (string) $error->getMessage();
    if (!preg_match('/^growth_(?:oauth|discovery)_[a-z0-9_]{1,80}$/D', $code)) {
        $code = 'growth_discovery_unknown_failure';
    }
    $evidence['error_code'] = $code;
    fwrite(STDERR, "Growth metadata discovery failed: " . $code . "\n");
}

$evidence['finished_at_utc'] = gmdate('c');
$json = json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (!is_string($json) || file_put_contents($path, $json . "\n") === false) {
    fwrite(STDERR, "Could not write sanitized Growth metadata evidence.\n");
    exit(2);
}
if ($evidence['status'] !== 'success') {
    exit(1);
}
fwrite(STDOUT, "Growth REST metadata discovery passed; no Salesforce record reads or writes.\n");
