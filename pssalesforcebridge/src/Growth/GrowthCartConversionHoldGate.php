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

    /**
     * Compose fresh, shop-scoped local customer safety evidence with the
     * existing double-order-read hold. Still NO sending, no consent approval.
     *
     * This cannot infer affirmative Growth consent from newsletter/optin,
     * even if every asserted signal was "verified" by the caller.
     * No email, customer ID, or source row is returned.
     */
    public static function inspectWithLocalCustomer(
        $shopId, $cartId, $customerId, $selectRows, $prefix, array $signals
    ) {
        self::validateSignals($signals);
        $local = GrowthLocalCustomerHoldReader::inspect(
            $shopId, $customerId, $selectRows, $prefix
        );
        if ($local['local_customer_source_state']
            !== 'no_local_negative_observed') {
            $signals['current_customer_state'] = 'unknown';
        }
        // Explicitly invalidate caller-supplied "verified" assumptions:
        // shop optin/newsletter never certify the separate Growth purpose.
        $signals['growth_consent_for_purpose'] = 'unknown';
        $result = self::inspect(
            $shopId, $cartId, $selectRows, $prefix, $signals
        );
        $result['reasons'] = array_values(array_unique(array_merge(
            $result['reasons'], $local['reasons']
        )));
        $result['status'] = 'hold_for_review';
        $result['local_customer_source_state'] =
            $local['local_customer_source_state'];
        $result['growth_purpose_consent_certified'] = false;
        $result['other_suppression_sources_checked'] = false;
        return $result;
    }

    /**
     * Native PrestaShop 1.7/8/9 read-only bridge for the complete hold
     * composition. Every query bypasses executeS cache, including the second
     * purchase check; no hooks, sender, token, queue, email or writes.
     *
     * A fresh SELECT is NOT a transactional dispatch fence. Purchase or
     * opt-out may still happen after these reads, so sends remain forbidden.
     */
    public static function inspectPrestaShopWithLocalCustomer(
        $shopId, $cartId, $customerId, array $signals
    ) {
        self::validateSignals($signals);
        if (!defined('_DB_PREFIX_') || !class_exists('Db')) {
            throw new RuntimeException('growth_cart_prestashop_unavailable');
        }
        return self::inspectWithLocalCustomer(
            $shopId, $cartId, $customerId, function ($sql) {
                return \Db::getInstance()->executeS($sql, true, false);
            }, _DB_PREFIX_, $signals
        );
    }

    /**
     * G7 native cart + existing fresh local customer and double order hold.
     *
     * Prevent caller assertions of cart nonemptiness, snapshot freshness,
     * and elapsed abandonment from being treated as certified evidence.
     * Even a present, nonempty cart is NOT "abandoned" until inactivity
     * provenance, trusted snapshot, and final purchase fence are certified.
     *
     * Preflight validates all signals BEFORE any DB read; no PII is returned.
     */
    public static function inspectWithNativeCart(
        $shopId, $cartId, $customerId, $selectRows, $prefix, array $signals,
        $reviewWindow = null
    ) {
        self::validateSignals($signals);
        $cart = GrowthLocalCartSnapshotReader::inspect(
            $shopId, $cartId, $customerId, $selectRows, $prefix,
            $reviewWindow
        );
        // A caller supplied "snapshot_current=true" is NOT proof of a
        // snapshot match. Validate locally, then keep that claim unverified.
        $signals['cart_has_products'] = $cart['positive_product_observed'];
        $signals['snapshot_current'] = false;
        $signals['abandonment_window'] = 'unknown';
        $signals['cart_status'] = 'unknown';
        $result = self::inspectWithLocalCustomer(
            $shopId, $cartId, $customerId, $selectRows, $prefix, $signals
        );
        $result['status'] = 'hold_for_review';
        $result['local_cart_source_state'] = $cart['local_cart_source_state'];
        $result['local_cart_positive_product_observed'] =
            $cart['positive_product_observed'];
        $result['local_cart_inactivity_window_state'] =
            $cart['local_cart_inactivity_window_state'];
        $result['inactivity_window_certified_for_bc'] = false;
        $result['native_cart_snapshot_verified_for_bc'] = false;
        $result['local_cart_source_checked'] = true;
        $result['reasons'] = array_values(array_unique(array_merge(
            $result['reasons'], $cart['reasons']
        )));
        return $result;
    }

    /**
     * PrestaShop 1.7/8/9 read-only adaptation.
     * Every cart, product, customer, native-order and ledger SELECT uses
     * executeS($sql, true, false) to bypass the PrestaShop result cache.
     */
    public static function inspectPrestaShopWithNativeCart(
        $shopId, $cartId, $customerId, array $signals
    ) {
        self::validateSignals($signals);
        if (!defined('_DB_PREFIX_') || !class_exists('Db')) {
            throw new RuntimeException('growth_cart_prestashop_unavailable');
        }
        return self::inspectWithNativeCart(
            $shopId, $cartId, $customerId, function ($sql) {
                return \Db::getInstance()->executeS($sql, true, false);
            }, _DB_PREFIX_, $signals
        );
    }

    /**
     * Review the local 60-minute cart window under EXPLICIT writer/timezone/
     * observation assumptions, then combine with existing purchase and
     * opt-out holds. Even an elapsed time is review-only and never a send.
     * No new queries: the callback reads the date from the same validated
     * cart row within the existing seven fresh local SELECTs.
     */
    public static function inspectWithNativeCartWindow(
        $shopId, $cartId, $customerId, $selectRows, $prefix, array $signals,
        $shopTimezone, $asOfUtc, $writePathEvidence
    ) {
        self::validateSignals($signals);
        return self::inspectWithNativeCart(
            $shopId, $cartId, $customerId, $selectRows, $prefix,
            $signals, function ($cartLocalDate) use (
                $shopTimezone, $asOfUtc, $writePathEvidence
            ) {
                return GrowthCartInactivityWindowReview::inspect(
                    $cartLocalDate, $shopTimezone, $asOfUtc,
                    $writePathEvidence
                );
            }
        );
    }

    /**
     * PrestaShop 1.7/8/9 adapter: shop-specific PS_TIMEZONE read only.
     * PS_TIMEZONE alone never proves past date_upd write semantics;
     * the caller must supply a separately verified UTC clock/evidence,
     * and the returned result remains hold_for_review in every case.
     */
    public static function inspectPrestaShopWithNativeCartWindow(
        $shopId, $cartId, $customerId, array $signals,
        $asOfUtc, $writePathEvidence
    ) {
        self::validateSignals($signals);
        if (!defined('_DB_PREFIX_') || !class_exists('Db')
            || !class_exists('Configuration')) {
            throw new RuntimeException('growth_cart_prestashop_unavailable');
        }
        $timezone = \Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\ShopTimezoneResolver
            ::readFromPrestaShop($shopId);
        return self::inspectWithNativeCartWindow(
            $shopId, $cartId, $customerId, function ($sql) {
                return \Db::getInstance()->executeS($sql, true, false);
            }, _DB_PREFIX_, $signals, $timezone, $asOfUtc,
            $writePathEvidence
        );
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
