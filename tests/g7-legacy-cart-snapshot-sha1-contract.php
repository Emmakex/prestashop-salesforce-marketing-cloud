<?php
/**
 * G7 0.7.1 snapshot replay from pinned historical source:
 * https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/blob/527fe127a681cf3869611e3cbceac36421a28aff/pssalesforcebridge/src/Cart/CartCaptureService.php
 *
 * Historical code hashes sha1(json_encode($payload)) AFTER adding eligible,
 * eligibility_reason, consent_mode, status, and abandon_after.
 * Legacy sfmc_cart.snapshot_hash is CHAR(40). Synthetic data only.
 */
require_once dirname(__DIR__)
    . '/pssalesforcebridge/src/Growth/GrowthLegacyCartSnapshotDigestReview.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthLegacyCartSnapshotDigestReview as Replay;

function g7ReplayAssert($good,$why)
{
    if (!$good) { fwrite(STDERR,"G7 legacy replay FAIL: ".$why."\n"); exit(1); }
}

function g7Payload()
{
    // Exact field and insertion order: CartNormalizer::fromCart then
    // five additional fields from CartCaptureService::capture.
    return [
        'cart_key'=>'ps_2_93','cart_id'=>93,'shop_id'=>2,
        'customer_id'=>17,'contact_key'=>'ps_2_17',
        'email'=>'synthetic@example.invalid',
        'firstname'=>'Synthetic','lastname'=>'Buyer',
        'newsletter'=>true,'optin'=>true,'customer_active'=>true,
        'customer_deleted'=>false,'is_guest'=>false,'currency'=>'EUR',
        'total'=>42.5,'product_count'=>1,'item_count'=>1,
        'last_activity'=>'2026-11-16 11:30:00',
        'items'=>[[
            'line_key'=>'12:0:0','product_id'=>12,
            'product_attribute_id'=>0,'customization_id'=>0,
            'sku'=>'SYNTH-SKU','name'=>'Synthetic product',
            'quantity'=>1,'unit_price'=>42.5,'line_total'=>42.5
        ]],
        'eligible'=>true,'eligibility_reason'=>'eligible',
        'consent_mode'=>'marketing_optin',
        'status'=>'active','abandon_after'=>'2026-11-16 12:30:00'
    ];
}
const GOLDEN_071_SHA1 = 'b6f9a8814d5ecbf74c365bb4e12e58ea12e7317f';
g7ReplayAssert(sha1(json_encode(g7Payload()))===GOLDEN_071_SHA1,
    'legacy_hash_golden_vector_drift');
g7ReplayAssert(strlen(GOLDEN_071_SHA1)===40,'char40_digest_expected');

function g7ReplayCheck($p,$stored,$expect,$shop=2,$cart=93,$customer=17)
{
    $result=Replay::inspect($shop,$cart,$customer,$p,$stored);
    g7ReplayAssert($result['status']==='hold_for_review'
        && $result['legacy_snapshot_replay_state']===$expect
        && $result['legacy_snapshot_format']==='sha1_json_encode_char40',
        'unexpected_'.$expect);
    foreach(['legacy_source_record_provenance_certified',
        'current_native_cart_recalculation_certified',
        'source_cart_snapshot_equivalence_certified',
        'final_purchase_exclusion_fence_certified',
        'growth_purpose_consent_certified','recovery_link_creation_authorized',
        'data_cloud_ingestion_authorized','marketing_sends_authorized'] as $key) {
        g7ReplayAssert($result[$key]===false,'unapproved_'.$key);
    }
    $json=json_encode($result);
    foreach([GOLDEN_071_SHA1,'SYNTH-SKU',
        'synthetic@example.invalid','Synthetic product','ps_2_93',
        '"cart_id"','"customer_id"','"shop_id"'] as $private) {
        g7ReplayAssert(strpos($json,$private)===false,
            'source_payload_or_digest_leaked');
    }
}
g7ReplayCheck(g7Payload(),GOLDEN_071_SHA1,
    'legacy_sha1_payload_digest_matches_injected_only');
g7ReplayCheck(g7Payload(),sha1('wrong stored hash fixture'),
    'legacy_sha1_payload_digest_changed_hold');
