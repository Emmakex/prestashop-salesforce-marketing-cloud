<?php
/** G6 only: Core Marketing -> Email configuration. No actual consent rows. */
require_once __DIR__ . '/review/G6MarketingEmailCoreBindingReview.php';

function g6BindAssert($condition, $why) {
    if (!$condition) throw new RuntimeException('g6_binding_test_' . $why);
}
function g6BindFixture($recordCount = 1) {
    $records = [];
    for ($i=0; $i<$recordCount; ++$i) {
        $records[] = [
            'attributes'=>['url'=>'/services/data/private-record-id'],
            'CommunicationSubscriptionId'=>($i===0
                ? '0al000000000001AAA' : '0al000000000002AAA'),
            'CommunicationSubscription'=>['Name'=>'Marketing'],
            'EngagementChannelType'=>['ContactPointType'=>'Email']
        ];
    }
    return json_encode(['totalSize'=>$recordCount,'done'=>true,'records'=>$records]);
}
function g6BindSafe($result, $expected, $seen) {
    g6BindAssert($result['binding_state'] === $expected
        && $result['status'] === 'hold_for_review'
        && $result['single_marketing_email_link_observed'] === $seen,
        'state_' . $expected);
    foreach ([
        'salesforce_subscription_id_exported','salesforce_channel_id_exported',
        'consumer_consent_records_read','csc_effective_consent_certified',
        'business_unit_scope_certified','recipient_consent_read_authorized',
        'data_cloud_ingestion_authorized','marketing_sends_authorized'
    ] as $flag) {
        g6BindAssert($result[$flag] === false, 'unsafe_flag_' . $flag);
    }
    foreach (['0al000000000001AAA','private-record-id','example.my.salesforce.com',
        'synthetic_oauth_token','@', 'Marketing', 'Email'] as $notOutput) {
        g6BindAssert(strpos(json_encode($result), $notOutput) === false,
            'raw_value_leaked');
    }
}
$origin='https://example.my.salesforce.com';
$token='synthetic_oauth_token_123456789';
$calls=[];
$fetch=function($url,$auth) use (&$calls,$origin,$token) {
    g6BindAssert($auth===$token,'token_transport');
    g6BindAssert(strpos($url,$origin.'/services/data/v68.0/query/?q=')===0,
        'not_fixed_core_query');
    $query=rawurldecode(substr($url,strlen($origin.'/services/data/v68.0/query/?q=')));
    g6BindAssert($query===G6MarketingEmailCoreBindingReview::SOQL
        && strpos($query,'LIMIT 2')!==false
        && strpos($query,"WHERE CommunicationSubscription.Name = 'Marketing'")!==false
        && strpos($query,"EngagementChannelType.ContactPointType = 'Email'")!==false,
        'unbounded_query');
    $calls[]=$url;
    return ['status'=>200,'body'=>g6BindFixture()];
};
$r=G6MarketingEmailCoreBindingReview::inspect($origin,$token,$fetch);
g6BindSafe($r,'marketing_email_core_binding_observed_review_only',true);
g6BindAssert(count($calls)===1,'not_one_get');
g6BindSafe(G6MarketingEmailCoreBindingReview::inspect($origin,$token,
    function($url,$t){return ['status'=>200,'body'=>g6BindFixture(2)];}),
    'core_binding_ambiguous_review_only',false);
g6BindSafe(G6MarketingEmailCoreBindingReview::inspect($origin,$token,
    function($url,$t){return ['status'=>200,'body'=>g6BindFixture(0)];}),
    'core_binding_not_observed_review_only',false);
g6BindSafe(G6MarketingEmailCoreBindingReview::inspect($origin,$token,
    function($url,$t){return ['status'=>403,'body'=>'{"private":"secret"}'];}),
    'core_config_http_unverified',false);
g6BindSafe(G6MarketingEmailCoreBindingReview::inspect($origin,$token,
    function($url,$t){throw new RuntimeException('private host error');}),
    'core_config_transport_unverified',false);
g6BindSafe(G6MarketingEmailCoreBindingReview::inspect($origin,$token,
    function($url,$t) {
        $data=json_decode(g6BindFixture(),true);
        $data['records'][0]['CommunicationSubscription']['Name']='Other';
        return ['status'=>200,'body'=>json_encode($data)];
    }), 'core_config_result_unverified',false);
g6BindSafe(G6MarketingEmailCoreBindingReview::inspect($origin,$token,
    function($url,$t) {
        $data=json_decode(g6BindFixture(),true);
        $data['done']=false;
        return ['status'=>200,'body'=>json_encode($data)];
    }), 'core_config_result_unverified',false);
foreach (['http://example.my.salesforce.com',
    'https://example.com','https://example.my.salesforce.com/path',
    'https://user:p@example.my.salesforce.com',
    'https://example.my.salesforce.com:444'] as $bad) {
    try {
        G6MarketingEmailCoreBindingReview::inspect($bad,$token,$fetch);
        g6BindAssert(false,'unsafe_origin_accepted');
    } catch (RuntimeException $error) {
        g6BindAssert($error->getMessage()==='g6_binding_invalid_core_origin',
            'wrong_origin_rejection');
    }
}
$module=file_get_contents(dirname(__DIR__)
    . '/pssalesforcebridge/pssalesforcebridge.php');
g6BindAssert(strpos($module,'G6MarketingEmailCoreBindingReview')===false,
    'binding_probe_wired_to_production');
echo "G6 Core Marketing Email technical binding review PASS; one GET, no IDs exported, no consent and no sends.\n";
