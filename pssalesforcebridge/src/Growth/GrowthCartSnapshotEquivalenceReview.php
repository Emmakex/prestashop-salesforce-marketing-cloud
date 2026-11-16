<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use RuntimeException;

/**
 * G7: bounded comparison protocol for an original captured cart snapshot
 * and a separately recalculated CURRENT cart snapshot.
 *
 * REVIEW-ONLY: No snapshot producer is connected to this class, and this
 * cannot certify that a hash was obtained from real, fresh cart items.
 * The historical hash algorithm must be audited before an adapter is added.
 *
 * A match under injected assertions is not campaign eligibility and not a
 * substitute for an atomic pre-dispatch purchase/opt-out exclusion fence.
 */
final class GrowthCartSnapshotEquivalenceReview
{
    public static function inspect($shopId, $cartId, $customerId, $captured, $current)
    {
        if (!is_int($shopId) || $shopId < 1
            || !is_int($cartId) || $cartId < 1
            || !is_int($customerId) || $customerId < 1) {
            throw new RuntimeException('growth_snapshot_scope_invalid');
        }
        $previous = self::classify($captured, $shopId, $cartId, $customerId,
            'captured_snapshot');
        $now = self::classify($current, $shopId, $cartId, $customerId,
            'fresh_recalculation');

        $matched = false;
        if ($previous !== 'valid' || $now !== 'valid') {
            $state = $previous !== 'valid'
                ? 'captured_snapshot_unverified' : 'fresh_snapshot_unverified';
        } elseif ($captured['algorithm'] !== $current['algorithm']) {
            $state = 'snapshot_algorithm_mismatch_hold';
        } elseif (!hash_equals($captured['digest'], $current['digest'])) {
            $state = 'snapshot_digest_changed_hold';
        } else {
            $matched = true;
            $state = 'snapshot_digest_match_injected_only';
        }

        // Neither a matching digest nor a claimed fresh provenance is proof
        // that a real PrestaShop source snapshot was recalculated. Hash values,
        // cart IDs, capture metadata and source rows are never returned.
        return [
            'status' => 'hold_for_review',
            'snapshot_equivalence_state' => $state,
            'injected_digest_match_observed' => $matched,
            'captured_snapshot_source_certified_for_bc' => false,
            'fresh_snapshot_producer_certified_for_bc' => false,
            'full_native_cart_snapshot_equivalence_certified' => false,
            'source_cart_activity_certified' => false,
            'race_with_future_dispatch_closed' => false,
            'growth_purpose_consent_certified' => false,
            'recovery_link_creation_authorized' => false,
            'data_cloud_ingestion_authorized' => false,
            'marketing_sends_authorized' => false,
        ];
    }

    private static function classify($snapshot, $shopId, $cartId,
        $customerId, $expectedOrigin)
    {
        if (!is_array($snapshot)) {
            return 'invalid';
        }
        $keys = array_keys($snapshot);
        sort($keys);
        if ($keys !== ['algorithm', 'digest', 'id_cart', 'id_customer',
            'id_shop', 'origin']) {
            return 'invalid';
        }
        if ($snapshot['id_shop'] !== $shopId
            || $snapshot['id_cart'] !== $cartId
            || $snapshot['id_customer'] !== $customerId
            || $snapshot['origin'] !== $expectedOrigin
            || $snapshot['algorithm'] !== 'sha256'
            || !is_string($snapshot['digest'])
            || !preg_match('/^[a-f0-9]{64}$/D', $snapshot['digest'])
            || $snapshot['digest'] === str_repeat('0', 64)) {
            return 'invalid';
        }
        return 'valid';
    }

    private function __construct() {}
}
