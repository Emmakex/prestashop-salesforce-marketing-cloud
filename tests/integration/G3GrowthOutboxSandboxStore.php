<?php
/**
 * Disposable CI database only. Dedicated Growth outbox; NO legacy queue.
 * Never a real Data 360 sender, module upgrade or PrestaShop hook handler.
 */
final class G3GrowthOutboxSandboxStore
{
    private $pdo;
    private $failureStage;
    private $delayUs;

    public function __construct(PDO $pdo, $failureStage = '', $delayUs = 0)
    {
        $this->pdo = $pdo;
        $this->failureStage = $failureStage;
        $this->delayUs = $delayUs;
    }

    public function insertOrLoad(array $identity, $factory)
    {
        if (!is_callable($factory)) {
            throw new RuntimeException('g3_outbox_factory_invalid');
        }
        $key = hash('sha256', 'growth-outbox-entity:' . $identity['source_namespace']
            . "\n" . $identity['shop_id'] . ':' . $identity['entity_type']
            . ':' . $identity['entity_id']);
        $lock = $this->pdo->prepare('SELECT GET_LOCK(?, 10)');
        $lock->execute([$key]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('g3_outbox_entity_lock_unavailable');
        }
        try {
            $this->pdo->beginTransaction();
            $q = $this->pdo->prepare('SELECT accepted_sequence, source_namespace, shop_id, '
                . 'entity_type, entity_id, dedupe_fingerprint, original_event_type, captured_at_utc, '
                . 'captured_at_precision, clock_source, clock_sync_verified, source_modified_provenance '
                . 'FROM g3_growth_outbox_v1 WHERE source_namespace = ? AND dedupe_fingerprint = ? FOR UPDATE');
            $q->execute([$identity['source_namespace'], $identity['dedupe_fingerprint']]);
            $previous = $q->fetch(PDO::FETCH_ASSOC);
            if (is_array($previous)) {
                $this->pdo->commit();
                return ['row' => self::asContractRow($previous), 'inserted' => false];
            }
            $row = call_user_func($factory);
            $insert = $this->pdo->prepare('INSERT INTO g3_growth_outbox_v1 '
                . '(source_namespace, shop_id, entity_type, entity_id, dedupe_fingerprint, '
                . 'original_event_type, captured_at_utc, captured_at_precision, clock_source, '
                . 'clock_sync_verified, source_modified_provenance, status) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $insert->execute([
                $row['source_namespace'], $row['shop_id'], $row['entity_type'],
                $row['entity_id'], $row['dedupe_fingerprint'], $row['original_event_type'],
                $row['captured_at_utc'], $row['captured_at_precision'],
                $row['clock_source'], 0, $row['source_modified_provenance'], 'blocked',
            ]);
            $sequence = (int) $this->pdo->lastInsertId();
            if ($sequence < 1) {
                throw new RuntimeException('g3_outbox_sequence_missing');
            }
            $this->failIf('outbox');
            $ledger = $this->pdo->prepare('INSERT INTO g3_growth_entity_ledger_v1 '
                . '(source_namespace, shop_id, entity_type, entity_id, latest_accepted_sequence, status) '
                . 'VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE '
                . 'latest_accepted_sequence = VALUES(latest_accepted_sequence), status = VALUES(status)');
            $ledger->execute([
                $row['source_namespace'], $row['shop_id'], $row['entity_type'],
                $row['entity_id'], $sequence, 'blocked',
            ]);
            $this->failIf('ledger');
            if ($this->delayUs > 0) {
                usleep($this->delayUs);
            }
            $this->pdo->commit();
            $row['accepted_sequence'] = $sequence;
            return ['row' => $row, 'inserted' => true];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } finally {
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$key]);
        }
    }

    private function failIf($stage)
    {
        if ($stage === $this->failureStage) {
            throw new RuntimeException('g3_outbox_injected_' . $stage);
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
