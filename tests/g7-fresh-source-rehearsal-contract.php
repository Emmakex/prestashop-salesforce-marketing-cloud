<?php
/**
 * Unit source boundary of G7 fresh-source+same-outbox lease rehearsal.
 * Functional concurrency/ledger/opt-out/202 tests run on disposable G3 DB
 * in tests/integration/g7_durable_outbox_lease.php.
 */
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthFreshSourceBlockedRehearsal.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthFreshSourceBlockedRehearsal as Fresh;

function g7FreshContractAssert($ok,$code) {
    if (!$ok) throw new RuntimeException('g7_fresh_contract_'.$code);
}
$ref=new ReflectionClass('Emmakex\\PrestaShopSalesforceMarketingCloud\\Growth\\GrowthFreshSourceBlockedRehearsal');
g7FreshContractAssert($ref->hasMethod('simulate')
    && $ref->getMethod('simulate')->isPublic()
    && $ref->hasMethod('inspectSnapshot')
    && $ref->getMethod('inspectSnapshot')->isPrivate(),
    'expected_composition_missing');
$src=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthFreshSourceBlockedRehearsal.php');
$module=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/pssalesforcebridge.php');
$cron=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/controllers/front/cron.php');
$lease=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthOutboxDeliveryLease.php');
foreach (['curl_', 'file_get_contents(', 'file_put_contents(',
    'Mail::send(', 'Tools::redirect(', 'new \PDO(', 'getenv(\'SF_'] as $bad) {
    g7FreshContractAssert(strpos($src,$bad)===false,
        'unexpected_io_'.$bad);
}
g7FreshContractAssert(strpos($src,'GrowthCapturedEventPstPreview::inspect')!==false
    && strpos($src,'$lease->claim(')!==false
    && strpos($src,'$lease->cancel(')!==false
    && strpos($src,'$lease->markAcceptedUnconfirmed(')!==false
    && strpos($src,'$lease->markUnknownUnconfirmed(')!==false
    && strpos($src,'$lease->holdClaim(')!==false
    && strpos($src,'hash_equals(')!==false
    && strpos($src,'http_request_performed\'=>false')!==false,
    'required_boundaries_missing');
g7FreshContractAssert(strpos($module,'GrowthFreshSourceBlockedRehearsal')===false
    && strpos($cron,'GrowthFreshSourceBlockedRehearsal')===false,
    'rehearsal_wired_to_merchant');
g7FreshContractAssert(strpos($lease,"'g3_ci'")!==false
    && strpos($lease,'G7_CI_LEASE_ENABLE')!==false,
    'sandbox_guard_removed');
echo "G7 fresh source rehearsal contract PASS: native PST + G3 lease, no merchant or HTTP path.\n";
