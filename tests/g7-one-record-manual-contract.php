<?php
/** G7 0.9.4: single native customer+order, explicit UAT, no network. */
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/DataCloud/IngestionPayloadBuilder.php';
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/DataCloud/GrowthTypedPstPayloadBuilder.php';
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/DataCloud/GrowthNativeCustomerOrderDraft.php';
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/DataCloud/GrowthStreamingIngestionPlan.php';
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/DataCloud/GrowthDataCloudUpsertTransport.php';
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthOneRecordSelection.php';
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthShopSourceBinding.php';
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthOneRecordManualSender.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthOneRecordSelection as SelectOne;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthOneRecordManualSender as SendOne;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthShopSourceBinding as SourceBinding;

class Configuration {
    public static $values=[];
    public static function get($key) {
        return array_key_exists($key,self::$values)?self::$values[$key]:false;
    }
    public static function updateValue($key,$value) {
        self::$values[$key]=$value;
        return true;
    }
}
class G7OnceDb {
    public $lock=false;
    public function getValue($sql,$useCache=true) {
        if ($useCache!==false)throw new RuntimeException('cached_lock');
        if (strpos($sql,'GET_LOCK')!==false) {
            if ($this->lock)return 0;
            $this->lock=true;return 1;
        }
        if (strpos($sql,'RELEASE_LOCK')!==false) {
            $this->lock=false;return 1;
        }
        throw new RuntimeException('unexpected_sql');
    }
}
function g7OnceAssert($ok,$label) {
    if (!$ok)throw new RuntimeException('g7_one_record_contract_'.$label);
}
function g7OnceReject($fn,$expected) {
    try {$fn();} catch (RuntimeException $e) {
        g7OnceAssert($e->getMessage()===$expected,
            'wrong_error_'.$e->getMessage());return;
    }
    g7OnceAssert(false,'accepted_invalid_'.$expected);
}
$c=(object)[
    'id'=>44,'id_shop'=>2,
    'email'=>'synthetic@example.invalid','firstname'=>'Synthetic',
    'lastname'=>'Fixture','newsletter'=>1,'optin'=>1,
    'active'=>1,'deleted'=>0,'is_guest'=>0,
    'date_upd'=>'2026-11-19 10:30:00'
];
$o=(object)[
    'id'=>77,'id_shop'=>2,'id_customer'=>44,'id_cart'=>10,
    'id_currency'=>5,'total_paid'=>'49.20','current_state'=>2,
    'date_upd'=>'2026-11-19 10:32:00'
];
$money=(object)['id'=>5,'iso_code'=>'EUR'];
$ns='growth_ci_abhtwo';
$customerLoader=function($id)use($c){return $id===44?clone $c:new stdClass();};
$orderLoader=function($id)use($o){return $id===77?clone $o:new stdClass();};
$moneyLoader=function($id)use($money){return $id===5?clone $money:new stdClass();};
$preview=function()use(&$ns,$customerLoader,$orderLoader,$moneyLoader) {
    return SelectOne::prepareInternal($ns,2,44,77,
        $customerLoader,$orderLoader,$moneyLoader);
};
$p=$preview();$s=SelectOne::summarize($p);
g7OnceAssert($s['status']==='one_customer_one_order_shape_verified_local_only'
    && $s['records_sent']===0
    && !$s['marketing_sends_authorized']
    && $p['order_record_private']['customer_source_key']
        ===$p['customer_record_private']['source_key']
    && count($p['customer_record_private'])===11
    && count($p['order_record_private'])===11,'native_pair_and_pst');
g7OnceAssert(strpos(json_encode($s),'synthetic')===false
    && strpos(json_encode($s),'44')===false
    && strpos(json_encode($s),'49.20')===false,'preview_private_data_leak');
