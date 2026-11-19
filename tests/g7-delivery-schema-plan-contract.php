<?php
/**
 * Nonexecuting G7 SAME G3 OUTBOX additive column migration contract.
 * Full DDL proof and schema rollback run exclusively on disposable g3_ci.
 */
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthOutboxSchemaPlan.php';
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG7DeliverySchemaPlan.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG7DeliverySchemaPlan as Plan;

function g7SchemaAssert($yes,$code) {
    if (!$yes) throw new RuntimeException('g7_schema_contract_'.$code);
}
function g7SchemaReject($prefix) {
    try { Plan::preview($prefix); }
    catch (RuntimeException $e) {
        g7SchemaAssert($e->getMessage()==='g7_delivery_prefix_invalid'
            || $e->getMessage()==='g7_delivery_table_too_long',
            'wrong_error');
        return;
    }
    g7SchemaAssert(false,'unsafe_prefix_accepted');
}
foreach(['g3_','r1dn_','ps_','a',str_repeat('x',32)] as $prefix){
    $p=Plan::preview($prefix);
    g7SchemaAssert($p['table']===$prefix.'growth_outbox_v1'
        && strpos($p['upgrade_sql'],'ALTER TABLE')===0
        && substr_count($p['upgrade_sql'],'ADD COLUMN')===5
        && substr_count($p['rollback_sql'],'DROP COLUMN')===5
        && strpos($p['upgrade_sql'],"DEFAULT 'blocked'")!==false
        && strpos($p['upgrade_sql'],'ADD KEY')!==false
        && strpos($p['upgrade_sql'],"status='ready'")===false
        && strpos($p['upgrade_sql'],'DROP TABLE')===false
        && strpos($p['upgrade_sql'],'CREATE TABLE')===false
        && $p['g3_original_blocked_constraint_preserved']
        && $p['new_rows_delivery_state']==='blocked'
        && !$p['merchant_upgrade_authorized']
        && !$p['automatic_rollback_authorized']
        && !$p['dispatch_authorized']
        && !$p['marketing_sends_authorized']
        && !$p['data_cloud_ingestion_authorized'],
        'unsafe_plan');
}
foreach(['', 'ps-','ps@','ps_;DROP TABLE',
    '../ps_','a b_',str_repeat('a',33)] as $bad) {
    g7SchemaReject($bad);
}
g7SchemaReject(null);
g7SchemaReject(42);
$none=Plan::reviewRollback(0);
$attempts=Plan::reviewRollback(1);
g7SchemaAssert($none['status']==='rollback_candidate_requires_owner_approval'
    && $none['owner_approval_required']
    && $none['backup_required']
    && !$none['automatic_rollback_authorized']
    && !$none['rollback_executed']
    && $attempts['status']==='rollback_blocked_retains_delivery_evidence'
    && !$attempts['automatic_rollback_authorized'],
    'rollback_policy');
foreach([-1,'0',null] as $bad) {
    $denied=false;
    try { Plan::reviewRollback($bad); }
    catch (RuntimeException $e) {
        $denied=$e->getMessage()==='g7_delivery_rollback_count_invalid';
    }
    g7SchemaAssert($denied,'unsafe_rollback_arg');
}
$module=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/pssalesforcebridge.php');
$cron=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/controllers/front/cron.php');
$planCode=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG7DeliverySchemaPlan.php');
// The review-only plan can now be loaded by the explicit BO diagnostic,
// but must still be absent from automatic install, upgrade and cron.
$installStart=strpos($module,'public function install()');
$uninstallStart=strpos($module,'public function uninstall()');
$upgrade=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/upgrade/upgrade-0.8.8.php');
g7SchemaAssert($installStart!==false && $uninstallStart>$installStart
    && strpos(substr($module,$installStart,$uninstallStart-$installStart),
        'GrowthG7StagingDeliveryMigration')===false
    && strpos($cron,'GrowthG7DeliverySchemaPlan')===false
    && strpos($cron,'GrowthG7StagingDeliveryMigration')===false
    && strpos($upgrade,'ALTER TABLE')===false
    && strpos($upgrade,'GrowthG7DeliverySchemaPlan')===false,
    'unexpected_live_migration');
foreach(['Db::','->execute(', '->exec(', 'curl_', 'file_get_contents(',
    'Configuration::updateValue('] as $forbidden) {
    g7SchemaAssert(strpos($planCode,$forbidden)===false,
        'side_effect_in_review_plan');
}
echo "G7 additive same-outbox lease schema plan PASS: safe defaults, gated rollback, no live install.\n";
