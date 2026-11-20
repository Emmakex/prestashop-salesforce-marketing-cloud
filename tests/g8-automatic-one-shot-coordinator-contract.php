<?php
/** G8 synthetic automation contract; never opens network or real shop DB. */
class Configuration {
    public static function get($key,$lang=null,$group=null,$shop=null) {
        return false;
    }
}
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthShopSourceBinding.php';
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthAutomaticOneShotCoordinator.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthAutomaticOneShotCoordinator as Auto;

function autoAssert($ok,$case) {
    if (!$ok) throw new RuntimeException('g8_auto_contract_'.$case);
}
function autoBlocked($callback,$case) {
    try {
        $callback();
    } catch (RuntimeException $error) {
        autoAssert($error->getMessage()==='g8_auto_tick_not_authorized'
            || $error->getMessage()==='growth_source_unverified_for_send',
            $case.'_wrong_error');
        return;
    }
    autoAssert(false,$case.'_not_blocked');
}
$ns='pssfmc_ci_auto_20261120';
$scope=[
    'merchant'=>'BCABH','namespace'=>$ns,'shop'=>1,
    'mode'=>'test','capture'=>'off',
    'owner_gate'=>Auto::OWNER_GATE
];
$customer=['shop'=>1,'type'=>'PSCustomer','id'=>44,'sequence'=>11];
$order=['shop'=>1,'type'=>'PSOrder','id'=>77,'sequence'=>12];
$calls=[];
$persisted=[];
$reservations=[];
$parentConfirmed=false;
$remote='absent_confirmed';
$sourceFlip=false;
$localAllowed=true;
$fakeLoad=function($c)use(&$sourceFlip,$ns) {
    $isCustomer=$c['type']==='PSCustomer';
    $payload=[
        'source_key'=>$ns.':1:'.($isCustomer?'customer':'order')
            .':'.$c['id'],
        'source_namespace'=>$ns,
        'shop_id'=>1,
    ];
    if ($isCustomer) $payload['customer_id']=$c['id'];
    else $payload['customer_source_key']=$ns.':1:customer:44';
    return [
        'shop'=>$c['shop'],'type'=>$c['type'],'id'=>$c['id'],
        'sequence'=>$c['sequence'],
        'fingerprint'=>hash('sha256',($sourceFlip?'changed':'original')
            .$c['type'].$c['id']),
        'payload_private'=>$payload,
        'customer_id'=>44
    ];
};
$eligible=function($fresh)use(&$localAllowed){
    return $localAllowed;
};
$presence=function($candidate,$fresh)use(&$remote) {
    return $remote;
};
$parent=function($shop,$namespace,$id)use(&$parentConfirmed,$ns) {
    return $parentConfirmed && $shop===1 && $namespace===$ns && $id===44;
};
$reserve=function($candidate,$fingerprint)use(&$reservations) {
    $key=$candidate['type'].':'.$candidate['id'];
    if (isset($reservations[$key]))return 'previous_attempt';
    $reservations[$key]=$fingerprint;
    return 'reserved_once';
};
$post=function($source,$type,$payload,$candidate)use(&$calls) {
    autoAssert($source==='ABH_PrestaShop_Data_Cloud','wrong_bc_source');
    $calls[]=$type;
    return ['http_status'=>202,'outcome'=>'accepted_asynchronously_unconfirmed'];
};
$save=function($candidate,$outcome)use(&$persisted) {
    $persisted[$candidate['type']]=$outcome;
    return true;
};
$run=function($events)use($scope,$fakeLoad,$eligible,$presence,$parent,$reserve,$post,$save) {
    return Auto::tick(
        $scope,$events,$fakeLoad,$eligible,$presence,$parent,
        $reserve,$post,$save
    );
};

// Customer may be sent once. Order MUST wait for verified customer DLO.
$first=$run([$customer,$order]);
autoAssert($calls===['PSCustomer']
    && $first['accepted_202_unconfirmed']===1
    && $first['pending_reconciliation']===1
    && $first['skipped']===1
    && $first['records_verified_in_dlo']===0
    && $persisted['PSCustomer']==='reconcile',
    'customer_only_parent_not_verified');

// A second tick may not replay the customer while HTTP 202 is unresolved.
$dup=$run([$customer]);
autoAssert(count($calls)===1 && $dup['reserved']===0
    && $dup['skipped']===1,'unknown_202_replayed');

// Once its parent is explicitly verified in DLO, the order may post once.
$parentConfirmed=true;
$orderResult=$run([$order]);
autoAssert($calls===['PSCustomer','PSOrder']
    && $orderResult['accepted_202_unconfirmed']===1
    && $persisted['PSOrder']==='reconcile',
    'order_parent_sequence');
