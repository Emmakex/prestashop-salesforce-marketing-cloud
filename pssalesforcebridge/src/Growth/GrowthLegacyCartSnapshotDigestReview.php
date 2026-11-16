<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

/**
 * Reproduce EXACTLY the 0.7.1 CartCaptureService fingerprint operation:
 *
 *     $encoded = json_encode($payload);
 *     $snapshotHash = sha1($encoded);
 *
 * Here $payload is the whole post-eligibility, ordered CartNormalizer array,
 * NOT the cart_product existence flag or a sanitized/public subset.
 *
 * THIS IS ONLY an offline/injected compatibility review. No DB access,
 * customer extraction, new snapshot writer, recovery URL or external call.
 * The report intentionally never contains the payload, hash or identifiers.
 * A digest match does not authenticate the source row or certify live cart
 * freshness, consent, idempotence, or checkout suppression.
 */
final class GrowthLegacyCartSnapshotDigestReview
{
    const MAX_ENCODED_BYTES = 262144;
    const MAX_ITEMS = 200;

    private static $fields = [
        'cart_key','cart_id','shop_id','customer_id','contact_key','email',
        'firstname','lastname','newsletter','optin','customer_active',
        'customer_deleted','is_guest','currency','total','product_count',
        'item_count','last_activity','items','eligible','eligibility_reason',
        'consent_mode','status','abandon_after'
    ];
    private static $itemFields = [
        'line_key','product_id','product_attribute_id','customization_id',
        'sku','name','quantity','unit_price','line_total'
    ];

    public static function inspect($shopId,$cartId,$customerId,$payload,$storedHash)
    {
        $state = 'legacy_payload_unverified';
        if (is_int($shopId) && $shopId > 0
            && is_int($cartId) && $cartId > 0
            && is_int($customerId) && $customerId > 0
            && is_string($storedHash)
            && preg_match('/^[a-f0-9]{40}$/D', $storedHash)
            && $storedHash !== str_repeat('0', 40)
            && self::validPayload($payload,$shopId,$cartId,$customerId)) {
            // json_encode default options and insertion order are essential:
            // legacy CartCaptureService hashed the EXACT raw encoded string.
            $json = json_encode($payload);
            if (is_string($json) && strlen($json) <= self::MAX_ENCODED_BYTES) {
                $calculated = sha1($json);
                $state = hash_equals($storedHash,$calculated)
                    ? 'legacy_sha1_payload_digest_matches_injected_only'
                    : 'legacy_sha1_payload_digest_changed_hold';
            }
        }
        return [
            'status' => 'hold_for_review',
            'legacy_snapshot_replay_state' => $state,
            'legacy_snapshot_format' => 'sha1_json_encode_char40',
            'legacy_source_record_provenance_certified' => false,
            'current_native_cart_recalculation_certified' => false,
            'source_cart_snapshot_equivalence_certified' => false,
            'final_purchase_exclusion_fence_certified' => false,
            'growth_purpose_consent_certified' => false,
            'recovery_link_creation_authorized' => false,
            'data_cloud_ingestion_authorized' => false,
            'marketing_sends_authorized' => false
        ];
    }

    private static function validPayload($p,$shop,$cart,$customer)
    {
        if (!is_array($p) || array_keys($p) !== self::$fields
            || $p['shop_id'] !== $shop || $p['cart_id'] !== $cart
            || $p['customer_id'] !== $customer
            || $p['cart_key'] !== 'ps_'.$shop.'_'.$cart
            || $p['contact_key'] !== 'ps_'.$shop.'_'.$customer) {
            return false;
        }
        foreach (['email','firstname','lastname','currency',
            'last_activity','eligibility_reason','consent_mode',
            'status','abandon_after'] as $key) {
            if (!is_string($p[$key]))return false;
        }
        foreach (['newsletter','optin','customer_active','customer_deleted',
            'is_guest','eligible'] as $key) {
            if (!is_bool($p[$key]))return false;
        }
        if (!is_float($p['total'])
            || !is_int($p['product_count'])
            || !is_int($p['item_count'])
            || $p['item_count'] < 0
            || $p['product_count'] < 0
            || !is_array($p['items'])
            || count($p['items']) > self::MAX_ITEMS
            || count($p['items']) !== $p['item_count']) {
            return false;
        }
        foreach($p['items'] as $item) {
            if (!is_array($item) || array_keys($item) !== self::$itemFields
                || !is_int($item['product_id'])
                || !is_int($item['product_attribute_id'])
                || !is_int($item['customization_id'])
                || !is_int($item['quantity'])
                || !is_float($item['unit_price'])
                || !is_float($item['line_total'])
                || !is_string($item['line_key'])
                || !is_string($item['sku'])
                || !is_string($item['name'])) {
                return false;
            }
        }
        return true;
    }

    private function __construct() {}
}
