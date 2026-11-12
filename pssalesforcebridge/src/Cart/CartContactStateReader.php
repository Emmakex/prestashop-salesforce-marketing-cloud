<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Cart;

/**
 * Re-read the source PrestaShop customer immediately before a Journey send.
 * No HTTP, Salesforce, logging, or mutation; null is a fail-closed result.
 * This does not replace a separately approved external suppression check.
 */
final class CartContactStateReader
{
    public static function read($shopId, $customerId)
    {
        if ((int) $shopId <= 0 || (int) $customerId <= 0) {
            return null;
        }

        try {
            $customer = new \Customer((int) $customerId);
        } catch (\Throwable $exception) {
            return null;
        }

        if (!is_object($customer) || !isset($customer->id)
            || (int) $customer->id !== (int) $customerId
            || !isset($customer->id_shop) || (int) $customer->id_shop !== (int) $shopId) {
            return null;
        }

        $email = isset($customer->email) ? trim((string) $customer->email) : '';

        return [
            'customer_id' => (int) $customer->id,
            'email' => $email,
            'newsletter' => !empty($customer->newsletter),
            'optin' => !empty($customer->optin),
            'customer_active' => !empty($customer->active),
            'customer_deleted' => !empty($customer->deleted),
        ];
    }
}
