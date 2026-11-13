<?php
/**
 * G3: CI-ONLY lifecycle candidate against REAL disposable PrestaShop runtime.
 *
 * Executed by tests/runtime/run-prestashop-certification.sh only.
 * NOT a module installer/upgrade script; never deployed to a merchant.
 * No remote clients, outbound workers, business payload or credentials.
 */
require_once __DIR__ . '/G3PrestaShopAdapterSandbox.php';

final class G3PrestaShopRuntimeLifecycleCandidate
{
    private $db;
    private $binding;
    private $outbox;
    private $ledger;

    public function __construct()
    {
        // A module version/mode check is part of the gate, NOT permission to send.
        if (getenv('GITHUB_ACTIONS') !== 'true'
            || getenv('G3_RUNTIME_CI_ONLY') !== 'yes_disposable_prestashop'
            || getenv('EXPECTED_PS_VERSION') !== (string) _PS_VERSION_
            || !defined('_DB_PREFIX_') || _DB_PREFIX_ !== 'ps_'
            || !class_exists('Db') || !class_exists('Module')
            || !Module::isInstalled('pssalesforcebridge')
            || (string) Configuration::get('PS_SFMC_MODE') !== 'test') {
            throw new RuntimeException('g3_ps_runtime_sandbox_required');
        }
        $this->db = Db::getInstance();
        if ((string) $this->db->getValue('SELECT DATABASE()') !== 'prestashop') {
            throw new RuntimeException('g3_ps_runtime_sandbox_database_required');
        }
        $this->binding = _DB_PREFIX_ . 'g3_provider_binding_v1';
        $this->outbox = _DB_PREFIX_ . 'g3_growth_outbox_v1';
        $this->ledger = _DB_PREFIX_ . 'g3_growth_entity_ledger_v1';
    }

