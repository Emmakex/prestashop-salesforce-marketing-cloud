<?php
/** G3 protected read-only DLO->DMO mapping metadata: five fixed GETs only. */
require_once __DIR__ . '/review/G3TenantDmoCatalogProbe.php';

function mappingAssert($ok, $reason)
{
    if (!$ok) {
        fwrite(STDERR, "G3 mapping metadata FAIL: " . $reason . PHP_EOL);
        exit(1);
    }
}
function mappingReject($callback, $code)
{
    try {
        $callback();
    } catch (RuntimeException $error) {
        mappingAssert($error->getMessage() === $code, 'unexpected_error');
        return;
    }
    mappingAssert(false, 'unsafe_input_accepted');
}
$names = ['Individual', 'SalesOrder', 'Account',
    'AccountContact', 'ContactPointEmail'];
$base = 'https://example.my.salesforce.com/services/data/v68.0/'
    . 'ssot/data-model-object-mappings?dmoDeveloperName=ssot__';
$probe = new G3TenantDmoCatalogProbe(
    'https://example.my.salesforce.com', 'synthetic-token'
);
$urls = [];
$fake = function ($url, $token) use (&$urls, $names, $base) {
    mappingAssert($token === 'synthetic-token', 'token_invalid');
    mappingAssert(strpos($url, $base) === 0, 'untrusted_url');
    $suffix = substr($url, strlen($base));
    mappingAssert(substr($suffix, -5) === '__dlm', 'unknown_suffix');
    $object = substr($suffix, 0, -5);
    mappingAssert(in_array($object, $names, true), 'untrusted_object');
    $urls[] = $url;
    return [
        'status' => 200,
        'body' => json_encode(['objectSourceTargetMaps' => [
            [
                'sourceEntityDeveloperName' => 'tenant-private-custom-dlo__dll',
                'targetEntityDeveloperName' => 'ssot__' . $object . '__dlm',
                'developerName' => 'tenant-secret-mapping-identifier',
                'status' => 'ACTIVE',
                'fieldMappings' => [
                    [
                        'sourceFieldDeveloperName' => 'never-log-customer-field',
                        'targetFieldDeveloperName' => 'ssot__Id__c',
                    ],
                    [
                        'sourceFieldDeveloperName' => 'private-again',
                        'targetFieldDeveloperName' => 'not_allowlisted__c',
                    ],
                ],
            ],
            [
                'sourceEntityDeveloperName' => 'another-private-dlo__dll',
                'targetEntityDeveloperName' => 'ssot__' . $object . '__dlm',
                'status' => 'CREATING',
                'fieldMappings' => [
                    ['targetFieldDeveloperName' => 'ssot__BillToAccountId__c'],
                ],
            ],
        ]]),
    ];
};
$evidence = $probe->inspectObjectMappings($fake);
mappingAssert(count($urls) === 5, 'must_use_five_literal_paths');
foreach ($names as $i => $name) {
    mappingAssert($urls[$i] === $base . $name . '__dlm', 'not_exact_get');
    $out = $evidence['objects'][$name];
    mappingAssert($out['state'] === 'mapping_metadata_observed'
        && $out['visible_mapping_count'] === 2
        && $out['visible_active_mapping_count'] === 1
        && $out['all_active_mappings_have_field_detail'] === true
        && $out['active_target_field_mapped_observed']['ssot__Id__c'],
        'mapping_evidence_not_counted');
    foreach (['full_tenant_mapping_catalog_certified',
        'pst_dlo_source_mapping_certified', 'dmo_relation_approved'] as $key) {
        mappingAssert($out[$key] === false, 'mapping_not_certified_' . $key);
    }
}
foreach (['field_mapping_activation_approved',
    'dlo_dmo_mapping_save_authorized', 'identity_stitching_authorized',
    'schema_upload_authorized', 'data_360_ingestion_authorized',
    'marketing_sends_authorized'] as $key) {
    mappingAssert($evidence[$key] === false, 'unsafe_authorization_' . $key);
}
mappingAssert(!$evidence['objects']['SalesOrder']
    ['active_target_field_mapped_observed']['ssot__BillToAccountId__c'],
    'unactivated_mapping_misreported_as_active');
