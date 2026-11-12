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

        if (!empty($cart['customer_deleted'])) {
            return ['eligible' => false, 'reason' => 'customer_deleted'];
        }

        // known_contact is an explicit alternative requiring the merchant's
        // independently documented legal basis and suppression review.
        if ((string) $mode === self::MODE_KNOWN_CONTACT) {
            return ['eligible' => true, 'reason' => 'known_contact'];
        }

        if ((string) $mode !== self::MODE_MARKETING_OPTIN) {
            return ['eligible' => false, 'reason' => 'unsupported_consent_mode'];
        }

        // First-party newsletter only. PrestaShop optin represents offers
        // from partners and cannot independently authorize our cart recovery.
        // This is technical eligibility, NOT proof of legal permission.
        if (!empty($cart['newsletter'])) {
            return ['eligible' => true, 'reason' => 'newsletter_subscribed'];
        }

        return ['eligible' => false, 'reason' => 'newsletter_required'];
    }
}