$foreignOrder=clone $o;$foreignOrder->id_customer=99;
g7OnceReject(function()use($ns,$customerLoader,$foreignOrder,$moneyLoader) {
    SelectOne::prepareInternal($ns,2,44,77,$customerLoader,
        function()use($foreignOrder){return $foreignOrder;},$moneyLoader);
},'g7_one_record_customer_order_mismatch');
g7OnceReject(function()use($ns,$customerLoader,$orderLoader,$moneyLoader) {
    SelectOne::prepareInternal($ns,3,44,77,
        $customerLoader,$orderLoader,$moneyLoader);
},'g7_one_record_customer_not_in_shop');
g7OnceReject(function()use($ns,$customerLoader,$orderLoader,$moneyLoader) {
    SelectOne::prepareInternal($ns,2,45,77,$customerLoader,
        $orderLoader,$moneyLoader);
},'g7_one_record_customer_not_in_shop');
g7OnceReject(function()use($ns,$customerLoader,$orderLoader,$moneyLoader) {
    SelectOne::prepareInternal($ns,2,44,78,$customerLoader,
        $orderLoader,$moneyLoader);
},'g7_one_record_order_not_in_shop');



Configuration::$values=[
    'PS_SFMC_MODE'=>'test',
    'PS_GROWTH_LOCAL_CAPTURE'=>'off',
    'PS_GROWTH_MERCHANT'=>'ABH',
    'PS_GROWTH_NAMESPACE'=>$ns,
    'PS_SFMC_CRON_TOKEN'=>str_repeat('b',64)
];
$db=new G7OnceDb();
$token=function(){return [
    'instance_url'=>'https://synthetic.c360a.salesforce.com',
    'access_token'=>'synthetic-private-token'
];};
$calls=[];
$mockHttp=function($url,$body,$gotToken)use(&$calls) {
    $item=json_decode($body,true)['data'][0];
    $calls[]=[
        'object'=>strpos($url,'/PSOrder')!==false?'order':'customer',
        'url'=>$url,
        'source_key'=>$item['source_key']
    ];
    g7OnceAssert($gotToken==='synthetic-private-token','token_changed');
    return ['status'=>202,'response_received'=>true];
};
g7OnceReject(function()use($db,$ns,$preview,$token,$mockHttp){
    SendOne::dispatch($db,$ns,'ABH',2,'PSCustomer','NO','',
        $preview,$token,$mockHttp);
},'g7_one_send_scope_or_approval_denied');
g7OnceAssert(count($calls)===0,'post_before_approval');
g7OnceReject(function()use($db,$ns,$preview,$token,$mockHttp){
    SendOne::dispatch($db,$ns,'OTHER',2,'PSCustomer',SendOne::APPROVAL,'',
        $preview,$token,$mockHttp);
},'g7_one_send_test_mode_gate_denied');
g7OnceAssert(count($calls)===0,'foreign_merchant_post_attempt');
$r=SendOne::dispatch($db,$ns,'ABH',2,'PSCustomer',SendOne::APPROVAL,'',
    $preview,$token,$mockHttp);
g7OnceAssert($r['status']==='one_record_accepted_unconfirmed_manual_dlo_check'
    && !$r['record_persisted_in_salesforce_certified']
    && !$r['dlo_dmo_verified']
    && !$r['marketing_sends_authorized']
    && count($calls)===1 && $calls[0]['object']==='customer'
    && !$db->lock,'customer_one_shot_202');
g7OnceReject(function()use($db,$ns,$preview,$token,$mockHttp){
    SendOne::dispatch($db,$ns,'ABH',2,'PSCustomer',SendOne::APPROVAL,'',
        $preview,$token,$mockHttp);
},'g7_one_send_already_attempted');
g7OnceAssert(count($calls)===1,'customer_replayed');
g7OnceReject(function()use($db,$ns,$preview,$token,$mockHttp){
    SendOne::dispatch($db,$ns,'ABH',2,'PSOrder',SendOne::APPROVAL,'',
        $preview,$token,$mockHttp);
},'g7_one_send_customer_dlo_review_required');
g7OnceAssert(count($calls)===1,'order_before_dlo');

