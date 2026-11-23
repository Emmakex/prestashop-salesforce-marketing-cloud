<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

/**
 * Deterministic, server-keyed Back Office form CSRF, scoped to shop+employee.
 *
 * Keep the PrestaShop AdminModules token in the form action (the controller
 * still enforces it). Do NOT derive this additional form HMAC from the
 * controller token: in PS 9 its value can change across route handling.
 *
 * No storage, no cookie changes, no tokens in logs or exception messages.
 */
final class GrowthStableBoCsrf
{
    public static function issue($serverSecret, $shop, $employee)
    {
        if (!is_string($serverSecret) || strlen($serverSecret)<24
            || !is_int($shop) || $shop<1
            || !is_int($employee) || $employee<1) {
            return '';
        }
        return hash_hmac(
            'sha256',
            'pssfmc-bo-form-v2:shop:'.$shop.':employee:'.$employee,
            $serverSecret
        );
    }

    public static function verify($token, $serverSecret, $shop, $employee)
    {
        if (!is_string($token)
            || preg_match('/^[a-f0-9]{64}$/D',$token)!==1) {
            return false;
        }
        $expected=self::issue($serverSecret,$shop,$employee);
        return $expected!=='' && hash_equals($expected,$token);
    }

    private function __construct(){}
}