    public function install($merchant, $provider, $namespace)
    {
        $plan = $this->plan($merchant, $provider, $namespace);
        if (!$plan['growth_schema_install_supported']) {
            throw new RuntimeException('g3_ps_runtime_engagement_not_implemented');
        }
        $existing = $this->present();
        if (count($existing) === 3) {
            $this->assertBinding($plan);
            $this->assertGrowthShapes();
            return 'already_installed';
        }
        if ($existing) {
            // DDL is not transactional; partial schema is never auto-dropped.
            throw new RuntimeException('g3_ps_runtime_partial_schema_manual_review');
        }
        $this->execute("CREATE TABLE \`{$this->binding}\` (
            source_namespace VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            merchant VARCHAR(32) CHARACTER SET ascii NOT NULL,
            provider VARCHAR(40) CHARACTER SET ascii NOT NULL,
            prestashop_version VARCHAR(20) CHARACTER SET ascii NOT NULL,
            mode VARCHAR(8) CHARACTER SET ascii NOT NULL DEFAULT 'test',
            status VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT 'blocked',
            PRIMARY KEY (source_namespace),
            CONSTRAINT chk_g3_ps_test_v1 CHECK (mode = 'test'),
            CONSTRAINT chk_g3_ps_blocked_v1 CHECK (status = 'blocked'),
            CONSTRAINT chk_g3_ps_growth_v1 CHECK (provider = 'marketing_cloud_growth')
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->execute("INSERT INTO \`{$this->binding}\`
            (source_namespace, merchant, provider, prestashop_version, mode, status)
            VALUES ('" . pSQL($plan['source_namespace']) . "',
                '" . pSQL($plan['merchant']) . "',
                '" . pSQL($plan['provider']) . "',
                '" . pSQL($plan['prestashop_version']) . "', 'test', 'blocked')");
        foreach ($this->growthStatements() as $statement) {
            $this->execute($statement);
        }
        $this->assertBinding($plan);
        $this->assertGrowthShapes();
        return 'installed';
    }

    /**
     * Repeated install and future upgrade must keep the same immutable binding.
     * If accepted data exists, uninstall means RETAIN (not DROP or DELETE).
     */
    public function uninstallCandidate($merchant, $provider, $namespace)
    {
        $plan = $this->plan($merchant, $provider, $namespace);
        $present = $this->present();
        if (!$present) {
            return 'already_absent';
        }
        if (count($present) !== 3) {
            throw new RuntimeException('g3_ps_runtime_partial_schema_manual_review');
        }
        $this->assertBinding($plan);
        $this->assertGrowthShapes();
        $outboxCount = (int) $this->db->getValue("SELECT COUNT(*) FROM \`{$this->outbox}\`");
        $ledgerCount = (int) $this->db->getValue("SELECT COUNT(*) FROM \`{$this->ledger}\`");
        if ($outboxCount !== 0 || $ledgerCount !== 0) {
            // No purging, even if events were captured in Test.
            return 'retained_nonempty';
        }

        $this->execute("DROP TABLE \`{$this->ledger}\`");
        $this->execute("DROP TABLE \`{$this->outbox}\`");
        $this->execute("DROP TABLE \`{$this->binding}\`");
        return 'removed_empty';
    }

    public function present()
    {
        $found = [];
        foreach ([$this->binding, $this->outbox, $this->ledger] as $table) {
            $sql = "SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . pSQL($table) . "'";
            if ((int) $this->db->getValue($sql) === 1) {
                $found[] = $table;
            }
        }
        return $found;
    }

    private function plan($merchant, $provider, $namespace)
    {
        return G3PrestaShopAdapterSandbox::preflight(
            (string) _PS_VERSION_, $merchant, $provider, $namespace,
            (string) Configuration::get('PS_SFMC_MODE')
        );
    }

    private function assertBinding(array $plan)
    {
        $wanted = ['source_namespace', 'merchant', 'provider',
            'prestashop_version', 'mode', 'status'];
        if ($this->columns($this->binding) !== $wanted || $this->engine($this->binding) !== 'innodb') {
            throw new RuntimeException('g3_ps_runtime_binding_schema_drift');
        }
        $rows = $this->db->executeS("SELECT source_namespace, merchant, provider,
            prestashop_version, mode, status FROM \`{$this->binding}\`");
        if (!is_array($rows) || count($rows) !== 1) {
            throw new RuntimeException('g3_ps_runtime_binding_count_invalid');
        }
        $row = $rows[0];
        foreach (['source_namespace', 'merchant', 'provider', 'prestashop_version'] as $field) {
            if (!isset($row[$field]) || $row[$field] !== $plan[$field]) {
                throw new RuntimeException('g3_ps_runtime_binding_conflict');
            }
        }
        if ($row['mode'] !== 'test' || $row['status'] !== 'blocked') {
            throw new RuntimeException('g3_ps_runtime_binding_unblocked');
        }
    }

    private function assertGrowthShapes()
    {
        $outbox = [
            'accepted_sequence', 'source_namespace', 'shop_id', 'entity_type',
            'entity_id', 'dedupe_fingerprint', 'original_event_type',
            'captured_at_utc', 'captured_at_precision', 'clock_source',
            'clock_sync_verified', 'source_modified_provenance', 'status',
        ];
        $ledger = [
            'source_namespace', 'shop_id', 'entity_type', 'entity_id',
            'latest_accepted_sequence', 'status',
        ];
        if ($this->columns($this->outbox) !== $outbox
            || $this->columns($this->ledger) !== $ledger
            || $this->engine($this->outbox) !== 'innodb'
            || $this->engine($this->ledger) !== 'innodb') {
            throw new RuntimeException('g3_ps_runtime_growth_schema_drift');
        }
        // MySQL 5.7 (used by pinned PrestaShop runtime harness) parses but
        // does not enforce CHECK. This candidate has NO worker/send route.
        // DB-enforced CHECK is certified separately on MySQL 8/MariaDB 10.
    }

    private function columns($table)
    {
        $rows = $this->db->executeS("SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . pSQL($table) . "'
            ORDER BY ORDINAL_POSITION");
        if (!is_array($rows)) {
            return [];
        }
        return array_map(function ($row) { return $row['COLUMN_NAME']; }, $rows);
    }

    private function engine($table)
    {
        return strtolower((string) $this->db->getValue(
            "SELECT ENGINE FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . pSQL($table) . "'"
        ));
    }

    private function growthStatements()
    {
        $raw = file_get_contents(__DIR__ . '/g3_growth_outbox_v1_schema.sql');
        if (!is_string($raw)) {
            throw new RuntimeException('g3_ps_runtime_sql_fixture_missing');
        }
        $raw = preg_replace('/^\s*--.*$/m', '', $raw);
        $raw = str_replace(
            ['g3_growth_entity_ledger_v1', 'g3_growth_outbox_v1'],
            [$this->ledger, $this->outbox],
            $raw
        );
        $statements = array_values(array_filter(array_map('trim', explode(';', $raw))));
        if (count($statements) !== 2
            || strpos($statements[0], 'CREATE TABLE ' . $this->outbox) !== 0
            || strpos($statements[1], 'CREATE TABLE ' . $this->ledger) !== 0) {
            throw new RuntimeException('g3_ps_runtime_ddl_contract_changed');
        }
        return $statements;
    }

    private function execute($sql)
    {
        if (!$this->db->execute($sql)) {
            throw new RuntimeException('g3_ps_runtime_ddl_failed');
        }
    }
}