$wrongPair=function()use($preview) {
    $p=$preview();
    $p['customer_record_private']['source_key']='growth_ci_abhtwo:2:customer:999';
    $p['order_record_private']['customer_source_key']='growth_ci_abhtwo:2:customer:999';
    return $p;
};
g7OnceReject(function()use($db,$ns,$wrongPair,$token,$mockHttp) {
    SendOne::dispatch($db,$ns,'ABH',2,'PSOrder',
        SendOne::APPROVAL,SendOne::CUSTOMER_DLO_ATTESTATION,
        $wrongPair,$token,$mockHttp);
},'g7_one_send_wrong_customer_for_order');
g7OnceAssert(count($calls)===1,'foreign_customer_receipt_reused');

$r2=SendOne::dispatch(
    $db,$ns,'ABH',2,'PSOrder',SendOne::APPROVAL,
    SendOne::CUSTOMER_DLO_ATTESTATION,$preview,$token,$mockHttp
);
g7OnceAssert($r2['status']==='one_record_accepted_unconfirmed_manual_dlo_check'
    && count($calls)===2 && $calls[1]['object']==='order'
    && !$db->lock,'order_one_shot_after_dlo');
g7OnceAssert(strpos(json_encode($r2),'synthetic')===false
    && strpos(json_encode($r2),'49.20')===false,'result_pii');

$ns='growth_ci_other_shop';
Configuration::updateValue('PS_GROWTH_NAMESPACE',$ns);
$failTransport=function(){return ['status'=>0,'response_received'=>false];};
$failed=SendOne::dispatch($db,$ns,'ABH',2,'PSCustomer',
    SendOne::APPROVAL,'',$preview,$token,$failTransport);
g7OnceAssert($failed['status']==='one_record_unverified_manual_reconciliation_required'
    && !$failed['retry_authorized'],'timeout_marked_retriable');
g7OnceReject(function()use($db,$ns,$preview,$token,$mockHttp){
    SendOne::dispatch($db,$ns,'ABH',2,'PSCustomer',SendOne::APPROVAL,'',
        $preview,$token,$mockHttp);
},'g7_one_send_already_attempted');
g7OnceAssert(count($calls)===2,'retry_after_timeout');

$ns='growth_ci_thirdshop';
Configuration::updateValue('PS_GROWTH_NAMESPACE',$ns);
$brokenAuth=function(){throw new RuntimeException('private_oauth_error');};
g7OnceReject(function()use($db,$ns,$preview,$brokenAuth,$mockHttp){
    SendOne::dispatch($db,$ns,'ABH',2,'PSCustomer',SendOne::APPROVAL,'',
        $preview,$brokenAuth,$mockHttp);
},'private_oauth_error');
$success=SendOne::dispatch($db,$ns,'ABH',2,'PSCustomer',SendOne::APPROVAL,'',
    $preview,$token,$mockHttp);
g7OnceAssert(count($calls)===3
    && $success['status']==='one_record_accepted_unconfirmed_manual_dlo_check',
    'auth_failed_reserved_attempt');

// BC production merchant code is BCABH; existing ABH is legacy-compatible.
// Both use the existing ABH PST connector, without creating a new source.
$ns='abh_bc_prod_20261119';
Configuration::updateValue('PS_GROWTH_NAMESPACE',$ns);
Configuration::updateValue('PS_GROWTH_MERCHANT','BCABH');
g7OnceReject(function()use($db,$ns,$preview,$token,$mockHttp){
    SendOne::dispatch($db,$ns,'SHOP_OTHER',2,'PSCustomer',SendOne::APPROVAL,'',
        $preview,$token,$mockHttp);
},'g7_one_send_test_mode_gate_denied');
g7OnceReject(function()use($db,$ns,$preview,$token,$mockHttp){
    SendOne::dispatch($db,$ns,'ABH',2,'PSCustomer',SendOne::APPROVAL,'',
        $preview,$token,$mockHttp);
},'g7_one_send_test_mode_gate_denied');
$bcBefore=count($calls);
$bc=SendOne::dispatch($db,$ns,'BCABH',2,'PSCustomer',SendOne::APPROVAL,'',
    $preview,$token,$mockHttp);
