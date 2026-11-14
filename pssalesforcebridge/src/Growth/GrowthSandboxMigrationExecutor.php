<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use Emmakex\PrestaShopSalesforceMarketingCloud\Provider\ExplicitProviderRouter;
use PDO;
use RuntimeException;

/**
 * Explicit, sandbox-ONLY executor for the future per-prefix Growth upgrade.
 *
 * This class is intentionally NOT a production migration entrypoint:
 *  - It refuses anything except the disposable g3_ci database and CI user.
 *  - It is not loaded by module install(), upgrade scripts, hooks or workers.
 *  - There is no way to authorize a real-shop migration with a boolean flag.
 *
 * Future production execution needs a distinct G3 owner-approved change,
 * backup/recovery evidence, live schema preflight and rollout authorization.
 */
final class GrowthSandboxMigrationExecutor
{
    private $db;
    private $plan;

    public function __construct(
        PDO $db, $prestashopVersion, $merchant, $provider, $namespace,
        $mode, $prefix
    ) {
        // Both environment AND SQL CHECK enforcement must be established
        // before planning any sandbox installation or DROP operations.
        self::assertSandbox($db);
        GrowthSqlEngineGate::inspectConnection($db);
        $this->plan = GrowthOutboxSchemaPlan::preview(
            $prestashopVersion, $merchant, $provider, $namespace, $mode, $prefix
        );
        if ($this->plan['provider'] !== ExplicitProviderRouter::GROWTH
            || $this->plan['status'] !== 'blocked'
            || $this->plan['installation_authorized'] !== false) {
            throw new RuntimeException('growth_migration_invalid_candidate');
        }
        $this->db = $db;
    }

    /** Inventory is non-authorizing; errors and drift always halt execution. */
    public function inspect()
    {
        $state = $this->inventoryState();
        if ($state === 'present_requires_schema_integrity_review') {
            $this->assertInstalledSchema();
            return $this->report('installed_blocked_sandbox');
        }
        return $this->report($state);
    }

