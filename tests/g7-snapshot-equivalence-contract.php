<?php
/** G7 snapshot comparison contract: exact scopes, no PII and no dispatch. */
require_once dirname(__DIR__).'/pssalesforcebridge/src/Growth/GrowthCartSnapshotEquivalenceReview.php';
require_once dirname(__DIR__).'/pssalesforcebridge/src/Growth/GrowthLocalCartSnapshotReader.php';
require_once dirname(__DIR__).'/pssalesforcebridge/src/Growth/GrowthLocalCustomerHoldReader.php';
require_once dirname(__DIR__).'/pssalesforcebridge/src/Growth/LocalOrderPresenceReader.php';
require_once dirname(__DIR__).'/pssalesforcebridge/src/Growth/GrowthCartConversionHoldGate.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthCartSnapshotEquivalenceReview as Snapshot;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthCartConversionHoldGate as Gate;

function snapAssert($yes,$why) {
    if (!$yes) { fwrite(STDERR,"G7 snapshot FAIL: ".$why."\n"); exit(1); }
}
function snap($origin,$digest=null) {
    return [
        'id_shop'=>2, 'id_cart'=>93, 'id_customer'=>17,
        'origin'=>$origin, 'algorithm'=>'sha1',
        'digest'=>$digest === null ? hash('sha1','captured-cart-fixture-1') : $digest,
    ];
}
function snapSafety($out,$expected) {
    snapAssert($out['status']==='hold_for_review'
        && $out['snapshot_equivalence_state']===$expected,'bad_state_'.$expected);
    foreach(['full_native_cart_snapshot_equivalence_certified',
        'source_cart_activity_certified','race_with_future_dispatch_closed',
        'growth_purpose_consent_certified','marketing_sends_authorized',
        'data_cloud_ingestion_authorized','recovery_link_creation_authorized'] as $key) {
        if (array_key_exists($key,$out)) {
            snapAssert($out[$key]===false,'unapproved_'.$key);
        }
    }
    $json=json_encode($out);
    foreach(['captured-cart-fixture','customer@example.invalid',
        '"id_cart"','"id_customer"','"id_shop"',hash('sha1','captured-cart-fixture-1')]
        as $source) {
        snapAssert(strpos($json,$source)===false,'source_hash_or_identity_leak');
    }
}
$original=snap('captured_snapshot');
$fresh=snap('fresh_recalculation');
$match=Snapshot::inspect(2,93,17,$original,$fresh);
snapSafety($match,'snapshot_digest_match_injected_only');
snapAssert($match['legacy_071_sha1_format_observed']===true,
    'legacy_sha1_format_not_certified');
snapAssert($match['injected_digest_match_observed']===true,
    'same_digest_not_observed');
$changed=snap('fresh_recalculation',hash('sha1','changed-cart-fixture-2'));
$comparison=Snapshot::inspect(2,93,17,$original,$changed);
snapSafety($comparison,'snapshot_digest_changed_hold');
snapAssert($comparison['injected_digest_match_observed']===false,'changed_digest_passed');

foreach([
    ['capture_wrong_shop',array_merge($original,['id_shop'=>3]),$fresh,'captured_snapshot_unverified'],
    ['fresh_wrong_shop',$original,array_merge($fresh,['id_shop'=>3]),'fresh_snapshot_unverified'],
    ['capture_wrong_customer',array_merge($original,['id_customer'=>18]),$fresh,'captured_snapshot_unverified'],
    ['fresh_wrong_cart',$original,array_merge($fresh,['id_cart'=>94]),'fresh_snapshot_unverified'],
    ['origin_swapped',$fresh,$original,'captured_snapshot_unverified'],
    ['unknown_algorithm',$original,array_merge($fresh,['algorithm'=>'sha512']),'fresh_snapshot_unverified'],
    ['sha256_not_legacy',$original,array_merge($fresh,['algorithm'=>'sha256','digest'=>hash('sha256','captured-cart-fixture-1')]),'fresh_snapshot_unverified'],
    ['invalid_digest',$original,array_merge($fresh,['digest'=>'abcd']),'fresh_snapshot_unverified'],
    ['placeholder_digest',$original,array_merge($fresh,['digest'=>str_repeat('0',40)]),'fresh_snapshot_unverified'],
    ['extra_email',$original,array_merge($fresh,['email'=>'customer@example.invalid']),'fresh_snapshot_unverified'],
    ['missing_hash',$original,array_diff_key($fresh,['digest'=>true]),'fresh_snapshot_unverified'],
    ['wrong_type',$original,array_merge($fresh,['id_shop'=>'2']),'fresh_snapshot_unverified'],
    ['nonarray',$original,false,'fresh_snapshot_unverified'],
] as $case) {
    $result=Snapshot::inspect(2,93,17,$case[1],$case[2]);
    snapSafety($result,$case[3]);
    snapAssert($result['injected_digest_match_observed']===false,
        'invalid_snapshot_looked_matched_'.$case[0]);
}
foreach([[0,93,17],[2,-1,17],[2,93,'17']] as $ids) {
    try {
        Snapshot::inspect($ids[0],$ids[1],$ids[2],$original,$fresh);
        snapAssert(false,'invalid_scope_accepted');
    } catch (RuntimeException $error) {
        snapAssert($error->getMessage()==='growth_snapshot_scope_invalid',
            'wrong_scope_error');
    }
}
function snapSignals() {
    return [
        'provider'=>'marketing_cloud_growth','mode'=>'test',
        'installation_shop_scope'=>'verified','cart_status'=>'abandoned',
        'snapshot_current'=>true,'cart_has_products'=>true,
        'abandonment_window'=>'elapsed','current_customer_state'=>'active',
        'contact_matches_snapshot'=>true,'growth_consent_for_purpose'=>'verified',
        'growth_suppression'=>'clear','frequency_cap'=>'available'
    ];
}
function snapRows(&$calls,$latePurchase=false,$optout=false) {
    $nativeReads=0;
    return function($sql)use(&$calls,&$nativeReads,$latePurchase,$optout) {
        $calls[]=$sql;
        if (strpos($sql,'FROM `ps_ci_cart`')!==false)
            return [['id_shop'=>'2','id_cart'=>'93','id_customer'=>'17',
                'date_upd'=>'2026-11-16 11:30:00']];
        if (strpos($sql,'FROM `ps_ci_cart_product`')!==false)
            return [['id_shop'=>'2','id_cart'=>'93','quantity'=>'1']];
        if (strpos($sql,'FROM `ps_ci_customer`')!==false)
            return [['id_shop'=>'2','id_customer'=>'17','active'=>'1',
                'deleted'=>'0','is_guest'=>'0','newsletter'=>$optout?'0':'1',
                'optin'=>'1']];
        if (strpos($sql,'FROM `ps_ci_orders`')!==false) {
            ++$nativeReads;
            if($latePurchase&&$nativeReads===2)
                return [['id_shop'=>'2','id_cart'=>'93','id_order'=>'200']];
            return [];
        }
        if (strpos($sql,'FROM `ps_ci_sfmc_order_sync`')!==false)
            return [];
        snapAssert(false,'unexpected_sql');
        return false;
    };
}
$queries=[];
$report=Gate::inspectWithNativeCartSnapshotReview(2,93,17,
    snapRows($queries), 'ps_ci_',snapSignals(),$original,$fresh);