g7OnceAssert(count($calls)===$bcBefore+1
    && $bc['status']==='one_record_accepted_unconfirmed_manual_dlo_check'
    && !$bc['marketing_sends_authorized']
    && !$bc['dlo_dmo_verified'],'bcabh_same_source_customer');
$bcOrder=SendOne::dispatch($db,$ns,'BCABH',2,'PSOrder',
    SendOne::APPROVAL,SendOne::CUSTOMER_DLO_ATTESTATION,
    $preview,$token,$mockHttp);
g7OnceAssert(count($calls)===$bcBefore+2
    && $bcOrder['status']==='one_record_accepted_unconfirmed_manual_dlo_check',
    'bcabh_same_source_order');

// Second merchant / second site: cannot borrow BC's source.
$ns='do_online_prod_20261119';
Configuration::updateValue('PS_GROWTH_NAMESPACE',$ns);
Configuration::updateValue('PS_GROWTH_MERCHANT','DOABH');
$beforeDo=count($calls);
g7OnceReject(function()use($db,$ns,$preview,$token,$mockHttp) {
    SendOne::dispatch($db,$ns,'DOABH',2,'PSCustomer',
        SendOne::APPROVAL,'',$preview,$token,$mockHttp);
},'growth_source_unverified_for_send');
g7OnceAssert(count($calls)===$beforeDo,'do_not_sent_to_bc');
SourceBinding::bind(2,'DOABH',$ns,'DO_PrestaShop_Data_Cloud',
    SourceBinding::ATTEST);
$do=SendOne::dispatch($db,$ns,'DOABH',2,'PSCustomer',
    SendOne::APPROVAL,'',$preview,$token,$mockHttp);
g7OnceAssert($do['status']==='one_record_accepted_unconfirmed_manual_dlo_check'
    && count($calls)===$beforeDo+1
    && strpos($calls[$beforeDo]['url'],
        '/api/v1/ingest/sources/DO_PrestaShop_Data_Cloud/PSCustomer')!==false
    && strpos($calls[$beforeDo]['url'],'ABH_PrestaShop_Data_Cloud')===false,
    'do_source_not_selected');
$doOrder=SendOne::dispatch($db,$ns,'DOABH',2,'PSOrder',
    SendOne::APPROVAL,SendOne::CUSTOMER_DLO_ATTESTATION,
    $preview,$token,$mockHttp);
g7OnceAssert(count($calls)===$beforeDo+2
    && strpos($calls[$beforeDo+1]['url'],
        '/api/v1/ingest/sources/DO_PrestaShop_Data_Cloud/PSOrder')!==false,
    'do_order_source_not_selected');

Configuration::updateValue('PS_SFMC_MODE','production');
g7OnceReject(function()use($db,$ns,$preview,$token,$mockHttp){
    SendOne::dispatch($db,$ns,'ABH',2,'PSOrder',SendOne::APPROVAL,
        SendOne::CUSTOMER_DLO_ATTESTATION,$preview,$token,$mockHttp);
},'g7_one_send_test_mode_gate_denied');

$module=file_get_contents(dirname(__DIR__).'/pssalesforcebridge/pssalesforcebridge.php');
$cron=file_get_contents(dirname(__DIR__).'/pssalesforcebridge/controllers/front/cron.php');
$hooks=substr($module,strpos($module,'public function hookActionCustomerAccountAdd'),
    strpos($module,'public function getContent')
    -strpos($module,'public function hookActionCustomerAccountAdd'));
g7OnceAssert(strpos($module,'renderManualOneRecordForms')!==false
    && strpos($module,'runManualOneRecord')!==false
    && strpos($module,"$"."this->version = '0.9.4'")!==false
    && strpos($hooks,'GrowthOneRecordManualSender')===false
    && strpos($cron,'GrowthOneRecordManualSender')===false,
    'unapproved_auto_wiring');
echo "G7 controlled 1+1 UAT PASS: same-shop PST, explicit approval, exactly-once attempt, 202/timeout held, no marketing.\n";
