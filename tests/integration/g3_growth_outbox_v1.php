<?php
/**
 * CI-only isolated MySQL/MariaDB test for a separate Growth outbox.
 * NEVER executes DDL in PrestaShop or touches Salesforce.
 */
require_once dirname(__DIR__, 2) . '/pssalesforcebridge/src/DataCloud/LiveEventUtcObservation.php';
require_once dirname(__DIR__, 2) . '/pssalesforcebridge/src/DataCloud/StagedAtomicUtcAcceptance.php';
require_once __DIR__ . '/G3GrowthOutboxSandboxMigration.php';
require_once __DIR__ . '/G3GrowthOutboxSandboxStore.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\StagedAtomicUtcAcceptance;

function g3GrowthAssert($condition, $reason)
{
    if (!$condition) {
        throw new RuntimeException('g3_growth_' . $reason);
    }
}
function g3GrowthConnection()
{
    $dsn = 'mysql:host=127.0.0.1;port=3306;dbname=g3_ci;charset=utf8mb4';
    g3GrowthAssert(getenv('G3_CI_ENABLE') === 'yes_sandbox_only'
        && getenv('G3_CI_DSN') === $dsn
        && getenv('G3_CI_USER') === 'g3_ci_user'
        && getenv('G3_CI_PASSWORD') === 'g3_ci_pass_only', 'connection_not_sandbox');
    for ($i = 0; $i < 30; ++$i) {
        try {
            $pdo = new PDO($dsn, 'g3_ci_user', getenv('G3_CI_PASSWORD'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            new G3GrowthOutboxSandboxMigration($pdo); // verify DB and SQL account.
            return $pdo;
        } catch (PDOException $e) {
            usleep(200000);
        }
    }
    throw new RuntimeException('g3_growth_sandbox_unreachable');
}
function g3GrowthCount(PDO $db, $table)
{
    g3GrowthAssert(in_array($table, [
        'g3_growth_outbox_v1', 'g3_growth_entity_ledger_v1',
        'g3_sfmc_queue', 'g3_sfmc_customer_sync', 'g3_sfmc_order_sync',
    ], true), 'count_invalid_table');
    return (int) $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}
function g3GrowthAccept(PDO $db, $id, $hashChar, $namespace = 'growth_ci_a',
    $shop = 1, $entity = 'customer', $hook = 'CUSTOMER_CREATED', $fail = '', $delay = 0)
{
    $legacy = $entity . ':' . $shop . ':' . $id . ':' . str_repeat($hashChar, 40);
    $store = new G3GrowthOutboxSandboxStore($db, $fail, $delay);
    return StagedAtomicUtcAcceptance::accept($namespace, $hook, $entity,
        $shop, $id, $legacy, [$store, 'insertOrLoad']);
}
function g3GrowthMustFail($callback, $message)
{
    $failed = false;
    try {
        call_user_func($callback);
    } catch (Throwable $e) {
        $failed = true;
    }
    g3GrowthAssert($failed, $message);
}
function g3GrowthWorker()
{
    $db = g3GrowthConnection();
    $r = g3GrowthAccept($db, 87, 'c', 'growth_ci_a', 1, 'customer',
        'CUSTOMER_CREATED', '', 200000);
    echo json_encode([
        'inserted' => $r['inserted'],
        'captured_at_utc' => $r['captured_at_utc'],
        'accepted_sequence' => $r['accepted_sequence'],
        'data_360_ingestion_authorized' => $r['data_360_ingestion_authorized'],
        'marketing_sends_authorized' => $r['marketing_sends_authorized'],
    ]);
}
function g3GrowthConcurrency(PDO $db)
{
    $processes = [];
    for ($i = 0; $i < 8; ++$i) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' worker';
        $p = proc_open($cmd, [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        g3GrowthAssert(is_resource($p), 'worker_start');
        fclose($pipes[0]);
        $processes[] = [$p, $pipes];
    }
    $first = 0;
    $timestamps = [];
    $sequences = [];
    foreach ($processes as $p) {
        $out = stream_get_contents($p[1][1]);
        $err = stream_get_contents($p[1][2]);
        fclose($p[1][1]); fclose($p[1][2]);
        g3GrowthAssert(proc_close($p[0]) === 0, 'concurrent_worker_' . substr($err, 0, 60));
        $r = json_decode($out, true);
        g3GrowthAssert(is_array($r) && isset($r['captured_at_utc']), 'worker_result');
        $first += $r['inserted'] ? 1 : 0;
        $timestamps[$r['captured_at_utc']] = true;
        $sequences[$r['accepted_sequence']] = true;
        g3GrowthAssert($r['data_360_ingestion_authorized'] === false
            && $r['marketing_sends_authorized'] === false, 'worker_unblocked');
    }
    g3GrowthAssert($first === 1 && count($timestamps) === 1
        && count($sequences) === 1, 'concurrent_duplicate_or_retimestamp');
    g3GrowthAssert((int) $db->query("SELECT COUNT(*) FROM g3_growth_outbox_v1 "
        . "WHERE source_namespace = 'growth_ci_a' AND entity_id = 87")->fetchColumn() === 1,
        'concurrent_extra_row');
}
function g3GrowthTests(PDO $db)
{
    $migration = new G3GrowthOutboxSandboxMigration($db);
    // Don't clean up or overwrite legacy tables; verify that nothing touches
    // previous 0.7.1 mirror records present in the same CI database.
    $legacy = [];
    foreach (['g3_sfmc_queue', 'g3_sfmc_customer_sync', 'g3_sfmc_order_sync'] as $table) {
        $legacy[$table] = g3GrowthCount($db, $table);
    }
    g3GrowthAssert($migration->install() === true, 'first_schema_install');
    g3GrowthAssert($migration->install() === false, 'schema_upgrade_idempotency');
    $migration->assertSchema();

    foreach (['outbox' => 'd', 'ledger' => 'e'] as $stage => $hex) {
        g3GrowthMustFail(function () use ($db, $stage, $hex) {
            g3GrowthAccept($db, 31, $hex, 'growth_ci_a', 1, 'customer',
                'CUSTOMER_CREATED', $stage);
        }, 'missing_injected_failure_' . $stage);
        g3GrowthAssert(g3GrowthCount($db, 'g3_growth_outbox_v1') === 0
            && g3GrowthCount($db, 'g3_growth_entity_ledger_v1') === 0,
            'partial_commit_' . $stage);
    }

    $first = g3GrowthAccept($db, 31, 'a');
    $retry = g3GrowthAccept($db, 31, 'a', 'growth_ci_a', 1, 'customer',
        'CUSTOMER_UPDATED');
    g3GrowthAssert($first['inserted'] && !$retry['inserted']
        && $retry['captured_at_reused']
        && $first['captured_at_utc'] === $retry['captured_at_utc']
        && $first['accepted_sequence'] === $retry['accepted_sequence']
        && $retry['row']['original_event_type'] === 'CUSTOMER_CREATED', 'replay_not_idempotent');
    $changed = g3GrowthAccept($db, 31, 'b', 'growth_ci_a', 1, 'customer',
        'CUSTOMER_UPDATED');
    g3GrowthAssert($changed['inserted'] && $changed['accepted_sequence'] > $first['accepted_sequence'],
        'new_payload_not_inserted');
    $old = g3GrowthAccept($db, 31, 'a');
    $current = (int) $db->query("SELECT latest_accepted_sequence FROM "
        . "g3_growth_entity_ledger_v1 WHERE source_namespace = 'growth_ci_a' "
        . "AND shop_id = 1 AND entity_type = 'customer' AND entity_id = 31")->fetchColumn();
    g3GrowthAssert(!$old['inserted'] && $current === $changed['accepted_sequence'],
        'stale_replay_regressed_ledger');
    $otherNamespace = g3GrowthAccept($db, 31, 'a', 'growth_ci_b');
    $otherShop = g3GrowthAccept($db, 31, 'a', 'growth_ci_a', 2);
    $order = g3GrowthAccept($db, 31, 'a', 'growth_ci_a', 1, 'order', 'ORDER_PAID');
    g3GrowthAssert($otherNamespace['inserted'] && $otherShop['inserted']
        && $order['inserted']
        && g3GrowthCount($db, 'g3_growth_outbox_v1') === 5
        && g3GrowthCount($db, 'g3_growth_entity_ledger_v1') === 4,
        'tenant_shop_entity_collision');

    // Neither table has a sendable state in this version of the contract.
    g3GrowthMustFail(function () use ($db) {
        $db->exec("UPDATE g3_growth_outbox_v1 SET status = 'pending' WHERE entity_id = 31");
    }, 'status_check_not_enforced_outbox');
    g3GrowthMustFail(function () use ($db) {
        $db->exec("UPDATE g3_growth_entity_ledger_v1 SET status = 'ready' WHERE entity_id = 31");
    }, 'status_check_not_enforced_ledger');
    g3GrowthAssert((int) $db->query("SELECT COUNT(*) FROM g3_growth_outbox_v1 "
        . "WHERE status <> 'blocked'")->fetchColumn() === 0, 'outbox_status_changed');

    g3GrowthConcurrency($db);

    g3GrowthMustFail(function () use ($migration) {
        $migration->rollbackIfEmpty();
    }, 'rollback_must_refuse_nonempty');
    // The only DELETE operations in this test act on the two namespaced,
    // disposable CI-owned tables after explicit sandbox DSN/user checks.
    $db->exec('DELETE FROM g3_growth_entity_ledger_v1');
    $db->exec('DELETE FROM g3_growth_outbox_v1');
    g3GrowthAssert($migration->rollbackIfEmpty() === true, 'empty_rollback');
    g3GrowthAssert($migration->rollbackIfEmpty() === false, 'rollback_idempotency');

    // No side effects in legacy tables, including after rollback.
    foreach ($legacy as $table => $count) {
        g3GrowthAssert(g3GrowthCount($db, $table) === $count, 'legacy_mutated_' . $table);
    }

    // Partial interrupted DDL must fail closed rather than silently
    // dropping an existing table, or migrating any unrelated objects.
    $sql = file_get_contents(__DIR__ . '/g3_growth_outbox_v1_schema.sql');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $statements = array_values(array_filter(array_map('trim', explode(';', $sql))));
    $db->exec($statements[0]);
    g3GrowthMustFail(function () use ($migration) {
        $migration->install();
    }, 'partial_install_must_fail_closed');
    g3GrowthMustFail(function () use ($migration) {
        $migration->rollbackIfEmpty();
    }, 'partial_rollback_must_fail_closed');
    // Deliberate CI-only remediation after confirmed empty fixture table.
    g3GrowthAssert(g3GrowthCount($db, 'g3_growth_outbox_v1') === 0,
        'partial_fixture_not_empty');
    $db->exec('DROP TABLE g3_growth_outbox_v1');
    g3GrowthAssert($migration->install() === true, 'reinstall_after_remediation');
    g3GrowthAssert($migration->rollbackIfEmpty() === true, 'final_rollback');

    foreach ($legacy as $table => $count) {
        g3GrowthAssert(g3GrowthCount($db, $table) === $count,
            'legacy_changed_after_migration');
    }
}

try {
    $pdo = g3GrowthConnection();
    if (isset($argv[1]) && $argv[1] === 'worker') {
        g3GrowthWorker();
        exit(0);
    }
    g3GrowthTests($pdo);
    echo "G3 dedicated Growth outbox sandbox PASS: two DB tables, rollback, namespacing, "
        . "8 workers and legacy isolation; no sends.\n";
} catch (Throwable $e) {
    // No SQL, tokens, payloads, DSN or credentials included in CI output.
    fwrite(STDERR, "G3 Growth isolated outbox failed at a sandbox integrity gate.\n");
    exit(1);
}
