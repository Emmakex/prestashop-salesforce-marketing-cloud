<?php
/**
 * G7 native hook capture: exact source scope, salted dedupe, no PII
 * in durable row or response, local Test only. Synthetic fixtures.
 */
$root=dirname(__DIR__).'/pssalesforcebridge/src/';
foreach([
    'Provider/ExplicitProviderRouter.php',
    'DataCloud/LiveEventUtcObservation.php',
    'DataCloud/StagedAtomicUtcAcceptance.php',
    'Growth/BlockedOutboxCoordinator.php',
    'Compatibility/CustomerHookAdapter.php',
    'Compatibility/OrderHookAdapter.php',
    'Growth/GrowthBlockedHookAdapter.php',
    'Growth/GrowthStagingHookCapture.php',
] as $file)require_once $root.$file;

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthStagingHookCapture as Capture;

function g7StagingAssert($yes,$code)
{
    if(!$yes)throw new RuntimeException('g7_staging_contract_'.$code);
}
function g7StagingReject($fn,$reason)
{
    try {$fn();}
    catch(RuntimeException $e){
        g7StagingAssert($e->getMessage()===$reason,
            'wrong_error_'.$e->getMessage());
        return;
    }
    g7StagingAssert(false,'unsafe_accepted_'.$reason);
}
$secret=str_repeat('c',64);
$records=[];$sequence=0;$calls=0;
$store=function($identity,$factory)use(&$records,&$sequence,&$calls){
    ++$calls;
    $hash=$identity['dedupe_fingerprint'];
    if(isset($records[$hash]))return ['row'=>$records[$hash],'inserted'=>false];
    $record=$factory();
    $record['accepted_sequence']=++$sequence;
    $records[$hash]=$record;
    return ['row'=>$record,'inserted'=>true];
};
$c=(object)[
    'id'=>91,'id_shop'=>2,
    'email'=>'sensitive-in-memory@example.invalid',
    'firstname'=>'PRIVATE_SYNTHETIC_FIRST',
    'lastname'=>'PRIVATE_SYNTHETIC_LAST',
    'newsletter'=>1,'optin'=>0,'active'=>1,
    'deleted'=>0,'is_guest'=>0,
    'date_upd'=>'2026-11-17 11:20:00'
];
$o=(object)[
    'id'=>51,'id_shop'=>2,'id_customer'=>91,'id_cart'=>18,
    'id_currency'=>1,'total_paid'=>'44.00','current_state'=>2,
    'date_upd'=>'2026-11-17 11:25:00'
];
$args=['9.1.5','ABH','growth_g7_stage_ci',$secret,$store];
$r1=Capture::capture('actionCustomerAccountAdd',['newCustomer'=>$c],
    $args[0],$args[1],$args[2],$args[3],$args[4]);
$r2=Capture::capture('actionCustomerAccountAdd',['newCustomer'=>$c],
    $args[0],$args[1],$args[2],$args[3],$args[4]);
$changed=clone $c; $changed->newsletter=0;
$r3=Capture::capture('actionCustomerAccountUpdate',['customer'=>$changed],
    $args[0],$args[1],$args[2],$args[3],$args[4]);
$r4=Capture::capture('actionValidateOrder',['order'=>$o],
    $args[0],$args[1],$args[2],$args[3],$args[4]);
g7StagingAssert($r1['inserted'] && !$r2['inserted'] && $r3['inserted']
    && $r4['inserted'] && $r1['accepted_sequence']===$r2['accepted_sequence']
    && $sequence===3 && $calls===4,'dedupe_or_incremental');
foreach($records as $row){
    g7StagingAssert($row['clock_sync_verified']===false
        && $row['source_modified_provenance']==='unverified'
        && !isset($row['email'],$row['first_name'],$row['total_paid'])
        && strlen($row['dedupe_fingerprint'])===64,'row_privacy_or_clock');
    $encoded=json_encode($row);
    foreach(['PRIVATE_SYNTHETIC','sensitive-in-memory','44.00'] as $bad){
        g7StagingAssert(strpos($encoded,$bad)===false,'pii_in_local_row');
    }
}
foreach([$r1,$r2,$r3,$r4] as $result){
    g7StagingAssert($result['status']==='blocked_local_only'
        && $result['outbound_worker_authorized']===false
        && $result['marketing_sends_authorized']===false,
        'outbound_flag');
}
$wrongShop=clone $c;unset($wrongShop->id_shop);
g7StagingReject(function()use($wrongShop,$args){
    Capture::capture('actionCustomerAccountAdd',
        ['newCustomer'=>$wrongShop],...$args);
},'g7_staging_hook_explicit_scope_required');
$bad=clone $c;unset($bad->date_upd);
g7StagingReject(function()use($bad,$args){
    Capture::capture('actionCustomerAccountUpdate',['customer'=>$bad],...$args);
},'g7_staging_hook_source_incomplete');
g7StagingReject(function()use($c,$args){
    Capture::capture('actionCartSave',['cart'=>$c],...$args);
},'g7_staging_hook_not_eligible');
g7StagingReject(function()use($c,$args){
    Capture::capture('actionCustomerAccountAdd',['newCustomer'=>$c],
        $args[0],$args[1],$args[2],str_repeat('z',64),$args[4]);
},'g7_staging_hook_not_eligible');
g7StagingAssert($calls===4,'rejected_event_reached_store');
$module=file_get_contents(dirname(__DIR__).'/pssalesforcebridge/pssalesforcebridge.php');
g7StagingAssert(strpos($module,'GrowthStagingHookCapture::captureFromShop')!==false
    && strpos($module,"CONFIG_LOCAL_CAPTURE")!==false
    && strpos($module,"CONFIG_MODE, 'test'")!==false,
    'module_stage_gate_missing');
$source=file_get_contents($root.'Growth/GrowthStagingHookCapture.php');
foreach(['curl_','file_put_contents(', '/api/v1/ingest/',
          '->requestAccessToken(', 'sfmc_queue'] as $forbidden){
    g7StagingAssert(strpos($source,$forbidden)===false,
        'unexpected_remote_or_legacy_io');
}
echo "G7 native local Test hook capture PASS: HMAC dedupe, exact scope, no PII payload or sends.\n";
