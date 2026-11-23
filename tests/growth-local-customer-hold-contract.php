<?php
/**
 * G6 read-only local customer status and G7 cart hold composition.
 * Synthetic rows only; NEVER proves purpose consent, never sends.
 */
require_once dirname(__DIR__) . '/pssalesforcebridge/src/Growth/GrowthLocalCustomerHoldReader.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/Growth/LocalOrderPresenceReader.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/Growth/GrowthCartConversionHoldGate.php';
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthLocalCustomerHoldReader as Reader;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthCartConversionHoldGate as Cart;

function localCustomerAssert($ok, $reason)
{
    if (!$ok) {
        fwrite(STDERR, "G6 local customer hold FAIL: " . $reason . PHP_EOL);
        exit(1);
    }
}
function localCustomerReject($fn, $code)
{
    try {
        $fn();
    } catch (RuntimeException $e) {
        localCustomerAssert($e->getMessage() === $code,
            'different_failure_' . $e->getMessage());
        return;
    }
    localCustomerAssert(false, 'should_reject_' . $code);
}
function customerRow()
{
    return ['id_customer' => '17', 'id_shop' => '2',
        'active' => '1', 'deleted' => '0', 'is_guest' => '0',
        'newsletter' => '1', 'optin' => '1'];
}
function localSignals()
{
    return [
        'provider' => 'marketing_cloud_growth',
        'mode' => 'test',
        'installation_shop_scope' => 'verified',
        'cart_status' => 'abandoned',
        'snapshot_current' => true,
        'cart_has_products' => true,
        'abandonment_window' => 'elapsed',
        'current_customer_state' => 'active',
        'contact_matches_snapshot' => true,
        'growth_consent_for_purpose' => 'verified',
        'growth_suppression' => 'clear',
        'frequency_cap' => 'available',
    ];
}
function localAssertSafe($result)
{
    localCustomerAssert($result['status'] === 'hold_for_review',
        'unexpected_send_or_review_state');
    foreach (['customer_contact_authorized', 'growth_purpose_consent_certified',
        'source_customer_policy_certified', 'data_cloud_ingestion_authorized',
        'marketing_sends_authorized'] as $flag) {
        if (array_key_exists($flag, $result)) {
            localCustomerAssert($result[$flag] === false,
                'unapproved_flag_' . $flag);
        }
    }
    $json = json_encode($result);
    foreach (['private@example.invalid', 'source-secret', '"id_customer"',
        '"id_shop"', '"customer_id"'] as $private) {
        localCustomerAssert(strpos($json, $private) === false,
            'source_data_leaked_' . $private);
    }
}
function localCustomerInspect($rows, $expectedReasons, $state)
{
    $queries = [];
    $fn = function ($sql) use (&$queries, $rows) {
        $queries[] = $sql;
        localCustomerAssert($sql === 'SELECT `id_customer`, `id_shop`, '
            . '`active`, `deleted`, `is_guest`, `newsletter`, `optin`'
            . ' FROM `ps_ci_customer` WHERE `id_shop` = 2'
            . ' AND `id_customer` = 17 LIMIT 2',
            'unscoped_or_pii_sql');
        if ($rows instanceof Exception) {
            throw $rows;
        }
        return $rows;
    };
    $result = Reader::inspect(2, 17, $fn, 'ps_ci_');
    localCustomerAssert(count($queries) === 1, 'not_one_source_read');
    localAssertSafe($result);
    localCustomerAssert($result['local_customer_source_state'] === $state,
        'wrong_source_state');
    foreach ($expectedReasons as $reason) {
        localCustomerAssert(in_array($reason, $result['reasons'], true),
            'reason_missing_' . $reason);
    }
    return $result;
}
localCustomerInspect([customerRow()],
    ['growth_purpose_consent_not_certified'], 'no_local_negative_observed');
foreach ([
    ['active', '0', 'local_customer_inactive'],
    ['deleted', '1', 'local_customer_deleted'],
    ['is_guest', '1', 'local_customer_guest'],
    ['newsletter', '0', 'local_newsletter_negative'],
    ['optin', '0', 'local_optin_negative'],
] as $case) {
    $row = customerRow();
    $row[$case[0]] = $case[1];
    localCustomerInspect([$row], [$case[2]], 'known_negative');
}
$row = customerRow();
$row['active'] = '0';
$row['deleted'] = '1';
$row['newsletter'] = '0';
$row['optin'] = '0';
localCustomerInspect([$row], [
    'local_customer_inactive', 'local_customer_deleted',
    'local_newsletter_negative', 'local_optin_negative'
], 'known_negative');

localCustomerInspect([], ['local_customer_not_found'], 'unknown');
localCustomerInspect(false, ['local_customer_read_unverified'], 'unknown');
localCustomerInspect(new RuntimeException('db down'),
    ['local_customer_read_unverified'], 'unknown');
localCustomerInspect([customerRow(), customerRow()],
    ['local_customer_read_unverified'], 'unknown');
localCustomerInspect(['unexpected'=>'row'],
    ['local_customer_read_unverified'], 'unknown');
