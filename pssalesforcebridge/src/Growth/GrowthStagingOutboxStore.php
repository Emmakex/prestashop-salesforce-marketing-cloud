<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use Db;
use RuntimeException;
use Throwable;

/**
 * Explicit staging-only PrestaShop Db adapter to the EXISTING G3 Growth
 * binding/outbox/ledger schema. No second queue, no raw PII, no HTTP.
 *
 * Unlike PrestaShopDbBlockedOutboxStore (sandbox-only), this adapter can
 * operate against a merchant STAGING database, but only when an authorized
 * local Test capture gate has already been armed in the Back Office.
 * It refuses missing binding, Test revocation and unapproved database
 * engines; default installation never invokes it.
 */
final class GrowthStagingOutboxStore
{
    private $db;
    private $tables;
    private $namespace;
    private $merchant;
    private $version;
    private $failureStage;

    public function __construct(
        $db, $version, $merchant, $provider, $namespace, $mode,
        $prefix, $failureStage = ''
    ) {
        self::assertStagingGate($db);
        $plan = GrowthOutboxSchemaPlan::preview(
            $version, $merchant, $provider, $namespace, $mode, $prefix
        );
        if ($failureStage !== '' && !in_array($failureStage, ['outbox', 'ledger'], true)) {
            throw new RuntimeException('growth_staging_store_invalid_test_failpoint');
        }
        if (!is_object($db)
            || !is_callable([$db, 'executeS'])
            || !is_callable([$db, 'execute'])
            || !is_callable([$db, 'getValue'])
            || !is_callable([$db, 'Insert_ID'])) {
            throw new RuntimeException('growth_staging_store_invalid_prestashop_db');
        }
        $this->db = $db;
        $this->tables = $plan['tables'];
        $this->namespace = $namespace;
        $this->merchant = $merchant;
        $this->version = $version;
        $this->failureStage = $failureStage;
        $this->checkBinding();
    }

    /**
     * Implements StagedAtomicUtcAcceptance insertOrLoad callback interface.
     * One named lock protects source entity order across concurrent requests.
     * A single transaction atomically writes outbox and ledger. It never
     * creates a job, sends email, or invokes Salesforce.
     */
    public function insertOrLoad(array $identity, $factory)
    {
        if (!is_callable($factory)) {
            throw new RuntimeException('growth_staging_store_factory_required');
        }
        $this->checkIdentity($identity);
        self::assertStagingGate($this->db);
        $this->checkBinding();
        $fingerprint = $identity['dedupe_fingerprint'];
        $lockName = hash(
            'sha256',
            'growth-outbox-entity:' . $identity['source_namespace'] . "\n"
            . $identity['shop_id'] . ':' . $identity['entity_type']
            . ':' . $identity['entity_id']
        );
        // Never cache advisory-lock acquisition; stale lock state breaks
        // cross-request exclusion in real PrestaShop Db implementations.
        if ((string) $this->db->getValue(
            "SELECT GET_LOCK('" . $lockName . "', 10)", false
        ) !== '1') {
            throw new RuntimeException('growth_staging_store_lock_unavailable');
        }

        $transactionStarted = false;
        try {
            if (!$this->db->execute('START TRANSACTION')) {
                throw new RuntimeException('growth_staging_store_transaction_failed');
            }
            $transactionStarted = true;
            $sql = 'SELECT '
                . 'accepted_sequence, source_namespace, shop_id, entity_type, '
                . 'entity_id, dedupe_fingerprint, original_event_type, '
                . 'captured_at_utc, captured_at_precision, clock_source, '
                . 'clock_sync_verified, source_modified_provenance FROM '
                . self::quote($this->tables['outbox'])
                . " WHERE source_namespace = '" . $identity['source_namespace'] . "'"
                . " AND dedupe_fingerprint = '" . $fingerprint . "'"
                . ' FOR UPDATE';
            // Third arg explicitly disables PrestaShop's SELECT cache.
            $rows = $this->db->executeS($sql, true, false);
            if (!is_array($rows) || count($rows) > 1) {
                throw new RuntimeException('growth_staging_store_select_unverified');
            }

            if (count($rows) === 1) {
                $existing = self::normalizeExisting($rows[0], $identity);
                if (!$this->db->execute('COMMIT')) {
                    throw new RuntimeException('growth_staging_store_commit_failed');
                }
                $transactionStarted = false;
                return ['row' => $existing, 'inserted' => false];
            }
            $row = call_user_func($factory);
            self::verifyNewRow($row, $identity);

            $insert = 'INSERT INTO ' . self::quote($this->tables['outbox'])
                . ' (source_namespace, shop_id, entity_type, entity_id,'
                . ' dedupe_fingerprint, original_event_type, captured_at_utc,'
                . ' captured_at_precision, clock_source, clock_sync_verified,'
                . ' source_modified_provenance, status) VALUES ('
                . "'" . $row['source_namespace'] . "',"
                . $row['shop_id'] . ","
                . "'" . $row['entity_type'] . "',"
                . $row['entity_id'] . ","
                . "'" . $row['dedupe_fingerprint'] . "',"
                . "'" . $row['original_event_type'] . "',"
                . "'" . $row['captured_at_utc'] . "',"
                . "'seconds','php_unix_epoch_seconds',0,'unverified','blocked')";
            if (!$this->db->execute($insert)) {
                throw new RuntimeException('growth_staging_store_outbox_insert_failed');
            }
            $sequence = $this->db->Insert_ID();
            if (!is_numeric($sequence) || !ctype_digit((string) $sequence)
                || (int) $sequence < 1 || (string) (int) $sequence !== (string) $sequence) {
                throw new RuntimeException('growth_staging_store_sequence_missing');
            }
            $sequence = (int) $sequence;
            $this->failIf('outbox');

            $ledger = 'INSERT INTO ' . self::quote($this->tables['ledger'])
                . ' (source_namespace, shop_id, entity_type, entity_id,'
                . ' latest_accepted_sequence, status) VALUES ('
                . "'" . $row['source_namespace'] . "',"
                . $row['shop_id'] . ","
                . "'" . $row['entity_type'] . "',"
                . $row['entity_id'] . ","
                . $sequence . ",'blocked')"
                . ' ON DUPLICATE KEY UPDATE'
                . ' latest_accepted_sequence = VALUES(latest_accepted_sequence),'
                . " status = 'blocked'";
            if (!$this->db->execute($ledger)) {
                throw new RuntimeException('growth_staging_store_ledger_insert_failed');
            }
            $this->failIf('ledger');
            if (!$this->db->execute('COMMIT')) {
                throw new RuntimeException('growth_staging_store_commit_failed');
            }
            $transactionStarted = false;
            $row['accepted_sequence'] = $sequence;
            return ['row' => $row, 'inserted' => true];
        } catch (Throwable $e) {
            if ($transactionStarted) {
                // Never treat an error as a successful local acceptance.
                $this->db->execute('ROLLBACK');
            }
            throw $e;
        } finally {
            // RELEASE_LOCK must execute on every attempt (no SELECT cache).
            // Do not leak source keys or query contents into logs on failure.
            $this->db->getValue("SELECT RELEASE_LOCK('" . $lockName . "')", false);
        }
    }

