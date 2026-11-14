<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use RuntimeException;

/**
 * Read-only Growth cart conversion preflight; no recovery sending or enqueue.
 *
 * The caller-supplied customer/consent flags are UNTRUSTED assertions until
 * G3/G6 signatures and real source readers exist. This class never converts
 * those assertions or two clean local order reads into send authorization.
 *
 * Two independent passes narrow a stale-cart evidence gap, but cannot
 * eliminate the purchase-vs-dispatch race. A future approved sender must
 * have an independently certified final exclusion fence.
 *
 * NOT wired to module hooks, cron, workers or production. No personal data
 * enters the returned report.
 */
final class GrowthCartConversionHoldGate
{
    private static $keys = [
        'provider', 'mode', 'installation_shop_scope', 'cart_status',
        'snapshot_current', 'cart_has_products', 'abandonment_window',
        'current_customer_state', 'contact_matches_snapshot',
        'growth_consent_for_purpose', 'growth_suppression', 'frequency_cap',
    ];

    private static $enums = [
        'provider' => ['marketing_cloud_growth'],
        'mode' => ['test'],
        'installation_shop_scope' => ['verified', 'cross_scope', 'unknown'],
        'cart_status' => ['abandoned', 'active', 'converted', 'missing', 'unknown'],
        'abandonment_window' => ['elapsed', 'not_elapsed', 'unknown'],
        'current_customer_state' => ['active', 'inactive', 'deleted', 'unknown'],
        'growth_consent_for_purpose' => ['verified', 'denied', 'unknown'],
        'growth_suppression' => ['clear', 'suppressed', 'unknown'],
        'frequency_cap' => ['available', 'exceeded', 'unknown'],
    ];

    /**
     * @param int $shopId Explicit PrestaShop shop
     * @param int $cartId Explicit PrestaShop cart
     * @param callable $selectRows Dependency for bounded PrestaShop SQL SELECTs
     * @param string $prefix Verified installation database prefix
     * @param array $signals Strict non-PII, externally asserted cart metadata
     */
    public static function inspect(
        $shopId, $cartId, $selectRows, $prefix, array $signals
    ) {
        self::validateSignals($signals);
        // Each pass uses two independent SELECTs in LocalOrderPresenceReader:
        // native orders + existing local order ledger. Any SQL error is UNKNOWN.
        $first = LocalOrderPresenceReader::inspect(
            $shopId, $cartId, $selectRows, $prefix
        );
        $second = LocalOrderPresenceReader::inspect(
            $shopId, $cartId, $selectRows, $prefix
        );
        $native = [
            $first['native_order_state'], $second['native_order_state']
        ];
        $ledger = [
            $first['connector_order_state'], $second['connector_order_state']
        ];
        foreach (array_merge($native, $ledger) as $state) {
            if (!in_array($state, ['none', 'found', 'unknown'], true)) {
                throw new RuntimeException('growth_cart_local_order_state_invalid');
            }
        }

        $converted = in_array('found', $native, true)
            || in_array('found', $ledger, true);
        $unknown = in_array('unknown', $native, true)
            || in_array('unknown', $ledger, true);

        $reasons = [];
        if ($converted) {
            $reasons[] = 'purchase_found_in_local_source';
        } elseif ($unknown) {
            $reasons[] = 'purchase_state_unverified';
        }
        $checks = [
            'installation_scope_unverified' =>
                $signals['installation_shop_scope'] !== 'verified',
            'cart_not_abandoned' => $signals['cart_status'] !== 'abandoned',
            'cart_snapshot_unverified' => $signals['snapshot_current'] !== true,
            'cart_empty' => $signals['cart_has_products'] !== true,
            'abandonment_window_unverified' =>
                $signals['abandonment_window'] !== 'elapsed',
            'customer_state_unverified' =>
                $signals['current_customer_state'] !== 'active',
            'contact_state_unverified' =>
                $signals['contact_matches_snapshot'] !== true,
            'purpose_consent_unverified' =>
                $signals['growth_consent_for_purpose'] !== 'verified',
            'suppression_unverified' =>
                $signals['growth_suppression'] !== 'clear',
            'frequency_unverified' =>
                $signals['frequency_cap'] !== 'available',
        ];
        foreach ($checks as $reason => $failure) {
            if ($failure) {
                $reasons[] = $reason;
            }
        }

        // Even perfect synthetic assertions and both clean passes are NOT
        // permission to recover a cart or call any Salesforce API.
        return [
            'status' => $reasons ? 'hold_for_review'
                : 'local_cart_candidate_review_only',
            'reasons' => $reasons,
            'local_checks' => 2,
            'native_order_states' => $native,
            'connector_order_states' => $ledger,
            'purchase_found_locally' => $converted,
            'purchase_state_unknown' => $unknown,
            'source_assertions_certified_for_bc' => false,
            'installation_namespace_globally_certified' => false,
            'race_with_future_dispatch_closed' => false,
            'consent_certified_for_bc' => false,
            'cart_stream_approved' => false,
            'growth_flow_authorized' => false,
            'recovery_link_creation_authorized' => false,
            'data_cloud_ingestion_authorized' => false,
            'marketing_sends_authorized' => false,
        ];
    }

    private static function validateSignals(array $signals)
    {
        $keys = array_keys($signals);
        $expected = self::$keys;
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            throw new RuntimeException('growth_cart_signal_shape_invalid');
        }
        foreach (self::$enums as $field => $allowed) {
            if (!is_string($signals[$field])
                || !in_array($signals[$field], $allowed, true)) {
                throw new RuntimeException('growth_cart_signal_value_invalid');
            }
        }
        foreach ([
            'snapshot_current', 'cart_has_products',
            'contact_matches_snapshot'
        ] as $flag) {
            if (!is_bool($signals[$flag])) {
                throw new RuntimeException('growth_cart_signal_boolean_invalid');
            }
        }
    }

    private function __construct()
    {
    }
}
