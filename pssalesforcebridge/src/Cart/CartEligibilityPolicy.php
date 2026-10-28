<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Cart;

final class CartEligibilityPolicy
{
    const MODE_MARKETING_OPTIN = 'marketing_optin';
    const MODE_KNOWN_CONTACT = 'known_contact';

    public function evaluate(array $cart, $mode)
    {
        if (empty($cart['product_count'])) {
            return ['eligible' => false, 'reason' => 'empty_cart'];
        }

        if (empty($cart['customer_id']) || empty($cart['contact_key'])) {
            return ['eligible' => false, 'reason' => 'missing_contact'];
        }

        $email = isset($cart['email']) ? trim((string) $cart['email']) : '';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['eligible' => false, 'reason' => 'missing_email'];
        }

        if (empty($cart['customer_active'])) {
            return ['eligible' => false, 'reason' => 'customer_inactive'];
        }

        if ((string) $mode === self::MODE_KNOWN_CONTACT) {
            return ['eligible' => true, 'reason' => 'known_contact'];
        }

        if (!empty($cart['newsletter']) || !empty($cart['optin'])) {
            return ['eligible' => true, 'reason' => 'marketing_optin'];
        }

        return ['eligible' => false, 'reason' => 'marketing_optin_required'];
    }
}