$encoded = json_encode($evidence);
foreach (['tenant-private-custom-dlo', 'tenant-secret-mapping-identifier',
    'never-log-customer-field', 'private-again', 'another-private-dlo',
    'synthetic-token', 'example.my.salesforce.com', 'not_allowlisted__c'] as $secret) {
    mappingAssert(strpos($encoded, $secret) === false,
        'untrusted_metadata_escaped');
}

$failed = $probe->inspectObjectMappings(function ($url, $token) {
    return ['status' => 403, 'body' => '{"secret":"do-not-log"}'];
});
foreach ($names as $name) {
    mappingAssert($failed['objects'][$name]['state'] === 'unverified'
        && $failed['objects'][$name]['visible_mapping_count'] === null
        && $failed['objects'][$name]['visible_active_mapping_count'] === null,
        '403_should_not_mean_no_mappings');
}
$notFound = $probe->inspectObjectMappings(function ($url, $token) {
    return ['status' => 404, 'body' => 'not found'];
});
mappingAssert($notFound['objects']['SalesOrder']['state'] === 'unverified',
    '404_falsely_certified_absence');

$malformed = $probe->inspectObjectMappings(function ($url, $token) {
    return ['status'=>200, 'body'=>'{"objectSourceTargetMaps":{}}'];
});
mappingAssert($malformed['objects']['Individual']['state']
    === 'unverified_response_shape'
    && $malformed['objects']['Individual']['visible_mapping_count'] === null,
    'empty_object_must_not_mean_no_mappings');
$wrongTarget = $probe->inspectObjectMappings(function ($url, $token) {
    return ['status'=>200, 'body'=>json_encode(['objectSourceTargetMaps'=>[[
        'targetEntityDeveloperName'=>'ssot__Other__dlm',
        'sourceEntityDeveloperName'=>'tenant-secret-source',
        'status'=>'ACTIVE',
    ]]])];
});
mappingAssert($wrongTarget['objects']['SalesOrder']['state']
    === 'unverified_response_shape', 'wrong_dmo_not_blocked');
$unbounded = $probe->inspectObjectMappings(function ($url, $token) {
    return ['status'=>200, 'body'=>json_encode([
        'objectSourceTargetMaps'=>array_fill(0, 501, ['secret'=>'nope']),
    ])];
});
mappingAssert($unbounded['objects']['Individual']['state']
    === 'unverified_response_shape', 'unbounded_count_not_blocked');
$missingDetails = $probe->inspectObjectMappings(function ($url, $token) {
    $n = substr($url, strpos($url, 'dmoDeveloperName=') + 17);
    return ['status'=>200,'body'=>json_encode(['objectSourceTargetMaps'=>[[
        'targetEntityDeveloperName'=>$n,
        'sourceEntityDeveloperName'=>'synthetic_source',
        'status'=>'ACTIVE',
    ]]])];
});
mappingAssert($missingDetails['objects']['SalesOrder']['state']
    === 'mapping_metadata_observed'
    && $missingDetails['objects']['SalesOrder']
        ['all_active_mappings_have_field_detail'] === false
    && $missingDetails['objects']['SalesOrder']
        ['active_target_field_mapped_observed']['ssot__Id__c'] === false,
    'missing_detail_not_marked_unverified');

mappingReject(function () use ($probe) {
    $probe->inspectObjectMappings('untrusted');
}, 'g3_dmo_invalid_transport');
$source = file_get_contents(__DIR__ . '/review/G3TenantDmoCatalogProbe.php');
foreach (['CURLOPT_POST => true', 'CURLOPT_CUSTOMREQUEST =>',
    'CURLOPT_PUT =>', 'CURLOPT_POSTFIELDS =>',
    'CURLOPT_FOLLOWLOCATION => true', '/query/?q=',
    '/query/v1/', '/api/v1/ingest/'] as $unsafe) {
    mappingAssert(strpos($source, $unsafe) === false,
        'reader_has_write_or_record_path');
}
echo "G3 DMO mapping metadata (5 scoped GETs) PASS; no mapping approved.\n";
