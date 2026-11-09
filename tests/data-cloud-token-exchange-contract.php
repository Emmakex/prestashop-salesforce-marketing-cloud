<?php

require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/TokenExchangeClient.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\TokenExchangeClient;

function dc_exchange_assert($ok, $message)
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$exchange = new TokenExchangeClient();
$called = false;
$result = $exchange->exchange('https://alfabh.my.salesforce.com', 'synthetic-core-token',
    function ($endpoint, $fields) use (&$called) {
        $called = true;
        dc_exchange_assert($endpoint === 'https://alfabh.my.salesforce.com/services/a360/token',
            'Wrong Data Cloud token exchange endpoint.');
        dc_exchange_assert($fields === [
            'grant_type' => 'urn:salesforce:grant-type:external:cdp',
            'subject_token' => 'synthetic-core-token',
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
        ], 'Wrong token exchange form payload.');
        return [
            'status' => 200,
            'body' => '{"access_token":"synthetic-cloud-token",'
                . '"instance_url":"https://m-demo7.c360a.salesforce.com","token_type":"Bearer"}',
        ];
    }
);
// Host-only endpoint returned by the tenant must be normalized exclusively
// when its DNS suffix belongs to the documented Salesforce-owned c360a zone.
foreach ([
    'credencial.c360a.salesforce.com' => 'https://credencial.c360a.salesforce.com',
    'REGION.TENANT-ID.C360A.SALESFORCE.COM' => 'https://region.tenant-id.c360a.salesforce.com',
] as $hostname => $expectedOrigin) {
    $normalized = $exchange->exchange('https://alfabh.my.salesforce.com', 'synthetic',
        function () use ($hostname) {
            return ['status' => 200, 'body' => json_encode([
                'access_token' => 'never-log-token',
                'instance_url' => $hostname,
                'token_type' => 'Bearer',
            ])];
        });
    dc_exchange_assert($normalized['instance_url'] === $expectedOrigin,
        'Valid Salesforce Data Cloud bare hostname was not normalized.');
}
foreach ([
    'attacker.example.org',
    'credencial.c360a.salesforce.com.attacker.org',
    'user@credencial.c360a.salesforce.com',
    'credencial.c360a.salesforce.com:8443',
    'credencial.c360a.salesforce.com/path',
    'credencial.c360a.salesforce.com?x=y',
    'credencial.c360a.salesforce.com#fragment',
    ' credencial.c360a.salesforce.com',
    '-bad.c360a.salesforce.com',
    'credencial.c360a.salesforce.com.',
] as $invalidHostname) {
    try {
        $exchange->exchange('https://alfabh.my.salesforce.com', 'synthetic',
            function () use ($invalidHostname) {
                return ['status' => 200, 'body' => json_encode([
                    'access_token' => 'never-log-token',
                    'instance_url' => $invalidHostname,
                ])];
            });
        dc_exchange_assert(false, 'Untrusted bare hostname was accepted.');
    } catch (RuntimeException $error) {
        dc_exchange_assert(strpos($error->getMessage(), 'data_cloud_exchange_invalid_cloud_url_') === 0,
            'Unsafe hostname must fail closed with a sanitized code.');
        dc_exchange_assert(strpos($error->getMessage(), 'credencial') === false,
            'Tenant identity must never appear in an error.');
    }
}

