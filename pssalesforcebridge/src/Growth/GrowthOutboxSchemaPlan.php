<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use Emmakex\PrestaShopSalesforceMarketingCloud\Provider\ExplicitProviderRouter;
use RuntimeException;

/**
 * Inert Growth DDL preview, NOT a module install/upgrade or SQL executor.
 * Only the g3_ci integration tests may execute the emitted statements.
 * Production needs G3 schema/retention/namespace and separate migration approval.
 */
final class GrowthOutboxSchemaPlan
{
    const BINDING = 'growth_provider_binding_v1';
    const OUTBOX = 'growth_outbox_v1';
    const LEDGER = 'growth_entity_ledger_v1';

    public static function preview($version, $merchant, $provider, $namespace, $mode, $prefix)
    {
        new ExplicitProviderRouter($version, $merchant, $provider, $namespace, $mode);
        if ($provider !== ExplicitProviderRouter::GROWTH) {
            throw new RuntimeException('growth_schema_growth_only');
        }
        if (!is_string($prefix) || !preg_match('/^[A-Za-z0-9_]{1,32}$/D', $prefix)) {
            throw new RuntimeException('growth_schema_prefix_invalid');
        }
        $binding = $prefix . self::BINDING;
        $outbox = $prefix . self::OUTBOX;
        $ledger = $prefix . self::LEDGER;
        foreach ([$binding, $outbox, $ledger] as $name) {
            if (strlen($name) > 64) {
                throw new RuntimeException('growth_schema_table_too_long');
            }
        }
        // MySQL FK names can collide globally within a schema. Distinct,
        // bounded identifiers for different PrestaShop installation prefixes.
        $tag = substr(hash('sha256', $prefix), 0, 14);
        $cb = 'ck_grow_bind_' . $tag;
        $co = 'ck_grow_out_' . $tag;
        $cl = 'ck_grow_led_' . $tag;
        $fn = 'fk_grow_ns_' . $tag;
        $fs = 'fk_grow_seq_' . $tag;
        $sql = [
            "CREATE TABLE ~{$binding}~ (
                ~source_namespace~ VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                ~merchant~ VARCHAR(32) CHARACTER SET ascii NOT NULL,
                ~provider~ VARCHAR(40) CHARACTER SET ascii NOT NULL,
                ~prestashop_version~ VARCHAR(20) CHARACTER SET ascii NOT NULL,
                ~mode~ VARCHAR(8) CHARACTER SET ascii NOT NULL DEFAULT 'test',
                ~status~ VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT 'blocked',
                PRIMARY KEY (~source_namespace~),
                CONSTRAINT ~{$cb}~ CHECK (
                    ~provider~ = 'marketing_cloud_growth'
                    AND ~mode~ = 'test' AND ~status~ = 'blocked'
                )
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE ~{$outbox}~ (
                ~accepted_sequence~ BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                ~source_namespace~ VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                ~shop_id~ INT UNSIGNED NOT NULL,
                ~entity_type~ VARCHAR(16) CHARACTER SET ascii NOT NULL,
                ~entity_id~ BIGINT UNSIGNED NOT NULL,
                ~dedupe_fingerprint~ CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                ~original_event_type~ VARCHAR(32) CHARACTER SET ascii NOT NULL,
                ~captured_at_utc~ CHAR(24) CHARACTER SET ascii NOT NULL,
                ~captured_at_precision~ VARCHAR(12) CHARACTER SET ascii NOT NULL,
                ~clock_source~ VARCHAR(40) CHARACTER SET ascii NOT NULL,
                ~clock_sync_verified~ TINYINT(1) NOT NULL DEFAULT 0,
                ~source_modified_provenance~ VARCHAR(32) CHARACTER SET ascii NOT NULL,
                ~status~ VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT 'blocked',
                PRIMARY KEY (~accepted_sequence~),
                UNIQUE KEY ~uq_growth_event_v1~ (~source_namespace~, ~dedupe_fingerprint~),
                KEY ~idx_growth_entity_v1~ (~source_namespace~, ~shop_id~, ~entity_type~, ~entity_id~),
                CONSTRAINT ~{$co}~ CHECK (~status~ = 'blocked'),
                CONSTRAINT ~{$fn}~ FOREIGN KEY (~source_namespace~)
                    REFERENCES ~{$binding}~ (~source_namespace~) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE ~{$ledger}~ (
                ~source_namespace~ VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                ~shop_id~ INT UNSIGNED NOT NULL,
                ~entity_type~ VARCHAR(16) CHARACTER SET ascii NOT NULL,
                ~entity_id~ BIGINT UNSIGNED NOT NULL,
                ~latest_accepted_sequence~ BIGINT UNSIGNED NOT NULL,
                ~status~ VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT 'blocked',
                PRIMARY KEY (~source_namespace~, ~shop_id~, ~entity_type~, ~entity_id~),
                KEY ~idx_growth_ledger_sequence_v1~ (~latest_accepted_sequence~),
                CONSTRAINT ~{$cl}~ CHECK (~status~ = 'blocked'),
                CONSTRAINT ~{$fs}~ FOREIGN KEY (~latest_accepted_sequence~)
                    REFERENCES ~{$outbox}~ (~accepted_sequence~) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
        // Prefix is constrained above; identifiers are quoted, values later
        // use a prepared statement. No SQL is run by this class.
        $sql = array_map(function ($statement) {
            return str_replace('~', chr(96), $statement);
        }, $sql);
        return [
            'stage' => 'growth_prefixed_schema_review_only',
            'status' => 'blocked',
            'provider' => ExplicitProviderRouter::GROWTH,
            'source_namespace' => $namespace,
            'tables' => [
                'binding' => $binding,
                'outbox' => $outbox,
                'ledger' => $ledger,
            ],
            'create_sql' => $sql,
            'drop_order_if_empty' => [$ledger, $outbox, $binding],
            'binding_values' => [
                $namespace, $merchant, $provider, $version, 'test', 'blocked'
            ],
            'schema_approved' => false,
            'installation_authorized' => false,
            'upgrade_authorized' => false,
            'rollback_authorized' => false,
            'data_360_ingestion_authorized' => false,
            'marketing_sends_authorized' => false,
        ];
    }

    /** Absent/full/partial inventory classification only; never runs SQL. */
    public static function inventoryState(array $plan, $existingTableNames)
    {
        if (!is_array($existingTableNames)
            || !isset($plan['tables']) || !is_array($plan['tables'])
            || count($plan['tables']) !== 3) {
            return 'unknown_manual_review';
        }
        $wanted = array_values($plan['tables']);
        $found = [];
        foreach ($existingTableNames as $table) {
            if (!is_string($table) || !in_array($table, $wanted, true)
                || isset($found[$table])) {
                return 'unknown_manual_review';
            }
            $found[$table] = true;
        }
        if (!$found) {
            return 'absent_review_only';
        }
        if (count($found) === 3) {
            return 'present_requires_schema_integrity_review';
        }
        return 'partial_install_manual_review';
    }
}
