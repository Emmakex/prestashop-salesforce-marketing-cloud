<?php
/** G7 native 0.7.1 cart source replay + existing 7-query fail-closed holds. */
require_once dirname(__DIR__).'/pssalesforcebridge/src/DataCloud/SourceTimestampNormalizer.php';
require_once dirname(__DIR__).'/pssalesforcebridge/src/DataCloud/ShopTimezoneResolver.php';
require_once dirname(__DIR__).'/pssalesforcebridge/src/Growth/GrowthLegacyCartSnapshotDigestReview.php';
require_once dirname(__DIR__).'/pssalesforcebridge/src/Growth/GrowthLegacyCartFreshNativeReview.php';
require_once dirname(__DIR__).'/pssalesforcebridge/src/Growth/GrowthLocalCartSnapshotReader.php';
require_once dirname(__DIR__).'/pssalesforcebridge/src/Growth/GrowthLocalCustomerHoldReader.php';
require_once dirname(__DIR__).'/pssalesforcebridge/src/Growth/LocalOrderPresenceReader.php';
require_once dirname(__DIR__).'/pssalesforcebridge/src/Growth/GrowthCartConversionHoldGate.php';
require_once dirname(__DIR__).'/pssalesforcebridge/src/Growth/GrowthCartLastLocalExclusionReview.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthLegacyCartFreshNativeReview as Fresh;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthCartConversionHoldGate as Gate;

function freshAssert($v,$why) {
    if (!$v) { fwrite(STDERR,"G7 fresh native FAIL: ".$why."\n"); exit(1); }
}
const CAPTURED_071_SHA1 = '651957cd3ca8e447ffca0b532ffe9cd1d6573cf2';
function freshCart() {
    return (object)[
        'id'=>93,'id_shop'=>2,'id_customer'=>17,'id_currency'=>1,
        'date_upd'=>'2026-11-16 11:30:00',
        'products'=>[[
            'id_product'=>12,'id_product_attribute'=>0,'id_customization'=>0,
            'cart_quantity'=>1,'price_wt'=>42.5,'total_wt'=>42.5,
            'reference'=>'SYNTH-SKU','name'=>'Synthetic product']],
        'total'=>42.5
    ];
}
function freshCustomer() {
    return (object)[
        'id'=>17,'id_shop'=>2,'email'=>'synthetic@example.invalid',
        'firstname'=>'Synthetic','lastname'=>'Buyer',
        'newsletter'=>1,'optin'=>1,'active'=>1,'deleted'=>0,'is_guest'=>0
    ];
}
function freshInspect($cart=null,$customer=null,$products=null,
    $hash=CAPTURED_071_SHA1,$zone='Europe/Madrid',$orders='none_asserted')
{
    $cart=$cart===null?freshCart():$cart;
    $customer=$customer===null?freshCustomer():$customer;
    $products=$products===null?$cart->products:$products;
    return Fresh::inspectObjects(2,93,17,$cart,$customer,$products,
        'EUR',$cart->total,$hash,$zone,$orders,60);
}
function freshSafe($report,$status) {
    freshAssert($report['status']==='hold_for_review'
        && $report['native_legacy_fresh_snapshot_state']===$status,
        'wrong_state_'.$status);
    foreach([
        'native_cart_read_scope_certified_for_bc',
        'legacy_writer_timezone_certified_for_bc',
        'legacy_snapshot_compared_to_live_db_certified',
        'legacy_snapshot_source_authenticity_certified',
        'growth_purpose_consent_certified',
        'final_purchase_and_optout_fence_certified',
        'data_cloud_ingestion_authorized',
        'recovery_link_creation_authorized',
        'marketing_sends_authorized'
    ] as $flag) {
        freshAssert($report[$flag]===false,'unsafe_'.$flag);
    }
    $json=json_encode($report);
    foreach([CAPTURED_071_SHA1,'synthetic@example.invalid','Synthetic',
        'SYNTH-SKU','Synthetic product','2026-11-16','42.5',
        '"id_customer"','"id_cart"','"id_shop"'] as $private) {
        freshAssert(strpos($json,$private)===false,'private_leak');
    }
}
freshSafe(freshInspect(),'native_071_recomputed_digest_match_review_only');
$c=freshCart();$c->products[0]['cart_quantity']=2;
freshSafe(freshInspect($c), 'native_071_recomputed_digest_changed_hold');
$c=freshCart();$c->products[0]['name']='Changed product';
freshSafe(freshInspect($c), 'native_071_recomputed_digest_changed_hold');
$c=freshCart();$c->products[0]['price_wt']=44.0;
freshSafe(freshInspect($c), 'native_071_recomputed_digest_changed_hold');
$c=freshCart();$c->total=44.0;
freshSafe(freshInspect($c), 'native_071_recomputed_digest_changed_hold');
$c=freshCart();$c->date_upd='2026-11-16 11:31:00';
freshSafe(freshInspect($c), 'native_071_recomputed_digest_changed_hold');
$c=freshCart();$c->id_shop=3;
freshSafe(freshInspect($c), 'native_source_scope_mismatch');
$c=freshCart();$c->id_customer=18;
freshSafe(freshInspect($c), 'native_source_scope_mismatch');
$cu=freshCustomer();$cu->id_shop=3;
freshSafe(freshInspect(null,$cu), 'native_source_scope_mismatch');
$cu=freshCustomer();$cu->newsletter=0;
freshSafe(freshInspect(null,$cu), 'native_071_recomputed_digest_changed_hold');
$cu=freshCustomer();$cu->optin=0;
freshSafe(freshInspect(null,$cu), 'native_071_recomputed_digest_changed_hold');
$cu=freshCustomer();$cu->email='changed@example.invalid';
freshSafe(freshInspect(null,$cu), 'native_071_recomputed_digest_changed_hold');
$c=freshCart();$c->date_upd='2026-10-25 02:30:00';
freshSafe(freshInspect($c), 'native_time_source_unverified');
$c=freshCart();$c->date_upd='2026-03-29 02:30:00';
freshSafe(freshInspect($c), 'native_time_source_unverified');
$c=freshCart();$c->date_upd='2026-02-30 11:30:00';
freshSafe(freshInspect($c), 'native_time_source_unverified');
freshSafe(freshInspect(null,null,[],CAPTURED_071_SHA1),
    'native_source_fields_unverified');
