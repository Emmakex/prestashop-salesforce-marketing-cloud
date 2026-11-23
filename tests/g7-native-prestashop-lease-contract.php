<?php
/**
 * G7 native PrestaShop Db lease contract.
 * Pure PHP 7.1/8.5 test: no merchant access or network requests.
 */
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthPrestaShopDeliveryLease.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthPrestaShopDeliveryLease as Lease;

function g7NativeLeaseAssert($v,$code) {
    if (!$v) throw new RuntimeException('g7_native_lease_contract_'.$code);
}
$reflection=new ReflectionClass('Emmakex\\PrestaShopSalesforceMarketingCloud\\Growth\\GrowthPrestaShopDeliveryLease');
foreach (['inspect','claim','cancel','markAcceptedUnconfirmed',
    'markUnknownUnconfirmed','holdClaim','quarantineExpired'] as $method) {
    g7NativeLeaseAssert($reflection->hasMethod($method)
        && $reflection->getMethod($method)->isPublic(),
        'missing_'.$method);
}
$code=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthPrestaShopDeliveryLease.php');
$module=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/pssalesforcebridge.php');
$cron=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/controllers/front/cron.php');
// The G8 cron may require the lease class ONLY for its staging-only
// schema guard inside a read-only event scan. Never call lease mutation,
// claim, cancellation, transport or reconciliation from module/cron.
g7NativeLeaseAssert(
    strpos($module,'GrowthG8CronReadOnly::inspect(')!==false
    && strpos($cron,'GrowthG8CronReadOnly::inspect(')===false
    && strpos($cron,'GrowthPrestaShopDeliveryLease')===false
    && strpos($module,'new GrowthPrestaShopDeliveryLease(')===false
    && strpos($module,'->claim(')===false
    && strpos($module,'->markAcceptedUnconfirmed(')===false
    && strpos($module,'->markUnknownUnconfirmed(')===false
    && strpos($module,'->quarantineExpired(')===false
    && strpos($cron,'->claim(')===false
    && strpos($cron,'->markAcceptedUnconfirmed(')===false
    && strpos($cron,'->markUnknownUnconfirmed(')===false
    && strpos($cron,'->quarantineExpired(')===false,
    'lease_mutation_wired_to_live_module');
foreach ([
    'GrowthG7StagingDeliveryMigration::inspect',
    "AND o.delivery_state='ready' AND o.delivery_attempts=0",
    "AND o.lease_token_hash IS NULL",
    "AND o.lease_until_epoch IS NULL",
    "AND o.delivery_last_http IS NULL",
    'l.latest_accepted_sequence=o.accepted_sequence',
    "AND o.status='blocked'",
    "AND l.status='blocked'",
    "SELECT ROW_COUNT()",
    'hash(',
    "'reconcile'",
    'quarantineExpired',
    "'PS_GROWTH_LOCAL_CAPTURE'",
    "'data_cloud_ingestion_authorized'=>false",
    "'outbound_worker_authorized'=>false",
    "'marketing_sends_authorized'=>false"
] as $required) {
    g7NativeLeaseAssert(strpos($code,$required)!==false,
        'safety_boundary_missing');
}
foreach ([
    'curl_', 'file_get_contents(', 'Mail::send(', 'new \\PDO(',
    'wp_remote_post(', '->post(', '->send(',
    "delivery_state='ready',"
] as $forbidden) {
    g7NativeLeaseAssert(strpos($code,$forbidden)===false,
        'unexpected_transport_or_ready_writer');
}
g7NativeLeaseAssert(strpos($code,'delivery_attempts<3')===false
    && strpos($code,'delivery_attempts<2')===false,
    'prior_attempt_reclaim_not_blocked');
g7NativeLeaseAssert(strpos($code,'GrowthOutboxSchemaPlan::preview')!==false
    && strpos($code,'executeS($sql,true,false)')!==false
    && strpos($code,"getValue('SELECT ROW_COUNT()',false)")!==false,
    'prestashop_db_contract');
echo "G7 native PrestaShop Db lease contract PASS: same outbox, staged gates, no sender or ready writes.\n";
