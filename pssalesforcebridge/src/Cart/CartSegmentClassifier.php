<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Cart;

use RuntimeException;

/**
 * Pure, offline classification for READ-ONLY cart analytics.
 *
 * This is NOT wired to the live detector, queue, cron, Data Cloud or Journey
 * Builder. It never authorizes contacting a customer.
 *
 * age_minutes is supplied only after a separate source-time verification.
 * Missing or unverified ages must not produce a definitive abandoned state.
 */
final class CartSegmentClassifier
{
    public static function classify(array $source, $abandonMinutes = 60)
    {
        $required = ['shop_id', 'cart_id', 'product_count', 'has_order', 'is_guest', 'known_contact', 'activity_time_verified'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $source)) {
                throw new RuntimeException('cart_segment_missing_input');
            }
        }
        if (!self::positiveId($source['shop_id']) || !self::positiveId($source['cart_id'])
            || !is_int($source['product_count']) || $source['product_count'] < 0
            || !is_bool($source['has_order']) || !is_bool($source['is_guest'])
            || !is_bool($source['known_contact'])
            || !is_bool($source['activity_time_verified'])) {
            throw new RuntimeException('cart_segment_invalid_input');
        }
        $threshold = (int) $abandonMinutes;
        if ($threshold < 15 || $threshold > 10080) {
            throw new RuntimeException('cart_segment_invalid_threshold');
        }
        if (array_key_exists('age_minutes', $source)
            && (!is_int($source['age_minutes']) || $source['age_minutes'] < 0)) {
            throw new RuntimeException('cart_segment_invalid_age');
        }
        $status = isset($source['cart_status']) ? (string) $source['cart_status'] : '';
        if (!in_array($status, ['', 'active', 'abandoned', 'ineligible', 'empty', 'converted', 'recovered'], true)) {
            throw new RuntimeException('cart_segment_invalid_status');
        }
        $identity = !$source['known_contact'] ? 'anonymous'
            : ($source['is_guest'] ? 'guest' : 'registered');

        // Native order proof wins even if stale module state says "abandoned".
        if ($source['has_order']) {
            $segment = 'converted';
        } elseif ($status === 'recovered') {
            // A clicked recovery link is not necessarily a completed order.
            $segment = 'recovery_started';
        } elseif ($source['product_count'] === 0) {
            $segment = 'empty';
        } elseif (!$source['activity_time_verified'] ||
            !array_key_exists('age_minutes', $source)) {
            $segment = 'activity_time_unverified';
        } elseif ($source['age_minutes'] >= $threshold) {
            // Deliberately includes module "ineligible" carts for analytics.
            $segment = 'abandoned';
        } else {
            $segment = 'active';
        }

        return [
            'segment' => $segment,
            'identity' => $identity,
            'abandoned_for_analytics' => $segment === 'abandoned',
            // Communication permission is a separate, independently verified
            // contract; PrestaShop newsletter/optin alone are not sufficient.
            'outreach_authorized' => false,
            'data_cloud_ingestion_authorized' => false,
            'marketing_send_authorized' => false,
        ];
    }

    private static function positiveId($id)
    {
        return (is_int($id) || (is_string($id) && ctype_digit($id)))
            && (int) $id > 0 && (string) (int) $id === (string) $id;
    }
}
