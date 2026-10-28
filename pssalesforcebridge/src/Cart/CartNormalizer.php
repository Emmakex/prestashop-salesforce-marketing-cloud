<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Cart;

final class CartNormalizer
{
    public static function fromCart($cart, $customer, array $products, $currencyIso, $shopId, $total)
    {
        $cartId = is_object($cart) && isset($cart->id) ? (int) $cart->id : 0;
        $customerId = is_object($customer) && isset($customer->id) ? (int) $customer->id : 0;
        $items = [];
        $productCount = 0;

        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }

            $productId = isset($product['id_product']) ? (int) $product['id_product'] : 0;
            $attributeId = isset($product['id_product_attribute']) ? (int) $product['id_product_attribute'] : 0;
            $customizationId = isset($product['id_customization']) ? (int) $product['id_customization'] : 0;
            $quantity = isset($product['cart_quantity']) ? (int) $product['cart_quantity'] : (isset($product['quantity']) ? (int) $product['quantity'] : 0);
            $productCount += max(0, $quantity);

            $unitPrice = isset($product['price_wt']) ? (float) $product['price_wt'] : (isset($product['price']) ? (float) $product['price'] : 0.0);
            $lineTotal = isset($product['total_wt']) ? (float) $product['total_wt'] : ($unitPrice * max(0, $quantity));

            $items[] = [
                'line_key' => $productId . ':' . $attributeId . ':' . $customizationId,
                'product_id' => $productId,
                'product_attribute_id' => $attributeId,
                'customization_id' => $customizationId,
                'sku' => isset($product['reference']) ? (string) $product['reference'] : '',
                'name' => isset($product['name']) ? (string) $product['name'] : '',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ];
        }

        $lastActivity = is_object($cart) && isset($cart->date_upd) && $cart->date_upd !== ''
            ? (string) $cart->date_upd
            : date('Y-m-d H:i:s');

        return [
            'cart_key' => 'ps_' . (int) $shopId . '_' . $cartId,
            'cart_id' => $cartId,
            'shop_id' => (int) $shopId,
            'customer_id' => $customerId,
            'contact_key' => $customerId > 0 ? 'ps_' . (int) $shopId . '_' . $customerId : '',
            'email' => is_object($customer) && isset($customer->email) ? trim((string) $customer->email) : '',
            'firstname' => is_object($customer) && isset($customer->firstname) ? (string) $customer->firstname : '',
            'lastname' => is_object($customer) && isset($customer->lastname) ? (string) $customer->lastname : '',
            'newsletter' => is_object($customer) && !empty($customer->newsletter),
            'optin' => is_object($customer) && !empty($customer->optin),
            'customer_active' => is_object($customer) && (!isset($customer->active) || !empty($customer->active)),
            'is_guest' => is_object($customer) && !empty($customer->is_guest),
            'currency' => (string) $currencyIso,
            'total' => (float) $total,
            'product_count' => $productCount,
            'item_count' => count($items),
            'last_activity' => $lastActivity,
            'items' => $items,
        ];
    }
}
