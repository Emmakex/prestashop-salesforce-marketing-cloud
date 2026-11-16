<?php
/** G7 offline 60-minute review: DST, timezone, freshness, no sends. */
require_once dirname(__DIR__).'/pssalesforcebridge/src/DataCloud/SourceTimestampNormalizer.php';
require_once dirname(__DIR__).'/pssalesforcebridge/src/DataCloud/ShopTimezoneResolver.php';
require_once dirname(__DIR__).'/pssalesforcebridge/src/Growth/GrowthCartInactivityWindowReview.php';
require_once dirname(__DIR__).'/pssalesforcebridge/src/Growth/GrowthLocalCartSnapshotReader.php';
require_once dirname(__DIR__).'/pssalesforcebridge/src/Growth/GrowthLocalCustomerHoldReader.php';
require_once dirname(__DIR__).'/pssalesforcebridge/src/Growth/LocalOrderPresenceReader.php';
require_once dirname(__DIR__).'/pssalesforcebridge/src/Growth/GrowthCartConversionHoldGate.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthCartInactivityWindowReview as Window;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthCartConversionHoldGate as Hold;

function g7WindowAssert($yes,$description) {
    if (!$yes) { fwrite(STDERR,"G7 window FAIL: ".$description."\n"); exit(1); }
}
function g7WindowReview($local,$zone,$now,$proof,$expected) {
    $r=Window::inspect($local,$zone,$now,$proof);
    g7WindowAssert($r['status']==='hold_for_review'
        && $r['inactivity_window_state']===$expected,'state_'.$expected);
    g7WindowAssert($r['minimum_seconds']===3600,'threshold_not_60m');
    foreach(['source_write_path_certified_for_bc',
        'as_of_utc_clock_certified_for_bc','cart_snapshot_equivalence_certified',
        'abandoned_cart_confirmed_for_bc','race_with_checkout_and_optout_closed',
        'growth_purpose_consent_certified','recovery_link_creation_authorized',
        'data_cloud_ingestion_authorized','marketing_sends_authorized'] as $key) {
        g7WindowAssert($r[$key]===false,'illegal_flag_'.$key);
    }
    foreach([$local,$now,$zone,'source_shop_id','customer@example.invalid'] as $private) {
        g7WindowAssert(strpos(json_encode($r),$private)===false,
            'raw_timestamp_or_private_data_leaked');
    }
    return $r;
}
$proof='shop_local_write_path_asserted';
g7WindowReview('2026-11-16 11:30:01','Europe/Madrid',
    '2026-11-16T11:30:00Z',$proof,'under_60_minutes'); // 59m59s
g7WindowReview('2026-11-16 11:30:00','Europe/Madrid',
    '2026-11-16T11:30:00Z',$proof,
    'elapsed_60m_candidate_under_asserted_provenance'); // 3600s
g7WindowReview('2026-11-16 11:29:59','Europe/Madrid',
    '2026-11-16T11:30:00Z',$proof,
    'elapsed_60m_candidate_under_asserted_provenance'); // 3601s
g7WindowReview('2026-07-16 11:30:00','Europe/Madrid',
    '2026-07-16T10:30:00Z',$proof,
    'elapsed_60m_candidate_under_asserted_provenance'); // CEST +2
g7WindowReview('2026-11-16 14:00:00','Europe/Madrid',
    '2026-11-16T11:30:00Z',$proof,'source_clock_in_future');
g7WindowReview('2026-03-29 02:30:00','Europe/Madrid',
    '2026-03-29T04:00:00Z',$proof,'dst_gap_nonexistent');
g7WindowReview('2026-10-25 02:30:00','Europe/Madrid',
    '2026-10-25T04:00:00Z',$proof,'dst_overlap_ambiguous');
g7WindowReview('2026-02-30 12:30:00','Europe/Madrid',
    '2026-11-16T11:30:00Z',$proof,'invalid_source_calendar');
g7WindowReview('2026-11-16T12:30:00Z','Europe/Madrid',
    '2026-11-16T11:30:00Z',$proof,'invalid_source_format');
g7WindowReview('2026-11-16 12:30:00','CET',
    '2026-11-16T11:30:00Z',$proof,'shop_timezone_unknown');
g7WindowReview('2026-11-16 11:30:00','Europe/Madrid',
    '2026-11-16T12:30:00Z','unverified',
    'write_path_provenance_unknown');
