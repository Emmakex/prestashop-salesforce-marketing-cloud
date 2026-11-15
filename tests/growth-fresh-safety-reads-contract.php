<?php
/**
 * G6/G7 uncached SELECT safety: exercise actual PrestaShop adapter methods
 * with synthetic, deliberately stale Db query-cache fixtures.
 *
 * No real PrestaShop, secrets, writes, records, hooks or marketing sends.
 */
require_once dirname(__DIR__) . '/pssalesforcebridge/src/Growth/LocalOrderPresenceReader.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/Growth/GrowthLocalCustomerHoldReader.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/Growth/GrowthCartConversionHoldGate.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\LocalOrderPresenceReader as OrderReader;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthLocalCustomerHoldReader as CustomerReader;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthCartConversionHoldGate as CartGate;

function freshAssert($ok, $why)
{
    if (!$ok) {
        fwrite(STDERR, 'Growth uncached safety FAIL: ' . $why . PHP_EOL);
        exit(1);
    }
}
if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_ci_');
}
/** Emulates Db::executeS query-cache contract for PS 1.7/8/9. */
class Db
{
    public static $cache = [];
    public static $calls = [];
    public static $nativeCall = 0;
    public static $nativeFirst = [];
    public static $nativeSecond = [];
    public static $ledger = [];
    public static $customer = [];
    public static $errors = [];

    public static function getInstance() { return new self(); }

    public function executeS($sql, $array = true, $useCache = true)
    {
        freshAssert($array === true, 'should_return_array');
        self::$calls[] = [$sql, $array, $useCache];
        // This represents the production hazard: when the caller leaves
        // useCache=true, an older no-order or newsletter=1 row is returned.
        if ($useCache && array_key_exists($sql, self::$cache)) {
            return self::$cache[$sql];
        }
        if (strpos($sql, 'FROM `ps_ci_orders`') !== false) {
            ++self::$nativeCall;
            $result = self::$nativeCall === 1
                ? self::$nativeFirst : self::$nativeSecond;
        } elseif (strpos($sql, 'FROM `ps_ci_sfmc_order_sync`') !== false) {
            $result = self::$ledger;
        } elseif (strpos($sql, 'FROM `ps_ci_customer`') !== false) {
            $result = self::$customer;
        } else {
            throw new RuntimeException('unexpected_query');
        }
        if ($useCache) {
            self::$cache[$sql] = $result;
        }
        return $result;
    }
}

function freshReset()
{
    Db::$cache = [];
    Db::$calls = [];
    Db::$nativeCall = 0;
    Db::$nativeFirst = [];
    Db::$nativeSecond = [];
    Db::$ledger = [];
    Db::$errors = [];
    Db::$customer = [
        'id_customer' => '17',
        'id_shop' => '2',
        'active' => '1',
        'deleted' => '0',
        'is_guest' => '0',
        'newsletter' => '1',
        'optin' => '1',
    ];
}
function freshCheckCalls($count)
{
    freshAssert(count(Db::$calls) === $count,
        'unexpected_query_count_' . count(Db::$calls));
    foreach (Db::$calls as $call) {
        freshAssert($call[2] === false && $call[1] === true,
            'cached_safety_read');
        $sql = $call[0];
        freshAssert(strpos($sql, 'SELECT ') === 0
            && strpos($sql, ' WHERE `id_shop` = 2 AND ') !== false
            && strpos($sql, ' LIMIT ') !== false,
            'unscoped_or_nonselect_sql');
        foreach (['email', 'firstname', 'lastname', 'passwd', 'INSERT',
            'UPDATE', 'DELETE', 'SELECT *'] as $bad) {
            freshAssert(stripos($sql, $bad) === false,
                'private_data_or_write_' . $bad);
        }
    }
}
function freshSignals()
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
function freshSafeguards($out)
{
    freshAssert($out['marketing_sends_authorized'] === false
        && $out['data_cloud_ingestion_authorized'] === false,
        'unapproved_dispatch');
    $dump = json_encode($out);
    foreach (['private@example.invalid', 'secret', '777',
        'id_customer', 'customer_id', 'source_namespace'] as $s) {
        freshAssert(strpos($dump, $s) === false, 'pii_or_id_leaked');
    }
}

freshReset();
$nativeSql = 'SELECT `id_shop`, `id_cart`, `id_order`'
    . ' FROM `ps_ci_orders` WHERE `id_shop` = 2 AND `id_cart` = 93'
    . ' ORDER BY `id_order` ASC LIMIT 1';
