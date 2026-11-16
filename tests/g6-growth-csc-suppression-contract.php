<?php
/** G6 read-only Growth CSC + native customer hold contract; synthetic only. */
require_once dirname(__DIR__) . '/pssalesforcebridge/src/Growth/GrowthLocalCustomerHoldReader.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/Growth/GrowthCommunicationConsentHoldGate.php';
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthCommunicationConsentHoldGate as Gate;

function g6Assert($ok,$reason) { if (!$ok) { fwrite(STDERR,"G6 CSC FAIL ".$reason."\n"); exit(1); } }
function g6Row() { return ['id_customer'=>'17','id_shop'=>'2','active'=>'1','deleted'=>'0',
    'is_guest'=>'0','newsletter'=>'1','optin'=>'1']; }
function g6Csc($status) { return ['channel'=>'email',
    'contact_point_source_key'=>'growth_ci_test:2:customer:17',
    'observed_at_utc'=>'2026-11-16T11:59:50Z',
    'source'=>'salesforce_communication_subscription_consent',
    'status'=>$status,'subscription_id'=>'cart_recovery_test']; }
function g6Inspect($rows,$evidence,&$called) {
    $called=0;
    $select=function($sql)use($rows) {
        g6Assert(strpos($sql,'SELECT ')===0
            && strpos($sql,'WHERE ')!==false
            && strpos($sql,'id_shop')!==false
            && strpos($sql,'id_customer')!==false
            && strpos($sql,'LIMIT 2')!==false
            && stripos($sql,'email')===false
            && stripos($sql,'firstname')===false,'local_read_not_scoped_or_private');
        return $rows;
    };
    $csc=function($key,$subscription,$channel)use($evidence,&$called) {
        ++$called;
        g6Assert($key==='growth_ci_test:2:customer:17'
            && $subscription==='cart_recovery_test'
            && $channel==='email','csc_scoped_read_invalid');
        if($evidence==='throws')throw new RuntimeException('private_transport_exception');
        return $evidence;
    };
    return Gate::inspect(2,17,'growth_ci_test','cart_recovery_test',
        '2026-11-16T12:00:00Z',$select,'ps_ci_',$csc);
}
function g6Safe($out) {
    g6Assert(in_array($out['status'],['hold_for_review','review_candidate_only'],true),
        'unexpected_state');
    foreach(['salesforce_csc_reader_certified_for_bc',
        'salesforce_send_time_consent_certified','external_suppression_sources_complete',
        'final_atomic_exclusion_fence_ready','data_cloud_ingestion_authorized',
        'marketing_sends_authorized'] as $key) {
        g6Assert(isset($out[$key])===false && array_key_exists($key,$out)
            && $out[$key]===false,'unsafe_flag_'.$key);
    }
    $body=json_encode($out);
    foreach(['growth_ci_test','cart_recovery_test','customer:17',
        'private_transport_exception','private@','id_customer'] as $secret) {
        g6Assert(strpos($body,$secret)===false,'private_data_leak');
    }
}
$called=0;
$ok=g6Inspect([g6Row()],[g6Csc('opt_in')],$called);
g6Assert($called===1 && $ok['status']==='review_candidate_only',
    'recent_csc_positive_not_reviewed'); g6Safe($ok);
$no=g6Inspect([g6Row()],[g6Csc('opt_out')],$called);
g6Assert($called===1 && in_array('salesforce_csc_opted_out',$no['reasons'],true),
    'remote_optout_ignored'); g6Safe($no);
