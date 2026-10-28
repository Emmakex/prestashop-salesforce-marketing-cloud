<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Compatibility;

final class Platform
{
    const MIN_PRESTASHOP = '1.7.8.0';
    const MAX_PRESTASHOP = '9.99.99';

    public static function version()
    {
        return defined('_PS_VERSION_') ? (string) _PS_VERSION_ : '0.0.0';
    }

    public static function isSupported()
    {
        $version = self::version();

        return version_compare($version, self::MIN_PRESTASHOP, '>=')
            && version_compare($version, self::MAX_PRESTASHOP, '<=');
    }

    public static function family()
    {
        $version = self::version();

        if (version_compare($version, '9.0.0', '>=')) {
            return '9';
        }

        if (version_compare($version, '8.0.0', '>=')) {
            return '8';
        }

        if (version_compare($version, '1.7.8.0', '>=')) {
            return '1.7.8';
        }

        return 'unsupported';
    }

    private function __construct()
    {
    }
}
