<?php
/**
 * G7/G3 CI-only: inspect a versioned claim attempt without claiming a job.
 *
 * This deliberately uses the EXISTING blocked G3 outbox/ledger and their
 * exact entity advisory lock. The current schema cannot persist a lease or
 * a cancel state: neither a clean source read nor a matching revision
 * authorizes an outbound action. Never deploy as a PrestaShop worker.
 */
final class G3GrowthBlockedVersionClaimReview
{
    private $db;

    public function __construct(PDO $db)
    {
        // Reuse the existing strict g3_ci DSN, SQL account and schema checks.
        $guard = new G3GrowthOutboxSandboxMigration($db);
        $guard->assertSchema();
        $this->db = $db;
    }

    public function inspect(
        $namespace, $shopId, $entityType, $entityId,
        $expectedSequence, $readLocalExclusion
    ) {
        if (!is_string($namespace)
            || !preg_match('/^[A-Za-z0-9_]{1,32}$/D', $namespace)
            || !is_int($shopId) || $shopId < 1
            || !in_array($entityType, ['customer', 'order'], true)
            || !is_int($entityId) || $entityId < 1
            || !is_int($expectedSequence) || $expectedSequence < 1
            || !is_callable($readLocalExclusion)) {
            // 'cart' is deliberately NOT a supported outbox entity.
            throw new RuntimeException('g7_g3_claim_scope_or_callback_invalid');
        }

        $key = hash('sha256', 'growth-outbox-entity:' . $namespace
            . "\n" . $shopId . ':' . $entityType . ':' . $entityId);
        $lock = $this->db->prepare('SELECT GET_LOCK(?, 10)');
        $lock->execute([$key]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('g7_g3_claim_lock_unavailable');
        }

        try {
            $this->db->beginTransaction();
            // Lock and compare the accepted ledger version. No new table,
            // UPDATE, outbound claim state, token, or lease is created.
            $ledger = $this->db->prepare(
                'SELECT latest_accepted_sequence, status'
                . ' FROM g3_growth_entity_ledger_v1'
                . ' WHERE source_namespace=? AND shop_id=?'
                . ' AND entity_type=? AND entity_id=? FOR UPDATE'
            );
            $ledger->execute([$namespace, $shopId, $entityType, $entityId]);
            $row = $ledger->fetch(PDO::FETCH_ASSOC);
            $state = 'source_unverified';
            $sameVersion = false;
            $negativeObserved = false;
            if (is_array($row) && $row['status'] === 'blocked'
                && ctype_digit((string) $row['latest_accepted_sequence'])) {
                $actual = (int) $row['latest_accepted_sequence'];
                if ($actual > 0 && $actual !== $expectedSequence) {
                    $state = 'stale_version_blocked';
                } elseif ($actual === $expectedSequence) {
                    $outbox = $this->db->prepare(
                        'SELECT accepted_sequence FROM g3_growth_outbox_v1'
                        . ' WHERE accepted_sequence=? AND source_namespace=?'
                        . ' AND shop_id=? AND entity_type=? AND entity_id=?'
                        . " AND status='blocked' FOR UPDATE"
                    );
                    $outbox->execute([
                        $expectedSequence, $namespace, $shopId, $entityType, $entityId
                    ]);
                    $matching = $outbox->fetchAll(PDO::FETCH_ASSOC);
                    if (count($matching) === 1) {
                        $sameVersion = true;
                        try {
                            $observation = call_user_func($readLocalExclusion);
                        } catch (Throwable $error) {
                            $observation = 'unknown';
                        }
                        // Callback is a synthetic review input, NOT Growth
                        // CSC proof. Invalid/missing results fail closed.
                        if ($observation === 'purchase_found') {
                            $state = 'purchase_cancellation_observed';
                            $negativeObserved = true;
                        } elseif ($observation === 'optout') {
                            $state = 'optout_cancellation_observed';
                            $negativeObserved = true;
                        } elseif ($observation === 'clear') {
                            $state = 'version_matches_but_claim_forbidden';
                        } else {
                            $state = 'local_exclusion_unverified';
                        }
                    }
                }
            }
            $this->db->commit();
            return [
                'status' => 'hold_for_review',
                'claim_review_state' => $state,
                'expected_version_matches_ledger' => $sameVersion,
                'local_negative_observed' => $negativeObserved,
                'claim_persisted' => false,
                'claim_authorized' => false,
                'local_cancellation_persisted' => false,
                'transactional_delivery_fence_certified' => false,
                'growth_consent_certified' => false,
                'cart_outbox_supported' => false,
                'outbound_worker_authorized' => false,
                'data_cloud_ingestion_authorized' => false,
                'marketing_sends_authorized' => false,
            ];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            // No SQL, identifiers, data, callback errors or credentials.
            throw new RuntimeException('g7_g3_claim_review_failed');
        } finally {
            $release = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$key]);
        }
    }
}
