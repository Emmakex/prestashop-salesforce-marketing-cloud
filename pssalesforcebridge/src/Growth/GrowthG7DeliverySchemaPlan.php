<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use RuntimeException;

/**
 * G7 append-only candidate migration of the EXISTING G3 outbox.
 *
 * PURE REVIEW: no DB call, no HTTP, no module install hook, no cron.
 * The only SQL execution is in ephemeral g3_ci integration tests.
 * The shop's status='blocked' CHECK remains untouched, and new columns
 * default delivery_state='blocked'. Nothing becomes dispatch-ready.
 */
final class GrowthG7DeliverySchemaPlan
{
    const INDEX = 'idx_g7_lease_state';

    public static function preview($prefix)
    {
        if (!is_string($prefix)
            || !preg_match('/^[A-Za-z0-9_]{1,32}$/D',$prefix)) {
            throw new RuntimeException('g7_delivery_prefix_invalid');
        }
        $table=$prefix.GrowthOutboxSchemaPlan::OUTBOX;
        if (strlen($table)>64) {
            throw new RuntimeException('g7_delivery_table_too_long');
        }
        $name=chr(96).$table.chr(96);
        $index=chr(96).self::INDEX.chr(96);
        return [
            'status'=>'g7_additive_schema_review_only',
            'table'=>$table,
            'upgrade_sql'=>"ALTER TABLE $name "
                . "ADD COLUMN delivery_state VARCHAR(16) NOT NULL "
                . "DEFAULT 'blocked',"
                . "ADD COLUMN lease_token_hash CHAR(64) CHARACTER SET ascii "
                . "COLLATE ascii_bin NULL,"
                . "ADD COLUMN lease_until_epoch BIGINT UNSIGNED NULL,"
                . "ADD COLUMN delivery_attempts INT UNSIGNED NOT NULL DEFAULT 0,"
                . "ADD COLUMN delivery_last_http SMALLINT UNSIGNED NULL,"
                . "ADD KEY $index (delivery_state,lease_until_epoch)",
            // NOT executable on any merchant database without a separate
            // approved maintenance window and explicit preflight. Never
            // erase evidence of a claimed, reconciled or attempted event.
            'rollback_sql'=>"ALTER TABLE $name "
                . "DROP KEY $index,"
                . "DROP COLUMN delivery_last_http,"
                . "DROP COLUMN delivery_attempts,"
                . "DROP COLUMN lease_until_epoch,"
                . "DROP COLUMN lease_token_hash,"
                . "DROP COLUMN delivery_state",
            'rollback_nonempty_state_count_sql'=>"SELECT COUNT(*) "
                . "FROM $name WHERE delivery_state <> 'blocked'"
                . " OR delivery_attempts <> 0"
                . " OR lease_token_hash IS NOT NULL"
                . " OR lease_until_epoch IS NOT NULL"
                . " OR delivery_last_http IS NOT NULL",
            'g3_original_blocked_constraint_preserved'=>true,
            'new_rows_delivery_state'=>'blocked',
            'merchant_upgrade_authorized'=>false,
            'automatic_rollback_authorized'=>false,
            'dispatch_authorized'=>false,
            'marketing_sends_authorized'=>false,
            'data_cloud_ingestion_authorized'=>false,
        ];
    }

    /**
     * A rollback may be considered only when no state/attempt survives.
     * This never executes rollback and doesn't replace a backup or approval.
     */
    public static function reviewRollback($nonemptyStateCount)
    {
        if (!is_int($nonemptyStateCount) || $nonemptyStateCount<0) {
            throw new RuntimeException('g7_delivery_rollback_count_invalid');
        }
        return [
            'status'=>$nonemptyStateCount===0
                ?'rollback_candidate_requires_owner_approval'
                :'rollback_blocked_retains_delivery_evidence',
            'owner_approval_required'=>true,
            'backup_required'=>true,
            'automatic_rollback_authorized'=>false,
            'rollback_executed'=>false,
            'outbound_worker_authorized'=>false,
        ];
    }

    private function __construct() {}
}
