<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Provider;

use RuntimeException;

/**
 * Entry/egress gate for the real PrestaShop module.
 * An installed legacy 0.7.1 configuration is NOT proof of provider choice.
 * For ABH, Growth is the only eligible provider and remains Test-only.
 * This class cannot activate Growth, Data Cloud ingestion or campaigns.
 */
final class MerchantProviderGate
{
    const UNSELECTED = 'unselected';
    const GROWTH = 'marketing_cloud_growth';
    const ENGAGEMENT = 'marketing_cloud_engagement';

    public static function validate($merchant, $provider, $mode)
    {
        if (!is_string($provider) || !in_array($provider, [
            self::UNSELECTED, self::GROWTH, self::ENGAGEMENT
        ], true)) {
            throw new RuntimeException('provider_explicit_selection_required');
        }
        if (!is_string($mode) || !in_array($mode, ['test', 'production'], true)) {
            throw new RuntimeException('provider_mode_invalid');
        }
        if ($provider === self::UNSELECTED) {
            if ($merchant !== '' || $mode !== 'test') {
                throw new RuntimeException('provider_unselected_must_be_test');
            }
            return true;
        }
        if (!is_string($merchant) ||
            !preg_match('/^[A-Z][A-Z0-9_]{1,31}$/D', $merchant)) {
            throw new RuntimeException('provider_merchant_required');
        }
        if ($merchant === 'ABH' && $provider !== self::GROWTH) {
            throw new RuntimeException('provider_abh_growth_only');
        }
        if ($provider === self::GROWTH && $mode !== 'test') {
            throw new RuntimeException('provider_growth_not_authorized_for_production');
        }
        return true;
    }

    public static function legacyCaptureAllowed($merchant, $provider)
    {
        // An unconfigured module never silently assumes Engagement.
        return is_string($merchant)
            && preg_match('/^[A-Z][A-Z0-9_]{1,31}$/D', $merchant) === 1
            && $merchant !== 'ABH'
            && $provider === self::ENGAGEMENT;
    }

    public static function legacyDeliveryAllowed($merchant, $provider, $mode)
    {
        return $mode === 'production' &&
            self::legacyCaptureAllowed($merchant, $provider);
    }

    public static function growthTestSelected($merchant, $provider, $mode)
    {
        return is_string($merchant)
            && preg_match('/^[A-Z][A-Z0-9_]{1,31}$/D', $merchant) === 1
            && $provider === self::GROWTH
            && $mode === 'test';
    }

    private function __construct()
    {
    }
}
