<?php
/**
 * Real MySQL/MariaDB CI sandbox test, no merchant DB or Salesforce access.
 * Requires an exact ephemeral database and an unprivileged CI user.
 */
require_once dirname(__DIR__, 2) . '/pssalesforcebridge/src/DataCloud/LiveEventUtcObservation.php';
require_once dirname(__DIR__, 2) . '/pssalesforcebridge/src/DataCloud/StagedAtomicUtcAcceptance.php';
require_once __DIR__ . '/G3SandboxPdoJournal.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\StagedAtomicUtcAcceptance;

function g3Check($ok, $why)
{
    if (!$ok) {
        throw new RuntimeException('g3_ci_' . $why);
    }
}
function g3Connect()
{
    $dsn = 'mysql:host=127.0.0.1;port=3306;dbname=g3_ci;charset=utf8mb4';
    g3Check(getenv('G3_CI_ENABLE') === 'yes_sandbox_only'
        && getenv('G3_CI_DSN') === $dsn
        && getenv('G3_CI_USER') === 'g3_ci_user'
        && getenv('G3_CI_PASSWORD') !== false, 'unsafe_connection');
    $pdo = null;
    for ($attempt = 0; $attempt < 35; ++$attempt) {
        try {
            $pdo = new PDO($dsn, 'g3_ci_user', getenv('G3_CI_PASSWORD'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            break;
        } catch (PDOException $e) {
            usleep(200000);
        }
    }
    g3Check($pdo instanceof PDO, 'database_not_available');
    g3Check($pdo->query('SELECT DATABASE()')->fetchColumn() === 'g3_ci', 'database_mismatch');
    return $pdo;
}
function g3Count(PDO $pdo, $table)
{
    g3Check(in_array($table, ['g3_journal', 'g3_outbox', 'g3_ledger'], true), 'table_unapproved');
    return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}
function g3Accept(G3SandboxPdoJournal $store, $id, $hex, $hook = 'CUSTOMER_UPDATED',
    $namespace = 'ci_merchant', $entity = 'customer', $shop = 1)
{
    $legacyKey = $entity . ':' . $shop . ':' . $id . ':' . str_repeat($hex, 40);
    return StagedAtomicUtcAcceptance::accept($namespace, $hook, $entity,
        $shop, $id, $legacyKey, [$store, 'insertOrLoad']);
}
function g3Reset(PDO $pdo)
{
    // Drop only fixed sandbox-owned tables after exact DSN/user validation.
    foreach (['g3_ledger', 'g3_outbox', 'g3_journal'] as $table) {
        $pdo->exec('DROP TABLE IF EXISTS ' . $table);
    }
    $sql = file_get_contents(__DIR__ . '/g3_sandbox_schema.sql');
    foreach (explode(';', $sql) as $statement) {
        if (trim($statement) !== '') {
            $pdo->exec(trim($statement));
        }
    }
}
function g3Sequential(PDO $pdo)
{
    foreach (['journal' => 'd', 'outbox' => 'e', 'ledger' => 'f'] as $stage => $hex) {
        $failed = false;
        try {
            g3Accept(new G3SandboxPdoJournal($pdo, $stage), 123, $hex);
        } catch (RuntimeException $e) {
            $failed = $e->getMessage() === 'g3_sandbox_injected_' . $stage;
        }
        g3Check($failed, 'injected_error_missing_' . $stage);
        foreach (['g3_journal', 'g3_outbox', 'g3_ledger'] as $table) {
            g3Check(g3Count($pdo, $table) === 0, 'orphaned_record_after_' . $stage);
        }
    }

    $first = g3Accept(new G3SandboxPdoJournal($pdo), 123, 'a', 'CUSTOMER_CREATED');
    g3Check($first['inserted'] && g3Count($pdo, 'g3_outbox') === 1, 'first_commit');
    // Same commercial dedupe, even if hook name changes: preserve first UTC.
    $again = g3Accept(new G3SandboxPdoJournal($pdo), 123, 'a', 'CUSTOMER_UPDATED');
    g3Check(!$again['inserted'] && $again['captured_at_reused']
        && $again['captured_at_utc'] === $first['captured_at_utc']
        && $again['row']['original_event_type'] === 'CUSTOMER_CREATED'
        && $again['accepted_sequence'] === $first['accepted_sequence'], 'retry_regenerated_clock');
    $later = g3Accept(new G3SandboxPdoJournal($pdo), 123, 'b');
    g3Check($later['inserted'] && $later['accepted_sequence'] > $first['accepted_sequence'], 'new_payload');
    $seq = (int) $pdo->query("SELECT latest_accepted_sequence FROM g3_ledger WHERE "
        . "source_namespace = 'ci_merchant' AND shop_id = 1 "
        . "AND entity_type = 'customer' AND entity_id = 123")->fetchColumn();
    g3Check($seq === $later['accepted_sequence'], 'ledger_not_atomic');
    $merchant = g3Accept(new G3SandboxPdoJournal($pdo), 123, 'a', 'CUSTOMER_CREATED',
        'another_merchant');
    $shop = g3Accept(new G3SandboxPdoJournal($pdo), 123, 'a', 'CUSTOMER_CREATED',
        'ci_merchant', 'customer', 2);
    $order = g3Accept(new G3SandboxPdoJournal($pdo), 123, 'a', 'ORDER_PAID',
        'ci_merchant', 'order');
    g3Check($merchant['inserted'] && $shop['inserted'] && $order['inserted']
        && g3Count($pdo, 'g3_journal') === 5
        && g3Count($pdo, 'g3_outbox') === 5
        && g3Count($pdo, 'g3_ledger') === 4, 'namespace_or_outbox_integrity');
}
function g3Parallel(PDO $pdo)
{
    // Eight separate PHP processes and separate PDO connections race on one
    // dedupe fingerprint while the winner holds a 0.3s advisory lock.
    $processes = [];
    for ($i = 0; $i < 8; ++$i) {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' worker';
        $p = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        g3Check(is_resource($p), 'worker_start_failed');
        fclose($pipes[0]);
        $processes[] = [$p, $pipes];
    }
    $uniqueTime = [];
    $uniqueSequence = [];
    $insertions = 0;
    foreach ($processes as $entry) {
        $stdout = stream_get_contents($entry[1][1]);
        $stderr = stream_get_contents($entry[1][2]);
        fclose($entry[1][1]);
        fclose($entry[1][2]);
        $exit = proc_close($entry[0]);
        g3Check($exit === 0, 'worker_failed_' . substr($stderr, 0, 100));
        $row = json_decode($stdout, true);
        g3Check(is_array($row) && isset($row['captured_at_utc']), 'bad_worker_data');
        $insertions += $row['inserted'] ? 1 : 0;
        $uniqueTime[$row['captured_at_utc']] = true;
        $uniqueSequence[$row['accepted_sequence']] = true;
        g3Check($row['data_360_ingestion_authorized'] === false, 'unblocked_ingestion');
    }
    g3Check($insertions === 1 && count($uniqueTime) === 1
        && count($uniqueSequence) === 1, 'concurrent_duplicate_or_clock_changed');
    g3Check(g3Count($pdo, 'g3_journal') === 6
        && g3Count($pdo, 'g3_outbox') === 6
        && g3Count($pdo, 'g3_ledger') === 5, 'concurrent_partial_commit');
    g3Check((int) $pdo->query("SELECT COUNT(*) FROM g3_outbox WHERE status <> 'blocked'")
        ->fetchColumn() === 0, 'outbox_unblocked');
}

try {
    $pdo = g3Connect();
    if (isset($argv[1]) && $argv[1] === 'worker') {
        $result = g3Accept(new G3SandboxPdoJournal($pdo, '', 300000), 888, 'c');
        echo json_encode([
            'inserted' => $result['inserted'],
            'captured_at_utc' => $result['captured_at_utc'],
            'accepted_sequence' => $result['accepted_sequence'],
            'data_360_ingestion_authorized' => $result['data_360_ingestion_authorized'],
        ]);
        exit(0);
    }
    g3Reset($pdo);
    g3Sequential($pdo);
    g3Parallel($pdo);
    echo "G3 real DB sandbox passed: atomic rollback + retry + 8 concurrent workers; no sends.\n";
} catch (Throwable $e) {
    // No DSNs or PDO exceptions may leak test credentials in runner output.
    fwrite(STDERR, "G3 sandbox check failed at acceptance / schema / concurrency gate.\n");
    exit(1);
}
