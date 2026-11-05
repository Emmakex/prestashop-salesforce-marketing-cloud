<?php

require_once dirname(__DIR__) . '/pssalesforcebridge/src/Growth/AuthClient.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\AuthClient;

function growth_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$client = new AuthClient('https://alfabh.my.salesforce.com/', 'fake-id', 'fake-secret');
$called = false;
$token = $client->requestAccessToken(function ($endpoint, $fields) use (&$called) {
    $called = true;
    growth_assert($endpoint === 'https://alfabh.my.salesforce.com/services/oauth2/token', 'Wrong Growth token endpoint.');
    growth_assert($fields === [
        'grant_type' => 'client_credentials',
        'client_id' => 'fake-id',
        'client_secret' => 'fake-secret',
    ], 'Unexpected Client Credentials payload.');
    return ['status' => 200, 'body' => '{"access_token":"test-token","instance_url":"https://alfabh.my.salesforce.com"}'];
});
growth_assert($called && $token['access_token'] === 'test-token', 'Growth token contract failed.');

foreach (['http://alfabh.my.salesforce.com', 'https://evil.example.org', 'https://alfabh.my.salesforce.com/path', 'https://user@alfabh.my.salesforce.com'] as $invalid) {
    try {
        new AuthClient($invalid, 'fake-id', 'fake-secret');
        growth_assert(false, 'An unsafe OAuth domain was accepted.');
    } catch (RuntimeException $expected) {
        growth_assert(strpos($expected->getMessage(), 'growth_oauth_') === 0, 'Unexpected domain error.');
    }
}

try {
    $client->requestAccessToken(function () {
        return ['status' => 401, 'body' => '{"error_description":"fake-secret"}'];
    });
    growth_assert(false, '401 response accepted.');
} catch (RuntimeException $expected) {
    growth_assert($expected->getMessage() === 'growth_oauth_http_401', '401 must expose status only, not an OAuth description.');
}

try {
    $client->requestAccessToken(function () {
        return ['status' => 400, 'body' => '{"error":"invalid_client","error_description":"private identity fake-secret"}'];
    });
    growth_assert(false, 'Invalid client was accepted.');
} catch (RuntimeException $expected) {
    growth_assert($expected->getMessage() === 'growth_oauth_http_400_invalid_client', 'Known OAuth error must be safely classified.');
}

try {
    $client->requestAccessToken(function () {
        return ['status' => 403, 'body' => '{"error":"evil-secret-fake-secret","error_description":"private tenant data"}'];
    });
    growth_assert(false, 'Unknown OAuth error accepted.');
} catch (RuntimeException $expected) {
    growth_assert($expected->getMessage() === 'growth_oauth_http_403', 'Unknown OAuth error must be suppressed.');
}

try {
    $client->requestAccessToken(function () {
        return ['status' => 200, 'body' => '{"access_token":"secret-value","instance_url":"https://evil.example.org"}'];
    });
    growth_assert(false, 'Invalid instance URL accepted.');
} catch (RuntimeException $expected) {
    growth_assert(strpos($expected->getMessage(), 'secret-value') === false, 'Token leaked in error.');
}

$root = dirname(__DIR__);
$workflow = file_get_contents($root . '/.github/workflows/growth-oauth-certification.yml');
$harness = file_get_contents($root . '/tests/live/growth-oauth-certification.php');
growth_assert(is_string($workflow) && is_string($harness), 'Missing workflow/harness.');
growth_assert(strpos($workflow, 'workflow_dispatch:') !== false, 'Workflow must be manual.');
growth_assert(!preg_match('/^  (push|pull_request|schedule):/m', $workflow), 'Workflow must not run automatically.');
growth_assert(strpos($workflow, 'environment: salesforce-growth-certification') !== false, 'Missing isolated Growth environment.');
growth_assert(strpos($workflow, 'ref: ' . '$' . '{{ github.sha }}') !== false, 'Missing exact SHA checkout.');
growth_assert(strpos($workflow, 'secrets.SF_GROWTH_CLIENT_SECRET') !== false, 'Client Secret must come from GitHub Secrets.');
growth_assert(strpos($workflow, 'vars.SF_GROWTH_ORG_URL') !== false, 'Organization URL must be an Environment variable.');
growth_assert(strpos($workflow, 'sfmc-live-certification') === false, 'Engagement environment must not be used.');
growth_assert(strpos($harness, 'new AuthClient(') !== false, 'Harness must reuse Growth AuthClient.');
growth_assert(strpos($harness, "'auth_only'") !== false && strpos($harness, 'salesforce_data_writes') !== false, 'Harness must remain read-only.');
growth_assert(strpos($harness, "error_code'] = $code") !== false, 'Evidence must persist the sanitized failure code.');
growth_assert(strpos($harness, 'preg_match(') !== false, 'Harness must validate diagnostic codes.');
growth_assert(strpos($harness, "getMessage(),") === false, 'Never forward an exception message directly to the evidence.');

fwrite(STDOUT, "Growth OAuth auth-only contract passed.\n");
