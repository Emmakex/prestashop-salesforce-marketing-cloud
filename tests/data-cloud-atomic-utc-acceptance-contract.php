<?php
/** G3 offline acceptance test: replay keeps first UTC stamp; no database/API. */
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/LiveEventUtcObservation.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/StagedAtomicUtcAcceptance.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\StagedAtomicUtcAcceptance;

function stagedAcceptAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}
function stagedReject($cb, $message)
{
    try {
        call_user_func($cb);
        stagedAcceptAssert(false, 'Rejected case accepted: ' . $message);
    } catch (RuntimeException $e) {
        stagedAcceptAssert(strpos($e->getMessage(), 'g3_') === 0,
            'Wrong failure for ' . $message . ': ' . $e->getMessage());
    }
}

$rows = [];
$seq = 0;
$factoryCallCount = 0;
$store = function (array $identity, $factory) use (&$rows, &$seq, &$factoryCallCount) {
    $key = $identity['source_namespace'] . ':' . $identity['dedupe_fingerprint'];
    if (isset($rows[$key])) {
        return ['row' => $rows[$key], 'inserted' => false];
    }
    ++$factoryCallCount;
    $row = $factory();
    $row['accepted_sequence'] = ++$seq;
    $rows[$key] = $row;
    return ['row' => $row, 'inserted' => true];
};
$hashA = str_repeat('a', 40);
$hashB = str_repeat('b', 40);
$customerDedupeA = 'customer:1:17:' . $hashA;
$customerDedupeB = 'customer:1:17:' . $hashB;

$first = StagedAtomicUtcAcceptance::accept('bc_installation', 'CUSTOMER_CREATED',
    'customer', 1, 17, $customerDedupeA, $store);
stagedAcceptAssert($first['inserted'] && $first['accepted_sequence'] === 1
    && $first['captured_at_reused'] === false, 'First acceptance missing.');
stagedAcceptAssert($first['row']['original_event_type'] === 'CUSTOMER_CREATED',
    'Original accepted event not preserved.');
$original = $first['captured_at_utc'];

date_default_timezone_set('Pacific/Auckland');
$retry = StagedAtomicUtcAcceptance::accept('bc_installation', 'CUSTOMER_UPDATED',
    'customer', 1, 17, $customerDedupeA, $store);
stagedAcceptAssert(!$retry['inserted'] && $retry['captured_at_reused'] &&
    $retry['captured_at_utc'] === $original &&
    $retry['row']['original_event_type'] === 'CUSTOMER_CREATED' &&
    $retry['accepted_sequence'] === 1 && $factoryCallCount === 1,
    'Retry incorrectly regenerated UTC or accepted a new customer event.');
date_default_timezone_set('UTC');

$second = StagedAtomicUtcAcceptance::accept('bc_installation', 'CUSTOMER_UPDATED',
    'customer', 1, 17, $customerDedupeB, $store);
stagedAcceptAssert($second['inserted'] && $second['accepted_sequence'] === 2,
    'Genuinely changed customer payload must create a distinct observation.');
stagedAcceptAssert($second['row']['dedupe_fingerprint'] !== $first['row']['dedupe_fingerprint'],
    'Commercial deduplication fingerprint unexpectedly collided.');
// A delayed replay of the earlier event must not overwrite a later accepted
// version. Acceptance order is not a source revision or a marketing watermark.
$late = StagedAtomicUtcAcceptance::accept('bc_installation', 'CUSTOMER_CREATED',
    'customer', 1, 17, $customerDedupeA, $store);
stagedAcceptAssert($late['accepted_sequence'] === 1
    && !$late['apply_out_of_order_authorized']
    && !$late['source_revision_verified'],
    'A stale event has been authorized for delivery.');
stagedAcceptAssert($seq === 2 && count($rows) === 2,
    'A replay inserted a new row.');

$anotherInstall = StagedAtomicUtcAcceptance::accept('another_installation', 'CUSTOMER_CREATED',
    'customer', 1, 17, $customerDedupeA, $store);
stagedAcceptAssert($anotherInstall['inserted'] && $anotherInstall['accepted_sequence'] === 3
    && $anotherInstall['row']['dedupe_fingerprint'] !== $first['row']['dedupe_fingerprint'],
    'Same customer ID on another installation must be isolated.');
$anotherShop = StagedAtomicUtcAcceptance::accept('bc_installation', 'CUSTOMER_CREATED',
    'customer', 2, 17, 'customer:2:17:' . $hashA, $store);
stagedAcceptAssert($anotherShop['inserted'] && $anotherShop['accepted_sequence'] === 4,
    'Different shop ID has to remain an independent event.');
