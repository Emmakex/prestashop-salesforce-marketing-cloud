<?php
/**
 * CI-only MySQL/MariaDB acceptance for the PrestaShop/Growth installation
 * candidate. Uses only the disposable g3_ci database, never merchant SQL.
 */
require_once __DIR__ . '/G3PrestaShopAdapterSandbox.php';

function g3AdapterAssert($yes, $reason)
{
    if (!$yes) {
        throw new RuntimeException('g3_adapter_test_' . $reason);
    }
}
function g3AdapterFail($callback, $reason)
{
    try {
        call_user_func($callback);
    } catch (Throwable $e) {
        return;
    }
    g3AdapterAssert(false, $reason);
}
function g3AdapterDatabase()
{
    $dsn = 'mysql:host=127.0.0.1;port=3306;dbname=g3_ci;charset=utf8mb4';
    g3AdapterAssert(getenv('G3_CI_ENABLE') === 'yes_sandbox_only'
        && getenv('G3_CI_DSN') === $dsn
        && getenv('G3_CI_USER') === 'g3_ci_user'
        && getenv('G3_CI_PASSWORD') === 'g3_ci_pass_only',
        'sandbox_connection_required');
    $db = new PDO($dsn, 'g3_ci_user', 'g3_ci_pass_only', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    new G3GrowthOutboxSandboxMigration($db);
    return $db;
}
function g3AdapterTables(PDO $db)
{
    $q = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN
        ('g3_ps_adapter_binding_v1', 'g3_growth_outbox_v1',
         'g3_growth_entity_ledger_v1')");
    return $q->fetchAll(PDO::FETCH_COLUMN, 0);
}
function g3AdapterTest(PDO $db)
{
    $adapter = new G3PrestaShopAdapterSandbox($db);
    $selection = ['8.2.8', 'ABH', 'marketing_cloud_growth', 'growth_ci_abhtest', 'test'];
    g3AdapterAssert(count(g3AdapterTables($db)) === 0, 'dirty_sandbox');
    $legacyCounts = [];
    foreach (['g3_sfmc_queue', 'g3_sfmc_customer_sync', 'g3_sfmc_order_sync'] as $table) {
        $legacyCounts[$table] = (int) $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    g3AdapterAssert(call_user_func_array([$adapter, 'install'], $selection) === true,
        'initial_install');
    g3AdapterAssert(count(g3AdapterTables($db)) === 3, 'owned_tables_missing');
    g3AdapterAssert(call_user_func_array([$adapter, 'install'], $selection) === false,
        'install_not_idempotent');
    $binding = $db->query('SELECT provider, mode, status, source_namespace
        FROM g3_ps_adapter_binding_v1')->fetch(PDO::FETCH_ASSOC);
    g3AdapterAssert($binding === [
        'provider' => 'marketing_cloud_growth',
        'mode' => 'test',
        'status' => 'blocked',
        'source_namespace' => 'growth_ci_abhtest',
    ], 'binding_not_explicit');

    foreach ([
        ['8.2.8', 'ABH', 'marketing_cloud_engagement', 'growth_ci_abhtest', 'test'],
        ['8.2.8', 'ABH', 'marketing_cloud_growth', 'growth_ci_abhtest', 'production'],
        ['9.1.5', 'ABH', 'marketing_cloud_growth', 'growth_ci_abhtest', 'test'],
        ['8.2.8', 'ABH', 'marketing_cloud_growth', 'growth_ci_other', 'test'],
        ['8.2.8', 'OTHER_MERCHANT', 'marketing_cloud_growth', 'growth_ci_abhtest', 'test'],
    ] as $bad) {
        g3AdapterFail(function () use ($adapter, $bad) {
            call_user_func_array([$adapter, 'install'], $bad);
        }, 'provider_or_installation_switch_accepted');
    }
    g3AdapterFail(function () use ($adapter) {
        $adapter->install('8.2.8', 'OTHER_MERCHANT', 'marketing_cloud_engagement',
            'growth_ci_other', 'test');
    }, 'legacy_engagement_must_not_be_auto_installed');

    // DB constraints enforce Test/blocked even if application code is bypassed.
    g3AdapterFail(function () use ($db) {
        $db->exec("UPDATE g3_ps_adapter_binding_v1 SET mode = 'production'");
    }, 'binding_mode_guard_missing');
    g3AdapterFail(function () use ($db) {
        $db->exec("UPDATE g3_ps_adapter_binding_v1 SET status = 'ready'");
    }, 'binding_status_guard_missing');
    g3AdapterFail(function () use ($db) {
        $db->exec("UPDATE g3_ps_adapter_binding_v1 SET provider = 'marketing_cloud_engagement'");
    }, 'binding_provider_guard_missing');

    // Synthetic, blocked row must prevent destructive rollback.
    $q = $db->prepare("INSERT INTO g3_growth_outbox_v1
        (source_namespace, shop_id, entity_type, entity_id, dedupe_fingerprint,
         original_event_type, captured_at_utc, captured_at_precision, clock_source,
         clock_sync_verified, source_modified_provenance, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $q->execute([
        'growth_ci_abhtest', 1, 'customer', 33, str_repeat('a', 64),
        'CUSTOMER_CREATED', '2026-11-13T15:00:00.000Z', 'seconds',
        'php_wall_clock', 0, 'unknown', 'blocked',
    ]);
    g3AdapterFail(function () use ($adapter, $selection) {
        call_user_func_array([$adapter, 'rollbackIfEmpty'], $selection);
    }, 'nonempty_rollback_not_blocked');
    g3AdapterAssert(count(g3AdapterTables($db)) === 3, 'nonempty_rollback_modified_schema');
    $db->exec("DELETE FROM g3_growth_outbox_v1
        WHERE source_namespace = 'growth_ci_abhtest' AND entity_id = 33");
    g3AdapterAssert(call_user_func_array([$adapter, 'rollbackIfEmpty'], $selection) === true,
        'rollback_empty_failed');
    g3AdapterAssert(call_user_func_array([$adapter, 'rollbackIfEmpty'], $selection) === false,
        'rollback_not_idempotent');
    g3AdapterAssert(count(g3AdapterTables($db)) === 0, 'rollback_left_tables');

    // An interrupted first DDL cannot silently auto-heal.
    $db->exec('CREATE TABLE g3_ps_adapter_binding_v1 (incomplete INT)
        ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    g3AdapterFail(function () use ($adapter, $selection) {
        call_user_func_array([$adapter, 'install'], $selection);
    }, 'partial_install_accepted');
    g3AdapterFail(function () use ($adapter, $selection) {
        call_user_func_array([$adapter, 'rollbackIfEmpty'], $selection);
    }, 'partial_rollback_accepted');
    $db->exec('DROP TABLE g3_ps_adapter_binding_v1'); // CI-only manual repair fixture
    g3AdapterAssert(call_user_func_array([$adapter, 'install'], $selection) === true,
        'reinstall_after_manual_review');
    g3AdapterAssert(call_user_func_array([$adapter, 'rollbackIfEmpty'], $selection) === true,
        'rollback_after_reinstall');

    foreach ($legacyCounts as $table => $count) {
        g3AdapterAssert((int) $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn()
            === $count, 'legacy_mutation_detected');
    }
    g3AdapterAssert(count(g3AdapterTables($db)) === 0, 'sandbox_cleanup');
}

try {
    g3AdapterTest(g3AdapterDatabase());
    echo "G3 PrestaShop/provider adapter sandbox PASS on MySQL/MariaDB; no sends.\n";
} catch (Throwable $e) {
    // Never expose raw SQL, credentials or merchant data in CI output.
    fwrite(STDERR, "G3 PrestaShop adapter sandbox rejected at an integrity gate.\n");
    exit(1);
}
