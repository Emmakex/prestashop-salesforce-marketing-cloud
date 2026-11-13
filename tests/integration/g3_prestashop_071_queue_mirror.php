<?php
/** Sandbox MySQL/MariaDB 0.7.1 queue + ledgers, never a merchant DB. */
require_once dirname(__DIR__, 2) . '/pssalesforcebridge/src/DataCloud/LiveEventUtcObservation.php';
require_once dirname(__DIR__, 2) . '/pssalesforcebridge/src/DataCloud/StagedAtomicUtcAcceptance.php';
require_once __DIR__ . '/G3SandboxLegacyQueueBridge.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\StagedAtomicUtcAcceptance;

function g3LegacyCheck($ok, $code)
{
    if (!$ok) {
        throw new RuntimeException('g3_ci_' . $code);
    }
}
function g3LegacyConnect()
{
    $dsn = 'mysql:host=127.0.0.1;port=3306;dbname=g3_ci;charset=utf8mb4';
    g3LegacyCheck(getenv('G3_CI_ENABLE') === 'yes_sandbox_only'
        && getenv('G3_CI_DSN') === $dsn
        && getenv('G3_CI_USER') === 'g3_ci_user'
        && getenv('G3_CI_PASSWORD') === 'g3_ci_pass_only', 'sandbox_only');
    $pdo = null;
    for ($i = 0; $i < 30; ++$i) {
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
    g3LegacyCheck($pdo instanceof PDO, 'sandbox_not_available');
    g3LegacyCheck($pdo->query('SELECT DATABASE()')->fetchColumn() === 'g3_ci',
        'wrong_database');
    return $pdo;
}
function g3LegacyReset(PDO $pdo)
{
    foreach (['g3_sfmc_order_sync', 'g3_sfmc_customer_sync',
        'g3_sfmc_queue', 'g3_growth_journal'] as $table) {
        $pdo->exec('DROP TABLE IF EXISTS ' . $table);
    }
    $schema = file_get_contents(__DIR__ . '/g3_prestashop_071_mirror_schema.sql');
    foreach (explode(';', $schema) as $sql) {
        if (trim($sql) !== '') {
            $pdo->exec(trim($sql));
        }
    }
}
function g3LegacyCount(PDO $pdo, $table)
{
    g3LegacyCheck(in_array($table, ['g3_sfmc_order_sync',
        'g3_sfmc_customer_sync', 'g3_sfmc_queue', 'g3_growth_journal'], true),
        'unsafe_table');
    return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}
function g3LegacyAccept(PDO $pdo, $id, $hex, $namespace = 'ci_bridge',
    $hook = 'CUSTOMER_UPDATED', $entity = 'customer', $shop = 1,
    $stage = '', $delay = 0)
{
    $dedupe = $entity . ':' . $shop . ':' . $id . ':' . str_repeat($hex, 40);
    $store = new G3SandboxLegacyQueueBridge($pdo, $dedupe, $stage, $delay);
    return StagedAtomicUtcAcceptance::accept(
        $namespace, $hook, $entity, $shop, $id, $dedupe,
        [$store, 'insertOrLoad']
    );
}
function g3LegacyTest(PDO $pdo)
{
    // Failure at every write stage MUST roll back all real-schema mirrors.
    foreach (['journal' => 'a', 'queue' => 'b', 'link' => 'c', 'ledger' => 'd'] as $stage => $hex) {
        $failed = false;
        try {
            g3LegacyAccept($pdo, 41, $hex, 'ci_bridge', 'CUSTOMER_CREATED', 'customer', 1, $stage);
        } catch (RuntimeException $e) {
            $failed = $e->getMessage() === 'g3_bridge_injected_' . $stage;
        }
        g3LegacyCheck($failed, 'rollback_failure_' . $stage);
        foreach (['g3_sfmc_customer_sync', 'g3_sfmc_order_sync',
            'g3_sfmc_queue', 'g3_growth_journal'] as $table) {
            g3LegacyCheck(g3LegacyCount($pdo, $table) === 0, 'partial_write_' . $stage);
        }
    }

    $first = g3LegacyAccept($pdo, 41, 'e', 'ci_bridge', 'CUSTOMER_CREATED');
    g3LegacyCheck($first['inserted']
        && g3LegacyCount($pdo, 'g3_growth_journal') === 1
        && g3LegacyCount($pdo, 'g3_sfmc_queue') === 1
        && g3LegacyCount($pdo, 'g3_sfmc_customer_sync') === 1, 'initial_insert');
    $queue = $pdo->query('SELECT status, event_type, payload, dedupe_key FROM g3_sfmc_queue')->fetch(PDO::FETCH_ASSOC);
    g3LegacyCheck($queue['status'] === 'blocked'
        && $queue['event_type'] === 'CUSTOMER_CREATED'
        && $queue['payload'] === '{"synthetic":true}'
        && $queue['dedupe_key'] === 'customer:1:41:' . str_repeat('e', 40),
        'wrong_queue_semantics');

    // Same key but another hook -> reuse first UTC and DO NOT rewrite ledger.
    $same = g3LegacyAccept($pdo, 41, 'e');
    g3LegacyCheck(!$same['inserted'] && $same['captured_at_reused']
        && $same['captured_at_utc'] === $first['captured_at_utc']
        && $same['row']['original_event_type'] === 'CUSTOMER_CREATED'
        && $same['accepted_sequence'] === $first['accepted_sequence'], 'replay_changed_original');

    $next = g3LegacyAccept($pdo, 41, 'f');
    g3LegacyCheck($next['inserted'] && $next['accepted_sequence'] > $first['accepted_sequence'],
        'changed_payload_not_written');
    $digest = $pdo->query('SELECT payload_hash FROM g3_sfmc_customer_sync WHERE id_customer = 41')->fetchColumn();
    g3LegacyCheck($digest === str_repeat('f', 40), 'ledger_did_not_advance');
    $late = g3LegacyAccept($pdo, 41, 'e');
    g3LegacyCheck(!$late['inserted'] && $late['accepted_sequence'] === $first['accepted_sequence']
        && $pdo->query('SELECT payload_hash FROM g3_sfmc_customer_sync WHERE id_customer = 41')->fetchColumn()
            === str_repeat('f', 40), 'replay_overwrote_newer_ledger');
    g3LegacyCheck(g3LegacyCount($pdo, 'g3_sfmc_queue') === 2, 'queue_duplicate_inserted');

    $order = g3LegacyAccept($pdo, 52, 'a', 'ci_bridge', 'ORDER_PAID', 'order');
    g3LegacyCheck($order['inserted'] && g3LegacyCount($pdo, 'g3_sfmc_order_sync') === 1,
        'order_not_atomic');
    // Same id/hash on another shop must not collide.
    $otherShop = g3LegacyAccept($pdo, 41, 'e', 'ci_bridge', 'CUSTOMER_CREATED',
        'customer', 2);
    g3LegacyCheck($otherShop['inserted'], 'shop_not_isolated');
    $queueCount = g3LegacyCount($pdo, 'g3_sfmc_queue');
    // Another install in the *same physical database* wants the same
    // namespace-less 0.7.1 queue key: fail closed (no silent reuse).
    $failed = false;
    try {
        g3LegacyAccept($pdo, 41, 'e', 'other_installation', 'CUSTOMER_CREATED');
    } catch (RuntimeException $e) {
        $failed = $e->getMessage() === 'g3_bridge_unmapped_legacy_dedupe';
    }
    g3LegacyCheck($failed && g3LegacyCount($pdo, 'g3_sfmc_queue') === $queueCount,
        'cross_install_collision_not_blocked');

    // Pre-existing legacy queue row (even one marked processed) must never be
    // claimed as a new Growth event.
    $now = gmdate('Y-m-d H:i:s');
    $sql = $pdo->prepare('INSERT INTO g3_sfmc_queue '
        . '(event_type, entity_type, entity_id, dedupe_key, payload, status, '
        . 'attempts, available_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $sql->execute(['CUSTOMER_CREATED', 'customer', '61',
        'customer:1:61:' . str_repeat('a', 40), '{"synthetic":true}',
        'processed', 0, $now, $now]);
    $failed = false;
    try {
        g3LegacyAccept($pdo, 61, 'a', 'ci_bridge', 'CUSTOMER_CREATED');
    } catch (RuntimeException $e) {
        $failed = $e->getMessage() === 'g3_bridge_unmapped_legacy_dedupe';
    }
    g3LegacyCheck($failed, 'historical_legacy_queue_claimed');

    // An existing legacy ledger maintained by 0.7.1 must not be overwritten.
    $ls = $pdo->prepare('INSERT INTO g3_sfmc_customer_sync '
        . '(id_shop, id_customer, contact_key, payload_hash, status, '
        . 'created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $ls->execute([1, 62, 'ci_legacy_62', str_repeat('a', 40),
        'synced', $now, $now]);
    $failed = false;
    try {
        g3LegacyAccept($pdo, 62, 'b');
    } catch (RuntimeException $e) {
        $failed = $e->getMessage() === 'g3_bridge_active_legacy_ledger';
    }
    g3LegacyCheck($failed, 'active_legacy_ledger_overwritten');

    // If an external process changes the queue status, fail rather than
    // silently treating the replay as a harmless old event.
    $pdo->exec("UPDATE g3_sfmc_queue SET status = 'processed' "
        . "WHERE dedupe_key = 'customer:1:41:" . str_repeat('e', 40) . "'");
    $failed = false;
    try {
        g3LegacyAccept($pdo, 41, 'e', 'ci_bridge', 'CUSTOMER_CREATED');
    } catch (RuntimeException $e) {
        $failed = $e->getMessage() === 'g3_bridge_queue_link_invalid';
    }
    g3LegacyCheck($failed, 'tampered_link_accepted');
    $pdo->exec("UPDATE g3_sfmc_queue SET status = 'blocked' "
        . "WHERE dedupe_key = 'customer:1:41:" . str_repeat('e', 40) . "'");

    $notBlocked = (int) $pdo->query("SELECT COUNT(*) FROM g3_sfmc_queue "
        . "WHERE status NOT IN ('blocked','processed')")->fetchColumn();
    g3LegacyCheck($notBlocked === 0 && $pdo->query("SELECT COUNT(*) "
        . "FROM g3_growth_journal WHERE legacy_queue_id IS NULL")->fetchColumn() == 0,
        'unlinked_or_activated_growth_event');
}
function g3LegacyParallel(PDO $pdo)
{
    $proc = [];
    for ($i = 0; $i < 8; ++$i) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' worker';
        $p = proc_open($cmd, [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']
        ], $pipes);
        g3LegacyCheck(is_resource($p), 'worker_spawn');
        fclose($pipes[0]);
        $proc[] = [$p, $pipes];
    }
    $one = 0; $observed = []; $seq = [];
    foreach ($proc as $entry) {
        $out = stream_get_contents($entry[1][1]);
        $err = stream_get_contents($entry[1][2]);
        fclose($entry[1][1]); fclose($entry[1][2]);
        g3LegacyCheck(proc_close($entry[0]) === 0, 'worker_exit_' . substr($err, 0, 60));
        $j = json_decode($out, true);
        g3LegacyCheck(is_array($j) && !empty($j['captured_at_utc']), 'worker_result');
        $one += $j['inserted'] ? 1 : 0;
        $observed[$j['captured_at_utc']] = true;
        $seq[$j['accepted_sequence']] = true;
        g3LegacyCheck($j['marketing_sends_authorized'] === false
            && $j['data_360_ingestion_authorized'] === false, 'worker_not_blocked');
    }
    g3LegacyCheck($one === 1 && count($observed) === 1
        && count($seq) === 1, 'concurrent_duplicate_or_clock');
    $q = $pdo->query("SELECT COUNT(*) FROM g3_sfmc_queue "
        . "WHERE dedupe_key = 'customer:1:88:" . str_repeat('c', 40) . "'"
        . " AND status = 'blocked'")->fetchColumn();
    g3LegacyCheck((int) $q === 1, 'concurrent_queue_duplicate');
    $links = $pdo->query("SELECT COUNT(*) FROM g3_growth_journal "
        . "WHERE source_namespace = 'ci_bridge' AND shop_id = 1 "
        . "AND entity_type = 'customer' AND entity_id = 88 "
        . "AND legacy_queue_id IS NOT NULL")->fetchColumn();
    g3LegacyCheck((int) $links === 1, 'concurrent_unlinked_journal');
}

try {
    $pdo = g3LegacyConnect();
    if (isset($argv[1]) && $argv[1] === 'worker') {
        $r = g3LegacyAccept($pdo, 88, 'c', 'ci_bridge', 'CUSTOMER_CREATED',
            'customer', 1, '', 250000);
        echo json_encode([
            'inserted' => $r['inserted'],
            'captured_at_utc' => $r['captured_at_utc'],
            'accepted_sequence' => $r['accepted_sequence'],
            'marketing_sends_authorized' => $r['marketing_sends_authorized'],
            'data_360_ingestion_authorized' => $r['data_360_ingestion_authorized'],
        ]);
        exit(0);
    }
    g3LegacyReset($pdo);
    g3LegacyTest($pdo);
    g3LegacyParallel($pdo);
    echo "G3 PS 0.7.1 table mirror passed: rollback, collision guards, 8 workers, blocked queue.\n";
} catch (Throwable $e) {
    // The CI must never expose credentials, payloads, DSNs or customer data.
    fwrite(STDERR, "G3 PrestaShop queue mirror CI failed at isolated DB safety gate.\n");
    exit(1);
}
