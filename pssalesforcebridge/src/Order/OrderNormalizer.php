<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Order;

final class OrderNormalizer
{
    public static function fromOrder($order, $customer, $cart, $currencyIso, $shopId, $stateId = null, $paid = null)
    {
        $orderId = is_object($order) && isset($order->id) ? (int) $order->id : 0;
        $cartId = 0;
        if (is_object($order) && isset($order->id_cart)) {
            $cartId = (int) $order->id_cart;
        } elseif (is_object($cart) && isset($cart->id)) {
            $cartId = (int) $cart->id;
        }

        $customerId = 0;
        if (is_object($order) && isset($order->id_customer)) {
            $customerId = (int) $order->id_customer;
        } elseif (is_object($customer) && isset($customer->id)) {
            $customerId = (int) $customer->id;
        }

        $shopId = (int) $shopId;
        $resolvedStateId = $stateId !== null
            ? (int) $stateId
            : (is_object($order) && isset($order->current_state) ? (int) $order->current_state : 0);
        $resolvedPaid = $paid !== null
            ? (bool) $paid
            : (is_object($order) && isset($order->valid) ? (bool) $order->valid : false);

        return [
            'order_key' => $shopId > 0 && $orderId > 0 ? 'ps_' . $shopId . '_' . $orderId : '',
            'order_id' => $orderId,
            'shop_id' => $shopId,
            'cart_id' => $cartId,
            'customer_id' => $customerId,
            'contact_key' => $shopId > 0 && $customerId > 0 ? 'ps_' . $shopId . '_' . $customerId : '',
            'reference' => is_object($order) && isset($order->reference) ? (string) $order->reference : '',
            'currency_id' => is_object($order) && isset($order->id_currency) ? (int) $order->id_currency : 0,
            'currency' => (string) $currencyIso,
            'total_paid' => self::number($order, 'total_paid_tax_incl', 'total_paid'),
            'total_products' => self::number($order, 'total_products_wt', 'total_products'),
            'total_shipping' => self::number($order, 'total_shipping_tax_incl', 'total_shipping'),
            'total_discounts' => self::number($order, 'total_discounts_tax_incl', 'total_discounts'),
            'payment' => is_object($order) && isset($order->payment) ? (string) $order->payment : '',
            'module' => is_object($order) && isset($order->module) ? (string) $order->module : '',
            'state_id' => $resolvedStateId,
            'paid' => $resolvedPaid,
            'valid' => is_object($order) && isset($order->valid) ? (bool) $order->valid : false,
            'date_add' => is_object($order) && isset($order->date_add) ? (string) $order->date_add : '',
            'date_upd' => is_object($order) && isset($order->date_upd) ? (string) $order->date_upd : '',
        ];
    }

    private static function number($object, $preferred, $fallback)
    {
        if (is_object($object) && isset($object->{$preferred})) {
            return (float) $object->{$preferred};
        }

        if (is_object($object) && isset($object->{$fallback})) {
            return (float) $object->{$fallback};
        }

        return 0.0;
    }

    private function __construct()
    {
    }
}
