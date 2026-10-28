<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Recovery;

use Cart;
use RuntimeException;
use Validate;

final class CartSessionRestorer
{
    public function restore($sourceCartId, $shopId, $tokenCustomerId, $context)
    {
        $source = new Cart((int) $sourceCartId);
        if (!Validate::isLoadedObject($source)) {
            throw new RuntimeException('The source cart no longer exists.');
        }

        if ((int) $source->id_shop !== (int) $shopId) {
            throw new RuntimeException('The recovery cart belongs to another shop.');
        }

        $products = $source->getProducts();
        if (!is_array($products) || empty($products)) {
            throw new RuntimeException('The source cart no longer contains recoverable products.');
        }

        $duplicated = $source->duplicate();
        if (!is_array($duplicated) || empty($duplicated['success']) || empty($duplicated['cart'])) {
            throw new RuntimeException('PrestaShop was unable to duplicate the recovery cart.');
        }

        $cart = $duplicated['cart'];
        if (!Validate::isLoadedObject($cart)) {
            throw new RuntimeException('The duplicated recovery cart is invalid.');
        }

        $sameAuthenticatedCustomer = false;
        if (is_object($context) && isset($context->customer) && Validate::isLoadedObject($context->customer)) {
            $sameAuthenticatedCustomer = (int) $context->customer->id > 0
                && (int) $context->customer->id === (int) $tokenCustomerId;
        }

        $cart->id_address_delivery = 0;
        $cart->id_address_invoice = 0;
        $cart->id_carrier = 0;
        if (property_exists($cart, 'delivery_option')) {
            $cart->delivery_option = '';
        }

        if ($sameAuthenticatedCustomer) {
            $cart->id_customer = (int) $context->customer->id;
            $cart->secure_key = isset($context->customer->secure_key) ? (string) $context->customer->secure_key : '';
        } else {
            $cart->id_customer = 0;
            $cart->secure_key = '';
        }

        if (is_object($context) && isset($context->cookie) && isset($context->cookie->id_guest)) {
            $cart->id_guest = (int) $context->cookie->id_guest;
        } elseif (!$sameAuthenticatedCustomer) {
            $cart->id_guest = 0;
        }

        if (!$cart->update()) {
            throw new RuntimeException('Unable to sanitize the recovered cart.');
        }

        if (!is_object($context) || !isset($context->cookie)) {
            throw new RuntimeException('PrestaShop front-office context is unavailable.');
        }

        $context->cart = $cart;
        $context->cookie->id_cart = (int) $cart->id;

        if (method_exists($context->cookie, 'write')) {
            $context->cookie->write();
        }

        return (int) $cart->id;
    }
}
