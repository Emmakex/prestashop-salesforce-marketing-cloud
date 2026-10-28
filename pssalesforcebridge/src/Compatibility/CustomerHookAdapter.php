<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Compatibility;

final class CustomerHookAdapter
{
    public static function customerFromParams(array $params)
    {
        foreach (['newCustomer', 'customer', 'object'] as $key) {
            if (isset($params[$key]) && is_object($params[$key])) {
                return $params[$key];
            }
        }

        return null;
    }

    public static function shopId($customer, $context = null)
    {
        if (is_object($customer) && !empty($customer->id_shop)) {
            return (int) $customer->id_shop;
        }

        if (is_object($context) && isset($context->shop) && is_object($context->shop) && !empty($context->shop->id)) {
            return (int) $context->shop->id;
        }

        return 1;
    }

    private function __construct()
    {
    }
}
