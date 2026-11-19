<?php
/** G7 staging delivery migration must stay explicit and outbound blocked. */
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG7StagingDeliveryMigration.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG7StagingDeliveryMigration as G7;

function g7MigrationAssert($cond,$why) {
    if (!$cond) throw new RuntimeException('g7_migration_contract_'.$why);
}
$original=[
    'accepted_sequence','source_namespace','shop_id','entity_type',
    'entity_id','dedupe_fingerprint','original_event_type',
    'captured_at_utc','captured_at_precision','clock_source',
    'clock_sync_verified','source_modified_provenance','status'
];
$extended=array_merge($original,[
    'delivery_state','lease_token_hash','lease_until_epoch',
    'delivery_attempts','delivery_last_http'
]);
g7MigrationAssert(G7::outboxColumnsAllowed($original)
    && G7::outboxColumnsAllowed($extended)
    && !G7::outboxColumnsAllowed(array_slice($extended,0,-1))
    && !G7::outboxColumnsAllowed(array_reverse($extended))
    && !G7::outboxColumnsAllowed(array_merge($extended,['unknown'])),
    'partial_or_extra_columns_not_rejected');
g7MigrationAssert(G7::APPROVE==='PREPARE_G7_STAGING_OUTBOX_ONLY'
    && G7::UNDO==='ROLLBACK_G7_STAGING_OUTBOX_ONLY'
    && G7::APPROVE!==G7::UNDO, 'separate_owner_approvals_missing');
$module=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/pssalesforcebridge.php');
$cron=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/controllers/front/cron.php');
$upgrade=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/upgrade/upgrade-0.8.8.php');
$service=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG7StagingDeliveryMigration.php');
g7MigrationAssert(strpos($module,"$"."this->version = '0.9.1'")!==false
    && strpos($module,'submitPsG7SchemaInspect')!==false
    && strpos($module,'submitPsG7SchemaPrepare')!==false
    && strpos($module,'submitPsG7SchemaRollback')!==false
    && strpos($module,'I_HAVE_A_VERIFIED_STAGING_BACKUP')!==false,
    'admin_forms_or_version_missing');
g7MigrationAssert(strpos($upgrade,'ALTER TABLE')===false
    && strpos($upgrade,'GrowthG7StagingDeliveryMigration')===false
    && strpos($upgrade,"'test'")!==false
    && strpos($upgrade,"'off'")!==false,
    'automatic_ddl_or_egress_in_upgrade');
g7MigrationAssert(strpos($cron,'GrowthG7StagingDeliveryMigration')===false
    && strpos($cron,'GrowthG7DeliverySchemaPlan')===false,
    'migration_wired_to_cron');
foreach (['merchant_upgrade_authorized'=>false] as $key=>$unused) {
    g7MigrationAssert(strpos($service,'GrowthG7DeliverySchemaPlan::preview')!==false
        && strpos($service,'GrowthSqlEngineGate::inspectPrestaShop')!==false
        && strpos($service,"'PS_GROWTH_LOCAL_CAPTURE'")!==false
        && strpos($service,'GrowthOutboxSchemaPlan::preview')!==false
        && strpos($service,"'g7_delivery_history_prevents_rollback'")!==false,
        'safety_prerequisites_absent');
}
echo "G7 staging migration contract PASS: strict schema, owner+backup, no upgrade DDL or sends.\n";
