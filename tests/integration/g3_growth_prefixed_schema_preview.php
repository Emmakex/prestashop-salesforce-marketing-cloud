<?php
/**
 * Disposable g3_ci only: execute the *real-module* prefixed Growth DDL
 * in MySQL 8/MariaDB 10.11, exercise constraints and safe empty rollback.
 * Never a PrestaShop upgrade or a customer database operation.
 */
require_once dirname(__DIR__, 2)
    . '/pssalesforcebridge/src/Provider/ExplicitProviderRouter.php';
require_once dirname(__DIR__, 2)
    . '/pssalesforcebridge/src/Growth/GrowthOutboxSchemaPlan.php';
require_once __DIR__ . '/G3GrowthOutboxSandboxMigration.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthOutboxSchemaPlan as Schema;

function prefixedAssert($ok, $reason)
{
    if (!$ok) {
        throw new RuntimeException('g3_growth_prefixed_' . $reason);
    }
}
function prefixedReject($fn, $reason)
{
    try {
        call_user_func($fn);
    } catch (Throwable $e) {
        return;
    }
    prefixedAssert(false, $reason);
}
function prefixedDb()
{
    $dsn = 'mysql:host=127.0.0.1;port=3306;dbname=g3_ci;charset=utf8mb4';
    prefixedAssert(getenv('G3_CI_ENABLE') === 'yes_sandbox_only'
        && getenv('G3_CI_DSN') === $dsn
        && getenv('G3_CI_USER') === 'g3_ci_user'
        && getenv('G3_CI_PASSWORD') === 'g3_ci_pass_only',
        'sandbox_environment_required');
    $db = new PDO($dsn, 'g3_ci_user', 'g3_ci_pass_only', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    // Existing sandbox guard additionally verifies DATABASE() + CURRENT_USER().
    new G3GrowthOutboxSandboxMigration($db);
    return $db;
}
function prefixedCount(PDO $db, $name)
{
    prefixedAssert(is_string($name)
        && preg_match('/^[a-z0-9_]{1,64}$/D', $name), 'unsafe_table_identifier');
    if (strpos($name, 'g3_sfmc_') === 0) {
        $p = $db->query("SELECT COUNT(*) FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $name . "'");
        if ((int) $p->fetchColumn() === 0) {
            return null; // absence is preserved, not silently recreated
        }
    }
    return (int) $db->query('SELECT COUNT(*) FROM ' . $name)->fetchColumn();
}
function prefixedInventory(PDO $db, array $plan)
{
    $placeholders = implode(',', array_fill(0, count($plan['tables']), '?'));
    $stmt = $db->prepare('SELECT TABLE_NAME FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $placeholders . ')');
    $stmt->execute(array_values($plan['tables']));
    return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
}
function prefixedInstallFixture(PDO $db, array $plan)
{
    prefixedAssert(Schema::inventoryState($plan, prefixedInventory($db, $plan))
        === 'absent_review_only', 'preexisting_schema');
    foreach ($plan['create_sql'] as $statement) {
        $db->exec($statement);
    }
    prefixedAssert(Schema::inventoryState($plan, prefixedInventory($db, $plan))
        === 'present_requires_schema_integrity_review', 'new_schema_missing');
    $stmt = $db->prepare('INSERT INTO ' . $plan['tables']['binding']
        . ' (source_namespace, merchant, provider, prestashop_version, mode, status)'
        . ' VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute($plan['binding_values']);
    return $stmt;
}
function prefixedRollbackIfEmpty(PDO $db, array $plan)
{
    $state = Schema::inventoryState($plan, prefixedInventory($db, $plan));
    if ($state === 'absent_review_only') {
        return false;
    }
    if ($state !== 'present_requires_schema_integrity_review') {
        throw new RuntimeException('g3_growth_partial_schema_manual_review');
    }
    foreach (array_values($plan['tables']) as $name) {
        if (prefixedCount($db, $name) !== 0) {
            throw new RuntimeException('g3_growth_rollback_nonempty_forbidden');
        }
    }
    // This helper exists ONLY inside this g3_ci integration test; it cannot
    // be imported into the module to DROP tables in Barcelona Confort.
    foreach ($plan['drop_order_if_empty'] as $name) {
        $db->exec('DROP TABLE ' . $name);
    }
    return true;
}
function prefixedCheckDbConstraints(PDO $db, array $plan)
{
    foreach ($plan['tables'] as $kind => $name) {
        $q = $db->prepare('SELECT ENGINE FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $q->execute([$name]);
        prefixedAssert(strtolower((string) $q->fetchColumn()) === 'innodb',
            'nontransactional_' . $kind);
        $q = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE = ?');
        $q->execute([$name, 'CHECK']);
        prefixedAssert((int) $q->fetchColumn() >= 1, 'blocked_check_missing_' . $kind);
    }
    $q = $db->prepare('SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS '
        . 'WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    foreach (['outbox', 'ledger'] as $kind) {
        $q->execute([$plan['tables'][$kind]]);
        prefixedAssert((int) $q->fetchColumn() === 1, 'fk_missing_' . $kind);
    }
    $q = $db->prepare('SELECT COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
        . 'AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX');
    $q->execute([$plan['tables']['outbox'], 'uq_growth_event_v1']);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    prefixedAssert(count($rows) === 2
        && $rows[0]['COLUMN_NAME'] === 'source_namespace'
        && $rows[1]['COLUMN_NAME'] === 'dedupe_fingerprint'
        && (int) $rows[0]['NON_UNIQUE'] === 0,
        'unique_namespace_fingerprint_missing');
}
function prefixedInsertOutbox(PDO $db, array $plan, $namespace, $fingerprint, $status)
{
    $stmt = $db->prepare('INSERT INTO ' . $plan['tables']['outbox']
        . ' (source_namespace, shop_id, entity_type, entity_id, dedupe_fingerprint,'
        . ' original_event_type, captured_at_utc, captured_at_precision, clock_source,'
        . ' clock_sync_verified, source_modified_provenance, status) '
        . 'VALUES (?, 1, ?, 44, ?, ?, ?, ?, ?, 0, ?, ?)');
    $stmt->execute([
        $namespace, 'customer', $fingerprint, 'CUSTOMER_CREATED',
        '2026-11-14T12:00:00.000Z', 'seconds', 'php_unix_epoch_seconds',
        'unverified', $status
    ]);
    return (int) $db->lastInsertId();
}
function prefixedFixtureTests(PDO $db)
{
    $legacy = [];
    foreach (['g3_sfmc_queue', 'g3_sfmc_customer_sync', 'g3_sfmc_order_sync'] as $name) {
        $legacy[$name] = prefixedCount($db, $name);
    }
    $plans = [
        Schema::preview('1.7.8.11', 'ABH', 'marketing_cloud_growth',
            'growth_ci_shop_a', 'test', 'ci_a_'),
        Schema::preview('9.1.5', 'OTHER_MERCHANT', 'marketing_cloud_growth',
            'growth_ci_shop_b', 'test', 'ci_b_'),
    ];
    foreach ($plans as $i => $plan) {
        $insertBinding = prefixedInstallFixture($db, $plan);
        prefixedCheckDbConstraints($db, $plan);
        $namespace = $plan['binding_values'][0];
        $outbox = $plan['tables']['outbox'];
        $ledger = $plan['tables']['ledger'];
        $binding = $plan['tables']['binding'];
        prefixedAssert(prefixedCount($db, $binding) === 1, 'binding_missing_' . $i);
        prefixedReject(function () use ($db, $binding, $namespace) {
            $db->exec("UPDATE " . $binding . " SET status='ready'");
        }, 'mutable_provider_binding_' . $i);
        prefixedReject(function () use ($db, $binding) {
            $db->exec("UPDATE " . $binding . " SET provider='unsupported_external_product'");
        }, 'retired_provider_fallback_' . $i);
        prefixedReject(function () use ($db, $plan, $namespace) {
            prefixedInsertOutbox($db, $plan, $namespace, str_repeat('d', 64), 'ready');
        }, 'sendable_outbox_' . $i);
        prefixedReject(function () use ($db, $plan) {
            prefixedInsertOutbox($db, $plan, 'growth_ci_missing',
                str_repeat('e', 64), 'blocked');
        }, 'unbound_namespace_' . $i);
        $seq = prefixedInsertOutbox($db, $plan, $namespace, str_repeat('a', 64), 'blocked');
        prefixedAssert($seq > 0, 'sequence_missing_' . $i);
        prefixedReject(function () use ($db, $plan, $namespace) {
            prefixedInsertOutbox($db, $plan, $namespace, str_repeat('a', 64), 'blocked');
        }, 'event_fingerprint_not_unique_' . $i);
        $q = $db->prepare('INSERT INTO ' . $ledger
            . ' (source_namespace, shop_id, entity_type, entity_id,'
            . ' latest_accepted_sequence, status) VALUES (?,1,?,?,?,?)');
        prefixedReject(function () use ($q, $namespace) {
            $q->execute([$namespace, 'customer', 44, 987654, 'blocked']);
        }, 'orphan_ledger_accepted_' . $i);
        $q->execute([$namespace, 'customer', 44, $seq, 'blocked']);
        prefixedReject(function () use ($db, $ledger) {
            $db->exec("UPDATE " . $ledger . " SET status='ready'");
        }, 'sendable_ledger_' . $i);
        prefixedReject(function () use ($db, $binding, $namespace) {
            $db->exec("DELETE FROM " . $binding);
        }, 'binding_deleted_with_outbox_' . $i);
        prefixedReject(function () use ($db, $outbox) {
            $db->exec("DELETE FROM " . $outbox);
        }, 'outbox_deleted_with_ledger_' . $i);
        prefixedReject(function () use ($db, $plan) {
            prefixedRollbackIfEmpty($db, $plan);
        }, 'nonempty_rollback_allowed_' . $i);
        // CI only: discard synthetic records in FK order, then rollback.
        $db->exec('DELETE FROM ' . $ledger);
        $db->exec('DELETE FROM ' . $outbox);
        $db->exec('DELETE FROM ' . $binding);
        prefixedAssert(prefixedRollbackIfEmpty($db, $plan),
            'empty_rollback_failed_' . $i);
        prefixedAssert(!prefixedRollbackIfEmpty($db, $plan),
            'idempotent_rollback_failed_' . $i);
    }
    foreach ($legacy as $name => $initial) {
        prefixedAssert(prefixedCount($db, $name) === $initial,
            'legacy_table_mutated_' . $name);
    }
}
try {
    prefixedFixtureTests(prefixedDb());
    echo "Growth per-prefix DDL: MySQL/MariaDB constraints, FK and empty rollback PASS.\n";
} catch (Throwable $e) {
    // Never print SQL, DSN, credentials or merchant data from an exception.
    fwrite(STDERR, "Growth prefixed DDL sandbox rejected at a controlled gate.\n");
    exit(1);
}
