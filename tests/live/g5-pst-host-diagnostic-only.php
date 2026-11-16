<?php
/**
 * G5 PST hostname attestation after unexpected 202 from /actions/test.
 * No Salesforce ingestion or validation requests, no personal data.
 * OAuth Core + Data Cloud token exchange only (already established G1/G2).
 */
$root = dirname(dirname(__DIR__));
require_once $root . '/pssalesforcebridge/src/Growth/AuthClient.php';
require_once $root . '/pssalesforcebridge/src/DataCloud/TokenExchangeClient.php';
require_once $root . '/tests/review/G5PstEndpointAttestation.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\AuthClient;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\TokenExchangeClient;
use Emmakex\PrestaShopSalesforceMarketingCloud\Review\G5PstEndpointAttestation;

if (PHP_SAPI !== 'cli'
    || getenv('G5_PST_HOST_MODE') !== 'diagnose_host_no_payload'
    || getenv('G5_PST_HOST_CONFIRM') !== 'diagnose_host_no_payload'
    || getenv('GITHUB_EVENT_NAME') !== 'workflow_dispatch'
    || getenv('GITHUB_REPOSITORY') !== 'Emmakex/prestashop-salesforce-marketing-cloud'
    || getenv('GITHUB_REF') !== 'refs/heads/main') {
    fwrite(STDERR, "G5 host diagnostic denied: manual protected workflow only.\n");
    exit(2);
}
$file = getenv('G5_PST_HOST_EVIDENCE_PATH');
if ($file !== 'g5-pst-host-diagnostic-sanitized.json') {
    fwrite(STDERR, "G5 host diagnostic denied: evidence file mismatch.\n");
    exit(2);
}
$report = [
    'version' => 1,
    'stage' => 'g5_host_diagnostic_no_payload',
    'status' => 'failed',
    'run_id' => (string) (getenv('GITHUB_RUN_ID') ?: ''),
    'commit_sha' => (string) (getenv('GITHUB_SHA') ?: ''),
    'expected_connector' => G5PstEndpointAttestation::SOURCE,
    'tenant_host_matches_official_pst_yaml' => false,
    'test_paths_verified_offline' => false,
    'salesforce_data_post_performed' => false,
    'salesforce_validation_post_performed' => false,
    'records_ingested_by_diagnostic' => false,
    'raw_host_token_headers_payload_response_logged' => false,
];
try {
    $auth = new AuthClient(
        (string) getenv('SF_GROWTH_ORG_URL'),
        (string) getenv('SF_GROWTH_CLIENT_ID'),
        (string) getenv('SF_GROWTH_CLIENT_SECRET')
    );
    $core = $auth->requestAccessToken();
    $exchange = new TokenExchangeClient();
    $cloud = $exchange->exchange($core['instance_url'], $core['access_token']);
    unset($core, $auth, $exchange);
    // Never emit/print the host, the OAuth access token, an HTTP URL or request.
    $check = G5PstEndpointAttestation::attest($cloud['instance_url']);
    unset($cloud);
    $report['tenant_host_matches_official_pst_yaml']
        = $check['tenant_host_matches_official_pst_yaml'];
    $report['test_paths_verified_offline'] = $check['test_paths_verified_offline'];
    if (!$report['tenant_host_matches_official_pst_yaml']) {
        throw new \RuntimeException('g5_host_mismatch_with_official_pst_yaml');
    }
    if (!$report['test_paths_verified_offline']) {
        throw new \RuntimeException('g5_host_invalid_test_paths');
    }
    $report['status'] = 'success';
} catch (\Throwable $error) {
    $code = (string) $error->getMessage();
    if (!preg_match('/^(?:growth_oauth|data_cloud_exchange|g5_host)_[a-z0-9_]{1,100}$/D', $code)) {
        $code = 'g5_host_unknown_failure';
    }
    $report['error_code'] = $code;
    fwrite(STDERR, "G5 host diagnostic result: " . $code . "\n");
}
$report['finished_at_utc'] = gmdate('c');
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (!is_string($json) || file_put_contents($file, $json . "\n") === false) {
    fwrite(STDERR, "G5 host diagnostic could not save sanitized result.\n");
    exit(2);
}
if ($report['status'] !== 'success') {
    exit(1);
}
fwrite(STDOUT, "G5 host attestation PASS: official tenant matches; no data POST performed.\n");
