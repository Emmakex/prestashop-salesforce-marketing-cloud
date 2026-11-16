<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use DateTimeImmutable;
use DateTimeZone;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\SourceTimestampNormalizer;
use Throwable;

/**
 * G7 local, EXPLICITLY INVOKED review of the current PrestaShop cart against
 * the unchanged 0.7.1 SHA1/json_encode full-payload snapshot algorithm.
 *
 * This is not part of install, hooks, cron, queue or a sender. Native PS
 * objects may contain PII, so payloads and hashes stay inside this call:
 * never return or log customer/product data or a reusable snapshot hash.
 *
 * Eligibility is reproduced solely to compare the historical fingerprint,
 * NOT to infer Marketing Cloud Growth consent or campaign permission.
 */
final class GrowthLegacyCartFreshNativeReview
{
    const MAX_PRODUCTS = 200;

    public static function inspectPrestaShop(
        $shopId, $cartId, $customerId, $capturedHash,
        $shopTimezone, $orderState = 'unknown', $abandonMinutes = 60
    ) {
        if (!self::validScope($shopId, $cartId, $customerId)) {
            return self::hold('native_scope_invalid');
        }
        if (!class_exists('Cart') || !class_exists('Customer')
            || !class_exists('Currency')) {
            return self::hold('native_prestashop_unavailable');
        }
        try {
            $cart = new \Cart($cartId);
            if (!is_object($cart) || !isset($cart->id_shop)
                || (int)$cart->id_shop !== $shopId
                || !isset($cart->id_customer)
                || (int)$cart->id_customer !== $customerId
                || !isset($cart->id_currency)
                || (int)$cart->id_currency < 1) {
                return self::hold('native_cart_scope_unverified');
            }
            $customer = new \Customer($customerId);
            if (!is_object($customer) || !isset($customer->id)
                || (int)$customer->id !== $customerId) {
                return self::hold('native_customer_unverified');
            }
            // Some shop setups share customers (id_shop=0). A positive
            // explicit different shop is never acceptable.
            if (isset($customer->id_shop)
                && (int)$customer->id_shop > 0
                && (int)$customer->id_shop !== $shopId) {
                return self::hold('native_customer_cross_shop');
            }
            if (!isset($customer->active,$customer->deleted,
                $customer->is_guest,$customer->newsletter,
                $customer->optin,$customer->email)) {
                return self::hold('native_customer_fields_unverified');
            }
            $currency = new \Currency((int)$cart->id_currency);
            if (!is_object($currency) || !isset($currency->iso_code)
                || !is_string($currency->iso_code)
                || $currency->iso_code === '') {
                return self::hold('native_currency_unverified');
            }
            if (!method_exists($cart,'getProducts')
                || !method_exists($cart,'getOrderTotal')) {
                return self::hold('native_cart_api_unavailable');
            }
            // PrestaShop core makes its own DB reads internally, but this
            // adapter issues no SQL, reads no legacy sfmc_cart table and
            // never writes. Product list must be complete and bounded.
            $products = $cart->getProducts();
            $total = $cart->getOrderTotal(true, \Cart::BOTH);
            return self::inspectObjects($shopId,$cartId,$customerId,
                $cart,$customer,$products,$currency->iso_code,$total,
                $capturedHash,$shopTimezone,$orderState,$abandonMinutes);
        } catch (Throwable $error) {
            return self::hold('native_source_read_unverified');
        }
    }

