<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Compatibility;

final class OrderHookAdapter
{
    public static function orderFromParams(array $params)
    {
        return isset($params['order']) && is_object($params['order']) ? $params['order'] : null;
    }

    public static function customerFromParams(array $params)
    {
        return isset($params['customer']) && is_object($params['customer']) ? $params['customer'] : null;
    }

    public static function cartFromParams(array $params)
    {
        return isset($params['cart']) && is_object($params['cart']) ? $params['cart'] : null;
    }

    public static function shopId($order, $context = null)
    {
        if (is_object($order) && isset($order->id_shop) && (int) $order->id_shop > 0) {
            return (int) $order->id_shop;
        }

        if (is_object($context) && isset($context->shop) && is_object($context->shop) && isset($context->shop->id)) {
            return (int) $context->shop->id;
        }

        return 0;
    }

    public static function currencyIso(array $params, $order = null)
    {
        if (isset($params['currency']) && is_object($params['currency']) && !empty($params['currency']->iso_code)) {
            return (string) $params['currency']->iso_code;
        }

        if (is_object($order) && isset($order->id_currency) && method_exists('Currency', 'getIsoCodeById')) {
            $iso = \Currency::getIsoCodeById((int) $order->id_currency);
            return is_string($iso) ? $iso : '';
        }

        return '';
    }

    public static function statusContext(array $params)
    {
        $orderId = isset($params['id_order']) ? (int) $params['id_order'] : 0;
        if ($orderId <= 0 || !class_exists('Order')) {
            return null;
        }

        $order = new \Order($orderId);
        if (!is_object($order) || !isset($order->id) || (int) $order->id <= 0) {
            return null;
        }

        $customer = null;
        if (isset($order->id_customer) && (int) $order->id_customer > 0 && class_exists('Customer')) {
            $customer = new \Customer((int) $order->id_customer);
        }

        $cart = null;
        if (isset($order->id_cart) && (int) $order->id_cart > 0 && class_exists('Cart')) {
            $cart = new \Cart((int) $order->id_cart);
        }

        $status = isset($params['newOrderStatus']) && is_object($params['newOrderStatus']) ? $params['newOrderStatus'] : null;

        return [
            'order' => $order,
            'customer' => $customer,
            'cart' => $cart,
            'status' => $status,
        ];
    }

    public static function eventTypeForStatus($status)
    {
        if (is_object($status) && !empty($status->paid)) {
            return 'ORDER_PAID';
        }

        if (is_object($status) && isset($status->id) && class_exists('Configuration')) {
            $cancelledId = (int) \Configuration::get('PS_OS_CANCELED');
            if ($cancelledId > 0 && (int) $status->id === $cancelledId) {
                return 'ORDER_CANCELLED';
            }
        }

        return 'ORDER_UPDATED';
    }

    private function __construct()
    {
    }
}
