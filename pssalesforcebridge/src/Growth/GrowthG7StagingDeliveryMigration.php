<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use RuntimeException;
use Throwable;

/**
 * Explicit, staging-only G7 schema step on the SAME G3 outbox.
 *
 * No scheduler, sender or token; Test + local capture OFF required for DDL.
 * Never called on install/upgrade. All diagnostics are generic and PII-free.
 * MySQL DDL implicitly commits: a failed ALTER requires manual inspection.
 */
final class GrowthG7StagingDeliveryMigration
{
    const APPROVE = 'PREPARE_G7_STAGING_OUTBOX_ONLY';
    const UNDO = 'ROLLBACK_G7_STAGING_OUTBOX_ONLY';

    private static $original = [
        'accepted_sequence','source_namespace','shop_id','entity_type',
        'entity_id','dedupe_fingerprint','original_event_type',
        'captured_at_utc','captured_at_precision','clock_source',
        'clock_sync_verified','source_modified_provenance','status'
    ];
    private static $extra = [
        'delivery_state','lease_token_hash','lease_until_epoch',
        'delivery_attempts','delivery_last_http'
    ];

    /** Read-only, safe to call from the Back Office (Test only). */
    public static function inspect($db,$version,$merchant,$namespace,$prefix)
    {
        self::guard($db,$merchant,$namespace,false);
        $plan=self::g3($version,$merchant,$namespace,$prefix);
        $engine=GrowthSqlEngineGate::inspectPrestaShop($db);
        if ($engine['check_constraints_enforced']!==true) {
            throw new RuntimeException('g7_delivery_engine_unverified');
        }
        foreach ($plan['tables'] as $table) {
            if (strtolower((string)$db->getValue(
                "SELECT ENGINE FROM information_schema.TABLES"
                . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table'",
                false
            ))!=='innodb') {
                throw new RuntimeException('g7_delivery_table_unverified');
            }
            if ((int)$db->getValue(
                "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS"
                . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table'"
                . " AND CONSTRAINT_TYPE='CHECK'",false
            )!==1) {
                throw new RuntimeException('g7_delivery_check_unverified');
            }
        }

        $binding=self::quoted($plan['tables']['binding']);
        if ((string)$db->getValue(
            "SELECT COUNT(*) FROM $binding"
            . " WHERE source_namespace='$namespace' AND merchant='$merchant'"
            . " AND prestashop_version='$version'"
            . " AND provider='marketing_cloud_growth'"
            . " AND mode='test' AND status='blocked'", false
        )!=='1') {
            throw new RuntimeException('g7_delivery_binding_unverified');
        }

        $outbox=$plan['tables']['outbox'];
        $rows=$db->executeS(
            "SELECT COLUMN_NAME,DATA_TYPE,IS_NULLABLE,COLUMN_DEFAULT"
            . " FROM information_schema.COLUMNS"
            . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$outbox'"
            . " ORDER BY ORDINAL_POSITION",true,false
        );
        if (!is_array($rows)) {
            throw new RuntimeException('g7_delivery_inventory_unverified');
        }
        $names=array_map(function($r) {
            return isset($r['COLUMN_NAME']) ? $r['COLUMN_NAME'] : null;
        },$rows);
        $base=$names===self::$original;
        $extended=$names===array_merge(self::$original,self::$extra);
        if (!$base && !$extended) {
            throw new RuntimeException('g7_delivery_partial_or_drift');
        }

        $index=$db->executeS(
            "SELECT COLUMN_NAME,SEQ_IN_INDEX FROM information_schema.STATISTICS"
            . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$outbox'"
            . " AND INDEX_NAME='idx_g7_lease_state' ORDER BY SEQ_IN_INDEX",
            true,false
        );
        if (!is_array($index)) {
            throw new RuntimeException('g7_delivery_index_unverified');
        }
        if ($base && count($index)!==0) {
            throw new RuntimeException('g7_delivery_partial_or_drift');
        }
        if ($extended) {
            if (count($index)!==2
                || $index[0]['COLUMN_NAME']!=='delivery_state'
                || $index[1]['COLUMN_NAME']!=='lease_until_epoch'
                || (int)$index[0]['SEQ_IN_INDEX']!==1
                || (int)$index[1]['SEQ_IN_INDEX']!==2) {
                throw new RuntimeException('g7_delivery_partial_or_drift');
            }
            $wanted=[
                'delivery_state'=>['varchar','NO','blocked'],
                'lease_token_hash'=>['char','YES',null],
                'lease_until_epoch'=>['bigint','YES',null],
                'delivery_attempts'=>['int','NO','0'],
                'delivery_last_http'=>['smallint','YES',null],
            ];
            foreach ($rows as $row) {
                $name=$row['COLUMN_NAME'];
                if (!isset($wanted[$name]))continue;
                $w=$wanted[$name];
                $default=$row['COLUMN_DEFAULT'];
                if (is_string($default)) {
                    $default=trim($default,"'");
                    // MariaDB may spell a NULL default as 'NULL' in metadata.
                    if (strtoupper($default)==='NULL')$default=null;
                }
                if (strtolower((string)$row['DATA_TYPE'])!==$w[0]
                    || strtoupper((string)$row['IS_NULLABLE'])!==$w[1]
                    || (string)$default!==(string)$w[2]) {
                    throw new RuntimeException('g7_delivery_partial_or_drift');
                }
            }
        }

        $table=self::quoted($outbox);
        if ((string)$db->getValue(
            "SELECT COUNT(*) FROM $table WHERE status <> 'blocked'",false
        )!=='0') {
            throw new RuntimeException('g7_delivery_original_guard_broken');
        }

        return [
            'status'=>$base?'original_g3_verified':'extended_g7_verified',
            'existing_events_preserved'=>true,
            'migration_required'=>$base,
            'schema_write_authorized'=>false,
            'outbound_worker_authorized'=>false,
            'marketing_sends_authorized'=>false,
            'data_cloud_ingestion_authorized'=>false,
        ];
    }

