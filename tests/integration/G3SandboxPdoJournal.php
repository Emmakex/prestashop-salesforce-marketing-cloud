<?php
/**
 * G3 sandbox-only MySQL/MariaDB journal. NOT a module runtime adapter.
 * No schema migrations on PrestaShop, no Salesforce, no customer payload.
 */
final class G3SandboxPdoJournal
{
    private $pdo;
    private $failureStage;
    private $delay;

    public function __construct(PDO $pdo, $failureStage = '', $delay = 0)
    {
        $this->pdo = $pdo;
        $this->failureStage = (string) $failureStage;
        $this->delay = (int) $delay;
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function insertOrLoad(array $identity, $factory)
    {
        if (!is_callable($factory)) {
            throw new RuntimeException('g3_store_factory_invalid');
        }
        // MySQL named lock serializes writers over the same tenant+event key.
        // The real unique index is an additional, independent safeguard.
        $lockName = hash('sha256', 'g3-sandbox:' . $identity['source_namespace']
            . ':' . $identity['dedupe_fingerprint']);
        $lock = $this->pdo->prepare('SELECT GET_LOCK(?, 10)');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('g3_sandbox_lock_not_acquired');
        }
        try {
            $this->pdo->beginTransaction();
            $select = $this->pdo->prepare('SELECT accepted_sequence, source_namespace, shop_id, '
                . 'entity_type, entity_id, dedupe_fingerprint, original_event_type, '
                . 'captured_at_utc, captured_at_precision, clock_source, '
                . 'clock_sync_verified, source_modified_provenance '
                . 'FROM g3_journal WHERE source_namespace = ? AND dedupe_fingerprint = ? FOR UPDATE');
            $select->execute([$identity['source_namespace'], $identity['dedupe_fingerprint']]);
            $previous = $select->fetch(PDO::FETCH_ASSOC);
            if (is_array($previous)) {
                $this->pdo->commit();
                return ['row' => self::asContractRow($previous), 'inserted' => false];
            }
            $row = call_user_func($factory);
            if ($this->delay > 0) {
                usleep($this->delay);
            }
            $insert = $this->pdo->prepare('INSERT INTO g3_journal '
                . '(source_namespace, shop_id, entity_type, entity_id, dedupe_fingerprint, '
                . 'original_event_type, captured_at_utc, captured_at_precision, clock_source, '
                . 'clock_sync_verified, source_modified_provenance) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $insert->execute([
                $row['source_namespace'], $row['shop_id'], $row['entity_type'],
                $row['entity_id'], $row['dedupe_fingerprint'], $row['original_event_type'],
                $row['captured_at_utc'], $row['captured_at_precision'],
                $row['clock_source'], 0, $row['source_modified_provenance'],
            ]);
            $sequence = (int) $this->pdo->lastInsertId();
            if ($sequence < 1) {
                throw new RuntimeException('g3_sandbox_sequence_missing');
            }
            $this->failIf('journal');
            $outbox = $this->pdo->prepare('INSERT INTO g3_outbox '
                . '(journal_sequence, status) VALUES (?, ?)');
            $outbox->execute([$sequence, 'blocked']);
            $this->failIf('outbox');
            $ledger = $this->pdo->prepare('INSERT INTO g3_ledger '
                . '(source_namespace, shop_id, entity_type, entity_id, '
                . 'latest_accepted_sequence, status) VALUES (?, ?, ?, ?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE latest_accepted_sequence = VALUES(latest_accepted_sequence), '
                . 'status = VALUES(status)');
            $ledger->execute([
                $row['source_namespace'], $row['shop_id'], $row['entity_type'],
                $row['entity_id'], $sequence, 'blocked',
            ]);
            $this->failIf('ledger');
            $this->pdo->commit();
            $row['accepted_sequence'] = $sequence;
            return ['row' => $row, 'inserted' => true];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        } finally {
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }

    private function failIf($stage)
    {
        if ($this->failureStage === $stage) {
            throw new RuntimeException('g3_sandbox_injected_' . $stage);
        }
    }

    private static function asContractRow(array $row)
    {
        return [
            'source_namespace' => (string) $row['source_namespace'],
            'shop_id' => (int) $row['shop_id'],
            'entity_type' => (string) $row['entity_type'],
            'entity_id' => (int) $row['entity_id'],
            'dedupe_fingerprint' => (string) $row['dedupe_fingerprint'],
            'original_event_type' => (string) $row['original_event_type'],
            'captured_at_utc' => (string) $row['captured_at_utc'],
            'captured_at_precision' => (string) $row['captured_at_precision'],
            'clock_source' => (string) $row['clock_source'],
            'clock_sync_verified' => (bool) $row['clock_sync_verified'],
            'source_modified_provenance' => (string) $row['source_modified_provenance'],
            'accepted_sequence' => (int) $row['accepted_sequence'],
        ];
    }
}
