<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud;

use RuntimeException;

/**
 * G3 candidate: define the write-once acceptance boundary for NEW hook events.
 *
 * This class does NOT persist anything. Caller supplies a storage primitive
 * that MUST atomically insert-or-load by (source_namespace, dedupe_fingerprint),
 * invoking the factory only on a new insert. See tests for a synthetic store.
 *
 * NOT wired to the 0.7.1 queue, PrestaShop hooks, Data 360 or marketing.
 */
final class StagedAtomicUtcAcceptance
{
    /**
     * @param callable $insertOrLoad function(array $identity, callable $newRowFactory): array
     */
    public static function accept($namespace, $eventType, $entityType, $shopId, $entityId, $dedupeKey, $insertOrLoad)
    {
        if (!is_callable($insertOrLoad)) {
            throw new RuntimeException('g3_atomic_store_required');
        }
        if (!is_string($namespace) || !preg_match('/^[A-Za-z0-9_-]{2,32}$/D', $namespace)) {
            throw new RuntimeException('g3_installation_namespace_required');
        }
        if (!is_int($shopId) || $shopId < 1 || !is_int($entityId) || $entityId < 1) {
            throw new RuntimeException('g3_source_identity_invalid');
        }
        if (!is_string($entityType) || !in_array($entityType, ['customer', 'order'], true)) {
            throw new RuntimeException('g3_entity_type_invalid');
        }
        $events = $entityType === 'customer'
            ? ['CUSTOMER_CREATED', 'CUSTOMER_UPDATED']
            : ['ORDER_CREATED', 'ORDER_UPDATED', 'ORDER_PAID', 'ORDER_CANCELLED'];
        if (!is_string($eventType) || !in_array($eventType, $events, true)) {
            throw new RuntimeException('g3_event_type_invalid');
        }
        // Match the legacy 0.7.1 queue's existing commercial payload hash key.
        // Capture time MUST NOT be added to the dedupe input.
        $prefix = $entityType . ':' . $shopId . ':' . $entityId . ':';
        if (!is_string($dedupeKey) || strlen($dedupeKey) !== strlen($prefix) + 40
            || strpos($dedupeKey, $prefix) !== 0
            || !preg_match('/^[a-f0-9]{40}$/D', substr($dedupeKey, strlen($prefix)))) {
            throw new RuntimeException('g3_legacy_dedupe_key_invalid');
        }

        // The existing key does not contain a merchant ID. Namespace MUST be
        // part of the new journal's unique index to prevent cross-store collisions.
        $identity = [
            'source_namespace' => $namespace,
            'shop_id' => $shopId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'dedupe_fingerprint' => hash('sha256', $namespace . "\n" . $dedupeKey),
        ];
        $factoryCalls = 0;
        $newRow = null;
        $factory = function () use ($identity, $eventType, &$factoryCalls, &$newRow) {
            if ($newRow !== null) {
                return $newRow;
            }
            ++$factoryCalls;
            $observed = LiveEventUtcObservation::observeNow($eventType);
            $newRow = $identity + [
                'original_event_type' => $eventType,
                'captured_at_utc' => $observed['captured_at_utc'],
                'captured_at_precision' => 'seconds',
                'clock_source' => 'php_unix_epoch_seconds',
                'clock_sync_verified' => false,
                'source_modified_provenance' => 'unverified',
            ];
            return $newRow;
        };
        $out = call_user_func($insertOrLoad, $identity, $factory);

        if (!is_array($out)
            || !isset($out['row']) || !is_array($out['row'])
            || !array_key_exists('inserted', $out) || !is_bool($out['inserted'])) {
            throw new RuntimeException('g3_store_response_invalid');
        }
        $row = $out['row'];
        $expectedKeys = array_merge(array_keys($identity), [
            'original_event_type', 'captured_at_utc', 'captured_at_precision',
            'clock_source', 'clock_sync_verified', 'source_modified_provenance',
            'accepted_sequence',
        ]);
        if (array_keys($row) !== $expectedKeys) {
            throw new RuntimeException('g3_store_response_fields_invalid');
        }
        foreach ($identity as $key => $value) {
            if ($row[$key] !== $value) {
                throw new RuntimeException('g3_store_identity_mismatch');
            }
        }
        if (!in_array($row['original_event_type'], $events, true)
            || !is_string($row['captured_at_utc'])
            || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.000Z$/D', $row['captured_at_utc'])
            || strtotime($row['captured_at_utc']) === false
            || gmdate('Y-m-d\TH:i:s', strtotime($row['captured_at_utc'])) . '.000Z' !== $row['captured_at_utc']
            || $row['captured_at_precision'] !== 'seconds'
            || $row['clock_source'] !== 'php_unix_epoch_seconds'
            || $row['clock_sync_verified'] !== false
            || $row['source_modified_provenance'] !== 'unverified'
            || !is_int($row['accepted_sequence']) || $row['accepted_sequence'] < 1
            || ($out['inserted'] && $factoryCalls !== 1)
            || (!$out['inserted'] && $factoryCalls !== 0)) {
            throw new RuntimeException('g3_store_observation_unverified');
        }

        // The acceptance sequence is a local storage-order marker, NOT a
        // source revision and NEVER a Data 360 Record Modified watermark.
        return [
            'row' => $row,
            'inserted' => $out['inserted'],
            'captured_at_utc' => $row['captured_at_utc'],
            'accepted_sequence' => $row['accepted_sequence'],
            'captured_at_reused' => !$out['inserted'],
            'source_updated_utc' => null,
            'source_revision_verified' => false,
            'apply_out_of_order_authorized' => false,
            'record_modified_authorized' => false,
            'data_360_ingestion_authorized' => false,
            'marketing_sends_authorized' => false,
            'storage_adapter_certified' => false,
        ];
    }
}
