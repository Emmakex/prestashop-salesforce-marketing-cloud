<?php
/**
 * G3 PrestaShop/provider adapter candidate. DISPOSABLE g3_ci sandbox only.
 *
 * NOT shipped with pssalesforcebridge 0.7.1. No hooks, credentials,
 * Salesforce APIs, ingestion, queue workers, or marketing sends.
 * The PrestaShop version is a declared fixture, NOT runtime certification.
 */
require_once __DIR__ . '/G3GrowthOutboxSandboxMigration.php';

final class G3PrestaShopAdapterSandbox
{
    const GROWTH = 'marketing_cloud_growth';
    const ENGAGEMENT = 'marketing_cloud_engagement';
    const BINDING_TABLE = 'g3_ps_adapter_binding_v1';

    private $pdo;
    private $migration;

    public function __construct(PDO $pdo)
    {
        // Constructor also proves the exact ephemeral DB, account and CI flags.
        $this->migration = new G3GrowthOutboxSandboxMigration($pdo);
        $this->pdo = $pdo;
    }

    public static function preflight($version, $merchant, $provider, $namespace, $mode)
    {
        if (!is_string($version) || !preg_match('/^(1\.7\.8\.[0-9]+|[89]\.[0-9]+\.[0-9]+)$/D', $version)) {
            throw new RuntimeException('g3_adapter_unsupported_ps_version');
        }
        $family = strpos($version, '1.7.8.') === 0 ? '1.7.8'
            : (strpos($version, '8.') === 0 ? '8' : '9');
        if (!is_string($merchant) || !preg_match('/^[A-Z][A-Z0-9_]{1,31}$/D', $merchant)) {
            throw new RuntimeException('g3_adapter_merchant_required');
        }
        if ($provider !== self::GROWTH && $provider !== self::ENGAGEMENT) {
            throw new RuntimeException('g3_adapter_explicit_provider_required');
        }
        if ($merchant === 'ABH' && $provider !== self::GROWTH) {
            throw new RuntimeException('g3_adapter_abh_growth_only');
        }
        if (!is_string($namespace) || !preg_match('/^[a-z][a-z0-9_]{5,31}$/D', $namespace)) {
            throw new RuntimeException('g3_adapter_namespace_required');
        }
        if ($mode !== 'test') {
            throw new RuntimeException('g3_adapter_test_only');
        }

        return [
            'prestashop_version' => $version,
            'prestashop_family' => $family,
            'merchant' => $merchant,
            'provider' => $provider,
            'source_namespace' => $namespace,
            'mode' => 'test',
            'status' => 'blocked',
            'growth_schema_install_supported' => $provider === self::GROWTH,
            'schema_publication_authorized' => false,
            'data_360_ingestion_authorized' => false,
            'marketing_sends_authorized' => false,
            'outbound_worker_authorized' => false,
            'runtime_certified' => false,
        ];
    }

    public function install($version, $merchant, $provider, $namespace, $mode)
    {
        $plan = self::preflight($version, $merchant, $provider, $namespace, $mode);
        // Engagement is a separate legacy provider, never a Growth fallback.
        if (!$plan['growth_schema_install_supported']) {
            throw new RuntimeException('g3_adapter_engagement_install_not_implemented');
        }
        $present = $this->presentTables();
        if (count($present) === 3) {
            $this->assertBound($plan);
            $this->migration->assertSchema();
            return false;
        }
        if (count($present) !== 0) {
            // MySQL DDL implicitly commits. Never auto-repair a partial install.
            throw new RuntimeException('g3_adapter_partial_install_manual_review');
        }

        $this->pdo->exec("CREATE TABLE g3_ps_adapter_binding_v1 (
            source_namespace VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            merchant VARCHAR(32) CHARACTER SET ascii NOT NULL,
            provider VARCHAR(40) CHARACTER SET ascii NOT NULL,
            prestashop_version VARCHAR(20) CHARACTER SET ascii NOT NULL,
            mode VARCHAR(8) CHARACTER SET ascii NOT NULL DEFAULT 'test',
            status VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT 'blocked',
            PRIMARY KEY (source_namespace),
            CONSTRAINT chk_g3_adapter_test_mode CHECK (mode = 'test'),
            CONSTRAINT chk_g3_adapter_blocked CHECK (status = 'blocked'),
            CONSTRAINT chk_g3_adapter_growth CHECK (provider = 'marketing_cloud_growth')
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $statement = $this->pdo->prepare(
            'INSERT INTO g3_ps_adapter_binding_v1
            (source_namespace, merchant, provider, prestashop_version, mode, status)
            VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $namespace, $merchant, $provider, $version, 'test', 'blocked',
        ]);
        $this->migration->install();
        $this->assertBound($plan);
        $this->migration->assertSchema();
        return true;
    }

    public function rollbackIfEmpty($version, $merchant, $provider, $namespace, $mode)
    {
        $plan = self::preflight($version, $merchant, $provider, $namespace, $mode);
        if (!$plan['growth_schema_install_supported']) {
            throw new RuntimeException('g3_adapter_engagement_rollback_not_implemented');
        }
        $present = $this->presentTables();
        if (count($present) === 0) {
            return false;
        }
        if (count($present) !== 3) {
            throw new RuntimeException('g3_adapter_partial_install_manual_review');
        }
        $this->assertBound($plan);
        // The Growth migration rejects every rollback containing accepted rows.
        // No legacy queue/ledger table is ever dropped or modified.
        $this->migration->rollbackIfEmpty();
        $this->pdo->exec('DROP TABLE g3_ps_adapter_binding_v1');
        return true;
    }

    private function assertBound(array $plan)
    {
        $table = $this->pdo->query("SELECT ENGINE FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'g3_ps_adapter_binding_v1'");
        if (strtolower((string) $table->fetchColumn()) !== 'innodb') {
            throw new RuntimeException('g3_adapter_binding_engine_mismatch');
        }
        $columns = $this->pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'g3_ps_adapter_binding_v1'
            ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN, 0);
        if ($columns !== ['source_namespace', 'merchant', 'provider', 'prestashop_version', 'mode', 'status']) {
            throw new RuntimeException('g3_adapter_binding_schema_mismatch');
        }
        $checks = $this->pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'g3_ps_adapter_binding_v1'
            AND CONSTRAINT_TYPE = 'CHECK'")->fetchAll(PDO::FETCH_COLUMN, 0);
        foreach (['chk_g3_adapter_test_mode', 'chk_g3_adapter_blocked', 'chk_g3_adapter_growth'] as $check) {
            if (!in_array($check, $checks, true)) {
                throw new RuntimeException('g3_adapter_binding_guard_missing');
            }
        }
        $rows = $this->pdo->query('SELECT source_namespace, merchant, provider,
            prestashop_version, mode, status FROM g3_ps_adapter_binding_v1')
            ->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1
            || $rows[0]['source_namespace'] !== $plan['source_namespace']
            || $rows[0]['merchant'] !== $plan['merchant']
            || $rows[0]['provider'] !== $plan['provider']
            || $rows[0]['prestashop_version'] !== $plan['prestashop_version']
            || $rows[0]['mode'] !== 'test'
            || $rows[0]['status'] !== 'blocked') {
            throw new RuntimeException('g3_adapter_provider_binding_conflict');
        }
    }

    private function presentTables()
    {
        $q = $this->pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN
            ('g3_ps_adapter_binding_v1', 'g3_growth_outbox_v1',
             'g3_growth_entity_ledger_v1')");
        return $q->fetchAll(PDO::FETCH_COLUMN, 0);
    }
}
