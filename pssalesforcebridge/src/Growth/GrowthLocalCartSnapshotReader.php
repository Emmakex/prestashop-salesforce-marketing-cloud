<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use RuntimeException;
use Throwable;

/**
 * G7 fresh, shop-scoped cart evidence. No sender, hook or recovery URL.
 * Source timestamp is checked for shape only; timezone, inactivity,
 * snapshot equivalence and checkout races are never certified.
 */
final class GrowthLocalCartSnapshotReader
{
    public static function inspect($shopId, $cartId, $customerId, $selectRows, $prefix)
    {
        if (!is_int($shopId) || $shopId <= 0
            || !is_int($cartId) || $cartId <= 0
            || !is_int($customerId) || $customerId <= 0) {
            throw new RuntimeException('growth_cart_snapshot_scope_invalid');
        }
        if (!is_callable($selectRows)) {
            throw new RuntimeException('growth_cart_snapshot_reader_required');
        }
        if (!is_string($prefix) || strlen($prefix) < 1
            || strlen($prefix) > 64
            || !preg_match('/^[A-Za-z0-9_]+$/D', $prefix)) {
            throw new RuntimeException('growth_cart_snapshot_prefix_invalid');
        }
        $cartSql = 'SELECT `id_cart`, `id_shop`, `id_customer`, `date_upd`'
            . ' FROM `' . $prefix . 'cart` WHERE `id_shop` = ' . $shopId
            . ' AND `id_cart` = ' . $cartId . ' LIMIT 2';
        try {
            $rows = call_user_func($selectRows, $cartSql);
        } catch (Throwable $error) {
            return self::hold('cart_source_unverified', 'local_cart_read_failed', false);
        }
        if ($rows === []) {
            return self::hold('cart_missing', 'local_cart_not_found', false);
        }
        if (!is_array($rows) || count($rows) !== 1 || !isset($rows[0])
            || !is_array($rows[0])) {
            return self::hold('cart_source_unverified', 'local_cart_row_ambiguous', false);
        }
        $row = $rows[0];
        $keys = array_keys($row);
        sort($keys);
        if ($keys !== ['date_upd','id_cart','id_customer','id_shop']) {
            return self::hold('cart_source_unverified', 'local_cart_columns_invalid', false);
        }
        if (self::id($row['id_cart']) !== $cartId
            || self::id($row['id_shop']) !== $shopId
            || self::id($row['id_customer']) !== $customerId) {
            return self::hold('cart_source_unverified', 'local_cart_scope_mismatch', false);
        }
        if (!self::localDatetime($row['date_upd'])) {
            return self::hold('cart_source_unverified', 'local_cart_timestamp_invalid', false);
        }

        // Existence of a positive quantity only. Never query names, product
        // IDs, prices, email, address, discount, total or private payload.
        $productSql = 'SELECT `id_cart`, `id_shop`, `quantity`'
            . ' FROM `' . $prefix . 'cart_product`'
            . ' WHERE `id_shop` = ' . $shopId
            . ' AND `id_cart` = ' . $cartId
            . ' AND `quantity` > 0 LIMIT 1';
        try {
            $products = call_user_func($selectRows, $productSql);
        } catch (Throwable $error) {
            return self::hold('cart_source_unverified', 'local_cart_product_read_failed', false);
        }
        if ($products === []) {
            return self::hold('cart_present_empty', 'local_cart_has_no_positive_products', false);
        }
        if (!is_array($products) || count($products) !== 1
            || !isset($products[0]) || !is_array($products[0])) {
            return self::hold('cart_source_unverified', 'local_cart_product_row_ambiguous', false);
        }
        $pr = $products[0];
        $cols = array_keys($pr);
        sort($cols);
        if ($cols !== ['id_cart','id_shop','quantity']) {
            return self::hold('cart_source_unverified', 'local_cart_product_columns_invalid', false);
        }
        if (self::id($pr['id_shop']) !== $shopId
            || self::id($pr['id_cart']) !== $cartId
            || self::id($pr['quantity']) === null) {
            return self::hold('cart_source_unverified',
                'local_cart_product_scope_or_quantity_invalid', false);
        }
        return self::hold('cart_present_nonempty_review_only',
            'cart_snapshot_and_abandonment_window_unverified', true);
    }

    /** Native PrestaShop 1.7/8/9; no cache on either SELECT. */
    public static function inspectPrestaShop($shopId, $cartId, $customerId)
    {
        if (!defined('_DB_PREFIX_') || !class_exists('Db')) {
            throw new RuntimeException('growth_cart_snapshot_prestashop_unavailable');
        }
        return self::inspect($shopId, $cartId, $customerId, function ($sql) {
            return \Db::getInstance()->executeS($sql, true, false);
        }, _DB_PREFIX_);
    }

    private static function hold($state, $reason, $hasProducts)
    {
        return [
            'status' => 'hold_for_review',
            'local_cart_source_state' => $state,
            'reasons' => [$reason],
            'positive_product_observed' => $hasProducts,
            'source_snapshot_hash_verified' => false,
            'abandonment_window_verified' => false,
            'cart_timestamp_timezone_verified' => false,
            'purchase_exclusion_fence_certified' => false,
            'growth_purpose_consent_certified' => false,
            'recovery_link_creation_authorized' => false,
            'data_cloud_ingestion_authorized' => false,
            'marketing_sends_authorized' => false,
        ];
    }

    private static function localDatetime($value)
    {
        if (!is_string($value) || !preg_match(
            '/^([0-9]{4})-([0-9]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2})$/D',
            $value, $m
        )) {
            return false;
        }
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1])
            && (int)$m[4] <= 23 && (int)$m[5] <= 59 && (int)$m[6] <= 59;
    }

    private static function id($value)
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value)) {
            return null;
        }
        $id = (int)$value;
        return $id > 0 && (string)$id === $value ? $id : null;
    }

    private function __construct() {}
}