    private function checkIdentity(array $id)
    {
        $keys = array_keys($id);
        $expected = [
            'source_namespace', 'shop_id', 'entity_type', 'entity_id',
            'dedupe_fingerprint',
        ];
        if ($keys !== $expected || $id['source_namespace'] !== $this->namespace
            || !is_int($id['shop_id']) || $id['shop_id'] < 1
            || !is_int($id['entity_id']) || $id['entity_id'] < 1
            || !in_array($id['entity_type'], ['customer', 'order'], true)
            || !is_string($id['dedupe_fingerprint'])
            || !preg_match('/^[a-f0-9]{64}$/D', $id['dedupe_fingerprint'])) {
            throw new RuntimeException('growth_staging_store_identity_invalid');
        }
    }

    private function checkBinding()
    {
        // Consent and route gates may be revoked between events. A cached
        // COUNT(*) could incorrectly validate an obsolete provider binding.
        $sql = 'SELECT COUNT(*) FROM ' . self::quote($this->tables['binding'])
            . " WHERE source_namespace = '" . $this->namespace . "'"
            . " AND merchant = '" . $this->merchant . "'"
            . " AND prestashop_version = '" . $this->version . "'"
            . " AND provider = 'marketing_cloud_growth'"
            . " AND mode = 'test' AND status = 'blocked'";
        if ((string) $this->db->getValue($sql, false) !== '1') {
            throw new RuntimeException('growth_staging_store_binding_unverified');
        }
    }

