<?php
/** G3 PST existing-stream proof: one bounded filtered GET; zero permissions. */
require_once __DIR__ . '/review/G3TenantDmoCatalogProbe.php';

function pstStreamAssert($ok, $reason)
{
    if (!$ok) {
        fwrite(STDERR, "G3 PST stream FAIL: " . $reason . PHP_EOL);
        exit(1);
    }
}
function pstStreamReject($callback, $errorCode)
{
    try {
        $callback();
    } catch (RuntimeException $e) {
        pstStreamAssert($e->getMessage() === $errorCode,
            'unexpected_exception_' . $e->getMessage());
        return;
    }
    pstStreamAssert(false, 'expected_rejection_' . $errorCode);
}
$expected = 'https://example.my.salesforce.com/services/data/v68.0/'
    . 'ssot/data-streams?connectionName=ABH_PrestaShop_Data_Cloud&limit=200&offset=0';
$probe = new G3TenantDmoCatalogProbe(
    'https://example.my.salesforce.com', 'synthetic-token'
);
function pstStreamCheck(array $result, $expectedState, $expectedHttp)
{
    pstStreamAssert($result['state'] === $expectedState
        && $result['http_status_code'] === $expectedHttp,
        'state_or_http_drift');
    foreach (['full_tenant_stream_inventory_certified',
        'pst_stream_absence_certified', 'pst_stream_schema_approved',
        'data_stream_creation_authorized', 'dlo_dmo_mapping_save_authorized',
        'data_360_ingestion_authorized', 'marketing_sends_authorized'] as $key) {
        pstStreamAssert($result[$key] === false, 'unsafe_permission_' . $key);
    }
    foreach (['private@example.invalid', 'tenant-private-name', 'secret-key',
        'example.my.salesforce.com'] as $sensitive) {
        pstStreamAssert(strpos(json_encode($result), $sensitive) === false,
            'untrusted_metadata_exposed');
    }
}
$callCount = 0;
$withTwo = $probe->inspectPstStreamPresence(
    function ($url, $token) use (&$callCount, $expected) {
        ++$callCount;
        pstStreamAssert($url === $expected && $token === 'synthetic-token',
            'unexpected_endpoint_or_token');
        return ['status' => 200, 'body' => json_encode([
            'totalSize' => 10,
            'nextPageUrl' => 'https://tenant-private-name.invalid/secret-key',
            'dataStreams' => [
                [
                    'name' => 'tenant-private-name',
                    'connectorInfo' => ['connectorDetails' => [
                        'name' => 'ABH_PrestaShop_Data_Cloud',
                        'sourceObject' => 'private@example.invalid',
                    ]],
                    'dataLakeObjectInfo' => [
                        'name' => 'tenant-private-name__dll',
                    ],
                ],
                [
                    'label' => 'secret-key',
                    'connectorInfo' => ['connectorDetails' => [
                        'name' => 'unrelated_connection',
                    ]],
                ],
            ],
        ])];
    }
);
pstStreamAssert($callCount === 1, 'extra_gets');
pstStreamCheck($withTwo, 'filtered_page_items_observed_review_only', 200);
pstStreamAssert($withTwo['first_page_count'] === 2
    && $withTwo['exact_connector_detail_match_count'] === 1
    && $withTwo['any_dlo_metadata_visible'] === true,
    'scoped_counts_not_observed');

$noItems = $probe->inspectPstStreamPresence(function ($url, $token) use ($expected) {
    pstStreamAssert($url === $expected, 'empty_path_changed');
    return ['status' => 200, 'body' => '{"dataStreams":[],"totalSize":0}'];
});
pstStreamCheck($noItems, 'filtered_page_empty_review_only', 200);
pstStreamAssert($noItems['first_page_count'] === 0
    && $noItems['exact_connector_detail_match_count'] === 0
    && $noItems['pst_stream_absence_certified'] === false,
    'empty_page_was_global_absence_claim');

foreach ([403, 404] as $http) {
    $denied = $probe->inspectPstStreamPresence(function ($url, $token) use ($http) {
        return ['status' => $http,
            'body' => '{"secret":"private@example.invalid"}'];
    });
    pstStreamCheck($denied, 'unverified_http_' . $http, $http);
    pstStreamAssert($denied['first_page_count'] === null
        && $denied['exact_connector_detail_match_count'] === null,
        'http_failure_was_zero_stream_claim');
}
foreach ([
    '{"dataStreams":{}}',
    '{"dataStreams":null}',
    '{}',
    '{"dataStreams":"private@example.invalid"}',
] as $badBody) {
    $bad = $probe->inspectPstStreamPresence(function ($url, $token)
        use ($badBody) {
        return ['status' => 200, 'body' => $badBody];
    });
    pstStreamCheck($bad, 'unverified_response_shape', null);
}
$tooMany = $probe->inspectPstStreamPresence(function ($url, $token) {
    return ['status'=>200, 'body'=>json_encode([
        'dataStreams'=>array_fill(0, 201, ['name'=>'secret-key'])
    ])];
});
pstStreamCheck($tooMany, 'unverified_response_shape', null);
$invalidRow = $probe->inspectPstStreamPresence(function ($url, $token) {
    return ['status'=>200, 'body'=>'{"dataStreams":[null]}'];
});
pstStreamCheck($invalidRow, 'unverified_response_shape', null);
pstStreamReject(function () use ($probe) {
    $probe->inspectPstStreamPresence('not_callback');
}, 'g3_dmo_invalid_transport');
pstStreamReject(function () use ($probe) {
    $probe->inspectPstStreamPresence(function ($url, $token) {
        return ['status'=>500, 'body'=>'{"private":"secret-key"}'];
    });
}, 'g3_dmo_http_500');

$src = file_get_contents(__DIR__ . '/review/G3TenantDmoCatalogProbe.php');
foreach (['CURLOPT_POST => true', 'CURLOPT_CUSTOMREQUEST =>',
    'CURLOPT_POSTFIELDS =>', 'CURLOPT_PUT =>',
    '/actions/run', '/api/v1/ingest/'] as $forbidden) {
    pstStreamAssert(strpos($src, $forbidden) === false,
        'read_only_boundary_broken_' . $forbidden);
}
echo "G3 PST filtered data-stream presence GET-only contract PASS.\n";
