<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

/**
 * G7 LAST LOCAL OBSERVATION, NOT A DISPATCH FENCE.
 *
 * Run immediately AFTER the existing native cart/snapshot review to reduce
 * stale order/opt-out exposure. Reuses the exact local readers, does not
 * introduce a new queue, new SQL shapes, writes or Salesforce requests.
 *
 * It CANNOT close the race between the final SELECT and an external send.
 * Every outcome remains hold_for_review and marketing_sends_authorized=false.
 */
final class GrowthCartLastLocalExclusionReview
{
    public static function inspect(
        $shopId, $cartId, $customerId, $selectRows, $prefix
    ) {
        // Existing readers validate scope and prefix BEFORE their queries.
        // Customer check happens first, then orders/ledger as late as possible.
        $customer = GrowthLocalCustomerHoldReader::inspect(
            $shopId, $customerId, $selectRows, $prefix
        );
        $order = LocalOrderPresenceReader::inspect(
            $shopId, $cartId, $selectRows, $prefix
        );

        $customerState = $customer['local_customer_source_state'];
        $nativeOrder = $order['native_order_state'];
        $ledgerOrder = $order['connector_order_state'];
        $purchaseFound = $nativeOrder === 'found'
            || $ledgerOrder === 'found';
        $purchaseUnknown = $nativeOrder === 'unknown'
            || $ledgerOrder === 'unknown';

        // Prioritize a purchase as the state, but preserve ALL independent
        // exclusion reasons even if another source is unavailable.
        $reasons = [];
        if ($purchaseFound) {
            $state = 'purchase_found_in_last_local_review';
            $reasons[] = 'purchase_found_in_last_local_review';
        } elseif ($purchaseUnknown) {
            $state = 'last_local_purchase_source_unverified';
            $reasons[] = 'last_local_purchase_source_unverified';
        } elseif ($customerState === 'known_negative') {
            $state = 'last_local_customer_negative';
        } elseif ($customerState !== 'no_local_negative_observed') {
            $state = 'last_local_customer_source_unverified';
        } else {
            $state = 'last_local_no_negative_observed_review_only';
        }
        if ($customerState === 'known_negative') {
            $reasons[] = 'last_local_customer_negative';
        } elseif ($customerState !== 'no_local_negative_observed') {
            $reasons[] = 'last_local_customer_source_unverified';
        }
        if (!$reasons) {
            $reasons[] = 'last_local_check_not_atomic_with_external_delivery';
        }

        // No raw customer details, source ID, SQL, email, consent state,
        // timestamp, transaction token or untrusted callback output.
        return [
            'status' => 'hold_for_review',
            'last_local_exclusion_state' => $state,
            'last_customer_source_state' => $customerState,
            'last_native_order_state' => $nativeOrder,
            'last_connector_order_state' => $ledgerOrder,
            'last_local_checks' => 3,
            'purchase_observed_in_last_check' => $purchaseFound,
            'last_purchase_source_uncertain' => $purchaseUnknown,
            'last_local_source_uncertain' => $purchaseUnknown
                || $customerState === 'unknown',
            'reasons' => $reasons,
            'last_local_no_negative_is_send_permission' => false,
            'final_local_transactional_claim_certified' => false,
            'race_with_future_dispatch_closed' => false,
            'salesforce_send_time_consent_certified' => false,
            'source_snapshot_authoritative_for_bc' => false,
            'growth_purpose_consent_certified' => false,
            'data_cloud_ingestion_authorized' => false,
            'recovery_link_creation_authorized' => false,
            'marketing_sends_authorized' => false,
        ];
    }

    public static function inspectPrestaShop($shopId, $cartId, $customerId)
    {
        if (!defined('_DB_PREFIX_') || !class_exists('Db')) {
            throw new \RuntimeException('growth_cart_last_local_prestashop_unavailable');
        }
        return self::inspect(
            $shopId, $cartId, $customerId,
            function ($sql) {
                return \Db::getInstance()->executeS($sql, true, false);
            }, _DB_PREFIX_
        );
    }

    private function __construct() {}
}
