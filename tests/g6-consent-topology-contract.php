<?php
/** G6 two-GET consent source topology, synthetic metadata ONLY, PHP 7.1+. */
require_once __DIR__ . '/review/G6ConsentSourceTopologyProbe.php';
function topologyAssert($ok,$reason) {
    if (!$ok) { fwrite(STDERR,"G6 topology FAIL: ".$reason."\n"); exit(1); }
}
function topologyReject($callback,$expected) {
    try { $callback(); } catch (RuntimeException $error) {
        topologyAssert($error->getMessage()===$expected,'unexpected_exception');
        return;
    }
    topologyAssert(false,'unsafe_accepted');
}
$origin='https://tenant.my.salesforce.com';
$token='ci_review_synthetic_token_only_abcdef123';
$mapping='https://tenant.my.salesforce.com/services/data/v68.0/ssot/'
    . 'data-model-object-mappings?dmoDeveloperName='
    . 'ssot__CommunicationSubscriptionConsent__dlm&dataspace=default';
$streams='https://tenant.my.salesforce.com/services/data/v68.0/ssot/'
    . 'data-streams?limit=200&offset=0&orderBy=DeveloperName';
$mappingBody=['objectSourceTargetMaps'=>[
    ['targetEntityDeveloperName'=>'ssot__CommunicationSubscriptionConsent__dlm',
     'sourceEntityDeveloperName'=>'PRIVATE_SOURCE_DO_NOT_EXPORT','status'=>'ACTIVE',
     'fieldMappings'=>[['targetFieldDeveloperName'=>'PRIVATE_FIELD_DO_NOT_EXPORT']]],
    ['targetEntityDeveloperName'=>'ssot__CommunicationSubscriptionConsent__dlm',
     'sourceEntityDeveloperName'=>'OTHER_PRIVATE_SOURCE','status'=>'CREATING'],
]];
$streamBody=['dataStreams'=>[
    ['name'=>'MessagingConsentV2-MessagingConsent',
     'secret'=>'PRIVATE_STREAM_DETAILS'],
    ['developerName'=>'ConsentAuditTrailV2-ConsentAuditTrail'],
    ['label'=>'CommSubscription_001ABC123XYZ'],
    ['name'=>'CommSubscriptionChannelType_001ABC123XYZ'],
    ['name'=>'ARBITRARY_CUSTOMER_NAMED_STREAM_SHOULD_NOT_LEAK'],
]];
$calls=[];
$fake=function($url,$gotToken)use(&$calls,$token,$mapping,$streams,$mappingBody,$streamBody) {
    topologyAssert($gotToken===$token,'token_bad');
    topologyAssert(in_array($url,[$mapping,$streams],true),'unexpected_url');
    $calls[]=$url;
    return $url===$mapping
        ? ['status'=>200,'body'=>json_encode($mappingBody)]
        : ['status'=>200,'body'=>json_encode($streamBody)];
};
$result=G6ConsentSourceTopologyProbe::inspect($origin,$token,$fake);
topologyAssert($calls===[$mapping,$streams],'unexpected_transport_calls');
topologyAssert($result['csc_mapping']['state']==='mapping_metadata_observed_review_only'
    && $result['csc_mapping']['visible_mapping_count']===2
    && $result['csc_mapping']['active_mapping_count']===1
    && $result['csc_mapping']['absence_certified']===false,'mapped_metadata_not_bounded');
$f=$result['consent_related_streams'];
topologyAssert($f['state']==='first_page_observed_review_only'
    && $f['first_page_count']===5 && $f['first_page_only']===true
    && $f['missing_streams_certified']===false
    && $f['source_kit_installed_certified']===false,'catalog_claim_too_strong');