freshSafe(freshInspect(null,null,null,str_repeat('f',64)),
    'native_071_recomputed_digest_unverified');
freshSafe(freshInspect(null,null,null,CAPTURED_071_SHA1,'Europe/Madrid','unknown'),
    'native_order_state_unverified');
freshSafe(freshInspect(null,null,null,CAPTURED_071_SHA1,'Europe/Madrid','found'),
    'native_purchase_asserted_hold');

// Test the concrete PrestaShop 1.7/8/9 object/API adapter with local
// fixture classes; no production DB or genuine personal information.
if (!defined('_DB_PREFIX_')) define('_DB_PREFIX_','ps_ci_');
class Cart {
    const BOTH=3;
    public static $template;
    public $id,$id_shop,$id_customer,$id_currency,$date_upd,$products,$total;
    public function __construct($id) {
        $data=clone self::$template;
        foreach(get_object_vars($data) as $k=>$v) $this->$k=$v;
    }
    public function getProducts(){return $this->products;}
    public function getOrderTotal($tax,$type){return $this->total;}
}
class Customer {
    public static $template;
    public $id,$id_shop,$email,$firstname,$lastname,
        $newsletter,$optin,$active,$deleted,$is_guest;
    public function __construct($id) {
        $data=clone self::$template;
        foreach(get_object_vars($data) as $k=>$v) $this->$k=$v;
    }
}
class Currency {
    public static $iso='EUR';
    public $iso_code;
    public function __construct($id){$this->iso_code=self::$iso;}
}
class Configuration {
    public static $calls=[];
    public static function get($name,$lang=null,$group=null,$shop=null) {
        self::$calls[]=[$name,$lang,$group,$shop];
        return $name==='PS_TIMEZONE' && $shop===2?'Europe/Madrid':false;
    }
}
class Db {
    public static $calls=[];
    public static $lateOrder=false;
    public static $optout=false;
    public static $orders=0;
    public static $customers=0;
    public static $ledgerReads=0;
    public static $finalOrder=false;
    public static $finalOptout=false;
    public static $finalCustomerUnknown=false;
    public static $finalLedgerUnknown=false;
    public static function getInstance(){return new self();}
    public function executeS($sql,$array=true,$useCache=true) {
        self::$calls[]=[$sql,$array,$useCache];
        if(strpos($sql,'FROM `ps_ci_cart`')!==false)
            return [['id_cart'=>'93','id_shop'=>'2','id_customer'=>'17',
                'date_upd'=>'2026-11-16 11:30:00']];
        if(strpos($sql,'FROM `ps_ci_cart_product`')!==false)
            return [['id_cart'=>'93','id_shop'=>'2','quantity'=>'1']];
        if(strpos($sql,'FROM `ps_ci_customer`')!==false) {
            ++self::$customers;
            if(self::$finalCustomerUnknown && self::$customers===2) return false;
            $negative=self::$optout || (self::$finalOptout && self::$customers===2);
            return [['id_customer'=>'17','id_shop'=>'2','active'=>'1',
                'deleted'=>'0','is_guest'=>'0','newsletter'=>$negative?'0':'1',
                'optin'=>'1']];
        }
        if(strpos($sql,'FROM `ps_ci_orders`')!==false) {
            ++self::$orders;
            if((self::$lateOrder && self::$orders===2)
                || (self::$finalOrder && self::$orders===3))
                return [['id_cart'=>'93','id_shop'=>'2','id_order'=>'200']];
            return [];
        }
        if(strpos($sql,'FROM `ps_ci_sfmc_order_sync`')!==false) {
            ++self::$ledgerReads;
            if(self::$finalLedgerUnknown && self::$ledgerReads===3) return false;
            return [];
        }
        freshAssert(false,'unexpected_SQL');
    }
}
function freshSignals() {
    return [
        'provider'=>'marketing_cloud_growth','mode'=>'test',
        'installation_shop_scope'=>'verified','cart_status'=>'abandoned',
        'snapshot_current'=>true,'cart_has_products'=>true,
        'abandonment_window'=>'elapsed','current_customer_state'=>'active',
        'contact_matches_snapshot'=>true,'growth_consent_for_purpose'=>'verified',
        'growth_suppression'=>'clear','frequency_cap'=>'available'
    ];
}
Cart::$template=freshCart();Customer::$template=freshCustomer();
$native=Fresh::inspectPrestaShop(2,93,17,CAPTURED_071_SHA1,
    'Europe/Madrid','none_asserted',60);
