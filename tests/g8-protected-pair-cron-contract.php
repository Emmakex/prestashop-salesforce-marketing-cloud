<?php
/**
 * G8 0.9.8 protected exact-pair cron acceptance contract.
 * No DB access, token exchange, HTTP, merchant rows or sending.
 * CI certifies the path only; a live UAT is NOT implied.
 */
if (!defined('_PS_VERSION_'))define('_PS_VERSION_', '9.1.5');

function pairCronAssert($ok,$why)
{
    if (!$ok)throw new RuntimeException('g8_pair_cron_'.$why);
}

$root=dirname(__DIR__);
$module=file_get_contents($root.'/pssalesforcebridge/pssalesforcebridge.php');
$front=file_get_contents($root.'/pssalesforcebridge/controllers/front/cron.php');
$native=file_get_contents($root
    .'/pssalesforcebridge/src/Growth/GrowthG8NativeStagingServices.php');
$worker=file_get_contents($root
    .'/pssalesforcebridge/src/Growth/GrowthG8StagingWorker.php');
$pair=file_get_contents($root
    .'/pssalesforcebridge/src/Growth/GrowthG8UatPairScope.php');
$coordinator=file_get_contents($root
    .'/pssalesforcebridge/src/Growth/GrowthAutomaticOneShotCoordinator.php');
$lease=file_get_contents($root
    .'/pssalesforcebridge/src/Growth/GrowthPrestaShopDeliveryLease.php');
$upgrade=file_get_contents($root.'/pssalesforcebridge/upgrade/upgrade-0.9.8.php');

pairCronAssert(strpos($module,"\$this->version = '0.9.8';")!==false
    && strpos($upgrade,'function upgrade_module_0_9_7(')!==false
    && strpos($upgrade,'return true;')!==false
    && strpos($upgrade,'Configuration::updateValue(')===false
    && strpos($upgrade,'Db::')===false,
    'beta_upgrade_must_be_no_op');

$tokenCheck=strpos($front,'hash_equals($expected, $provided)');
$operationRead=strpos($front,"Tools::getValue('operation'");
pairCronAssert($tokenCheck!==false && $operationRead!==false
    && $tokenCheck<$operationRead
    && strpos($front,"'status','inspect','dispatch','dispatch_pair'")!==false
    && strpos($front,"'invalid_operation'")!==false,
    'pair_cron_must_be_token_protected');

$start=strpos($module,"public function cronReport(");
pairCronAssert($start!==false,'cron_entry_missing');
$cron=substr($module,$start);
pairCronAssert(strpos($cron,"if (\$operation==='dispatch' || \$operation==='dispatch_pair')")!==false
    && strpos($cron,"\$type=\$operation==='dispatch_pair'?null:'PSCustomer'")!==false
    && strpos($cron,'GrowthG8NativeStagingServices::run(')!==false
    && strpos($cron,"'PS_GROWTH_G8_STAGING_APPROVAL'")!==false
    && strpos($cron,"PS_SFMC_MODE")!==false
    && strpos($cron,"PS_GROWTH_LOCAL_CAPTURE")!==false
    && strpos($cron,'staging_dispatch_not_approved')!==false,
    'pair_never_default_or_unapproved');

$stagePos=strpos($cron,"if (\$operation==='dispatch' || \$operation==='dispatch_pair')");
$workerPos=strpos($cron,'GrowthG8NativeStagingServices::run(');
pairCronAssert($stagePos!==false && $stagePos<$workerPos
    && strpos($cron,'if ($operation===\'dispatch_pair\')')===false,
    'pair_is_not_an_implicit_default_branch');

pairCronAssert(strpos($native,'$objectType=\'PSCustomer\'')!==false
    && strpos($native,'$objectType!==null')!==false
    && strpos($native,'GrowthG8UatPairScope::inspect(')!==false
    && strpos($native,'GrowthG8StagingWorker::run(')!==false
    && strpos($native,'],'.'$objectType')!==false
    && strpos($native,'parentDloVerified')!==false,
    'native_pair_and_single_object_must_share_worker');

pairCronAssert(strpos($worker,'$objectType=null')!==false
    && strpos($worker,'GrowthG8UatPairScope::candidates(')!==false
    && strpos($worker,'GrowthG8StagingReadyGate::promote(')!==false
    && strpos($worker,'GrowthG8DurableReceiptBridge(')!==false
    && strpos($worker,"if (\$type==='PSOrder'")!==false
    && strpos($worker,"\$callbacks['parentDloVerified']")!==false
    && strpos($worker,"'absent_confirmed'")!==false,
    'worker_must_enforce_parent_and_exact_dlo');

pairCronAssert(strpos($pair,"['customer',\$t['customer_id'],'PSCustomer']")!==false
    && strpos($pair,"['order',\$t['order_id'],'PSOrder']")!==false
    && strpos($coordinator,"\$receipt!=='reserved_once'")!==false
    && strpos($coordinator,'$transportStarted=true;')!==false
    && strpos($coordinator,'$reserveOnce')!==false
    && strpos($lease,"o.delivery_attempts=o.delivery_attempts+1")!==false
    && strpos($lease,'o.delivery_attempts=0')!==false,
    'once_only_and_customer_before_order');

class Configuration {
    public static function get($key) {
        if ($key==='PS_SFMC_MODE')return 'test';
        if ($key==='PS_GROWTH_LOCAL_CAPTURE')return 'off';
        if ($key==='PS_SFMC_CART_CONSENT_MODE')return 'marketing_optin';
        if ($key==='PS_GROWTH_G8_STAGING_APPROVAL')return 'off';
        return false;
    }
}
class Customer {}
class Order {}
class Currency {}
require_once $root.'/pssalesforcebridge/src/Growth/GrowthG8StagingWorker.php';
require_once $root.'/pssalesforcebridge/src/Growth/GrowthG8NativeStagingServices.php';

$denied=\Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG8NativeStagingServices::run(
    new stdClass(),'9.1.5','ci_',1,null
);
pairCronAssert(is_array($denied)
    && $denied['status']==='staging_dispatch_not_approved'
    && $denied['counts']===null
    && $denied['automatic_retry_authorized']===false
    && $denied['marketing_sends_authorized']===false,
    'unapproved_pair_must_be_inert');

echo "G8 protected pair cron beta PASS: explicit operation, default OFF, parent DLO, CAS and no live egress.\n";
