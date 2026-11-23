<?php
/**
 * G8 READY transition contract; synthetic, no SQL/HTTP.
 * The real CAS → receipt test is executed in disposable MySQL/MariaDB G3 CI.
 */
class Configuration {
    public static $data=[
        'PS_SFMC_MODE'=>'test',
        'PS_GROWTH_LOCAL_CAPTURE'=>'off',
        'PS_GROWTH_MERCHANT'=>'ABH',
        'PS_GROWTH_NAMESPACE'=>'growth_g8_stage_ci'
    ];
    public static function get($key,$language=null,$shopGroup=null,$shop=null) {
        return isset(self::$data[$key])?self::$data[$key]:false;
    }
}
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthShopSourceBinding.php';
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG8StagingReadyGate.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG8StagingReadyGate as Gate;

function readyAssert($yes,$why) {
    if (!$yes)throw new RuntimeException('g8_ready_gate_'.$why);
}
$db=new class {
    public $calls=0;
    public function execute($sql) {++$this->calls;return true;}
    public function getValue($sql,$cache=false) {++$this->calls;return 1;}
    public function executeS($sql,$cache=true,$useCache=false) {
        ++$this->calls;return [];
    }
};
$scope=[
    'shop'=>1,'merchant'=>'ABH','namespace'=>'growth_g8_stage_ci',
    'mode'=>'test','capture'=>'off',
    'owner_gate'=>Gate::OWNER_GATE
];
$c=['shop'=>1,'type'=>'PSCustomer','id'=>91234,'sequence'=>24];
$yes=function(){return true;};
$absent=function(){return 'absent_confirmed';};
$pass=function($sc,$candidate)use($db,$yes,$absent) {
    return Gate::promote(
        $db,'9.1.5',$sc,'ci_',$candidate,$yes,$absent,$yes
    );
};
$denied=$scope;$denied['owner_gate']='';
readyAssert($pass($denied,$c)['status']==='blocked_by_configuration',
    'missing_owner_not_blocked');
$denied=$scope;$denied['mode']='production';
readyAssert($pass($denied,$c)['status']==='blocked_by_configuration',
    'production_mode_not_blocked');
$denied=$scope;$denied['capture']='staging_armed';
readyAssert($pass($denied,$c)['status']==='blocked_by_configuration',
    'active_capture_not_blocked');
$denied=$scope;$denied['shop']=2;
readyAssert($pass($denied,$c)['status']==='blocked_by_configuration',
    'cross_shop_not_blocked');
$denied=$scope;$denied['namespace']='growth_bc_production';
readyAssert($pass($denied,$c)['status']==='blocked_by_configuration',
    'production_namespace_not_blocked');
$bad=$c;$bad['type']='PSCart';
readyAssert($pass($scope,$bad)['status']==='blocked_by_configuration',
    'unsupported_object_not_blocked');
$bad=$c;$bad['secret']='private';
readyAssert($pass($scope,$bad)['status']==='blocked_by_configuration',
    'unexpected_fields_not_blocked');
$scan=Gate::scanReady($db,'9.1.5',$denied,'ci_',0,10);
readyAssert($scan['status']==='ready_scan_failed_closed'
    && $scan['candidates_private']===[],
    'cross_namespace_scan_not_blocked');
$scan=Gate::scanReady($db,'9.1.5',$scope,'ci_',0,11);
readyAssert($scan['status']==='ready_scan_failed_closed'
    && $scan['candidates_private']===[],
    'unbounded_scan_not_blocked');
readyAssert($db->calls===0,'invalid_scope_touched_db');
$source=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG8StagingReadyGate.php');
$module=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/pssalesforcebridge.php');
$cron=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/controllers/front/cron.php');
readyAssert(strpos($module,'GrowthG8StagingReadyGate.php')!==false
    && strpos($module,'GrowthG8NativeStagingServices::run(')!==false
    && strpos($cron,'GrowthG8StagingReadyGate')===false
    && strpos($module,"PS_GROWTH_G8_STAGING_APPROVAL")!==false,
    'stage_ready_gate_wiring_missing');
foreach ([
    'GrowthPrestaShopDeliveryLease',
    'l.latest_accepted_sequence=o.accepted_sequence',
    "o.delivery_state='blocked'",
    'o.delivery_attempts=0',
    'o.lease_token_hash IS NULL',
    'o.lease_until_epoch IS NULL',
    'o.delivery_last_http IS NULL',
    "'ready_for_one_cas_only'",
    "'ready_candidates_private'"
] as $required) {
    readyAssert(strpos($source,$required)!==false,
        'missing_cas_or_scan_guard');
}
foreach (['curl_','->post(', 'Mail::send(','->claim(',
    'GrowthDataCloudUpsertTransport','DELETE FROM',
    'INSERT INTO'] as $forbidden) {
    readyAssert(strpos($source,$forbidden)===false,'unexpected_egress');
}
echo "G8 READY gate PASS: exact staging scope, bounded private scan and no transport.\n";
