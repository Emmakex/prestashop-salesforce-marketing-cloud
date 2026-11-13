<?php
/**
 * TEST ONLY: atomic adapter against an exact 0.7.1 queue/ledger table mirror.
 *
 * Refuses non-sandbox connections. Not a PrestaShop runtime class or upgrade.
 * The legacy queue is always written with status=blocked, never pending.
 */
final class G3SandboxLegacyQueueBridge
{
    private $pdo;
    private $dedupeKey;
    private $failAt;
    private $delayUs;

    public function __construct(PDO $pdo, $dedupeKey, $failAt = '', $delayUs = 0)
    {
        $this->pdo = $pdo;
        $this->dedupeKey = (string) $dedupeKey;
        $this->failAt = (string) $failAt;
        $this->delayUs = (int) $delayUs;
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function insertOrLoad(array $identity, $factory)
    {
        if (!is_callable($factory) || !isset($identity['source_namespace'])
            || !isset($identity['dedupe_fingerprint'])
            || !isset($identity['shop_id']) || !isset($identity['entity_type'])
            || !isset($identity['entity_id'])
            || hash('sha256', $identity['source_namespace'] . "\n" . $this->dedupeKey)
                !== $identity['dedupe_fingerprint']) {
            throw new RuntimeException('g3_bridge_identity_invalid');
        }
        // Lock the ENTITY, not just the fingerprint. Distinct payload hashes
        // for the same customer/order must not race to overwrite a ledger row.
        $lockName = hash('sha256', 'g3-entity:' . $identity['source_namespace']
            . ':' . $identity['shop_id'] . ':' . $identity['entity_type']
            . ':' . $identity['entity_id']);
        $lock = $this->pdo->prepare('SELECT GET_LOCK(?, 10)');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('g3_bridge_lock_unavailable');
        }
        try {
            $this->pdo->beginTransaction();
            $q = $this->pdo->prepare('SELECT accepted_sequence, source_namespace, shop_id, '
                . 'entity_type, entity_id, dedupe_fingerprint, original_event_type, '
                . 'captured_at_utc, captured_at_precision, clock_source, '
                . 'clock_sync_verified, source_modified_provenance, legacy_queue_id '
                . 'FROM g3_growth_journal WHERE source_namespace = ? '
                . 'AND dedupe_fingerprint = ? FOR UPDATE');
            $q->execute([$identity['source_namespace'], $identity['dedupe_fingerprint']]);
            $existing = $q->fetch(PDO::FETCH_ASSOC);
            if (is_array($existing)) {
                // A duplicate with a missing/processed legacy queue is NOT
                // silently requeued. Treat as an integrity incident.
                $this->checkQueueLink($existing['legacy_queue_id'],
                    $this->dedupeKey, $existing['original_event_type']);
                $this->pdo->commit();
                return ['row' => self::row($existing), 'inserted' => false];
            }

            // 0.7.1 uniq_sfmc_dedupe has no merchant namespace. An old
            // pending/sent/failed row or another tenant may own the key:
            // reject rather than INSERT IGNORE or attach to that row.
            $collision = $this->pdo->prepare('SELECT id_sfmc_queue '
                . 'FROM g3_sfmc_queue WHERE dedupe_key = ? FOR UPDATE');
            $collision->execute([$this->dedupeKey]);
            if ($collision->fetchColumn() !== false) {
                throw new RuntimeException('g3_bridge_unmapped_legacy_dedupe');
            }

            $ledgerTable = $identity['entity_type'] === 'customer'
                ? 'g3_sfmc_customer_sync' : 'g3_sfmc_order_sync';
            $idColumn = $identity['entity_type'] === 'customer'
                ? 'id_customer' : 'id_order';
            $ledger = $this->pdo->prepare('SELECT status FROM ' . $ledgerTable
                . ' WHERE id_shop = ? AND ' . $idColumn . ' = ? FOR UPDATE');
            $ledger->execute([$identity['shop_id'], $identity['entity_id']]);
            $oldStatus = $ledger->fetchColumn();
            if ($oldStatus !== false && $oldStatus !== 'blocked') {
                // Never overwrite the ledger maintained by the installed
                // Engagement worker. An opt-in migration needs its own ADR.
                throw new RuntimeException('g3_bridge_active_legacy_ledger');
            }

            $row = call_user_func($factory);
            $new = $this->pdo->prepare('INSERT INTO g3_growth_journal '
                . '(source_namespace, shop_id, entity_type, entity_id, '
                . 'dedupe_fingerprint, original_event_type, captured_at_utc, '
                . 'captured_at_precision, clock_source, clock_sync_verified, '
                . 'source_modified_provenance) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $new->execute([
                $row['source_namespace'], $row['shop_id'], $row['entity_type'],
                $row['entity_id'], $row['dedupe_fingerprint'], $row['original_event_type'],
                $row['captured_at_utc'], $row['captured_at_precision'],
                $row['clock_source'], 0, $row['source_modified_provenance'],
            ]);
            $sequence = (int) $this->pdo->lastInsertId();
            $this->failIf('journal');

            // Existing 0.7.1 payload and dedupe key shapes are preserved.
            // This exact synthetic payload cannot carry customer PII.
            $now = gmdate('Y-m-d H:i:s');
            $insertQueue = $this->pdo->prepare('INSERT INTO g3_sfmc_queue '
                . '(event_type, entity_type, entity_id, dedupe_key, payload, '
                . 'status, attempts, available_at, created_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $insertQueue->execute([
                $row['original_event_type'], $row['entity_type'],
                (string) $row['entity_id'], $this->dedupeKey,
                '{"synthetic":true}', 'blocked', 0, $now, $now,
            ]);
            $queueId = (int) $this->pdo->lastInsertId();
            if ($queueId < 1 || $sequence < 1) {
                throw new RuntimeException('g3_bridge_missing_sequence');
            }
            $this->failIf('queue');
            $link = $this->pdo->prepare('UPDATE g3_growth_journal '
                . 'SET legacy_queue_id = ? WHERE accepted_sequence = ?');
            $link->execute([$queueId, $sequence]);
            if ($link->rowCount() !== 1) {
                throw new RuntimeException('g3_bridge_link_failed');
            }
            $this->failIf('link');

