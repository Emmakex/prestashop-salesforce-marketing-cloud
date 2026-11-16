<?php
/** G6 CSC DMO one-GET metadata-only contract (PHP 7.1+, synthetic). */
require_once __DIR__ . '/review/G6CscDmoMetadataProbe.php';
function g6MetaAssert($ok,$why) {
    if (!$ok) { fwrite(STDERR,"G6 CSC metadata FAIL: ".$why."\n"); exit(1); }
}
function g6MetaReject($fn,$reason) {
    try { $fn(); } catch (RuntimeException $e) {
        g6MetaAssert($e->getMessage() === $reason,'wrong_exception');
        return;
    }
    g6MetaAssert(false,'unsafe_accepted');
}
$origin = 'https://tenant.my.salesforce.com';
$token = 'synthetic-core-oauth-token-123456';
$fields = [
    'ssot__Id__c',
    'ssot__CommunicationSubscriptionId__c',
    'ssot__ConsentStatusId__c',
    'ssot__ContactPointId__c',
    'ssot__PartyId__c',
    'ssot__EngagementChannelTypeId__c',
    'ssot__EffectiveFromDate__c',
    'ssot__EffectiveToDate__c'
];
$fixture = ['name'=>'ssot__CommunicationSubscriptionConsent__dlm',
    'category'=>'OTHER', 'dataSpaceName'=>'default',
    'fields'=>array_map(function($name) {
        return ['name'=>$name,'isPrimaryKey'=>$name==='ssot__Id__c'];
    },$fields)
];
$fixture['fields'][] = ['name'=>'private_merchant_field__c'];
$called=0;
$get=function($url,$bearer)use(&$called,$origin,$token,$fixture) {
    ++$called;
    g6MetaAssert($url===$origin.G6CscDmoMetadataProbe::API_PATH,
        'unexpected_metadata_path');
    g6MetaAssert($bearer===$token,'token_mismatch');
    return ['status'=>200,'body'=>json_encode($fixture)];
};
$report=G6CscDmoMetadataProbe::inspect($origin,$token,$get);
g6MetaAssert($called===1,'not_exactly_one_get');
g6MetaAssert($report['status']==='csc_dmo_schema_candidate_seen'
    && $report['primary_key_metadata_seen']
    && count($report['documented_fields_present'])===8,
    'metadata_fields_unverified');
foreach($report['documented_fields_present'] as $key=>$seen) {
    g6MetaAssert(in_array($key,$fields,true) && $seen===true,
        'unexpected_field_summary');
}
foreach(['personal_consent_records_read','customer_data_read',
    'data_cloud_ingestion_attempted','salesforce_mutations',
    'marketing_sends_authorized','marketing_subscription_record_id_verified',
    'csc_dmo_has_consent_records_verified',
    'consent_source_usable_for_growth_sends_verified'] as $flag) {
    g6MetaAssert($report[$flag]===false,'unexpected_permission_'.$flag);
}
$json=json_encode($report);
foreach(['private_merchant_field__c',$token,$origin,'customer@example.com']
    as $secret) {
    g6MetaAssert(strpos($json,$secret)===false,'response_leak');
}
foreach([401,403,404,429,503] as $status) {
    $out=G6CscDmoMetadataProbe::inspect($origin,$token,
        function()use($status){ return ['status'=>$status,'body'=>'{"sensitive":"secret"}']; });
    g6MetaAssert($out['status']==='csc_dmo_metadata_unverified'
        && $out['http_status']===$status
        && $out['csc_dmo_has_consent_records_verified']===false
        && strpos(json_encode($out),'secret')===false,
        'non200_misclassified');
}
$partial=$fixture;
array_pop($partial['fields']); // remove private field, not a required field
$partial['fields']=array_slice($partial['fields'],0,-1);
$out=G6CscDmoMetadataProbe::inspect($origin,$token,function()use($partial){
    return ['status'=>200,'body'=>json_encode($partial)];
});
g6MetaAssert($out['status']==='csc_dmo_schema_partial','missing_field_passed');
$wrong=$fixture; $wrong['name']='DifferentDmo';
$out=G6CscDmoMetadataProbe::inspect($origin,$token,function()use($wrong){
    return ['status'=>200,'body'=>json_encode($wrong)];
});
g6MetaAssert($out['status']==='csc_dmo_metadata_invalid','wrong_dmo_passed');
foreach(['http://tenant.my.salesforce.com',
    'https://tenant.my.salesforce.com:8080',
    'https://tenant.my.salesforce.com/path',
    'https://evil.example.com',
    'https://user:password@tenant.my.salesforce.com'] as $invalid) {
    g6MetaReject(function()use($invalid,$token,$get){
        G6CscDmoMetadataProbe::inspect($invalid,$token,$get);
    },'g6_csc_invalid_core_origin');
}
g6MetaReject(function()use($origin,$get) {
    G6CscDmoMetadataProbe::inspect($origin,'secret',$get);
},'g6_csc_invalid_token');
$module=file_get_contents(dirname(__DIR__).'/pssalesforcebridge/pssalesforcebridge.php');
g6MetaAssert(strpos($module,'G6CscDmoMetadataProbe')===false,
    'review_probe_wired_into_live_module');
echo "G6 CSC DMO metadata-only GET contract PASS: no records, token, consent or sends.\n";