$run([$order]);
autoAssert(count($calls)===2,'order_replayed');

// Already in Salesforce but absent in local ledger: NEVER replay BC smoke.
$remote='present';
$never=$run([['shop'=>1,'type'=>'PSCustomer','id'=>45,'sequence'=>13]]);
autoAssert($never['skipped']===1 && count($calls)===2,
    'remote_existing_customer_replayed');
$remote='unknown';
$never=$run([['shop'=>1,'type'=>'PSCustomer','id'=>45,'sequence'=>13]]);
autoAssert($never['skipped']===1 && count($calls)===2,
    'unknown_remote_state_replayed');
$remote='absent_confirmed';

// Local opt-out / deleted / guest / unverified customer flags fail closed.
$localAllowed=false;
$no=$run([['shop'=>1,'type'=>'PSCustomer','id'=>46,'sequence'=>14]]);
autoAssert($no['reserved']===0 && count($calls)===2,
    'local_suppression_ignored');
$localAllowed=true;

// After CAS reserve, any source drift prevents POST. Persist 'held'.
$freshReadCount=0;
$changedLoad=function($c)use($fakeLoad,&$freshReadCount,&$sourceFlip) {
    $sourceFlip=++$freshReadCount===2;
    return $fakeLoad($c);
};
$changed=['shop'=>1,'type'=>'PSCustomer','id'=>47,'sequence'=>15];
$changedResult=Auto::tick(
    $scope,[$changed],$changedLoad,$eligible,$presence,$parent,
    $reserve,$post,$save
);
autoAssert(count($calls)===2 && $changedResult['held']===1
    && $persisted['PSCustomer']==='held',
    'changed_after_lease_sent');
$sourceFlip=false;

// If HTTP throws or status is unrecognized, never interpret as delivery.
$uncertain=['shop'=>1,'type'=>'PSCustomer','id'=>48,'sequence'=>16];
$throwingPost=function(){throw new RuntimeException('simulated_http_timeout');};
$unknown=Auto::tick(
    $scope,[$uncertain],$fakeLoad,$eligible,$presence,$parent,
    $reserve,$throwingPost,$save
);
autoAssert($unknown['pending_reconciliation']===1
    && $unknown['accepted_202_unconfirmed']===0
    && $persisted['PSCustomer']==='reconcile','timeout_not_quarantined');
$bad=['shop'=>1,'type'=>'PSCustomer','id'=>49,'sequence'=>17];
$failedPost=function(){return ['http_status'=>503,'outcome'=>'rejected'];};
$held=Auto::tick(
    $scope,[$bad],$fakeLoad,$eligible,$presence,$parent,
    $reserve,$failedPost,$save
);
autoAssert($held['held']===1 && $persisted['PSCustomer']==='held',
    '503_not_held');

// No settings, source or cross-shop cannot trigger callback.
$notApproved=$scope;$notApproved['owner_gate']='';
autoBlocked(function()use($notApproved,$customer,$fakeLoad,$eligible,
    $presence,$parent,$reserve,$post,$save){
    Auto::tick($notApproved,[$customer],$fakeLoad,$eligible,$presence,
        $parent,$reserve,$post,$save);
},'owner_gate');
$wrongSource=$scope;$wrongSource['merchant']='DOABH';
autoBlocked(function()use($wrongSource,$customer,$fakeLoad,$eligible,
    $presence,$parent,$reserve,$post,$save){
    Auto::tick($wrongSource,[$customer],$fakeLoad,$eligible,$presence,
        $parent,$reserve,$post,$save);
},'no_do_source');
$outside=['shop'=>2,'type'=>'PSCustomer','id'=>50,'sequence'=>18];
$cross=$run([$outside]);
autoAssert($cross['skipped']===1 && count($calls)===2,
    'cross_shop_executed');
autoBlocked(function()use($scope,$customer,$fakeLoad,$eligible,$presence,
    $parent,$reserve,$post,$save){
    Auto::tick($scope,array_fill(0,11,$customer),$fakeLoad,
        $eligible,$presence,$parent,$reserve,$post,$save);
},'unbounded_batch');

autoAssert(strpos(json_encode($first),'source_key')===false
    && strpos(json_encode($first),$ns)===false
    && $first['marketing_sends_authorized']===false
    && $first['automatic_retry_authorized']===false
    && $first['records_verified_in_dlo']===0,
    'pii_leaked_or_false_delivery');
echo "G8 AUTO COORDINATOR PASS: BC customer/order, 202 hold, no retry, DLO checks, gates, cap and no PII.\n";