foreach($f['expected_names_seen_in_first_page'] as $name=>$found) {
    topologyAssert($found===true,'failed_to_find_known_'.$name);
}
foreach(['individual_consent_records_read','customer_data_read',
    'data_cloud_ingestion_attempted','salesforce_writes_performed',
    'marketing_sends_authorized',
    'communication_subscription_consent_source_certified',
    'communication_subscription_technical_id_verified',
    'send_time_consent_cache_certified',
    'full_tenant_mapping_catalog_certified',
    'full_tenant_stream_inventory_certified'] as $flag) {
    topologyAssert($result[$flag]===false,'permission_granted_'.$flag);
}
$serialized=json_encode($result);
foreach(['PRIVATE_SOURCE_DO_NOT_EXPORT','OTHER_PRIVATE_SOURCE',
    'PRIVATE_FIELD_DO_NOT_EXPORT',
    'ARBITRARY_CUSTOMER_NAMED_STREAM_SHOULD_NOT_LEAK',
    $origin,$token] as $bad) {
    topologyAssert(strpos($serialized,$bad)===false,'secret_or_other_stream_leaked');
}
foreach([[403,404],[404,403],[401,429]] as $codes) {
    $out=G6ConsentSourceTopologyProbe::inspect($origin,$token,
        function($url,$auth)use($mapping,$codes) {
            $status=$url===$mapping ? $codes[0] : $codes[1];
            return ['status'=>$status,'body'=>'{"secret":"PRIVATE_ERROR_DO_NOT_EXPORT"}'];
        });
    topologyAssert($out['csc_mapping']['state']==='mapping_metadata_http_unverified'
        && $out['consent_related_streams']['state']==='stream_inventory_http_unverified'
        && $out['csc_mapping']['absence_certified']===false
        && $out['consent_related_streams']['missing_streams_certified']===false
        && strpos(json_encode($out),'PRIVATE_ERROR_DO_NOT_EXPORT')===false,
        'http_errors_improperly_look_empty');
}
$unknownMapping=G6ConsentSourceTopologyProbe::inspect($origin,$token,
    function($url,$auth)use($mapping) {
        if ($url===$mapping) return ['status'=>200,'body'=>'{"objectSourceTargetMaps":[]}'];
        return ['status'=>200,'body'=>'{"dataStreams":[]}'];
    });
topologyAssert($unknownMapping['csc_mapping']['visible_mapping_count']===0
    && !$unknownMapping['csc_mapping']['absence_certified']
    && !$unknownMapping['consent_related_streams']['missing_streams_certified'],
    'empty_visible_page_not_absence');
$malformed=G6ConsentSourceTopologyProbe::inspect($origin,$token,
    function($url,$auth)use($mapping) {
        return $url===$mapping
            ? ['status'=>200,'body'=>'{"objectSourceTargetMaps":[{"status":"ACTIVE","targetEntityDeveloperName":"other_dmo"}]}']
            : ['status'=>200,'body'=>'{"dataStreams":"broken"}'];
    });
topologyAssert($malformed['csc_mapping']['state']==='mapping_metadata_shape_unverified'
    && $malformed['consent_related_streams']['state']==='stream_inventory_shape_unverified',
    'wrong_or_malformed_shapes_not_blocked');
foreach(['https://evil.example.com','http://tenant.my.salesforce.com',
    'https://user:pass@tenant.my.salesforce.com',
    'https://tenant.my.salesforce.com/records',
    'https://tenant.my.salesforce.com?foo=private'] as $badOrigin) {
    topologyReject(function()use($badOrigin,$token,$fake) {
        G6ConsentSourceTopologyProbe::inspect($badOrigin,$token,$fake);
    },'g6_topology_invalid_origin');
}
topologyReject(function()use($origin,$fake){
    G6ConsentSourceTopologyProbe::inspect($origin,'short',$fake);
},'g6_topology_invalid_token');
topologyReject(function()use($origin,$token){
    G6ConsentSourceTopologyProbe::inspect($origin,$token,
        function(){return ['status'=>'200','body'=>'{}'];});
},'g6_topology_invalid_http_response');
$module=file_get_contents(dirname(__DIR__).'/pssalesforcebridge/pssalesforcebridge.php');
topologyAssert(strpos($module,'G6ConsentSourceTopologyProbe')===false,
    'not_connected_to_live_module');
echo "G6 consent source topology GET-only PASS: fixed paths, safe metadata, no records/sends.\n";