freshSafe($native,'native_071_recomputed_digest_match_review_only');

Db::$calls=[];Db::$lateOrder=false;Db::$optout=false;Db::$orders=0;
$gate=Gate::inspectPrestaShopWithFreshLegacyCartReview(2,93,17,
    freshSignals(),CAPTURED_071_SHA1);
freshAssert($gate['status']==='hold_for_review'
    && $gate['fresh_legacy_snapshot_state']
        ==='native_071_recomputed_digest_match_review_only'
    && $gate['marketing_sends_authorized']===false
    && $gate['final_send_exclusion_fence_certified']===false
    && $gate['historical_capture_hash_authenticated']===false,
    'composed_gate_cannot_authorize');
freshAssert(count(Db::$calls)===7,'composed_seven_fresh_selects');
foreach(Db::$calls as $sql) freshAssert($sql[1]===true&&$sql[2]===false,
    'composed_SQL_cache_not_bypassed');
freshAssert(Configuration::$calls
    && end(Configuration::$calls)===['PS_TIMEZONE',null,null,2],
    'shop_timezone_scope_missing');

Db::$calls=[];Db::$orders=0;Db::$lateOrder=true;
$gate=Gate::inspectPrestaShopWithFreshLegacyCartReview(2,93,17,
    freshSignals(),CAPTURED_071_SHA1);
