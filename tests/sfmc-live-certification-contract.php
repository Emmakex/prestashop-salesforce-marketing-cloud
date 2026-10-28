<?php

$root = dirname(__DIR__);
$workflowPath = $root . '/.github/workflows/sfmc-live-certification.yml';
$harnessPath = $root . '/tests/live/sfmc-live-certification.php';
$docPath = $root . '/SFMC_LIVE_CERTIFICATION.md';

function cert_contract_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

cert_contract_assert(is_file($workflowPath), 'SFMC live certification workflow is missing.');
cert_contract_assert(is_file($harnessPath), 'SFMC live certification harness is missing.');
cert_contract_assert(is_file($docPath), 'SFMC live certification documentation is missing.');

$workflow = file_get_contents($workflowPath);
$harness = file_get_contents($harnessPath);
$doc = file_get_contents($docPath);

cert_contract_assert(is_string($workflow), 'Unable to read SFMC live certification workflow.');
cert_contract_assert(is_string($harness), 'Unable to read SFMC live certification harness.');
cert_contract_assert(is_string($doc), 'Unable to read SFMC live certification documentation.');

cert_contract_assert(strpos($workflow, 'workflow_dispatch:') !== false, 'SFMC live certification must remain manually dispatched.');
cert_contract_assert(!preg_match('/^  (push|pull_request):/m', $workflow), 'SFMC live certification must not run automatically on push or pull request.');
cert_contract_assert(strpos($workflow, 'default: auth') !== false, 'SFMC live certification must default to auth-only.');
cert_contract_assert(strpos($workflow, 'confirm_live_write') !== false, 'SFMC live certification must require an explicit live-write control.');
cert_contract_assert(strpos($workflow, "inputs.stage != 'auth' && inputs.confirm_live_write != true") !== false, 'Workflow must fail closed when live-write confirmation is absent.');
cert_contract_assert(strpos($workflow, 'environment: sfmc-live-certification') !== false, 'SFMC live certification must stay behind its dedicated GitHub environment.');
cert_contract_assert(strpos($workflow, 'ref: ${{ github.sha }}') !== false, 'SFMC live certification must check out the exact dispatched SHA.');

cert_contract_assert(strpos($harness, 'new AuthClient(') !== false, 'Harness must reuse the production AuthClient.');
cert_contract_assert(strpos($harness, 'new RestClient(') !== false, 'Harness must reuse the production RestClient.');
cert_contract_assert(strpos($harness, 'new CustomerDataExtensionWriter(') !== false, 'Harness must reuse the production customer Data Extension writer.');
cert_contract_assert(strpos($harness, 'new OrderDataExtensionWriter(') !== false, 'Harness must reuse the production order Data Extension writer.');
cert_contract_assert(strpos($harness, 'new JourneyClient(') !== false, 'Harness must reuse the production JourneyClient.');
cert_contract_assert(strpos($harness, 'rest_instance_url') !== false, 'Harness must validate tenant REST routing from OAuth.');
cert_contract_assert(strpos($harness, "hash('sha256', \$contactKey)") !== false, 'Harness evidence must hash the certification ContactKey.');
cert_contract_assert(strpos($harness, 'SFMC_CERT_CONFIRM_LIVE_WRITE') !== false, 'Harness must independently enforce live-write confirmation.');
cert_contract_assert(strpos($harness, 'https://example.invalid/sfmc-certification/') !== false, 'Harness must use a non-routable recovery URL fallback.');

cert_contract_assert(strpos($doc, 'manual-only') !== false, 'Live certification documentation must preserve the manual-only boundary.');
cert_contract_assert(strpos($doc, 'does not by itself prove the entire abandoned-cart recovery lifecycle') !== false, 'Documentation must keep the API/Journey gate separate from end-to-end recovery certification.');

fwrite(STDOUT, "SFMC live certification contract passed.\n");
