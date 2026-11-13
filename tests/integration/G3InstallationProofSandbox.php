<?php
/**
 * CI-ONLY installation-key proof, NOT a production registration service.
 * Key is enrolled by an external operator fixture, never from the shop DB.
 * A copied UUID or namespace alone CANNOT satisfy a fresh RSA challenge.
 *
 * Proof of key possession DOES NOT prove that a key was non-exportable or
 * that the host is unique. All Salesforce permissions remain false.
 */
require_once __DIR__ . '/G3NamespaceRegistrySandbox.php';

final class G3InstallationProofSandbox
{
    const KEY_TABLE = 'g3_install_proof_key_ci_v1';
    const CHALLENGE_TABLE = 'g3_install_proof_challenge_ci_v1';

    private $pdo;
    private $registry;

    public function __construct(PDO $pdo, G3NamespaceRegistrySandbox $registry)
    {
        if (getenv('G3_CI_ENABLE') !== 'yes_sandbox_only'
            || getenv('G3_CI_DSN') !== 'mysql:host=127.0.0.1;port=3306;dbname=g3_ci;charset=utf8mb4'
            || getenv('G3_CI_USER') !== 'g3_ci_user'
            || getenv('G3_CI_PASSWORD') !== 'g3_ci_pass_only'
            || (string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'g3_ci') {
            throw new RuntimeException('g3_proof_not_disposable');
        }
        $this->pdo = $pdo;
        $this->registry = $registry;
    }

    public function createDisposableTables()
    {
        if ($this->tableExists(self::KEY_TABLE)
            || $this->tableExists(self::CHALLENGE_TABLE)) {
            throw new RuntimeException('g3_proof_partial_or_existing_schema');
        }
        $this->pdo->exec("CREATE TABLE g3_install_proof_key_ci_v1 (
            source_namespace VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            external_installation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            public_key_pem TEXT NOT NULL,
            public_key_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            status VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT 'blocked',
            key_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
            key_state VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT 'active',
            revoked_at_epoch BIGINT UNSIGNED NULL,
            PRIMARY KEY (source_namespace),
            UNIQUE KEY uq_g3_proof_install (external_installation_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->pdo->exec("CREATE TABLE g3_install_proof_challenge_ci_v1 (
            nonce_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            source_namespace VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            external_installation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            expires_epoch BIGINT UNSIGNED NOT NULL,
            key_version BIGINT UNSIGNED NOT NULL,
            public_key_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            consumed TINYINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (nonce_sha256),
            KEY idx_g3_proof_source (source_namespace, external_installation_id),
            CONSTRAINT fk_g3_proof_key FOREIGN KEY (source_namespace)
                REFERENCES g3_install_proof_key_ci_v1(source_namespace)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->assertTables();
    }

    public function enrollByExternalOperator($namespace, $installationId, $merchant, $provider, $publicKeyPem)
    {
        $this->assertTables();
        // The real product must require a separately authenticated operator
        // workflow; env flag is only a disposable CI fixture gate.
        if (getenv('G3_CI_EXTERNAL_OPERATOR_FIXTURE') !== 'yes_manual_ci_only') {
            throw new RuntimeException('g3_proof_external_operator_required');
        }
        $this->assertReserved($namespace, $installationId, $merchant, $provider);
        $normalized = self::validatedPublicKey($publicKeyPem);
        $fingerprint = hash('sha256', $normalized);
        $existing = $this->keyRows($namespace, $installationId);
        if ($existing) {
            if (count($existing) !== 1
                || !self::sameKey($existing[0], $namespace, $installationId, $fingerprint)) {
                throw new RuntimeException('g3_proof_key_rotation_manual_review');
            }
            return false;
        }
        try {
            $stmt = $this->pdo->prepare('INSERT INTO g3_install_proof_key_ci_v1
                (source_namespace, external_installation_id, public_key_pem,
                 public_key_sha256, status) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$namespace, $installationId, $normalized, $fingerprint, 'blocked']);
        } catch (PDOException $e) {
            throw new RuntimeException('g3_proof_enrollment_collision');
        }
        return true;
    }

    public function issueChallenge($namespace, $installationId, $merchant, $provider)
    {
        $this->assertReserved($namespace, $installationId, $merchant, $provider);
        $this->assertTables();
        $this->pdo->beginTransaction();
        try {
            // Serializes issuance with operator key rotation / revocation.
            $key = $this->loadKey($namespace, $installationId, true);
            $nonce = bin2hex(random_bytes(32));
            $expiry = time() + 60;
            $stmt = $this->pdo->prepare('INSERT INTO g3_install_proof_challenge_ci_v1
                (nonce_sha256, source_namespace, external_installation_id,
                 expires_epoch, key_version, public_key_sha256, consumed)
                VALUES (?, ?, ?, ?, ?, ?, 0)');
            $stmt->execute([hash('sha256', $nonce), $namespace, $installationId,
                $expiry, $key['key_version'], $key['public_key_sha256']]);
            $this->pdo->commit();
            return ['nonce' => $nonce, 'expires_epoch' => $expiry];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function signingMessage($namespace, $installationId, $merchant, $provider, $nonce, $expiry)
    {
        if (!is_string($namespace) || !preg_match('/^[a-z][a-z0-9_]{5,31}$/D', $namespace)
            || !is_string($installationId)
            || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $installationId)
            || !is_string($merchant) || !preg_match('/^[A-Z][A-Z0-9_]{1,31}$/D', $merchant)
            || !in_array($provider, ['marketing_cloud_growth', 'marketing_cloud_engagement'], true)
            || ($merchant === 'ABH' && $provider !== 'marketing_cloud_growth')
            || !is_string($nonce) || !preg_match('/^[0-9a-f]{64}$/D', $nonce)
            || !is_int($expiry) || $expiry <= 0) {
            throw new RuntimeException('g3_proof_invalid_challenge');
        }
        return "G3_INSTALLATION_PROOF_CI_V1\n" . $namespace . "\n" . $installationId
            . "\n" . $merchant . "\n" . $provider . "\n" . $nonce
            . "\n" . $expiry . "\n";
    }

    public function verifyOnce($namespace, $installationId, $merchant, $provider, $nonce, $expiry, $signatureBase64)
    {
        $this->assertReserved($namespace, $installationId, $merchant, $provider);
        $this->assertTables();
        $message = self::signingMessage($namespace, $installationId, $merchant,
            $provider, $nonce, $expiry);
        if (!is_string($signatureBase64) || strlen($signatureBase64) > 1024) {
            throw new RuntimeException('g3_proof_invalid_signature');
        }
        $signature = base64_decode($signatureBase64, true);
        if (!is_string($signature) || strlen($signature) < 256 || strlen($signature) > 512) {
            throw new RuntimeException('g3_proof_invalid_signature');
        }
        $this->pdo->beginTransaction();
        try {
            // Lock the key BEFORE signature verification. Concurrent revocation
            // or rotation cannot pass between verification and nonce consume.
            $key = $this->loadKey($namespace, $installationId, true);
            if (openssl_verify($message, $signature, $key['public_key_pem'],
                OPENSSL_ALGO_SHA256) !== 1) {
                throw new RuntimeException('g3_proof_invalid_signature');
            }
            // A challenge is bound to one precise key generation and digest.
            // An old, unconsumed challenge fails even if re-signed by a new key.
            $stmt = $this->pdo->prepare('UPDATE g3_install_proof_challenge_ci_v1
                SET consumed = 1 WHERE nonce_sha256 = ?
                AND source_namespace = ? AND external_installation_id = ?
                AND expires_epoch = ? AND key_version = ?
                AND public_key_sha256 = ? AND consumed = 0 AND expires_epoch > ?');
            $stmt->execute([hash('sha256', $nonce), $namespace, $installationId,
                $expiry, $key['key_version'], $key['public_key_sha256'], time()]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('g3_proof_missing_expired_or_replayed');
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return ['proof_verified' => true, 'identity_owner_approved' => false,
            'schema_upload_authorized' => false,
            'data_360_ingestion_authorized' => false,
            'marketing_sends_authorized' => false];
    }

    /**
     * An operator in a REAL product must be separately authenticated.
     * CI-only fixture flag is not an actual second-person approval.
     * A rotation never elevates the record's permanent blocked status.
     */
    public function rotateByExternalOperator($namespace, $installationId,
        $merchant, $provider, $expectedOldFingerprint, $newPublicKeyPem)
    {
        self::requireOperatorFixture();
        $this->assertReserved($namespace, $installationId, $merchant, $provider);
        $this->assertTables();
        $normalized = self::validatedPublicKey($newPublicKeyPem);
        $newFingerprint = hash('sha256', $normalized);
        if (!is_string($expectedOldFingerprint)
            || !preg_match('/^[0-9a-f]{64}$/D', $expectedOldFingerprint)) {
            throw new RuntimeException('g3_proof_expected_fingerprint_required');
        }
        $this->pdo->beginTransaction();
        try {
            $key = $this->loadKey($namespace, $installationId, true);
            if (!hash_equals($key['public_key_sha256'], $expectedOldFingerprint)
                || hash_equals($key['public_key_sha256'], $newFingerprint)) {
                throw new RuntimeException('g3_proof_rotation_conflict_manual_review');
            }
            $next = (int) $key['key_version'] + 1;
            $stmt = $this->pdo->prepare("UPDATE g3_install_proof_key_ci_v1
                SET public_key_pem = ?, public_key_sha256 = ?, key_version = ?
                WHERE source_namespace = ? AND external_installation_id = ?
                AND status = 'blocked' AND key_state = 'active'
                AND revoked_at_epoch IS NULL AND key_version = ?
                AND public_key_sha256 = ?");
            $stmt->execute([$normalized, $newFingerprint, $next, $namespace,
                $installationId, $key['key_version'], $expectedOldFingerprint]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('g3_proof_rotation_conflict_manual_review');
            }
            $this->pdo->commit();
            return ['key_version' => $next, 'status' => 'blocked',
                'identity_owner_approved' => false, 'data_360_ingestion_authorized' => false,
                'marketing_sends_authorized' => false];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Irreversible CI revocation: recovery requires a NEW, separately reviewed
     * enrollment flow. A fresh enroll/rotation cannot resurrect this record.
     */
    public function revokeByExternalOperator($namespace, $installationId,
        $merchant, $provider, $expectedFingerprint)
    {
        self::requireOperatorFixture();
        $this->assertReserved($namespace, $installationId, $merchant, $provider);
        $this->assertTables();
        if (!is_string($expectedFingerprint)
            || !preg_match('/^[0-9a-f]{64}$/D', $expectedFingerprint)) {
            throw new RuntimeException('g3_proof_expected_fingerprint_required');
        }
        $this->pdo->beginTransaction();
        try {
            $key = $this->loadKey($namespace, $installationId, true);
            if (!hash_equals($key['public_key_sha256'], $expectedFingerprint)) {
                throw new RuntimeException('g3_proof_revocation_conflict_manual_review');
            }
            $next = (int) $key['key_version'] + 1;
            $stmt = $this->pdo->prepare("UPDATE g3_install_proof_key_ci_v1
                SET key_state = 'revoked', revoked_at_epoch = ?, key_version = ?
                WHERE source_namespace = ? AND external_installation_id = ?
                AND status = 'blocked' AND key_state = 'active'
                AND revoked_at_epoch IS NULL AND key_version = ?
                AND public_key_sha256 = ?");
            $stmt->execute([time(), $next, $namespace, $installationId,
                $key['key_version'], $expectedFingerprint]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('g3_proof_revocation_conflict_manual_review');
            }
            $this->pdo->commit();
            return ['key_version' => $next, 'key_state' => 'revoked',
                'identity_owner_approved' => false, 'data_360_ingestion_authorized' => false,
                'marketing_sends_authorized' => false];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function removeSyntheticChallenges()
    {
        $this->assertTables();
        $this->pdo->exec('DELETE FROM g3_install_proof_challenge_ci_v1');
    }

    public function removeSyntheticEnrollment($namespace, $installationId)
    {
        $this->assertTables();
        $stmt = $this->pdo->prepare('DELETE FROM g3_install_proof_key_ci_v1
            WHERE source_namespace = ? AND external_installation_id = ? AND status = ?');
        $stmt->execute([$namespace, $installationId, 'blocked']);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('g3_proof_enrollment_cleanup_rejected');
        }
    }

    public function destroyDisposableTables()
    {
        $this->assertTables();
        foreach ([self::CHALLENGE_TABLE, self::KEY_TABLE] as $table) {
            if ((int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() !== 0) {
                throw new RuntimeException('g3_proof_nonempty_drop_forbidden');
            }
        }
        $this->pdo->exec('DROP TABLE g3_install_proof_challenge_ci_v1');
        $this->pdo->exec('DROP TABLE g3_install_proof_key_ci_v1');
    }

    private function assertReserved($namespace, $installationId, $merchant, $provider)
    {
        $this->registry->inspect($namespace, $installationId, $merchant, $provider);
    }

    private function keyRows($namespace, $installationId, $forUpdate = false)
    {
        $stmt = $this->pdo->prepare('SELECT source_namespace, external_installation_id,
            public_key_pem, public_key_sha256, status, key_version,
            key_state, revoked_at_epoch
            FROM g3_install_proof_key_ci_v1
            WHERE source_namespace = ? OR external_installation_id = ? LIMIT 3'
            . ($forUpdate ? ' FOR UPDATE' : ''));
        $stmt->execute([$namespace, $installationId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function loadKey($namespace, $installationId, $forUpdate = false)
    {
        $rows = $this->keyRows($namespace, $installationId, $forUpdate);
        if (count($rows) !== 1 || $rows[0]['source_namespace'] !== $namespace
            || $rows[0]['external_installation_id'] !== $installationId
            || $rows[0]['status'] !== 'blocked'
            || $rows[0]['key_state'] !== 'active'
            || $rows[0]['revoked_at_epoch'] !== null
            || (int) $rows[0]['key_version'] < 1
            || !hash_equals(hash('sha256', $rows[0]['public_key_pem']),
                $rows[0]['public_key_sha256'])) {
            throw new RuntimeException('g3_proof_key_unenrolled_or_tampered');
        }
        return $rows[0];
    }

    private static function sameKey(array $row, $namespace, $installId, $fingerprint)
    {
        return $row['source_namespace'] === $namespace
            && $row['external_installation_id'] === $installId
            && $row['public_key_sha256'] === $fingerprint
            && $row['status'] === 'blocked'
            && $row['key_state'] === 'active'
            && $row['revoked_at_epoch'] === null;
    }

    private static function requireOperatorFixture()
    {
        if (getenv('G3_CI_EXTERNAL_OPERATOR_FIXTURE') !== 'yes_manual_ci_only') {
            throw new RuntimeException('g3_proof_external_operator_required');
        }
    }

    private static function validatedPublicKey($pem)
    {
        if (!is_string($pem) || strlen($pem) > 4096) {
            throw new RuntimeException('g3_proof_public_key_invalid');
        }
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new RuntimeException('g3_proof_public_key_invalid');
        }
        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || $details['type'] !== OPENSSL_KEYTYPE_RSA
            || $details['bits'] < 2048 || $details['bits'] > 4096
            || !isset($details['key'])) {
            throw new RuntimeException('g3_proof_public_key_invalid');
        }
        return $details['key'];
    }

    private function tableExists($name)
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$name]);
        return (int) $stmt->fetchColumn() === 1;
    }

    private function assertTables()
    {
        $expected = [
            self::KEY_TABLE => [
                'source_namespace', 'external_installation_id', 'public_key_pem',
                'public_key_sha256', 'status', 'key_version', 'key_state',
                'revoked_at_epoch',
            ],
            self::CHALLENGE_TABLE => [
                'nonce_sha256', 'source_namespace', 'external_installation_id',
                'expires_epoch', 'key_version', 'public_key_sha256', 'consumed',
            ],
        ];
        foreach ($expected as $table => $columns) {
            if (!$this->tableExists($table)) {
                throw new RuntimeException('g3_proof_schema_incomplete');
            }
            $stmt = $this->pdo->prepare('SELECT ENGINE FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $stmt->execute([$table]);
            if (strtolower((string) $stmt->fetchColumn()) !== 'innodb') {
                throw new RuntimeException('g3_proof_engine_mismatch');
            }
            $stmt = $this->pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                ORDER BY ORDINAL_POSITION');
            $stmt->execute([$table]);
            if ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) !== $columns) {
                throw new RuntimeException('g3_proof_schema_column_drift');
            }
        }
    }
}