    /**
     * Install only when ALL three tables are absent, or verify an exact
     * previously installed schema without changing it (idempotent no-op).
     * MySQL DDL implicitly commits: partial failure is NEVER auto-cleaned.
     */
    public function install()
    {
        $state = $this->inventoryState();
        if ($state === 'present_requires_schema_integrity_review') {
            $this->assertInstalledSchema();
            return false;
        }
        if ($state !== 'absent_review_only') {
            throw new RuntimeException('growth_migration_partial_or_unknown_schema');
        }
        foreach ($this->plan['create_sql'] as $statement) {
            $this->db->exec($statement);
        }
        $binding = $this->plan['tables']['binding'];
        $statement = $this->db->prepare(
            'INSERT INTO ' . $this->quoteIdentifier($binding)
            . ' (source_namespace, merchant, provider, prestashop_version, mode, status)'
            . ' VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute($this->plan['binding_values']);
        $this->assertInstalledSchema();
        return true;
    }

    /**
     * Only the owned, exact, EMPTY schema can be removed. Never delete event
     * rows, "repair" a partial install, or touch legacy sfmc_* tables.
     */
    public function rollbackIfEmpty()
    {
        $state = $this->inventoryState();
        if ($state === 'absent_review_only') {
            return false;
        }
        if ($state !== 'present_requires_schema_integrity_review') {
            throw new RuntimeException('growth_migration_partial_or_unknown_schema');
        }
        $this->assertInstalledSchema();
        foreach (['outbox', 'ledger'] as $kind) {
            $table = $this->quoteIdentifier($this->plan['tables'][$kind]);
            if ((int) $this->db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() !== 0) {
                throw new RuntimeException('growth_migration_rollback_nonempty_forbidden');
            }
        }
        // Reverse FK dependency order; only disposable g3_ci tables can match
        // this sandbox-approved plan. DDL errors leave manual review, not repair.
        foreach ($this->plan['drop_order_if_empty'] as $table) {
            $this->db->exec('DROP TABLE ' . $this->quoteIdentifier($table));
        }
        return true;
    }

    private function inventoryState()
    {
        $wanted = array_values($this->plan['tables']);
        $stmt = $this->db->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?, ?)'
        );
        $stmt->execute($wanted);
        return GrowthOutboxSchemaPlan::inventoryState(
            $this->plan, $stmt->fetchAll(PDO::FETCH_COLUMN, 0)
        );
    }

    private function assertInstalledSchema()
    {
        $columns = [
            'binding' => [
                'source_namespace', 'merchant', 'provider', 'prestashop_version',
                'mode', 'status',
            ],
            'outbox' => [
                'accepted_sequence', 'source_namespace', 'shop_id', 'entity_type',
                'entity_id', 'dedupe_fingerprint', 'original_event_type',
                'captured_at_utc', 'captured_at_precision', 'clock_source',
                'clock_sync_verified', 'source_modified_provenance', 'status',
            ],
            'ledger' => [
                'source_namespace', 'shop_id', 'entity_type', 'entity_id',
                'latest_accepted_sequence', 'status',
            ],
        ];
        foreach ($this->plan['tables'] as $kind => $name) {
            $stmt = $this->db->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
                . 'ORDER BY ORDINAL_POSITION'
            );
            $stmt->execute([$name]);
            if ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) !== $columns[$kind]) {
                throw new RuntimeException('growth_migration_column_drift');
            }
            $stmt = $this->db->prepare(
                'SELECT ENGINE FROM information_schema.TABLES '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $stmt->execute([$name]);
            if (strtolower((string) $stmt->fetchColumn()) !== 'innodb') {
                throw new RuntimeException('growth_migration_engine_drift');
            }
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
                . "AND CONSTRAINT_TYPE = 'CHECK'"
            );
            $stmt->execute([$name]);
            if ((int) $stmt->fetchColumn() !== 1) {
                throw new RuntimeException('growth_migration_check_drift');
            }
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS '
                . 'WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $stmt->execute([$name]);
            $expectedForeignKeys = $kind === 'binding' ? 0 : 1;
            if ((int) $stmt->fetchColumn() !== $expectedForeignKeys) {
                throw new RuntimeException('growth_migration_foreign_key_drift');
            }
            if ($kind !== 'binding') {
                $target = $kind === 'outbox'
                    ? $this->plan['tables']['binding']
                    : $this->plan['tables']['outbox'];
                $stmt = $this->db->prepare(
                    'SELECT REFERENCED_TABLE_NAME, DELETE_RULE '
                    . 'FROM information_schema.REFERENTIAL_CONSTRAINTS '
                    . 'WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?'
                );
                $stmt->execute([$name]);
                $foreignKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($foreignKeys) !== 1
                    || $foreignKeys[0]['REFERENCED_TABLE_NAME'] !== $target
                    || $foreignKeys[0]['DELETE_RULE'] !== 'RESTRICT') {
                    throw new RuntimeException('growth_migration_foreign_key_target_drift');
                }
            }
        }

        $stmt = $this->db->prepare(
            'SELECT COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
            . "AND INDEX_NAME = 'uq_growth_event_v1' ORDER BY SEQ_IN_INDEX"
        );
        $stmt->execute([$this->plan['tables']['outbox']]);
        $unique = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($unique) !== 2
            || $unique[0]['COLUMN_NAME'] !== 'source_namespace'
            || $unique[1]['COLUMN_NAME'] !== 'dedupe_fingerprint'
            || (int) $unique[0]['NON_UNIQUE'] !== 0
            || (int) $unique[1]['NON_UNIQUE'] !== 0) {
            throw new RuntimeException('growth_migration_unique_drift');
        }

        $binding = $this->quoteIdentifier($this->plan['tables']['binding']);
        $rows = $this->db->query(
            'SELECT source_namespace, merchant, provider, prestashop_version,'
            . ' mode, status FROM ' . $binding
        )->fetchAll(PDO::FETCH_NUM);
        if (count($rows) !== 1 || $rows[0] !== $this->plan['binding_values']) {
            throw new RuntimeException('growth_migration_binding_conflict');
        }
    }

    private function report($status)
    {
        return [
            'status' => $status,
            'provider' => ExplicitProviderRouter::GROWTH,
            'database_type' => 'disposable_ci_only',
            'installation_authorized' => false,
            'upgrade_authorized' => false,
            'rollback_in_production_authorized' => false,
            'data_360_ingestion_authorized' => false,
            'marketing_sends_authorized' => false,
        ];
    }

    private function quoteIdentifier($identifier)
    {
        if (!is_string($identifier) || !preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $identifier)) {
            throw new RuntimeException('growth_migration_invalid_table_identifier');
        }
        return chr(96) . $identifier . chr(96);
    }

    private static function assertSandbox(PDO $db)
    {
        if (getenv('G3_CI_ENABLE') !== 'yes_sandbox_only'
            || getenv('G3_CI_DSN') !== 'mysql:host=127.0.0.1;port=3306;dbname=g3_ci;charset=utf8mb4'
            || getenv('G3_CI_USER') !== 'g3_ci_user'
            || getenv('G3_CI_PASSWORD') !== 'g3_ci_pass_only') {
            throw new RuntimeException('growth_migration_sandbox_only');
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'g3_ci'
            || strpos((string) $db->query('SELECT CURRENT_USER()')->fetchColumn(), 'g3_ci_user@') !== 0) {
            throw new RuntimeException('growth_migration_sandbox_only');
        }
    }
}
