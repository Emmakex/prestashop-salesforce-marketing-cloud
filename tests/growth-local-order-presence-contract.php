<?php
/** Real module class, local SELECT-only reader, tested without a shop or DB. */
require_once dirname(__DIR__)
    . '/pssalesforcebridge/src/Growth/LocalOrderPresenceReader.php';
require_once __DIR__ . '/review/G3GrowthCartSourcePresendReview.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\LocalOrderPresenceReader;

function growthOrderCheck($condition, $reason)
{
    if (!$condition) {
        fwrite(STDERR, 'Growth local order reader: ' . $reason . "\n");
        exit(1);
    }
}
function growthOrderReject($callback, $expected)
{
    try {
        call_user_func($callback);
    } catch (RuntimeException $e) {
        growthOrderCheck($e->getMessage() === $expected, 'wrong_error_' . $expected);
        return;
    }
    growthOrderCheck(false, 'unsafe_accepted_' . $expected);
}
function growthOrderReview($native, $ledger)
{
    $queries = [];
    $reader = function ($query) use ($native, $ledger, &$queries) {
        $queries[] = $query;
        if (strpos($query, '`ps_test_orders`') !== false) {
            return $native;
        }
        if (strpos($query, '`ps_test_sfmc_order_sync`') !== false) {
            return $ledger;
        }
        throw new RuntimeException('unexpected_query');
    };
    $result = LocalOrderPresenceReader::inspect(1, 33, $reader, 'ps_test_');
    growthOrderCheck(count($queries) === 2, 'must_query_both_sources');
    foreach ($queries as $sql) {
        growthOrderCheck(strpos($sql, 'SELECT `id_shop`, `id_cart`, `id_order`') === 0
            && strpos($sql, ' WHERE `id_shop` = 1 AND `id_cart` = 33 ') !== false
            && substr($sql, -30) === 'ORDER BY `id_order` ASC LIMIT 1',
            'query_not_exact_shop_cart_limited');
        foreach (['email', 'customer', 'SELECT *', 'INSERT', 'UPDATE', 'DELETE',
            ' JOIN ', '/api/v1/ingest', 'RecoveryUrl'] as $unsafe) {
            growthOrderCheck(strpos($sql, $unsafe) === false,
                'query_contains_unapproved_term_' . $unsafe);
        }
    }
    foreach (['growth_cart_send_authorized', 'absence_certified_for_campaign',
        'growth_consent_certified', 'data_360_ingestion_authorized',
        'marketing_sends_authorized'] as $flag) {
        growthOrderCheck(isset($result[$flag]) && $result[$flag] === false,
            'read_must_not_authorize_' . $flag);
    }
    growthOrderCheck(strpos(json_encode($result), '777') === false,
        'raw_order_id_in_report');
    return $result;
}
$row = ['id_shop' => '1', 'id_cart' => '33', 'id_order' => '777'];
$noOrders = growthOrderReview([], []);
growthOrderCheck($noOrders['status'] === 'no_order_candidate_only'
    && $noOrders['native_order_state'] === 'none'
    && $noOrders['connector_order_state'] === 'none',
    'successful_empty_reads_must_be_tentative_only');

$fromNative = growthOrderReview([$row], []);
$fromLedger = growthOrderReview([], [$row]);
$withFailedSecondary = growthOrderReview([$row], false);
foreach ([$fromNative, $fromLedger, $withFailedSecondary] as $result) {
    growthOrderCheck($result['status'] === 'converted_locally',
        'known_purchase_should_be_found');
}
$unknownNative = growthOrderReview(false, []);
$unknownLedger = growthOrderReview([], false);
growthOrderCheck($unknownNative['status'] === 'unknown_hold'
    && $unknownLedger['status'] === 'unknown_hold',
    'error_must_not_be_false_negative');

