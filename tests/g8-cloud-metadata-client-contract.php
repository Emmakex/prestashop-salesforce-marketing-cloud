<?php
/**
 * G8 live metadata HTTP client contract. Synthetic responses; NO network.
 */
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/DataCloud/GrowthG8DloMetadataResolver.php';
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/DataCloud/GrowthG8CloudMetadataClient.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\GrowthG8CloudMetadataClient as Client;

function cloudMetadataAssert($yes,$why) {
    if (!$yes)throw new RuntimeException('g8_cloud_metadata_'.$why);
}
$origin='https://synthetic.c360a.salesforce.com';
$token='synthetic-not-secret';
$catalog=[
    'done'=>true,'entities'=>[
        ['entityName'=>'PST_PSCustomer__dll',
            'displayName'=>'PST-PSCustomer','entityType'=>'DataLakeObject'],
        ['entityName'=>'PST_PSOrder__dll',
            'displayName'=>'PST-PSOrder','entityType'=>'DataLakeObject']
    ]
];
$calls=[];
$fetch=function($path)use(&$calls,$catalog) {
    $calls[]=$path;
    if ($path==='/api/v1/metadataEntities?entityType=DataLakeObject') {
        return $catalog;
    }
    if ($path==='/api/v1/metadata?entityType=DataLakeObject'
        .'&entityName=PST_PSCustomer__dll') {
        return ['metadata'=>[[
            'name'=>'PST_PSCustomer__dll',
            'fields'=>[['name'=>'source_key__c']]
        ]]];
    }
    if ($path==='/api/v1/metadata?entityType=DataLakeObject'
        .'&entityName=PST_PSOrder__dll') {
        return ['metadata'=>[[
            'name'=>'PST_PSOrder__dll',
            'fields'=>[
                ['name'=>'source_key__c'],
                ['name'=>'customer_source_key__c']
            ]
        ]]];
    }
    throw new RuntimeException('synthetic_external_request_disallowed');
};
$resolved=Client::resolve($origin,$token,$fetch);
cloudMetadataAssert($resolved===[
    'PSCustomer'=>[
        'dlo'=>'PST_PSCustomer__dll','key'=>'source_key__c'
    ],
    'PSOrder'=>[
        'dlo'=>'PST_PSOrder__dll','key'=>'source_key__c',
        'customer_key'=>'customer_source_key__c'
    ]
], 'resolved_names_or_keys_invalid');
cloudMetadataAssert(count($calls)===3
    && $calls[0]==='/api/v1/metadataEntities?entityType=DataLakeObject'
    && $calls[1]==='/api/v1/metadata?entityType=DataLakeObject&entityName=PST_PSCustomer__dll'
    && $calls[2]==='/api/v1/metadata?entityType=DataLakeObject&entityName=PST_PSOrder__dll',
    'wrong_endpoints_or_extra_request');
$before=count($calls);
foreach ([
    ['http://synthetic.c360a.salesforce.com',$token],
    ['https://attacker.example.com',$token],
    ['https://synthetic.c360a.salesforce.com:444',$token],
    [$origin,"private\nnot_allowed"],
    [$origin,'']
] as $case) {
    $blocked=false;
    try {Client::resolve($case[0],$case[1],$fetch);}
    catch (RuntimeException $e) {
        $blocked=$e->getMessage()==='g8_metadata_client_scope_invalid';
    }
    cloudMetadataAssert($blocked && count($calls)===$before,
        'invalid_origin_or_token_sent');
}
$blocked=false;
try {
    Client::resolve($origin,$token,function() {
        throw new RuntimeException('secret private@example.test');
    });
} catch (RuntimeException $e) {
    $blocked=$e->getMessage()==='g8_metadata_unverified';
}
cloudMetadataAssert($blocked,'exception_not_redacted');
$blocked=false;
try {
    Client::resolve($origin,$token,function() {
        return ['done'=>false,'entities'=>[]];
    });
} catch (RuntimeException $e) {
    $blocked=$e->getMessage()==='g8_metadata_unverified';
}
cloudMetadataAssert($blocked,'partial_catalog_not_blocked');
$source=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/src/DataCloud/GrowthG8CloudMetadataClient.php');
cloudMetadataAssert(strpos($source,'CURLOPT_FOLLOWLOCATION=>false')!==false
    && strpos($source,'CURLOPT_SSL_VERIFYPEER=>true')!==false
    && strpos($source,'CURLOPT_SSL_VERIFYHOST=>2')!==false
    && strpos($source,'/api/v1/metadata?entityType=DataLakeObject')!==false
    && strpos($source,'->post(')===false
    && strpos($source,'Mail::send(')===false,
    'unsafe_metadata_client');
echo "G8 metadata live adapter PASS: exact two PST DLOs, HTTPS GET only, no logging.\n";
