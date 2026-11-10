<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud;

use RuntimeException;

/**
 * Pure, non-authorizing review of per-shop timezone and SQL column metadata.
 * Even a fully green technical snapshot NEVER authorizes upload or ingestion.
 */
final class InstallationReadinessInspector
{
    public static function inspect(array $shopIds, $timezoneReader, $metadataReader)
    {
        if (!is_callable($timezoneReader) || !is_callable($metadataReader)) {
            throw new RuntimeException('data_cloud_readiness_reader_missing');
        }

        $shops = [];
        $ids = [];
        foreach ($shopIds as $id) {
            if ((!is_int($id) && (!is_string($id) || !ctype_digit($id)))
                || (int) $id < 1) {
                continue;
            }
            $ids[(int) $id] = (int) $id;
        }
        ksort($ids);
        foreach ($ids as $id) {
            try {
                $zone = ShopTimezoneResolver::resolve($id, $timezoneReader);
                $shops[] = [
                    'shop_id' => $id,
                    'timezone' => $zone,
                    'status' => 'valid',
                ];
            } catch (\Throwable $e) {
                // No raw error messages or configuration values in a BO panel.
                $shops[] = [
                    'shop_id' => $id,
                    'timezone' => '',
                    'status' => 'missing_or_invalid',
                ];
            }
        }

        try {
            $raw = call_user_func($metadataReader);
        } catch (\Throwable $e) {
            $raw = [];
        }
        if (!is_array($raw)) {
            $raw = [];
        }

        $columns = [];
        $metadataComplete = true;
        foreach (['customer', 'orders'] as $object) {
            $columns[$object] = [];
            foreach (['date_add', 'date_upd'] as $column) {
                $kind = isset($raw[$object][$column])
                    ? strtolower((string) $raw[$object][$column])
                    : '';
                if ($kind !== 'datetime' && $kind !== 'timestamp') {
                    $kind = 'unavailable';
                    $metadataComplete = false;
                }
                $columns[$object][$column] = $kind;
            }
        }
        $zonesComplete = !empty($shops);
        foreach ($shops as $shop) {
            if ($shop['status'] !== 'valid') {
                $zonesComplete = false;
            }
        }
        $checks = [
            'shops_identified' => !empty($shops),
            'shop_timezones_valid' => $zonesComplete,
            'date_columns_inspected' => $metadataComplete,
        ];
        $missing = [];
        foreach ($checks as $name => $passed) {
            if (!$passed) {
                $missing[] = $name;
            }
        }

        return [
            // No mutable setting, tenant fact or checked box can set this
            // to true without a separate explicit approval workflow.
            'status' => 'blocked',
            'schema_upload_allowed' => false,
            'record_ingestion_allowed' => false,
            'marketing_sends_allowed' => false,
            'checks' => $checks,
            'shops' => $shops,
            'date_columns' => $columns,
            'technical_blockers' => $missing,
            'approval_blockers' => [
                'immutable_installation_namespace_not_registered',
                'historical_datetime_provenance_not_approved',
                'gdpr_consent_retention_erasure_not_approved',
                'field_dictionary_and_model_mapping_not_approved',
                'record_modified_refresh_strategy_not_approved',
                'pst_schema_and_streams_not_approved',
                'marketing_eligibility_not_approved',
            ],
        ];
    }
}
