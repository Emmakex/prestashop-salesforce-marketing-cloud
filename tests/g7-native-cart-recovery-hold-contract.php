<?php
/** G7 native cart/product + customer + double-order hold: synthetic only. */
require_once dirname(__DIR__).'/pssalesforcebridge/src/Growth/GrowthLocalCartSnapshotReader.php';
require_once dirname(__DIR__).'/pssalesforcebridge/src/Growth/GrowthLocalCustomerHoldReader.php';
require_once dirname(__DIR__).'/pssalesforcebridge/src/Growth/LocalOrderPresenceReader.php';
require_once dirname(__DIR__).'/pssalesforcebridge/src/Growth/GrowthCartConversionHoldGate.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthLocalCartSnapshotReader as Cart;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthCartConversionHoldGate as Hold;

function g7Assert($ok,$why) {
    if (!$ok) { fwrite(STDERR,"G7 native cart FAIL: ".$why."\n"); exit(1); }
}
function g7Signals() {
    return [
        'provider'=>'marketing_cloud_growth','mode'=>'test',
        'installation_shop_scope'=>'verified',
        'cart_status'=>'abandoned','snapshot_current'=>true,
        'cart_has_products'=>true,'abandonment_window'=>'elapsed',
        'current_customer_state'=>'active','contact_matches_snapshot'=>true,
        'growth_consent_for_purpose'=>'verified','growth_suppression'=>'clear',
        'frequency_cap'=>'available'
    ];
}
function g7CartRow() {
    return ['id_cart'=>'93','id_shop'=>'2','id_customer'=>'17',
        'date_upd'=>'2026-11-16 11:50:00'];
}
function g7ProductRow() {
    return ['id_cart'=>'93','id_shop'=>'2','quantity'=>'2'];
}
function g7CustomerRow() {
    return ['id_customer'=>'17','id_shop'=>'2','active'=>'1',
        'deleted'=>'0','is_guest'=>'0','newsletter'=>'1','optin'=>'1'];
}
function g7Select($cart, $products, $customer, $nativeOrderPass=0, &$calls=null)
{
    $nativeCount=0;
    return function($sql)use($cart,$products,$customer,$nativeOrderPass,&$nativeCount,&$calls) {
        if (!is_array($calls)) $calls=[];
        $calls[]=$sql;
        // Never select or return addresses, email, totals, product names.
        foreach (['email','firstname','lastname','passwd','address',
            'secure_key','price','total_paid','id_product`'] as $forbidden) {
            g7Assert(stripos($sql,str_replace('`','',$forbidden))===false,
                'query_leaked_'.$forbidden);
        }
        if (strpos($sql,'FROM `ps_ci_cart`')!==false) {
            g7Assert($sql==='SELECT `id_cart`, `id_shop`, `id_customer`, `date_upd`'
                .' FROM `ps_ci_cart` WHERE `id_shop` = 2'
                .' AND `id_cart` = 93 LIMIT 2','cart_not_scoped');
            return $cart;
        }
        if (strpos($sql,'FROM `ps_ci_cart_product`')!==false) {
            g7Assert($sql==='SELECT `id_cart`, `id_shop`, `quantity`'
                .' FROM `ps_ci_cart_product` WHERE `id_shop` = 2'
                .' AND `id_cart` = 93 AND `quantity` > 0 LIMIT 1',
                'product_not_scoped');
            return $products;
        }
        if (strpos($sql,'FROM `ps_ci_customer`')!==false) {
            g7Assert(strpos($sql,'`id_customer` = 17 LIMIT 2')!==false,
                'customer_not_scoped');
            return $customer;
        }
        if (strpos($sql,'FROM `ps_ci_orders`')!==false) {
            g7Assert(strpos($sql,'`id_shop` = 2 AND `id_cart` = 93')!==false,
                'order_not_scoped');
            ++$nativeCount;
            if ($nativeCount===$nativeOrderPass) {
                return [['id_shop'=>'2','id_cart'=>'93','id_order'=>'1000']];
            }
            return [];
        }
        if (strpos($sql,'FROM `ps_ci_sfmc_order_sync`')!==false) {
            return [];
        }
        g7Assert(false,'unexpected_sql');
        return false;
    };
}
function g7Safe($report) {
    g7Assert($report['status']==='hold_for_review','not_hold');
    foreach(['marketing_sends_authorized','data_cloud_ingestion_authorized',
        'recovery_link_creation_authorized','race_with_future_dispatch_closed',
        'consent_certified_for_bc','native_cart_snapshot_verified_for_bc'] as $flag) {
        g7Assert(array_key_exists($flag,$report)&&$report[$flag]===false,
            'unsafe_'.$flag);
    }
    $body=json_encode($report);
    foreach(['2026-11-16','"id_cart"','"id_customer"','"id_shop"',
        'private@example.invalid','password'] as $private) {
        g7Assert(strpos($body,$private)===false,'leaked_private_source');
    }
}
$goodCalls=[];
$good=Hold::inspectWithNativeCart(2,93,17,
    g7Select([g7CartRow()],[g7ProductRow()],[g7CustomerRow()],0,$goodCalls),
    'ps_ci_',g7Signals());
g7Assert(count($goodCalls)===7,'expected_two_cart_one_customer_four_orders');
g7Assert($good['local_cart_source_state']==='cart_present_nonempty_review_only'
    && $good['local_cart_positive_product_observed']===true
    && $good['purchase_found_locally']===false
    && in_array('cart_snapshot_and_abandonment_window_unverified',$good['reasons'],true)
    && in_array('cart_not_abandoned',$good['reasons'],true)
    && in_array('purpose_consent_unverified',$good['reasons'],true),
    'caller_asserted_eligible_cannot_bypass');
g7Safe($good);