    public static function inspectObjects(
        $shopId,$cartId,$customerId,$cart,$customer,$products,
        $currencyIso,$total,$capturedHash,$shopTimezone,
        $orderState = 'unknown',$abandonMinutes = 60
    ) {
        if (!self::validScope($shopId,$cartId,$customerId)) {
            return self::hold('native_scope_invalid');
        }
        if ($orderState !== 'none_asserted') {
            return self::hold($orderState === 'found'
                ? 'native_purchase_asserted_hold'
                : 'native_order_state_unverified');
        }
        // Only BC's existing 60-minute marketing_optin capture policy
        // is replayed. A different period/purpose requires separate
        // owner-approved policy, and this is never consent certification.
        if ($abandonMinutes !== 60) {
            return self::hold('native_abandonment_policy_unverified');
        }
        if (!is_object($cart) || !is_object($customer)
            || !isset($cart->id,$cart->id_shop,$cart->id_customer)
            || (int)$cart->id !== $cartId
            || (int)$cart->id_shop !== $shopId
            || (int)$cart->id_customer !== $customerId
            || !isset($customer->id)
            || (int)$customer->id !== $customerId
            || (isset($customer->id_shop) && (int)$customer->id_shop > 0
                && (int)$customer->id_shop !== $shopId)) {
            return self::hold('native_source_scope_mismatch');
        }
        if (!isset($cart->date_upd)
            || !is_string($cart->date_upd)
            || !isset($customer->active,$customer->deleted,
                $customer->is_guest,$customer->newsletter,
                $customer->optin,$customer->email)
            || !is_string($currencyIso) || $currencyIso === ''
            || !is_array($products)
            || count($products) < 1 || count($products) > self::MAX_PRODUCTS
            || !is_numeric($total) || !is_finite((float)$total)) {
            return self::hold('native_source_fields_unverified');
        }
        try {
            $sourceUtc = SourceTimestampNormalizer::toUtc(
                $cart->date_upd,$shopTimezone
            );
            $sourceAt = new DateTimeImmutable($sourceUtc,
                new DateTimeZone('UTC'));
            $zone = new DateTimeZone($shopTimezone);
            $abandonAfter = $sourceAt->modify('+3600 seconds')
                ->setTimezone($zone)->format('Y-m-d H:i:s');
        } catch (Throwable $error) {
            return self::hold('native_time_source_unverified');
        }
        $items = [];
        $count = 0;
        foreach($products as $p) {
            if (!is_array($p)
                || !isset($p['id_product'])
                || !isset($p['id_product_attribute'])
                || (!isset($p['cart_quantity']) && !isset($p['quantity']))) {
                return self::hold('native_product_shape_unverified');
            }
            // Preserve the original 0.7.1 numeric casts, priority and
            // insertion order, but never silently drop bad product rows.
            foreach(['id_product','id_product_attribute'] as $k) {
                if (!is_numeric($p[$k]) || (int)$p[$k] < 0) {
                    return self::hold('native_product_shape_unverified');
                }
            }
            $productId=(int)$p['id_product'];
            $attributeId=(int)$p['id_product_attribute'];
            $customizationId=isset($p['id_customization'])
                ? (int)$p['id_customization'] : 0;
            $quantity=isset($p['cart_quantity']) ? (int)$p['cart_quantity']
                : (int)$p['quantity'];
            if ($productId < 1 || $quantity <= 0 || $attributeId < 0
                || $customizationId < 0) {
                return self::hold('native_product_shape_unverified');
            }
            $count += max(0,$quantity);
            $unitPrice=isset($p['price_wt']) ? (float)$p['price_wt']
                : (isset($p['price']) ? (float)$p['price'] : 0.0);
            $lineTotal=isset($p['total_wt']) ? (float)$p['total_wt']
                : $unitPrice * max(0,$quantity);
            if (!is_finite($unitPrice) || !is_finite($lineTotal)) {
                return self::hold('native_product_price_unverified');
            }
            $items[] = [
                'line_key'=>$productId.':'.$attributeId.':'.$customizationId,
                'product_id'=>$productId,
                'product_attribute_id'=>$attributeId,
                'customization_id'=>$customizationId,
                'sku'=>isset($p['reference'])?(string)$p['reference']:'',
                'name'=>isset($p['name'])?(string)$p['name']:'',
                'quantity'=>$quantity,
                'unit_price'=>$unitPrice,
                'line_total'=>$lineTotal,
            ];
        }
        $email=trim((string)$customer->email);
        $customerActive=!empty($customer->active);
        $customerDeleted=!empty($customer->deleted);
        $newsletter=!empty($customer->newsletter);
        $eligible=false;
        $reason='unknown';
        // Exact 0.7.1 marketing_optin CartEligibilityPolicy ordering.
        if ($count <= 0) { $reason='empty_cart'; }
        elseif ($email==='' || !filter_var($email,FILTER_VALIDATE_EMAIL)) {
            $reason='missing_email';
        } elseif (!$customerActive) { $reason='customer_inactive'; }
        elseif ($customerDeleted) { $reason='customer_deleted'; }
        elseif (!$newsletter) { $reason='newsletter_required'; }
        else { $eligible=true; $reason='newsletter_subscribed'; }
        $status=$eligible?'active':'ineligible';

        // Exact 0.7.1 CartNormalizer top-level order, plus five final
        // CartCaptureService keys. Payload is NEVER returned/stored/logged.
        $payload = [
            'cart_key'=>'ps_'.$shopId.'_'.$cartId,
            'cart_id'=>$cartId,'shop_id'=>$shopId,
            'customer_id'=>$customerId,
            'contact_key'=>'ps_'.$shopId.'_'.$customerId,
            'email'=>$email,
            'firstname'=>isset($customer->firstname)
                ? (string)$customer->firstname : '',
            'lastname'=>isset($customer->lastname)
                ? (string)$customer->lastname : '',
            'newsletter'=>$newsletter,
            'optin'=>!empty($customer->optin),
            'customer_active'=>$customerActive,
            'customer_deleted'=>$customerDeleted,
            'is_guest'=>!empty($customer->is_guest),
            'currency'=>$currencyIso,'total'=>(float)$total,
            'product_count'=>$count,'item_count'=>count($items),
            'last_activity'=>$cart->date_upd,'items'=>$items,
            'eligible'=>$eligible,'eligibility_reason'=>$reason,
            'consent_mode'=>'marketing_optin','status'=>$status,
            'abandon_after'=>$abandonAfter,
        ];
        $replay=GrowthLegacyCartSnapshotDigestReview::inspect(
            $shopId,$cartId,$customerId,$payload,$capturedHash
        );
        unset($payload,$items,$products,$email);
        $state=$replay['legacy_snapshot_replay_state'];
        if ($state==='legacy_sha1_payload_digest_matches_injected_only') {
            return self::hold('native_071_recomputed_digest_match_review_only');
        }
        if ($state==='legacy_sha1_payload_digest_changed_hold') {
            return self::hold('native_071_recomputed_digest_changed_hold');
        }
        return self::hold('native_071_recomputed_digest_unverified');
    }

    private static function validScope($shop,$cart,$customer)
    {
        return is_int($shop) && $shop > 0
            && is_int($cart) && $cart > 0
            && is_int($customer) && $customer > 0;
    }

    private static function hold($state)
    {
        return [
            'status'=>'hold_for_review',
            'native_legacy_fresh_snapshot_state'=>$state,
            'legacy_algorithm'=>'sha1_json_encode_char40',
            'native_cart_read_scope_certified_for_bc'=>false,
            'legacy_writer_timezone_certified_for_bc'=>false,
            'legacy_snapshot_compared_to_live_db_certified'=>false,
            'legacy_snapshot_source_authenticity_certified'=>false,
            'growth_purpose_consent_certified'=>false,
            'final_purchase_and_optout_fence_certified'=>false,
            'data_cloud_ingestion_authorized'=>false,
            'recovery_link_creation_authorized'=>false,
            'marketing_sends_authorized'=>false,
        ];
    }

    private function __construct() {}
}
