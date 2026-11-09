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
        dc_exchange_assert($error->getMessage() === 'data_cloud_exchange_invalid_cloud_url',
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