    /**
     * Explicit owner action, ONLY while capture is OFF.
     * The boolean is a user-entered "external backup exists" assertion, not
     * a promise that this module creates or verifies the actual backup.
     */
    public static function apply(
        $db,$version,$merchant,$namespace,$prefix,$phrase,$backupConfirmed
    ) {
        self::assertWriteGate($db,$merchant,$namespace,$phrase,$backupConfirmed,self::APPROVE);
        $g3=self::g3($version,$merchant,$namespace,$prefix);
        $plan=GrowthG7DeliverySchemaPlan::preview($prefix);
        $lock=hash('sha256','g7-schema:'.$namespace.':'.$prefix);
        if ((string)$db->getValue("SELECT GET_LOCK('$lock', 2)",false)!=='1') {
            throw new RuntimeException('g7_delivery_schema_busy');
        }
        try {
            $inventory=self::inspect($db,$version,$merchant,$namespace,$prefix);
            if ($inventory['status']==='extended_g7_verified') {
                return self::result('already_extended_verified');
            }
            if ($inventory['status']!=='original_g3_verified') {
                throw new RuntimeException('g7_delivery_schema_unverified');
            }
            if (!$db->execute($plan['upgrade_sql'])) {
                throw new RuntimeException('g7_delivery_ddl_requires_review');
            }
            $after=self::inspect($db,$version,$merchant,$namespace,$prefix);
            if ($after['status']!=='extended_g7_verified') {
                throw new RuntimeException('g7_delivery_postcheck_failed');
            }
            return self::result('extended_blocked_no_sends');
        } finally {
            $db->getValue("SELECT RELEASE_LOCK('$lock')",false);
        }
    }