            $payloadHash = substr($this->dedupeKey, -40);
            $contactKey = 'g3_synthetic_' . $row['source_namespace']
                . '_s' . $row['shop_id'] . '_' . $row['entity_type']
                . '_' . $row['entity_id'];
            if ($row['entity_type'] === 'customer') {
                $upsert = $this->pdo->prepare('INSERT INTO g3_sfmc_customer_sync '
                    . '(id_shop, id_customer, contact_key, payload_hash, status, '
                    . 'created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?) '
                    . 'ON DUPLICATE KEY UPDATE contact_key = VALUES(contact_key), '
                    . 'payload_hash = VALUES(payload_hash), '
                    . 'status = VALUES(status), updated_at = VALUES(updated_at)');
                $upsert->execute([
                    $row['shop_id'], $row['entity_id'], $contactKey,
                    $payloadHash, 'blocked', $now, $now,
                ]);
            } else {
                $upsert = $this->pdo->prepare('INSERT INTO g3_sfmc_order_sync '
                    . '(id_shop, id_order, id_cart, contact_key, payload_hash, status, '
                    . 'created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?) '
                    . 'ON DUPLICATE KEY UPDATE contact_key = VALUES(contact_key), '
                    . 'payload_hash = VALUES(payload_hash), '
                    . 'status = VALUES(status), updated_at = VALUES(updated_at)');
                $upsert->execute([
                    $row['shop_id'], $row['entity_id'], 0, $contactKey,
                    $payloadHash, 'blocked', $now, $now,
                ]);
            }
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
            $release->execute([$lockName]);
        }
    }

    private function checkQueueLink($id, $key, $event)
    {
        if (!ctype_digit((string) $id) || (int) $id < 1) {
            throw new RuntimeException('g3_bridge_queue_link_missing');
        }
        $q = $this->pdo->prepare('SELECT status, event_type, dedupe_key '
            . 'FROM g3_sfmc_queue WHERE id_sfmc_queue = ? FOR UPDATE');
        $q->execute([(int) $id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['status'] !== 'blocked'
            || $row['event_type'] !== $event || $row['dedupe_key'] !== $key) {
            throw new RuntimeException('g3_bridge_queue_link_invalid');
        }
    }

    private function failIf($stage)
    {
        if ($this->failAt === $stage) {
            throw new RuntimeException('g3_bridge_injected_' . $stage);
        }
    }

    private static function row(array $row)
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