// Only fixed non-sensitive shape categories may be emitted for suspicious URL
// responses; neither raw URL nor tenant identifiers should appear in logs.
$badShapes = [
    'http://tenant.c360a.salesforce.com' => 'scheme',
    'https://user@tenant.c360a.salesforce.com' => 'userinfo',
    'https://tenant.c360a.salesforce.com:8443' => 'port',
    'https://tenant.c360a.salesforce.com/services/api' => 'path',
    'https://tenant.c360a.salesforce.com?secret=synthetic' => 'query',
    'https://tenant.c360a.salesforce.com#fragment' => 'fragment',
    '/relative/no-origin' => 'structure',
];
foreach ($badShapes as $badUrl => $reason) {
    try {
        $exchange->exchange('https://alfabh.my.salesforce.com', 'synthetic',
            function () use ($badUrl) {
                return ['status' => 200, 'body' => json_encode([
                    'access_token' => 'never-log-token', 'instance_url' => $badUrl,
                ])];
            });
        dc_exchange_assert(false, 'Bad URL shape accepted.');
    } catch (RuntimeException $error) {
        dc_exchange_assert(
            $error->getMessage() === 'data_cloud_exchange_invalid_cloud_url_' . $reason,
            'Wrong sanitized URL-shape classification.'
        );
        dc_exchange_assert(strpos($error->getMessage(), 'tenant') === false,
            'Tenant identity leaked.');
    }
}

// Previously, a nested Salesforce tenant host was incorrectly rejected.
$nested = $exchange->exchange('https://alfabh.my.salesforce.com', 'synthetic',
    function () {
        return [
            'status' => 200,
            'body' => '{"access_token":"synthetic",'
                . '"instance_url":"https://region.tenant-id.c360a.salesforce.com"}',
        ];
    });
dc_exchange_assert($nested['instance_url'] === 'https://region.tenant-id.c360a.salesforce.com',
    'Multi-label tenant host incorrectly rejected.');

foreach ([
    'https://tenant.salesforce.com' => 'data_cloud_exchange_invalid_cloud_url_salesforce_other',
    'https://a360.cdp.test.aws.sfdc.cl' => 'data_cloud_exchange_invalid_cloud_url_sfdc',
    'https://attacker.example.org' => 'data_cloud_exchange_invalid_cloud_url_external',
    'https://tenant.c360a.salesforce.com.attacker.org' => 'data_cloud_exchange_invalid_cloud_url_external',
    'https://-invalid.c360a.salesforce.com' => 'data_cloud_exchange_invalid_cloud_url_label',
] as $candidate => $expectedError) {
    try {
        $exchange->exchange('https://alfabh.my.salesforce.com', 'synthetic',
            function () use ($candidate) {
                return ['status' => 200, 'body' => json_encode([
                    'access_token' => 'never-log-token', 'instance_url' => $candidate,
                ])];
            });
        dc_exchange_assert(false, 'Unsafe domain accepted.');
    } catch (RuntimeException $error) {
        dc_exchange_assert($error->getMessage() === $expectedError,
            'Diagnostic must never expose URL, token, or tenant ID.');
    }
}

dc_exchange_assert($called, 'No token exchange performed.');
dc_exchange_assert($result === [
    'access_token' => 'synthetic-cloud-token',
    'instance_url' => 'https://m-demo7.c360a.salesforce.com',
], 'Token exchange output mismatch.');