    private static function verifyNewRow($row, array $identity)
    {
        if (!is_array($row)
            || array_keys($row) !== array_merge(array_keys($identity), [
                'original_event_type', 'captured_at_utc', 'captured_at_precision',
                'clock_source', 'clock_sync_verified',
                'source_modified_provenance',
            ])) {
            throw new RuntimeException('growth_staging_store_new_row_invalid');
        }
        foreach ($identity as $key => $value) {
            if ($row[$key] !== $value) {
                throw new RuntimeException('growth_staging_store_new_row_identity_mismatch');
            }
        }
        $events = $identity['entity_type'] === 'customer'
            ? ['CUSTOMER_CREATED', 'CUSTOMER_UPDATED']
            : ['ORDER_CREATED', 'ORDER_UPDATED', 'ORDER_PAID', 'ORDER_CANCELLED'];
        if (!in_array($row['original_event_type'], $events, true)
            || !is_string($row['captured_at_utc'])
            || !preg_match(
                '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.000Z$/D',
                $row['captured_at_utc']
            )
            || strtotime($row['captured_at_utc']) === false
            || gmdate('Y-m-d\TH:i:s', strtotime($row['captured_at_utc'])) . '.000Z'
                !== $row['captured_at_utc']
            || $row['captured_at_precision'] !== 'seconds'
            || $row['clock_source'] !== 'php_unix_epoch_seconds'
            || $row['clock_sync_verified'] !== false
            || $row['source_modified_provenance'] !== 'unverified') {
            throw new RuntimeException('growth_staging_store_unverified_observation');
        }
    }

    private static function normalizeExisting($raw, array $identity)
    {
        if (!is_array($raw) || count($raw) !== 12) {
            throw new RuntimeException('growth_staging_store_existing_row_invalid');
        }
        $keys = [
            'accepted_sequence', 'source_namespace', 'shop_id', 'entity_type',
            'entity_id', 'dedupe_fingerprint', 'original_event_type',
            'captured_at_utc', 'captured_at_precision', 'clock_source',
            'clock_sync_verified', 'source_modified_provenance',
        ];
        $rawKeys = array_keys($raw);
        sort($keys);
        sort($rawKeys);
        if ($keys !== $rawKeys) {
            throw new RuntimeException('growth_staging_store_existing_fields_invalid');
        }
        if (!is_numeric($raw['accepted_sequence'])
            || !ctype_digit((string) $raw['accepted_sequence'])
            || (int) $raw['accepted_sequence'] < 1) {
            throw new RuntimeException('growth_staging_store_existing_sequence_invalid');
        }
        $row = [
            'source_namespace' => (string) $raw['source_namespace'],
            'shop_id' => (int) $raw['shop_id'],
            'entity_type' => (string) $raw['entity_type'],
            'entity_id' => (int) $raw['entity_id'],
            'dedupe_fingerprint' => (string) $raw['dedupe_fingerprint'],
            'original_event_type' => (string) $raw['original_event_type'],
            'captured_at_utc' => (string) $raw['captured_at_utc'],
            'captured_at_precision' => (string) $raw['captured_at_precision'],
            'clock_source' => (string) $raw['clock_source'],
            'clock_sync_verified' => (bool) $raw['clock_sync_verified'],
            'source_modified_provenance' => (string) $raw['source_modified_provenance'],
            'accepted_sequence' => (int) $raw['accepted_sequence'],
        ];
        $expectedId = array_intersect_key($row, $identity);
        if ($expectedId !== $identity) {
            throw new RuntimeException('growth_staging_store_existing_identity_mismatch');
        }
        self::verifyNewRow(array_slice($row, 0, 11, true), $identity);
        if ($raw['clock_sync_verified'] !== 0 && $raw['clock_sync_verified'] !== '0') {
            throw new RuntimeException('growth_staging_store_clock_unverified');
        }
        return $row;
    }

    private function failIf($stage)
    {
        if ($this->failureStage === $stage) {
            throw new RuntimeException('growth_staging_store_injected_' . $stage);
        }
    }

    private static function quote($name)
    {
        if (!is_string($name) || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $name)) {
            throw new RuntimeException('growth_staging_store_table_invalid');
        }
        return chr(96) . $name . chr(96);
    }

    private static function assertStagingGate($db)
    {
        if (!is_object($db) || !is_callable([$db, 'getValue'])
            || !class_exists('Configuration')
            || (string) \Configuration::get('PS_SFMC_MODE') !== 'test'
            || (string) \Configuration::get('PS_GROWTH_LOCAL_CAPTURE') !== 'staging_armed'
            || !defined('_DB_PREFIX_')) {
            throw new RuntimeException('growth_staging_store_disabled');
        }
        // Fail closed if the DB cannot enforce CHECK status='blocked'.
        $engine = GrowthSqlEngineGate::inspectPrestaShop($db);
        if ($engine['check_constraints_enforced'] !== true) {
            throw new RuntimeException('growth_staging_store_sql_engine_unsupported');
        }
        $prefix = _DB_PREFIX_;
        if (!is_string($prefix)
            || !preg_match('/^[A-Za-z0-9_]{1,32}$/D', $prefix)) {
            throw new RuntimeException('growth_staging_store_prefix_invalid');
        }
    }
}
