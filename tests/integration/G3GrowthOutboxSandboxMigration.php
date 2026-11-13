<?php
/**
 * G3 only: reversible DDL against the disposable g3_ci database.
 *
 * Not an upgrade script or PrestaShop module class. Never call against BC.
 * DDL implicitly commits in MySQL: a partial install fails closed and must
 * be reconciled by a CI operator, NOT cleaned up with unsafe DROP commands.
 */
final class G3GrowthOutboxSandboxMigration
{
    private $pdo;
    private $tableNames = ['g3_growth_outbox_v1', 'g3_growth_entity_ledger_v1'];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->guard();
    }

    public function install()
    {
        $existing = $this->tables();
        if (count($existing) === 2) {
            $this->assertSchema();
            return false; // idempotent no-op, preserve any preexisting rows.
        }
        if (count($existing) !== 0) {
            throw new RuntimeException('g3_growth_partial_schema_manual_repair_required');
        }
        foreach ($this->ddl() as $statement) {
            $this->pdo->exec($statement);
        }
        $this->assertSchema();
        return true;
    }

    public function rollbackIfEmpty()
    {
        $existing = $this->tables();
        if (!$existing) {
            return false;
        }
        if (count($existing) !== 2) {
            throw new RuntimeException('g3_growth_partial_schema_manual_repair_required');
        }
        $this->assertSchema();
        foreach ($this->tableNames as $table) {
            $count = (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
            if ($count !== 0) {
                throw new RuntimeException('g3_growth_rollback_nonempty_forbidden');
            }
        }
        // Drop only the two OWNED, empty tables, never any legacy PrestaShop table.
        $this->pdo->exec('DROP TABLE g3_growth_entity_ledger_v1');
        $this->pdo->exec('DROP TABLE g3_growth_outbox_v1');
        return true;
    }

    public function assertSchema()
    {
        $tables = $this->tables();
        if (count($tables) !== 2) {
            throw new RuntimeException('g3_growth_incomplete_schema');
        }
        $columnsExpected = [
            'g3_growth_outbox_v1' => [
                'accepted_sequence', 'source_namespace', 'shop_id', 'entity_type',
                'entity_id', 'dedupe_fingerprint', 'original_event_type',
                'captured_at_utc', 'captured_at_precision', 'clock_source',
                'clock_sync_verified', 'source_modified_provenance', 'status',
            ],
            'g3_growth_entity_ledger_v1' => [
                'source_namespace', 'shop_id', 'entity_type', 'entity_id',
                'latest_accepted_sequence', 'status',
            ],
        ];
        foreach ($columnsExpected as $table => $wanted) {
            $q = $this->pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
            $q->execute([$table]);
            if ($q->fetchAll(PDO::FETCH_COLUMN, 0) !== $wanted) {
                throw new RuntimeException('g3_growth_schema_column_drift');
            }
            $engine = $this->pdo->prepare('SELECT ENGINE FROM information_schema.TABLES '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $engine->execute([$table]);
            if (strtolower((string) $engine->fetchColumn()) !== 'innodb') {
                throw new RuntimeException('g3_growth_non_transactional_engine');
            }
            $checks = $this->pdo->prepare('SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE = ?');
            $checks->execute([$table, 'CHECK']);
            $expectedCheck = $table === 'g3_growth_outbox_v1'
                ? 'chk_growth_outbox_blocked_v1' : 'chk_growth_ledger_blocked_v1';
            if (!in_array($expectedCheck, $checks->fetchAll(PDO::FETCH_COLUMN, 0), true)) {
                throw new RuntimeException('g3_growth_blocked_constraint_missing');
            }
        }
        // Unique event fingerprint is merchant-scoped and exact. A unique key
        // without source_namespace would silently merge separate installations.
        $keys = $this->pdo->query('SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX, NON_UNIQUE '
            . 'FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() '
            . "AND TABLE_NAME = 'g3_growth_outbox_v1' AND INDEX_NAME = 'uq_growth_event_v1' "
            . 'ORDER BY SEQ_IN_INDEX')->fetchAll(PDO::FETCH_ASSOC);
        if (count($keys) !== 2 || $keys[0]['COLUMN_NAME'] !== 'source_namespace'
            || $keys[1]['COLUMN_NAME'] !== 'dedupe_fingerprint'
            || (int) $keys[0]['NON_UNIQUE'] !== 0 || (int) $keys[1]['NON_UNIQUE'] !== 0) {
            throw new RuntimeException('g3_growth_unique_namespace_missing');
        }
        $fk = $this->pdo->query('SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS '
            . "WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_growth_ledger_outbox_v1'"
            . " AND TABLE_NAME = 'g3_growth_entity_ledger_v1'")->fetchColumn();
        if ((int) $fk !== 1) {
            throw new RuntimeException('g3_growth_ledger_fk_missing');
        }
    }

    private function tables()
    {
        $q = $this->pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN "
            . "('g3_growth_outbox_v1', 'g3_growth_entity_ledger_v1')");
        return $q->fetchAll(PDO::FETCH_COLUMN, 0);
    }

    private function ddl()
    {
        $contents = file_get_contents(__DIR__ . '/g3_growth_outbox_v1_schema.sql');
        if (!is_string($contents)) {
            throw new RuntimeException('g3_growth_sql_unreadable');
        }
        $contents = preg_replace('/^\s*--.*$/m', '', $contents);
        $statements = array_values(array_filter(array_map('trim', explode(';', $contents))));
        if (count($statements) !== 2
            || strpos($statements[0], 'CREATE TABLE g3_growth_outbox_v1') !== 0
            || strpos($statements[1], 'CREATE TABLE g3_growth_entity_ledger_v1') !== 0) {
            throw new RuntimeException('g3_growth_schema_fixture_changed');
        }
        return $statements;
    }

    private function guard()
    {
        if (getenv('G3_CI_ENABLE') !== 'yes_sandbox_only'
            || getenv('G3_CI_DSN') !== 'mysql:host=127.0.0.1;port=3306;dbname=g3_ci;charset=utf8mb4'
            || getenv('G3_CI_USER') !== 'g3_ci_user'
            || getenv('G3_CI_PASSWORD') !== 'g3_ci_pass_only'
            || $this->pdo->query('SELECT DATABASE()')->fetchColumn() !== 'g3_ci') {
            throw new RuntimeException('g3_growth_not_sandbox_connection');
        }
        $actualUser = (string) $this->pdo->query('SELECT CURRENT_USER()')->fetchColumn();
        if (strpos($actualUser, 'g3_ci_user@') !== 0) {
            throw new RuntimeException('g3_growth_wrong_mysql_user');
        }
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }
}