foreach ([
    'http://alfabh.my.salesforce.com',
    'https://user:pass@alfabh.my.salesforce.com',
    'https://alfabh.my.salesforce.com:444',
    'https://alfabh.my.salesforce.com/path',
    'https://alfabh.my.salesforce.com.evil.org',
    'https://evil.example.org',
] as $bad) {
    try {
        $exchange->exchange($bad, 'synthetic', function () { return []; });
        dc_exchange_assert(false, 'Unsafe Core URL accepted.');
    } catch (RuntimeException $error) {
        dc_exchange_assert($error->getMessage() === 'data_cloud_exchange_invalid_core_url',
            'Unexpected Core URL diagnostic.');
    }
}
foreach ([
    'https://evil.example.org',
    'https://m-demo7.c360a.salesforce.com.evil.org',
    'http://m-demo7.c360a.salesforce.com',
    'https://user@m-demo7.c360a.salesforce.com',
    'https://m-demo7.c360a.salesforce.com/path',
    'https://m-demo7.c360a.salesforce.com:80',
    'https://m-demo7.c360a.salesforce.com?token=fake',
] as $bad) {
    try {
        $exchange->exchange('https://alfabh.my.salesforce.com', 'core-token',
            function () use ($bad) {
                return [
                    'status' => 200,
                    'body' => json_encode([
                        'access_token' => 'synthetic-secret-which-must-not-leak',
                        'instance_url' => $bad,
                    ]),
                ];
            });
        dc_exchange_assert(false, 'Unsafe Cloud URL accepted.');
    } catch (RuntimeException $error) {
        $expectedError = strpos($bad, 'https://evil.example.org') === 0
            || strpos($bad, '.evil.org') !== false
            ? 'data_cloud_exchange_invalid_cloud_url_external'
            : (strpos($bad, 'http://') === 0
                ? 'data_cloud_exchange_invalid_cloud_url_scheme'
                : (strpos($bad, 'https://user@') === 0
                    ? 'data_cloud_exchange_invalid_cloud_url_userinfo'
                    : (strpos($bad, '/path') !== false
                        ? 'data_cloud_exchange_invalid_cloud_url_path'
                        : (strpos($bad, ':80') !== false
                            ? 'data_cloud_exchange_invalid_cloud_url_port'
                            : 'data_cloud_exchange_invalid_cloud_url_query'))));
        dc_exchange_assert($error->getMessage() === $expectedError,
            'Cloud token/URL leaked in error.');
    }
}
foreach ([400, 401, 403, 429, 500] as $status) {
    try {
        $exchange->exchange('https://alfabh.my.salesforce.com', 'synthetic-core-token',
            function () use ($status) {
                return [
                    'status' => $status,
                    'body' => '{"message":"client-secret-never-log","error_description":"person@example.org"}',
                ];
            });
        dc_exchange_assert(false, 'Unexpected HTTP status accepted.');
    } catch (RuntimeException $error) {
        dc_exchange_assert($error->getMessage() === 'data_cloud_exchange_http_' . $status,
            'Non-allowlisted response leaked.');
    }
}
try {
    $exchange->exchange('https://alfabh.my.salesforce.com', 'synthetic-core-token',
        function () {
            return [
                'status' => 400,
                'body' => '{"error":"invalid_subject_token","error_description":"private-data"}',
            ];
        });
    dc_exchange_assert(false, 'Invalid subject token accepted.');
} catch (RuntimeException $error) {
    dc_exchange_assert($error->getMessage() === 'data_cloud_exchange_http_400_invalid_subject_token',
        'Known diagnostic was not safely classified.');
}
try {
    $exchange->exchange('https://alfabh.my.salesforce.com', '',
        function () { return []; });
    dc_exchange_assert(false, 'Empty Core token accepted.');
} catch (RuntimeException $error) {
    dc_exchange_assert($error->getMessage() === 'data_cloud_exchange_missing_subject_token',
        'Unexpected empty token diagnostic.');
}
try {
    $exchange->exchange('https://alfabh.my.salesforce.com', 'synthetic',
        function () {
            return [
                'status' => 200,
                'body' => '{"access_token":"private-secret",'
                    . '"instance_url":"https://m-demo7.c360a.salesforce.com","token_type":"Basic"}',
            ];
        });
    dc_exchange_assert(false, 'Unexpected token type accepted.');
} catch (RuntimeException $error) {
    dc_exchange_assert($error->getMessage() === 'data_cloud_exchange_invalid_response',
        'Token-type error leaked.');
}
$source = file_get_contents(dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/TokenExchangeClient.php');
dc_exchange_assert(strpos($source, 'CURLOPT_FOLLOWLOCATION => false') !== false, 'Redirects must stay disabled.');
dc_exchange_assert(strpos($source, 'CURLOPT_SSL_VERIFYPEER => true') !== false, 'TLS verification must stay enabled.');
dc_exchange_assert(strpos($source, '/api/v1/ingest/sources/') === false, 'Token client must never ingest records.');
dc_exchange_assert(strpos($source, 'CURLOPT_POST => true') !== false, 'Exchange must use POST form.');
fwrite(STDOUT, "Data Cloud token exchange offline contract passed: no Salesforce calls.\n");
