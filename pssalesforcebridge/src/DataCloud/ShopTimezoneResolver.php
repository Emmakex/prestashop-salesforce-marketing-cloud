<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud;

use DateTimeZone;
use RuntimeException;

/**
 * Read the explicit shop context, never infer timezone from host locale,
 * Salesforce org, server clock, customer country or ABH business address.
 * No HTTP, secrets, database writes, or automatic export.
 */
final class ShopTimezoneResolver
{
    public static function resolve($shopId, $getTimezone)
    {
        if (!is_int($shopId) || $shopId <= 0) {
            throw new RuntimeException('data_cloud_shop_timezone_invalid_shop');
        }
        if (!is_callable($getTimezone)) {
            throw new RuntimeException('data_cloud_shop_timezone_missing_reader');
        }
        // The caller must read PS_TIMEZONE for exactly this shop context.
        $value = call_user_func($getTimezone, $shopId);
        if (!is_string($value) || $value === ''
            || !in_array($value, DateTimeZone::listIdentifiers(DateTimeZone::ALL), true)) {
            throw new RuntimeException('data_cloud_shop_timezone_not_configured');
        }
        return $value;
    }

    public static function readFromPrestaShop($shopId)
    {
        if (!class_exists('Configuration', false)) {
            throw new RuntimeException('data_cloud_shop_timezone_prestashop_unavailable');
        }
        return self::resolve($shopId, function ($id) {
            // PrestaShop Configuration::get(key, id_lang, id_shop_group, id_shop).
            // Shop overrides inherit from global configuration if absent.
            // This method is not called by any existing live worker.
            return \Configuration::get('PS_TIMEZONE', null, null, $id);
        });
    }
}
