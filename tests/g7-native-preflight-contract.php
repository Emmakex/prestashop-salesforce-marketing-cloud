<?php
/** Read-only G7 source/ledger + native PSCustomer/PSOrder preflight contract. */
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG7NativePreflight.php';
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG7NativePreflight as Preflight;
function g7PreflightAssert($v,$why) {
    if (!$v)throw new RuntimeException('g7_preflight_contract_'.$why);
}
$ref=new ReflectionClass('Emmakex\\PrestaShopSalesforceMarketingCloud\\Growth\\GrowthG7NativePreflight');
g7PreflightAssert($ref->hasMethod('inspectLatest')
    && $ref->getMethod('inspectLatest')->isPublic()
    && $ref->hasMethod('snapshot')
    && $ref->getMethod('snapshot')->isPrivate(),
    'method_boundary');
$code=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG7NativePreflight.php');
$module=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/pssalesforcebridge.php');
$cron=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/controllers/front/cron.php');
g7PreflightAssert(
    strpos($module,'GrowthG7NativePreflight')===false
    && strpos($cron,'GrowthG7NativePreflight')===false,
    'unexpected_runtime_wiring');
foreach([
    'GrowthPrestaShopDeliveryLease',
    'GrowthCapturedEventPstPreview::inspect',
    'GrowthLocalCustomerHoldReader::inspect',
    'nativeCustomerState',
    'executeS($sql,true,false)',
    'hash_equals(',
    "delivery_state']!=='blocked'",
    'source_unverified_hold',
    "'marketing_sends_authorized'=>false",
    "'data_cloud_ingestion_authorized'=>false",
    "'http_request_performed'=>false"
] as $required) {
    g7PreflightAssert(strpos($code,$required)!==false,
        'missing_safety_boundary');
}
foreach([
    'curl_', 'file_get_contents(', '->execute(', '->claim(',
    '->cancel(', '->post(', 'Mail::send(',
    'file_put_contents(', '->updateValue(',
    "delivery_state='ready'"
] as $forbidden) {
    g7PreflightAssert(strpos($code,$forbidden)===false,
        'unexpected_write_or_transport');
}
echo "G7 native source/ledger preflight PASS: double read, PST, local negative HOLD, no sends.\n";
