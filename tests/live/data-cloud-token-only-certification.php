<?php
/** ABH Data Cloud auth-only certification: no record reads, writes or schema uploads. */
$root = dirname(dirname(__DIR__));
require_once $root . '/pssalesforcebridge/src/Growth/AuthClient.php';
require_once $root . '/pssalesforcebridge/src/DataCloud/TokenExchangeClient.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\AuthClient;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\TokenExchangeClient;

$path = getenv('SF_DC_EVIDENCE_PATH');
if (!is_string($path) || $path === '') {
    $path = 'data-cloud-token-only-certification.json';
}
$evidence = [
    'schema_version' => 1,
    'provider' => 'salesforce_data_cloud_ingestion',
    'stage' => 'oauth_token_exchange_only',
    'status' => 'failed',
    'commit_sha' => (string) (getenv('GITHUB_SHA') ?: ''),
    'run_id' => (string) (getenv('GITHUB_RUN_ID') ?: ''),
    'credential_values_recorded' => false,
    'salesforce_record_reads' => false,
    'salesforce_data_writes' => false,
    'schema_uploaded' => false,
    'data_stream_created' => false,
    'marketing_messages_sent' => false,
];

try {
    $auth = new AuthClient(
        (string) getenv('SF_GROWTH_ORG_URL'),
        (string) getenv('SF_GROWTH_CLIENT_ID'),
        (string) getenv('SF_GROWTH_CLIENT_SECRET')
    );
    $core = $auth->requestAccessToken();
    $evidence['salesforce_core_oauth_success'] = true;
    $exchange = new TokenExchangeClient();
    $cloud = $exchange->exchange($core['instance_url'], $core['access_token']);
    $evidence['data_cloud_access_token_received'] = is_string($cloud['access_token'])
        && $cloud['access_token'] !== '';
    $evidence['data_cloud_instance_url_validated'] = true;
    $evidence['status'] = $evidence['data_cloud_access_token_received'] ? 'success' : 'failed';
    unset($auth, $core, $exchange, $cloud);
} catch (\Throwable $error) {
    $code = (string) $error->getMessage();
    if (!preg_match('/^(?:growth_oauth|data_cloud_exchange)_[a-z0-9_]{1,80}$/D', $code)) {
        $code = 'data_cloud_exchange_unknown_failure';
    }
    $evidence['error_code'] = $code;
    fwrite(STDERR, "Data Cloud token exchange failed: " . $code . "\n");
}
$evidence['finished_at_utc'] = gmdate('c');
$json = json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (!is_string($json) || file_put_contents($path, $json . "\n") === false) {
    fwrite(STDERR, "Unable to write sanitized Data Cloud evidence.\n");
    exit(2);
}
if ($evidence['status'] !== 'success') {
    exit(1);
}
fwrite(STDOUT, "Data Cloud OAuth token exchange succeeded; no record reads or writes.\n");