snapAssert(count($queries)===7,'must_reuse_seven_sql_reads');
snapSafety($report,'snapshot_digest_match_injected_only');
snapAssert($report['snapshot_digest_match_injected_only']===true
    && $report['full_native_cart_snapshot_equivalence_certified']===false
    && $report['final_send_exclusion_fence_certified']===false
    && in_array('cart_snapshot_unverified',$report['reasons'],true),
    'injected_digest_must_never_override_snapshot_hold');

$queries=[];
$report=Gate::inspectWithNativeCartSnapshotReview(2,93,17,
    snapRows($queries,true), 'ps_ci_',snapSignals(),$original,$fresh);
snapSafety($report,'snapshot_digest_match_injected_only');
snapAssert(count($queries)===7
    && $report['purchase_found_locally']===true
    && in_array('purchase_found_in_local_source',$report['reasons'],true),
    'late_purchase_not_blocked');

$queries=[];
$report=Gate::inspectWithNativeCartSnapshotReview(2,93,17,
    snapRows($queries,false,true), 'ps_ci_',snapSignals(),$original,$fresh);
snapSafety($report,'snapshot_digest_match_injected_only');
snapAssert(count($queries)===7
    && in_array('local_newsletter_negative',$report['reasons'],true),
    'optout_not_blocked');

$queries=[];
$report=Gate::inspectWithNativeCartSnapshotReview(2,93,17,
    snapRows($queries), 'ps_ci_',snapSignals(),$original,$changed);
snapSafety($report,'snapshot_digest_changed_hold');
snapAssert(count($queries)===7 && $report['snapshot_digest_match_injected_only']===false,
    'changed_snapshot_not_blocked');
$invalidSignals=snapSignals();$invalidSignals['email']='untrusted@example.invalid';
$reads=0;
try {
    Gate::inspectWithNativeCartSnapshotReview(2,93,17,
        function()use(&$reads){++$reads; return [];},
        'ps_ci_',$invalidSignals,$original,$fresh);
    snapAssert(false,'unexpected_extra_signal_accepted');
} catch (RuntimeException $error) {
    snapAssert($error->getMessage()==='growth_cart_signal_shape_invalid'
        && $reads===0,'bad_preflight');
}
$module=file_get_contents(dirname(__DIR__).'/pssalesforcebridge/pssalesforcebridge.php');
snapAssert(strpos($module,'GrowthCartSnapshotEquivalenceReview')===false
    && strpos($module,'inspectWithNativeCartSnapshotReview')===false,
    'module_runtime_touched');
$code=file_get_contents(dirname(__DIR__).'/pssalesforcebridge/src/Growth/GrowthCartSnapshotEquivalenceReview.php');
foreach(['curl_','executeS(', 'file_get_contents(', '->enqueue(',
    '->insert(', '->issueUrl(', '/api/v1/ingest/'] as $unsafe)
    snapAssert(strpos($code,$unsafe)===false,'unexpected_side_effect');
echo "G7 captured/fresh snapshot digest review PASS: shop/cart/customer, drift, late purchase, optout; no sends.\n";
