<?php
/** Manual, auth-only Growth certification: no Salesforce record/marketing writes. */
$root = dirname(dirname(__DIR__));
require_once $root . '/pssalesforcebridge/src/Growth/AuthClient.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\AuthClient;

$path = getenv('SF_GROWTH_EVIDENCE_PATH');
if (!is_string($path) || $path === '') {
    $path = 'growth-oauth-certification.json';
}

$evidence = [
    'schema_version' => 1,
    'provider' => 'marketing_cloud_growth',
    'stage' => 'auth_only',
    'status' => 'failed',
    'commit_sha' => (string) (getenv('GITHUB_SHA') ?: ''),
    'run_id' => (string) (getenv('GITHUB_RUN_ID') ?: ''),
    'credential_values_recorded' => false,
    'salesforce_data_writes' => false,
];

try {
    $client = new AuthClient(
        (string) getenv('SF_GROWTH_ORG_URL'),
        (string) getenv('SF_GROWTH_CLIENT_ID'),
        (string) getenv('SF_GROWTH_CLIENT_SECRET')
    );
    $token = $client->requestAccessToken();
    $evidence['status'] = 'success';
    $evidence['oauth_access_token_received'] = isset($token['access_token']) && $token['access_token'] !== '';
    $evidence['instance_url_validated'] = true;
    unset($token);
} catch (\Throwable $error) {
    // AuthClient error codes are server-independent or strictly allowlisted OAuth names.
    // Reject any unexpected message to prevent leakage into logs/artifacts.
    $code = (string) $error->getMessage();
    if (!preg_match('/^growth_oauth_[a-z0-9_]{1,80}$/D', $code)) {
        $code = 'growth_oauth_unknown_failure';
    }
    $evidence['error_code'] = $code;
    fwrite(STDERR, "Growth OAuth failed with sanitized code: " . $code . "\n");
}

$evidence['finished_at_utc'] = gmdate('c');
$json = json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (!is_string($json) || file_put_contents($path, $json . "\n") === false) {
    fwrite(STDERR, "Unable to persist sanitized evidence.\n");
    exit(2);
}
if ($evidence['status'] !== 'success') {
    exit(1);
}
fwrite(STDOUT, "Growth OAuth authentication passed; no Salesforce data writes.\n");
