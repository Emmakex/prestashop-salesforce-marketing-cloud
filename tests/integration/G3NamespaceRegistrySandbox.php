<?php
/**
 * G3 global namespace reservation DESIGN in one disposable CI DB.
 *
 * Real installations require a separately governed, authoritative registry.
 * No customer data, no Salesforce client, no upload, no sends, no module hook.
 */
final class G3NamespaceRegistrySandbox
{
    const TABLE = 'g3_namespace_registry_ci_v1';

    private $pdo;

    public function __construct(PDO $pdo)
    {
        if (getenv('G3_CI_ENABLE') !== 'yes_sandbox_only'
            || getenv('G3_CI_DSN') !== 'mysql:host=127.0.0.1;port=3306;dbname=g3_ci;charset=utf8mb4'
            || getenv('G3_CI_USER') !== 'g3_ci_user'
            || getenv('G3_CI_PASSWORD') !== 'g3_ci_pass_only'
            || (string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'g3_ci') {
            throw new RuntimeException('g3_namespace_not_disposable_registry');
        }
        $this->pdo = $pdo;
    }

    public function createDisposableRegistry()
    {
        if ($this->exists()) {
            throw new RuntimeException('g3_namespace_registry_existing_manual_review');
        }
        $this->pdo->exec('CREATE TABLE g3_namespace_registry_ci_v1 (
            source_namespace VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            external_installation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            merchant VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            provider VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            status VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT \'blocked\',
            PRIMARY KEY (source_namespace),
            UNIQUE KEY uq_g3_install_id (external_installation_id),
            CONSTRAINT chk_g3_registry_blocked CHECK (status = \'blocked\')
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->assertShape();
    }

    public function reserve($namespace, $externalInstallationId, $merchant, $provider)
    {
        $this->validate($namespace, $externalInstallationId, $merchant, $provider);
        $this->assertShape();
        // Unique constraints perform collision arbitration atomically.
        // An attempted duplicate NEVER updates the authoritative row.
        try {
            $stmt = $this->pdo->prepare('INSERT INTO g3_namespace_registry_ci_v1
                (source_namespace, external_installation_id, merchant, provider, status)
                VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$namespace, $externalInstallationId, $merchant,
                $provider, 'blocked']);
            return ['created' => true, 'blocked' => true, 'approved' => false];
        } catch (PDOException $e) {
            $record = $this->fetchReservation($namespace, $externalInstallationId);
            if (count($record) !== 1 || !self::sameBinding($record[0],
                $namespace, $externalInstallationId, $merchant, $provider)) {
                throw new RuntimeException('g3_namespace_collision_manual_review');
            }
            return ['created' => false, 'blocked' => true, 'approved' => false];
        }
    }

    public function inspect($namespace, $externalInstallationId, $merchant, $provider)
    {
        $this->validate($namespace, $externalInstallationId, $merchant, $provider);
        $this->assertShape();
        $record = $this->fetchReservation($namespace, $externalInstallationId);
        if (count($record) !== 1 || !self::sameBinding($record[0],
            $namespace, $externalInstallationId, $merchant, $provider)) {
            throw new RuntimeException('g3_namespace_attestation_mismatch');
        }
        // This only proves integrity in the synthetic registry, not approval
        // or ownership of an independent production deployment.
        return ['binding_consistent' => true, 'owner_approved' => false,
            'data_360_ingestion_authorized' => false,
            'marketing_sends_authorized' => false];
    }

    public function countReservations()
    {
        $this->assertShape();
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM g3_namespace_registry_ci_v1'
        )->fetchColumn();
    }

    public function destroyDisposableRegistry()
    {
        $this->assertShape();
        if ($this->countReservations() !== 0) {
            throw new RuntimeException('g3_namespace_registry_nonempty_drop_refused');
        }
        $this->pdo->exec('DROP TABLE g3_namespace_registry_ci_v1');
    }

    public function removeSyntheticReservation($namespace, $externalInstallationId)
    {
        // CI cleanup only: requires exact dual identity, never uninstall logic.
        if (!is_string($namespace) || !is_string($externalInstallationId)) {
            throw new RuntimeException('g3_namespace_cleanup_identity_required');
        }
        $this->assertShape();
        $stmt = $this->pdo->prepare('DELETE FROM g3_namespace_registry_ci_v1
            WHERE source_namespace = ? AND external_installation_id = ?
            AND status = ?');
        $stmt->execute([$namespace, $externalInstallationId, 'blocked']);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('g3_namespace_cleanup_identity_mismatch');
        }
    }

    private function fetchReservation($namespace, $externalInstallationId)
    {
        $stmt = $this->pdo->prepare('SELECT source_namespace, external_installation_id,
            merchant, provider, status
            FROM g3_namespace_registry_ci_v1
            WHERE source_namespace = ? OR external_installation_id = ? LIMIT 3');
        $stmt->execute([$namespace, $externalInstallationId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function sameBinding(array $row, $namespace, $installId, $merchant, $provider)
    {
        return $row['source_namespace'] === $namespace
            && $row['external_installation_id'] === $installId
            && $row['merchant'] === $merchant
            && $row['provider'] === $provider
            && $row['status'] === 'blocked';
    }

    private function validate($namespace, $installId, $merchant, $provider)
    {
        if (!is_string($namespace)
            || !preg_match('/^[a-z][a-z0-9_]{5,31}$/D', $namespace)
            || !is_string($installId)
            || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $installId)
            || !is_string($merchant)
            || !preg_match('/^[A-Z][A-Z0-9_]{1,31}$/D', $merchant)
            || ($provider !== 'marketing_cloud_growth'
                && $provider !== 'marketing_cloud_engagement')
            || ($merchant === 'ABH' && $provider !== 'marketing_cloud_growth')) {
            throw new RuntimeException('g3_namespace_invalid_or_provider_mismatch');
        }
    }

    private function exists()
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'g3_namespace_registry_ci_v1'");
        return (int) $stmt->fetchColumn() === 1;
    }

    private function assertShape()
    {
        if (!$this->exists()) {
            throw new RuntimeException('g3_namespace_registry_missing');
        }
        $stmt = $this->pdo->query("SELECT ENGINE FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'g3_namespace_registry_ci_v1'");
        if (strtolower((string) $stmt->fetchColumn()) !== 'innodb') {
            throw new RuntimeException('g3_namespace_registry_engine_drift');
        }
        $cols = $this->pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'g3_namespace_registry_ci_v1'
            ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN, 0);
        if ($cols !== ['source_namespace', 'external_installation_id',
            'merchant', 'provider', 'status']) {
            throw new RuntimeException('g3_namespace_registry_column_drift');
        }
        $indexes = $this->pdo->query("SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE
            FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'g3_namespace_registry_ci_v1'
            AND INDEX_NAME IN ('PRIMARY', 'uq_g3_install_id')")->fetchAll(PDO::FETCH_ASSOC);
        if (count($indexes) !== 2) {
            throw new RuntimeException('g3_namespace_registry_unique_keys_missing');
        }
        $found = [];
        foreach ($indexes as $index) {
            if ((int) $index['NON_UNIQUE'] !== 0) {
                throw new RuntimeException('g3_namespace_registry_nonunique_index');
            }
            $found[$index['INDEX_NAME']] = $index['COLUMN_NAME'];
        }
        if (!isset($found['PRIMARY'], $found['uq_g3_install_id'])
            || $found['PRIMARY'] !== 'source_namespace'
            || $found['uq_g3_install_id'] !== 'external_installation_id') {
            throw new RuntimeException('g3_namespace_registry_unique_key_drift');
        }
    }
}