$customerSql = 'SELECT `id_customer`, `id_shop`, `active`, `deleted`,'
    . ' `is_guest`, `newsletter`, `optin` FROM `ps_ci_customer`'
    . ' WHERE `id_shop` = 2 AND `id_customer` = 17 LIMIT 2';
$found = ['id_shop'=>'2','id_cart'=>'93','id_order'=>'777'];

// Cached previous absence MUST NOT hide an actual native purchase.
Db::$cache[$nativeSql] = [];
Db::$nativeFirst = [$found];
Db::$nativeSecond = [$found];
$order = OrderReader::inspectPrestaShop(2, 93);
freshAssert($order['status'] === 'converted_locally'
    && $order['native_order_state'] === 'found'
    && Db::$nativeCall === 1, 'cached_absence_hid_purchase');
freshSafeguards($order);
freshCheckCalls(2);

// Cached previous optin=1 MUST NOT hide a live unsubscribe.
freshReset();
$previous = Db::$customer;
Db::$cache[$customerSql] = [$previous];
Db::$customer['optin'] = '0';
Db::$customer['newsletter'] = '0';
$customer = CustomerReader::inspectPrestaShop(2,17);
freshAssert($customer['status'] === 'hold_for_review'
    && $customer['local_customer_source_state'] === 'known_negative'
    && in_array('local_optin_negative',$customer['reasons'],true)
    && in_array('local_newsletter_negative',$customer['reasons'],true),
    'cached_positive_flags_hid_unsubscribe');
freshSafeguards($customer);
freshCheckCalls(1);

// Even with two uncached reads, a purchase can appear BETWEEN them.
freshReset();
Db::$cache[$nativeSql] = [];
Db::$cache[$customerSql] = [Db::$customer];
Db::$nativeFirst = [];
Db::$nativeSecond = [$found];
$out = CartGate::inspectPrestaShopWithLocalCustomer(
    2, 93, 17, freshSignals()
);
freshAssert(Db::$nativeCall === 2 && $out['status'] === 'hold_for_review'
    && $out['purchase_found_locally'] === true
    && $out['native_order_states'] === ['none','found']
    && in_array('purchase_found_in_local_source', $out['reasons'], true)
    && in_array('purpose_consent_unverified', $out['reasons'], true)
    && $out['race_with_future_dispatch_closed'] === false
    && $out['growth_purpose_consent_certified'] === false,
    'second_uncached_read_did_not_detect_late_purchase');
freshSafeguards($out);
freshCheckCalls(5);

// If any source is missing or corrupt the result must stay blocked and never
// rely on stale cached evidence.
freshReset();
Db::$nativeFirst = [];
Db::$nativeSecond = [];
Db::$ledger = false;
Db::$customer = [];
$unknown = CartGate::inspectPrestaShopWithLocalCustomer(
    2, 93, 17, freshSignals()
);
freshAssert($unknown['status'] === 'hold_for_review'
    && $unknown['purchase_state_unknown'] === true
    && in_array('local_customer_not_found', $unknown['reasons'], true)
    && in_array('purchase_state_unverified', $unknown['reasons'], true),
    'unknown_source_was_treated_as_clear');
freshSafeguards($unknown);
freshCheckCalls(5);

// Validate externally asserted shapes BEFORE reaching PrestaShop Db API.
freshReset();
$bad = freshSignals();
$bad['mode'] = 'production';
try {
    CartGate::inspectPrestaShopWithLocalCustomer(2,93,17,$bad);
    freshAssert(false,'invalid_mode_accepted');
} catch (RuntimeException $ex) {
    freshAssert($ex->getMessage() === 'growth_cart_signal_value_invalid',
        'unexpected_validation_error');
}
freshAssert(count(Db::$calls) === 0, 'invalid_mode_reached_database');

$module = file_get_contents(dirname(__DIR__)
    . '/pssalesforcebridge/pssalesforcebridge.php');
freshAssert(strpos($module, 'inspectPrestaShopWithLocalCustomer') === false
    && strpos($module, 'GrowthCartConversionHoldGate') === false,
    'unapproved_pre_send_checks_wired_to_live_module');
echo "Growth native PrestaShop safety: uncached 2x purchase + opt-out reads PASS; no sends.\n";
