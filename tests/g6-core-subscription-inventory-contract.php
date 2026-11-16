<?php
/** Synthetic-only G6 bounded Salesforce Core subscription configuration reads. */
require_once __DIR__ . '/review/G6CoreSubscriptionInventory.php';
use Emmakex\PrestaShopSalesforceMarketingCloud\Review\G6CoreSubscriptionInventory as Inventory;

function g6InvAssert($yes,$reason) { if (!$yes) { fwrite(STDERR,"G6 inventory FAIL ".$reason."\n"); exit(1); } }
function g6InvFail($fn,$error) {
    try { $fn(); } catch (RuntimeException $e) {
        g6InvAssert($e->getMessage()===$error,'wrong_'. $e->getMessage()); return;
    }
    g6InvAssert(false,'unsafe_accepted_'. $error);
}
$origin='https://example.my.salesforce.com';
$token='synthetic_test_token_1234567890';
$used=[];
$fake=function($url,$gotToken)use(&$used,$origin,$token){
    g6InvAssert($gotToken===$token,'token_mismatch');
    g6InvAssert(strpos($url,$origin.'/services/data/v68.0/')===0,'unexpected_endpoint');
    $suffix=substr($url,strlen($origin.'/services/data/v68.0/'));
    $used[]=$suffix;
    if($suffix==='sobjects/CommSubscription/describe'){
        return ['status'=>200,'body'=>json_encode(['name'=>'CommSubscription',
            'fields'=>[['name'=>'Name'],['name'=>'IsDefault'],
                ['name'=>'SensitiveOtherField']]])];
    }
    g6InvAssert(strpos($suffix,'query/?q=')===0,'unexpected_non_GET_path');
    $soql=rawurldecode(substr($suffix,strlen('query/?q=')));
    g6InvAssert($soql==='SELECT Name, IsDefault FROM CommSubscription LIMIT 20',
        'unbounded_or_pii_soql');
    return ['status'=>200,'body'=>json_encode(['totalSize'=>2,'done'=>true,'records'=>[
        ['attributes'=>['url'=>'hidden_private_url'],'Name'=>'Marketing','IsDefault'=>true],
        ['Name'=>'Other confidential campaign','IsDefault'=>false],
    ]])];
};
$r=Inventory::inspect($origin,$token,$fake);
g6InvAssert(count($used)===2
    && $r['status']==='core_subscription_inventory_observed'
    && $r['core_record_count_in_first_page']===2
    && $r['marketing_name_seen_in_bounded_page']===true
    && $r['core_default_count_in_bounded_page']===1
    && $r['growth_subscription_confirmed']===false
    && $r['data_cloud_csc_checked']===false
    && $r['marketing_sends_authorized']===false,'safe_result_expected');
foreach(['Other confidential campaign','hidden_private_url',
    'example.my.salesforce.com','synthetic_test_token'] as $secret){
    g6InvAssert(strpos(json_encode($r),$secret)===false,'leaked_results');
}
$limited=Inventory::inspect($origin,$token,function($url,$t)use($fake){
    if(strpos($url,'/query/?q=')!==false){
        return ['status'=>200,'body'=>json_encode(['totalSize'=>21,'done'=>false,
            'records'=>[['Name'=>'Marketing','IsDefault'=>true]]])];
    }
    return $fake($url,$t);
});
g6InvAssert($limited['status']==='core_subscription_inventory_partial'
    && !$limited['core_inventory_complete'],'partial_not_detected');
$forbidden=Inventory::inspect($origin,$token,function($url,$t){
    return ['status'=>403,'body'=>'{"error":"PRIVATE_ORG"}'];
});
g6InvAssert($forbidden['status']==='core_subscription_describe_unavailable'
    && $forbidden['marketing_name_seen_in_bounded_page']===null
    && strpos(json_encode($forbidden),'PRIVATE_ORG')===false,
    '403_cannot_mean_absent');
$notFound=Inventory::inspect($origin,$token,function($url,$t){
    return ['status'=>404,'body'=>'{"error":"PRIVATE"}'];
});
g6InvAssert($notFound['status']==='core_subscription_describe_unavailable'
    && $notFound['query_http_status']===404,'404_not_verified');
$bad=Inventory::inspect($origin,$token,function($url,$t){
    return ['status'=>200,'body'=>'{"name":"CommSubscription","fields":[{"name":"SecretField"}]}'];
});
g6InvAssert($bad['status']==='core_subscription_name_unavailable','missing_name_field');
$corrupt=Inventory::inspect($origin,$token,function($url,$t){
    if(strpos($url,'/query/?q=')!==false){
        return ['status'=>200,'body'=>'{"records":[{"Name":"Marketing","IsDefault":"true"}],"totalSize":1,"done":true}'];
    }
    return ['status'=>200,'body'=>'{"name":"CommSubscription","fields":[{"name":"Name"},{"name":"IsDefault"}]}'];
});
g6InvAssert($corrupt['status']==='core_subscription_row_unverified',
    'bad_bool_cannot_be_default');
foreach(['https://example.com','http://example.my.salesforce.com',
    'https://example.my.salesforce.com:444',
    'https://example.my.salesforce.com/path',
    'https://user:pw@example.my.salesforce.com'] as $invalid) {
    g6InvFail(function()use($invalid,$token,$fake){
        Inventory::inspect($invalid,$token,$fake);
    },'g6_subscription_invalid_core_origin');
}
g6InvFail(function()use($origin,$fake){
    Inventory::inspect($origin,'x',$fake);
},'g6_subscription_invalid_auth');
$module=file_get_contents(dirname(__DIR__).'/pssalesforcebridge/pssalesforcebridge.php');
g6InvAssert(strpos($module,'G6CoreSubscriptionInventory')===false,
    'catalog_not_wired_into_module');
echo "G6 Core subscription read-only inventory contract PASS; no consent records, no send, no import.\n";