try {
    Window::inspect('2026-11-16 11:30:00','Europe/Madrid',
        '2026-11-16 11:30:00',$proof);
    g7WindowAssert(false,'invalid_now_accepted');
} catch (RuntimeException $e) {
    g7WindowAssert($e->getMessage()==='growth_cart_window_now_utc_invalid',
        'wrong_now_error');
}
try {
    Window::inspect('2026-11-16 11:30:00','Europe/Madrid',
        '2026-11-16T12:30:00Z','pretend_production_verified');
    g7WindowAssert(false,'invalid_provenance_accepted');
} catch (RuntimeException $e) {
    g7WindowAssert($e->getMessage()==='growth_cart_window_evidence_invalid',
        'wrong_provenance_error');
}
function g7WindowSignals() {
    return [
        'provider'=>'marketing_cloud_growth','mode'=>'test',
        'installation_shop_scope'=>'verified','cart_status'=>'abandoned',
        'snapshot_current'=>true,'cart_has_products'=>true,
        'abandonment_window'=>'elapsed','current_customer_state'=>'active',
        'contact_matches_snapshot'=>true,'growth_consent_for_purpose'=>'verified',
        'growth_suppression'=>'clear','frequency_cap'=>'available',
    ];
}
function g7WindowSqlReader(&$calls,$local) {
    return function($sql) use (&$calls,$local) {
        $calls[]=$sql;
        if (strpos($sql,'FROM `ps_ci_cart`')!==false) {
            return [['id_cart'=>'93','id_shop'=>'2',
                'id_customer'=>'17','date_upd'=>$local]];
        }
        if (strpos($sql,'FROM `ps_ci_cart_product`')!==false) {
            return [['id_cart'=>'93','id_shop'=>'2','quantity'=>'1']];
        }
        if (strpos($sql,'FROM `ps_ci_customer`')!==false) {
            return [['id_customer'=>'17','id_shop'=>'2','active'=>'1',
                'deleted'=>'0','is_guest'=>'0','newsletter'=>'1','optin'=>'1']];
        }
        if (strpos($sql,'FROM `ps_ci_orders`')!==false) {
            return [];
        }
        if (strpos($sql,'FROM `ps_ci_sfmc_order_sync`')!==false) {
            return [];
        }
        g7WindowAssert(false,'unexpected_SQL');
        return false;
    };
}
function g7WindowResultSafe($result,$expected) {
    g7WindowAssert($result['status']==='hold_for_review'
        && $result['local_cart_inactivity_window_state']===$expected
        && $result['inactivity_window_certified_for_bc']===false
        && $result['race_with_future_dispatch_closed']===false
        && $result['marketing_sends_authorized']===false,
        'window_claim_elevated_to_permission');
    g7WindowAssert(in_array('abandonment_window_unverified',
        $result['reasons'],true),'unapproved_external_elapsed_accepted');
}
$calls=[];
$review=Hold::inspectWithNativeCartWindow(
    2,93,17,g7WindowSqlReader($calls,'2026-11-16 11:30:00'),
    'ps_ci_',g7WindowSignals(),'Europe/Madrid',
    '2026-11-16T11:30:00Z',$proof
);
g7WindowAssert(count($calls)===7,'seven_fresh_local_selects_not_reused');
g7WindowResultSafe($review,'elapsed_60m_candidate_under_asserted_provenance');
$calls=[];
$review=Hold::inspectWithNativeCartWindow(
    2,93,17,g7WindowSqlReader($calls,'2026-10-25 02:30:00'),
    'ps_ci_',g7WindowSignals(),'Europe/Madrid',
    '2026-10-25T04:00:00Z',$proof
);
g7WindowAssert(count($calls)===7,'dst_kept_full_safety_reads');
g7WindowResultSafe($review,'dst_overlap_ambiguous');

$calls=[];
$review=Hold::inspectWithNativeCartWindow(
    2,93,17,g7WindowSqlReader($calls,'2026-11-16 11:30:00'),
    'ps_ci_',g7WindowSignals(),'Europe/Madrid',
    '2026-11-16T11:30:00Z','unverified'
);
g7WindowResultSafe($review,'write_path_provenance_unknown');

// Native bridge: Configuration->shop PS_TIMEZONE + exactly 7 uncached SELECT.
if (!defined('_DB_PREFIX_')) define('_DB_PREFIX_','ps_ci_');
class Configuration {
    public static $calls=[];
    public static function get($key,$lang=null,$group=null,$shop=null) {
        self::$calls[]=[$key,$lang,$group,$shop];
        return $key==='PS_TIMEZONE'&&$shop===2 ? 'Europe/Madrid':false;
    }
}
class Db {
    public static $calls=[];
    public static function getInstance(){return new self();}
    public function executeS($sql,$array=true,$useCache=true) {
        self::$calls[]=[$sql,$array,$useCache];
        $dummy=[];
        $reader=g7WindowSqlReader($dummy,'2026-11-16 11:30:00');
        return $reader($sql);
    }
}
$native=Hold::inspectPrestaShopWithNativeCartWindow(
    2,93,17,g7WindowSignals(),
    '2026-11-16T11:30:00Z',$proof
);
g7WindowResultSafe($native,'elapsed_60m_candidate_under_asserted_provenance');
g7WindowAssert(count(Configuration::$calls)===1
    && Configuration::$calls[0]===['PS_TIMEZONE',null,null,2],
    'shop_timezone_not_scoped');
g7WindowAssert(count(Db::$calls)===7,'native_query_count');
foreach(Db::$calls as $call) {
    g7WindowAssert($call[1]===true&&$call[2]===false,'native_cached_query');
}
$module=file_get_contents(dirname(__DIR__).'/pssalesforcebridge/pssalesforcebridge.php');
g7WindowAssert(strpos($module,'GrowthCartInactivityWindowReview')===false,
    'new_review_wired_to_hooks');
echo "G7 60-minute DST-aware inactivity review PASS: 59m59/60m, gaps/overlaps, 7 fresh SELECTs, no sends.\n";