$changes=[
    ['email','changed@example.invalid'],
    ['firstname','Renamed'],
    ['optin',false],
    ['newsletter',false],
    ['currency','USD'],
    ['total',42.6],
    ['last_activity','2026-11-16 11:31:00'],
    ['eligible',false],
    ['eligibility_reason','customer_opted_out'],
    ['consent_mode','known_contact'],
    ['status','ineligible'],
    ['abandon_after','2026-11-16 13:30:00'],
];
foreach($changes as $change) {
    $p=g7Payload();
    $p[$change[0]]=$change[1];
    g7ReplayCheck($p,GOLDEN_071_SHA1,'legacy_sha1_payload_digest_changed_hold');
}
$p=g7Payload();$p['items'][0]['quantity']=2;
g7ReplayCheck($p,GOLDEN_071_SHA1,'legacy_sha1_payload_digest_changed_hold');
$p=g7Payload();$p['items'][0]['name']='Changed product name';
g7ReplayCheck($p,GOLDEN_071_SHA1,'legacy_sha1_payload_digest_changed_hold');
$p=g7Payload();$p['items'][0]['unit_price']=41.5;
g7ReplayCheck($p,GOLDEN_071_SHA1,'legacy_sha1_payload_digest_changed_hold');

// The legacy serializer depends on both top-level and item key order.
// Reordered input must not be accepted as the exact 0.7.1 payload.
$p=g7Payload();$p=array_reverse($p,true);
g7ReplayCheck($p,GOLDEN_071_SHA1,'legacy_payload_unverified');
$p=g7Payload();$p['items'][0]=array_reverse($p['items'][0],true);
g7ReplayCheck($p,GOLDEN_071_SHA1,'legacy_payload_unverified');
$p=g7Payload();$p['email']='changed@example.invalid';
$p['extra_field']='never_leak';
g7ReplayCheck($p,GOLDEN_071_SHA1,'legacy_payload_unverified');
$p=g7Payload();$p['cart_id']=94;
g7ReplayCheck($p,GOLDEN_071_SHA1,'legacy_payload_unverified');
$p=g7Payload();$p['contact_key']='ps_2_99';
g7ReplayCheck($p,GOLDEN_071_SHA1,'legacy_payload_unverified');
$p=g7Payload();$p['total']='42.5';
g7ReplayCheck($p,GOLDEN_071_SHA1,'legacy_payload_unverified');
$p=g7Payload();$p['items'][0]['unexpected_person']='secret';
g7ReplayCheck($p,GOLDEN_071_SHA1,'legacy_payload_unverified');
$p=g7Payload();$p['items']=[['broken'=>'row']];
g7ReplayCheck($p,GOLDEN_071_SHA1,'legacy_payload_unverified');
$p=g7Payload();$p['email']=str_repeat('X',270000);
g7ReplayCheck($p,GOLDEN_071_SHA1,'legacy_payload_unverified');
g7ReplayCheck(g7Payload(),hash('sha256','legacy fixture'),
    'legacy_payload_unverified');
g7ReplayCheck(g7Payload(),str_repeat('0',40),'legacy_payload_unverified');
g7ReplayCheck(g7Payload(),GOLDEN_071_SHA1,'legacy_payload_unverified',3);
g7ReplayCheck(g7Payload(),GOLDEN_071_SHA1,'legacy_payload_unverified',2,94);
g7ReplayCheck(g7Payload(),GOLDEN_071_SHA1,'legacy_payload_unverified',2,93,18);

// A legacy SHA-1 is only a change detector, not a security token, 
// marketing consent, proof of current cart items, or a dispatch fence.
$module=file_get_contents(dirname(__DIR__)
    . '/pssalesforcebridge/pssalesforcebridge.php');
$source=file_get_contents(dirname(__DIR__)
    . '/pssalesforcebridge/src/Growth/GrowthLegacyCartSnapshotDigestReview.php');
g7ReplayAssert(strpos($module,'GrowthLegacyCartSnapshotDigestReview')===false,
    'replay_reader_connected_to_live_module');
foreach(['curl_','executeS(', 'Db::getInstance()', 'file_get_contents(',
    '->insert(', '->issueUrl(', '->enqueue(', '/api/v1/ingest/'] as $unsafe) {
    g7ReplayAssert(strpos($source,$unsafe)===false,
        'outbound_or_db_access_in_replay');
}
echo "G7 0.7.1 SHA1 JSON snapshot replay PASS: golden CHAR40, drift, order, cross-scope, no sends.\n";
