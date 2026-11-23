<?php
/**
 * Exact metadata resolution for two existing PST Data Cloud DLOs.
 * Synthetic only: no token, no network, no merchant PII.
 */
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/DataCloud/GrowthG8DloMetadataResolver.php';
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\GrowthG8DloMetadataResolver as Resolver;

function metadataAssert($yes,$label) {
    if (!$yes)throw new RuntimeException('g8_metadata_contract_'.$label);
}
function metadataReject($fn,$label) {
    try {$fn();}catch(RuntimeException $error){
        metadataAssert(strpos($error->getMessage(),'g8_dlo_metadata_')===0,
            $label.'_error_not_sanitized');
        return;
    }
    metadataAssert(false,$label.'_not_blocked');
}
$names=[
    'PSCustomer'=>'Real_PST_PSCustomer__dll',
    'PSOrder'=>'Real_PST_PSOrder__dll',
];
$entities=[
    'done'=>true,'entities'=>[
        ['entityName'=>$names['PSCustomer'],
            'displayName'=>'PST-PSCustomer','entityType'=>'DataLakeObject'],
        ['entityName'=>$names['PSOrder'],
            'displayName'=>'PST-PSOrder','entityType'=>'DataLakeObject'],
        ['entityName'=>'NotOurCustom__dlm',
            'displayName'=>'PST-PSCustomer','entityType'=>'DataModelObject'],
    ]
];
$load=function($name)use($names) {
    if ($name===$names['PSCustomer']) {
        return ['metadata'=>[[
            'name'=>$name,'fields'=>[
                ['name'=>'source_key__c'],['name'=>'customer_id__c'],
                ['name'=>'email__c']
            ]
        ]]];
    }
    if ($name===$names['PSOrder']) {
        return ['metadata'=>[[
            'name'=>$name,'fields'=>[
                ['name'=>'source_key__c'],
                ['name'=>'customer_source_key__c'],
                ['name'=>'order_id__c']
            ]
        ]]];
    }
    throw new RuntimeException('unapproved_entity_fetch');
};
$resolved=Resolver::resolve($entities,$load);
metadataAssert(
    $resolved['PSCustomer']['dlo']===$names['PSCustomer']
    && $resolved['PSCustomer']['key']==='source_key__c'
    && $resolved['PSOrder']['dlo']===$names['PSOrder']
    && $resolved['PSOrder']['key']==='source_key__c'
    && $resolved['PSOrder']['customer_key']==='customer_source_key__c',
    'exact_api_names_not_resolved'
);
$partial=$entities;$partial['done']=false;
metadataReject(function()use($partial,$load){
    Resolver::resolve($partial,$load);
},'partial_entities');
$partial=$entities;$partial['nextBatchId']='unexpected';
metadataReject(function()use($partial,$load){
    Resolver::resolve($partial,$load);
},'catalog_paged');
$dup=$entities;$dup['entities'][]=$entities['entities'][0];
metadataReject(function()use($dup,$load){
    Resolver::resolve($dup,$load);
},'duplicate_display_name');
$dup=$entities;$dup['entities'][0]['entityName']='Wrong;DROP TABLE';
metadataReject(function()use($dup,$load){
    Resolver::resolve($dup,$load);
},'sql_identifier_injection');
$dup=$entities;$dup['entities'][0]['displayName']='Not PST';
metadataReject(function()use($dup,$load){
    Resolver::resolve($dup,$load);
},'wrong_display_name');
$wrongMeta=function($name)use($load,$names){
    $s=$load($name);
    if($name===$names['PSOrder'])$s['metadata'][0]['name']='Other_DLO__dll';
    return $s;
};
metadataReject(function()use($entities,$wrongMeta){
    Resolver::resolve($entities,$wrongMeta);
},'different_metadata_object');
$missing=function($name)use($load,$names){
    $s=$load($name);
    if($name===$names['PSOrder']) {
        $s['metadata'][0]['fields']=[
            ['name'=>'source_key__c'],
            ['name'=>'order_id__c'],
        ];
    }
    return $s;
};
metadataReject(function()use($entities,$missing){
    Resolver::resolve($entities,$missing);
},'missing_fk');
$ambiguous=function($name)use($load,$names){
    $s=$load($name);
    if($name===$names['PSCustomer']){
        $s['metadata'][0]['fields'][]=['name'=>'source_key'];
    }
    return $s;
};
metadataReject(function()use($entities,$ambiguous){
    Resolver::resolve($entities,$ambiguous);
},'ambiguous_field');
$bad=function($name)use($load) {
    $s=$load($name);
    $s['metadata'][0]['fields'][0]['name']='source_key__c;DROP';
    return $s;
};
metadataReject(function()use($entities,$bad){
    Resolver::resolve($entities,$bad);
},'injected_key');
metadataReject(function()use($entities){
    Resolver::resolve($entities,function(){
        throw new RuntimeException('sensitive_sql=private@example.test');
    });
},'private_error_propagation'); // handled by a safe outer wrapper below

/* HTTP diagnostic is safe to expose ONLY if its value is allowlisted. */
foreach (['g8_metadata_http_status_403','g8_metadata_http_status_404',
    'g8_metadata_http_status_0','g8_metadata_http_status_other',
    'g8_metadata_json_unverified'] as $httpCode) {
    try {
        Resolver::resolve($entities,function()use($httpCode) {
            throw new RuntimeException($httpCode);
        });
        metadataAssert(false,'http_status_should_fail');
    } catch (RuntimeException $error) {
        metadataAssert($error->getMessage()===$httpCode,
            'allowlisted_detail_http_not_preserved');
    }
}
try {
    Resolver::resolve($entities,function(){
        throw new RuntimeException('tenant_host_api_token_private');
    });
    metadataAssert(false,'private_callback_not_blocked');
} catch (RuntimeException $error) {
    metadataAssert($error->getMessage()==='g8_dlo_metadata_lookup_failed',
        'private_callback_error_not_scrubbed');
}
echo "G8 metadata resolver PASS: strict PST DLOs and source key fields, ambiguity/no guess; no network.\n";