freshAssert(count(Db::$calls)===7
    && $gate['purchase_found_locally']===true
    && $gate['fresh_legacy_snapshot_state']==='native_purchase_asserted_hold'
    && $gate['marketing_sends_authorized']===false,
    'late_order_should_block');
Db::$lateOrder=false;Db::$calls=[];Db::$orders=0;Db::$optout=true;
$gate=Gate::inspectPrestaShopWithFreshLegacyCartReview(2,93,17,
    freshSignals(),CAPTURED_071_SHA1);
freshAssert(count(Db::$calls)===7
    && in_array('local_newsletter_negative',$gate['reasons'],true)
    && $gate['marketing_sends_authorized']===false,
    'local_customer_optout_should_block');
Db::$optout=false;Db::$calls=[];Db::$orders=0;
Cart::$template=freshCart();Cart::$template->id_shop=3;
$native=Fresh::inspectPrestaShop(2,93,17,CAPTURED_071_SHA1,
    'Europe/Madrid','none_asserted',60);
freshSafe($native,'native_cart_scope_unverified');
Cart::$template=freshCart();
$module=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/pssalesforcebridge.php');
freshAssert(strpos($module,'GrowthLegacyCartFreshNativeReview')===false
    && strpos($module,'inspectPrestaShopWithFreshLegacyCartReview')===false,
    'native_review_connected_to_live_module');
$code=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthLegacyCartFreshNativeReview.php');
foreach(['curl_','executeS(', 'Db::getInstance()', '->enqueue(',
    '->insert(', '->issueUrl(', '/api/v1/ingest/'] as $bad)
    freshAssert(strpos($code,$bad)===false,'native_review_side_effect');
echo "G7 fresh native 0.7.1 snapshot replay PASS: golden SHA1, drift, 7 uncached holds, late purchase, optout, no sends.\n";

// G7 last local exclusion check occurs AFTER the native SHA1 read.
// The ten SELECTs intentionally do NOT constitute marketing permission.
function resetLastLocalMocks() {
    Db::$calls=[];Db::$orders=0;Db::$customers=0;Db::$ledgerReads=0;
    Db::$lateOrder=false;Db::$optout=false;Db::$finalOrder=false;
    Db::$finalOptout=false;Db::$finalCustomerUnknown=false;
    Db::$finalLedgerUnknown=false;
    Cart::$template=freshCart();Customer::$template=freshCustomer();
}
function assertLastLocal($r,$status) {
    freshAssert($r['last_local_exclusion_state']===$status
        && $r['status']==='hold_for_review'
        && $r['last_local_check_count']===3
        && $r['last_local_customer_state']!==null
        && $r['final_send_exclusion_fence_certified']===false
        && $r['race_with_future_dispatch_closed']===false
        && $r['salesforce_send_time_consent_certified']===false
        && $r['recovery_link_creation_authorized']===false
        && $r['data_cloud_ingestion_authorized']===false
        && $r['marketing_sends_authorized']===false,
        'last_local_must_remain_blocked_'.$status);
    freshAssert(count(Db::$calls)===10,'last_local_ten_fresh_selects');
    foreach(Db::$calls as $c) {
        freshAssert($c[1]===true && $c[2]===false,
            'last_local_query_cache_must_be_bypassed');
    }
    freshAssert(
        strpos(Db::$calls[7][0],'FROM `ps_ci_customer`')!==false
        && strpos(Db::$calls[8][0],'FROM `ps_ci_orders`')!==false
        && strpos(Db::$calls[9][0],'FROM `ps_ci_sfmc_order_sync`')!==false,
        'last_recheck_sql_order_customer_then_native_order_then_ledger');
    $serialized=json_encode($r);
    foreach([CAPTURED_071_SHA1,'synthetic@example.invalid',
        'SYNTH-SKU','Synthetic product','2026-11-16 11:30:00']
        as $private) {
        freshAssert(strpos($serialized,$private)===false,
            'last_check_leaked_source_data');
    }
}
resetLastLocalMocks();
$final=Gate::inspectPrestaShopWithFreshLegacyCartFinalReview(
    2,93,17,freshSignals(),CAPTURED_071_SHA1);