// Failed/missing/corrupt legacy reader, wrong tenant scope or malformed data:
// NEVER treated as absence, even if native query is successfully empty.
$invalid = [
    false, null, 0, true, '', '0',
    [['id_shop' => 1, 'id_cart' => 33]],
    [['id_shop' => 2, 'id_cart' => 33, 'id_order' => 777]],
    [['id_shop' => 1, 'id_cart' => 34, 'id_order' => 777]],
    [['id_shop' => 1, 'id_cart' => 33, 'id_order' => 0]],
    [['id_shop' => 1, 'id_cart' => 33, 'id_order' => '777x']],
    [['id_shop' => 1, 'id_cart' => 33, 'id_order' => '922337203685477580800000']],
    [['id_shop' => 1, 'id_cart' => 33, 'id_order' => 777,
      'email' => 'private@example.invalid']],
    [$row, $row],
    ['id_shop' => 1, 'id_cart' => 33, 'id_order' => 777],
];
foreach ($invalid as $i => $value) {
    foreach ([true, false] as $badNative) {
        $result = $badNative ? growthOrderReview($value, [])
            : growthOrderReview([], $value);
        growthOrderCheck($result['status'] === 'unknown_hold',
            'invalid_result_accepted_' . $i);
        growthOrderCheck(strpos(json_encode($result), 'private@example.invalid') === false,
            'pii_must_not_escape_' . $i);
    }
}

$queries = 0;
$error = LocalOrderPresenceReader::inspect(1, 33,
    function ($sql) use (&$queries) {
        ++$queries;
        throw new RuntimeException('do not log database password');
    }, 'ps_test_');
growthOrderCheck($queries === 2 && $error['status'] === 'unknown_hold'
    && strpos(json_encode($error), 'password') === false,
    'failed_query_exception_leaked_or_interpreted_as_empty');

foreach ([[0,33],[-1,33],[1,0],[1,-1],['1',33],[1,'33'],[true,33]] as $scope) {
    growthOrderReject(function () use ($scope) {
        LocalOrderPresenceReader::inspect($scope[0], $scope[1],
            function () { return []; }, 'ps_test_');
    }, 'growth_order_scope_invalid');
}
foreach (['', 'a` OR 1=1', '../private', 'ps-test-', str_repeat('p',65), 'p;'] as $prefix) {
    growthOrderReject(function () use ($prefix) {
        LocalOrderPresenceReader::inspect(1,33,
            function () { return []; }, $prefix);
    }, 'growth_order_prefix_invalid');
}
growthOrderReject(function () {
    LocalOrderPresenceReader::inspect(1,33,'not_callable','ps_test_');
}, 'growth_order_reader_required');

$src = file_get_contents(dirname(__DIR__)
    . '/pssalesforcebridge/src/Growth/LocalOrderPresenceReader.php');
foreach (['curl_', 'file_get_contents(', 'requestAccessToken(',
    '/api/v1/ingest/', '/interaction/v1/events', '->execute(',
    '->insert(', '->update(', '->delete('] as $unsafe) {
    growthOrderCheck(strpos($src, $unsafe) === false,
        'reader_must_be_readonly_' . $unsafe);
}
$module = file_get_contents(dirname(__DIR__) . '/pssalesforcebridge/pssalesforcebridge.php');
growthOrderCheck(strpos($module, 'LocalOrderPresenceReader') === false,
    'new_reader_must_not_be_wired_into_legacy_production_071');

// Production adapter points to the normal PrestaShop Db API. Mock its
// response for executable SQL shape verification, not a live database.
if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_test_');
}
class Db
{
    public static $queries = [];
    public static function getInstance() { return new self(); }
    public function executeS($sql) {
        self::$queries[] = $sql;
        return strpos($sql, '`ps_test_orders`') !== false
            ? [['id_shop'=>'1','id_cart'=>'33','id_order'=>'777']] : [];
    }
}
$realAdapter = LocalOrderPresenceReader::inspectPrestaShop(1,33);
growthOrderCheck($realAdapter['status'] === 'converted_locally'
    && count(Db::$queries) === 2,
    'prestashop_db_adapter_did_not_query_scoped_sources');
echo "Growth local order presence reader: scoped SELECT-only, fail closed (PHP 7.1+).\n";
