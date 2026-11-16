<?php
require_once __DIR__.'/review/G6MarketingEmailCoreBindingReview.php';

function checkG6Link($yes,$reason) {
    if (!$yes) throw new RuntimeException('g6_core_link_'.$reason);
}
function fakeLinkRows($count=1) {
    $records=[];
    for ($i=0;$i<$count;++$i) {
        $records[]=[
            'Id'=> $i===0?'0al000000000001AAA':'0al000000000002AAA',
            'CommunicationSubscriptionId'=> $i===0
                ?'0ac000000000001AAA':'0ac000000000002AAA',
            'CommunicationSubscription'=>['Name'=>'Marketing'],
            'attributes'=>['url'=>'private-record-path']
        ];
    }
    return json_encode(['totalSize'=>$count,'done'=>true,'records'=>$records]);
}
function safeG6Link($result,$state,$seen) {
    checkG6Link($result['status']==='hold_for_review'
        && $result['binding_state']===$state
        && $result['single_marketing_channel_link_observed']===$seen,
        'wrong_state_'.$state);
    foreach ([
        'email_channel_type_technically_verified',
        'business_unit_scope_certified',
        'customer_consent_records_read',
        'csc_effective_consent_certified',
        'ids_or_auth_exported',
        'marketing_sends_authorized',
        'data_cloud_ingestion_authorized'
    ] as $flag) {
        checkG6Link($result[$flag]===false,'unsafe_'.$flag);
    }
    foreach (['0al000000000001AAA','0ac000000000001AAA',
        'private-record-path','example.my.salesforce.com',
        'synthetic_oauth_token_123456789'] as $private) {
        checkG6Link(strpos(json_encode($result),$private)===false,'data_exposed');
    }
}
$origin='https://example.my.salesforce.com';
$token='synthetic_oauth_token_123456789';
$calls=0;
$mock=function($url,$bearer)use($origin,$token,&$calls) {
    checkG6Link($bearer===$token,'token_changed');
    $prefix=$origin.'/services/data/v68.0/query/?q=';
    checkG6Link(strpos($url,$prefix)===0,'wrong_endpoint');
    $query=rawurldecode(substr($url,strlen($prefix)));
    checkG6Link($query===G6MarketingEmailCoreBindingReview::SOQL
        && strpos($query,"CommunicationSubscription.Name = 'Marketing'")!==false
        && strpos($query,'LIMIT 2')!==false,'wrong_query');
    ++$calls;
    return ['status'=>200,'body'=>fakeLinkRows()];
};
safeG6Link(G6MarketingEmailCoreBindingReview::inspect($origin,$token,$mock),
    'marketing_core_channel_link_observed_review_only',true);
checkG6Link($calls===1,'not_one_get');
$stateCases=[
    [200,fakeLinkRows(2),'core_link_ambiguous_review_only'],
    [200,fakeLinkRows(0),'core_link_not_observed_review_only'],
    [403,'{"error":"private"}','core_config_http_unverified'],
    [200,'{"done":false,"totalSize":1,"records":[]}',
        'core_config_result_unverified'],
];
foreach ($stateCases as $case) {
    safeG6Link(G6MarketingEmailCoreBindingReview::inspect(
        $origin,$token,function($url,$token)use($case) {
            return ['status'=>$case[0],'body'=>$case[1]];
        }),$case[2],false);
}
safeG6Link(G6MarketingEmailCoreBindingReview::inspect(
    $origin,$token,function($url,$token) {
        throw new RuntimeException('raw private error');
    }), 'core_config_transport_unverified',false);
foreach (['https://example.com','http://example.my.salesforce.com',
    'https://user:pw@example.my.salesforce.com',
    'https://example.my.salesforce.com:443',
    'https://example.my.salesforce.com/path'] as $bad) {
    try {
        G6MarketingEmailCoreBindingReview::inspect($bad,$token,$mock);
        checkG6Link(false,'bad_origin_accepted');
    } catch (RuntimeException $e) {
        checkG6Link($e->getMessage()==='g6_binding_invalid_core_origin',
            'bad_origin_rejected_wrongly');
    }
}
$module=file_get_contents(dirname(__DIR__).'/pssalesforcebridge/pssalesforcebridge.php');
checkG6Link(strpos($module,'G6MarketingEmailCoreBindingReview')===false,
    'runtime_module_connected');
echo "G6 Core Marketing channel link: bounded read and fail-closed contract PASS.\n";
