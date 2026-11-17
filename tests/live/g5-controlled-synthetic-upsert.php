<?php
/**
 * Salesforce ABH canary: a single guarded POST from a protected manual run.
 * Only synthetic constant fields. Never reads PrestaShop customer data.
 *
 * Use preview_only first (no OAuth/HTTP). To POST, an owner must explicitly
 * enter two approval strings and run the protected environment job. An HTTP
 * 202 is only an asynchronous receipt, NOT a DLO/DMO certification.
 */
$root = dirname(__DIR__, 2);
require_once $root . '/pssalesforcebridge/src/Growth/AuthClient.php';
require_once $root . '/pssalesforcebridge/src/DataCloud/TokenExchangeClient.php';
require_once $root . '/pssalesforcebridge/src/DataCloud/IngestionPayloadBuilder.php';
require_once $root . '/pssalesforcebridge/src/DataCloud/GrowthTypedPstPayloadBuilder.php';
require_once $root . '/pssalesforcebridge/src/DataCloud/GrowthStreamingIngestionPlan.php';
require_once $root . '/tests/review/G5ControlledSyntheticUpsert.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\AuthClient;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\TokenExchangeClient;
use Emmakex\PrestaShopSalesforceMarketingCloud\Review\G5ControlledSyntheticUpsert as Canary;

$mode = getenv('G5_CANARY_MODE');
if (PHP_SAPI !== 'cli'
    || getenv('GITHUB_REPOSITORY')
        !== 'Emmakex/prestashop-salesforce-marketing-cloud'
    || getenv('GITHUB_EVENT_NAME') !== 'workflow_dispatch'
    || getenv('GITHUB_REF') !== 'refs/heads/main'
    || !in_array($mode, [
        'preview_only', Canary::CUSTOMER_MODE, Canary::ORDER_MODE
    ], true)
    || getenv('G5_CANARY_EVIDENCE_PATH')
        !== 'g5-canary-sanitized.json') {
    fwrite(STDERR, "G5 canary denied: protected manual main workflow required.\n");
    exit(2);
}
$object = $mode === Canary::ORDER_MODE ? 'PSOrder' : 'PSCustomer';
$evidence = [
    'version' => 1,
    'run_id' => (string) (getenv('GITHUB_RUN_ID') ?: ''),
    'commit_sha' => (string) (getenv('GITHUB_SHA') ?: ''),
    'source' => Canary::SOURCE,
    'object' => $object,
    'mode' => $mode,
    'status' => 'hold_for_review',
    'is_synthetic_only' => true,
    'shop_data_read' => false,
    'real_customer_data_used' => false,
    'marketing_sends_authorized' => false,
    'dlo_row_verified' => false,
    'dmo_mapping_verified' => false,
    'automatic_retry_authorized' => false,
    'oauth_or_payload_logged' => false,
    'upsert_attempted' => false,
];
try {
    if ($mode === 'preview_only') {
        // Fixed synthetic hostname; never make a request in preview mode.
        $plan = Canary::prepare(
            'https://synthetic.c360a.salesforce.com', $object
        );
        if ($plan['count'] !== 1 || $plan['dispatch_authorized'] !== false
            || $plan['marketing_sends_authorized'] !== false) {
            throw new RuntimeException('g5_canary_preview_invalid');
        }
        unset($plan);
        $evidence['status'] = 'offline_preview_pass';
    } else {
        // Avoid overwriting a preexisting synthetic canary by mistake.
        if (getenv('G5_CANARY_ABSENCE_CONFIRMED')
            !== 'CANARY_NOT_PRESENT_IN_DLO') {
            throw new RuntimeException('g5_canary_absence_review_required');
        }
        $auth = new AuthClient(
            (string) getenv('SF_GROWTH_ORG_URL'),
            (string) getenv('SF_GROWTH_CLIENT_ID'),
            (string) getenv('SF_GROWTH_CLIENT_SECRET')
        );
        $core = $auth->requestAccessToken();
        $exchange = new TokenExchangeClient();
        $cloud = $exchange->exchange($core['instance_url'], $core['access_token']);
        unset($auth, $core, $exchange);

        // This is exactly ONE possible POST, no PSOrder after PSCustomer
        // unless the owner submits a different run after DLO verification.
        $result = Canary::execute(
            $cloud['instance_url'], $cloud['access_token'], $object
        );
        unset($cloud);
        $evidence['upsert_attempted'] = true;
        $evidence['http_status'] = $result['http_status'];
        $evidence['outcome_state'] = $result['outcome_state'];
        $evidence['status'] = $result['accepted_asynchronously_unconfirmed']
            ? 'accepted_unconfirmed_manual_dlo_check_required'
            : 'hold_for_review';
    }
} catch (Throwable $error) {
    $code = (string) $error->getMessage();
    if (!preg_match(
        '/^(?:g5_canary|g7_streaming|g4_pst|growth_oauth|data_cloud_exchange)_[a-z0-9_]{1,90}$/D',
        $code
    )) {
        $code = 'g5_canary_unclassified_hold';
    }
    $evidence['error_code'] = $code;
    // Never print any raw response/host/identity/stack or credentials.
    fwrite(STDERR, "G5 canary needs review: " . $code . "\n");
}
$evidence['finished_at_utc'] = gmdate('c');
$json = json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (!is_string($json) || file_put_contents(
    'g5-canary-sanitized.json', $json . "\n"
) === false) {
    fwrite(STDERR, "G5 canary sanitized evidence unavailable.\n");
    exit(2);
}
if ($evidence['status'] === 'offline_preview_pass') {
    echo "G5 synthetic canary offline preview PASS; zero network operations.\n";
    exit(0);
}
if ($evidence['status'] === 'accepted_unconfirmed_manual_dlo_check_required') {
    echo "G5 synthetic upsert returned 202; verify DLO/DMO manually. NO retry or marketing send.\n";
    exit(0);
}
exit(1);
