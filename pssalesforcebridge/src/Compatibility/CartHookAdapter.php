<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Compatibility;

use Cart;
use Context;
use Currency;
use Customer;

final class CartHookAdapter
{
    public static function cartFromParams(array $params)
    {
        if (isset($params['cart']) && is_object($params['cart'])) {
            return $params['cart'];
        }

        if (!empty($params['id_cart'])) {
            $cart = new Cart((int) $params['id_cart']);
            if (isset($cart->id) && (int) $cart->id > 0) {
                return $cart;
            }
        }

        return null;
    }

    public static function shopId($cart, $context = null)
    {
        if (is_object($cart) && isset($cart->id_shop) && (int) $cart->id_shop > 0) {
            return (int) $cart->id_shop;
        }

        if ($context instanceof Context && isset($context->shop) && isset($context->shop->id)) {
            return (int) $context->shop->id;
        }

        return 0;
    }

    public static function customer($cart)
    {
        if (!is_object($cart) || !isset($cart->id_customer) || (int) $cart->id_customer <= 0) {
            return null;
        }

        $customer = new Customer((int) $cart->id_customer);

        return isset($customer->id) && (int) $customer->id > 0 ? $customer : null;
    }

    public static function currencyIso($cart, $context = null)
    {
        if (is_object($cart) && isset($cart->id_currency) && (int) $cart->id_currency > 0) {
            $currency = new Currency((int) $cart->id_currency);
            if (isset($currency->iso_code) && $currency->iso_code !== '') {
                return (string) $currency->iso_code;
            }
        }

        if ($context instanceof Context && isset($context->currency) && isset($context->currency->iso_code)) {
            return (string) $context->currency->iso_code;
        }

        return '';
    }

    public static function products($cart)
    {
        if (!is_object($cart) || !method_exists($cart, 'getProducts')) {
            return [];
        }

        $products = $cart->getProducts();

        return is_array($products) ? $products : [];
    }

    public static function total($cart)
    {
        if (!is_object($cart) || !method_exists($cart, 'getOrderTotal')) {
            return 0.0;
        }

        try {
            return (float) $cart->getOrderTotal(true, Cart::BOTH);
        } catch (\Throwable $e) {
            // Cart total enrichment is non-authoritative. A partial CLI/context state,
            // extension error or cross-version TypeError must never break cart save.
            return 0.0;
        }
    }
}
