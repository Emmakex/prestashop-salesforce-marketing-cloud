<?php
/**
 * G8 worker hard denial contract: no SQL, POST, campaign or PII
 * without the exact owner approval and scoped callbacks.
 */
class Configuration {
    public static $data=[
        'PS_GROWTH_MERCHANT'=>'ABH',
        'PS_GROWTH_NAMESPACE'=>'pssfmc_stage_ci_20261123',
        'PS_SFMC_MODE'=>'test',
        'PS_GROWTH_LOCAL_CAPTURE'=>'off',
        'PS_GROWTH_G8_STAGING_APPROVAL'=>'off'
    ];
    public static function get($name,$language=null,$group=null,$shop=null) {
        return isset(self::$data[$name])?self::$data[$name]:false;
    }
}
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthShopSourceBinding.php';
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthAutomaticOneShotCoordinator.php';
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG8StagingWorker.php';
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG8StagingWorker as Worker;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthAutomaticOneShotCoordinator as Coordinator;

function stageWorkerAssert($ok,$name) {
    if (!$ok)throw new RuntimeException('g8_stage_worker_'.$name);
}
$db=new class {
    public $calls=0;
    public function executeS($sql,$array=true,$cache=false) {
        ++$this->calls;throw new RuntimeException('disallowed_egress');
    }
};
$scope=[
    'shop'=>1,'merchant'=>'ABH',
    'namespace'=>'pssfmc_stage_ci_20261123',
    'mode'=>'test','capture'=>'off',
    'owner_gate'=>Coordinator::OWNER_GATE
];
$called=0;
$noCall=function()use(&$called){
    ++$called;
    throw new RuntimeException('private@example.test');
};
$callbacks=[
    'loadFresh'=>$noCall,
    'locallyEligible'=>$noCall,
    'contactEligibility'=>$noCall,
    'recordDloStatus'=>$noCall,
    'parentDloVerified'=>$noCall,
    'postOnce'=>$noCall
];
$run=function($scope,$callbacks)use($db) {
    return Worker::run($db,'9.1.5',$scope,'ci_',$callbacks);
};
$denied=$run($scope,$callbacks);
stageWorkerAssert($denied['status']==='staging_worker_blocked'
    && $denied['counts']===null && $db->calls===0 && $called===0
    && !$denied['marketing_sends_authorized'],
    'default_block_failed');
$bad=$scope;$bad['mode']='production';
stageWorkerAssert($run($bad,$callbacks)['status']==='staging_worker_blocked',
    'production_allowed');
$bad=$scope;$bad['namespace']='pssfmc_bc_prod_20261123';
stageWorkerAssert($run($bad,$callbacks)['status']==='staging_worker_blocked',
    'production_namespace_allowed');
$bad=$scope;$bad['shop']=2;
stageWorkerAssert($run($bad,$callbacks)['status']==='staging_worker_blocked',
    'cross_shop_allowed');
$bad=$scope;$bad['owner_gate']='';
stageWorkerAssert($run($bad,$callbacks)['status']==='staging_worker_blocked',
    'missing_owner_gate');
$noContact=$callbacks;unset($noContact['contactEligibility']);
stageWorkerAssert($run($scope,$noContact)['status']==='staging_worker_blocked',
    'missing_contact_callback');
$noPresence=$callbacks;unset($noPresence['recordDloStatus']);
stageWorkerAssert($run($scope,$noPresence)['status']==='staging_worker_blocked',
    'missing_dlo_callback');
$bad=$scope;$bad['capture']='staging_armed';
stageWorkerAssert($run($bad,$callbacks)['status']==='staging_worker_blocked',
    'capture_armed_allowed');
stageWorkerAssert($db->calls===0 && $called===0,
    'unexpected_action_on_denial');
Configuration::$data['PS_GROWTH_G8_STAGING_APPROVAL']=Worker::APPROVAL;
$bad=$run($scope,$callbacks);
stageWorkerAssert($bad['status']==='staging_worker_failed_closed'
    && $bad['counts']===null
    && strpos(json_encode($bad),'private@example.test')===false,
    'invalid_database_or_exception_leaked');
Configuration::$data['PS_GROWTH_G8_STAGING_APPROVAL']='off';
$mod=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/pssalesforcebridge.php');
$cron=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/controllers/front/cron.php');
$source=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG8StagingWorker.php');
stageWorkerAssert(strpos($mod,'GrowthG8NativeStagingServices::run(')!==false
    && strpos($cron,"'dispatch'")!==false
    && strpos($cron,'hash_equals(')!==false
    && strpos($source,'GrowthG8StagingReadyGate::promote(')!==false
    && strpos($source,'GrowthAutomaticOneShotCoordinator::tick(')!==false
    && strpos($source,'GrowthG8DurableReceiptBridge(')!==false,
    'worker_not_wired');
stageWorkerAssert(strpos($source,'curl_')===false
    && strpos($source,'Mail::send(')===false
    && strpos($source,'INSERT INTO')===false
    && strpos($source,'DELETE FROM')===false,
    'second_queue_or_http_reintroduced');
echo "G8 stage worker contract PASS: default deny, no egress, explicit owner and callbacks.\n";