foreach (['id_shop' => '3', 'id_customer' => '18'] as $k => $value) {
    $row = customerRow();
    $row[$k] = $value;
    localCustomerInspect([$row], ['local_customer_scope_mismatch'], 'unknown');
}
foreach (['active' => true, 'deleted' => '2', 'is_guest' => null,
    'newsletter' => -1, 'optin' => 'yes'] as $k => $value) {
    $row = customerRow();
    $row[$k] = $value;
    localCustomerInspect([$row], ['local_customer_read_unverified'], 'unknown');
}
$row = customerRow();
$row['email'] = 'private@example.invalid';
localCustomerInspect([$row], ['local_customer_read_unverified'], 'unknown');

$invalidCalls = 0;
$spy = function () use (&$invalidCalls) {
    ++$invalidCalls;
    return [];
};
foreach ([0, -1, '2', true] as $invalidId) {
    localCustomerReject(function () use ($invalidId, $spy) {
        Reader::inspect($invalidId, 17, $spy, 'ps_ci_');
    }, 'growth_customer_scope_invalid');
    localCustomerReject(function () use ($invalidId, $spy) {
        Reader::inspect(2, $invalidId, $spy, 'ps_ci_');
    }, 'growth_customer_scope_invalid');
}
localCustomerReject(function () {
    Reader::inspect(2, 17, false, 'ps_ci_');
}, 'growth_customer_reader_required');
foreach (['', 'ps_bad`prefix', str_repeat('x', 65)] as $prefix) {
    localCustomerReject(function () use ($prefix, $spy) {
        Reader::inspect(2, 17, $spy, $prefix);
    }, 'growth_customer_prefix_invalid');
}
localCustomerAssert($invalidCalls === 0, 'invalid_scope_reached_sql');

$queries = [];
$selector = function ($sql) use (&$queries) {
    $queries[] = $sql;
    if (strpos($sql, 'FROM `ps_ci_customer`') !== false) {
        return [customerRow()];
    }
    if (strpos($sql, 'FROM `ps_ci_orders`') !== false
        || strpos($sql, 'FROM `ps_ci_sfmc_order_sync`') !== false) {
        return [];
    }
    throw new RuntimeException('query_not_allowlisted');
};
$out = Cart::inspectWithLocalCustomer(2, 93, 17, $selector, 'ps_ci_', localSignals());
localAssertSafe($out);
localCustomerAssert(count($queries) === 5
    && strpos($queries[0], 'ps_ci_customer') !== false
    && $out['local_customer_source_state'] === 'no_local_negative_observed'
    && in_array('purpose_consent_unverified', $out['reasons'], true)
    && in_array('growth_purpose_consent_not_certified', $out['reasons'], true)
    && $out['marketing_sends_authorized'] === false
    && $out['race_with_future_dispatch_closed'] === false,
    'positive_shop_flags_accidentally_authorized_contact');

$selectorNegative = function ($sql) {
    if (strpos($sql, 'FROM `ps_ci_customer`') !== false) {
        $row = customerRow();
        $row['deleted'] = '1';
        $row['optin'] = '0';
        return [$row];
    }
    return [];
};
$negative = Cart::inspectWithLocalCustomer(
    2, 93, 17, $selectorNegative, 'ps_ci_', localSignals()
);
localAssertSafe($negative);
localCustomerAssert($negative['local_customer_source_state'] === 'known_negative'
    && in_array('local_customer_deleted', $negative['reasons'], true)
    && in_array('local_optin_negative', $negative['reasons'], true)
    && in_array('customer_state_unverified', $negative['reasons'], true),
    'negative_customer_state_failed_to_override_user_assertion');

$sourceFail = Cart::inspectWithLocalCustomer(
    2, 93, 17, function ($sql) {
        return strpos($sql, 'FROM `ps_ci_customer`') !== false ? false : [];
    }, 'ps_ci_', localSignals()
);
localAssertSafe($sourceFail);
localCustomerAssert(in_array('local_customer_read_unverified', $sourceFail['reasons'], true)
    && in_array('purpose_consent_unverified', $sourceFail['reasons'], true),
    'failed_customer_query_not_suppressed');

$skip = 0;
$spy = function () use (&$skip) { ++$skip; return []; };
$badSignals = localSignals();
$badSignals['mode'] = 'production';
localCustomerReject(function () use ($badSignals, $spy) {
    Cart::inspectWithLocalCustomer(2, 93, 17, $spy, 'ps_ci_', $badSignals);
}, 'growth_cart_signal_value_invalid');
localCustomerAssert($skip === 0, 'bad_signals_reached_sql');

$module = file_get_contents(dirname(__DIR__) . '/pssalesforcebridge/pssalesforcebridge.php');
$source = file_get_contents(dirname(__DIR__)
    . '/pssalesforcebridge/src/Growth/GrowthLocalCustomerHoldReader.php');
localCustomerAssert(strpos($module, 'GrowthLocalCustomerHoldReader.php') !== false
    && strpos($module, 'GrowthG8NativeStagingServices::run(') !== false
    && strpos($module, 'inspectWithLocalCustomer') === false,
    'customer_reader_outside_protected_stage');
foreach (['\`email\`', '\`firstname\`', '\`lastname\`',
    'CURLOPT_', '/api/v1/ingest/', '->insert(', '->execute(', 'file_put_contents('] as $bad) {
    localCustomerAssert(strpos($source, $bad) === false,
        'not_local_read_only_' . $bad);
}
echo "G6 customer local HOLD + G7 cart composition PASS; no consent proof/sends.\n";
