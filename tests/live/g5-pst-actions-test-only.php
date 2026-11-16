<?php
/**
 * G5 explicit manual validation of TWO fixed synthetic ABH PST records.
 * Only Salesforce /actions/test is called. No real source rows, ingestion,
 * token logging, writes to Data Cloud, or Marketing Cloud sends.
 */
$root = dirname(dirname(__DIR__));
require_once $root . '/pssalesforcebridge/src/Growth/AuthClient.php';
require_once $root . '/pssalesforcebridge/src/DataCloud/TokenExchangeClient.php';
require_once $root . '/pssalesforcebridge/src/DataCloud/IngestionPayloadBuilder.php';
require_once $root . '/pssalesforcebridge/src/DataCloud/GrowthTypedPstPayloadBuilder.php';
require_once $root . '/tests/review/G5SyntheticValidationOnly.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\AuthClient;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\TokenExchangeClient;
use Emmakex\PrestaShopSalesforceMarketingCloud\Review\G5SyntheticValidationOnly as Validator;

if (PHP_SAPI !== 'cli'
    || getenv('G5_PST_VALIDATION_MODE') !== Validator::MODE
    || getenv('G5_PST_RUN_CONFIRM') !== Validator::MODE
    || getenv('GITHUB_EVENT_NAME') !== 'workflow_dispatch'
    || getenv('GITHUB_REPOSITORY') !== 'Emmakex/prestashop-salesforce-marketing-cloud'
    || getenv('GITHUB_REF') !== 'refs/heads/main') {
    fwrite(STDERR, "G5 denied: explicit manual GitHub main workflow required.\n");
    exit(2);
}

$evidencePath = getenv('G5_PST_EVIDENCE_PATH');
if ($evidencePath !== 'g5-pst-actions-test-sanitized.json') {
    fwrite(STDERR, "G5 denied: evidence path must be fixed and sanitized.\n");
    exit(2);
}
$evidence = [
    'contract_version' => 1,
    'stage' => 'g5_salesforce_actions_test_synthetic_only',
    'source' => Validator::SOURCE,
    'status' => 'failed',
    'run_id' => (string) (getenv('GITHUB_RUN_ID') ?: ''),
    'commit_sha' => (string) (getenv('GITHUB_SHA') ?: ''),
    'schema' => 'PST_v0.4',
    'objects_validated' => [],
    'data_space' => 'default',
    'request_type' => 'POST /api/v1/ingest/sources/<fixed>/<object>/actions/test',
    'actual_ingestion_attempted' => false,
    'data_stream_mutated' => false,
    'salesforce_records_persisted_by_actions_test' => false,
    'marketing_sends_authorized' => false,
    'credentials_or_payload_logged' => false,
];
try {
    // This fixture is already G4 certified and contains no real customer data.
    ob_start();
    require $root . '/tests/g4-pst-typed-payload-fixture.php';
    $fixtureText = ob_get_clean();
    $fixture = json_decode($fixtureText, true);
    if (!is_array($fixture) || array_keys($fixture) !== ['PSCustomer', 'PSOrder']) {
        throw new \RuntimeException('g5_actions_test_fixture_shape_invalid');
    }
    if ($fixture['PSOrder']['data'][0]['customer_source_key']
        !== $fixture['PSCustomer']['data'][0]['source_key']) {
        throw new \RuntimeException('g5_actions_test_customer_order_relation_invalid');
    }
    $payloads = [];
    foreach (['PSCustomer', 'PSOrder'] as $object) {
        $payloads[$object] = json_encode(
            $fixture[$object], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($payloads[$object])) {
            throw new \RuntimeException('g5_actions_test_fixture_encoding_invalid');
        }
    }
    $auth = new AuthClient(
        (string) getenv('SF_GROWTH_ORG_URL'),
        (string) getenv('SF_GROWTH_CLIENT_ID'),
        (string) getenv('SF_GROWTH_CLIENT_SECRET')
    );
    $core = $auth->requestAccessToken();
    $exchange = new TokenExchangeClient();
    $cloud = $exchange->exchange($core['instance_url'], $core['access_token']);
    unset($core, $auth, $exchange);

    // Test the *same* typed source keys for customer and order; the test API
    // never persists rows, so this does not prove an actual DMO join exists.
    foreach ($payloads as $object => $payload) {
        $sanitizedResult = Validator::validate(
            $cloud['instance_url'], $cloud['access_token'], $object, $payload
        );
        $evidence['objects_validated'][] = [
            'object' => $object,
            'http_status' => $sanitizedResult['http_status'],
            'record_count' => 1,
            'status' => 'schema_validated',
        ];
    }
    unset($cloud, $payloads, $fixtureText, $fixture);
    $evidence['status'] = 'success';
} catch (\Throwable $error) {
    $code = (string) $error->getMessage();
    if (!preg_match('/^(?:growth_oauth|data_cloud_exchange|g4_pst|g5_actions_test)_[a-z0-9_]{1,100}$/D', $code)) {
        $code = 'g5_actions_test_unknown_failure';
    }
    $evidence['error_code'] = $code;
    fwrite(STDERR, "G5 failed with sanitized code: " . $code . "\n");
}
$evidence['finished_at_utc'] = gmdate('c');
$json = json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (!is_string($json)
    || file_put_contents($evidencePath, $json . "\n") === false) {
    fwrite(STDERR, "G5 sanitized evidence write failed.\n");
    exit(2);
}
if ($evidence['status'] !== 'success') {
    exit(1);
}
fwrite(STDOUT, "G5 Salesforce /actions/test passed for synthetic customer and order; no ingestion attempted.\n");
