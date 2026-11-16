<?php
/**
 * ABH G6: Salesforce Core communication-subscription configuration inventory.
 * No customer records, no Data Cloud CSC reads, no subscription or consent writes.
 */
$root = dirname(dirname(__DIR__));
require_once $root . '/pssalesforcebridge/src/Growth/AuthClient.php';
require_once $root . '/tests/review/G6CoreSubscriptionInventory.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\AuthClient;
use Emmakex\PrestaShopSalesforceMarketingCloud\Review\G6CoreSubscriptionInventory as Inventory;

if (PHP_SAPI !== 'cli'
    || getenv('GITHUB_EVENT_NAME') !== 'workflow_dispatch'
    || getenv('GITHUB_REPOSITORY') !== 'Emmakex/prestashop-salesforce-marketing-cloud'
    || getenv('GITHUB_REF') !== 'refs/heads/main'
    || getenv('G6_SUBSCRIPTION_INVENTORY_MODE') !== 'readonly_core_config_only'
    || getenv('G6_SUBSCRIPTION_INVENTORY_CONFIRM') !== 'readonly_core_config_only'
    || getenv('G6_SUBSCRIPTION_EVIDENCE_PATH') !== 'g6-abh-core-subscription-inventory.json') {
    fwrite(STDERR, "G6 Core subscription catalog: manual protected workflow required.\n");
    exit(2);
}
$evidence = [
    'schema_version' => 1,
    'stage' => 'g6_salesforce_core_subscription_config_only',
    'status' => 'failed',
    'run_id' => (string) (getenv('GITHUB_RUN_ID') ?: ''),
    'commit_sha' => (string) (getenv('GITHUB_SHA') ?: ''),
    'core_subscription_inventory' => null,
    'growth_subscription_confirmed' => false,
    'data_cloud_csc_checked' => false,
    'customer_data_read' => false,
    'personal_consent_records_read' => false,
    'salesforce_mutations' => false,
    'marketing_sends_authorized' => false,
    'credential_or_raw_response_logged' => false,
];
try {
    $auth = new AuthClient(
        (string) getenv('SF_GROWTH_ORG_URL'),
        (string) getenv('SF_GROWTH_CLIENT_ID'),
        (string) getenv('SF_GROWTH_CLIENT_SECRET')
    );
    $core = $auth->requestAccessToken();
    $result = Inventory::inspect($core['instance_url'], $core['access_token']);
    unset($core,$auth);
    $evidence['core_subscription_inventory'] = $result;
    $evidence['status'] = $result['status'] === 'core_subscription_inventory_observed'
        ? 'core_inventory_observed_not_growth_confirmed'
        : 'core_inventory_unverified';
} catch (\Throwable $error) {
    $code = (string) $error->getMessage();
    if (!preg_match('/^(?:growth_oauth|g6_subscription)_[a-z0-9_]{1,100}$/D',$code)) {
        $code = 'g6_subscription_unknown_failure';
    }
    $evidence['error_code'] = $code;
    fwrite(STDERR, "G6 inventory failure: " . $code . "\n");
}
$evidence['finished_at_utc'] = gmdate('c');
$out = json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (!is_string($out) || file_put_contents(
    'g6-abh-core-subscription-inventory.json', $out . "\n") === false) {
    fwrite(STDERR, "G6 unable to write sanitized inventory.\n");
    exit(2);
}
if ($evidence['status'] !== 'core_inventory_observed_not_growth_confirmed') {
    fwrite(STDERR,"G6 core subscription inventory unavailable or incomplete; no conclusion about Growth.\n");
    exit(1);
}
fwrite(STDOUT,"G6 Core subscription inventory observed. Check Growth Consent UI separately; no sends.\n");