foreach([[],[g6Csc('unknown')],[g6Csc('opt_in'),g6Csc('opt_out')],'throws'] as $evidence){
    $out=g6Inspect([g6Row()],$evidence,$called);
    g6Assert($out['status']==='hold_for_review','unknown_positive'); g6Safe($out);
}
foreach(['channel'=>'sms','contact_point_source_key'=>'growth_ci_test:3:customer:17',
    'subscription_id'=>'wrong_subscription','source'=>'prestashop_newsletter'] as $field=>$value){
    $e=g6Csc('opt_in');$e[$field]=$value;
    $out=g6Inspect([g6Row()],[$e],$called);
    g6Assert(in_array('salesforce_csc_scope_mismatch',$out['reasons'],true),
        'cross_scope_'.$field);g6Safe($out);
}
foreach(['2026-11-16T11:54:59Z','2026-11-16T12:01:00Z',
    '2026-02-31T11:59:50Z','2026-11-16 11:59:50'] as $date) {
    $e=g6Csc('opt_in');$e['observed_at_utc']=$date;
    $out=g6Inspect([g6Row()],[$e],$called);
    g6Assert(in_array('salesforce_csc_stale_or_invalid',$out['reasons'],true),
        'invalid_or_stale_utc');g6Safe($out);
}
$extra=g6Csc('opt_in');$extra['email']='private@fixture.example.invalid';
$out=g6Inspect([g6Row()],[$extra],$called);
g6Assert(in_array('salesforce_csc_shape_invalid',$out['reasons'],true),
    'extra_private_column_not_rejected');g6Safe($out);
foreach(['newsletter','optin','active','is_guest','deleted'] as $field) {
    $r=g6Row();$r[$field]=in_array($field,['deleted','is_guest'],true)?'1':'0';
    $out=g6Inspect([$r],[g6Csc('opt_in')],$called);
    g6Assert($called===0 && $out['status']==='hold_for_review'
        && in_array('local_customer_hold',$out['reasons'],true),
        'negative_local_must_override_remote_'.$field);g6Safe($out);
}
$wrong=g6Row();$wrong['id_shop']='3';
$out=g6Inspect([$wrong],[g6Csc('opt_in')],$called);
g6Assert($called===0 && in_array('local_customer_scope_mismatch',$out['reasons'],true),
    'cross_shop_not_blocked');g6Safe($out);
$out=g6Inspect([],[g6Csc('opt_in')],$called);
g6Assert($called===0 && in_array('local_customer_not_found',$out['reasons'],true),
    'missing_customer_not_blocked');g6Safe($out);
foreach([['bad namespace','cart_recovery_test','2026-11-16T12:00:00Z',
    'growth_csc_input_scope_invalid'],
    ['growth_ci_test','cart_recovery_test','2026-11-16 12:00:00',
    'growth_csc_now_utc_invalid']] as $case) {
    try {
        Gate::inspect(2,17,$case[0],$case[1],$case[2],
            function(){return [];},'ps_ci_',function(){return [];});
        g6Assert(false,'bad_scope_accepted');
    } catch(RuntimeException $ex) { g6Assert($ex->getMessage()===$case[3],
        'wrong_scope_error'); }
}
if(!defined('_DB_PREFIX_'))define('_DB_PREFIX_','ps_ci_');
class Db {
    public static $calls=[];
    public static function getInstance(){return new self();}
    public function executeS($sql,$array=true,$useCache=true){
        self::$calls[]=[$sql,$array,$useCache]; return [g6Row()];
    }
}
$out=Gate::inspectPrestaShop(2,17,'growth_ci_test','cart_recovery_test',
    '2026-11-16T12:00:00Z',function(){return [g6Csc('opt_in')];});
g6Assert(count(Db::$calls)===1 && Db::$calls[0][1]===true
    && Db::$calls[0][2]===false && $out['status']==='review_candidate_only',
    'native_fresh_read_failed');g6Safe($out);
$module=file_get_contents(dirname(__DIR__).'/pssalesforcebridge/pssalesforcebridge.php');
g6Assert(strpos($module,'GrowthCommunicationConsentHoldGate')===false,
    'new_gate_wired_to_live_module');
echo "G6 Growth CSC source / local suppression PASS: scoped consent, opt-out precedence, no sends.\n";