    /**
     * Explicit, separate rollback; NEVER drop columns if any attempt
     * exists. Also refuses any unexpected metadata/drift.
     */
    public static function rollback(
        $db,$version,$merchant,$namespace,$prefix,$phrase,$backupConfirmed
    ) {
        self::assertWriteGate($db,$merchant,$namespace,$phrase,$backupConfirmed,self::UNDO);
        $plan=GrowthG7DeliverySchemaPlan::preview($prefix);
        $lock=hash('sha256','g7-schema:'.$namespace.':'.$prefix);
        if ((string)$db->getValue("SELECT GET_LOCK('$lock', 2)",false)!=='1') {
            throw new RuntimeException('g7_delivery_schema_busy');
        }
        try {
            $inventory=self::inspect($db,$version,$merchant,$namespace,$prefix);
            if ($inventory['status']==='original_g3_verified') {
                return self::result('already_original_verified');
            }
            if ($inventory['status']!=='extended_g7_verified') {
                throw new RuntimeException('g7_delivery_schema_unverified');
            }
            $count=$db->getValue($plan['rollback_nonempty_state_count_sql'],false);
            if (!is_numeric($count) || !ctype_digit((string)$count)) {
                throw new RuntimeException('g7_delivery_rollback_count_unverified');
            }
            $review=GrowthG7DeliverySchemaPlan::reviewRollback((int)$count);
            if ($review['status']!=='rollback_candidate_requires_owner_approval') {
                throw new RuntimeException('g7_delivery_history_prevents_rollback');
            }
            if (!$db->execute($plan['rollback_sql'])) {
                throw new RuntimeException('g7_delivery_rollback_ddl_review');
            }
            $after=self::inspect($db,$version,$merchant,$namespace,$prefix);
            if ($after['status']!=='original_g3_verified') {
                throw new RuntimeException('g7_delivery_rollback_postcheck_failed');
            }
            return self::result('rolled_back_no_sends');
        } finally {
            $db->getValue("SELECT RELEASE_LOCK('$lock')",false);
        }
    }

    /** Public contract for existing G3 bootstrap re-arm after extension. */
    public static function outboxColumnsAllowed($names)
    {
        return is_array($names)
            && ($names===self::$original
                || $names===array_merge(self::$original,self::$extra));
    }

    private static function result($status)
    {
        return [
            'status'=>$status,'customer_events_unchanged'=>true,
            'salesforce_requests_performed'=>false,
            'outbound_worker_authorized'=>false,
            'marketing_sends_authorized'=>false,
            'data_cloud_ingestion_authorized'=>false,
        ];
    }

    private static function assertWriteGate(
        $db,$merchant,$namespace,$phrase,$backupConfirmed,$expected
    ) {
        self::guard($db,$merchant,$namespace,true);
        if ($phrase!==$expected || $backupConfirmed!==true) {
            throw new RuntimeException('g7_delivery_owner_approval_required');
        }
    }

    private static function guard($db,$merchant,$namespace,$writing)
    {
        if (!class_exists('Configuration')
            || !is_object($db)
            || !is_callable([$db,'getValue'])
            || !is_callable([$db,'executeS'])
            || !is_callable([$db,'execute'])
            || (string)\Configuration::get('PS_SFMC_MODE')!=='test'
            || (string)\Configuration::get('PS_GROWTH_MERCHANT')!==$merchant
            || (string)\Configuration::get('PS_GROWTH_NAMESPACE')!==$namespace
            || !is_string($merchant)
            || !preg_match('/^[A-Z][A-Z0-9_]{1,31}$/D',$merchant)
            || !is_string($namespace)
            || !preg_match('/^[a-z][a-z0-9_]{5,31}$/D',$namespace)
            || ($writing && (string)\Configuration::get('PS_GROWTH_LOCAL_CAPTURE')!=='off')) {
            throw new RuntimeException('g7_delivery_test_gate_denied');
        }
    }

    private static function g3($version,$merchant,$namespace,$prefix)
    {
        return GrowthOutboxSchemaPlan::preview(
            $version,$merchant,'marketing_cloud_growth',$namespace,'test',$prefix
        );
    }

    private static function quoted($name)
    {
        if (!is_string($name)
            || !preg_match('/^[A-Za-z0-9_]{1,64}$/D',$name)) {
            throw new RuntimeException('g7_delivery_identifier_invalid');
        }
        return chr(96).$name.chr(96);
    }

    private function __construct() {}
}
