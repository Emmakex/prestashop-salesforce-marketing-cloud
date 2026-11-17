<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use RuntimeException;

/**
 * Explicit staging-only setup of the SAME Growth G3 three-table schema.
 * Never runs on module install/upgrade, never drops/changes legacy tables.
 * The owner must first save a unique merchant/namespace identity and enter
 * the exact local-only confirmation in the Back Office.
 */
final class GrowthStagingSchemaBootstrap
{
    const CONFIRM = 'ENABLE_STAGING_LOCAL_CAPTURE_ONLY';

    public static function install($db, $version, $merchant, $namespace, $prefix, $confirm)
    {
        if ($confirm !== self::CONFIRM
            || !is_object($db)
            || !is_callable([$db, 'getValue'])
            || !is_callable([$db, 'execute'])
            || !is_callable([$db, 'executeS'])
            || (string) \Configuration::get('PS_SFMC_MODE') !== 'test'
            || (string) \Configuration::get('PS_GROWTH_MERCHANT') !== $merchant
            || (string) \Configuration::get('PS_GROWTH_NAMESPACE') !== $namespace
            || (string) \Configuration::get('PS_GROWTH_LOCAL_CAPTURE') === 'staging_armed') {
            throw new RuntimeException('g7_capture_setup_not_approved');
        }

        $engine = GrowthSqlEngineGate::inspectPrestaShop($db);
        if ($engine['check_constraints_enforced'] !== true) {
            throw new RuntimeException('g7_capture_setup_sql_check_required');
        }
        $plan = GrowthOutboxSchemaPlan::preview(
            $version, $merchant, 'marketing_cloud_growth', $namespace,
            'test', $prefix
        );
        $existing = [];
        foreach ($plan['tables'] as $table) {
            if ((string)$db->getValue(
                "SELECT COUNT(*) FROM information_schema.TABLES "
                . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='"
                . $table . "'", false
            ) === '1') {
                $existing[] = $table;
            }
        }
        $state=GrowthOutboxSchemaPlan::inventoryState($plan,$existing);
        if ($state !== 'absent_review_only'
            && $state !== 'present_requires_schema_integrity_review') {
            throw new RuntimeException('g7_capture_setup_partial_schema');
        }
        if ($state === 'absent_review_only') {
            // DDL implicitly commits in MySQL. On partial failure stop and
            // require human reconciliation; NEVER silently drop prior tables.
            foreach ($plan['create_sql'] as $ddl) {
                if (!$db->execute($ddl)) {
                    throw new RuntimeException('g7_capture_setup_ddl_manual_review');
                }
            }
            $table=self::q($plan['tables']['binding']);
            $statement = 'INSERT INTO ' . $table
                . ' (source_namespace,merchant,provider,prestashop_version,mode,status)'
                . " VALUES ('" . $namespace . "','" . $merchant . "',"
                . "'marketing_cloud_growth','" . $version . "','test','blocked')";
            if (!$db->execute($statement)) {
                throw new RuntimeException('g7_capture_setup_binding_failed');
            }
        }

        self::verify($db, $plan);
        return [
            'status'=>'ready_for_staging_local_capture',
            'growth_outbox_schema'=>'same_g3_three_table_schema',
            'schema_present'=>true,
            'outbound_authorized'=>false,
            'customer_pii_exported'=>false,
            'marketing_sends_authorized'=>false,
        ];
    }

    private static function verify($db, array $plan)
    {
        $expected = [
            'binding'=>[
                'source_namespace','merchant','provider','prestashop_version',
                'mode','status'
            ],
            'outbox'=>[
                'accepted_sequence','source_namespace','shop_id','entity_type',
                'entity_id','dedupe_fingerprint','original_event_type',
                'captured_at_utc','captured_at_precision','clock_source',
                'clock_sync_verified','source_modified_provenance','status'
            ],
            'ledger'=>[
                'source_namespace','shop_id','entity_type','entity_id',
                'latest_accepted_sequence','status'
            ],
        ];
        foreach ($plan['tables'] as $role=>$table) {
            $rows=$db->executeS(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS "
                . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='"
                . $table . "' ORDER BY ORDINAL_POSITION", true, false
            );
            if (!is_array($rows)
                || array_map(function($r){ return $r['COLUMN_NAME']; },$rows)
                    !== $expected[$role]) {
                throw new RuntimeException('g7_capture_setup_schema_drift');
            }
            $engine = (string)$db->getValue(
                "SELECT ENGINE FROM information_schema.TABLES "
                . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='"
                . $table . "'", false
            );
            if (strtolower($engine)!=='innodb') {
                throw new RuntimeException('g7_capture_setup_nontransactional');
            }
            $checks = (int)$db->getValue(
                "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS "
                . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='"
                . $table . "' AND CONSTRAINT_TYPE='CHECK'", false
            );
            if ($checks!==1) {
                throw new RuntimeException('g7_capture_setup_constraint_missing');
            }
        }
        $table=self::q($plan['tables']['binding']);
        $v=$plan['binding_values'];
        if ((string)$db->getValue('SELECT COUNT(*) FROM '.$table
            . " WHERE source_namespace='".$v[0]."'"
            . " AND merchant='".$v[1]."'"
            . " AND provider='marketing_cloud_growth'"
            . " AND prestashop_version='".$v[3]."'"
            . " AND mode='test' AND status='blocked'",false)!=='1') {
            throw new RuntimeException('g7_capture_setup_namespace_conflict');
        }
    }

    private static function q($name)
    {
        if (!is_string($name) || !preg_match('/^[a-zA-Z0-9_]{1,64}$/D',$name)) {
            throw new RuntimeException('g7_capture_setup_identifier_invalid');
        }
        return chr(96).$name.chr(96);
    }

    private function __construct(){}
}
