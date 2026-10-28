<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Compatibility;

final class ShopResolver
{
    public static function activeShopIds($context = null)
    {
        $ids = [];

        if (class_exists('Shop') && method_exists('Shop', 'getShops')) {
            $shops = \Shop::getShops(true, null, true);
            if (is_array($shops)) {
                foreach ($shops as $key => $value) {
                    $candidate = is_numeric($value) ? (int) $value : (is_numeric($key) ? (int) $key : 0);
                    if ($candidate > 0) {
                        $ids[$candidate] = $candidate;
                    }
                }
            }
        }

        if (empty($ids) && $context && isset($context->shop) && isset($context->shop->id)) {
            $shopId = (int) $context->shop->id;
            if ($shopId > 0) {
                $ids[$shopId] = $shopId;
            }
        }

        ksort($ids);

        return array_values($ids);
    }

    private function __construct()
    {
    }
}
