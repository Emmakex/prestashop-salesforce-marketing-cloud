<?php
/**
 * Fixed synthetic upsert canary: all tests are offline and use injected
 * transport. Never read PrestaShop, never use real credentials, never POST.
 */
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/IngestionPayloadBuilder.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/GrowthTypedPstPayloadBuilder.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/GrowthStreamingIngestionPlan.php';
require_once __DIR__ . '/review/G5ControlledSyntheticUpsert.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Review\G5ControlledSyntheticUpsert as Canary;

function g5CanaryCheck($yes,$reason)
{
    if (!$yes) { throw new RuntimeException('g5_canary_contract_' . $reason); }
}
function g5CanaryReject($run,$code)
{
    try {
        $run();
    } catch (RuntimeException $error) {
        g5CanaryCheck($error->getMessage() === $code,
            'wrong_error_' . $error->getMessage());
        return;
    }
    g5CanaryCheck(false,'unsafe_accepted_' . $code);
}

$origin='https://synthetic.c360a.salesforce.com';
$fixed=Canary::NAMESPACE_VALUE.':'.Canary::SHOP.':';
$expected=[
    'PSCustomer'=>$fixed.'customer:'.Canary::ID,
    'PSOrder'=>$fixed.'order:'.Canary::ID,
];
foreach($expected as $object=>$key) {
    $packet=Canary::prepare($origin,$object);
    $decoded=json_decode($packet['body'],true);
    g5CanaryCheck($packet['status']==='blocked_transport_plan_only'
        && $packet['count']===1 && $packet['method']==='POST'
        && $packet['url']===$origin.'/api/v1/ingest/sources/'
            .Canary::SOURCE.'/'.$object
        && $packet['dispatch_authorized']===false
        && $packet['marketing_sends_authorized']===false
        && $decoded['data'][0]['source_key']===$key
        && $decoded['data'][0]['source_namespace']===Canary::NAMESPACE_VALUE,
        'shape_'.$object);
    g5CanaryCheck(substr($packet['url'],-13)!=='/actions/test',
        'validation_endpoint_used');
    if($object==='PSCustomer') {
        g5CanaryCheck($decoded['data'][0]['email']
            ==='g5-canary-20261117@example.invalid'
            && $decoded['data'][0]['newsletter_flag']===false
            && $decoded['data'][0]['partner_optin_flag']===false
            && $decoded['data'][0]['active_flag']===false
            && count($decoded['data'][0])===11,
            'synthetic_contact_not_safe');
    } else {
        g5CanaryCheck($decoded['data'][0]['customer_source_key']
            ===$expected['PSCustomer']
            && $decoded['data'][0]['cart_source_key']===''
            && $decoded['data'][0]['paid_flag']===false
            && (float)$decoded['data'][0]['total_paid']===0.0
            && count($decoded['data'][0])===11,
            'synthetic_order_not_safe');
    }
}
g5CanaryReject(function()use($origin){
    Canary::prepare($origin,'PSCart');
},'g5_canary_object_forbidden');
g5CanaryReject(function(){
    Canary::prepare('http://synthetic.c360a.salesforce.com','PSCustomer');
},'g7_streaming_origin_invalid');
g5CanaryReject(function(){
    Canary::prepare('https://example.com','PSCustomer');
},'g7_streaming_origin_invalid');

$received=[];
$fake=function($url,$body,$token)use(&$received){
    $received[]=['url'=>$url,'data'=>json_decode($body,true)];
    return ['status'=>202,'received'=>true];
};
$fakeToken='fake-only-test-token-never-live';
g5CanaryReject(function()use($origin,$fakeToken,$fake){
    Canary::execute($origin,$fakeToken,'PSCustomer',$fake);
},'g5_canary_manual_approval_required');
putenv('GITHUB_REPOSITORY=Emmakex/prestashop-salesforce-marketing-cloud');
putenv('GITHUB_REF=refs/heads/main');
putenv('GITHUB_EVENT_NAME=workflow_dispatch');
putenv('G5_CANARY_APPROVAL='.Canary::APPROVAL);
putenv('G5_CANARY_MODE='.Canary::CUSTOMER_MODE);
$customer=Canary::execute($origin,$fakeToken,'PSCustomer',$fake);
g5CanaryCheck(count($received)===1
    && $customer['http_status']===202
    && $customer['upsert_attempted']===true
    && $customer['accepted_asynchronously_unconfirmed']===true
    && $customer['dlo_record_verified']===false
    && $customer['dmo_mapping_verified']===false
    && $customer['automatic_retry_authorized']===false
    && $customer['marketing_sends_authorized']===false,
    '202_not_held_for_reconciliation');
g5CanaryReject(function()use($origin,$fakeToken,$fake){
    Canary::execute($origin,$fakeToken,'PSOrder',$fake);
},'g5_canary_sequence_not_approved');
putenv('G5_CANARY_MODE='.Canary::ORDER_MODE);
g5CanaryReject(function()use($origin,$fakeToken,$fake){
    Canary::execute($origin,$fakeToken,'PSOrder',$fake);
},'g5_canary_sequence_not_approved');
putenv('G5_CANARY_CUSTOMER_CONFIRMED=CUSTOMER_DLO_VERIFIED');
$order=Canary::execute($origin,$fakeToken,'PSOrder',$fake);
g5CanaryCheck(count($received)===2
    && $order['object']==='PSOrder'
    && $order['accepted_asynchronously_unconfirmed']===true
    && $received[1]['data']['data'][0]['customer_source_key']
        ===$received[0]['data']['data'][0]['source_key'],
    'order_not_linked_to_single_canary');
$negative=Canary::execute($origin,$fakeToken,'PSOrder',
    function(){return ['status'=>400,'received'=>true];});
g5CanaryCheck(!$negative['accepted_asynchronously_unconfirmed']
    && $negative['outcome_state']==='request_or_schema_requires_review',
    '400_false_positive');
$timeout=Canary::execute($origin,$fakeToken,'PSOrder',
    function(){return ['status'=>0,'received'=>false];});
g5CanaryCheck(!$timeout['accepted_asynchronously_unconfirmed']
    && $timeout['outcome_state']==='unknown_after_transport'
    && !$timeout['automatic_retry_authorized'],
    'timeout_auto_retry');
g5CanaryReject(function()use($origin,$fakeToken){
    Canary::execute($origin,$fakeToken,'PSOrder','not-a-function');
},'g5_canary_transport_invalid');

$module=file_get_contents(dirname(__DIR__).'/pssalesforcebridge/pssalesforcebridge.php');
g5CanaryCheck(strpos($module,'G5ControlledSyntheticUpsert')===false,
    'canary_connected_to_shop_runtime');
$workflow=file_get_contents(dirname(__DIR__)
    .'/.github/workflows/g5-controlled-synthetic-upsert.yml');
g5CanaryCheck(strpos($workflow,'salesforce-growth-certification')!==false
    && strpos($workflow,"customer_upsert_once")!==false
    && strpos($workflow,"preview_only")!==false,
    'manual_protection_missing');

// Remove local fake-approval variables.
foreach([
    'GITHUB_REPOSITORY','GITHUB_REF','GITHUB_EVENT_NAME',
    'G5_CANARY_APPROVAL','G5_CANARY_MODE','G5_CANARY_CUSTOMER_CONFIRMED'
] as $env) { putenv($env); }
echo "G5 one-record canary PASS: scoped synthetic customer then order, strict manual approval, 202 pending, no HTTP.\n";
