<?php
/**
 * G3-08/G7 design laboratory ONLY. No customer data, IO, transport or sender.
 * Every input is a synthetic assertion; even a fully clear result is NOT
 * proof of consent or a permit to call a Marketing Cloud Growth Flow.
 */
final class G3GrowthCartSourcePresendReview
{
    private static $keys = [
        'provider', 'runtime_mode', 'installation_shop_scope',
        'cart_status', 'snapshot_current', 'cart_has_products',
        'abandonment_window', 'native_order_state', 'connector_order_state',
        'current_customer_state', 'contact_matches_snapshot',
        'growth_consent_for_purpose', 'growth_suppression', 'frequency_cap',
    ];

    private static $enums = [
        'provider' => ['marketing_cloud_growth', 'marketing_cloud_engagement'],
        'runtime_mode' => ['test', 'production'],
        'installation_shop_scope' => ['verified', 'cross_scope', 'unknown'],
        'cart_status' => ['abandoned', 'active', 'converted', 'missing'],
        'abandonment_window' => ['elapsed', 'not_elapsed', 'unknown'],
        'native_order_state' => ['none', 'found', 'unknown'],
        'connector_order_state' => ['none', 'found', 'unknown'],
        'current_customer_state' => ['active', 'inactive', 'deleted', 'unknown'],
        'growth_consent_for_purpose' => ['verified', 'denied', 'unknown'],
        'growth_suppression' => ['clear', 'suppressed', 'unknown'],
        'frequency_cap' => ['available', 'exceeded', 'unknown'],
    ];

    public static function inspect(array $assertions)
    {
        // Never accept an email, order/cart ID, token, recovery URL or freeform
        // record: the laboratory only needs bounded decision metadata.
        $keys = array_keys($assertions);
        $expected = self::$keys;
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            throw new RuntimeException('g3_growth_cart_evidence_shape_invalid');
        }
        foreach (self::$enums as $key => $allowed) {
            if (!is_string($assertions[$key]) || !in_array($assertions[$key], $allowed, true)) {
                throw new RuntimeException('g3_growth_cart_evidence_enum_invalid');
            }
        }
        foreach (['snapshot_current', 'cart_has_products', 'contact_matches_snapshot'] as $key) {
            if (!is_bool($assertions[$key])) {
                throw new RuntimeException('g3_growth_cart_evidence_boolean_invalid');
            }
        }

        $reasons = [];
        $checks = [
            'provider_not_growth' => $assertions['provider'] !== 'marketing_cloud_growth',
            'runtime_not_test' => $assertions['runtime_mode'] !== 'test',
            'scope_not_verified' => $assertions['installation_shop_scope'] !== 'verified',
            'cart_not_abandoned' => $assertions['cart_status'] !== 'abandoned',
            'cart_snapshot_stale_or_unknown' => !$assertions['snapshot_current'],
            'cart_empty' => !$assertions['cart_has_products'],
            'inactivity_window_not_verified' => $assertions['abandonment_window'] !== 'elapsed',
            'native_order_found_or_unknown' => $assertions['native_order_state'] !== 'none',
            'connector_order_found_or_unknown' => $assertions['connector_order_state'] !== 'none',
            'current_customer_not_active' => $assertions['current_customer_state'] !== 'active',
            'customer_contact_changed_or_unknown' => !$assertions['contact_matches_snapshot'],
            'growth_purpose_consent_not_verified' => $assertions['growth_consent_for_purpose'] !== 'verified',
            'growth_suppression_not_clear' => $assertions['growth_suppression'] !== 'clear',
            'frequency_cap_not_verified' => $assertions['frequency_cap'] !== 'available',
        ];
        foreach ($checks as $reason => $failed) {
            if ($failed) {
                $reasons[] = $reason;
            }
        }
        return [
            'status' => $reasons ? 'hold_for_review' : 'local_source_candidate_only',
            'reasons' => $reasons,
            'evidence_type' => 'synthetic_assertions_not_live_checks',
            'cart_stream_approved' => false,
            'growth_flow_certified_for_abh' => false,
            'consent_certified_for_abh' => false,
            'recovery_link_creation_authorized' => false,
            'data_360_ingestion_authorized' => false,
            'marketing_sends_authorized' => false,
        ];
    }
}