$convertedCalls=[];
$converted=Hold::inspectWithNativeCart(2,93,17,
    g7Select([g7CartRow()],[g7ProductRow()],[g7CustomerRow()],2,$convertedCalls),
    'ps_ci_',g7Signals());
g7Assert(count($convertedCalls)===7
    && $converted['purchase_found_locally']===true
    && in_array('purchase_found_in_local_source',$converted['reasons'],true),
    'late_purchase_not_blocked');
g7Safe($converted);

$negativeCustomer=g7CustomerRow();
$negativeCustomer['newsletter']='0';
$negCalls=[];
$negative=Hold::inspectWithNativeCart(2,93,17,
    g7Select([g7CartRow()],[g7ProductRow()],[$negativeCustomer],0,$negCalls),
    'ps_ci_',g7Signals());
g7Assert(in_array('local_newsletter_negative',$negative['reasons'],true)
    && count($negCalls)===7,'local_opt_out_overridden'); g7Safe($negative);

foreach([
    ['cart missing',[],[g7ProductRow()],'cart_missing','local_cart_not_found',1],
    ['wrong shop',array_merge(g7CartRow(),['id_shop'=>'3']),[g7ProductRow()],
        'cart_source_unverified','local_cart_scope_mismatch',1],
    ['wrong customer',array_merge(g7CartRow(),['id_customer'=>'18']),[g7ProductRow()],
        'cart_source_unverified','local_cart_scope_mismatch',1],
    ['invalid timestamp',array_merge(g7CartRow(),['date_upd'=>'2026-02-30 10:00:00']),
        [g7ProductRow()],'cart_source_unverified','local_cart_timestamp_invalid',1],
    ['empty products',[g7CartRow()],[],'cart_present_empty',
        'local_cart_has_no_positive_products',2],
    ['wrong product shop',[g7CartRow()],
        [array_merge(g7ProductRow(),['id_shop'=>'3'])],
        'cart_source_unverified','local_cart_product_scope_or_quantity_invalid',2],
    ['malformed quantity',[g7CartRow()],
        [array_merge(g7ProductRow(),['quantity'=>'0'])],
        'cart_source_unverified','local_cart_product_scope_or_quantity_invalid',2],
    ['unknown columns',[array_merge(g7CartRow(),['email'=>'do_not_read'])],
        [g7ProductRow()],'cart_source_unverified','local_cart_columns_invalid',1],
] as $case) {
    $calls=[];
    $cart=is_array($case[1])&&isset($case[1]['id_cart'])?[$case[1]]:$case[1];
    $report=Hold::inspectWithNativeCart(2,93,17,
        g7Select($cart,$case[2],[g7CustomerRow()],0,$calls),
        'ps_ci_',g7Signals());
    g7Assert($report['local_cart_source_state']===$case[3]
        && in_array($case[4],$report['reasons'],true)
        && count($calls)===$case[5]+5,'case_failed_'.$case[0]);
    g7Safe($report);
}
$malformedSignals=g7Signals();
$malformedSignals['email']='private@example.invalid';
$reads=0;
try {
    Hold::inspectWithNativeCart(2,93,17,
        function()use(&$reads){++$reads;return [];},'ps_ci_',$malformedSignals);
    g7Assert(false,'bad_assertions_accepted');
} catch(RuntimeException $e) {
    g7Assert($e->getMessage()==='growth_cart_signal_shape_invalid'
        && $reads===0,'invalid_signals_reached_db');
}
foreach([0,-1,'2',true] as $invalidShop) {
    try {
        Cart::inspect($invalidShop,93,17,function(){return [];},'ps_ci_');
        g7Assert(false,'invalid_shop_accepted');
    } catch(RuntimeException $e) {
        g7Assert($e->getMessage()==='growth_cart_snapshot_scope_invalid',
            'bad_shop_exception');
    }
}
// Verify all SEVEN native SELECTs use uncached Db.executeS.
if (!defined('_DB_PREFIX_')) define('_DB_PREFIX_','ps_ci_');
class Db {
    public static $calls=[];
    public static function getInstance(){return new self();}
    public function executeS($sql,$array=true,$useCache=true){
        self::$calls[]=[$sql,$array,$useCache];
        static $orders=0;
        if (strpos($sql,'FROM `ps_ci_cart`')!==false) return [g7CartRow()];
        if (strpos($sql,'FROM `ps_ci_cart_product`')!==false) return [g7ProductRow()];
        if (strpos($sql,'FROM `ps_ci_customer`')!==false) return [g7CustomerRow()];
        if (strpos($sql,'FROM `ps_ci_orders`')!==false) {++$orders;return [];}
        if (strpos($sql,'FROM `ps_ci_sfmc_order_sync`')!==false) return [];
        return false;
    }
}
$native=Hold::inspectPrestaShopWithNativeCart(2,93,17,g7Signals());
g7Assert(count(Db::$calls)===7,'native_query_count');
foreach(Db::$calls as $record) {
    g7Assert($record[1]===true && $record[2]===false,
        'native_query_must_bypass_prestashop_cache');
}
g7Safe($native);
$module=file_get_contents(dirname(__DIR__).'/pssalesforcebridge/pssalesforcebridge.php');
g7Assert(strpos($module,'GrowthLocalCartSnapshotReader')===false,
    'new_snapshot_reader_connected_to_module');
$source=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthLocalCartSnapshotReader.php');
foreach(['curl_','->insert(', '->execute(', '/api/v1/ingest/',
    '->issueUrl(', '->enqueue('] as $unsafe) {
    g7Assert(strpos($source,$unsafe)===false,'unsafe_side_effect_'.$unsafe);
}
echo "G7 native scoped cart + product + customer + 2x order pass: 7 uncached SELECTs, all holds.\n";