$order = StagedAtomicUtcAcceptance::accept('bc_installation', 'ORDER_PAID',
    'order', 1, 17, 'order:1:17:' . $hashA, $store);
stagedAcceptAssert($order['inserted'] && $order['accepted_sequence'] === 5,
    'Order must not collide with customer for the same ID.');
foreach ([$first, $retry, $second, $late, $anotherInstall, $anotherShop, $order] as $result) {
    foreach (['source_revision_verified', 'apply_out_of_order_authorized',
        'record_modified_authorized', 'data_360_ingestion_authorized',
        'marketing_sends_authorized', 'storage_adapter_certified'] as $flag) {
        stagedAcceptAssert($result[$flag] === false, $flag . ' must remain false.');
    }
    stagedAcceptAssert($result['source_updated_utc'] === null,
        'A hook capture is not source_updated_utc.');
}
stagedAcceptAssert($factoryCallCount === 5, 'Factory called on duplicate acceptance.');

// Validation happens BEFORE calling an untrusted injected store.
$calls = 0;
$spy = function () use (&$calls) { ++$calls; return null; };
stagedReject(function () use ($spy, $customerDedupeA) {
    StagedAtomicUtcAcceptance::accept('', 'CUSTOMER_CREATED', 'customer', 1, 17, $customerDedupeA, $spy);
}, 'empty installation namespace');
stagedReject(function () use ($spy, $customerDedupeA) {
    StagedAtomicUtcAcceptance::accept('bc_installation', 'ORDER_PAID', 'customer', 1, 17, $customerDedupeA, $spy);
}, 'wrong hook type');
stagedReject(function () use ($spy, $customerDedupeA) {
    StagedAtomicUtcAcceptance::accept('bc_installation', 'CUSTOMER_CREATED', 'customer', 2, 17, $customerDedupeA, $spy);
}, 'cross-shop dedupe key');
stagedReject(function () use ($spy) {
    StagedAtomicUtcAcceptance::accept('bc_installation', 'CUSTOMER_UPDATED', 'customer',
        1, 17, 'customer:1:17:invalid', $spy);
}, 'non-hash legacy key');
stagedReject(function () use ($spy, $customerDedupeA) {
    StagedAtomicUtcAcceptance::accept('bc_installation', 'CUSTOMER_CREATED', 'customer',
        1, 17, $customerDedupeA, null);
}, 'missing store');
stagedAcceptAssert($calls === 0, 'Invalid input called the storage adapter.');

// Response verification: store must not inject PII, forge identity/UTC or
// pretend an existing event was just inserted without invoking the factory.
$malicious = function ($identity, $factory) {
    $row = $factory();
    $row['accepted_sequence'] = 1;
    $row['email'] = 'synthetic@example.invalid';
    return ['row' => $row, 'inserted' => true];
};
stagedReject(function () use ($customerDedupeA, $malicious) {
    StagedAtomicUtcAcceptance::accept('bc_installation', 'CUSTOMER_CREATED',
        'customer', 1, 17, $customerDedupeA, $malicious);
}, 'store includes raw PII');
$wrongKey = function ($identity, $factory) {
    $row = $factory();
    $row['accepted_sequence'] = 1;
    $row['source_namespace'] = 'other_install';
    return ['row' => $row, 'inserted' => true];
};
stagedReject(function () use ($customerDedupeA, $wrongKey) {
    StagedAtomicUtcAcceptance::accept('bc_installation', 'CUSTOMER_CREATED',
        'customer', 1, 17, $customerDedupeA, $wrongKey);
}, 'response identity changed');
$fakeExisting = function ($identity, $factory) {
    $row = $factory();
    $row['accepted_sequence'] = 1;
    return ['row' => $row, 'inserted' => false];
};
stagedReject(function () use ($customerDedupeA, $fakeExisting) {
    StagedAtomicUtcAcceptance::accept('bc_installation', 'CUSTOMER_CREATED',
        'customer', 1, 17, $customerDedupeA, $fakeExisting);
}, 'store wrongly regenerated original date on existing key');

$source = file_get_contents(dirname(__DIR__)
    . '/pssalesforcebridge/src/DataCloud/StagedAtomicUtcAcceptance.php');
foreach (['Db::', 'curl_', 'file_get_contents(', '->enqueue(', '->execute(',
    '/interaction/v1/events', '/api/v1/ingest/'] as $forbidden) {
    stagedAcceptAssert(strpos($source, $forbidden) === false,
        'Staged contract must not invoke database, queue or Salesforce transport.');
}
echo "G3 atomic acceptance candidate contract: write-once/retry/cross-shop safety passed; no DB or sends.\n";
