<?php
/**
 * CI-ONLY future 0.8.0 provider-egress policy.
 *
 * THIS IS NOT RELEASE CODE. All routes fail closed pending G3-G7 approval.
 * No Salesforce client, network activity, credentials or send endpoint.
 */
final class G3CandidateProviderGate
{
    public static function legacyOutboundReason($binding, $mode)
    {
        if (!is_array($binding)
            || !isset($binding['merchant'], $binding['provider'],
                $binding['source_namespace'], $binding['mode'], $binding['status'])) {
            return 'g3_provider_unbound';
        }
        if (!is_string($binding['source_namespace'])
            || !preg_match('/^[a-z][a-z0-9_]{5,31}$/D', $binding['source_namespace'])
            || $binding['mode'] !== 'test' || $binding['status'] !== 'blocked') {
            return 'g3_binding_integrity_failure';
        }
        if ($binding['merchant'] === 'ABH') {
            // ABH is Growth-only even if a legacy Engagement client is packaged.
            if ($binding['provider'] !== 'marketing_cloud_growth') {
                return 'g3_abh_provider_mismatch';
            }
            return 'g3_growth_delivery_not_approved';
        }
        if ($binding['provider'] !== 'marketing_cloud_engagement'
            && $binding['provider'] !== 'marketing_cloud_growth') {
            return 'g3_provider_unrecognized';
        }
        // An explicitly selected *other* merchant still needs tenant approval.
        // Mode=production is never, by itself, authorization.
        return 'g3_tenant_delivery_not_certified';
    }

    public static function prestashopOutboundReason()
    {
        if (!defined('_DB_PREFIX_') || !class_exists('Db')
            || !class_exists('Configuration')) {
            return 'g3_provider_unavailable';
        }
        try {
            $prefix = _DB_PREFIX_;
            if (!is_string($prefix) || !preg_match('/^[a-zA-Z0-9_]+$/D', $prefix)) {
                return 'g3_provider_unavailable';
            }
            $rows = Db::getInstance()->executeS(
                'SELECT source_namespace, merchant, provider, mode, status FROM `'
                . $prefix . 'g3_provider_binding_v1` LIMIT 2'
            );
            if (!is_array($rows) || count($rows) !== 1) {
                return 'g3_provider_unbound';
            }
            return self::legacyOutboundReason(
                $rows[0],
                (string) Configuration::get('PS_SFMC_MODE')
            );
        } catch (Throwable $e) {
            return 'g3_provider_unavailable';
        }
    }
}