assertLastLocal($final,'last_local_no_negative_observed_review_only');
freshAssert($final['fresh_legacy_snapshot_state']
    === 'native_071_recomputed_digest_match_review_only'
    && !$final['last_local_purchase_observed']
    && !$final['last_local_source_uncertain'],
    'no_negative_only_a_review_candidate');

resetLastLocalMocks();Db::$finalOrder=true;
$final=Gate::inspectPrestaShopWithFreshLegacyCartFinalReview(
    2,93,17,freshSignals(),CAPTURED_071_SHA1);
assertLastLocal($final,'purchase_found_in_last_local_review');
freshAssert($final['purchase_found_locally']===true
    && $final['last_local_purchase_observed']===true
    && in_array('purchase_found_in_last_local_review',$final['reasons'],true)
    && $final['fresh_legacy_snapshot_state']
        ==='native_071_recomputed_digest_match_review_only',
    'late_purchase_after_matching_snapshot_not_blocked');

resetLastLocalMocks();Db::$finalOptout=true;
$final=Gate::inspectPrestaShopWithFreshLegacyCartFinalReview(
    2,93,17,freshSignals(),CAPTURED_071_SHA1);
assertLastLocal($final,'last_local_customer_negative');
freshAssert(in_array('last_local_customer_negative',$final['reasons'],true)
    && $final['last_local_customer_state']==='known_negative',
    'late_optout_after_matching_snapshot_not_blocked');

resetLastLocalMocks();Db::$finalLedgerUnknown=true;
$final=Gate::inspectPrestaShopWithFreshLegacyCartFinalReview(
    2,93,17,freshSignals(),CAPTURED_071_SHA1);
assertLastLocal($final,'last_local_purchase_source_unverified');
freshAssert($final['last_local_source_uncertain']===true
    && $final['purchase_state_unknown']===true
    && in_array('last_local_purchase_source_unverified',$final['reasons'],true),
    'unknown_final_ledger_cannot_look_clear');

resetLastLocalMocks();Db::$finalCustomerUnknown=true;
$final=Gate::inspectPrestaShopWithFreshLegacyCartFinalReview(
    2,93,17,freshSignals(),CAPTURED_071_SHA1);
assertLastLocal($final,'last_local_customer_source_unverified');
freshAssert($final['last_local_source_uncertain']===true
    && $final['purchase_state_unknown']===false
    && $final['last_local_customer_state']==='unknown'
    && in_array('last_local_customer_source_unverified',$final['reasons'],true),
    'unknown_final_customer_must_block_without_falsifying_purchase_state');

resetLastLocalMocks();Db::$finalOrder=true;Db::$finalOptout=true;
$final=Gate::inspectPrestaShopWithFreshLegacyCartFinalReview(
    2,93,17,freshSignals(),CAPTURED_071_SHA1);
assertLastLocal($final,'purchase_found_in_last_local_review');
freshAssert($final['purchase_found_locally']===true
    && in_array('last_local_customer_negative',$final['reasons'],true)
    && in_array('purchase_found_in_last_local_review',$final['reasons'],true),
    'simultaneous_purchase_and_optout_must_preserve_both_reasons');

resetLastLocalMocks();
$invalidSignals=freshSignals();$invalidSignals['email']='must_not_access_source';
try {
    Gate::inspectPrestaShopWithFreshLegacyCartFinalReview(
        2,93,17,$invalidSignals,CAPTURED_071_SHA1);
    freshAssert(false,'unexpected_final_payload_accepted');
} catch (\RuntimeException $error) {
    freshAssert($error->getMessage()==='growth_cart_signal_shape_invalid'
        && count(Db::$calls)===0,'invalid_signals_reached_final_db');
}
freshAssert(strpos($module,'GrowthCartLastLocalExclusionReview')===false
    && strpos($module,'inspectPrestaShopWithFreshLegacyCartFinalReview')===false,
    'final_reader_must_not_be_wired_to_module');
echo "G7 last local exclusion PASS: 10 uncached SELECTs, third-pass purchase, late optout/unknown, no sends.\n";

