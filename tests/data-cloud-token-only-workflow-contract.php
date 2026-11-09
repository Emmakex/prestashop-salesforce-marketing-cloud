<?php
$root = dirname(__DIR__);
$workflow = file_get_contents($root . '/.github/workflows/data-cloud-token-only-certification.yml');
$harness = file_get_contents($root . '/tests/live/data-cloud-token-only-certification.php');
$ci = file_get_contents($root . '/.github/workflows/ci.yml');
$tests = [
    'files present' => is_string($workflow) && is_string($harness) && is_string($ci),
    'manual only' => strpos($workflow, 'workflow_dispatch:') !== false
        && !preg_match('/^  (schedule|push|pull_request):/m', $workflow),
    'protected secrets reused' => strpos($workflow, 'environment: salesforce-growth-certification') !== false
        && strpos($workflow, 'secrets.SF_GROWTH_CLIENT_SECRET') !== false,
    'pin exact commit' => strpos($workflow, 'git rev-parse HEAD') !== false
        && strpos($workflow, 'github.sha') !== false,
    'limited workflow permissions' => strpos($workflow, "permissions:\n  contents: read") !== false,
    'both auth clients reused' => strpos($harness, 'new AuthClient(') !== false
        && strpos($harness, 'new TokenExchangeClient()') !== false,
    'no writes' => strpos($harness, "'salesforce_data_writes' => false") !== false
        && strpos($harness, "'schema_uploaded' => false") !== false
        && strpos($harness, "'marketing_messages_sent' => false") !== false
        && strpos($harness, '/api/v1/ingest/') === false,
    'sanitized errors' => strpos($harness, 'preg_match(') !== false
        && strpos($harness, "error_code'] = $code") !== false,
    'gate in CI' => strpos($ci, 'php tests/data-cloud-token-only-workflow-contract.php') !== false,
];
foreach ($tests as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Data Cloud token-only gate failed: " . $name . "\n");
        exit(1);
    }
}
fwrite(STDOUT, "Data Cloud token-only workflow gate passed.\n");
