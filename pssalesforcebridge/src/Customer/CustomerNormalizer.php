<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Customer;

final class CustomerNormalizer
{
    public static function fromCustomer($customer, $shopId)
    {
        $customerId = isset($customer->id) ? (int) $customer->id : 0;
        $shopId = (int) $shopId;

        return [
            'contact_key' => self::contactKey($shopId, $customerId),
            'customer_id' => $customerId,
            'shop_id' => $shopId,
            'email' => isset($customer->email) ? (string) $customer->email : '',
            'firstname' => isset($customer->firstname) ? (string) $customer->firstname : '',
            'lastname' => isset($customer->lastname) ? (string) $customer->lastname : '',
            'newsletter' => !empty($customer->newsletter) ? 1 : 0,
            'optin' => !empty($customer->optin) ? 1 : 0,
            'active' => !isset($customer->active) || (bool) $customer->active ? 1 : 0,
            'language_id' => isset($customer->id_lang) ? (int) $customer->id_lang : 0,
            'date_add' => isset($customer->date_add) ? (string) $customer->date_add : '',
            'date_upd' => isset($customer->date_upd) ? (string) $customer->date_upd : '',
        ];
    }

    public static function contactKey($shopId, $customerId)
    {
        if ((int) $shopId <= 0 || (int) $customerId <= 0) {
            return '';
        }

        return 'ps_' . (int) $shopId . '_' . (int) $customerId;
    }

    private function __construct()
    {
    }
}
